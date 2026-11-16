<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/Ana/FinanceSettings.php';
require_once __DIR__ . '/../src/Ana/EvidenceSettings.php';
require_once __DIR__ . '/../src/Ana/FinancialSummary.php';

use GlpiPlugin\G4freportsana\Ana\FinanceSettings;
use GlpiPlugin\G4freportsana\Ana\EvidenceSettings;
use GlpiPlugin\G4freportsana\Ana\FinancialSummary;

$checks = 0;
function check($condition, string $description): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($description);
}
function throws(callable $callback, string $description): void {
    try { $callback(); } catch (InvalidArgumentException $e) { check(true, $description); return; }
    check(false, $description);
}
function metric(string $id, $value = 100, string $status = 'met'): array {
    return ['id' => $id, 'label' => $id, 'value' => $value,
        'value_kind' => $id === 'IPS1' ? 'mean_score' : ($id === 'IPS9' ? 'compliance' : 'percent_ratio'),
        'status' => $status, 'evidence_complete' => true];
}
function fixture(): array {
    $metrics = [];
    for ($i = 1; $i <= 10; $i++) {
        $id = 'IPS' . $i;
        $row = metric($id, $i === 1 ? 4.875 : ($i === 9 ? true : 100));
        if ($i === 3 || $i === 4) {
            $row['value_kind'] = 'priority_percent_ratios';
            $row['value'] = null;
            $row['children'] = [metric($id . '_P1'), metric($id . '_P2'), metric($id . '_P3')];
        }
        $metrics[] = $row;
    }
    return ['period' => ['month' => '2026-09'], 'input' => ['month' => '2026-09',
        'financial_config' => ['months' => ['2026-09' => [
            'confirmed' => true, 'approval_reference' => 'Fixture: aprovação mensal',
            'pao_applicable' => false, 'point_rounding' => 'proportional', 'rounding_reference' => 'Fixture: proporção',
            'money_rounding' => 'half_up', 'money_rounding_reference' => 'Fixture: centavo mais próximo',
        ]]]], 'metrics' => $metrics];
}
function setMetric(array &$snapshot, string $id, array $changes): void {
    foreach ($snapshot['metrics'] as &$row) {
        if ($row['id'] === $id) { $row = array_replace($row, $changes); return; }
        foreach ($row['children'] ?? [] as $index => $child) if ($child['id'] === $id) {
            $row['children'][$index] = array_replace($child, $changes); return;
        }
    }
    throw new RuntimeException('Fixture metric absent: ' . $id);
}

$baseline = FinanceSettings::defaultBaseline();
check(array_sum(array_map(static function ($row) { return FinanceSettings::cents($row['amount']); }, $baseline['services'])) === 34061090, 'Seven service amounts must equal ANA OS total.');
check($baseline['effective_from'] === '2026-03-01' && $baseline['effective_to'] === '2027-04-03', 'Actual OS effective dates.');
check(FinanceSettings::money('R$ 340.610,90', false) === '340610.90', 'Brazilian currency parser.');
check(FinanceSettings::cents('-0.01') === -1 && FinanceSettings::amount(-1) === '-0.01', 'Signed cents exact.');
check(FinanceSettings::money('0001.2', false) === '1.20', 'Canonical decimal strings.');
check(FinanceSettings::amount(FinanceSettings::cents('999999999.99')) === '999999999.99', 'No binary floating conversion for currency.');
throws(static function () { FinanceSettings::money('1.005'); }, 'Reject hidden money rounding.');
throws(static function () { FinanceSettings::money('NaN'); }, 'Reject NaN money.');
throws(static function () { FinanceSettings::normalize(['months' => ['2026-13' => []]]); }, 'Reject invalid month.');
throws(static function () { FinanceSettings::normalize(['months' => ['2026-09' => ['trs' => [['code' => '50']]]]]); }, 'Reject unknown TRS code.');
throws(static function () { FinanceSettings::normalize(['months' => ['2026-09' => ['indicator_decisions' => [['id' => 'IPS2', 'points' => 0]]]]]); }, 'No arbitrary financial replacement of fixed IPS policies.');
throws(static function () { FinanceSettings::normalize('{"months":{}}' . str_repeat(' ', 60000)); }, 'Configuration cannot exceed GLPI TEXT storage.');
$config = FinanceSettings::fromSettings([]);
check($config === FinanceSettings::normalize(FinanceSettings::json($config)), 'Finance normalize is idempotent.');
check(FinanceSettings::revision($config) === FinanceSettings::revision(FinanceSettings::normalize(FinanceSettings::json($config))), 'Finance revision stable across JSON.');

