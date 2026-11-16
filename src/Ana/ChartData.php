<?php
namespace GlpiPlugin\G4freportsana\Ana;

/** Chart definitions retain score/percent/compliance units from the frozen metrics. */
final class ChartData
{
    public static function build(array $snapshot):array
    {
        $charts=[];
        foreach($snapshot['metrics']??[]as$m){
            $items=($m['value_kind']??'')==='priority_percent_ratios'?($m['children']??[]):[$m];
            $categories=$targets=$values=[];$percent=($m['unit']??'')==='percent';
            foreach($items as$c){$categories[]=$c['priority']??$c['id'];$targets[]=$c['target']===null?null:($percent?$c['target']/100:$c['target']);
                $values[]=$c['value']===null?null:($percent?$c['value']/100:$c['value']);}
            $charts[$m['id']]=['id'=>$m['id'],'title'=>$m['id'].' — '.$m['label'],'categories'=>$categories,
                'series'=>[['name'=>'Meta','values'=>$targets,'color'=>ReportStyle::NAVY],['name'=>'Resultado apurado','values'=>$values,'color'=>ReportStyle::BLUE]],
                'kind'=>'column','stacked'=>false,'unit'=>$m['unit'],'available'=>count(array_filter($values,static fn($v)=>$v!==null))>0,
                'axis_category_title'=>'Indicador / prioridade','axis_value_title'=>$percent?'Percentual (%)':(($m['unit']??'')==='score'?'Nota (1 a 5)':'Conformidade (0/1)'),
                'note'=>MetricEvidence::statusLabel($m['status']).'. '.($m['method']??'').' '.($m['note']??'')];
        }
        $types=[0,0,0];foreach($snapshot['tickets']??[]as$t)$types[(int)$t['type']===2?0:((int)$t['type']===1?1:2)]++;
        $charts['tickets_types']=self::counts('tickets_types','Chamados selecionados por tipo',['Requisições','Incidentes','Outros'],$types);
        $charts['tickets_types']['kind']='pie';$charts['tickets_types']['point_colors']=[ReportStyle::BLUE,ReportStyle::YELLOW,ReportStyle::LIGHT_GRAY];
        $charts['tickets_daily']=self::daily($snapshot,false);$charts['calls_daily']=self::daily($snapshot,true);
        return$charts;
    }
    public static function ticketSurveyDistribution(array $snapshot):array
    {
        $counts=array_fill(0,7,0);foreach($snapshot['tickets']??[]as$t){$r=$t['satisfaction']??null;
            if($r===null||$r==='')$counts[5]++;elseif(!empty($t['satisfaction_valid']))$counts[(int)$r-1]++;else$counts[6]++;}
        $labels=['1','2','3','4','5','Sem resposta','Fora do corte / inválida'];$rows=[];foreach($labels as$i=>$label)$rows[]=[$label,$counts[$i]];
        return['rows'=>$rows,'total'=>array_sum($counts),'available'=>true];
    }
    private static function counts(string $id,string $title,array $categories,array $values):array
    {return['id'=>$id,'title'=>$title,'categories'=>$categories,'series'=>[['name'=>'Quantidade','values'=>$values,'color'=>ReportStyle::BLUE]],'kind'=>'column','stacked'=>false,'unit'=>'count','available'=>array_sum($values)>0,'note'=>''];}
    private static function daily(array $snapshot,bool $phone):array
    {
        $month=$snapshot['period']['month']??'';$days=HeatmapData::daysInMonth($month);$counts=array_fill(0,$days,0);$labels=[];
        for($i=1;$i<=$days;$i++)$labels[]=sprintf('%02d',$i);
        $dateKey=$phone?'entered_at':(($snapshot['input']['scope_basis']??'closed')==='solved'?'solved_at':'closed_at');
        foreach($snapshot[$phone?'calls':'tickets']??[]as$row){$parts=HeatmapData::timestampParts($row[$dateKey]??null,$month);if($parts)$counts[$parts['day']-1]++;}
        return self::counts($phone?'calls_daily':'tickets_daily',$phone?'Ligações por dia de entrada':'Chamados por dia de '.($dateKey==='solved_at'?'solução':'fechamento'),$labels,$counts);
    }
}
