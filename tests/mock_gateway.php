<?php
/**
 * Fake Threema Gateway for tests. Run with:
 *   MOCK_STATE=/tmp/state.json MOCK_SENDER_PUB=<hex> php -S 127.0.0.1:8099 tests/mock_gateway.php
 * Logs every accepted request to MOCK_STATE (JSON lines) and verifies E2E boxes by decrypting them.
 */

require_once __DIR__ . '/../src/GatewayClient.php';
require_once __DIR__ . '/../src/GatewayException.php';

use GlpiPlugin\Threema\GatewayClient;

const SECRET = 'SECRET';

function recipient_private(): string
{
    return sodium_crypto_generichash('recipient-seed', '', 32);
}

function log_line(array $row): void
{
    file_put_contents(getenv('MOCK_STATE') ?: '/tmp/mock-state.jsonl', json_encode($row) . "\n", FILE_APPEND);
}

function out(int $code, string $body = ''): void
{
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $body;
    exit;
}

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$in     = $method === 'POST' ? $_POST : $_GET;

if (($in['secret'] ?? null) !== SECRET) {
    out(401);
}
$from = $in['from'] ?? '';

if ($path === '/credits') {
    out(200, '42');
}

if (preg_match('#^/pubkeys/(.+)$#', $path, $m)) {
    $m[1] = rawurldecode($m[1]);
    log_line(['type' => 'pubkeys', 'id' => $m[1]]);
    if ($m[1] === 'UNKNOWN0') {
        out(404);
    }
    if ($m[1] === $from) {
        out(200, getenv('MOCK_SENDER_PUB'));
    }
    out(200, bin2hex(sodium_crypto_box_publickey_from_secretkey(recipient_private())));
}

if (preg_match('#^/lookup/(email_hash|phone_hash)/([0-9a-f]{64})$#', $path, $m)) {
    $known = $m[1] === 'email_hash'
        ? GatewayClient::hashEmail('known@example.com')
        : GatewayClient::hashPhone('+41 79 123 45 67');
    $m[2] === $known ? out(200, 'ABCD1234') : out(404);
}

if ($path === '/send_simple' && $method === 'POST') {
    $to = $in['to'] ?? '';
    if (strlen($to) !== 8 || $to === 'BADRECIP') { out(400); }
    if ($to === 'RATELIMT') { out(429); }
    if ($to === 'SERVER00') { out(500); }
    if (strlen($in['text'] ?? '') > 3500) { out(413); }
    log_line(['type' => 'send_simple', 'from' => $from, 'to' => $to, 'text' => $in['text']]);
    out(200, 'simple-0001');
}

if ($path === '/send_e2e' && $method === 'POST') {
    $to = $in['to'] ?? '';
    if ($to === 'BADRECIP') { out(400); }
    if ($to === 'RATELIMT') { out(429); }
    if ($to === 'SERVER00') { out(500); }
    $nonce = hex2bin($in['nonce'] ?? '');
    $box   = hex2bin($in['box'] ?? '');
    if (strlen($nonce) !== 24 || strlen($box) > 7812) { out(400); }
    $kp    = sodium_crypto_box_keypair_from_secretkey_and_publickey(
        recipient_private(),
        hex2bin(getenv('MOCK_SENDER_PUB'))
    );
    $plain = sodium_crypto_box_open($box, $nonce, $kp);
    if ($plain === false) { out(400); }
    $pad = ord($plain[strlen($plain) - 1]);
    if ($pad < 1 || $pad > 255 || substr($plain, -$pad) !== str_repeat(chr($pad), $pad)) { out(400); }
    $plain = substr($plain, 0, -$pad);
    if ($plain[0] !== "\x01") { out(400); }
    log_line(['type' => 'send_e2e', 'to' => $in['to'], 'text' => substr($plain, 1), 'pad' => $pad, 'boxlen' => strlen($box)]);
    out(200, 'e2e-0001');
}

out(404);
