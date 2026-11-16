<?php
namespace GlpiPlugin\G4freportsana\Ana;

/** Tables are projections of the immutable ANA snapshot; they never query a source. */
final class MetricEvidence
{
    public static function title(string $id, ?int $priority=null):string
    {
        $base=explode('_',$id)[0];
        return ['IPS1'=>'Satisfação dos usuários','IPS2'=>'Início de tratamento no prazo','IPS3'=>'Resolução de incidentes por prioridade',
            'IPS4'=>'Resolução de requisições por prioridade','IPS5'=>'Chamados sem reabertura','IPS6'=>'Atendimento das chamadas encaminhadas',
            'IPS7'=>'Disponibilidade dos serviços críticos','IPS8'=>'Disponibilidade dos serviços essenciais',
            'IPS9'=>'Implementação dos controles acordados','IPS10'=>'Disponibilidade do SIEM'][$base]??$id;
    }
    public static function labels(string $id):array
    {
        $base=explode('_',$id)[0];$pair=[
            'IPS1'=>['Soma das notas válidas','Respostas válidas'],
            'IPS2'=>['Chamados com TIT até 5 minutos','Chamados com TIT avaliável'],
            'IPS3'=>['Incidentes dentro do prazo da prioridade','Incidentes avaliáveis da prioridade'],
            'IPS4'=>['Requisições dentro do prazo da prioridade','Requisições avaliáveis da prioridade'],
            'IPS5'=>['Chamados fechados com solução sem reabertura','Chamados fechados com solução e histórico verificável'],
            'IPS6'=>['Chamadas atendidas','Chamadas encaminhadas'],
            'IPS7'=>['Tempo disponível','Janela líquida de medição'],
            'IPS8'=>['Tempo disponível','Janela líquida de medição'],
            'IPS10'=>['Tempo disponível','Janela líquida de medição'],
            'IPS9'=>['Controles com resultado conforme','Controles acordados aplicáveis'],
        ][$base]??['Numerador','Denominador'];return ['numerator'=>$pair[0],'denominator'=>$pair[1]];
    }
    public static function statusLabel(string $status):string
    {return ['met'=>'Atendido','unmet'=>'Não atendido','pending'=>'Não apurado','provisional'=>'Provisório','no_occurrences'=>'Sem ocorrências','not_applicable'=>'Não aplicável'][$status]??$status;}
    public static function category(array $ticket):string{return (string)($ticket['category']??'');}
    public static function priorityName($priority):string{return [1=>'Muito baixa',2=>'Baixa',3=>'Média',4=>'Alta',5=>'Muito alta',6=>'Maior'][(int)$priority]??'Não mapeada';}
    public static function duration($seconds):string
    {if(!is_numeric($seconds)||$seconds<0)return 'Não apurado';$s=(int)$seconds;return sprintf('%d:%02d:%02d',intdiv($s,3600),intdiv($s%3600,60),$s%60);}
    public static function callKey(array $call):string{return (string)($call['call_key']??$call['callid']??$call['id']??'');}
    public static function table(array $snapshot,string $id,string $scope='denominator'):array
    {
        $metric=self::findMetric($snapshot,$id);$base=explode('_',$id)[0];
        $num=array_fill_keys(array_map('strval',$metric['evidence_ids']??[]),true);
        $den=array_fill_keys(array_map('strval',$metric['denominator_ids']??[]),true);
        $unknown=array_fill_keys(array_map('strval',$metric['unresolved_ids']??[]),true);
        $excluded=array_fill_keys(array_map('strval',$metric['excluded_ids']??[]),true);
        if (($metric['value_kind'] ?? '') === 'priority_percent_ratios') {
            // Parent evidence displays each ticket's priority assessment; it is
            // not an aggregate ratio and has no numerator of its own.
            $num = $den = [];
            foreach ($metric['children'] ?? [] as $child) {
                $num += array_fill_keys(array_map('strval', $child['evidence_ids'] ?? []), true);
                $den += array_fill_keys(array_map('strval', $child['denominator_ids'] ?? []), true);
                $unknown += array_fill_keys(array_map('strval', $child['unresolved_ids'] ?? []), true);
            }
        }
        if($base==='IPS6'){
            $headers=['Identificador','Filas','Origem','Entrada','Atendimento','Conclusão','Atendida','Abandonada','Sem desfecho','Espera (s)','Conversa (s)','Pernas','Vínculo','Incerteza de vínculo',
                'Desistência (espera inferior a 30 segundos)','Abandono sem espera apurada','Numerador','Denominador','Pendente','Excluída do IPS6','Motivo da exclusão IPS6'];$rows=[];
            $individualEvidence=empty($metric['evidence_reference']);
            foreach($snapshot['calls']??[]as$c){$key=self::callKey($c);
                $eligibilityPending=$individualEvidence&&!isset($metric['denominator'])&&isset($unknown[$key])&&!empty($c['abandon_wait_unknown'])
                    &&!($metric['desistencias_included']??$snapshot['input']['ips6_include_desistencias']??true);
                if($scope==='numerator'&&(!isset($num[$key])||isset($excluded[$key])))continue;
                if($scope==='denominator'&&(!isset($den[$key])||isset($excluded[$key])||$eligibilityPending))continue;
                if($scope==='excluded'&&!isset($excluded[$key]))continue;
                $rows[]=[$key,$c['queue']??'',$c['caller']??'',$c['entered_at']??'',$c['answered_at']??'',$c['ended_at']??'',
                    self::yes(!empty($c['answered'])),self::yes(!empty($c['abandoned'])),self::yes(!empty($c['unknown_outcome'])),
                    $c['wait_seconds']??null,$c['talk_seconds']??null,$c['leg_count']??1,$c['linkage_basis']??'',self::yes(!empty($c['linkage_uncertain'])),
                    isset($c['desisted'])?self::yes((bool)$c['desisted']):null,isset($c['abandon_wait_unknown'])?self::yes((bool)$c['abandon_wait_unknown']):null,
                    $individualEvidence?self::yes(isset($num[$key])&&!isset($excluded[$key])):null,
                    $individualEvidence?($eligibilityPending?'Pendente':self::yes(isset($den[$key])&&!isset($excluded[$key]))):null,
                    $individualEvidence?self::yes(isset($unknown[$key])):null,
                    $individualEvidence?self::yes(isset($excluded[$key])):null,
                    $individualEvidence&&isset($excluded[$key])?($c['ips6_exclusion_reason']??''):null];}
            return ['headers'=>$headers,'rows'=>$rows];
        }
        if(in_array($base,['IPS7','IPS8','IPS10'],true)){
            $headers=['Serviço','Janela (s)','Indisponibilidade (s)','Exclusões (s)','Evidência','Aprovação das exclusões','Disponibilidade (%)','Resultado'];$rows=[];
            foreach($metric['children']??[]as$c){$s=$c['source_evidence']??[];$rows[]=[$c['label'],$s['window_seconds']??null,$s['downtime_seconds']??null,$s['excluded_seconds']??0,$s['evidence_reference']??'',$s['approval_reference']??'',$c['value'],self::statusLabel($c['status'])];}
            return['headers'=>$headers,'rows'=>$rows];
        }
        if($base==='IPS9'){
            $headers=['Controle','Vencimento','Percentual acordado','Percentual executado','Aceite','Resultado'];$rows=[];
            foreach($metric['children']??[]as$c){$s=$c['source_evidence']??[];$rows[]=[$c['label'],$s['due_date']??'',$s['target_percent']??null,$s['completion_percent']??null,$s['acceptance_reference']??'',self::statusLabel($c['status'])];}
            return ['headers'=>$headers,'rows'=>$rows];
        }
        $headers=['Chamado','Título','Tipo','Categoria','Abertura','Solução','Fechamento','Grupos responsáveis','Prioridade GLPI','Prioridade ANA','Base da prioridade',
            'TIT nativo (s)','TMS nativo (s)','TIT apurado (s)','TMS apurado (s)','Limite TMS (s)','Método do relógio','Pausas aprovadas TMS (s)',
            'Nota','Resposta válida no corte','Reaberto','Eventos de reabertura','Solução documentada','Histórico de reabertura completo','Numerador','Denominador','Pendente'];
        $rows=[];
        foreach($snapshot['tickets']??[]as$t){$key=(string)$t['id'];
            if($scope==='numerator'&&!isset($num[$key]))continue;
            if(in_array($base,['IPS3','IPS4'],true)&&((int)$t['type']!==($base==='IPS3'?1:2)))continue;
            if(isset($metric['priority'])&&($t['priority_contract']??null)!==$metric['priority'])continue;
            // Full selected cohort is retained so unanswered/unknown records remain auditable.
            $rows[]=[$t['id'],$t['title']??'',(int)$t['type']===1?'Incidente':'Requisição',self::category($t),$t['opened_at']??'',$t['solved_at']??'',$t['closed_at']??'',
                implode(', ',$t['responsibility_group_ids']??[]),$t['priority']??null,$t['priority_contract']??'',$t['priority_basis']??'',
                $t['native_tit_seconds']??null,$t['native_tms_seconds']??null,$t['tit_seconds']??null,$t['tms_seconds']??null,$t['tms_limit_seconds']??null,
                $t['clock_method']??'',$t['approved_pause_seconds']??0,$t['satisfaction']??null,self::yes(!empty($t['satisfaction_valid'])),
                self::yes(!empty($t['reopened'])),count($t['reopening_events']??[]),self::yes(!empty($t['has_solution_evidence'])),self::yes(!empty($t['reopening_evidence_complete'])),
                self::yes(isset($num[$key])),self::yes(isset($den[$key])),self::yes(isset($unknown[$key]))];
        }
        return ['headers'=>$headers,'rows'=>$rows];
    }
    public static function calendarReferenceRows(array $snapshot):array
    {
        $input=$snapshot['input'];return [['Modo',$input['clock_mode']??'native_reference'],['Calendário contratual',$input['calendar_mode']??'onsite'],
            ['Feriados contratuais',implode(', ',$input['contractual_holidays']??[])],['Referência dos feriados',$input['holiday_reference']??'Não informada'],
            ['Metodologia',$input['methodology_reference']??'Não confirmada']];
    }
    private static function findMetric(array $snapshot,string $id):array
    {foreach($snapshot['metrics']??[]as$m){if($m['id']===$id)return$m;foreach($m['children']??[]as$c)if($c['id']===$id)return$c;}return[];}
    private static function yes(bool $v):string{return $v?'Sim':'Não';}
}
