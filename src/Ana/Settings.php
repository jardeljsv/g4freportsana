<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Validated input and durable, administrator-owned ANA methodology. */
final class Settings
{
    public const RULES_VERSION = 'ana-2026-11-16-v4';
    public const METHOD_CONFIG_KEY = 'ana_methodology_json';

    public static function defaults(): array
    {
        return ['ana_test_numbers_json' => '[]', self::METHOD_CONFIG_KEY => '{}', FinanceSettings::CONFIG_KEY => '{}', EvidenceSettings::CONFIG_KEY => '{}'];
    }

    public static function methodologyDefaults(): array
    {
        return ['entity_id'=>0, 'n1_group_ids'=>[4], 'n2_group_ids'=>[1], 'n3_group_ids'=>range(22,30),
            'scope_basis'=>'closed', 'responsibility_basis'=>'solution', 'clock_mode'=>'native_reference',
            'tms_endpoint'=>'closed', 'calendar_mode'=>'onsite', 'survey_cutoff'=>'period_end',
            'reopening_mode'=>'solved_or_closed', 'call_unit'=>'interaction', 'ips6_include_desistencias'=>true,
            'methodology_confirmed'=>false, 'methodology_reference'=>'', 'priority_value_map'=>[],
            'approved_pauses'=>[], 'priority_overrides'=>[], 'scope_overrides'=>[], 'vip_ticket_ids'=>[], 'contractual_holidays'=>[], 'holiday_reference'=>''];
    }

    public static function policyChoices(): array
    {
        return ['scope_basis'=>['closed','solved','reference'], 'responsibility_basis'=>['solution','closure','current'],
            'clock_mode'=>['native_reference','contractual'], 'tms_endpoint'=>['closed','solved'],
            'calendar_mode'=>['onsite','elapsed'], 'survey_cutoff'=>['period_end','extraction'],
            'reopening_mode'=>['solved_or_closed','closed_only'], 'call_unit'=>['interaction','queue_leg']];
    }

