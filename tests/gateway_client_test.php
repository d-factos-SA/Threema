<?php
/**
 * Standalone tests for GatewayClient (no GLPI, no PHPUnit):  php tests/gateway_client_test.php
 * Starts tests/mock_gateway.php on 127.0.0.1 and exercises the real curl code path.
 */

require_once __DIR__ . '/../src/GatewayClient.php';
require_once __DIR__ . '/../src/GatewayException.php';

use GlpiPlugin\Threema\GatewayClient;
use GlpiPlugin\Threema\GatewayException;

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    $ok || $fail++;
}
function expect_exc(string $name, callable $fn, ?int $code = null, ?bool $permanent = null): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (GatewayException $e) {
        $ok = ($code === null || $e->getHttpCode() === $code) && ($permanent === null || $e->isPermanent() === $permanent);
        check($name, $ok, 'code=' . $e->getHttpCode() . ' permanent=' . var_export($e->isPermanent(), true));
    }
}

// --- Threema's published test vectors (from the API documentation) ---------
check('email hash vector', GatewayClient::hashEmail('test@threema.ch') === '1ea093239cc5f0e1b6ec81b866265b921f26dc4033025410063309f4d1a8ee2c');
check('email hash is case/space insensitive', GatewayClient::hashEmail("  TEST@Threema.CH ") === GatewayClient::hashEmail('test@threema.ch'));
check('phone hash vector', GatewayClient::hashPhone('41791234567') === 'ad398f4d7ebe63c6550a486cc6e07f9baa09bd9d8b3d8cb9d9be106d35a7fdbc');
check('phone normalisation (+, spaces, 00)',
    GatewayClient::normalizePhone('+41 79 123 45 67') === '41791234567'
    && GatewayClient::normalizePhone('0041 79 123 45 67') === '41791234567');

// --- Keys -----------------------------------------------------------------
$kp = GatewayClient::generateKeyPair();
check('generated keys have prefixes', strpos($kp['private'], 'private:') === 0 && strpos($kp['public'], 'public:') === 0);
check('derivePublicKey matches generated', 'public:' . GatewayClient::derivePublicKey($kp['private']) === $kp['public']);
check('parseKey accepts unprefixed hex', strlen(GatewayClient::parseKey(substr($kp['private'], 8), 'private')) === 32);
expect_exc('parseKey rejects garbage', function () { GatewayClient::parseKey('nothex', 'private'); });
check('isValidId', GatewayClient::isValidId('ECHOECHO') && GatewayClient::isValidId('*MYAPIID') && !GatewayClient::isValidId('SHORT') && !GatewayClient::isValidId('lowercase'));

// --- Start the mock gateway -----------------------------------------------
$state = sys_get_temp_dir() . '/threema-mock-' . getmypid() . '.jsonl';
@unlink($state);
$port = 18000 + random_int(0, 999);
$sender_hex = substr($kp['private'], 8);
$sender_pub = GatewayClient::derivePublicKey($kp['private']);
$cmd = sprintf(
    'MOCK_STATE=%s MOCK_SENDER_PUB=%s php -S 127.0.0.1:%d %s >/dev/null 2>&1 & echo $!',
    escapeshellarg($state), escapeshellarg($sender_pub), $port, escapeshellarg(__DIR__ . '/mock_gateway.php')
);
$pid = (int)trim(shell_exec($cmd));
register_shutdown_function(function () use ($pid, $state) { @posix_kill($pid, 15); @unlink($state); });
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) { break; }
    usleep(100000);
}
$base = "http://127.0.0.1:$port";
$log = function () use ($state): array {
    return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($state))));
};

// --- Account / errors -----------------------------------------------------
$basic = new GatewayClient('*MYAPIID', 'SECRET', GatewayClient::MODE_BASIC, null, ['base_url' => $base]);
check('credits', $basic->getCredits() === 42);
$bad = new GatewayClient('*MYAPIID', 'WRONG', GatewayClient::MODE_BASIC, null, ['base_url' => $base]);
expect_exc('wrong secret => 401, retryable', function () use ($bad) { $bad->getCredits(); }, 401, false);

