<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/inc/autoload.php';

use GlpiPlugin\G4freportsana\Ana\Calculator;
use GlpiPlugin\G4freportsana\Ana\FinancialSummary;
use GlpiPlugin\G4freportsana\Ana\FinanceSettings;
use GlpiPlugin\G4freportsana\Ana\PhoneData;

$checks=0;
function checkDesistencia(bool $condition,string $label):void {
    global $checks;
    if(!$condition)throw new RuntimeException($label);
    $checks++;
}
function ips6(array $snapshot):array {
    foreach($snapshot['metrics'] as $metric)if($metric['id']==='IPS6')return $metric;
    throw new RuntimeException('IPS6 absent');
}
function ips6Points(array $snapshot) {
    foreach(FinancialSummary::build($snapshot)['point_items'] as $item)if($item['metric_id']==='IPS6')return $item['points'];
    throw new RuntimeException('IPS6 financial item absent');
}
function parsedLegs(array $visits):array {
    $anchors=$events=[];$id=1;
    foreach($visits as $v){
        $entry=['id'=>$id++,'epoch'=>$v['start'],'callid'=>$v['callid'],'queuename'=>$v['queue']??'ana',
            'event'=>'ENTERQUEUE','agent'=>'NONE','data1'=>'','data2'=>'61999990000','data3'=>'1','data4'=>''];
        $anchors[]=$entry;$events[]=$entry;
        if($v['terminal']==='ANSWERED'){
            $events[]=array_replace($entry,['id'=>$id++,'epoch'=>$v['start']+5,'event'=>'CONNECT','agent'=>'SIP/fixture','data1'=>'5']);
            $events[]=array_replace($entry,['id'=>$id++,'epoch'=>$v['start']+15,'event'=>'COMPLETEAGENT','agent'=>'SIP/fixture','data1'=>'5','data2'=>'10']);
        }elseif($v['terminal']!=='NONE'){
            $wait=$v['wait']??'';
            $event=array_replace($entry,['id'=>$id++,'epoch'=>$v['start']+($v['elapsed']??max(1,is_numeric($wait)?(int)$wait:20)),
                'event'=>$v['terminal'],'data1'=>'1','data2'=>'1','data3'=>(string)$wait]);
            if($v['terminal']==='EXITWITHKEY'){$event['data3']='1';$event['data4']=(string)$wait;}
            $events[]=$event;
        }
    }
    return PhoneData::buildLegs($anchors,$events);
}
$start=strtotime('2026-10-01T00:00:00-03:00');
$input=['month'=>'2026-10','start_epoch'=>$start,'end_epoch'=>$start+31*86400,'timezone'=>'America/Sao_Paulo',
    'queue_names'=>['ana','ana-transbordo'],'call_unit'=>'interaction','methodology_confirmed'=>true,
    'methodology_reference'=>'Test fixture','financial_config'=>FinanceSettings::fromSettings([])];
function callSnapshot(array $input,array $legs):array {
    return Calculator::build($input,['tickets'=>[],'calls'=>$legs,'calls_available'=>true]);
}

