<?php
/** Isolated permission/config/snapshot checks; no GLPI or source DB required. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('UPDATE', 2);
define('GLPI_TMP_DIR', sys_get_temp_dir() . '/ana_security_test_' . bin2hex(random_bytes(8)));
class Config {
    public static array $values = [];
    public static function getConfigurationValues($context): array { return self::$values; }
    public static function setConfigurationValues($context, $values): void { self::$values = array_replace(self::$values, $values); }
}
class Session {
    public static bool $admin = false;
    public static function getLoginUserID(): int { return (int)($_SESSION['glpiID'] ?? 0); }
    public static function haveRight($name, $right): bool { return self::$admin && $name === 'config' && $right === UPDATE; }
}
require_once dirname(__DIR__) . '/inc/autoload.php';
require_once dirname(__DIR__) . '/inc/common.php';
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
use GlpiPlugin\G4freportsana\Database\ConnectionException;
use GlpiPlugin\G4freportsana\Database\MysqlClient;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\SnapshotStore;

$passed = 0;
function ok($condition, string $message): void { global $passed; if (!$condition) throw new RuntimeException($message); ++$passed; }
function rejected(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) { ok(true, $message); return; }
    throw new RuntimeException($message);
}
$cfg = ['enabled' => 1, 'allowed_profiles' => '', 'allowed_groups' => '', 'group_routes_json' => '[]'];
$_SESSION = ['glpiID' => 101, 'glpiactiveprofile' => ['id' => 7], 'glpigroups' => [4, 22]];
ok(Security::currentGroupIds() === [4, 22], 'Array offset1 cannot become membership in group1');
ok(!Security::canUse($cfg), 'Logged-in users denied until report access explicitly configured');
ok(!Security::canUse(array_replace($cfg, ['allowed_groups' => '1'])), 'Numeric session index cannot grantgroup1 access');
ok(Security::canUse(array_replace($cfg, ['allowed_groups' => '4'])), 'Explicit actual membership grants allowedreport access');
ok(Security::canUse(array_replace($cfg, ['allowed_profiles' => '7'])), 'Explicit profile grants allowedreport access');
$_SESSION['glpiID'] = 0;
ok(!Security::canUse(array_replace($cfg, ['allowed_groups' => '4'])), 'Anonymous user denied despite group residue');
$_SESSION['glpiID'] = 101;
Session::$admin = true;
ok(Security::canUse($cfg), 'Administrator can configure first use before access lists');
ok(!Security::canUse(array_replace($cfg, ['enabled' => 0])), 'Disabledplugin denies evenadmin use');
Session::$admin = false;

$defaults = ConnectionConfig::defaults();
ok(isset($defaults['ana_db_host']) && !isset($defaults['ses_db_host']), 'ANA connectionconfig is isolated fromSES');
ok(ConnectionConfig::fromConfig('glpi', $defaults)['source'] === 'ana', 'Repository GLPI alias resolves ANA connection');
$invalidEnv = array_replace($defaults, ['callcenter_db_enabled' => 1, 'callcenter_db_password_env' => 'ANA_TEST_ENV_NOT_PRESENT_8F8711']);
putenv('ANA_TEST_ENV_NOT_PRESENT_8F8711');
rejected(static fn() => ConnectionConfig::fromConfig('callcenter', $invalidEnv), 'Missing credential environment variable fails locally');
$client = new MysqlClient(['host' => 'localhost', 'database' => 'unused', 'username' => 'unused', 'password' => 'SECRET-MUST-NOT-PRINT']);
ob_start(); var_dump($client); $debug = ob_get_clean();
ok(strpos($debug, 'SECRET-MUST-NOT-PRINT') === false && strpos($debug, '[redacted]') !== false, 'Debug output redacts sourcecredentials');
foreach (['UPDATE glpi_tickets SET status=6', 'DELETE FROM glpi_tickets', 'DROP TABLE glpi_tickets', 'SET GLOBAL max_statement_time=0'] as $sql) {
    try { $client->fetchAll($sql); throw new RuntimeException('Unexpected database query acceptance'); }
    catch (ConnectionException $e) { ok($e->getReason() === 'invalid_query', 'Write/global statement rejected before any connection'); }
}

$savedMethod = Settings::methodologyDefaults();
$savedMethod['methodology_confirmed'] = true; $savedMethod['methodology_reference'] = 'Offline test approval reference';
$saved = [Settings::METHOD_CONFIG_KEY => Settings::json($savedMethod)];
$normal = Settings::normalizeInput(['month' => '2026-09'], $saved);
ok($normal['methodology_confirmed'], 'Saved method confirmed when unchanged');
$changed = Settings::normalizeInput(['month' => '2026-09', 'scope_basis' => 'solved', 'methodology_confirmed' => true], $saved);
ok(!$changed['methodology_confirmed'], 'Changing method cannot inherit saved approval');
$forged = Settings::normalizeInput(['month' => '2026-09', 'methodology_confirmed' => true, 'priority_value_map' => ['4' => 'P1'], 'approved_pauses' => [['ticket_id' => 1]], 'queue_names' => ['ssdf']], []);
ok(!$forged['methodology_confirmed'] && $forged['priority_value_map'] === [] && $forged['approved_pauses'] === [], 'Request cannot forge administrator methodology or exceptions');
ok($forged['queue_names'] === ['ana', 'ana-transbordo'], 'Request cannot substitute other contractqueue');
ok(Settings::normalizeInput(['month' => '2026-09', 'entity_id' => '0 OR 1=1', 'n1_group_ids' => [999]], [])['entity_id'] === 0, 'Request cannot change administrator-owned entity');
ok(Settings::normalizeInput(['month' => '2026-09', 'entity_id' => 99, 'n1_group_ids' => [999]], [])['n1_group_ids'] === [4], 'Request cannot change administrator-owned groups');
rejected(static fn() => Settings::methodology(['entity_id' => '0 OR 1=1']), 'Stored entity is integer validated');
rejected(static fn() => Settings::methodology(['methodology_confirmed' => true]), 'Method approval requires documented reference');
rejected(static fn() => Settings::methodology(['approved_pauses' => [['ticket_id' => 925, 'start' => '2026-09-31T08:00:00-03:00', 'end' => '2026-10-02T08:00:00-03:00', 'approval_reference' => 'Test reference']]]), 'Invalid pause calendar date cannot roll into next month');
$oversizedDecisions = array_map(static fn($id) => ['ticket_id' => $id, 'priority' => 'P3', 'reason' => str_repeat('R', 1100)], range(1, 60));
rejected(static fn() => Settings::methodology(['priority_overrides' => $oversizedDecisions]), 'Normalized method exceeding60000 bytes cannot overflow native config storage');

$snapshot = ['rules_version' => Settings::RULES_VERSION, 'metrics' => [['id' => 'IPS1', 'value' => 4.875]], 'input' => [], 'manual' => []];
$token = SnapshotStore::save($snapshot, 101);
ok(strlen($token) === 64 && ctype_xdigit($token), 'Snapshottoken has256bit randomidentifier');
ok(SnapshotStore::load($token, 101)['metrics'][0]['value'] === 4.875, 'Owner can reload exactsnapshot');
rejected(static fn() => SnapshotStore::load($token, 102), 'Differentuser cannot accesssnapshot');
rejected(static fn() => SnapshotStore::load('../' . $token, 101), 'Snapshot path traversal rejected');
rejected(static fn() => SnapshotStore::assertCurrentRules(['rules_version' => 'ses-2026-10-05-v16']), 'Old/foreign rules cannot beexported');
rejected(static fn() => SnapshotStore::assertCurrentRules(['rules_version' => 'ana-2026-10-16-v1']), 'ANA2.0.0 snapshots must regenerate after test-call exclusion correction');
$legacy = $snapshot;
$legacy['rules_version'] = 'ana-2026-10-16-v2';
$legacy['schema_version'] = 3;
SnapshotStore::assertCurrentRules($legacy);
ok(true, 'Compatible v2 snapshot passes the same gate used by the export endpoint');
foreach ([null, 2, 4, '3'] as $schema) {
    $invalid = $legacy;
    if ($schema === null) unset($invalid['schema_version']); else $invalid['schema_version'] = $schema;
    rejected(static fn() => SnapshotStore::assertCurrentRules($invalid), 'Legacy compatibility requires exact schema 3');
}
$legacyToken = SnapshotStore::save($legacy, 101);
$legacyUpdated = SnapshotStore::updateNarrative($legacyToken, 101, ['notes' => 'Historical report note', 'ips6_include_desistencias' => false]);
ok($legacyUpdated['rules_version'] === 'ana-2026-10-16-v2' && $legacyUpdated['metrics'] === $legacy['metrics'], 'Compatible narrative update preserves old rules and metric values');
ok(!array_key_exists('ips6_include_desistencias', $legacyUpdated['input']), 'Compatible report cannot acquire a new eligibility rule through narrative updates');
rejected(static fn() => SnapshotStore::load($legacyToken, 102), 'Legacy compatibility does not bypass snapshot ownership');
$updated = SnapshotStore::updateNarrative($token, 101, ['notes' => 'Documented clarification', 'metrics' => [['id' => 'IPS1', 'value' => 5]], 'financial' => ['payable' => 999999]]);
ok($updated['metrics'][0]['value'] === 4.875 && !isset($updated['financial']), 'Narrative update cannot alter computedmetric/finance');
ok($updated['input']['notes'] === 'Documented clarification', 'Allowed narrativeupdate persists');
foreach (glob(GLPI_TMP_DIR . '/g4freportsana_ana_*/*.json') ?: [] as $path) unlink($path);
foreach (glob(GLPI_TMP_DIR . '/g4freportsana_ana_*') ?: [] as $path) rmdir($path);
rmdir(GLPI_TMP_DIR);
echo "PASS: {$passed} permission, source-guard and snapshot checks. No database connection used.\n";
