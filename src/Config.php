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

use GLPIKey;
use Session;

/**
 * Plugin configuration, stored in glpi_configs with context "plugin:threema".
 * "api_secret" and "private_key" are encrypted with the GLPI key
 * (declared through the "secured_configs" hook in setup.php).
 */
class Config
{
    public const CONTEXT = 'plugin:threema';

    /** Keys encrypted at rest (keep in sync with setup.php) */
    public const SECURED = ['api_secret', 'private_key'];

    /** Public keys cached in glpi_plugin_threema_pubkeys are refreshed after this many days */
    private const PUBKEY_TTL_DAYS = 30;

    /**
     * Hook "undiscloseConfigValue": strip our secrets from configuration exports/API.
     *
     * @param array $fields config row (context, name, value)
     */
    public static function undisclose(array $fields): array
    {
        if (
            ($fields['context'] ?? '') === self::CONTEXT
            && in_array($fields['name'] ?? '', self::SECURED, true)
        ) {
            unset($fields['value']);
        }
        return $fields;
    }

    public static function defaults(): array
    {
        return [
            'gateway_id'       => '',
            'api_secret'       => '',
            'mode'             => GatewayClient::MODE_E2E,
            'private_key'      => '',
            'base_url'         => GatewayClient::DEFAULT_BASE_URL,
            'timeout'          => 15,
            'max_retries'      => 3,
            'retry_delay'      => 15,   // minutes
            'max_length'       => 3000, // bytes of UTF-8 text per message
            'admin_threema_id' => '',
            'auto_lookup'      => 0,
        ];
    }

    /**
     * @return array settings merged over defaults, secrets decrypted
     */
    public static function getAll(): array
    {
        $values = array_merge(
            self::defaults(),
            \Config::getConfigurationValues(self::CONTEXT)
        );
        $glpikey = new GLPIKey();
        foreach (self::SECURED as $name) {
            if (!empty($values[$name])) {
                $values[$name] = (string)$glpikey->decrypt($values[$name]);
            }
        }
        return $values;
    }

    public static function isConfigured(): bool
    {
        $cfg = self::getAll();
        if (!GatewayClient::isValidId(GatewayClient::normalizeId($cfg['gateway_id'])) || $cfg['api_secret'] === '') {
            return false;
        }
        return $cfg['mode'] !== GatewayClient::MODE_E2E || $cfg['private_key'] !== '';
    }

    /**
     * Validate and store the settings posted by the configuration form.
     * Empty secret fields keep the stored value. Nothing is saved if a field is invalid.
     *
     * @return string[] error messages (empty on success)
     */
    public static function save(array $input): array
    {
        $current = self::getAll();
        $errors  = [];
        $values  = [];

        $gateway_id = GatewayClient::normalizeId((string)($input['gateway_id'] ?? $current['gateway_id']));
        if ($gateway_id !== '' && !GatewayClient::isValidId($gateway_id)) {
            $errors[] = __('The Gateway ID must be 8 characters long (usually starting with *).', 'threema');
        }
        $values['gateway_id'] = $gateway_id;

        $mode = (string)($input['mode'] ?? $current['mode']);
        if (!in_array($mode, [GatewayClient::MODE_BASIC, GatewayClient::MODE_E2E], true)) {
            $errors[] = __('Unknown mode.', 'threema');
        }
        $values['mode'] = $mode;

        $secret = (string)($input['api_secret'] ?? '');
        if ($secret !== '') {
            $values['api_secret'] = $secret;
        }

        $private_key = trim((string)($input['private_key'] ?? ''));
        if ($private_key !== '') {
            try {
                GatewayClient::parseKey($private_key, 'private');
                $values['private_key'] = $private_key;
            } catch (GatewayException $e) {
                $errors[] = __('The private key must be 64 hexadecimal characters (with or without the "private:" prefix).', 'threema');
            }
        }
        if ($mode === GatewayClient::MODE_E2E && empty($values['private_key']) && empty($current['private_key'])) {
            $errors[] = __('End-to-end mode requires the private key of the Gateway ID.', 'threema');
        }
        if ($mode === GatewayClient::MODE_E2E && !function_exists('sodium_crypto_box')) {
            $errors[] = __('End-to-end mode requires the PHP "sodium" extension.', 'threema');
        }

        $base_url = trim((string)($input['base_url'] ?? $current['base_url']));
        if ($base_url === '') {
            $base_url = GatewayClient::DEFAULT_BASE_URL;
        }
        if (!preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', rtrim($base_url, '/'))) {
            $errors[] = __('The API URL must be an https:// URL without path.', 'threema');
        }
        $values['base_url'] = rtrim($base_url, '/');

        $values['timeout']     = max(1, min(60, (int)($input['timeout'] ?? $current['timeout'])));
        $values['max_retries'] = max(0, min(20, (int)($input['max_retries'] ?? $current['max_retries'])));
        $values['retry_delay'] = max(1, min(1440, (int)($input['retry_delay'] ?? $current['retry_delay'])));
        $hard_max              = $mode === GatewayClient::MODE_E2E ? 7000 : GatewayClient::BASIC_MAX_BYTES;
        $values['max_length']  = max(200, min($hard_max, (int)($input['max_length'] ?? $current['max_length'])));
        $values['auto_lookup'] = (int)!empty($input['auto_lookup'] ?? $current['auto_lookup']);

        $admin = GatewayClient::normalizeId((string)($input['admin_threema_id'] ?? $current['admin_threema_id']));
        if ($admin !== '' && !GatewayClient::isValidId($admin)) {
            $errors[] = __('The administrator Threema ID must be 8 characters long.', 'threema');
        }
        $values['admin_threema_id'] = $admin;

        if (!$errors) {
            \Config::setConfigurationValues(self::CONTEXT, $values);
        }
        return $errors;
    }

