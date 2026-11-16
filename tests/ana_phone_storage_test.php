<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freportsana\Ana\PhoneData;
use GlpiPlugin\G4freportsana\Ana\Repository;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
use GlpiPlugin\G4freportsana\Database\ConnectionException;
use GlpiPlugin\G4freportsana\Database\MysqlClient;

$checks = 0;
function checkPhone(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function phoneFailure(callable $operation, string $reason, string $label): void {
    try { $operation(); } catch (ConnectionException $e) {
        checkPhone($e->getReason() === $reason, $label . ': ' . $e->getReason());
        return;
    }
    throw new RuntimeException($label . ': expected rejection');
}

/** Only the transport is replaced. PhoneData::load itself is exercised. */
final class PhoneStorageTransport extends MysqlClient {
    public array $reads = [];
    public array $metadataReads = [];
    public array $anchors = [];
    public array $events = [];
    public array $probe = [];
    public function __construct(public ?int $timeType) {}
    public function columnTypes(string $sql): array {
        $this->metadataReads[] = $sql;
        return $this->timeType === null ? [] : ['time' => $this->timeType];
    }
    public function fetchAll(string $sql, array $parameters = [], int $maxRows = 100000): array {
        $this->reads[] = ['sql' => $sql, 'parameters' => $parameters, 'max_rows' => $maxRows];
        if ($sql === 'SELECT q.`time` AS source_time FROM queue_log q ORDER BY q.id DESC LIMIT 1') return $this->probe;
        if (str_contains($sql, "q.event='ENTERQUEUE'")) return $this->anchors;
        if (str_contains($sql, 'WHERE q.callid IN (')) return $this->events;
        throw new RuntimeException('Unexpected phone query');
    }
}
function phoneRows(int $epoch, string $local, bool $timestamp): array {
    $date = new DateTimeImmutable($local, new DateTimeZone('America/Sao_Paulo'));
    $rows = [];
    foreach ([['ENTERQUEUE', 0], ['CONNECT', 10], ['COMPLETEAGENT', 70]] as $i => [$event, $offset]) {
        $rows[] = [
            'id' => 11 + $i, 'callid' => '123.45', 'queuename' => 'ana', 'event' => $event, 'agent' => 'SIP/100',
            'source_time' => $timestamp ? gmdate('Y-m-d H:i:s', $epoch + $offset) : $date->modify('+' . $offset . ' seconds')->format('Y-m-d H:i:s'),
            'epoch' => $timestamp ? $epoch + $offset : null,
            'data1' => $event === 'ENTERQUEUE' ? '' : '10',
            'data2' => $event === 'ENTERQUEUE' ? '0000000000' : ($event === 'COMPLETEAGENT' ? '60' : ''),
            'data3' => '',
        ];
    }
    return $rows;
}
$period = Repository::period(['month' => '2026-10', 'timezone' => 'America/Sao_Paulo']);
$baseInput = ['queue_names' => ['ana', 'ana-transbordo']];
foreach ([
    MYSQLI_TYPE_TIMESTAMP => 'timestamp',
    MYSQLI_TYPE_DATETIME => 'datetime',
    MYSQLI_TYPE_VAR_STRING => 'varchar',
    MYSQLI_TYPE_STRING => 'char',
] as $native => $expectedType) {
    $client = new PhoneStorageTransport($native);
    $client->events = phoneRows($period['start_epoch'], '2026-10-01 00:00:00', $expectedType === 'timestamp');
    $client->anchors = [$client->events[0]];
    $client->probe = [['source_time' => '2026-11-04 08:00:00']];
    $warnings = [];
    $legs = PhoneData::load($client, $baseInput, $period, $warnings);
    checkPhone($client->metadataReads === ['SELECT `time` FROM `queue_log` LIMIT 0'], 'Native metadata query only');
    checkPhone(count($legs) === 1 && $legs[0]['source_time_type'] === $expectedType, $expectedType . ' supported');
    checkPhone($legs[0]['entered_epoch'] === $period['start_epoch'], $expectedType . ' local month starts at same instant');
    checkPhone($legs[0]['answered'] && $legs[0]['talk_seconds'] === 60 && $legs[0]['wait_seconds'] === 10, $expectedType . ' call outcome preserved');
    checkPhone($warnings === [], $expectedType . ' creates no configuration-confirmation warning');
    $anchor = $client->reads[0];
    $expectedBoundary = $expectedType === 'timestamp' ? [$period['start_epoch'], $period['end_epoch']] : ['2026-10-01 00:00:00', '2026-11-01 00:00:00'];
    checkPhone($anchor['parameters'] === array_merge($expectedBoundary, ['ana', 'ana-transbordo', 'ana', 'ana-transbordo']), $expectedType . ' exact month/queue parameters');
    checkPhone(str_contains($anchor['sql'], 'AND q.queuename IN (?,?) AND BINARY q.queuename IN (?,?)'), 'Exact case-sensitive queue filter retained');
    checkPhone($anchor['max_rows'] === 30000 && !str_contains($anchor['sql'], 'CAST(') && !str_contains($anchor['sql'], 'CONVERT('), 'Entry cap and sargable time column retained');
    $lastRead = $client->reads[count($client->reads) - 1];
    checkPhone($lastRead['parameters'] === ['123.45', 'ana', 'ana-transbordo', 'ana', 'ana-transbordo'] && $lastRead['max_rows'] === 250000, 'Call-ID event correlation and cap retained');
    if (in_array($expectedType, ['varchar', 'char'], true)) {
        checkPhone(count($client->reads) === 2, 'Selected calendar-text events require no global format probe');
    }
}
foreach ([null, 0, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_NEWDECIMAL] as $native) {
    $client = new PhoneStorageTransport($native);
    $warnings = [];
    phoneFailure(static function () use ($client, $baseInput, $period, &$warnings) {
        PhoneData::load($client, $baseInput, $period, $warnings);
    }, 'unsupported_time_type', 'Unsupported or absent native metadata');
    checkPhone($client->reads === [], 'Unknown storage cannot produce empty-call success');
}
foreach (['1790823600', '', '2026-10-32 10:00:00', '2026-10-01 25:00:00', '2026-10-01T00:00:00Z', '01/10/2026 00:00:00'] as $raw) {
    $client = new PhoneStorageTransport(MYSQLI_TYPE_VAR_STRING);
    $client->probe = [['source_time' => $raw]];
    $warnings = [];
    phoneFailure(static function () use ($client, $baseInput, $period, &$warnings) {
        PhoneData::load($client, $baseInput, $period, $warnings);
    }, 'invalid_phone_date', 'Noncanonical text source rejected');
    checkPhone(count($client->reads) === 2 && $client->reads[1]['max_rows'] === 1 && !str_contains($client->reads[1]['sql'], 'WHERE'), 'Empty text range is checked with one global time value before returning zero');
}
$client = new PhoneStorageTransport(MYSQLI_TYPE_VAR_STRING);
$client->probe = [['source_time' => '2026-10-31 23:59:59.123456']];
$client->events = phoneRows($period['start_epoch'], '2026-10-01 00:00:00', false);
$client->events[1]['source_time'] = 'invalid';
$client->anchors = [$client->events[0]];
$warnings = [];
phoneFailure(static function () use ($client, $baseInput, $period, &$warnings) {
    PhoneData::load($client, $baseInput, $period, $warnings);
}, 'invalid_phone_date', 'Every selected event is validated without a global format probe');

$client = new PhoneStorageTransport(MYSQLI_TYPE_VAR_STRING);
$client->probe = [['source_time' => 'malformed unrelated queue time']];
$client->events = phoneRows($period['start_epoch'], '2026-10-01 00:00:00', false);
$client->anchors = [$client->events[0]];
$warnings = [];
$legs = PhoneData::load($client, $baseInput, $period, $warnings);
checkPhone(count($legs) === 1 && $legs[0]['talk_seconds'] === 60 && count($client->reads) === 2, 'Valid selected ANA events do not depend on unrelated latest source row');
checkPhone(!array_filter($client->reads, static fn(array $read): bool => str_contains($read['sql'], 'ORDER BY q.id DESC LIMIT 1')), 'No global format probe when ANA anchors exist');

$client = new PhoneStorageTransport(MYSQLI_TYPE_VAR_STRING);
$warnings = [];
$legs = PhoneData::load($client, $baseInput, $period, $warnings);
checkPhone($legs === [] && count($client->reads) === 2, 'Empty source with no text sample remains a valid zero-call population');

$client = new PhoneStorageTransport(MYSQLI_TYPE_VAR_STRING);
$client->probe = [['source_time' => '2026-10-31 23:00:00']];
$warnings = [];
PhoneData::load($client, $baseInput + ['callcenter_timezone' => 'America/Manaus'], $period, $warnings);
checkPhone(array_slice($client->reads[0]['parameters'], 0, 2) === ['2026-09-30 23:00:00', '2026-10-31 23:00:00'], 'Calendar text query uses source-zone boundaries for report month');

$client = new PhoneStorageTransport(MYSQLI_TYPE_DATETIME);
$warnings = [];
PhoneData::load($client, $baseInput + ['callcenter_timezone' => ''], $period, $warnings);
checkPhone(array_slice($client->reads[0]['parameters'], 0, 2) === ['2026-10-01 00:00:00', '2026-11-01 00:00:00'], 'Blank override uses established default');
$client = new PhoneStorageTransport(MYSQLI_TYPE_TIMESTAMP);
$warnings = [];
PhoneData::load($client, $baseInput + ['callcenter_timezone' => 'Invalid/Unused'], $period, $warnings);
checkPhone(count($client->reads) === 1, 'TIMESTAMP uses stored instant without date-text override');

$client = new PhoneStorageTransport(MYSQLI_TYPE_DATETIME);
$warnings = [];
phoneFailure(static function () use ($client, $baseInput, $period, &$warnings) {
    PhoneData::load($client, $baseInput + ['callcenter_timezone' => 'Invalid/Zone'], $period, $warnings);
}, 'invalid_timezone', 'Invalid used override remains a phone configuration error');
checkPhone($client->reads === [], 'Invalid used timezone prevents phone data query');

$normalized = ConnectionConfig::prepareUpdate(['callcenter_db_timezone' => '  ']);
checkPhone($normalized['callcenter_db_timezone'] === 'America/Sao_Paulo', 'Optional blank configuration saves default');
$input = Settings::normalizeInput(['month' => '2026-10'], ['callcenter_db_enabled' => 0, 'callcenter_db_timezone' => 'Invalid/Unused']);
checkPhone($input['month'] === '2026-10' && $input['timezone'] === 'America/Sao_Paulo', 'Unused phone override cannot block GLPI report input');
$input = Settings::normalizeInput(['month' => '2026-10'], ['callcenter_db_timezone' => '']);
checkPhone($input['callcenter_timezone'] === 'America/Sao_Paulo', 'Blank saved override normalizes to default');

$metadata = new class extends MysqlClient {
    public array $reads = [];
    public function __construct() {}
    public function fetchAll(string $sql, array $parameters = [], int $maxRows = 100000): array {
        $this->reads[] = [$sql, $parameters, $maxRows];
        return [];
    }
};
$metadata->columnTypes('SELECT `time` FROM `queue_log` LIMIT 0');
checkPhone($metadata->reads === [['SELECT `time` FROM `queue_log` LIMIT 0', [], 1]], 'Metadata reuses bounded read-only transport');
phoneFailure(static fn() => $metadata->columnTypes('SELECT `time` FROM `queue_log`'), 'invalid_query', 'Metadata method rejects a row-reading query');

foreach ([MYSQLI_TYPE_TIMESTAMP => 'available', MYSQLI_TYPE_DATETIME => 'available', MYSQLI_TYPE_VAR_STRING => 'available', MYSQLI_TYPE_LONGLONG => 'unsupported_time_type'] as $native => $status) {
    $result = new class($native) {
        public bool $freed = false;
        public function __construct(private int $type) {}
        public function fetch_fields(): array { return [(object)['name' => 'time', 'type' => $this->type]]; }
        public function free(): void { $this->freed = true; }
    };
    $connection = new class($result) {
        public array $queries = [];
        public function __construct(private object $result) {}
        public function query(string $sql): object { $this->queries[] = $sql; return $this->result; }
    };
    $method = new ReflectionMethod(MysqlClient::class, 'checkTable');
    $check = $method->invoke($metadata, $connection, 'queue_log', ['required' => true, 'columns' => ['id', 'time']]);
    checkPhone($check['status'] === $status && $result->freed, 'Connection test agrees with extraction storage support and frees metadata');
}
echo "ANA phone storage: {$checks} checks passed; no database connection.\n";