$s = fixture(); $f = FinancialSummary::build($s);
check($f['complete'] && $f['points_total'] == 0 && $f['net_value'] === '340610.90', 'All proved met metrics give zero deduction and correct monthly base.');
check(count($f['point_items']) === 14, 'IPS3 and IPS4 each assessed across three priorities.');
check($f['point_items'][0]['value'] === 4.875 && $f['point_items'][0]['unit'] === 'score', 'Mean score never recomputed as percentage.');
$s = fixture(); setMetric($s, 'IPS1', ['value' => 3.9, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check($f['complete'] && $f['points_total'] == 2 && $f['deduction_total'] === '681.22', 'IPS1 each full 0.1 below 4 yields two points.');
$s = fixture(); setMetric($s, 'IPS6', ['value' => 98.9, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check($f['complete'] && $f['points_total'] == 10 && $f['deduction_total'] === '3406.11', 'IPS6 full 0.1 percentage-point deficit yields 10 points = 1%.');
$s = fixture(); setMetric($s, 'IPS2', ['value' => 97.5, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check($f['points_total'] == 2.5 && $f['deduction_total'] === '851.53', 'Explicit proportional point fractions.');
$s['input']['financial_config']['months']['2026-09']['point_rounding'] = 'floor'; $f = FinancialSummary::build($s);
check($f['points_total'] == 0, 'Explicit floor applies to deficit steps.');
$s['input']['financial_config']['months']['2026-09']['point_rounding'] = 'ceil'; $f = FinancialSummary::build($s);
check($f['points_total'] == 5, 'Explicit ceiling applies to deficit steps.');
$s['input']['financial_config']['months']['2026-09']['point_rounding'] = 'pending'; $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['net_value'] === null && $f['points_total'] === null, 'Unresolved fraction cannot become final zero deduction.');
$s = fixture(); setMetric($s, 'IPS3', ['unresolved_ids' => [9001], 'evidence_complete' => false, 'status' => 'pending']); $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['points_total'] === null && $f['net_value'] === null, 'Unknown priority in parent prevents financial finalization despite apparently met children.');
$s = fixture(); setMetric($s, 'IPS4', ['unresolved_ids' => [], 'evidence_complete' => true, 'status' => 'provisional']); $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['points_total'] === null, 'Provisional priority parent cannot be bypassed by valid-looking children.');
$s = fixture(); setMetric($s, 'IPS3_P1', ['value' => 80, 'status' => 'unmet']); setMetric($s, 'IPS3_P2', ['value' => 89, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check($f['points_total'] == 55, 'Priority deficits are assessed separately, not averaged.');
$s = fixture(); setMetric($s, 'IPS6', ['value' => 0, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check($f['applied_rate_total'] == 30 && $f['deduction_total'] === '102183.27', 'Combined monthly deduction capped at 30%.');
$m = &$s['input']['financial_config']['months']['2026-09'];
$m['pao_applicable'] = true; $m['pao_reference'] = 'Fixture first OS and PAO'; $m['pao_from'] = '2026-07-01'; $m['pao_to'] = '2026-09-30';
$m['cap_pao_order'] = 'cap_then_pao'; $m['cap_pao_reference'] = 'Fixture explicit approved sequence'; unset($m);
$f = FinancialSummary::build($s);
check($f['applied_rate_total'] == 21 && $f['deduction_total'] === '71528.29', 'PAO reduces assessed cap by 30%, retaining 70%.');
$s['input']['financial_config']['months']['2026-09']['cap_pao_order'] = 'pao_then_cap'; $f = FinancialSummary::build($s);
check($f['applied_rate_total'] == 30, 'Alternative explicitly approved ordering is distinct at cap.');
$s['input']['financial_config']['months']['2026-09']['pao_from'] = '2026-10-01'; $s['input']['financial_config']['months']['2026-09']['pao_to'] = '2026-12-31'; $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['net_value'] === null, 'Unrelated PAO date range cannot reduce this month.');
$s = fixture(); setMetric($s, 'IPS6', ['value' => 98.9, 'status' => 'unmet']);
$s['input']['financial_config']['months']['2026-09']['money_rounding'] = 'pending'; $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['net_value'] === null, 'Fractional cents require a recorded rule.');
$s = fixture(); setMetric($s, 'IPS2', ['value' => 95.14, 'status' => 'provisional']); $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['net_value'] === null, 'Native provisional metric cannot authorize financial result.');
$s = fixture(); setMetric($s, 'IPS10', ['value' => null, 'status' => 'not_applicable']); $f = FinancialSummary::build($s);
check(!$f['complete'], 'SIEM zero price alone cannot exempt IPS10.');
$s['input']['financial_config']['months']['2026-09']['indicator_decisions'] = [['id' => 'IPS10', 'applicable' => false, 'reference' => 'Fixture dated scope decision']]; $f = FinancialSummary::build($s);
check($f['complete'], 'Explicit referenced applicability decision allowed and preserved.');
$s = fixture(); setMetric($s, 'IPS9', ['value' => null, 'status' => 'not_applicable']);
$s['input']['external_evidence'] = ['controls_applicable' => false, 'controls_scope_reference' => 'Fixture approved scope for this month']; $f = FinancialSummary::build($s);
check($f['complete'], 'Existing explicit IPS9 scope decision does not require duplicate financial entry.');
$s = fixture(); setMetric($s, 'IPS9', ['value' => false, 'status' => 'unmet']); $f = FinancialSummary::build($s);
check(!$f['complete'], 'IPS9 failed agreed controls require agreed points.');
$s['input']['financial_config']['months']['2026-09']['indicator_decisions'] = [['id' => 'IPS9', 'applicable' => true, 'points' => 15, 'reference' => 'Fixture agreed implementation schedule']]; $f = FinancialSummary::build($s);
check($f['complete'] && $f['points_total'] == 15, 'IPS9 agreed points.');
$s = fixture(); $s['input']['financial_config']['months']['2026-09']['trs'] = [['code' => '48', 'description' => 'Fixture approved occurrence', 'points' => 3, 'quantity' => 2, 'approved' => true, 'reference' => 'Fixture fiscal decision']]; $f = FinancialSummary::build($s);
check($f['complete'] && $f['points_total'] == 6, 'Approved TRS contributes to same points total.');
$s['input']['financial_config']['months']['2026-09']['trs'][0]['approved'] = false; $f = FinancialSummary::build($s);
check(!$f['complete'] && $f['net_value'] === null, 'Unapproved TRS remains pending, not zero.');
$s = fixture(); $s['input']['financial_config']['months']['2026-09']['adjustments'] = [['description' => 'Fixture adjustment', 'amount' => '-0.01', 'reference' => 'Fixture approved']]; $f = FinancialSummary::build($s);
check($f['net_value'] === '340610.89', 'Signed adjustment exact cents.');
$s = fixture(); $s['period']['month'] = $s['input']['month'] = '2027-04'; $f = FinancialSummary::build($s);
check(!$f['available'] && $f['net_value'] === null, 'Partial OS month not silently prorated.');
$s = fixture(); unset($s['input']['financial_config']); $f = FinancialSummary::build($s);
check($f['available'] && !$f['complete'] && $f['gross_value'] === '340610.90' && $f['net_value'] === null, 'Known baseline is reference until monthly confirmation.');

$empty = EvidenceSettings::forMonth([], '2026-09');
check($empty['phone']['forwarded_calls'] === null && $empty['controls_applicable'] === null && !$empty['services'], 'Missing evidence remains absent.');
$e = EvidenceSettings::normalize(['months' => ['2026-09' => ['services' => [['metric_id' => 'IPS7', 'service' => 'Fixture service', 'window_seconds' => 1000, 'downtime_seconds' => 10, 'excluded_seconds' => 100, 'evidence_reference' => 'Fixture monitoring']]]]]);
check(!$e['months']['2026-09']['services'][0]['valid'], 'Unapproved downtime exclusion invalidates measured service.');
$e['months']['2026-09']['services'][0]['approval_reference'] = 'Fixture maintenance approval'; $e = EvidenceSettings::normalize($e);
check($e['months']['2026-09']['services'][0]['valid'], 'Approved valid exclusion accepted.');
$e['months']['2026-09']['services'][0]['downtime_seconds'] = 901; $e = EvidenceSettings::normalize($e);
check(!$e['months']['2026-09']['services'][0]['valid'], 'Downtime cannot exceed measurable window.');
$window = ['metric_id' => 'IPS10', 'service' => 'Fixture SIEM', 'window_seconds' => 30 * 86400, 'downtime_seconds' => 0, 'evidence_reference' => 'Fixture whole September window'];
$e = EvidenceSettings::normalize(['months' => ['2026-09' => ['services' => [$window]]]]);
check($e['months']['2026-09']['services'][0]['valid'], 'Whole September measurement window is valid.');
$window['window_seconds']++;
$e = EvidenceSettings::normalize(['months' => ['2026-09' => ['services' => [$window]]]]);
check(!$e['months']['2026-09']['services'][0]['valid'], 'Availability window cannot exceed actual month duration.');
$window['window_seconds'] = 29 * 86400;
$e = EvidenceSettings::normalize(['months' => ['2026-02' => ['services' => [$window]]]]);
check(!$e['months']['2026-02']['services'][0]['valid'], 'Availability window bound follows actual month, not a fixed 31-day limit.');
$e = EvidenceSettings::normalize(['months' => ['2026-09' => ['phone' => ['forwarded_calls' => 71, 'answered_calls' => 72, 'evidence_reference' => 'Fixture']]]]);
check(!$e['months']['2026-09']['phone']['valid'], 'Invalid telephone coverage cannot support 100%.');
throws(static function () { EvidenceSettings::normalize(['months' => ['2026-09' => ['phone' => ['forwarded_calls' => -1]]]]); }, 'Reject negative call counts.');
throws(static function () { EvidenceSettings::normalize(['months' => ['2026-09' => ['phone' => ['forwarded_calls' => 1.5]]]]); }, 'Reject fractional call counts.');
throws(static function () { EvidenceSettings::normalize(['months' => ['2026-09' => ['services' => [['metric_id' => 'IPS8', 'window_seconds' => INF]]]]]); }, 'Reject nonfinite durations.');
check($e === EvidenceSettings::normalize(EvidenceSettings::json($e)), 'Evidence normalize idempotent even when incomplete.');
echo 'ANA finance/evidence: ' . $checks . " checks passed.\n";
