<?php
namespace GlpiPlugin\G4freportsana\Ana;

/** Pure deterministic replay. Missing or contradictory evidence is explicit. */
final class Lifecycle
{
    public static function annotate(array $tickets,array $logRows,array $solutionRows,array $input): array
    {
        $zone=(string)($input['timezone']??'America/Sao_Paulo');
        $allowed=array_values(array_unique(array_map('intval',array_merge($input['n1_group_ids']??[4],$input['n2_group_ids']??[1],$input['n3_group_ids']??range(22,30)))));
        $basis=(string)($input['responsibility_basis']??'solution');
        $logs=$solutions=[];
        foreach($logRows as $r){
            $id=(int)($r['ticket_id']??$r['items_id']??$r['tickets_id']??0);
            $epoch=self::epoch($r['epoch']??$r['date_mod_epoch']??null);
            if(!$id) continue;
            $r['log_id']=(int)($r['log_id']??$r['id']??0); $r['epoch']=$epoch;
            $logs[$id][]=$r;
        }
        foreach($solutionRows as $r){
            $id=(int)($r['ticket_id']??$r['items_id']??$r['tickets_id']??0); if(!$id)continue;
            $epoch=self::epoch($r['created_epoch']??$r['date_creation_epoch']??$r['solution_epoch']??null);
            $sid=(int)($r['id']??$r['solution_id']??0);
            $solutions[$id][]=['id'=>$sid,'solution_id'=>$sid,'epoch'=>$epoch,'solution_epoch'=>$epoch,'created_epoch'=>$epoch,
                'solution_at'=>Repository::localDate($epoch,$zone),'solver_id'=>(int)($r['solver_id']??$r['users_id']??0),
                'solver_name'=>(string)($r['solver_name']??$r['user_name']??''),'status'=>(int)($r['status']??0),
                'approval_epoch'=>self::epoch($r['approval_epoch']??$r['date_approval_epoch']??null),
                'approval_user_id'=>(int)($r['approval_user_id']??$r['users_id_approval']??0)];
        }
        foreach($tickets as &$ticket){
            $id=(int)$ticket['id']; $history=$logs[$id]??[];
            usort($history,static fn($a,$b)=>[$a['epoch']??PHP_INT_MAX,$a['log_id']]<=>[$b['epoch']??PHP_INT_MAX,$b['log_id']]);
            $statuses=$groups=$users=[]; $invalidStatus=false; $invalidGroup=false;
            foreach($history as $r){
                $option=(int)($r['id_search_option']??0); $action=(int)($r['linked_action']??0);
                $base=['log_id'=>$r['log_id'],'epoch'=>$r['epoch'],'occurred_epoch'=>$r['epoch'],'occurred_at'=>Repository::localDate($r['epoch'],$zone),
                    'actor_id'=>ActorEvidence::historyUserId($r['user_name']??''),'actor_raw'=>(string)($r['user_name']??'')];
                if($option===12&&$action===0){
                    $from=self::status($r['old_value']??null);$to=self::status($r['new_value']??null);
                    if($from===null||$to===null||$r['epoch']===null){$invalidStatus=true;continue;}
                    $statuses[]=array_merge($base,['from_status'=>$from,'to_status'=>$to,'old_status'=>$from,'new_status'=>$to]);
                }
                if(($r['itemtype_link']??'')==='Group'&&$option===8&&in_array($action,[15,16],true)){
                    $target=self::target($r,$action);
                    if($target===0||$r['epoch']===null){$invalidGroup=true;continue;}
                    $groups[]=array_merge($base,['action'=>$action===15?'add':'remove','linked_action'=>$action,'group_id'=>$target,
                        'raw_value'=>(string)($action===15?($r['new_value']??''):($r['old_value']??''))]);
                }
                if(($r['itemtype_link']??'')==='User'&&$option===5&&in_array($action,[15,16],true)){
                    $users[]=array_merge($base,['action'=>$action===15?'add':'remove','user_id'=>self::target($r,$action)]);
                }
            }
            $records=$solutions[$id]??[];
            usort($records,static fn($a,$b)=>[$a['epoch']??PHP_INT_MAX,$a['id']]<=>[$b['epoch']??PHP_INT_MAX,$b['id']]);
            $ticket['solutions']=$records; $ticket['solutions_available']=$input['_solutions_available']??true;
            $ticket['status_events']=$statuses;$ticket['group_events']=$groups;$ticket['technician_events']=$users;
            $ticket['technical_history']=$history;
            $historyAvailable=$input['_history_available']??true;
            $statusComplete=$historyAvailable&&!$invalidStatus&&count($statuses)>0;
            $previous=null;
            foreach($statuses as $event){
                if($previous!==null&&$previous!==$event['from_status'])$statusComplete=false;
                $previous=$event['to_status'];
            }
            if($previous!==null&&$previous!==(int)($ticket['status']??0))$statusComplete=false;
            $ticket['reopening_history_complete']=$statusComplete;
            $ticket['reopening_events']=array_values(array_filter($statuses,static fn($s)=>in_array($s['from_status'],[5,6],true)&&in_array($s['to_status'],[1,2,3,4],true)));
            $ticket['reopened']=count($ticket['reopening_events'])>0;
            [$finalGroups,$groupIssues]=self::replay($groups);
            $current=self::ids($ticket['group_ids']??[]);
            $groupComplete=$historyAvailable&&!$invalidGroup&&count($groups)>0&&!$groupIssues&&$current===$finalGroups;
            $ticket['group_history_complete']=$groupComplete;
            $ticket['history_complete']=$statusComplete&&$groupComplete;
            $ticket['history_basis']='ordered_recorded_events_reconciled_to_current_rows';
            $ticket['history_issues']=$groupIssues;
            if(!$statusComplete)$ticket['history_issues'][]='status_chain_unverified';
            if($current!==$finalGroups)$ticket['history_issues'][]='group_replay_current_mismatch';
            $endpoint=$basis==='closure'?self::epoch($ticket['closed_epoch']??null):self::epoch($ticket['solved_epoch']??null);
            $targetStatus=$basis==='closure'?6:5;
            $logBoundary=null;
            foreach($statuses as $s){if($s['epoch']===$endpoint&&$s['to_status']===$targetStatus)$logBoundary=$s['log_id'];}
            if($basis==='current'){$responsible=$current;$resolved=count($current)>0;}
            else{[$responsible,$ignored]=self::replay($groups,$endpoint,$logBoundary);$resolved=$groupComplete&&$endpoint!==null&&count($responsible)>0;}
            $ticket['responsibility_group_ids']=$responsible;
            $ticket['responsibility_basis']=$basis;
            $ticket['responsibility_epoch']=$basis==='current'?null:$endpoint;
            $ticket['responsibility_status_log_id']=$logBoundary;
            $ticket['first_responsible_epoch']=null;
            if($groupComplete){
                foreach($groups as $g){
                    if($g['action']==='add'&&in_array($g['group_id'],$allowed,true)){$ticket['first_responsible_epoch']=$g['epoch'];break;}
                }
            }
            $ticket['scope_review']=!$resolved;
            $ticket['scope_included']=$resolved&&count(array_intersect($responsible,$allowed))>0;
            $ticket['scope_reason']=!$resolved?'Responsabilidade não comprovada na base selecionada.':($ticket['scope_included']?'Grupo ANA confirmado na base selecionada.':'Grupo responsável fora da seleção ANA.');
            if (!$resolved) {
                $milestone = $basis === 'closure' ? 'fechamento' : 'solução';
                if ($basis === 'current') $ticket['scope_reason'] = 'Sem grupo atribuído na extração.';
                elseif (!$groupComplete) $ticket['scope_reason'] = 'Histórico de atribuição incompleto.';
                elseif ($endpoint === null) $ticket['scope_reason'] = 'Data de ' . $milestone . ' ausente.';
                elseif ($logBoundary === null && count(array_filter($groups, static fn($event) => $event['epoch'] === $endpoint)) > 0) {
                    $ticket['scope_reason'] = 'Ordem entre ' . $milestone . ' e atribuição no mesmo segundo não determinada.';
                } else $ticket['scope_reason'] = 'Sem grupo responsável no momento da ' . ($basis === 'closure' ? 'conclusão' : 'solução') . '.';
            }
            $ticket['scope_override']=null;
            foreach($input['scope_overrides']??[] as $key=>$override){
                if(!is_array($override))continue;
                $oid=(int)($override['ticket_id']??$key);
                if($oid!==$id||trim((string)($override['reason']??''))==='')continue;
                if(!array_key_exists('include',$override))continue;
                $ticket['scope_override']=$override;
                $ticket['scope_included']=filter_var($override['include'],FILTER_VALIDATE_BOOLEAN);
                $ticket['scope_review']=false;$ticket['scope_reason']='Decisão documentada: '.trim((string)$override['reason']);
            }
            $ticket['unconfirmed_solution']=!count($records);
            $ticket['solution_confirmation_note']=count($records)?'Solução registrada no GLPI.':'Sem registro de solução.';
        }
        unset($ticket);
        return $tickets;
    }

