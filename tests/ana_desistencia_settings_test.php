<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\EvidenceSettings;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$legacy = Settings::methodology('{}');
$check($legacy['ips6_include_desistencias'] === true, 'Existing installations include short abandonments by default');
foreach ([true, false] as $value) {
    $method = Settings::methodology(['ips6_include_desistencias' => $value]);
    $json = Settings::methodologyJson($method);
    $check(Settings::methodologyFromSettings([Settings::METHOD_CONFIG_KEY => $json])['ips6_include_desistencias'] === $value, 'Save/read roundtrip preserves either choice');
    $normalized = Settings::normalizeInput(['month' => '2026-10', 'ips6_include_desistencias' => !$value], [Settings::METHOD_CONFIG_KEY => $json]);
    $check($normalized['ips6_include_desistencias'] === $value, 'Report request cannot override the administrator choice');
    $check($normalized['methodology_revision'] === Settings::revision($method), 'Snapshot revision includes the saved choice');
}
$exclude = $legacy;
$exclude['ips6_include_desistencias'] = false;
$check(Settings::revision($legacy) !== Settings::revision($exclude), 'Changing eligibility changes the methodology revision');
foreach (['false', 2, [], 'exclude'] as $bad) {
    try { Settings::methodology(['ips6_include_desistencias' => $bad]); throw new RuntimeException('Invalid choice was accepted'); }
    catch (InvalidArgumentException $e) { $check(true, 'Invalid nonboolean value rejected'); }
}
$source = ['forwarded_calls' => 100, 'answered_calls' => 98, 'evidence_reference' => 'Network reconciliation'];
$normalize = static function (array $phone): array {
    return EvidenceSettings::normalize(['months' => ['2026-10' => ['phone' => $phone]]])['months']['2026-10']['phone'];
};
$check($normalize($source)['desisted_calls'] === null && $normalize($source)['valid'], 'Old network totals remain valid without an invented short-call count');
foreach ([0, 2] as $count) {
    $phone = $normalize($source + ['desisted_calls' => $count]);
    $check($phone['valid'] && $phone['desisted_calls'] === $count, 'Known zero and valid short-call count retained');
}
foreach ([-1, 0.5, 3, 'invalid'] as $bad) {
    try { $normalize($source + ['desisted_calls' => $bad]); throw new RuntimeException('Invalid count accepted'); }
    catch (InvalidArgumentException $e) { $check(true, 'Invalid count rejected'); }
}
echo "PASS: $checks desistência configuration and source validation checks.\n";
