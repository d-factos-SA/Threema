<?php
/**
 * Development test: loads the plugin classes against the REAL GLPI interfaces/abstract classes (GLPI 11 / 12)
 * (NotificationInterface, NotificationEventAbstract, NotificationSetting) with minimal stubs for
 * the rest of GLPI, and runs the delivery code against tests/mock_gateway.php.
 *
 *   GLPI_SRC=/path/to/glpi-11.0.x php tests/glpi_harness_test.php
 *
 * No database and no web server needed.
 */

$glpi = getenv('GLPI_SRC');
if (!$glpi || !is_file("$glpi/src/NotificationEventAbstract.php")) {
    fwrite(STDERR, "Set GLPI_SRC to a GLPI 11 or 12 source tree.\n");
    exit(2);
}

// ---- constants & stubs ------------------------------------------------------------------------
define('ERROR', 2); define('WARNING', 1); define('INFO', 0); define('UPDATE', 4); define('READ', 1);
define('DAY_TIMESTAMP', 86400);
function __($s, $domain = 'glpi') { return $s; }
function _x($ctx, $s, $domain = 'glpi') { return $s; }
function __s($s, $domain = 'glpi') { return htmlspecialchars($s); }

trait CommonGLPIStub
{
    public static function getTypeName($nb = 0) { return ''; }
    public static function getIcon() { return ''; }
    public function addStandardTab($itemtype, &$ong, $options) {}
    public static function createTabEntry($text, $nb = 0) { return $text; }
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { return ''; }
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) { return true; }
    public function defineTabs($options = []) { return []; }
    public function getID() { return 1; }
}
// GLPI 12 types CommonGLPI::$rightname (string), GLPI 11 does not: mirror the real declaration
// (a child class cannot satisfy both, which is why the plugin never redeclares it)
if (preg_match('/static\s+string\s+\$rightname/', (string)file_get_contents("$glpi/src/CommonGLPI.php"))) {
    class CommonGLPI { use CommonGLPIStub; public static string $rightname = ''; }
} else {
    class CommonGLPI { use CommonGLPIStub; public static $rightname = ''; }
}
class CommonDBTM extends CommonGLPI
{
    public $fields = [];
    public static function getTable($classname = null) { return 'glpi_stub'; }
    public function showFormButtons($options = []) {}
    public function getFromDBByCrit($crit) { return false; }
}
abstract class NotificationTarget {}
class NotificationTemplate {}
class Notification_NotificationTemplate { public static function registerMode($m, $l, $f) {} }

class QueuedNotification
{
    public static $rows = [];
    public $fields = [];
    public function getFromResultSet($row) { $this->fields = $row; return true; }
    public function update($in) { foreach ($in as $k => $v) { self::$rows[$in['id']][$k] = $v; $this->fields[$k] = $v; } return true; }
    public function delete($in) { self::$rows[$in['id']]['is_deleted'] = 1; return true; }
    public function add($in) { return true; }
}
class Toolbox
{
    public static $logs = [];
    public static function logInFile($n, $t) { self::$logs[] = [$n, $t]; }
    public static function stripslashes_deep($v) { return $v; }
}
class Session
{
    public static $messages = [];
    public static function addMessageAfterRedirect($m, $c = true, $t = INFO) { self::$messages[] = [$t, $m]; }
    public static function getLoginUserID() { return 7; }
}
class GLPIKey
{
    public function encrypt($s) { return 'enc:' . base64_encode($s); }
    public function decrypt($s) { return substr($s, 0, 4) === 'enc:' ? base64_decode(substr($s, 4)) : $s; }
}
class Config
{
    public static $store = [];
    public static function getConfigurationValues($ctx, array $names = []) { return self::$store[$ctx] ?? []; }
    public static function getConfigurationValue($ctx, $n) { return self::$store[$ctx][$n] ?? null; }
    public static function setConfigurationValues($ctx, array $values = [])
    {
        $k = new GLPIKey();
        foreach ($values as $n => $v) {
            if ($v !== '' && in_array($n, GlpiPlugin\Threema\Config::SECURED, true)) { $v = $k->encrypt($v); }
            self::$store[$ctx][$n] = $v;
        }
    }
}
class FakeDB
{
    public $keys = [];
    public function request($c) { return isset($c['WHERE']['threemaid'], $this->keys[$c['WHERE']['threemaid']]) ? [['publickey' => $this->keys[$c['WHERE']['threemaid']]]] : []; }
    public function updateOrInsert($t, $p, $w) { $this->keys[$w['threemaid']] = $p['publickey']; }
}
$DB = new FakeDB();
$CFG_GLPI = ['language' => 'fr_FR', 'proxy_name' => ''];
$_SESSION = ['glpi_currenttime' => '2026-11-05 07:00:00'];

