<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/Database/ConnectionException.php';
require_once __DIR__.'/../src/Database/MysqlClient.php';
require_once __DIR__.'/../src/Ana/Repository.php';
require_once __DIR__.'/../src/Ana/PhoneData.php';
require_once __DIR__.'/../src/Ana/Lifecycle.php';
require_once __DIR__.'/../src/Ana/ActorEvidence.php';
require_once __DIR__.'/../src/Ana/SlaDataSource.php';
use GlpiPlugin\G4freportsana\Ana\Repository;
use GlpiPlugin\G4freportsana\Ana\PhoneData;
use GlpiPlugin\G4freportsana\Ana\Lifecycle;
use GlpiPlugin\G4freportsana\Ana\SlaDataSource;
function must($condition,$label){if(!$condition)throw new RuntimeException($label);}
$input=['month'=>'2026-09','timezone'=>'America/Sao_Paulo','entity_id'=>0];
$period=Repository::period($input);
must($period['start_epoch']===1788231600,'Local month boundary');
[$sql,$params]=Repository::ticketQuery($input,$period);
must(strpos($sql,'t.closedate >= FROM_UNIXTIME(?)')!==false,'Sargable closure boundary');
must(strpos($sql,'glpi_groups_tickets')===false,'No early current-team filter');
must(strpos($sql,'t.status = 6')!==false,'Closed population');
must($params===[0,1788231600,1790823600],'Epoch parameter ordering');
[$ref,$params]=Repository::ticketQuery($input+['scope_basis'=>'reference'],$period);
must(strpos($ref,'t.`date` >= FROM_UNIXTIME(?)')!==false,'Reference opening filter');
must($params[3]===1788220800,'Reference opening uses UTC boundary');
$bad=false;try{PhoneData::queues(['queue_names'=>['ssdf']]);}catch(InvalidArgumentException $e){$bad=true;}
must($bad,'No foreign queue selection');
function event($id,$epoch,$name,$queue='ana',$data=[]){return array_merge(['id'=>$id,'epoch'=>$epoch,'callid'=>'123.45','queuename'=>$queue,'event'=>$name,'agent'=>'SIP/100','data1'=>'','data2'=>'','data3'=>''],$data);}
$rows=[event(1,1000,'ENTERQUEUE','ana',['data2'=>'61999999999']),event(2,1010,'CONNECT','ana',['data1'=>'10']),event(3,1070,'COMPLETEAGENT','ana',['data1'=>'10','data2'=>'60']),event(4,1100,'ENTERQUEUE','ana',['data2'=>'61999999999']),event(5,1125,'ABANDON','ana',['data3'=>'25']),event(6,1130,'ENTERQUEUE','ana-transbordo'),event(7,1140,'CONNECT','ana-transbordo',['data1'=>'10']),event(8,1150,'COMPLETECALLER','ana-transbordo',['data2'=>'10'])];
$legs=PhoneData::buildLegs([$rows[0],$rows[3],$rows[5]],$rows);
must(count($legs)===3,'Repeated entries preserved');
must($legs[0]['answered']&&$legs[0]['talk_seconds']===60,'First answered duration');
must($legs[1]['desisted']&&$legs[1]['abandon_recorded']&&!$legs[1]['abandoned']&&!$legs[1]['answered'],'Later answer does not overwrite recorded short abandonment in its original queue leg');
must($legs[1]['wait_seconds']===25,'Abandon wait rawdata3');
must($legs[2]['interaction_id']==='123.45','Same call linkage');
must($legs[0]['event_ids']===[1,2,3],'Detailed leg evidence remains bounded by next entry');
$cross=PhoneData::buildLegs([$rows[0]],$rows);
must(count($cross)===3&&$cross[0]['in_period']&&!$cross[1]['in_period']&&$cross[2]['context_only'],'Crossperiod samecall legs preserved as context only');
$unknown=PhoneData::buildLegs([event(10,2000,'ENTERQUEUE')],[event(10,2000,'ENTERQUEUE')]);
must($unknown[0]['unknown_outcome']&&$unknown[0]['talk_seconds']===null,'Unknown outcome is not zero duration');
// DATETIME(0..6) and TIMESTAMP(6) retain the source precision while using
// matching whole-second metric clocks. These tests never connect to a database.
$dateEpoch=(new DateTimeImmutable('2026-09-15 09:00:00',new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
foreach(['2026-09-15 09:00:00','2026-09-15 09:00:00.1','2026-09-15 09:00:00.000000','2026-09-15 09:00:00.999999'] as $rawTime){
    $row=event(20,null,'ENTERQUEUE','ana',['source_time'=>$rawTime]);
    $parsed=PhoneData::buildLegs([$row],[$row],'America/Sao_Paulo','datetime')[0];
    must($parsed['entered_epoch']===$dateEpoch&&$parsed['events'][0]['source_time']===$rawTime,'Valid DATETIME precision preserved: '.$rawTime);
}
foreach([(string)$dateEpoch.'.999999',(float)$dateEpoch+0.999999] as $fractionalEpoch){
    $row=event(21,$fractionalEpoch,'ENTERQUEUE','ana',['source_time'=>'2026-09-15 12:00:00.999999']);
    $parsed=PhoneData::buildLegs([$row],[$row],'America/Sao_Paulo','timestamp')[0];
    must($parsed['entered_epoch']===$dateEpoch,'TIMESTAMP fractional epoch uses the same whole second');
}
foreach(['2026-09-31 09:00:00.123456','2026-09-15 25:00:00.123456','2026-09-15 09:00:00.1234567','2026-09-15 09:00:00.','2026-09-15 09:00:00.123 UTC'] as $rawTime){
    $row=event(22,null,'ENTERQUEUE','ana',['source_time'=>$rawTime]);
    $bad=false;try{PhoneData::buildLegs([$row],[$row],'America/Sao_Paulo','datetime');}catch(\GlpiPlugin\G4freportsana\Database\ConnectionException $e){$bad=true;}
    must($bad,'Invalid DATETIME still rejected: '.$rawTime);
}
$ticket=['id'=>1,'status'=>6,'solved_epoch'=>100,'closed_epoch'=>200,'group_ids'=>[29],'technician_ids'=>[]];
$logs=[['ticket_id'=>1,'log_id'=>1,'epoch'=>10,'itemtype_link'=>'Group','linked_action'=>15,'id_search_option'=>8,'new_value'=>'Segurança (30)'],['ticket_id'=>1,'log_id'=>2,'epoch'=>100,'linked_action'=>0,'id_search_option'=>12,'old_value'=>'2','new_value'=>'5'],['ticket_id'=>1,'log_id'=>3,'epoch'=>100,'itemtype_link'=>'Group','linked_action'=>16,'id_search_option'=>8,'old_value'=>'Segurança (30)'],['ticket_id'=>1,'log_id'=>4,'epoch'=>100,'itemtype_link'=>'Group','linked_action'=>15,'id_search_option'=>8,'new_value'=>'Redes (29)'],['ticket_id'=>1,'log_id'=>5,'epoch'=>200,'linked_action'=>0,'id_search_option'=>12,'old_value'=>'5','new_value'=>'6']];
$replayed=Lifecycle::annotate([$ticket],$logs,[],[])[0];
must($replayed['responsibility_group_ids']===[30],'Same-second change after solved log does not rewrite responsibility');
must($replayed['scope_included']&&$replayed['first_responsible_epoch']===10,'Historical owner and firstresponsibility');
must($replayed['unconfirmed_solution'],'Missing solution row remains explicit');
$unassigned=Lifecycle::annotate([array_merge($ticket,['id'=>2,'group_ids'=>[]])],[],[],['responsibility_basis'=>'current'])[0];
must($unassigned['scope_review']&&!$unassigned['scope_included'],'Unassigned candidate remains in review');
// The transport is overridden completely: this metadata test opens no socket.
$metadataClient=new class extends \GlpiPlugin\G4freportsana\Database\MysqlClient {
    public function __construct() {}
    public function fetchAll(string $sql,array $parameters=[],int $maxRows=100000):array {
        if(strpos($sql,'@@session.time_zone')!==false)return [['session_timezone'=>'+00:00','system_timezone'=>'UTC','extraction_epoch'=>1792097143]];
        if(strpos($sql,'FROM glpi_entities WHERE')!==false)return [['id'=>0,'name'=>'Fixture','entities_id'=>null,'calendars_id'=>0,'calendars_strategy'=>0]];
        throw new RuntimeException('Unexpected query in history capability test');
    }
};
$completeTicket=['id'=>1,'entity_id'=>0,'history_complete'=>true,'technical_history'=>[]];
$warnings=[];$context=SlaDataSource::load($metadataClient,[$completeTicket],$input,$warnings);
must($context['history_available']===true&&$context['capabilities']['history']===true,'Complete history capability matches evidence');
$warnings=[];$context=SlaDataSource::load($metadataClient,[$completeTicket,['id'=>2,'entity_id'=>0,'history_complete'=>false]],$input,$warnings);
must($context['history_available']===false&&$context['capabilities']['history']===false,'Incomplete history capability matches evidence');
echo "ANA repository: 34 scope, lifecycle and phone evidence checks passed.\n";
