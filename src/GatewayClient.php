<?php

/**
 * -------------------------------------------------------------------------
 * Threema plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace GlpiPlugin\Threema;

/**
 * Minimal client for the Threema Gateway "Message API".
 *
 * Implements, as documented in the Threema Gateway portal (API Documentation):
 *  - Basic mode           POST /send_simple   (server-side encryption)
 *  - End-to-end mode      POST /send_e2e      (NaCl crypto_box done locally, needs ext-sodium)
 *  - Public key lookup    GET  /pubkeys/{id}
 *  - ID lookup by hash    GET  /lookup/email_hash/{h}, /lookup/phone_hash/{h}
 *  - Remaining credits    GET  /credits
 *
 * It has no dependency on GLPI so it can be tested on its own.
 * Compatible with PHP >= 7.4 (GLPI 10.0 requirement).
 */
class GatewayClient
{
    public const MODE_BASIC = 'basic';
    public const MODE_E2E   = 'e2e';

    public const DEFAULT_BASE_URL = 'https://msgapi.threema.ch';

    /** Max text size (UTF-8 bytes) of a basic mode message */
    public const BASIC_MAX_BYTES = 3500;
    /** Max size of the encrypted box in E2E mode (bytes, before hex encoding) */
    public const E2E_BOX_MAX_BYTES = 7812;

    /** Published HMAC-SHA256 keys used to hash identifiers before lookup (documented by Threema) */
    private const EMAIL_HMAC_KEY = '30a5500fed9701fa6defdb610841900febb8e430881f7ad816826264ec09bad7';
    private const PHONE_HMAC_KEY = '85adf8226953f3d96cfd5d09bf29555eb955fcd8aa5ec4f9fcd869e258370723';

    /** Message type byte of a text message inside an E2E box */
    private const TYPE_TEXT = "\x01";

    /** @var string */
    private $from;
    /** @var string */
    private $secret;
    /** @var string */
    private $mode;
    /** @var string|null */
    private $private_key;
    /** @var string */
    private $base_url;
    /** @var int */
    private $timeout;
    /** @var string|null */
    private $proxy;
    /** @var callable|null  fn(string $method, string $url, array $form): array{0:int,1:string} */
    private $http;
    /** @var callable|null  fn(string $threema_id): ?string  (hex public key) */
    private $key_loader;
    /** @var callable|null  fn(string $threema_id, string $hex_public_key): void */
    private $key_saver;
    /** @var array<string,string> */
    private $key_cache = [];

    /**
     * @param string        $from        Gateway ID (8 chars, usually starts with '*')
     * @param string        $secret      API secret
     * @param string        $mode        MODE_BASIC | MODE_E2E
     * @param string|null   $private_key Private key (hex, "private:" prefix optional) - E2E only
     * @param array         $options     base_url, timeout, proxy, http (callable), key_loader, key_saver
     */
    public function __construct(string $from, string $secret, string $mode = self::MODE_BASIC, ?string $private_key = null, array $options = [])
    {
        if (!in_array($mode, [self::MODE_BASIC, self::MODE_E2E], true)) {
            throw new \InvalidArgumentException('Unknown mode: ' . $mode);
        }
        $this->from        = self::normalizeId($from);
        $this->secret      = $secret;
        $this->mode        = $mode;
        $this->private_key = $private_key;
        $this->base_url    = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout     = max(1, (int)($options['timeout'] ?? 15));
        $this->proxy       = $options['proxy'] ?? null;
        $this->http        = $options['http'] ?? null;
        $this->key_loader  = $options['key_loader'] ?? null;
        $this->key_saver   = $options['key_saver'] ?? null;

        if ($mode === self::MODE_E2E) {
            if (!function_exists('sodium_crypto_box')) {
                throw new GatewayException('PHP extension "sodium" is required for end-to-end mode.', 0, true);
            }
            // fail early on a malformed key
            self::parseKey((string)$private_key, 'private');
        }
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * Send a text message using the configured mode.
     *
     * @return string Message ID assigned by the gateway
     */
    public function sendText(string $to, string $text): string
    {
        $to = self::normalizeId($to);
        if (!self::isValidId($to)) {
            throw new GatewayException('Invalid Threema ID: ' . $to, 400, true);
        }
        return $this->mode === self::MODE_E2E
            ? $this->sendE2E($to, $text)
            : $this->sendSimple($to, $text);
    }

    private function sendSimple(string $to, string $text): string
    {
        if (strlen($text) > self::BASIC_MAX_BYTES) {
            throw new GatewayException('Message too long for basic mode (max ' . self::BASIC_MAX_BYTES . ' bytes).', 413, true);
        }
        [$code, $body] = $this->request('POST', '/send_simple', [], [
            'from'   => $this->from,
            'to'     => $to,
            'text'   => $text,
            'secret' => $this->secret,
        ]);
        return $this->expectOk($code, $body, 'send_simple');
    }

    private function sendE2E(string $to, string $text): string
    {
        $recipient_pub = hex2bin($this->fetchPublicKey($to));
        $sender_priv   = self::parseKey((string)$this->private_key, 'private');

        // Plaintext = type byte + text + PKCS#7 padding of random length (1..255),
        // as specified by Threema. Padding is capped so the box stays under the limit.
        $plain = self::TYPE_TEXT . $text;
        $max_pad = min(255, self::E2E_BOX_MAX_BYTES - SODIUM_CRYPTO_BOX_MACBYTES - strlen($plain));
        if ($max_pad < 1) {
            throw new GatewayException('Message too long for end-to-end mode.', 413, true);
        }
        $pad    = random_int(1, $max_pad);
        $plain .= str_repeat(chr($pad), $pad);

        $nonce   = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($sender_priv, $recipient_pub);
        $box     = sodium_crypto_box($plain, $nonce, $keypair);
        sodium_memzero($sender_priv);

        [$code, $body] = $this->request('POST', '/send_e2e', [], [
            'from'   => $this->from,
            'to'     => $to,
            'nonce'  => bin2hex($nonce),
            'box'    => bin2hex($box),
            'secret' => $this->secret,
        ]);
        return $this->expectOk($code, $body, 'send_e2e');
    }

    // ------------------------------------------------------------------
    // Lookups / account
    // ------------------------------------------------------------------

    /**
     * Public key (hex) of a Threema ID. Cached in memory and, if provided, by key_loader/key_saver
     * (Threema strongly recommends caching public keys).
     */
    public function fetchPublicKey(string $threema_id, bool $force = false): string
    {
        $threema_id = self::normalizeId($threema_id);
        if (!$force) {
            if (isset($this->key_cache[$threema_id])) {
                return $this->key_cache[$threema_id];
            }
            if ($this->key_loader !== null) {
                $cached = ($this->key_loader)($threema_id);
                if (is_string($cached) && preg_match('/^[0-9a-f]{64}$/', $cached)) {
                    return $this->key_cache[$threema_id] = $cached;
                }
            }
        }

        [$code, $body] = $this->request('GET', '/pubkeys/' . rawurlencode($threema_id), $this->auth());
        $hex = strtolower($this->expectOk($code, $body, 'pubkeys'));
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new GatewayException('Unexpected public key format returned by the gateway.', $code);
        }
        $this->key_cache[$threema_id] = $hex;
        if ($this->key_saver !== null) {
            ($this->key_saver)($threema_id, $hex);
        }
        return $hex;
    }

