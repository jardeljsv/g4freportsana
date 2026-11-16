<?php
/** A transport stub verifies failure isolation; no operational values invented. */
declare(strict_types=1);
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    class Config {
        public static array $values = [];
        public static function getConfigurationValues($context): array { return self::$values; }
    }
}
namespace GlpiPlugin\G4freportsana\Database {
    /** Empty read transport. Deliberately has no socket/connection implementation. */
    final class MysqlClient {
        public static array $sources = [];
        public function __construct(array $profile) { self::$sources[] = $profile['source']; }
        public function fetchAll(string $sql, array $parameters = [], int $maxRows = 100000): array {
            if (!preg_match('/^\s*SELECT\b/', $sql)) throw new \RuntimeException('Unexpected non-read statement');
            if (str_contains($sql, 'FROM glpi_groups WHERE')) return array_map(static fn($id) => ['id' => $id, 'name' => 'Group #' . $id, 'entity_id' => 0, 'parent_id' => 0], $parameters);
            if (str_contains($sql, 'FROM glpi_entities WHERE')) return [['id' => 0, 'name' => 'Test entity', 'entities_id' => null, 'calendars_id' => 0, 'calendars_strategy' => 0]];
            if (str_contains($sql, '@@session.time_zone')) return [['session_timezone' => '+00:00', 'system_timezone' => 'UTC', 'extraction_epoch' => 1792097143]];
            if (str_contains($sql, 'FROM glpi_tickets t')) return [];
            throw new \RuntimeException('Unexpected source query in transport-boundary test');
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/inc/autoload.php';
    use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
    use GlpiPlugin\G4freportsana\Database\MysqlClient;
    use GlpiPlugin\G4freportsana\Ana\Repository;
    putenv('ANA_TEST_GLPI_ENV_8F8711=LOCAL-OFFLINE-TEST-ONLY');
    putenv('ANA_TEST_PHONE_MISSING_ENV_8F8711');
    Config::$values = array_replace(ConnectionConfig::defaults(), [
        'ana_db_enabled' => 1, 'ana_db_host' => 'localhost', 'ana_db_name' => 'offline', 'ana_db_user' => 'offline',
        'ana_db_password_env' => 'ANA_TEST_GLPI_ENV_8F8711',
        'callcenter_db_enabled' => 1, 'callcenter_db_host' => 'localhost', 'callcenter_db_name' => 'offline',
        'callcenter_db_user' => 'offline', 'callcenter_db_password_env' => 'ANA_TEST_PHONE_MISSING_ENV_8F8711',
    ]);
    $result = Repository::extract(['month' => '2026-09', 'timezone' => 'America/Sao_Paulo', 'entity_id' => 0]);
    if ($result['calls_available'] !== false || $result['tickets'] !== [] || count($result['selection_catalog']['groups']) !== 11) throw new RuntimeException('Phone credential failure discarded independent GLPI extraction');
    if (MysqlClient::$sources !== ['ana']) throw new RuntimeException('Unexpected phone transport creation');
    if (str_contains(json_encode($result), 'LOCAL-OFFLINE-TEST-ONLY') || str_contains(json_encode($result), 'ANA_TEST_PHONE_MISSING_ENV')) throw new RuntimeException('Credentials or environment variable identifiers leaked into dataset');
    putenv('ANA_TEST_GLPI_ENV_8F8711');
    echo "PASS: Missing phone credential environment preserves GLPI data and creates no phone connection; diagnostics remain sanitized.\n";
}