// The exact boundary is below 30 seconds, including a genuine recorded zero.
$visits=[];
foreach([0,29,30,31] as $index=>$wait)$visits[]=['callid'=>'boundary-'.$wait,'start'=>$start+100*$index,'terminal'=>'ABANDON','wait'=>$wait];
$visits[]=['callid'=>'answered','start'=>$start+500,'terminal'=>'ANSWERED'];
$legs=parsedLegs($visits);
$default=callSnapshot($input,$legs);
$included=callSnapshot($input+['ips6_include_desistencias'=>true],$legs);
checkDesistencia(ips6($default)===ips6($included),'Absent setting preserves inclusion default');
checkDesistencia(ips6($included)['numerator']===1&&ips6($included)['denominator']===5&&ips6($included)['value']===20.0,'Default counts short calls in denominator only');
checkDesistencia($included['totals']['calls_received']===5&&$included['totals']['calls_desisted']===2&&$included['totals']['calls_abandoned']===2,'Operational totals split short and long caller departures');
foreach(array_slice($included['calls'],0,4) as $index=>$call){
    $wait=[0,29,30,31][$index];
    checkDesistencia($call['wait_seconds']===$wait,'Consolidated unanswered wait retained: '.$wait);
    checkDesistencia($call['desisted']===($wait<30)&&$call['abandoned']===($wait>=30)&&$call['abandon_recorded'],'Threshold classification: '.$wait);
    checkDesistencia(!$call['unknown_outcome']&&!$call['abandon_wait_unknown'],'Known abandonment is a known outcome: '.$wait);
}
$excludeInput=$input+['ips6_include_desistencias'=>false];
$excluded=callSnapshot($excludeInput,$legs);
checkDesistencia(ips6($excluded)['numerator']===1&&ips6($excluded)['denominator']===3,'Exclusion changes only eligible denominator');
checkDesistencia(ips6($excluded)['excluded_ids']===['boundary-0','boundary-29']&&ips6($excluded)['excluded_count']===2,'Exact excluded interaction IDs retained');
checkDesistencia($excluded['totals']===$included['totals']&&count($excluded['calls'])===5&&count($excluded['call_legs'])===5,'Operational calls and raw legs remain present');
checkDesistencia(!$excluded['calls'][0]['ips6_included']&&$excluded['calls'][0]['ips6_exclusion_reason']==='Desistência com espera inferior a 30 segundos.','Reason attached to excluded call');
checkDesistencia($excluded['calls'][2]['ips6_included'],'Exactly 30 seconds is included');
checkDesistencia(!$excluded['calls'][4]['abandon_recorded']&&!$excluded['calls'][4]['desisted']&&$excluded['calls'][4]['ips6_included'],'Answered calls cannot be excluded as desistências');

// Queue timeout/empty/key exits expose actual hold time, not position or key.
foreach(['EXITWITHTIMEOUT','EXITEMPTY','EXITWITHKEY'] as $terminal){
    $journey=parsedLegs([
        ['callid'=>'overflow','queue'=>'ana','start'=>$start,'terminal'=>$terminal,'wait'=>20],
        ['callid'=>'overflow','queue'=>'ana-transbordo','start'=>$start+20,'terminal'=>'ABANDON','wait'=>20],
    ]);
    $s=callSnapshot($excludeInput,$journey);
    checkDesistencia($journey[0]['wait_seconds']===20,'Documented wait field: '.$terminal);
    checkDesistencia(count($s['calls'])===1&&$s['calls'][0]['wait_seconds']===40&&$s['calls'][0]['abandoned']&&!$s['calls'][0]['desisted'],'Overflow accumulates 20+20 seconds: '.$terminal);
    checkDesistencia(ips6($s)['denominator']===1&&ips6($s)['excluded_count']===0,'Long overflow remains eligible: '.$terminal);
}
$shortJourney=parsedLegs([
    ['callid'=>'short-overflow','queue'=>'ana','start'=>$start,'terminal'=>'EXITWITHTIMEOUT','wait'=>10],
    ['callid'=>'short-overflow','queue'=>'ana-transbordo','start'=>$start+10,'terminal'=>'ABANDON','wait'=>19],
]);
$s=callSnapshot($excludeInput,$shortJourney);
checkDesistencia($s['calls'][0]['wait_seconds']===29&&ips6($s)['denominator']===0&&ips6($s)['value']===null&&ips6($s)['status']==='no_occurrences','All eligible calls excluded is not 100 percent');
$queueInput=$excludeInput;$queueInput['call_unit']='queue_leg';
$byLeg=callSnapshot($queueInput,$shortJourney);
checkDesistencia(count($byLeg['calls'])===2&&ips6($byLeg)['denominator']===1&&ips6($byLeg)['excluded_count']===1,'Explicit queue-leg policy evaluates each queue visit');

