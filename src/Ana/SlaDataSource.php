<?php
namespace GlpiPlugin\G4freportsana\Ana;
use GlpiPlugin\G4freportsana\Database\MysqlClient;
use GlpiPlugin\G4freportsana\Database\ConnectionException;

/** Fixed verified GLPI 11/12 columns; only referenced SLA/calendar IDs are read. */
final class SlaDataSource
{
    public static function load(MysqlClient $client,array $tickets,array $input,array &$warnings):array
    {
        $context=['timezone'=>$input['timezone']??'America/Sao_Paulo','database_timezone'=>[],
            'agreements'=>[],'entities'=>[],'calendars'=>[],'histories'=>[],'history_available'=>true,
            'capabilities'=>['agreements'=>false,'entities'=>false,'calendars'=>false,'timezone'=>false,'history'=>true]];
        foreach($tickets as $t){$context['histories'][(int)$t['id']]=$t['technical_history']??[];if(empty($t['history_complete']))$context['history_available']=false;}
        $context['capabilities']['history']=$context['history_available'];
        try{
            $r=$client->fetchAll("SELECT @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone, UNIX_TIMESTAMP() AS extraction_epoch",[],1);
            $context['database_timezone']=$r[0]??[];
            $context['capabilities']['timezone']=count($r)===1;
        }catch(ConnectionException $e){$warnings[]='Fuso da sessão SQL não disponível; datas dos chamados utilizam âncoras UNIX_TIMESTAMP, independentes da apresentação da sessão.';}
        try{
            $slaIds=[];$entityIds=[(int)($input['entity_id']??0)];
            foreach($tickets as $t){foreach(['sla_id','sla_tto_id'] as $f){if((int)($t[$f]??0)>0)$slaIds[]=(int)$t[$f];}$entityIds[]=(int)($t['entity_id']??0);}
            foreach(array_chunk(array_values(array_unique($slaIds)),250) as $ids){
                $rows=$client->fetchAll('SELECT id,name,entities_id,is_recursive,type,number_time,definition_time,end_of_working_day,use_ticket_calendar,calendars_id,slms_id FROM glpi_slas WHERE id IN ('.self::marks($ids).') ORDER BY id',$ids,1000);
                foreach($rows as $row)$context['agreements'][(int)$row['id']]=$row;
            }
            if(array_diff(array_unique($slaIds),array_keys($context['agreements'])))throw new ConnectionException('missing_columns','SLA referenciado não encontrado.');
            $context['capabilities']['agreements']=true;
            $pending=array_values(array_unique($entityIds));$depth=0;
            while($pending&&$depth++<32){
                $next=[];
                foreach(array_chunk($pending,250) as $ids){
                    $rows=$client->fetchAll('SELECT id,name,entities_id,calendars_id,calendars_strategy FROM glpi_entities WHERE id IN ('.self::marks($ids).') ORDER BY id',$ids,1000);
                    foreach($rows as $row){$id=(int)$row['id'];$context['entities'][$id]=$row;$parent=$row['entities_id'];if($parent!==null&&(int)$parent!==$id&&!isset($context['entities'][(int)$parent]))$next[]=(int)$parent;}
                }
                $pending=array_values(array_diff(array_unique($next),array_keys($context['entities'])));
            }
            if($pending)throw new ConnectionException('query_failed','Herança de entidades não resolvida.');
            if(array_diff(array_unique($entityIds),array_keys($context['entities'])))throw new ConnectionException('missing_columns','Entidade referenciada não encontrada.');
            $context['capabilities']['entities']=true;
            $calendarIds=[];
            foreach(array_merge(array_values($context['agreements']),array_values($context['entities'])) as $row){if((int)($row['calendars_id']??0)>0)$calendarIds[]=(int)$row['calendars_id'];}
            $holidays=[];
            foreach(array_chunk(array_values(array_unique($calendarIds)),250) as $ids){
                $rows=$client->fetchAll('SELECT id,name,entities_id,is_recursive FROM glpi_calendars WHERE id IN ('.self::marks($ids).') ORDER BY id',$ids,1000);
                foreach($rows as $row){$row['segments']=[];$row['holidays']=[];$context['calendars'][(int)$row['id']]=$row;}
                $rows=$client->fetchAll('SELECT id,calendars_id,day,`begin`,`end` FROM glpi_calendarsegments WHERE calendars_id IN ('.self::marks($ids).') ORDER BY calendars_id,day,`begin`,id',$ids,10000);
                foreach($rows as $row){$cid=(int)$row['calendars_id'];if(isset($context['calendars'][$cid]))$context['calendars'][$cid]['segments'][]=$row;}
                $rows=$client->fetchAll('SELECT ch.calendars_id,h.id,h.name,h.begin_date,h.end_date,h.is_perpetual FROM glpi_calendars_holidays ch LEFT JOIN glpi_holidays h ON h.id=ch.holidays_id WHERE ch.calendars_id IN ('.self::marks($ids).') ORDER BY ch.calendars_id,h.id',$ids,10000);
                foreach($rows as $row){if($row['id']===null)throw new ConnectionException('missing_columns','Feriado vinculado não encontrado.');$cid=(int)$row['calendars_id'];if(isset($context['calendars'][$cid]))$context['calendars'][$cid]['holidays'][]=$row;$holidays[(int)$row['id']]=$row;}
            }
            if(array_diff(array_unique($calendarIds),array_keys($context['calendars'])))throw new ConnectionException('missing_columns','Calendário referenciado não encontrado.');
            $context['holidays']=array_values($holidays);
            $context['capabilities']['calendars']=true;
        }catch(ConnectionException $e){
            $warnings[]='Referências de SLA/calendário incompletas: '.$e->getMessage().' Os contadores nativos foram preservados; cálculos que dependem das referências devem indicar a lacuna.';
        }
        return $context;
    }
    private static function marks(array $ids):string{return implode(',',array_fill(0,count($ids),'?'));}
}