// ---- real GLPI contract + plugin classes ----------------------------------------------------
foreach (['NotificationInterface', 'NotificationEventInterface', 'NotificationEventAbstract', 'NotificationSetting'] as $c) {
    require_once "$glpi/src/$c.php";
}
$p = dirname(__DIR__);
foreach (['GatewayException', 'GatewayClient', 'Config', 'Message', 'UserId'] as $c) { require_once "$p/src/$c.php"; }
foreach (['notificationthreema', 'notificationeventthreema', 'notificationthreemasetting'] as $c) { require_once "$p/inc/$c.class.php"; }

use GlpiPlugin\Threema\Config as TC;
use GlpiPlugin\Threema\GatewayClient;
use GlpiPlugin\Threema\Message;

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $name . ($ok || $detail === '' ? '' : "  -> $detail") . "\n";
    $ok || $fail++;
}

// ---- 1. GLPI naming/contract ------------------------------------------------------------------
foreach (['PluginThreemaNotificationThreema' => 'NotificationInterface',
          'PluginThreemaNotificationEventThreema' => 'NotificationEventInterface',
          'PluginThreemaNotificationThreemaSetting' => 'NotificationSetting'] as $cls => $parent) {
    $ref = new ReflectionClass($cls);
    check("$cls implements/extends $parent and is instantiable", is_a($cls, $parent, true) && $ref->isInstantiable() || $cls === 'PluginThreemaNotificationEventThreema' && !$ref->isAbstract());
}
// getModeClass() builds names like 'Plugin' . ucfirst($from) . 'NotificationEvent' . ucfirst($mode)
check('class names match what GLPI computes for mode "threema"',
    class_exists('Plugin' . ucfirst('threema') . 'NotificationEvent' . ucfirst('threema'))
    && class_exists('Plugin' . ucfirst('threema') . 'Notification' . ucfirst('threema') . 'Setting')
    && class_exists('Plugin' . ucfirst('threema') . 'Notification' . ucfirst('threema')));
check('setting getMode() is "threema"', PluginThreemaNotificationThreemaSetting::getMode() === 'threema');

// ---- 2. Message formatting ----------------------------------------------------------------
check('subject bold + body', Message::build("Ticket *#1*  new", "Hello\r\n\r\n\r\n\r\nWorld", 1000) === "*Ticket #1 new*\n\nHello\n\nWorld");
$long = Message::build('S', str_repeat('é', 5000), 100);
check('truncation is byte-bounded and UTF-8 safe', strlen($long) <= 100 && mb_check_encoding($long, 'UTF-8') && substr($long, -3) === "\u{2026}", strlen($long) . '');

// ---- 3. Target resolution ---------------------------------------------------------------------
$d = ['threema_id' => 'abcd1234'];
PluginThreemaNotificationEventThreema::getTargetField($d);
check('explicit ID is normalised', $d['threema_id'] === 'ABCD1234');
$d = ['threema_id' => 'bad'];
PluginThreemaNotificationEventThreema::getTargetField($d);
check('invalid ID => null target', $d['threema_id'] === null);
$d = ['users_id' => 0];
PluginThreemaNotificationEventThreema::getTargetField($d);
check('no ID, no user => null target', $d['threema_id'] === null);
check('target field name', PluginThreemaNotificationEventThreema::getTargetFieldName() === 'threema_id');
check('admin data empty until configured', PluginThreemaNotificationEventThreema::getAdminData() === []);
check('entity admins empty', PluginThreemaNotificationEventThreema::getEntityAdminsData(0) === []);

// ---- 4. Config::save validation ---------------------------------------------------------------
$kp = GatewayClient::generateKeyPair();
check('save rejects bad gateway id / key / http URL', count(TC::save(['gateway_id' => 'x', 'mode' => 'e2e', 'private_key' => 'zz', 'base_url' => 'http://evil'])) >= 3);
check('nothing stored on error', (Config::$store[TC::CONTEXT] ?? []) === []);
check('save rejects e2e without key', in_array(true, array_map(fn ($e) => stripos($e, 'requires the private key') !== false, TC::save(['gateway_id' => '*MYAPIID', 'api_secret' => 's', 'mode' => 'e2e', 'base_url' => ''])), true));

// ---- 5. Start mock gateway --------------------------------------------------------------------
$state = sys_get_temp_dir() . '/threema-harness-' . getmypid() . '.jsonl';
@unlink($state);
$port = 19000 + random_int(0, 999);
$pub  = GatewayClient::derivePublicKey($kp['private']);
$pid  = (int)trim(shell_exec(sprintf('MOCK_STATE=%s MOCK_SENDER_PUB=%s php -S 127.0.0.1:%d %s >/dev/null 2>&1 & echo $!',
    escapeshellarg($state), escapeshellarg($pub), $port, escapeshellarg(__DIR__ . '/mock_gateway.php'))));
register_shutdown_function(function () use ($pid, $state) { @posix_kill($pid, 15); @unlink($state); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$sent = function () use ($state) { return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($state)))); };

