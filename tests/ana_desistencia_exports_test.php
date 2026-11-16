<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freportsana\Ana\Calculator;
use GlpiPlugin\G4freportsana\Ana\DocxExporter;
use GlpiPlugin\G4freportsana\Ana\FinancialSummary;
use GlpiPlugin\G4freportsana\Ana\FinanceSettings;
use GlpiPlugin\G4freportsana\Ana\MetricEvidence;
use GlpiPlugin\G4freportsana\Ana\OfficePackage;
use GlpiPlugin\G4freportsana\Ana\PhoneData;
use GlpiPlugin\G4freportsana\Ana\XlsxExporter;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function verifyExport(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function exportMetric(array $snapshot): array {
    foreach ($snapshot['metrics'] as $metric) if ($metric['id'] === 'IPS6') return $metric;
    throw new RuntimeException('IPS6 missing');
}
function phoneSnapshot(array $input, array $calls): array {
    $snapshot = Calculator::build($input, ['tickets' => [], 'calls' => $calls, 'calls_available' => true]);
    $snapshot['financial'] = FinancialSummary::build($snapshot);
    return $snapshot;
}
/** Read exported values by cell coordinates, including blank evidence columns. */
function sheetRows(string $xml): array {
    $doc = new DOMDocument();
    verifyExport($doc->loadXML($xml, LIBXML_NONET), 'Invalid worksheet XML');
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    foreach ($xp->query('//s:sheetData/s:row') as $row) {
        $values = [];
        foreach ($xp->query('s:c', $row) as $cell) {
            preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $match);
            $column = 0;
            foreach (str_split($match[1]) as $letter) $column = $column * 26 + ord($letter) - 64;
            $values[$column] = $xp->evaluate('string(s:v|s:is/s:t)', $cell);
        }
        $rows[(int)$row->getAttribute('r')] = $values;
    }
    return $rows;
}
function labelledValue(array $rows, string $label, int $labelColumn = 1): ?string {
    foreach ($rows as $row) if (($row[$labelColumn] ?? '') === $label) return $row[$labelColumn + 1] ?? '';
    return null;
}
function recordRows(array $rows, string $firstHeader): array {
    $headers = null; $records = [];
    foreach ($rows as $row) {
        if (($row[1] ?? '') === $firstHeader) { $headers = $row; continue; }
        if ($headers === null) continue;
        $record = [];
        foreach ($headers as $column => $name) $record[$name] = $row[$column] ?? '';
        $records[] = $record;
    }
    return $records;
}
function docValue(string $xml, string $label): ?string {
    $doc = new DOMDocument(); $doc->loadXML($xml, LIBXML_NONET);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    foreach ($xp->query('//w:tr') as $row) {
        if ($xp->evaluate('string(w:tc[1])', $row) === $label) return $xp->evaluate('string(w:tc[2])', $row);
    }
    return null;
}
function sheetFormula(string $xml, string $cell): string {
    $doc = new DOMDocument(); $doc->loadXML($xml, LIBXML_NONET);
    $xp = new DOMXPath($doc); $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $xp->evaluate('string(//s:c[@r="' . $cell . '"]/s:f)');
}
function renderSnapshot(array $snapshot, string $case): array {
    global $argv;
    $before = serialize($snapshot);
    $xlsx = new XlsxExporter(); $docx = new DocxExporter();
    verifyExport($xlsx->filename($snapshot) === 'Relatorio_Mensal_ANA_2026-10.xlsx', 'Stable Excel filename');
    verifyExport($docx->filename($snapshot) === 'Relatorio_Mensal_ANA_2026-10.docx', 'Stable Word filename');
    $xlsxBinary = $xlsx->toBinary($snapshot); $docxBinary = $docx->toBinary($snapshot);
    $parts = OfficePackage::read($xlsxBinary); $wordParts = OfficePackage::read($docxBinary);
    $doc = new DOMDocument(); $doc->loadXML($parts['xl/workbook.xml'], LIBXML_NONET);
    $xp = new DOMXPath($doc); $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $sheets = $sheetXml = [];
    foreach ($xp->query('//s:sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        $sheetXml[$name] = $parts['xl/worksheets/sheet' . $sheet->getAttribute('sheetId') . '.xml'];
        $sheets[$name] = sheetRows($sheetXml[$name]);
    }
    verifyExport(serialize($snapshot) === $before, 'Exports must not mutate the frozen snapshot: ' . $case);
    if (isset($argv[1])) {
        $directory = rtrim($argv[1], '/') . '/' . $case;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Cannot create export test directory');
        file_put_contents($directory . '/' . $xlsx->filename($snapshot), $xlsxBinary);
        file_put_contents($directory . '/' . $docx->filename($snapshot), $docxBinary);
    }
    return [$sheets, $wordParts['word/document.xml'], $parts, $sheetXml];
}

$start = strtotime('2026-10-01T10:00:00-03:00');
$input = ['month' => '2026-10', 'start_epoch' => $start - 36000, 'end_epoch' => $start - 36000 + 31 * 86400,
    'timezone' => 'America/Sao_Paulo', 'queue_names' => ['ana', 'ana-transbordo'], 'call_unit' => 'interaction',
    'methodology_confirmed' => true, 'methodology_reference' => 'Export regression fixture',
    'financial_config' => FinanceSettings::fromSettings([])];
$legs = [];
foreach (['ANSWER' => null, 'SHORT' => 29, 'LONG' => 30, 'UNKNOWN' => null] as $id => $wait) {
    $legs[$id] = ['id' => 'leg-' . $id, 'callid' => $id, 'queue' => 'ana', 'caller' => '61900000000',
        'entered_epoch' => $start, 'entered_at' => '2026-10-01 10:00:00', 'ended_epoch' => $start + ($wait ?? 120),
        'ended_at' => '2026-10-01 10:02:00', 'answered' => $id === 'ANSWER', 'abandoned' => $id !== 'ANSWER',
        'wait_seconds' => $id === 'ANSWER' ? 5 : $wait, 'wait_basis' => 'queue_log_registered',
        'talk_seconds' => $id === 'ANSWER' ? 115 : null, 'answered_epoch' => $id === 'ANSWER' ? $start + 5 : null,
        'answered_at' => $id === 'ANSWER' ? '2026-10-01 10:00:05' : null];
}
$calls = [$legs['ANSWER'], $legs['SHORT'], $legs['LONG']];
$policyLabel = 'Desistências no IPS6 (espera inferior a 30 segundos)';
$shortLabel = 'Desistências (espera inferior a 30 segundos)';
foreach ([true, false] as $include) {
    $snapshot = phoneSnapshot($input + ['ips6_include_desistencias' => $include], $calls);
    $metric = exportMetric($snapshot);
    [$sheets, $word] = renderSnapshot($snapshot, $include ? 'included' : 'excluded');
    verifyExport(labelledValue($sheets['Metodologia'], $policyLabel) === ($include ? 'Incluídas' : 'Excluídas'), 'Excel applied policy');
    verifyExport(docValue($word, $policyLabel) === ($include ? 'Incluídas' : 'Excluídas'), 'Word applied policy');
    verifyExport(($sheets['IPS6'][8][2] ?? '') === '1' && ($sheets['IPS6'][9][2] ?? '') === ($include ? '3' : '2'), 'Excel IPS6 operands follow policy');
    verifyExport(docValue($word, 'Chamadas encaminhadas') === ($include ? '3' : '2'), 'Word IPS6 denominator follows policy');
    verifyExport(labelledValue($sheets['Resumo'], 'Ligações recebidas', 2) === '3' && docValue($word, 'Ligações recebidas') === '3', 'Operational received count stays unchanged');
    verifyExport(labelledValue($sheets['Resumo'], $shortLabel, 2) === '1' && docValue($word, $shortLabel) === '1', 'Short-call count distinct from abandonment');
    verifyExport(docValue($word, 'Ligações abandonadas') === '1', 'Only long caller departure is abandonment');
    verifyExport(docValue($word, 'Abandonos sem tempo de espera apurado') === null, 'No empty unknown-wait summary row');
    $evidence = MetricEvidence::table($snapshot, 'IPS6', 'all');
    $records = array_combine(array_column($evidence['rows'], 0), array_map(static fn(array $row): array => array_combine($evidence['headers'], $row), $evidence['rows']));
    verifyExport($records['SHORT']['Desistência (espera inferior a 30 segundos)'] === 'Sim' && $records['SHORT']['Abandonada'] === 'Não', 'Evidence short classification');
    verifyExport($records['SHORT']['Denominador'] === ($include ? 'Sim' : 'Não') && $records['SHORT']['Excluída do IPS6'] === ($include ? 'Não' : 'Sim'), 'Evidence participation flags');
    verifyExport(count(MetricEvidence::table($snapshot, 'IPS6')['rows']) === ($include ? 3 : 2), 'Strict denominator evidence scope');
    verifyExport(array_column(MetricEvidence::table($snapshot, 'IPS6', 'numerator')['rows'], 0) === ['ANSWER'], 'Strict numerator evidence scope');
    $exported = recordRows($sheets['IPS6'], 'Identificador');
    verifyExport(count($exported) === 3 && in_array('SHORT', array_column($exported, 'Identificador'), true), 'IPS6 worksheet retains excluded operational calls');
    $base = recordRows($sheets['Ligacoes consideradas'], 'Registro ID');
    verifyExport(count($base) === 3, 'Operational base retains every call');
    $short = array_values(array_filter($base, static fn(array $row): bool => $row['Call ID'] === 'SHORT'))[0];
    verifyExport($short['Elegível nas filas para IPS6'] === ($include ? '1' : '0'), 'Operational base records eligibility');
    verifyExport($short['Motivo de inelegibilidade nas filas'] === ($include ? '' : 'Desistência com espera inferior a 30 segundos.'), 'Operational base records factual exclusion reason');
    $raw = recordRows($sheets['Passagens por fila'], 'Registro ID');
    verifyExport($raw[0]['Elegível nas filas para IPS6'] === '', 'Raw legs do not invent interaction membership');
    if (!$include) verifyExport(docValue($word, 'Desistências excluídas do IPS6') === '1', 'Word explains denominator exclusion count');
}

$excludeInput = $input + ['ips6_include_desistencias' => false];
$zero = phoneSnapshot($excludeInput, [$legs['SHORT']]);
[$zeroSheets, $zeroWord, , $zeroXml] = renderSnapshot($zero, 'zero-eligible');
verifyExport(($zeroSheets['IPS6'][9][2] ?? '') === '0' && ($zeroSheets['IPS6'][10][2] ?? '') === 'Sem ocorrências', 'Zero eligible calls stays no occurrences');
verifyExport(docValue($zeroWord, 'Ligações recebidas') === '1' && docValue($zeroWord, 'Chamadas encaminhadas') === '0', 'Zero-eligible Word keeps operational volume');
verifyExport(sheetFormula($zeroXml['IPS6'], 'B10') === '', 'No division formula for an empty eligible population');
$unknown = phoneSnapshot($excludeInput, array_merge($calls, [$legs['UNKNOWN']]));
[$unknownSheets, $unknownWord, , $unknownXml] = renderSnapshot($unknown, 'unknown-wait');
verifyExport(docValue($unknownWord, 'Abandonos sem tempo de espera apurado') === '1', 'Word shows actual unknown-wait count');
verifyExport(labelledValue($unknownSheets['Resumo'], 'Abandonos sem tempo de espera apurado', 2) === '1', 'Excel shows actual unknown-wait count');
verifyExport(($unknownSheets['IPS6'][9][2] ?? '') === '' && sheetFormula($unknownXml['IPS6'], 'B9') === '' && sheetFormula($unknownXml['IPS6'], 'B10') === '', 'Unknown eligibility cannot recalculate into a numeric denominator or ratio');
verifyExport(docValue($unknownWord, 'Ligações recebidas') === '4' && docValue($unknownWord, 'Chamadas encaminhadas') === null, 'Pending Word metric preserves operational volume without an assumed denominator');
$unknownBase = recordRows($unknownSheets['Ligacoes consideradas'], 'Registro ID');
verifyExport($unknownBase[3]['Elegível nas filas para IPS6'] === 'Pendente', 'Operational base shows pending eligibility for missing wait');
$unknownRows = MetricEvidence::table($unknown, 'IPS6', 'all');
$last = array_combine($unknownRows['headers'], $unknownRows['rows'][3]);
verifyExport($last['Abandono sem espera apurada'] === 'Sim' && $last['Denominador'] === 'Pendente' && $last['Pendente'] === 'Sim' && $last['Desistência (espera inferior a 30 segundos)'] === 'Não', 'Unknown waiting has pending eligibility, never guessed short');
verifyExport(count(MetricEvidence::table($unknown, 'IPS6')['rows']) === 2, 'Strict denominator scope omits the call with unconfirmed eligibility');

// Aggregate network evidence has its own short-call count, independent of queue records.
$networkInput = $excludeInput;
$networkInput['external_evidence'] = ['phone' => ['forwarded_calls' => 100, 'answered_calls' => 95, 'desisted_calls' => 3, 'evidence_reference' => 'Network register']];
$network = phoneSnapshot($networkInput, $calls);
[$networkSheets, $networkWord] = renderSnapshot($network, 'network');
verifyExport(docValue($networkWord, 'Ligações recebidas') === '100' && docValue($networkWord, 'Chamadas encaminhadas') === '97', 'Network raw volume and eligible denominator stay distinct');
verifyExport(docValue($networkWord, $shortLabel) === '3' && labelledValue($networkSheets['Resumo'], $shortLabel, 2) === '3', 'Network short count does not use queue count');
verifyExport(labelledValue($networkSheets['Metodologia'], 'Desistências — evidência da rede') === '3', 'Network short source count exported');
verifyExport(OfficePackage::phoneTotals($network)['mean_wait_seconds'] === null && OfficePackage::phoneTotals($network)['calls_abandoned'] === null, 'No mixed-source abandonment or average');
$networkRows = MetricEvidence::table($network, 'IPS6', 'all');
verifyExport($networkRows['rows'][0][16] === null && $networkRows['rows'][0][17] === null && MetricEvidence::table($network, 'IPS6')['rows'] === [], 'Aggregate network operands are not attributed to queue rows');
unset($networkInput['external_evidence']['phone']['desisted_calls']);
$networkMissing = phoneSnapshot($networkInput, $calls);
[$missingSheets, $missingWord] = renderSnapshot($networkMissing, 'network-missing-short-count');
verifyExport(docValue($missingWord, 'Ligações recebidas') === '100' && docValue($missingWord, 'Ligações atendidas') === '95', 'Known network volumes remain visible when eligible denominator is pending');
verifyExport(docValue($missingWord, $shortLabel) === null && labelledValue($missingSheets['Resumo'], $shortLabel, 2) === null, 'Missing network desistências never become zero or queue count');

// Frozen pre-option reports retain their saved aggregate and classification.
$legacy = phoneSnapshot($input, $calls);
unset($legacy['input']['ips6_include_desistencias'], $legacy['totals']['calls_desisted'], $legacy['totals']['calls_abandon_wait_unknown']);
$legacy['totals']['calls_abandoned'] = 2;
foreach (['calls', 'call_legs'] as $key) foreach ($legacy[$key] as &$call) {
    $call['abandoned'] = $call['callid'] !== 'ANSWER';
    foreach (['abandon_recorded', 'desisted', 'abandon_wait_unknown', 'ips6_included', 'ips6_exclusion_reason'] as $field) unset($call[$field]);
}
unset($call);
foreach ($legacy['metrics'] as &$metric) if ($metric['id'] === 'IPS6') foreach (['desistencias_included', 'excluded_count', 'excluded_ids'] as $field) unset($metric[$field]);
unset($metric);
[$legacySheets, $legacyWord] = renderSnapshot($legacy, 'legacy');
verifyExport(docValue($legacyWord, 'Ligações abandonadas') === '2' && docValue($legacyWord, $shortLabel) === null, 'Historical classification and missing split preserved');
verifyExport(docValue($legacyWord, 'Chamadas encaminhadas') === '3' && docValue($legacyWord, $policyLabel) === 'Incluídas', 'Historical denominator and inclusion default preserved');
$legacyRows = MetricEvidence::table($legacy, 'IPS6', 'all');
verifyExport($legacyRows['rows'][1][7] === 'Sim' && $legacyRows['rows'][1][14] === null && $legacyRows['rows'][1][15] === null, 'Historical rows are not reclassified during evidence projection');
$legacyNetwork = $legacy;
foreach ($legacyNetwork['metrics'] as &$metric) if ($metric['id'] === 'IPS6') $metric['evidence_reference'] = 'Historical network register';
unset($metric);
unset($legacyNetwork['external_evidence'], $legacyNetwork['input']['external_evidence'], $legacyNetwork['manual']);
verifyExport(OfficePackage::phoneTotals($legacyNetwork)['calls_received'] === 3 && OfficePackage::phoneTotals($legacyNetwork)['calls_desisted'] === null, 'Historical network total fallback does not invent a short-call split');

// Retain the source field that proves waiting time for EXITWITHKEY.
$entry = ['id' => 1, 'epoch' => $start, 'callid' => 'KEY', 'queuename' => 'ana', 'event' => 'ENTERQUEUE', 'agent' => 'NONE', 'data1' => '', 'data2' => '61900000000', 'data3' => '1', 'data4' => ''];
$exit = array_replace($entry, ['id' => 2, 'epoch' => $start + 27, 'event' => 'EXITWITHKEY', 'data1' => '#', 'data2' => '1', 'data3' => '1', 'data4' => '27']);
$keySnapshot = phoneSnapshot($input, PhoneData::buildLegs([$entry], [$entry, $exit]));
[$keySheets] = renderSnapshot($keySnapshot, 'raw-wait-evidence');
$events = recordRows($keySheets['Eventos telefonicos'], 'Passagem ID');
verifyExport($events[1]['Evento'] === 'EXITWITHKEY' && $events[1]['data4 original'] === '27', 'Raw event export preserves data4 wait evidence');

echo "Desistência export regression: {$checks} checks passed\n";