// Missing or malformed waits never grant a short-call exemption.
foreach(['',null,'-1','not-a-duration','1.5'] as $wait){
    $bad=parsedLegs([['callid'=>'unknown-wait','start'=>$start,'terminal'=>'ABANDON','wait'=>$wait]]);
    $s=callSnapshot($excludeInput,$bad);
    checkDesistencia($s['calls'][0]['wait_seconds']===null&&$s['calls'][0]['abandon_wait_unknown']&&!$s['calls'][0]['unknown_outcome'],'Invalid wait retains known hangup and unknown wait');
    checkDesistencia(ips6($s)['denominator']===null&&ips6($s)['value']===null&&ips6($s)['observed_compliance']===null&&ips6($s)['status']==='pending'&&ips6($s)['unresolved_ids']===['unknown-wait']&&!ips6($s)['evidence_complete'],'Uncertain exclusion has no established denominator or result');
    $with=callSnapshot($input,$bad);
    checkDesistencia(ips6($with)['denominator']===1&&ips6($with)['unresolved_ids']===[],'Unknown wait does not block unconditional inclusion');
}
$mixedUnknown=parsedLegs([
    ['callid'=>'known-answer','start'=>$start,'terminal'=>'ANSWERED'],
    ['callid'=>'unknown-wait','start'=>$start+100,'terminal'=>'ABANDON','wait'=>''],
]);
$s=callSnapshot($excludeInput,$mixedUnknown);
checkDesistencia(ips6($s)['numerator']===1&&ips6($s)['denominator']===null&&ips6($s)['value']===null&&ips6($s)['status']==='pending','Known answer does not establish denominator with unknown exclusion eligibility');
checkDesistencia(ips6($s)['cohort_count']===2&&ips6($s)['evaluated_count']===1&&ips6($s)['coverage_percent']===50,'Unknown eligibility reduces evaluated coverage');
checkDesistencia(ips6($s)['denominator_ids']===['known-answer','unknown-wait']&&ips6($s)['queue_observed']['received']===2&&ips6($s)['queue_observed']['answered']===1&&count($s['calls'])===2,'Candidate identifiers and operational totals remain available');
checkDesistencia(ips6Points($s)===null,'Unknown queue eligibility cannot become assessed financial points');
$knownOutcome=callSnapshot($input,$mixedUnknown);
checkDesistencia(ips6($knownOutcome)['denominator']===2&&ips6($knownOutcome)['value']===50.0&&ips6($knownOutcome)['evaluated_count']===2&&ips6($knownOutcome)['coverage_percent']===100,'Unconditional inclusion evaluates the known unanswered outcome');
$incomplete=parsedLegs([
    ['callid'=>'incomplete','queue'=>'ana','start'=>$start,'terminal'=>'EXITWITHTIMEOUT','wait'=>''],
    ['callid'=>'incomplete','queue'=>'ana-transbordo','start'=>$start+20,'terminal'=>'ABANDON','wait'=>10],
]);
$s=callSnapshot($excludeInput,$incomplete);
checkDesistencia($s['calls'][0]['wait_seconds']===null&&ips6($s)['excluded_count']===0&&ips6($s)['unresolved_ids']===['incomplete'],'Missing first queue wait is not replaced by the short final wait');
$open=parsedLegs([['callid'=>'open','start'=>$start,'terminal'=>'NONE']]);
$s=callSnapshot($excludeInput,$open);
checkDesistencia($s['calls'][0]['unknown_outcome']&&!$s['calls'][0]['desisted']&&ips6($s)['excluded_count']===0,'No final event is not a desistência');
checkDesistencia(ips6($s)['denominator']===1&&ips6($s)['status']==='provisional'&&ips6($s)['evaluated_count']===0&&ips6($s)['coverage_percent']===0,'Existing provisional outcome remains provisional with accurate coverage');

