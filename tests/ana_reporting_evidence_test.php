<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';
use GlpiPlugin\G4freportsana\Ana\MetricEvidence;
use GlpiPlugin\G4freportsana\Ana\OfficePackage;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$snapshot = ['tickets' => [
    ['id'=>1,'type'=>1,'priority_contract'=>'P1'],
    ['id'=>2,'type'=>1,'priority_contract'=>'P2'],
    ['id'=>3,'type'=>1,'priority_contract'=>'P2'],
    ['id'=>4,'type'=>1,'priority_contract'=>null],
    ['id'=>5,'type'=>2,'priority_contract'=>'P3'],
], 'metrics' => [
    ['id'=>'IPS3','value_kind'=>'priority_percent_ratios','value'=>null,'unresolved_ids'=>[4],
        'denominator_ids'=>[1,2,3,4], 'children'=>[
            ['id'=>'IPS3_P1','priority'=>'P1','evidence_ids'=>[1],'denominator_ids'=>[1],'unresolved_ids'=>[]],
            ['id'=>'IPS3_P2','priority'=>'P2','evidence_ids'=>[],'denominator_ids'=>[2],'unresolved_ids'=>[3]],
        ]],
    ['id'=>'IPS4','value_kind'=>'priority_percent_ratios','value'=>null,'unresolved_ids'=>[],
        'denominator_ids'=>[5], 'children'=>[
            ['id'=>'IPS4_P3','priority'=>'P3','evidence_ids'=>[5],'denominator_ids'=>[5],'unresolved_ids'=>[]],
        ]],
]];
$before = serialize($snapshot);
$rows = MetricEvidence::table($snapshot, 'IPS3')['rows'];
$check(count($rows) === 4, 'Parent evidence must retain full incident cohort');
foreach ([[1,'Sim','Sim','Não'],[2,'Não','Sim','Não'],[3,'Não','Não','Sim'],[4,'Não','Não','Sim']] as $i=>$expected) {
    $actual = [$rows[$i][0],$rows[$i][24],$rows[$i][25],$rows[$i][26]];
    $check($actual === $expected, 'Parent must project child membership and unknown records: '.$expected[0]);
}
$rows = MetricEvidence::table($snapshot, 'IPS3', 'numerator')['rows'];
$check(array_column($rows,0) === [1], 'Parent numerator filter must select actual child contributors');
$rows = MetricEvidence::table($snapshot, 'IPS4')['rows'];
$check(count($rows)===1 && $rows[0][24]==='Sim', 'Request parent uses request-priority result');
$check(serialize($snapshot)===$before, 'Evidence projection must not mutate metrics or compute parent averages');

$financial = ['notes'=>[
    'As linhas monetárias representam pontos antes do limite e PAO. A glosa oficial é calculada sobre a soma dos pontos, com arredondamento monetário uma única vez no total; não some as linhas arredondadas.',
    'Composição financeira por serviços da OS. Quantidades de alocação e inconsistências documentais não são convertidas em quantidade de pessoas; preço zero não comprova dispensa de escopo.',
    'IPS1: Resultado provisório: pendência de método, escopo ou evidência impede glosa definitiva.',
    'IPS1, IPS2: Apuração provisória.',
    'A base e as decisões financeiras da competência ainda não têm confirmação com referência de aprovação.',
    'IPS2: Déficit fracionário: confirme tratamento proporcional, piso ou teto e a referência aprovada.',
    'Ajuste financeiro sem descrição ou referência de aprovação.',
], 'points_total'=>null, 'gross_value'=>'1000.00','net_value'=>null];
$before = serialize($financial);
$notes = OfficePackage::financialNotes($financial);
$check($notes === [
    'Aprovação financeira da competência não registrada.',
    'IPS2: Regra para frações de pontos não definida.',
    'Ajuste financeiro sem descrição ou referência de aprovação.',
], 'Old snapshots retain factual financial limitations without template prose');
$check(serialize($financial)===$before, 'Presentation must leave frozen financial values and notes untouched');
echo "PASS: $checks reporting evidence and legacy-presentation checks.\n";