    /**
     * Build a client from the stored settings.
     *
     * @throws GatewayException if the plugin is not fully configured
     */
    public static function getClient(): GatewayClient
    {
        global $DB;

        $cfg = self::getAll();
        if (!self::isConfigured()) {
            throw new GatewayException(__('The Threema Gateway is not fully configured.', 'threema'), 0, true);
        }

        $table = 'glpi_plugin_threema_pubkeys';
        $options = [
            'base_url' => $cfg['base_url'],
            'timeout'  => (int)$cfg['timeout'],
            'proxy'    => self::getProxy(),
            'key_loader' => static function (string $id) use ($DB, $table) {
                $limit = date('Y-m-d H:i:s', strtotime('-' . self::PUBKEY_TTL_DAYS . ' days'));
                foreach ($DB->request(['FROM' => $table, 'WHERE' => ['threemaid' => $id, 'date_mod' => ['>', $limit]]]) as $row) {
                    return $row['publickey'];
                }
                return null;
            },
            'key_saver' => static function (string $id, string $hex) use ($DB, $table) {
                $DB->updateOrInsert($table, ['publickey' => $hex, 'date_mod' => date('Y-m-d H:i:s')], ['threemaid' => $id]);
            },
        ];

        return new GatewayClient(
            $cfg['gateway_id'],
            $cfg['api_secret'],
            $cfg['mode'],
            $cfg['mode'] === GatewayClient::MODE_E2E ? $cfg['private_key'] : null,
            $options
        );
    }

    /** Proxy URL built from the GLPI proxy settings, or null */
    public static function getProxy(): ?string
    {
        global $CFG_GLPI;

        if (empty($CFG_GLPI['proxy_name'])) {
            return null;
        }
        $auth = '';
        if (!empty($CFG_GLPI['proxy_user'])) {
            $pass = !empty($CFG_GLPI['proxy_passwd']) ? (string)(new GLPIKey())->decrypt($CFG_GLPI['proxy_passwd']) : '';
            $auth = rawurlencode($CFG_GLPI['proxy_user']) . ($pass !== '' ? ':' . rawurlencode($pass) : '') . '@';
        }
        return 'http://' . $auth . $CFG_GLPI['proxy_name'] . (!empty($CFG_GLPI['proxy_port']) ? ':' . (int)$CFG_GLPI['proxy_port'] : '');
    }

    /**
     * "Test connection" button: checks ID/secret (credits) and, in E2E mode,
     * that the private key matches the public key registered for the Gateway ID.
     * Results are shown with GLPI messages.
     */
    public static function testConnection(): bool
    {
        try {
            $client  = self::getClient();
            $credits = $client->getCredits();
        } catch (GatewayException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
            return false;
        }
        Session::addMessageAfterRedirect(sprintf(__('Connection OK: %d credits remaining.', 'threema'), $credits));

        $cfg = self::getAll();
        if ($cfg['mode'] === GatewayClient::MODE_E2E) {
            try {
                $remote = $client->fetchPublicKey(GatewayClient::normalizeId($cfg['gateway_id']), true);
                if (!hash_equals($remote, GatewayClient::derivePublicKey($cfg['private_key']))) {
                    Session::addMessageAfterRedirect(
                        __('The private key does NOT match the public key registered for this Gateway ID: messages will be rejected.', 'threema'),
                        false,
                        ERROR
                    );
                    return false;
                }
                Session::addMessageAfterRedirect(__('The private key matches the public key registered for this Gateway ID.', 'threema'));
            } catch (GatewayException $e) {
                Session::addMessageAfterRedirect(
                    __('Could not verify the key pair against the gateway:', 'threema') . ' ' . htmlspecialchars($e->getMessage()),
                    false,
                    WARNING
                );
            }
        }
        return true;
    }

    /** "Send a test message" button: sends a message to the administrator Threema ID. */
    public static function sendTest(): bool
    {
        $cfg = self::getAll();
        $to  = GatewayClient::normalizeId($cfg['admin_threema_id']);
        if (!GatewayClient::isValidId($to)) {
            Session::addMessageAfterRedirect(__('Set a valid administrator Threema ID first.', 'threema'), false, ERROR);
            return false;
        }
        try {
            $id = self::getClient()->sendText(
                $to,
                '*GLPI* - ' . __('Threema test notification', 'threema') . "\n\n"
                . __('If you can read this, Threema notifications are working.', 'threema')
            );
        } catch (GatewayException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
            return false;
        }
        Session::addMessageAfterRedirect(sprintf(__('Test message sent to %1$s (message ID %2$s).', 'threema'), $to, htmlspecialchars($id)));
        return true;
    }
}
