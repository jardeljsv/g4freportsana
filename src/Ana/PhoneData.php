<?php
namespace GlpiPlugin\G4freportsana\Ana;
use GlpiPlugin\G4freportsana\Database\MysqlClient;
use GlpiPlugin\G4freportsana\Database\ConnectionException;

/** ENTERQUEUE-anchored legs, exact ANA queues, complete event evidence per selected call ID. */
final class PhoneData
{
    public const DESISTENCIA_SECONDS = 30;
    public static function queues(array $input):array
    {
        $queues=array_values(array_unique($input['queue_names']??['ana','ana-transbordo']));
        if(!$queues||array_diff($queues,['ana','ana-transbordo']))throw new \InvalidArgumentException('Filas ANA inválidas. Utilize ana e/ou ana-transbordo.');
        return $queues;
    }
    public static function load(MysqlClient $client,array $input,array $period,array &$warnings):array
    {
        $queues=self::queues($input);
        $fields=$client->columnTypes('SELECT `time` FROM `queue_log` LIMIT 0');
        $type=isset($fields['time'])?MysqlClient::dateTimeStorageType((int)$fields['time']):null;
        if($type===null)throw new ConnectionException('unsupported_time_type','O tipo de queue_log.time não permite a leitura de datas.');
        $timezone=trim((string)($type==='timestamp'?($period['timezone']??'America/Sao_Paulo'):($input['callcenter_timezone']??'America/Sao_Paulo')));
        if($timezone==='')$timezone='America/Sao_Paulo';
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable $e){throw new ConnectionException('invalid_timezone','Fuso horário do PABX inválido na configuração da conexão.');}
        $start=(new \DateTimeImmutable('@'.$period['start_epoch']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $end=(new \DateTimeImmutable('@'.$period['end_epoch']))->setTimezone($zone)->format('Y-m-d H:i:s');
        $epochSelect=$type==='timestamp'?'UNIX_TIMESTAMP(q.`time`) AS epoch':'NULL AS epoch';
        $marks=implode(',',array_fill(0,count($queues),'?'));
        $range=$type==='timestamp'?'q.`time` >= FROM_UNIXTIME(?) AND q.`time` < FROM_UNIXTIME(?)':'q.`time` >= ? AND q.`time` < ?';
        $params=$type==='timestamp'?[$period['start_epoch'],$period['end_epoch']]:[$start,$end];
        $params=array_merge($params,$queues,$queues);
        $anchors=$client->fetchAll('SELECT q.id,q.callid,q.queuename,q.`time` AS source_time,'.$epochSelect.',q.event,q.agent,q.data1,q.data2,q.data3,q.data4 FROM queue_log q WHERE '.$range.' AND q.event=\'ENTERQUEUE\' AND q.callid IS NOT NULL AND q.callid NOT IN (\'\',\'NONE\') AND q.queuename IN ('.$marks.') AND BINARY q.queuename IN ('.$marks.') ORDER BY q.`time`,q.id',$params,30000);
        if(!$anchors&&in_array($type,['varchar','char'],true)){
            // Selected events validate their own dates. For an empty text range,
            // distinguish a real zero from a source containing numeric time strings.
            $probe=$client->fetchAll('SELECT q.`time` AS source_time FROM queue_log q ORDER BY q.id DESC LIMIT 1',[],1);
            if($probe)self::calendarEpoch((string)($probe[0]['source_time']??''),$timezone);
        }
        $events=[];
        $callIds=array_values(array_unique(array_column($anchors,'callid')));
        foreach(array_chunk($callIds,100) as $ids){
            $bind=array_merge($ids,$queues,$queues);
            $rows=$client->fetchAll('SELECT q.id,q.callid,q.queuename,q.`time` AS source_time,'.$epochSelect.',q.event,q.agent,q.data1,q.data2,q.data3,q.data4 FROM queue_log q WHERE q.callid IN ('.implode(',',array_fill(0,count($ids),'?')).') AND q.queuename IN ('.$marks.') AND BINARY q.queuename IN ('.$marks.') ORDER BY q.callid,q.queuename,q.`time`,q.id',$bind,max(1,250000-count($events)));
            $events=array_merge($events,$rows);
            if(count($events)>=250000)throw new ConnectionException('row_limit_exceeded','Eventos telefônicos excederam o limite seguro; nenhum total parcial foi utilizado.');
        }
        $legs=self::buildLegs($anchors,$events,$timezone,$type);
        foreach($legs as &$leg){$leg['period_start_epoch']=$period['start_epoch'];$leg['period_end_epoch']=$period['end_epoch'];}
        unset($leg);
        return $legs;
    }

    /** Pure parser shared by offline tests. Every repeated ENTERQUEUE creates a separate leg. */
    public static function buildLegs(array $anchors,array $eventRows,string $timezone='America/Sao_Paulo',string $timeType='timestamp'):array
    {
        $anchorIds=[];
        foreach($anchors as $a)$anchorIds[(string)$a['id']]=true;
        $events=[];
        foreach($eventRows as $r){
            $queue=(string)($r['queuename']??$r['queue']??'');
            if(!in_array($queue,['ana','ana-transbordo'],true))continue;
            $epoch=$r['epoch']??null;
            if($epoch===null||$epoch===''){
                $raw=(string)($r['source_time']??$r['time']??'');
                $epoch=self::calendarEpoch($raw,$timezone);
            }
            $r['id']=(int)$r['id'];$r['epoch']=(int)$epoch;$r['queue']=$queue;
            $r['occurred_at']=Repository::localDate((int)$epoch,$timezone);
            $events[(string)$r['callid'].'\0'.$queue][]=$r;
        }
        $legs=[];
        foreach($events as $rows){
            usort($rows,static fn($a,$b)=>[$a['epoch'],$a['id']]<=>[$b['epoch'],$b['id']]);
            $current=null;
            foreach($rows as $event){
                if($event['event']==='ENTERQUEUE'){
                    if($current!==null){$current['next_entry_epoch']=$event['epoch'];$legs[]=self::finish($current,$timezone);}
                    $current=[
                        'id'=>(string)$event['callid'].':'.$event['queue'].':'.$event['id'],'entry_event_id'=>$event['id'],
                        'callid'=>(string)$event['callid'],'call_id'=>(string)$event['callid'],'interaction_id'=>(string)$event['callid'],
                        'queue'=>$event['queue'],'entered_epoch'=>$event['epoch'],'entered_at'=>$event['occurred_at'],
                        'in_period'=>isset($anchorIds[(string)$event['id']]),'context_only'=>!isset($anchorIds[(string)$event['id']]),
                        'caller'=>(string)($event['data2']??''),'events'=>[$event],'source_time_type'=>$timeType,
                        'source_timezone'=>$timezone,'linkage_basis'=>'same_callid','next_entry_epoch'=>null,
                    ];
                }elseif($current!==null){$current['events'][]=$event;}
            }
            if($current!==null)$legs[]=self::finish($current,$timezone);
        }
        if(count(array_filter($legs,static fn($l)=>$l['in_period']))!==count($anchors))throw new ConnectionException('query_failed','As entradas telefônicas mudaram durante a leitura; refaça a extração para evitar totais parciais.');
        usort($legs,static fn($a,$b)=>[$a['entered_epoch'],$a['entry_event_id']]<=>[$b['entered_epoch'],$b['entry_event_id']]);
        return $legs;
    }

    private static function calendarEpoch(string $raw,string $timezone):int
    {
        // DATETIME and calendar-text storage use the same strict parser.
        // Preserve raw fractions as evidence; all metric clocks use whole seconds.
        if(!preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/D',$raw,$parts)){
            throw new ConnectionException('invalid_phone_date','Formato de data inválido em queue_log.time.');
        }
        $normalized=$parts[1].'.'.str_pad($parts[2]??'',6,'0');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$normalized,new \DateTimeZone($timezone));
        if($date===false||$date->format('Y-m-d H:i:s.u')!==$normalized)throw new ConnectionException('invalid_phone_date','Data inválida em queue_log.time.');
        return $date->getTimestamp();
    }