// Config::save() demands https, so write the test base_url directly (the client itself accepts http)
$errors = TC::save(['gateway_id' => '*myapiid', 'api_secret' => 'SECRET', 'mode' => 'e2e', 'private_key' => $kp['private'],
    'base_url' => '', 'admin_threema_id' => 'echoecho', 'max_retries' => 2, 'retry_delay' => 5, 'max_length' => 500]);
check('valid configuration saved', $errors === [], implode('|', $errors));
Config::$store[TC::CONTEXT]['base_url'] = "http://127.0.0.1:$port";
check('secrets are stored encrypted', strpos(Config::$store[TC::CONTEXT]['api_secret'], 'enc:') === 0 && strpos(Config::$store[TC::CONTEXT]['private_key'], 'enc:') === 0);
check('secrets are decrypted by getAll()', TC::getAll()['api_secret'] === 'SECRET' && TC::getAll()['private_key'] === $kp['private']);
check('empty secret fields keep stored values', TC::save(['gateway_id' => '*MYAPIID', 'mode' => 'e2e', 'api_secret' => '', 'private_key' => '', 'base_url' => '']) === [] && TC::getAll()['api_secret'] === 'SECRET');
Config::$store[TC::CONTEXT]['base_url'] = "http://127.0.0.1:$port";
check('admin target uses configured ID', PluginThreemaNotificationEventThreema::getAdminData() === ['threema_id' => 'ECHOECHO', 'language' => 'fr_FR']);

// ---- 6. Connection / test buttons -------------------------------------------------------------
Session::$messages = [];
check('testConnection OK (credits + key pair)', TC::testConnection() === true && count(Session::$messages) === 2 && Session::$messages[0][0] === INFO, json_encode(Session::$messages));
check('sendTest delivers to admin', TC::sendTest() === true);

// ---- 7. Queue delivery (E2E) ------------------------------------------------------------------
$row = fn (int $id, string $to, int $try = 0) => ['id' => $id, 'recipient' => $to, 'name' => 'Ticket #' . $id . ' créé', 'body_text' => "Un nouveau ticket.\nhttps://glpi.example/front/ticket.form.php?id=$id", 'sent_try' => $try, 'is_deleted' => 0];
QueuedNotification::$rows = [];
$n = PluginThreemaNotificationEventThreema::send([$row(1, 'ECHOECHO'), $row(2, 'BADRECIP'), $row(3, 'SERVER00'), $row(4, 'SERVER00', 2)]);
$e2e = array_values(array_filter($sent(), fn ($r) => $r['type'] === 'send_e2e'));
check('send() returns number of delivered messages', $n === 1, (string)$n);
check('E2E message decrypted by the gateway with expected text', ($e2e[1]['text'] ?? '') === "*Ticket #1 créé*\n\nUn nouveau ticket.\nhttps://glpi.example/front/ticket.form.php?id=1", json_encode($e2e));
check('delivered => sent_time set and soft-deleted', isset(QueuedNotification::$rows[1]['sent_time']) && QueuedNotification::$rows[1]['is_deleted'] === 1);
check('permanent error (400) => given up immediately', QueuedNotification::$rows[2]['is_deleted'] === 1 && QueuedNotification::$rows[2]['sent_try'] === 1);
check('transient error (500) => kept, retried later', !isset(QueuedNotification::$rows[3]['is_deleted']) && QueuedNotification::$rows[3]['sent_try'] === 1 && QueuedNotification::$rows[3]['send_time'] > date('Y-m-d H:i:s'));
check('transient error after max_retries (2) => given up', QueuedNotification::$rows[4]['is_deleted'] === 1);
check('failures logged to threema-error', count(array_filter(Toolbox::$logs, fn ($l) => $l[0] === 'threema-error')) === 3);

// ---- 8. Queue delivery (basic mode) -----------------------------------------------------------
TC::save(['gateway_id' => '*MYAPIID', 'mode' => 'basic', 'base_url' => '', 'max_length' => 3500]);
Config::$store[TC::CONTEXT]['base_url'] = "http://127.0.0.1:$port";
QueuedNotification::$rows = [];
check('basic mode delivery', PluginThreemaNotificationEventThreema::send([$row(10, 'ECHOECHO')]) === 1);
$simple = array_values(array_filter($sent(), fn ($r) => $r['type'] === 'send_simple'));
check('basic message content', ($simple[0]['text'] ?? '') === "*Ticket #10 créé*\n\nUn nouveau ticket.\nhttps://glpi.example/front/ticket.form.php?id=10", json_encode($simple));

// ---- 9. Not configured ------------------------------------------------------------------------
Config::$store[TC::CONTEXT]['api_secret'] = '';
check('send() refuses when not configured', PluginThreemaNotificationEventThreema::send([$row(20, 'ECHOECHO')]) === false);

echo $fail ? "\n$fail FAILED\n" : "\nAll tests passed\n";
exit($fail ? 1 : 0);