    /** @return string|null Threema ID, or null if no match */
    public function lookupEmail(string $email): ?string
    {
        return $this->lookupHash('email_hash', self::hashEmail($email));
    }

    /** @return string|null Threema ID, or null if no match. Phone must be international (E.164). */
    public function lookupPhone(string $phone): ?string
    {
        return $this->lookupHash('phone_hash', self::hashPhone($phone));
    }

    private function lookupHash(string $kind, string $hash): ?string
    {
        [$code, $body] = $this->request('GET', '/lookup/' . $kind . '/' . $hash, $this->auth());
        if ($code === 404) {
            return null;
        }
        return strtoupper($this->expectOk($code, $body, 'lookup'));
    }

    /** Remaining credits of the account (also validates ID + secret). */
    public function getCredits(): int
    {
        [$code, $body] = $this->request('GET', '/credits', $this->auth());
        return (int)$this->expectOk($code, $body, 'credits');
    }

    // ------------------------------------------------------------------
    // Static helpers
    // ------------------------------------------------------------------

    public static function normalizeId(string $id): string
    {
        return strtoupper(trim($id));
    }

    public static function isValidId(string $id): bool
    {
        return (bool)preg_match('/^[0-9A-Z*]{8}$/', $id);
    }

    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), hex2bin(self::EMAIL_HMAC_KEY));
    }

    /** E.164 without leading '+' (also accepts "+41 79 123 45 67" and "0041791234567") */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strpos(trim($phone), '+') !== 0 && strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    public static function hashPhone(string $phone): string
    {
        return hash_hmac('sha256', self::normalizePhone($phone), hex2bin(self::PHONE_HMAC_KEY));
    }

    /**
     * Parse a key given as hex, with or without the "private:" / "public:" prefix.
     *
     * @return string binary key (32 bytes)
     */
    public static function parseKey(string $key, string $type): string
    {
        $key    = trim($key);
        $prefix = $type . ':';
        if (stripos($key, $prefix) === 0) {
            $key = substr($key, strlen($prefix));
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $key)) {
            throw new GatewayException('Invalid ' . $type . ' key (expected 64 hexadecimal characters).', 0, true);
        }
        return hex2bin($key);
    }

    /** @return string public key (hex) matching a private key (hex) */
    public static function derivePublicKey(string $private_key): string
    {
        return bin2hex(sodium_crypto_box_publickey_from_secretkey(self::parseKey($private_key, 'private')));
    }

    /** @return array{private:string,public:string} hex keys with the prefixes used by threema-msgapi-tool */
    public static function generateKeyPair(): array
    {
        $kp = sodium_crypto_box_keypair();
        return [
            'private' => 'private:' . bin2hex(sodium_crypto_box_secretkey($kp)),
            'public'  => 'public:' . bin2hex(sodium_crypto_box_publickey($kp)),
        ];
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private function auth(): array
    {
        return ['from' => $this->from, 'secret' => $this->secret];
    }

    /**
     * @return array{0:int,1:string} [http status, body]
     */
    private function request(string $method, string $path, array $query = [], array $form = []): array
    {
        $url = $this->base_url . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        if ($this->http !== null) {
            return ($this->http)($method, $url, $form);
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'glpi-plugin-threema',
            CURLOPT_HTTPHEADER     => ['Accept: text/plain, application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        if (!empty($this->proxy)) {
            $opts[CURLOPT_PROXY] = $this->proxy;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new GatewayException('Unable to reach the Threema Gateway: ' . $err, 0);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string)$body];
    }

    private function expectOk(int $code, string $body, string $what): string
    {
        if ($code === 200) {
            return trim($body);
        }
        $messages = [
            400 => 'Invalid recipient, or account not set up for this mode',
            401 => 'Gateway ID or secret is incorrect',
            402 => 'No credits remaining',
            404 => 'Not found',
            413 => 'Message too long',
            429 => 'Rate limit reached, try again later',
            500 => 'Temporary Threema Gateway error',
        ];
        $msg = $messages[$code] ?? 'Unexpected response';
        throw new GatewayException(sprintf('%s (HTTP %d, %s)', $msg, $code, $what), $code);
    }
}