    private static function finish(array $leg,string $timezone):array
    {
        $connect=$complete=$abandon=$terminal=$transfer=null;$completions=0;$transferEvents=[];
        foreach($leg['events'] as $e){
            $name=(string)$e['event'];
            if($name==='CONNECT'&&$connect===null)$connect=$e;
            if(in_array($name,['COMPLETEAGENT','COMPLETECALLER'],true)){$completions++;if($complete===null)$complete=$e;}
            if($name==='ABANDON'&&$abandon===null)$abandon=$e;
            if(in_array($name,['BLINDTRANSFER','ATTENDEDTRANSFER','TRANSFER'],true)){$transferEvents[]=$e;if($transfer===null)$transfer=$e;}
            if(in_array($name,['COMPLETEAGENT','COMPLETECALLER','ABANDON','EXITWITHTIMEOUT','EXITWITHKEY','EXITEMPTY'],true)&&$terminal===null)$terminal=$e;
        }
        $leg['answered']=$connect!==null||$complete!==null;
        $leg['abandon_recorded']=!$leg['answered']&&$abandon!==null;
        $leg['answered_epoch']=$connect['epoch']??null;
        $end=$complete??$terminal??$transfer;
        $leg['ended_epoch']=$end['epoch']??null;
        $leg['answered_at']=Repository::localDate($leg['answered_epoch'],$timezone);
        $leg['ended_at']=Repository::localDate($leg['ended_epoch'],$timezone);
        $leg['agent']=(string)($connect['agent']??$complete['agent']??'');
        $leg['wait_seconds']=self::duration($connect['data1']??$complete['data1']??$abandon['data3']??null);
        if(!$leg['answered']&&$abandon===null&&$terminal!==null){
            // queue_log positions are not durations. These terminal events
            // carry their queue wait in the documented third/fourth field.
            $waitField=($terminal['event']==='EXITWITHKEY')?'data4':'data3';
            $leg['wait_seconds']=self::duration($terminal[$waitField]??null);
        }
        $leg['wait_basis']=$leg['wait_seconds']===null?'unavailable':'queue_log_registered';
        if($leg['wait_seconds']===null&&$connect!==null){$leg['wait_seconds']=max(0,$connect['epoch']-$leg['entered_epoch']);$leg['wait_basis']='CONNECT_minus_ENTERQUEUE';}
        $leg['talk_seconds']=self::duration($complete['data2']??null);
        $leg['talk_basis']=$leg['talk_seconds']===null?'unavailable':'completion_data2';
        if($leg['talk_seconds']===null&&$complete!==null&&$connect!==null&&$complete['epoch']>=$connect['epoch']){$leg['talk_seconds']=$complete['epoch']-$connect['epoch'];$leg['talk_basis']='COMPLETE_minus_CONNECT';}
        $leg['unknown_outcome']=$terminal===null&&$transfer===null;
        $leg['transferred']=$transfer!==null;$leg['transfer_events']=$transferEvents;
        $leg['completion_event']=$complete['event']??null;$leg['terminal_event']=$terminal['event']??null;
        $leg['completion_count']=$completions;$leg['entry_count']=1;
        $leg['event_ids']=array_column($leg['events'],'id');
        $leg['missing_connect']=$leg['answered']&&$connect===null;
        $leg['conflicting_outcome']=$leg['answered']&&$abandon!==null;
        $leg['satisfaction']=null; // ANA IPS1 is GLPI survey evidence; no inherited IVR scale inversion.
        return self::classifyAbandonment($leg);
    }

    /** Shared by queue legs and their consolidated interaction. No missing wait is zero. */
    public static function classifyAbandonment(array $call):array
    {
        $recorded=!empty($call['abandon_recorded']??$call['abandoned']??false)
            || !empty($call['desisted']) || !empty($call['abandon_wait_unknown']);
        $call['abandon_recorded']=empty($call['answered'])&&$recorded;
        $wait=self::duration($call['wait_seconds']??null);
        $call['wait_seconds']=$wait;
        $call['desisted']=$call['abandon_recorded']&&$wait!==null&&$wait<self::DESISTENCIA_SECONDS;
        $call['abandoned']=$call['abandon_recorded']&&$wait!==null&&$wait>=self::DESISTENCIA_SECONDS;
        $call['abandon_wait_unknown']=$call['abandon_recorded']&&$wait===null;
        return $call;
    }
    private static function duration($v):?int{return $v!==null&&$v!==''&&preg_match('/\A[0-9]+\z/D',trim((string)$v))?(int)$v:null;}
}