    private static function replay(array $events,?int $until=null,?int $logBoundary=null):array
    {
        $state=[];$issues=[];
        foreach($events as $event){
            if($until!==null&&($event['epoch']>$until||($event['epoch']===$until&&$logBoundary!==null&&$event['log_id']>$logBoundary)))continue;
            $id=$event['group_id'];
            if($event['action']==='add'){
                if(isset($state[$id]))$issues[]='duplicate_group_add:'.$event['log_id'];
                $state[$id]=true;
            }else{
                if(!isset($state[$id]))$issues[]='group_remove_without_add:'.$event['log_id'];
                unset($state[$id]);
            }
        }
        $ids=array_map('intval',array_keys($state));sort($ids,SORT_NUMERIC);
        return [$ids,$issues];
    }
    private static function target(array $r,int $action):int
    {
        $numeric=$action===15?($r['new_id']??null):($r['old_id']??null);
        if(is_numeric($numeric)&&(int)$numeric>0)return (int)$numeric;
        return ActorEvidence::historyUserId($action===15?($r['new_value']??''):($r['old_value']??''));
    }
    private static function epoch($v):?int{return $v!==null&&$v!==''&&is_numeric($v)?(int)$v:null;}
    private static function status($v):?int{return in_array((string)$v,['1','2','3','4','5','6','10'],true)?(int)$v:null;}
    private static function ids(array $ids):array{$ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);return $ids;}
}