// Answered interactions remain answered even if a later destination abandons.
$transferred=parsedLegs([
    ['callid'=>'transfer','queue'=>'ana','start'=>$start,'terminal'=>'ANSWERED'],
    ['callid'=>'transfer','queue'=>'ana-transbordo','start'=>$start+20,'terminal'=>'ABANDON','wait'=>10],
]);
$s=callSnapshot($excludeInput,$transferred);
checkDesistencia(count($s['calls'])===1&&$s['calls'][0]['answered']&&!$s['calls'][0]['abandon_recorded']&&ips6($s)['numerator']===1&&ips6($s)['denominator']===1,'Later hangup cannot undo an earlier confirmed answer');
$different=$transferred;$different[1]['callid']='different';
$s=callSnapshot($excludeInput,$different);
checkDesistencia(count($s['calls'])===2&&ips6($s)['numerator']===1&&ips6($s)['excluded_count']===1,'Different call IDs are not joined by caller number');

// Administrative network totals have their own explicit short-call evidence.
$network=$input;
$network['external_evidence']['phone']=['forwarded_calls'=>100,'answered_calls'=>98,'desisted_calls'=>2,'evidence_reference'=>'Network fixture'];
$s=callSnapshot($network,$legs);
checkDesistencia(ips6($s)['value']===98.0&&ips6($s)['excluded_count']===0&&ips6($s)['excluded_ids']===[],'Network inclusion ignores queue exclusion counts');
checkDesistencia(ips6Points($s)===100.0,'Financial assessment uses included network denominator');
$network['ips6_include_desistencias']=false;$s=callSnapshot($network,$legs);
checkDesistencia(ips6($s)['numerator']===98.0&&ips6($s)['denominator']===98.0&&ips6($s)['value']===100.0&&ips6($s)['excluded_count']===2,'Network exclusion uses the network short-call count');
checkDesistencia(ips6($s)['excluded_ids']===[],'Network totals do not fabricate row-level identifiers');
checkDesistencia(ips6Points($s)===0.0,'Financial assessment follows the exclusion-adjusted metric');
$networkWithQueueUncertainty=callSnapshot($network,$mixedUnknown);
checkDesistencia(ips6($networkWithQueueUncertainty)['status']==='met'&&ips6($networkWithQueueUncertainty)['value']===100.0&&ips6($networkWithQueueUncertainty)['denominator']===98.0&&ips6($networkWithQueueUncertainty)['unresolved_ids']===[],'Valid network totals override queue eligibility uncertainty');
checkDesistencia(ips6($networkWithQueueUncertainty)['evaluated_count']===98&&ips6($networkWithQueueUncertainty)['coverage_percent']===100.0&&ips6Points($networkWithQueueUncertainty)===0.0,'Valid network override restores complete coverage and financial calculation');
foreach([null,-1,3,1.5,'invalid'] as $invalid){
    $network['external_evidence']['phone']['desisted_calls']=$invalid;$s=callSnapshot($network,$legs);
    checkDesistencia(ips6($s)['status']==='pending'&&ips6($s)['value']===null&&ips6($s)['denominator']===null&&!ips6($s)['evidence_complete'],'Missing or incompatible network short-call total remains unavailable');
    checkDesistencia(ips6Points($s)===null,'Unavailable network exclusion evidence cannot become zero financial points');
}
$network['external_evidence']['phone']['desisted_calls']=0;$s=callSnapshot($network,$legs);
checkDesistencia(ips6($s)['denominator']===100.0&&ips6($s)['value']===98.0,'Explicit network zero is known evidence');
$network['external_evidence']['phone']=['forwarded_calls'=>2,'answered_calls'=>0,'desisted_calls'=>2,'evidence_reference'=>'Network fixture'];$s=callSnapshot($network,$legs);
checkDesistencia(ips6($s)['status']==='no_occurrences'&&ips6($s)['value']===null,'All network calls excluded does not become 100 percent');
echo "ANA desistência policy: $checks checks passed; no database connection.\n";
