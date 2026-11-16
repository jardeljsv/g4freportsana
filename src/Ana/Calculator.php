<?php
namespace GlpiPlugin\G4freportsana\Ana;

/** Single ANA calculation consumed unchanged by the UI and both office exports. */
final class Calculator
{
    public static function build(array $input, array $data): array
    {
        $warnings = array_merge($data['warnings'] ?? [], $input['external_evidence']['validation_errors'] ?? []);
        $selected = $excluded = $review = $seen = $events = $unconfirmed = [];
        $evaluator = new SlaEvaluator($data['sla_context'] ?? [], $input);
        $confirmed = SlaEvaluator::confirmed($input);
        $clockLabel = ($input['clock_mode'] ?? 'native_reference') === 'contractual' ? 'relógio contratual' : 'contadores nativos do GLPI (referência)';
        $cutoff = ($input['survey_cutoff'] ?? 'period_end') === 'extraction'
            ? (int)($data['extracted_epoch'] ?? time()) : (int)($input['end_epoch'] ?? PHP_INT_MAX);
        foreach ($data['tickets'] ?? [] as $ticket) {
            $id = (int)($ticket['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) throw new \RuntimeException('Identificação duplicada ou inválida na extração de chamados: ' . $id);
            $seen[$id] = true;
            if (!empty($ticket['scope_review'])) $review[] = $ticket;
            if (empty($ticket['scope_included'])) { $excluded[] = $ticket; continue; }
            $ticket = array_replace($ticket, $evaluator->evaluate($ticket), self::priority($ticket, $input));
            $ticket['tit_within_target'] = $ticket['tit_seconds'] === null ? null : $ticket['tit_seconds'] <= 300;
            $limits = (int)($ticket['type'] ?? 0) === 1 ? ['P1'=>3600,'P2'=>14400,'P3'=>28800] : ['P1'=>7200,'P2'=>21600,'P3'=>43200];
            $ticket['tms_limit_seconds'] = $limits[$ticket['priority_contract'] ?? ''] ?? null;
            $ticket['tms_within_target'] = $ticket['tms_seconds'] === null || $ticket['tms_limit_seconds'] === null
                ? null : $ticket['tms_seconds'] <= $ticket['tms_limit_seconds'];
            $rating = $ticket['satisfaction'] ?? null;
            $answer = $ticket['survey_answered_epoch'] ?? null;
            $ticket['satisfaction_valid'] = is_numeric($rating) && is_finite((float)$rating) && (float)$rating >= 1 && (float)$rating <= 5
                && is_numeric($answer) && (int)$answer < $cutoff;
            $ticket['satisfaction_exclusion_reason'] = $rating === null || $rating === '' ? 'Sem resposta'
                : (!$ticket['satisfaction_valid'] ? 'Nota/data inválida ou resposta posterior ao corte' : '');
            $ticket = array_replace($ticket, self::reopening($ticket, $input, $cutoff));
            foreach ($ticket['reopening_events'] as $event) $events[] = $event;
            $solutions = $ticket['solutions'] ?? [];
            $ticket['has_solution_evidence'] = count($solutions) > 0;
            $last = $solutions ? $solutions[count($solutions)-1] : [];
            if ((int)($last['status'] ?? 0) !== 3) $unconfirmed[] = ['ticket_id'=>$id,'solution_status'=>$last['status'] ?? null,
                'reason'=>!$solutions ? 'Sem registro de solução.' : ([2=>'Solução aguardando aceite.',4=>'Solução recusada.'][(int)($last['status'] ?? 0)] ?? 'Situação do aceite não identificada.')];
            $selected[] = $ticket;
        }
        $allIds = array_column($selected, 'id');
        $scopeIncomplete = count($review) > 0 || !empty($data['selection_incomplete']);
        $metrics = [];
        $rated = array_values(array_filter($selected, static fn($t) => $t['satisfaction_valid']));
        $ratingIds = array_column($rated,'id');
        $sum = array_sum(array_map(static fn($t) => (float)$t['satisfaction'], $rated));
        $m = self::base('IPS1','mean_score',4,'score');
        $m = self::ratio($m,$sum,count($rated),$ratingIds,$ratingIds,[],count($selected),false,1);
        $m['method'] = 'Soma das notas válidas de 1 a 5 ÷ pesquisas respondidas válidas; corte em ' . (new \DateTimeImmutable('@'.$cutoff))->setTimezone(new \DateTimeZone($input['timezone']??'America/Sao_Paulo'))->format('d/m/Y H:i:s P') . ' (' . (($input['survey_cutoff']??'period_end')==='extraction'?'extração':'final da competência') . '; limite exclusivo).' ;
        $m['note'] = 'Fonte: pesquisas de satisfação do GLPI.';
        $m['response_rate_percent'] = count($selected) ? 100*count($rated)/count($selected) : null;
        // Nonresponse is a denominator rule, not missing source data.
        $m['evidence_complete'] = !$scopeIncomplete && ($data['capabilities']['surveys'] ?? $data['capabilities']['satisfaction'] ?? true);
        if (!$m['evidence_complete'] && $m['value'] !== null) $m['status']='provisional';
        $metrics[]=$m;
        $m=self::ticketRatio('IPS2',98,$selected,'tit_within_target',!$confirmed || $scopeIncomplete);
        $m['method']='TIT ≤ 300 segundos; ' . $clockLabel . '.';
        $m['note']='';
        $metrics[]=$m;
        $highCount=count(array_filter($selected,static fn($t)=>in_array($t['priority_contract'],['P1','P2'],true)));
        $priorityCap=['population'=>count($selected),'high_priority_count'=>$highCount,'maximum'=> (int)floor(count($selected)*0.7),
            'excess'=>max(0,$highCount-(int)floor(count($selected)*0.7)),'resolved'=> $highCount <= (int)floor(count($selected)*0.7)];
        if (!$priorityCap['resolved']) $warnings[]='P1/P2: '.$highCount.' de '.count($selected).' chamados; limite contratual de '.$priorityCap['maximum'].'. Excedentes: '.$priorityCap['excess'].'.';
        foreach (['IPS3'=>1,'IPS4'=>2] as $id=>$type) {
            $population=array_values(array_filter($selected,static fn($t)=>(int)($t['type']??0)===$type));
            $parent=self::base($id,'priority_percent_ratios',90);
            $parent['children']=[]; $parent['cohort_count']=count($population);
            $parent['denominator_ids']=array_column($population,'id'); $parent['cohort_ids']=$parent['denominator_ids'];
            $parent['unresolved_ids']=array_column(array_filter($population,static fn($t)=>$t['priority_contract']===null),'id');
            foreach (['P1','P2','P3'] as $priority) {
                $cohort=array_values(array_filter($population,static fn($t)=>$t['priority_contract']===$priority));
                $provisional=!$confirmed || $scopeIncomplete || !$priorityCap['resolved'] || count(array_filter($cohort,static fn($t)=>$t['priority_provisional']))>0;
                $child=self::ticketRatio($id.'_'.$priority,90,$cohort,'tms_within_target',$provisional);
                $child['priority']=$priority; $child['label']=MetricEvidence::title($id).' — '.$priority;
                $child['method']='TMS por prioridade; '.$clockLabel.'; prazo '.(($type===1?['P1'=>1,'P2'=>4,'P3'=>8]:['P1'=>2,'P2'=>6,'P3'=>12])[$priority]).' h.';
                if ($parent['unresolved_ids']) {
                    $child['unclassified_ids']=$parent['unresolved_ids'];
                    $child['evidence_complete']=false;
                    $child['status']=$child['value']===null?'pending':'provisional';
                    $child['note']=trim(($child['note']??'').' Há chamados sem prioridade contratual definida; a composição deste grupo permanece pendente.');
                }
                $parent['children'][]=$child;
            }
            $parent['evaluated_count']=array_sum(array_column($parent['children'],'evaluated_count'));
            $parent['coverage_percent']=count($population)?100*$parent['evaluated_count']/count($population):null;
            $parent['status']=self::childrenStatus($parent['children'],!empty($parent['unresolved_ids']));
            $parent['evidence_complete']=!$parent['unresolved_ids'] && !in_array(false,array_column($parent['children'],'evidence_complete'),true);
            $parent['method']='Verificação independente de P1, P2 e P3; mínimo 90% em cada prioridade. Sem média entre prioridades.';
            $parent['note']=!$priorityCap['resolved']?'Limite de 70% P1/P2 exige definição dos chamados excedentes.':'';
            $metrics[]=$parent;
        }
        $reopenDen=$reopenGood=$reopenUnknown=[];
        foreach($selected as $t) {
            if (!$t['has_solution_evidence'] || !$t['reopening_evidence_complete'] || (int)($t['status']??0)!==6) { $reopenUnknown[]=$t['id']; continue; }
            $reopenDen[]=$t['id']; if(!$t['reopened'])$reopenGood[]=$t['id'];
        }
        $m=self::ratio(self::base('IPS5','percent_ratio',98),count($reopenGood),count($reopenDen),$reopenGood,$reopenDen,$reopenUnknown,count($selected),!$confirmed || $scopeIncomplete);
        $m['method']='100 × (chamados fechados com solução − chamados distintos reabertos) ÷ chamados fechados com solução; '
            .(($input['reopening_mode']??'solved_or_closed')==='closed_only'?'retorno de Fechado':'retorno de Solucionado ou Fechado').' para estado ativo; observação até '.(new \DateTimeImmutable('@'.$cutoff))->setTimezone(new \DateTimeZone($input['timezone']??'America/Sao_Paulo'))->format('d/m/Y H:i:s P').' (limite exclusivo).';
        $m['note']='';
        $m['reopening_event_count']=count($events); $m['reopened_ticket_ids']=array_column(array_filter($selected,static fn($t)=>$t['reopened']),'id');
        $metrics[]=$m;
        [$calls,$legs,$excludedCalls,$phoneIssues]=self::calls($input,$data['calls']??[]);
        $warnings=array_merge($warnings,$phoneIssues);
        $includeDesistencias=$input['ips6_include_desistencias']??true;
        $eligibleCalls=array_values(array_filter($calls,static fn($c)=>$c['ips6_included']));
        $callDen=array_column($eligibleCalls,'call_key'); $answered=array_column(array_filter($eligibleCalls,static fn($c)=>!empty($c['answered'])),'call_key');
        $unknownCalls=array_values(array_unique(array_column(array_filter($eligibleCalls,static fn($c)=>!empty($c['unknown_outcome'])||(!$includeDesistencias&&!empty($c['abandon_wait_unknown']))),'call_key')));
        $unknownEligibility=!$includeDesistencias&&count(array_filter($eligibleCalls,static fn($c)=>!empty($c['abandon_wait_unknown'])))>0;
        $excludedDesistencias=array_column(array_filter($calls,static fn($c)=>!$c['ips6_included']),'call_key');
        $phoneAvailable=!empty($data['calls_available']);
        $phoneEvidence=$input['external_evidence']['phone']??[];
        $netValid=($phoneEvidence['valid']??true)!==false && isset($phoneEvidence['forwarded_calls'],$phoneEvidence['answered_calls'])
            && is_numeric($phoneEvidence['forwarded_calls']) && is_numeric($phoneEvidence['answered_calls'])
            && (float)$phoneEvidence['forwarded_calls']>=0 && (float)$phoneEvidence['answered_calls']>=0
            && (float)$phoneEvidence['answered_calls']<=(float)$phoneEvidence['forwarded_calls']
            && trim((string)($phoneEvidence['evidence_reference']??''))!=='';
        $m=self::ratio(self::base('IPS6','percent_ratio',99),count($answered),count($callDen),$answered,$callDen,$unknownCalls,count($callDen),true);
        $m['evaluated_count']=count($callDen)-count($unknownCalls);
        $m['coverage_percent']=$m['cohort_count']>0?100*$m['evaluated_count']/$m['cohort_count']:null;
        $m['desistencias_included']=(bool)$includeDesistencias;
        $m['excluded_ids']=$excludedDesistencias;$m['excluded_count']=count($excludedDesistencias);
        $m['method']='Atendidas ÷ recebidas nas filas exatas ANA × 100; unidade '.(($input['call_unit']??'interaction')==='queue_leg'?'entrada individual na fila':'ligação consolidada por identificador').'.';
        if(!$includeDesistencias)$m['method'].=' Desistências com espera inferior a 30 segundos excluídas do denominador.';
        $m['note']='Totais das filas sem conciliação com os encaminhamentos da rede.';
        $m['queue_observed']=['available'=>$phoneAvailable,'received'=>$phoneAvailable?count($calls):null,'answered'=>$phoneAvailable?count($answered):null,'unknown'=>$phoneAvailable?count($unknownCalls):null];
        if($unknownEligibility){
            // Candidate call IDs remain available for audit, but missing waits
            // prevent determining the denominator under the exclusion policy.
            $m['value']=$m['denominator']=$m['observed_compliance']=null;
            $m['status']='pending';$m['evidence_complete']=false;
            $m['note']='Espera não registrada em desistências ou abandonos; denominador pendente de apuração.';
        }
        if(!$phoneAvailable){$m['value']=$m['numerator']=$m['denominator']=null;$m['status']='pending';$m['evidence_complete']=false;}
        if($netValid){
            $networkDesistencias=$phoneEvidence['desisted_calls']??null;
            $networkDesistenciasValid=is_numeric($networkDesistencias)&&is_finite((float)$networkDesistencias)
                && (float)$networkDesistencias>=0 && (float)$networkDesistencias===floor((float)$networkDesistencias)
                && (float)$networkDesistencias<=(float)$phoneEvidence['forwarded_calls']-(float)$phoneEvidence['answered_calls'];
            $networkDen=(float)$phoneEvidence['forwarded_calls']-(!$includeDesistencias&&$networkDesistenciasValid?(float)$networkDesistencias:0);
            $m=self::ratio($m,(float)$phoneEvidence['answered_calls'],$networkDen,[],[],[],(int)$networkDen,!$confirmed);
            // Network totals and queue records are separate populations. Never
            // use queue exclusions to alter a documented network total.
            $m['excluded_ids']=[];
            $m['excluded_count']=$includeDesistencias?0:($networkDesistenciasValid?(int)$networkDesistencias:null);
            $m['method']='Totais de encaminhamento da rede documentados em '.$phoneEvidence['evidence_reference'].'.';
            if(!$includeDesistencias)$m['method'].=' Desistências com espera inferior a 30 segundos excluídas do denominador.';
            $m['note']='Fonte: totais de encaminhamento da rede.';
            $m['evidence_reference']=$phoneEvidence['evidence_reference'];
            if(!$includeDesistencias&&!$networkDesistenciasValid){
                $m['value']=$m['numerator']=$m['denominator']=null;$m['observed_compliance']=null;
                $m['status']='pending';$m['evidence_complete']=false;$m['evaluated_count']=0;$m['coverage_percent']=null;
                $m['note']='Quantidade de desistências da rede não informada ou incompatível com os totais.';
            }
        }
        $metrics[]=$m;
        foreach(['IPS7'=>99.9,'IPS8'=>99.7]as$id=>$target)$metrics[]=self::availability($id,$target,$input['external_evidence']??[]);
        $metrics[]=self::controls($input['external_evidence']??[],$input);
        $metrics[]=self::availability('IPS10',99.5,$input['external_evidence']??[]);
        $totals=['tickets'=>count($selected),'requests'=>count(array_filter($selected,static fn($t)=>(int)$t['type']===2)),
            'incidents'=>count(array_filter($selected,static fn($t)=>(int)$t['type']===1)), 'scope_review'=>count($review),
            'calls_received'=>$phoneAvailable?count($calls):null,'calls_answered'=>$phoneAvailable?count($answered):null,'calls_abandoned'=>$phoneAvailable?count(array_filter($calls,static fn($c)=>!empty($c['abandoned']))):null,
            'calls_desisted'=>$phoneAvailable?count(array_filter($calls,static fn($c)=>!empty($c['desisted']))):null,
            'calls_abandon_wait_unknown'=>$phoneAvailable?count(array_filter($calls,static fn($c)=>!empty($c['abandon_wait_unknown']))):null,
            'calls_available'=>$phoneAvailable, 'calls_network_forwarded'=>$netValid?(int)$phoneEvidence['forwarded_calls']:null,
            'calls_network_answered'=>$netValid?(int)$phoneEvidence['answered_calls']:null,
            'mean_talk_seconds'=>$phoneAvailable?self::meanField(array_filter($calls,static fn($c)=>!empty($c['answered'])),'talk_seconds'):null,
            'mean_wait_seconds'=>$phoneAvailable?self::meanField(array_filter($calls,static fn($c)=>!empty($c['answered'])),'wait_seconds'):null];
        if(!$confirmed)$warnings[]='Metodologia contratual sem aprovação registrada.';
        if($scopeIncomplete)$warnings[]=$review?'Escopo pendente de definição: '.count($review).' chamados.':'Seleção de chamados incompleta.';
        return ['schema_version'=>3,'rules_version'=>Settings::RULES_VERSION,'generated_at'=>gmdate('c'),'input'=>$input,
            'period'=>['month'=>$input['month'],'start'=>$input['start']??'','end_exclusive'=>$input['end_exclusive']??'',
                'label'=>$input['month'],'date_basis'=>$input['scope_basis']??'closed'],
            'totals'=>$totals,'metrics'=>$metrics,'tickets'=>$selected,'scope_review'=>$review,'excluded_tickets'=>$excluded,
            'calls'=>$calls,'call_legs'=>$legs,'excluded_calls'=>$excludedCalls,'reopening_events'=>$events,'unconfirmed_tickets'=>$unconfirmed,
            'warnings'=>array_values(array_unique($warnings)),'priority_cap'=>$priorityCap,'financial'=>[],
            'rule_notes'=>['ANA: dez IPS; notas médias não são percentuais.','O recorte por fechamento inclui chamados abertos antes da competência.',
                'Escalonamento não reinicia o prazo contratual. Pendência nativa não comprova suspensão aprovada.',
                'Reaberturas não possuem dispensa pelo mesmo solucionador.','Zero nativo é dado válido, distinto de ausência.'],
            'manual'=>$input['external_evidence']??[],'external_evidence'=>$input['external_evidence']??[],
            'source_manifest'=>$data['source_manifest']??[],'capabilities'=>$data['capabilities']??[],
            'selection_catalog'=>$data['selection_catalog']??[],'sla_reference'=>$data['sla_context']??[]];
    }

    private static function base(string $id,string $kind,?float $target,string $unit='percent'):array
    {
        $labels=MetricEvidence::labels($id);
        return ['id'=>$id,'label'=>MetricEvidence::title($id),'value_kind'=>$kind,'unit'=>$unit,'numerator'=>null,'denominator'=>null,
            'value'=>null,'target'=>$target,'direction'=>'gte','status'=>'pending','evidence_complete'=>false,'method'=>'','note'=>'',
            'numerator_label'=>$labels['numerator'],'denominator_label'=>$labels['denominator'],
            'evidence_ids'=>[],'denominator_ids'=>[],'unresolved_ids'=>[],'cohort_count'=>0,'evaluated_count'=>0,'coverage_percent'=>null];
    }
    private static function ratio(array $m,$num,$den,array $good,array $ids,array $unknown,int $cohort,bool $provisional,float $scale=100):array
    {
        $m['numerator']=$num;$m['denominator']=$den;$m['value']=$den>0?$scale*$num/$den:null;
        $m['evidence_ids']=array_values($good);$m['denominator_ids']=array_values($ids);$m['unresolved_ids']=array_values($unknown);
        $m['cohort_ids']=array_values(array_unique(array_merge($ids,$unknown)));$m['cohort_count']=$cohort;
        $m['evaluated_count']=(int)$den;$m['coverage_percent']=$cohort>0?100*$den/$cohort:null;
        $m['evidence_complete']=!$unknown;
        $m['status']=$den<=0?($cohort>0?'pending':'no_occurrences'):(($provisional||$unknown)?'provisional':($m['value']>=$m['target']?'met':'unmet'));
        $m['observed_compliance']=$m['value']===null?null:$m['value']>=$m['target'];
        return $m;
    }
    private static function ticketRatio(string $id,float $target,array $tickets,string $field,bool $provisional):array
    {
        $yes=$den=$unknown=[];
        foreach($tickets as$t){if(($t[$field]??null)===null){$unknown[]=$t['id'];continue;}$den[]=$t['id'];if($t[$field])$yes[]=$t['id'];
            if(!empty($t['sla_provisional']))$provisional=true;}
        $m=self::ratio(self::base($id,'percent_ratio',$target),count($yes),count($den),$yes,$den,$unknown,count($tickets),$provisional);
        if($unknown)$m['note']='Percentual observado somente nos registros avaliáveis; cobertura incompleta impede conclusão contratual.';
        return $m;
    }
    private static function priority(array $ticket,array $input):array
    {
        $id=(int)$ticket['id'];$result=['priority_contract'=>null,'priority_basis'=>'Sem classificação verificável','priority_provisional'=>true];
        foreach($input['priority_overrides']??[]as$key=>$override){
            if(!is_array($override))continue;
            if((int)($override['ticket_id']??$key)!==$id)continue;
            $p=$override['priority']??null;
            if(in_array($p,['P1','P2','P3'],true)&&trim((string)($override['reason']??''))!=='')return ['priority_contract'=>$p,'priority_basis'=>'Classificação documentada: '.$override['reason'],'priority_provisional'=>false];
        }
        if(in_array($id,array_map('intval',$input['vip_ticket_ids']??[]),true))return ['priority_contract'=>'P1','priority_basis'=>'VIP informado com evidência administrativa','priority_provisional'=>false];
        $map=$input['priority_value_map']??[];$p=$map[(string)($ticket['priority']??'')]??null;
        if(in_array($p,['P1','P2','P3'],true))return ['priority_contract'=>$p,'priority_basis'=>'Mapeamento explícito da prioridade GLPI','priority_provisional'=>!SlaEvaluator::confirmed($input)];
        // GLPI business rules can set the final priority independently of
        // urgency and impact. Use the stored result for the ANA P1/P2/P3
        // classification; an approved explicit mapping above takes precedence.
        $defaultMap=[1=>'P3',2=>'P3',3=>'P2',4=>'P1',5=>'P1',6=>'P1'];
        $glpiPriority=(int)($ticket['priority']??0);
        if(isset($defaultMap[$glpiPriority]))
            return ['priority_contract'=>$defaultMap[$glpiPriority],
                'priority_basis'=>'Prioridade final registrada no GLPI; correspondência ANA P1/P2/P3',
                'priority_provisional'=>!SlaEvaluator::confirmed($input)];
        return $result;
    }
    private static function reopening(array $ticket,array $input,int $cutoff):array
    {
        $events=[];$sources=($input['reopening_mode']??'solved_or_closed')==='closed_only'?[6]:[5,6];
        foreach($ticket['status_events']??[]as$event){$from=(int)($event['from_status']??$event['old_status']??0);$to=(int)($event['to_status']??$event['new_status']??0);
            $epoch=(int)($event['epoch']??$event['occurred_epoch']??0);
            if($epoch>0&&$epoch<$cutoff&&in_array($from,$sources,true)&&in_array($to,[1,2,3,4,10],true)){
                $events[]=array_replace($event,['ticket_id'=>(int)$ticket['id'],'from_status'=>$from,'to_status'=>$to,'epoch'=>$epoch,'administrative_exempt'=>false]);}}
        return ['reopened'=>(bool)$events,'reopening_events'=>$events,
            'reopening_evidence_complete'=>!empty($ticket['reopening_history_complete']??$ticket['history_complete']??false)];
    }
    private static function calls(array $input,array $source):array
    {
        $legs=$excluded=$groups=$warnings=[];$queues=$input['queue_names']??['ana','ana-transbordo'];$test=[];
        foreach($input['test_numbers']??[] as $entry){
            // Saved Settings rows carry an explicit active flag. Keep scalar
            // numbers accepted by earlier direct calculator callers as well.
            if(is_array($entry)){
                if(!in_array($entry['active']??true,[true,1,'1'],true))continue;
                $entry=$entry['number']??'';
            }
            if(!is_string($entry)&&!is_int($entry))continue;
            $digits=Settings::callerDigits($entry);
            if($digits!=='')$test[$digits]=true;
        }
        foreach($source as$i=>$c){
            $c=PhoneData::classifyAbandonment($c);
            $c['call_key']=(string)($c['call_key']??(($c['callid']??$c['id']??'').'|'.($c['queue']??'').'|'.($c['entered_epoch']??$i)));
            if(!in_array($c['queue']??'',$queues,true)){$c['excluded_reason']='Fila fora da seleção exata';$excluded[]=$c;continue;}
            if(isset($test[preg_replace('/\D/','',(string)($c['caller']??''))])){$c['excluded_reason']='Número de teste cadastrado';$excluded[]=$c;continue;}
            $legs[]=$c;
            if(($input['call_unit']??'interaction')==='queue_leg' && (!empty($c['context_only']) || (isset($c['in_period'])&&!$c['in_period']))) {$c['excluded_reason']='Perna de contexto fora da competência';$excluded[]=$c;continue;}
            $key=($input['call_unit']??'interaction')==='queue_leg'?$c['call_key']:(string)($c['callid']??'');
            if($key===''){$key=$c['call_key'];$c['linkage_uncertain']=true;}
            $groups[$key][]=$c;
        }
        $calls=[];
        foreach($groups as$key=>$rows){usort($rows,static fn($a,$b)=>(int)($a['entered_epoch']??0)<=>(int)($b['entered_epoch']??0));$c=$rows[0];
            if(($input['call_unit']??'interaction')==='interaction' && (isset($c['in_period'])&&!$c['in_period'])) {$c['excluded_reason']='Interação iniciada fora da competência; pernas preservadas como contexto';$excluded[]=$c;continue;}
            $c['call_key']=$key;$c['leg_count']=count($rows);$c['leg_keys']=array_column($rows,'call_key');$c['queues']=array_values(array_unique(array_column($rows,'queue')));$c['queue']=implode(' → ',$c['queues']);
            $c['answered']=count(array_filter($rows,static fn($r)=>!empty($r['answered'])))>0;
            $last=$rows[count($rows)-1];$c['abandon_recorded']=!$c['answered']&&!empty($last['abandon_recorded']);
            $knownExit=in_array($last['terminal_event']??'',['EXITWITHTIMEOUT','EXITWITHKEY','EXITEMPTY'],true);
            $c['unknown_outcome']=!$c['answered']&&!$c['abandon_recorded']&&!$knownExit;$c['transferred']=count(array_filter($rows,static fn($r)=>!empty($r['transferred'])))>0;
            $answeredRows=array_values(array_filter($rows,static fn($r)=>!empty($r['answered'])));
            usort($answeredRows,static fn($a,$b)=>(int)($a['answered_epoch']??PHP_INT_MAX)<=>(int)($b['answered_epoch']??PHP_INT_MAX));
            $firstAnswer=$answeredRows?$answeredRows[0]:null;
            $c['answered_at']=$firstAnswer['answered_at']??null;$c['answered_epoch']=$firstAnswer['answered_epoch']??null;
            $c['ended_at']=$last['ended_at']??null;$c['ended_epoch']=$last['ended_epoch']??null;
            $c['wait_seconds']=$firstAnswer&&isset($c['entered_epoch'],$firstAnswer['answered_epoch'])?max(0,(int)$firstAnswer['answered_epoch']-(int)$c['entered_epoch']):($firstAnswer['wait_seconds']??null);
            if(!$c['answered']){
                $waits=array_column($rows,'wait_seconds');
                $completeWait=count($waits)===count($rows)&&!in_array(null,$waits,true);
                $c['wait_seconds']=$completeWait?array_sum($waits):null;
                $c['wait_basis']=$completeWait?(count($rows)>1?'sum_queue_registered_waits':($last['wait_basis']??'queue_log_registered')):'unavailable';
            }
            // Reclassify only after queue waits are consolidated. A short final
            // queue visit does not make a longer interaction a desistência.
            unset($c['desisted'],$c['abandoned'],$c['abandon_wait_unknown']);
            $c=PhoneData::classifyAbandonment($c);
            $c['ips6_included']=($input['ips6_include_desistencias']??true)||!$c['desisted'];
            $c['ips6_exclusion_reason']=$c['ips6_included']?'':'Desistência com espera inferior a 30 segundos.';
            $talk=array_filter(array_column($answeredRows,'talk_seconds'),static fn($v)=>SlaEvaluator::seconds($v)!==null);
            $c['talk_seconds']=$talk&&count($talk)===count($answeredRows)?array_sum($talk):null;
            $c['linkage_basis']=count($rows)>1?'same_callid':'individual_queue_entry';$c['linkage_uncertain']=!empty($c['linkage_uncertain'])||($c['transferred']&&count($rows)===1);
            $calls[]=$c;
        }
        if(count(array_filter($calls,static fn($c)=>$c['linkage_uncertain'])))$warnings[]='Há transferências sem outra perna comprovada pelo mesmo callid; não se inferiu vínculo entre IDs diferentes.';
        return[$calls,$legs,$excluded,$warnings];
    }
    private static function availability(string $id,float $target,array $external):array
    {
        $m=self::base($id,'percent_ratio',$target);$m['children']=[];$invalid=[];
        foreach($external['services']??[]as$i=>$service){if(($service['metric_id']??'')!==$id)continue;
            $child=self::base($id.'_S'.($i+1),'percent_ratio',$target);$child['label']=$service['service']??'Serviço';$child['source_evidence']=$service;
            $window=$service['window_seconds']??null;$down=$service['downtime_seconds']??null;$excluded=$service['excluded_seconds']??0;
            $valid=($service['valid']??true)!==false && SlaEvaluator::seconds($window)!==null&&SlaEvaluator::seconds($down)!==null&&SlaEvaluator::seconds($excluded)!==null
                &&$window>0&&$excluded<$window&&$down<=($window-$excluded)&&trim((string)($service['evidence_reference']??''))!==''
                &&($excluded==0||trim((string)($service['approval_reference']??''))!=='');
            if($valid){$den=(float)$window-(float)$excluded;$child=self::ratio($child,$den-(float)$down,$den,[],[],[],(int)$den,false);}
            else $invalid[]=$child['id'];
            $child['method']='100 × (janela líquida − indisponibilidade) ÷ janela líquida; exclusões somente aprovadas.';$m['children'][]=$child;
        }
        $valid=array_values(array_filter($m['children'],static fn($c)=>$c['value']!==null));
        $m['cohort_count']=count($m['children']);$m['evaluated_count']=count($valid);$m['coverage_percent']=$m['cohort_count']?100*count($valid)/$m['cohort_count']:null;
        $m['unresolved_ids']=$invalid;$m['evidence_complete']=$m['cohort_count']>0&&!$invalid;
        if($valid){$worst=$valid[0];foreach($valid as$c)if($c['value']<$worst['value'])$worst=$c;
            $m['value']=$worst['value'];$m['numerator']=$worst['numerator'];$m['denominator']=$worst['denominator'];$m['worst_service']=$worst['label'];
            $m['status']=$invalid?'provisional':($m['value']>=$target?'met':'unmet');}
        $m['method']='Disponibilidade individual por serviço; síntese representa o menor resultado, sem média ponderada.';
        $m['note']='A apuração requer evidências de monitoramento e identificação da janela de cada serviço.';
        return $m;
    }
    private static function controls(array $external,array $input):array
    {
        $m=self::base('IPS9','compliance',null,'compliance');$m['children']=[];
        $scope=trim((string)($external['controls_scope_reference']??$external['scope_reference']??''));$applicable=$external['controls_applicable']??$external['applicable']??null;
        if($applicable===false&&$scope!==''){$m['status']='not_applicable';$m['evidence_complete']=true;$m['note']='Inaplicabilidade documentada: '.$scope;return$m;}
        foreach($external['controls']??[]as$i=>$control){$c=self::base('IPS9_C'.($i+1),'compliance',null,'compliance');$c['label']=$control['description']??$control['id']??'Controle';$c['source_evidence']=$control;
            $due=$control['due_date']??'';$validDate=is_string($due)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$due)&&checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4));
            $target=$control['target_percent']??null;$completion=$control['completion_percent']??null;
            if($validDate&&substr($due,0,7)>($input['month']??'')){$c['status']='not_applicable';$c['note']='Vencimento posterior à competência';$c['evidence_complete']=true;}
            elseif(($control['valid']??true)!==false&&$validDate&&is_numeric($target)&&$target>=0&&$target<=100&&is_numeric($completion)&&$completion>=0&&$completion<=100&&$scope!==''&&$applicable===true){
                $accepted=trim((string)($control['acceptance_reference']??''))!=='';
                if($completion<$target){$c['value']=0;$c['status']='unmet';$c['evidence_complete']=true;}
                elseif($accepted){$c['value']=1;$c['status']='met';$c['evidence_complete']=true;}
                else{$c['status']='pending';$c['note']='Execução informada sem aceite documentado';}
            }
            $m['children'][]=$c;
        }
        $active=array_values(array_filter($m['children'],static fn($c)=>$c['status']!=='not_applicable'));
        $m['cohort_count']=count($active);$m['evaluated_count']=count(array_filter($active,static fn($c)=>$c['value']!==null));
        $m['coverage_percent']=count($active)?100*$m['evaluated_count']/count($active):null;
        $m['status']=self::childrenStatus($active,false);
        if(!$active)$m['status']=$m['children']?'not_applicable':'pending';
        $m['evidence_complete']=$scope!==''&&$applicable===true&&count($active)>0&&$m['evaluated_count']===count($active);
        if($m['evidence_complete'])$m['value']=$m['status']==='met'?1:0;
        $m['method']='Verificação dos controles, percentuais de execução e prazos definidos no escopo aprovado, mediante registro de avaliação e aceite.';
        return$m;
    }
    private static function childrenStatus(array $children,bool $unknown):string
    {
        $states=array_column($children,'status');
        if(in_array('unmet',$states,true))return 'unmet';
        if($unknown||in_array('pending',$states,true))return 'pending';
        if(in_array('provisional',$states,true))return 'provisional';
        if(in_array('met',$states,true))return 'met';
        return 'no_occurrences';
    }
    private static function meanField(array $rows,string $key):?float
    { $values=[];foreach($rows as$r)if(SlaEvaluator::seconds($r[$key]??null)!==null)$values[]=(float)$r[$key];return$values?array_sum($values)/count($values):null; }
}
