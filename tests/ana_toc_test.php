<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freportsana\Ana\DocxExporter;
use GlpiPlugin\G4freportsana\Ana\OfficePackage;
$metrics = [];
for ($i=1; $i<=10; $i++) {
    $metrics[] = ['id'=>'IPS'.$i, 'label'=>'Indicador de teste '.$i, 'value_kind'=>'percent_ratio',
        'unit'=>'percent', 'status'=>'provisional', 'numerator'=>1,'denominator'=>1,'value'=>100,
        'target'=>98, 'evidence_complete'=>true, 'children'=>[], 'unresolved_ids'=>[], 'method'=>'Teste'];
}
$snapshot = [
    'period'=>['month'=>'2026-10', 'label'=>'Outubro 2026'],
    'input'=>['report_title'=>'Relatório de teste ANA'],
    'metrics'=>$metrics, 'totals'=>['tickets'=>0, 'incidents'=>0, 'requests'=>0],
    'tickets'=>[], 'financial'=>[], 'external_evidence'=>[],
];
$parts = OfficePackage::read((new DocxExporter())->toBinary($snapshot));
$doc = $parts['word/document.xml'];
$settings = $parts['word/settings.xml'];
function checkTOC(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
checkTOC(str_contains($doc, 'TOC \o "1-2" \h \z \u'), 'Missing native TOC field');
checkTOC(str_contains($settings, '<w:updateFields w:val="true"/>'), 'Missing automatic update flag');
checkTOC(substr_count($doc, '<w:bookmarkStart') === 21, 'Wrong bookmark count');
checkTOC(substr_count($doc, ' PAGEREF G4F_ANA_TOC_') === 21, 'Missing page references');
checkTOC(substr_count($doc, '<w:pageBreakBefore') >= 3, 'Cover, TOC and IPS sections not separated');
checkTOC(strpos($doc, 'SUMÁRIO') < strpos($doc, '1 Apresentação e composição financeira'), 'TOC not before chapter 1');
checkTOC(str_contains($doc, '<w:tab w:val="right" w:leader="dot"'), 'Missing dotted tab leaders');
checkTOC(str_contains($parts['word/styles.xml'], 'w:styleId="TOC2"'), 'Missing level 2 TOC style');
echo "OK: TOC on page 2, real chapters, bookmarks, page references, update fields\n";
