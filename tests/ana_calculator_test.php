<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Offline calculation boundary cases, synthetic only where the real export has no example. */
$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){$p='GlpiPlugin\\G4freportsana\\';if(strncmp($class,$p,strlen($p))===0){$file=$root.'/src/'.str_replace('\\','/',substr($class,strlen($p))).'.php';if(is_file($file))require_once$file;}});
use GlpiPlugin\G4freportsana\Ana\Calculator;
use GlpiPlugin\G4freportsana\Ana\SlaEvaluator;
use GlpiPlugin\G4freportsana\Ana\ChartData;
use GlpiPlugin\G4freportsana\Ana\HeatmapData;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\PhoneData;
$n=0;function check($truth,$label){global$n;if(!$truth)throw new RuntimeException($label);$n++;}
function metric($s,$id){foreach($s['metrics']as$m)if($m['id']===$id)return$m;throw new RuntimeException('metric absent');}
$start=strtotime('2026-09-01T00:00:00-03:00');$end=strtotime('2026-10-01T00:00:00-03:00');
$in=['month'=>'2026-09','start_epoch'=>$start,'end_epoch'=>$end,'timezone'=>'America/Sao_Paulo','clock_mode'=>'native_reference','methodology_confirmed'=>false,'queue_names'=>['ana','ana-transbordo']];
$t=['id'=>1,'scope_included'=>true,'type'=>2,'status'=>6,'impact'=>3,'urgency'=>3,'priority'=>4,'takeintoaccount_seconds'=>0,'solve_seconds'=>0,'satisfaction'=>5,'survey_answered_epoch'=>$start+10,'reopening_history_complete'=>true,'solutions'=>[['status'=>3]],'status_events'=>[]];
$s=Calculator::build($in,['tickets'=>[$t],'calls_available'=>false]);
check(count($s['metrics'])===10,'Exactly ten ANA indicators');
check(metric($s,'IPS1')['value']===5.0,'Mean is score5 rather than500');
$fractional=$t;$fractional['satisfaction']=3.5;$fractionalSnapshot=Calculator::build($in,['tickets'=>[$fractional]]);
check(metric($fractionalSnapshot,'IPS1')['value']===3.5&&metric($fractionalSnapshot,'IPS1')['denominator']===1,'NormalizedfractionalGLPIsurvey score remainsvalid');
check($s['tickets'][0]['tit_seconds']===0&&$s['tickets'][0]['tms_seconds']===0,'Native zero preserved');
check($s['tickets'][0]['priority_contract']==='P1','GLPI priority4 must be ANA P1 even with medium impact and urgency');
$low=$t;$low['id']=3;$low['priority']=2;$low['impact']=3;$low['urgency']=3;
$lowSnapshot=Calculator::build($in,['tickets'=>[$low]]);
check($lowSnapshot['tickets'][0]['priority_contract']==='P3','GLPI final low priority must be ANA P3 despite medium impact and urgency');
check(metric($s,'IPS2')['status']==='provisional','Unconfirmed nativeclock cannot become official compliance');
check(metric($s,'IPS7')['value']===null&&metric($s,'IPS10')['status']==='pending','Missing external evidence is not100%');
check($s['totals']['calls_received']===null&&$s['totals']['calls_answered']===null&&$s['totals']['calls_abandoned']===null,'Unavailablephone totals null not zero');
check(metric($s,'IPS6')['queue_observed']['received']===null&&!metric($s,'IPS6')['queue_observed']['available'],'Unavailablequeueobservations remainnull');
check(metric($s,'IPS3')['children'][0]['status']==='no_occurrences','Emptypriority not100%');
$mapped=$in+['methodology_reference'=>'Accepted mapping','priority_value_map'=>[4=>'P3']];$mapped['methodology_confirmed']=true;
$unknown=$t;$unknown['id']=2;$unknown['impact']=$unknown['urgency']=$unknown['priority']=0;
$uncertain=Calculator::build($mapped,['tickets'=>[$t,$unknown]]);
check(metric($uncertain,'IPS4')['unresolved_ids']===[2],'Unclassifiedticket visible in priorityparent');
check(metric($uncertain,'IPS4')['children'][2]['status']==='provisional'&&!metric($uncertain,'IPS4')['children'][2]['evidence_complete'],'Unknownpriority makesotherwisecompliantchild incomplete');
check(metric($uncertain,'IPS4')['children'][0]['status']==='pending','Unknownpriority preventsclaimingemptychild');
$t['status_events']=[['log_id'=>1,'from_status'=>5,'to_status'=>2,'epoch'=>$start+100,'actor_id'=>9],['log_id'=>2,'from_status'=>5,'to_status'=>2,'epoch'=>$start+200,'actor_id'=>9]];
$t['solutions']=[['status'=>3,'solver_id'=>9]];
$s=Calculator::build($in,['tickets'=>[$t]]);
check(metric($s,'IPS5')['numerator']===0&&metric($s,'IPS5')['denominator']===1,'Sameagentreopening counted once');
check(count($s['reopening_events'])===2,'Eventaudit preserves repeatedevents');
$t['solutions']=[];$s=Calculator::build($in,['tickets'=>[$t]]);
check(metric($s,'IPS5')['value']===null&&metric($s,'IPS5')['unresolved_ids']===[1],'Missing solutionrow unresolved, not inferred closedwithoutsolution');
$clockIn=$in+['calendar_mode'=>'onsite','tms_endpoint'=>'closed','contractual_holidays'=>['2026-09-07'],'holiday_reference'=>'Fixture approved holiday'];$clockIn['clock_mode']='contractual';
$a=strtotime('2026-09-04T19:00:00-03:00');$b=strtotime('2026-09-08T10:00:00-03:00');
$clockIn['approved_pauses']=[['ticket_id'=>1,'start_epoch'=>strtotime('2026-09-08T08:00:00-03:00'),'end_epoch'=>strtotime('2026-09-08T09:00:00-03:00'),'approval_reference'=>'A'],['ticket_id'=>1,'start_epoch'=>strtotime('2026-09-08T08:30:00-03:00'),'end_epoch'=>strtotime('2026-09-08T09:30:00-03:00'),'approval_reference'=>'B']];
$r=(new SlaEvaluator([],$clockIn))->evaluate(['id'=>1,'opened_epoch'=>$a-60,'first_responsible_epoch'=>$a,'closed_epoch'=>$b,'group_history_complete'=>true,'waiting_seconds'=>99999]);
check($r['tms_seconds']===5400,'Businessclock excludesweekendholiday, overlapsapprovedpauses once');
check($r['approved_pause_seconds']===5400,'Approved pause union90min');
check($r['tit_seconds']===60,'TIT elapsed fromopeningtofirstresponsibility');
$clockIn['approved_pauses'][]=['ticket_id'=>1,'start_epoch'=>$a,'end_epoch'=>$b,'approval_reference'=>''];
$r=(new SlaEvaluator([],$clockIn))->evaluate(['id'=>1,'opened_epoch'=>$a-60,'first_responsible_epoch'=>$a,'closed_epoch'=>$b,'group_history_complete'=>true]);
check($r['tms_seconds']===5400&&!$r['clock_evidence_complete'],'Unapproved pause ignoredand flagged');
$badDate=$clockIn;$badDate['approved_pauses']=[['ticket_id'=>1,'start'=>'2026-09-31T08:00:00-03:00','end'=>'2026-10-01T09:00:00-03:00','approval_reference'=>'ref']];
$r=(new SlaEvaluator([],$badDate))->evaluate(['id'=>1,'opened_epoch'=>$a-60,'first_responsible_epoch'=>$a,'closed_epoch'=>$b,'group_history_complete'=>true]);
check($r['approved_pause_seconds']===0&&!$r['clock_evidence_complete'],'Impossibleapprovedpausedate rejects ratherthannormalizes');
$calls=[['id'=>'leg1','callid'=>'shared','queue'=>'ana','entered_epoch'=>$start+100,'in_period'=>true,'answered'=>false,'abandoned'=>false,'transferred'=>true],['id'=>'leg2','callid'=>'shared','queue'=>'ana-transbordo','entered_epoch'=>$end+5,'answered_epoch'=>$end+20,'answered'=>true,'talk_seconds'=>20,'in_period'=>false,'context_only'=>true],['id'=>'leg3','callid'=>'different','queue'=>'ana','entered_epoch'=>$start+110,'in_period'=>true,'answered'=>false,'abandoned'=>true]];
$s=Calculator::build($in,['tickets'=>[],'calls'=>$calls,'calls_available'=>true]);
check($s['totals']['calls_received']===2&&$s['totals']['calls_answered']===1,'SameID crossmonthtransfer merges; differentID remainsdistinct');
check(metric($s,'IPS6')['status']==='provisional','Queueobservations notnetworkcoverage');
$externalPhone=$in;$externalPhone['external_evidence']=['phone'=>['forwarded_calls'=>71,'answered_calls'=>71,'evidence_reference'=>'approved network totals']];
$networkSnapshot=Calculator::build($externalPhone,['tickets'=>[],'calls_available'=>false]);
check($networkSnapshot['totals']['calls_received']===null&&$networkSnapshot['totals']['calls_network_forwarded']===71&&metric($networkSnapshot,'IPS6')['value']===100.0,'Externalphonecounts remain separate fromunavailableobservedqueues');
$prior=$calls;$prior[0]['entered_epoch']=$start-10;$prior[0]['in_period']=false;$prior[0]['context_only']=true;$prior[1]['entered_epoch']=$start+20;$prior[1]['in_period']=true;$prior[1]['context_only']=false;
$priorSnapshot=Calculator::build($in,['tickets'=>[],'calls'=>$prior,'calls_available'=>true]);
check($priorSnapshot['totals']['calls_received']===1,'Interactionbeforemonthnotrecountedbynewqueueentryinmonth');
$legIn=$in+['call_unit'=>'queue_leg'];$s=Calculator::build($legIn,['tickets'=>[],'calls'=>$calls,'calls_available'=>true]);
check($s['totals']['calls_received']===2&&$s['totals']['calls_answered']===0,'Queueleg excludescontextoutofperiod');
// Integration boundary: use administrator settings normalization and the real
// queue parser, so structured register rows cannot silently bypass exclusions.
$registeredInput=Settings::normalizeInput(['month'=>'2026-09'],['ana_test_numbers_json'=>json_encode([
    ['number'=>'(61) 99999-0001','label'=>'Active test phone','active'=>true],
    ['number'=>'(61) 99999-0002','label'=>'Inactive test phone','active'=>false],
])]);
check($registeredInput['test_numbers'][0]['number']==='61999990001'&&$registeredInput['test_numbers'][1]['active']===false,'Saved test register has normalized structured rows');
$anchors=$queueEvents=[];
foreach(['active'=>'(61) 99999-0001','inactive'=>'61999990002','ordinary'=>'61999990003','anonymous'=>''] as $callid=>$caller){
    $eventId=count($queueEvents)+1;$entry=['id'=>$eventId,'epoch'=>$start+100+$eventId,'callid'=>$callid,'queuename'=>'ana','event'=>'ENTERQUEUE','agent'=>'NONE','data1'=>'','data2'=>$caller,'data3'=>''];
    $anchors[]=$entry;$queueEvents[]=$entry;
    $queueEvents[]=array_replace($entry,['id'=>$eventId+1,'epoch'=>$entry['epoch']+5,'event'=>'CONNECT','agent'=>'SIP/fixture','data1'=>'5','data2'=>'']);
    $queueEvents[]=array_replace($entry,['id'=>$eventId+2,'epoch'=>$entry['epoch']+15,'event'=>'COMPLETEAGENT','agent'=>'SIP/fixture','data1'=>'5','data2'=>'10']);
}
$parsedCalls=PhoneData::buildLegs($anchors,$queueEvents);
set_error_handler(static function($severity,$message){throw new RuntimeException('Unexpected warning in registered test-call integration: '.$message);});
try{$registeredSnapshot=Calculator::build($registeredInput,['tickets'=>[],'calls'=>$parsedCalls,'calls_available'=>true]);}finally{restore_error_handler();}
check($registeredSnapshot['totals']['calls_received']===3&&$registeredSnapshot['totals']['calls_answered']===3,'Active test call excluded from observed totals without PHP warnings');
check(array_column($registeredSnapshot['excluded_calls'],'callid')===['active'],'Only active registered caller is excluded');
check(in_array('inactive',array_column($registeredSnapshot['calls'],'callid'),true)&&in_array('anonymous',array_column($registeredSnapshot['calls'],'callid'),true),'Inactive registration and absent caller remain in the report');
check(count($registeredSnapshot['excluded_calls'][0]['events'])===3&&$registeredSnapshot['excluded_calls'][0]['events'][0]['data2']==='(61) 99999-0001','Excluded test call retains complete raw queue evidence');
check(metric($registeredSnapshot,'IPS6')['queue_observed']['received']===3&&metric($registeredSnapshot,'IPS6')['queue_observed']['answered']===3,'IPS6 queue evidence follows the same test-call exclusion');
$registeredInput['call_unit']='queue_leg';$registeredLegs=Calculator::build($registeredInput,['tickets'=>[],'calls'=>$parsedCalls,'calls_available'=>true]);
check($registeredLegs['totals']['calls_received']===3&&count($registeredLegs['excluded_calls'])===1,'Registered active exclusion also applies to queue-leg counting');
$legacyInput=$registeredInput;$legacyInput['test_numbers']=['(61) 99999-0001',''];
$legacySnapshot=Calculator::build($legacyInput,['tickets'=>[],'calls'=>$parsedCalls,'calls_available'=>true]);
check($legacySnapshot['totals']['calls_received']===3&&array_column($legacySnapshot['excluded_calls'],'callid')===['active'],'Legacy direct scalar register works and empty values do not exclude anonymous callers');
$e=$in;$e['external_evidence']=['services'=>[['metric_id'=>'IPS7','service'=>'A','window_seconds'=>1000,'downtime_seconds'=>0,'evidence_reference'=>'monitorA'],['metric_id'=>'IPS7','service'=>'B','window_seconds'=>1000,'downtime_seconds'=>10,'evidence_reference'=>'monitorB']], 'controls_applicable'=>true,'controls_scope_reference'=>'agreement','controls'=>[['description'=>'Control','due_date'=>'2026-09-30','target_percent'=>80,'completion_percent'=>80,'acceptance_reference'=>'accepted']]];
$s=Calculator::build($e,['tickets'=>[]]);
check(metric($s,'IPS7')['value']===99.0&&metric($s,'IPS7')['status']==='unmet','Availabilityworstservice notaverage');
check(metric($s,'IPS9')['value']===1&&metric($s,'IPS9')['status']==='met','Agreed80% targetaccepted, no100%invented');
check(HeatmapData::timestampParts('2026-02-30T12:00:00-03:00','2026-03')===null,'Impossibledate doesnotnormalizeintoanothermonth');
$parts=HeatmapData::timestampParts('2026-09-01T00:22:41+00:00','2026-09');check($parts===null,'Offsetconversion keepsUTCSept1localAug31 outofSept');
$s=Calculator::build($in,['tickets'=>[$t]]);$charts=ChartData::build($s);check($charts['IPS1']['series'][1]['values'][0]===5.0,'Chartscore notpercentage');
try{Calculator::build($in,['tickets'=>[$t,$t]]);check(false,'DuplicateIDs reject');}catch(RuntimeException$e){check(true,'DuplicateIDs reject');}
echo "PASS $n ANA calculator boundary assertions\n";