    public static function methodology($raw): array
    {
        $data = self::object($raw, 'Metodologia');
        $out = self::methodologyDefaults();
        foreach (self::policyChoices() as $key=>$choices) $out[$key] = self::choice($data[$key] ?? $out[$key], $choices, $key);
        $out['ips6_include_desistencias'] = self::boolean($data['ips6_include_desistencias'] ?? true, 'Desistências no IPS6');
        $out['entity_id'] = self::integer($data['entity_id'] ?? 0, 'Entidade', 0, 2147483647);
        foreach (['n1_group_ids','n2_group_ids','n3_group_ids'] as $key) $out[$key] = self::ids($data[$key] ?? $out[$key], $key);
        self::validateGroups($out);
        $out['methodology_confirmed'] = self::boolean($data['methodology_confirmed'] ?? false, 'Confirmação metodológica');
        $out['methodology_reference'] = self::text($data['methodology_reference'] ?? '', 'Referência da metodologia', 2000);
        if ($out['methodology_confirmed'] && $out['methodology_reference'] === '') throw new \InvalidArgumentException('A confirmação metodológica exige uma referência de aprovação.');
        $map = $data['priority_value_map'] ?? [];
        if (!is_array($map) || count($map)>6) throw new \InvalidArgumentException('Mapeamento de prioridades inválido.');
        foreach ($map as $rawPriority=>$priority) {
            $key=self::integer((string)$rawPriority,'Prioridade GLPI',1,6);
            $out['priority_value_map'][$key]=self::choice($priority,['P1','P2','P3'],'Prioridade contratual');
        }
        foreach (['approved_pauses','priority_overrides','scope_overrides'] as $key) {
            $rows=$data[$key] ?? [];
            if (!is_array($rows) || count($rows)>1000 || ($rows && array_keys($rows)!==range(0,count($rows)-1))) throw new \InvalidArgumentException($key.': informe uma lista de até 1.000 registros.');
            $seen=[];
            foreach ($rows as $row) {
                if (!is_array($row)) throw new \InvalidArgumentException('Registro de metodologia inválido.');
                $ticket=self::integer($row['ticket_id'] ?? null,'Chamado',1,2147483647);
                $next=['ticket_id'=>$ticket];
                if ($key==='approved_pauses') {
                    foreach (['start','end'] as $time) {
                        $value=self::text($row[$time] ?? '', 'Data/hora da suspensão', 40);
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) throw new \InvalidArgumentException('A suspensão exige data/hora ISO com fuso, por exemplo 2026-09-01T08:00:00-03:00.');
                        try { $dt=new \DateTimeImmutable($value); $errors=\DateTimeImmutable::getLastErrors(); if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) throw new \InvalidArgumentException('Data inválida.'); } catch (\Throwable $e) { throw new \InvalidArgumentException('Data de suspensão inválida.'); }
                        $next[$time]=$dt->format(DATE_ATOM); $next[$time.'_epoch']=$dt->getTimestamp();
                    }
                    if ($next['end_epoch']<=$next['start_epoch']) throw new \InvalidArgumentException('O fim da suspensão deve ser posterior ao início.');
                    $next['approval_reference']=self::text($row['approval_reference'] ?? '', 'Aprovação da suspensão',2000);
                    if ($next['approval_reference']==='') throw new \InvalidArgumentException('Toda suspensão exige referência da aprovação da fiscalização.');
                } else {
                    if (isset($seen[$ticket])) throw new \InvalidArgumentException('Existe mais de uma decisão para o mesmo chamado.');
                    $seen[$ticket]=true;
                    $next['reason']=self::text($row['reason'] ?? '', 'Justificativa da decisão',2000);
                    if ($next['reason']==='') throw new \InvalidArgumentException('Toda decisão por chamado exige justificativa e referência.');
                    if ($key==='priority_overrides') $next['priority']=self::choice($row['priority'] ?? '', ['P1','P2','P3'],'Prioridade contratual');
                    else $next['include']=self::boolean($row['include'] ?? null, 'Inclusão no escopo');
                }
                $out[$key][]=$next;
            }
        }
        $out['vip_ticket_ids']=self::ids($data['vip_ticket_ids'] ?? [], 'Chamados VIP documentados');
        $holidays=$data['contractual_holidays'] ?? [];
        if (is_string($holidays)) $holidays=preg_split('/[\s,;]+/',trim($holidays),-1,PREG_SPLIT_NO_EMPTY);
        if (!is_array($holidays) || count($holidays)>500) throw new \InvalidArgumentException('Lista de feriados inválida.');
        foreach ($holidays as $date) {
            if (!is_string($date) || !preg_match('/^(20\d{2}|2100)-(\d{2})-(\d{2})$/D',$date,$match) || !checkdate((int)$match[2],(int)$match[3],(int)$match[1])) throw new \InvalidArgumentException('Informe feriados válidos no formato AAAA-MM-DD.');
            $out['contractual_holidays'][]=$date;
        }
        $out['contractual_holidays']=array_values(array_unique($out['contractual_holidays']));
        sort($out['contractual_holidays']);
        $out['holiday_reference']=self::text($data['holiday_reference'] ?? '', 'Referência dos feriados',2000);
        if ($out['contractual_holidays'] && $out['holiday_reference']==='') throw new \InvalidArgumentException('A lista de feriados exige referência documental.');
        if (strlen(self::json($out))>60000) throw new \InvalidArgumentException('A metodologia normalizada excede 60.000 bytes. Reduza os registros e preserve as referências essenciais.');
        return $out;
    }

    public static function methodologyFromSettings(array $settings): array { return self::methodology($settings[self::METHOD_CONFIG_KEY] ?? '{}'); }
    public static function methodologyJson(array $data): string { return self::json(self::methodology($data)); }
    public static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function revision(array $data): string { return hash('sha256', self::json($data)); }

    public static function normalizeNarrative(array $input): array
    {
        $result=[];
        foreach (['report_title'=>180,'executive_summary'=>20000,'actions'=>20000,'notes'=>20000,'phone_survey_actions'=>20000,
            'availability_notes'=>20000,'siem_notes'=>20000,'rdm_notes'=>20000,'vip_notes'=>20000,'trs_notes'=>20000,'improvement_notes'=>20000] as $field=>$limit) {
            if (array_key_exists($field,$input)) $result[$field]=self::text($input[$field],$field,$limit);
        }
        return $result;
    }

    public static function normalizeInput(array $input, array $settings): array
    {
        $month=self::text($input['month'] ?? '', 'Mês',7);
        if (!preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D',$month)) throw new \InvalidArgumentException('Informe um mês no formato AAAA-MM, entre 2000 e 2100.');
        $start=new \DateTimeImmutable($month.'-01 00:00:00',new \DateTimeZone('America/Sao_Paulo'));
        $saved=self::methodologyFromSettings($settings);
        $out=$saved;
        foreach (self::policyChoices() as $key=>$choices) $out[$key]=self::choice($input[$key] ?? $saved[$key],$choices,$key);
        // Contract boundaries are durable administrator settings, not request filters.
        $out['entity_id']=$saved['entity_id'];
        foreach (['n1_group_ids','n2_group_ids','n3_group_ids'] as $key) $out[$key]=$saved[$key];
        $out['callcenter_timezone']=self::text($settings['callcenter_db_timezone'] ?? 'America/Sao_Paulo','Fuso da telefonia',64);
        if ($out['callcenter_timezone']==='') $out['callcenter_timezone']='America/Sao_Paulo';
        foreach (array_merge(array_keys(self::policyChoices()),['entity_id','n1_group_ids','n2_group_ids','n3_group_ids']) as $key) {
            if ($out[$key]!==$saved[$key]) $out['methodology_confirmed']=false;
        }
        $out['month']=$month; $out['timezone']='America/Sao_Paulo';
        $out['start']=$out['period_start']=$start->format('Y-m-d H:i:s');
        $out['end_exclusive']=$out['period_end_exclusive']=$start->modify('+1 month')->format('Y-m-d H:i:s');
        $out['start_epoch']=$start->getTimestamp(); $out['end_epoch']=$start->modify('+1 month')->getTimestamp();
        // The two source queues are exact contract identifiers, never a substring filter.
        $out['queue_names']=['ana','ana-transbordo'];
        $out['test_numbers']=self::testNumbers($settings['ana_test_numbers_json'] ?? '[]');
        $out['financial_config']=FinanceSettings::fromSettings($settings);
        $out['external_evidence']=EvidenceSettings::forMonth($settings,$month);
        $out['methodology_revision']=self::revision($saved);
        $out=array_merge($out,self::normalizeNarrative(array_merge(['report_title'=>'Relatório Mensal de Serviços — ANA',
            'executive_summary'=>'','actions'=>'','notes'=>'','phone_survey_actions'=>'','availability_notes'=>'','siem_notes'=>'','rdm_notes'=>'','vip_notes'=>'','trs_notes'=>'','improvement_notes'=>''],$input)));
        return $out;
    }

    public static function prepareUpdate(array $input): array
    {
        if (array_key_exists('ana_test_numbers_json',$input)) $input['ana_test_numbers_json']=self::json(self::testNumbers($input['ana_test_numbers_json']));
        if (array_key_exists(self::METHOD_CONFIG_KEY,$input)) $input[self::METHOD_CONFIG_KEY]=self::methodologyJson(self::methodology($input[self::METHOD_CONFIG_KEY]));
        if (array_key_exists(FinanceSettings::CONFIG_KEY,$input)) $input[FinanceSettings::CONFIG_KEY]=FinanceSettings::json(FinanceSettings::normalize($input[FinanceSettings::CONFIG_KEY]));
        if (array_key_exists(EvidenceSettings::CONFIG_KEY,$input)) $input[EvidenceSettings::CONFIG_KEY]=EvidenceSettings::json(EvidenceSettings::normalize($input[EvidenceSettings::CONFIG_KEY]));
        return $input;
    }

    private static function validateGroups(array $out): void
    {
        foreach (['n1_group_ids','n2_group_ids','n3_group_ids'] as $key) if (!$out[$key]) throw new \InvalidArgumentException('Informe os grupos responsáveis de cada nível N1, N2 e N3.');
        if (array_intersect($out['n1_group_ids'],$out['n2_group_ids']) || array_intersect($out['n1_group_ids'],$out['n3_group_ids']) || array_intersect($out['n2_group_ids'],$out['n3_group_ids'])) throw new \InvalidArgumentException('Um grupo não pode pertencer a dois níveis de atendimento.');
    }
    private static function boolean($value,string $label): bool
    {
        if (!in_array($value,[true,false,1,0,'1','0'],true)) throw new \InvalidArgumentException($label.': situação inválida.');
        return in_array($value,[true,1,'1'],true);
    }
    private static function object($raw,string $label): array
    {
        if (is_string($raw)) {
            if (strlen($raw)>60000 || substr(ltrim($raw),0,1)!=='{') throw new \InvalidArgumentException($label.': objeto JSON inválido ou muito extenso.');
            $raw=json_decode($raw,true);
            if (json_last_error()!==JSON_ERROR_NONE) throw new \InvalidArgumentException($label.': JSON inválido.');
        }
        if (!is_array($raw)) throw new \InvalidArgumentException($label.': formato inválido.');
        return $raw;
    }

    public static function testNumbers($raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > 150000) {
                throw new \InvalidArgumentException('A lista de números de teste é muito extensa.');
            }
            if (substr(ltrim($raw), 0, 1) !== '[') {
                throw new \InvalidArgumentException('A lista de números de teste deve ser um array JSON, inclusive quando vazia ([]).');
            }
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('A lista de números de teste deve ser um JSON válido.');
            }
        }
        if (!is_array($raw) || ($raw && array_keys($raw) !== range(0, count($raw) - 1)) || count($raw) > 500) {
            throw new \InvalidArgumentException('Informe uma lista com no máximo 500 números de teste.');
        }
        $result = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Cada número de teste deve ter número, descrição e situação.');
            }
            $number = self::text($row['number'] ?? '', 'Número de teste', 80);
            if ($number === '' || preg_match('/[^0-9+().\-\s]/', $number)) {
                throw new \InvalidArgumentException('O número de teste deve conter dígitos e somente caracteres usuais de formatação.');
            }
            $number = self::callerDigits($number);
            if ($number === '' || strlen($number) > 32) {
                throw new \InvalidArgumentException('Informe um número de teste com 1 a 32 dígitos.');
            }
            if (isset($seen[$number])) {
                throw new \InvalidArgumentException('Existem números de teste duplicados após remover a formatação.');
            }
            $active = array_key_exists('active', $row) ? $row['active'] : true;
            if (!in_array($active, [true, false, 1, 0, '1', '0'], true)) {
                throw new \InvalidArgumentException('A situação do número de teste deve ser ativa ou inativa.');
            }
            $seen[$number] = true;
            $result[] = ['number' => $number, 'label' => self::text($row['label'] ?? '', 'Descrição do número', 240),
                'active' => in_array($active, [true, 1, '1'], true)];
        }
        return $result;
    }

    public static function callerDigits($value): string
    {
        return preg_replace('/[^0-9]/', '', (string)$value);
    }

    private static function ids($value, string $label): array
    {
        if (is_string($value)) {
            if (strlen($value) > 50000) {
                throw new \InvalidArgumentException($label . ': lista muito extensa.');
            }
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            $value = preg_split('/[\s,;]+/', $value);
        }
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1)) || count($value) > 5000) {
            throw new \InvalidArgumentException($label . ': informe uma lista de IDs numéricos.');
        }
        $ids = [];
        foreach ($value as $id) {
            $parsed = self::integer($id, $label, 1, 2147483647);
            $ids[$parsed] = $parsed;
        }
        return array_values($ids);
    }

    private static function integer($value, string $label, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string)$value)
            || (float)$value < $min || (float)$value > $max) {
            throw new \InvalidArgumentException($label . ': informe um número inteiro entre ' . $min . ' e ' . $max . '.');
        }
        return (int)$value;
    }

    private static function optionalInteger($value, string $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::integer($value, $label, 0, 1000000);
    }

    private static function text($value, string $label, int $limit): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException($label . ': valor inválido.');
        }
        $value = trim((string)$value);
        if (strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
            || !preg_match('//u', $value)) {
            throw new \InvalidArgumentException($label . ': texto inválido ou maior que o limite de ' . $limit . ' bytes.');
        }
        return $value;
    }

    private static function choice($value, array $choices, string $label): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new \InvalidArgumentException($label . ': opção inválida.');
        }
        return $value;
    }
}