// --- Basic mode -----------------------------------------------------------
check('basic send returns message id', $basic->sendText('echoecho', "Héllo ✓\n*bold*") === 'simple-0001');
$l = $log();
check('basic: params + UTF-8 + id normalised', ($l[0]['to'] ?? '') === 'ECHOECHO' && ($l[0]['text'] ?? '') === "Héllo ✓\n*bold*" && ($l[0]['from'] ?? '') === '*MYAPIID', json_encode($l[0] ?? null));
expect_exc('basic: too long => permanent', function () use ($basic) { $basic->sendText('ECHOECHO', str_repeat('a', 3501)); }, 413, true);
expect_exc('basic: invalid ID => permanent', function () use ($basic) { $basic->sendText('bad id', 'x'); }, 400, true);
expect_exc('basic: 400 from gateway => permanent', function () use ($basic) { $basic->sendText('BADRECIP', 'x'); }, 400, true);
expect_exc('basic: 429 => retryable', function () use ($basic) { $basic->sendText('RATELIMT', 'x'); }, 429, false);
expect_exc('basic: 500 => retryable', function () use ($basic) { $basic->sendText('SERVER00', 'x'); }, 500, false);
$dead = new GatewayClient('*MYAPIID', 'SECRET', GatewayClient::MODE_BASIC, null, ['base_url' => 'http://127.0.0.1:1', 'timeout' => 2]);
expect_exc('network failure => retryable', function () use ($dead) { $dead->sendText('ECHOECHO', 'x'); }, 0, false);

// --- E2E mode (mock decrypts the box with the recipient key) ---------------
$saved = [];
$e2e = new GatewayClient('*MYAPIID', 'SECRET', GatewayClient::MODE_E2E, $kp['private'], [
    'base_url'   => $base,
    'key_loader' => function ($id) use (&$saved) { return $saved[$id] ?? null; },
    'key_saver'  => function ($id, $hex) use (&$saved) { $saved[$id] = $hex; },
]);
foreach (["Hello", "Accents éàü & emoji 🚨", str_repeat('x', 5000)] as $i => $text) {
    check("e2e send #$i", $e2e->sendText('ECHOECHO', $text) === 'e2e-0001');
}
$e2e_rows = array_values(array_filter($log(), fn ($r) => $r['type'] === 'send_e2e'));
check('e2e: mock decrypted all texts exactly', count($e2e_rows) === 3 && $e2e_rows[0]['text'] === 'Hello' && $e2e_rows[1]['text'] === "Accents éàü & emoji 🚨" && $e2e_rows[2]['text'] === str_repeat('x', 5000));
check('e2e: padding in 1..255 and box under limit', $e2e_rows[2]['pad'] >= 1 && $e2e_rows[2]['pad'] <= 255 && $e2e_rows[2]['boxlen'] <= 7812);
$pk_hits = count(array_filter($log(), fn ($r) => $r['type'] === 'pubkeys' && $r['id'] === 'ECHOECHO'));
check('e2e: public key fetched once then cached', $pk_hits === 1 && isset($saved['ECHOECHO']), "hits=$pk_hits");
expect_exc('e2e: unknown recipient (pubkey 404) => permanent', function () use ($e2e) { $e2e->sendText('UNKNOWN0', 'x'); }, 404, true);
expect_exc('e2e: text beyond box limit => permanent', function () use ($e2e) { $e2e->sendText('ECHOECHO', str_repeat('y', 7800)); }, 413, true);
expect_exc('e2e: constructor rejects bad private key', function () use ($base) { new GatewayClient('*MYAPIID', 'SECRET', 'e2e', 'zz', ['base_url' => $base]); });
check('e2e: gateway ID public key matches derived one', $e2e->fetchPublicKey('*MYAPIID') === $sender_pub);

// --- Lookups --------------------------------------------------------------
check('lookup email hit', $basic->lookupEmail('Known@Example.com') === 'ABCD1234');
check('lookup email miss => null', $basic->lookupEmail('nobody@example.com') === null);
check('lookup phone hit', $basic->lookupPhone('0041791234567') === 'ABCD1234');
check('lookup phone miss => null', $basic->lookupPhone('+41790000000') === null);

echo $fail ? "\n$fail FAILED\n" : "\nAll tests passed\n";
exit($fail ? 1 : 0);
