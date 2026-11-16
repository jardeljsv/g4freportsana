<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Monthly non-GLPI evidence. Missing coverage remains missing, never assumed 100%. */
final class EvidenceSettings
{
    public const CONFIG_KEY = 'ana_evidence_json';

    public static function fromSettings(array $settings): array
    {
        return self::normalize($settings[self::CONFIG_KEY] ?? []);
    }

    public static function normalize($raw): array
    {
        $raw = FinanceSettings::object($raw, 'Evidências externas');
        $months = $raw['months'] ?? [];
        if (!is_array($months) || count($months) > 240) throw new \InvalidArgumentException('Competências de evidência inválidas.');
        $result = ['months' => []];
        foreach ($months as $month => $row) {
            FinanceSettings::month((string)$month);
            $monthStart = new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone('America/Sao_Paulo'));
            $monthSeconds = $monthStart->modify('+1 month')->getTimestamp() - $monthStart->getTimestamp();
            if (!is_array($row)) throw new \InvalidArgumentException('Evidências da competência inválidas.');
            $entry = self::emptyMonth();
            $entry['controls_applicable'] = FinanceSettings::boolean($row['controls_applicable'] ?? null, true);
            $entry['controls_scope_reference'] = FinanceSettings::text($row['controls_scope_reference'] ?? '', 2000);
            $serviceKeys = [];
            foreach (FinanceSettings::rows($row['services'] ?? [], 500, 'Serviços monitorados') as $service) {
                $item = [
                    'metric_id' => FinanceSettings::choice($service['metric_id'] ?? '', ['IPS7', 'IPS8', 'IPS10']),
                    'service' => FinanceSettings::text($service['service'] ?? '', 300),
                    'window_seconds' => FinanceSettings::number($service['window_seconds'] ?? 0),
                    'downtime_seconds' => FinanceSettings::number($service['downtime_seconds'] ?? 0),
                    'excluded_seconds' => FinanceSettings::number($service['excluded_seconds'] ?? 0),
                    'evidence_reference' => FinanceSettings::text($service['evidence_reference'] ?? '', 2000),
                    'approval_reference' => FinanceSettings::text($service['approval_reference'] ?? '', 2000),
                    'valid' => true, 'validation_errors' => [],
                ];
                $key = $item['metric_id'] . ':' . mb_strtolower($item['service'], 'UTF-8');
                if (isset($serviceKeys[$key])) throw new \InvalidArgumentException('Serviço repetido no mesmo indicador: ' . $item['service']);
                $serviceKeys[$key] = true;
                if ($item['service'] === '') $item['validation_errors'][] = 'Identificação do serviço ausente.';
                if ($item['window_seconds'] <= 0) $item['validation_errors'][] = 'Janela de medição deve ser positiva.';
                if ($item['window_seconds'] > $monthSeconds) $item['validation_errors'][] = 'Janela excede a duração real da competência (' . $monthSeconds . ' segundos).';
                if ($item['excluded_seconds'] >= $item['window_seconds']) $item['validation_errors'][] = 'Exclusões devem ser inferiores à janela de medição.';
                if ($item['downtime_seconds'] > $item['window_seconds'] - $item['excluded_seconds']) $item['validation_errors'][] = 'Indisponibilidade excede a janela mensurável após exclusões.';
                if ($item['excluded_seconds'] > 0 && $item['approval_reference'] === '') $item['validation_errors'][] = 'Exclusão de janela sem referência de aprovação.';
                if ($item['evidence_reference'] === '') $item['validation_errors'][] = 'Referência de monitoramento ausente.';
                $item['valid'] = !$item['validation_errors'];
                foreach ($item['validation_errors'] as $error) $entry['validation_errors'][] = $item['metric_id'] . ' / ' . $item['service'] . ': ' . $error;
                $entry['services'][] = $item;
            }
            $controlIds = [];
            foreach (FinanceSettings::rows($row['controls'] ?? [], 500, 'Controles e práticas') as $control) {
                $id = FinanceSettings::text($control['id'] ?? '', 64);
                if ($id === '' || isset($controlIds[$id])) throw new \InvalidArgumentException('Identificador de controle obrigatório e único.');
                $controlIds[$id] = true;
                $item = ['id' => $id, 'description' => FinanceSettings::text($control['description'] ?? '', 2000),
                    'due_date' => FinanceSettings::date($control['due_date'] ?? ''),
                    'completion_percent' => self::optionalNumber($control['completion_percent'] ?? null, 100),
                    'target_percent' => self::optionalNumber($control['target_percent'] ?? null, 100),
                    'acceptance_reference' => FinanceSettings::text($control['acceptance_reference'] ?? '', 2000),
                    'valid' => true, 'validation_errors' => []];
                if ($item['description'] === '') $item['validation_errors'][] = 'Descrição do controle ausente.';
                if ($item['due_date'] === '') $item['validation_errors'][] = 'Prazo acordado ausente.';
                if ($item['completion_percent'] === null || $item['target_percent'] === null) $item['validation_errors'][] = 'Meta ou realização não informada.';
                if ($item['acceptance_reference'] === '') $item['validation_errors'][] = 'Referência de avaliação/aceite ausente.';
                $item['valid'] = !$item['validation_errors'];
                foreach ($item['validation_errors'] as $error) $entry['validation_errors'][] = 'IPS9 / ' . $id . ': ' . $error;
                $entry['controls'][] = $item;
            }
            if (($entry['controls_applicable'] !== null || $entry['controls']) && $entry['controls_scope_reference'] === '') {
                $entry['validation_errors'][] = 'IPS9: referência de escopo/aplicabilidade ausente.';
            }
            $phone = $row['phone'] ?? [];
            if (!is_array($phone)) throw new \InvalidArgumentException('Evidência de telefonia inválida.');
            $entry['phone'] = ['forwarded_calls' => self::optionalCount($phone['forwarded_calls'] ?? null),
                'answered_calls' => self::optionalCount($phone['answered_calls'] ?? null),
                'desisted_calls' => self::optionalCount($phone['desisted_calls'] ?? null),
                'evidence_reference' => FinanceSettings::text($phone['evidence_reference'] ?? '', 2000),
                'valid' => false, 'validation_errors' => []];
            $p = &$entry['phone'];
            if ($p['forwarded_calls'] !== null || $p['answered_calls'] !== null || $p['desisted_calls'] !== null || $p['evidence_reference'] !== '') {
                if ($p['forwarded_calls'] === null || $p['answered_calls'] === null) $p['validation_errors'][] = 'Informe encaminhadas e atendidas.';
                if ($p['forwarded_calls'] !== null && $p['answered_calls'] !== null && $p['answered_calls'] > $p['forwarded_calls']) $p['validation_errors'][] = 'Atendidas excedem encaminhadas.';
                if ($p['desisted_calls'] !== null && $p['forwarded_calls'] !== null && $p['answered_calls'] !== null
                    && $p['desisted_calls'] > $p['forwarded_calls'] - $p['answered_calls']) throw new \InvalidArgumentException('Desistências excedem as chamadas não atendidas.');
                if ($p['evidence_reference'] === '') $p['validation_errors'][] = 'Referência de reconciliação de telefonia ausente.';
                $p['valid'] = !$p['validation_errors'];
                foreach ($p['validation_errors'] as $error) $entry['validation_errors'][] = 'IPS6: ' . $error;
            }
            unset($p);
            $topics = $row['topics'] ?? [];
            if (!is_array($topics)) throw new \InvalidArgumentException('Tópicos do relatório inválidos.');
            foreach (array_keys($entry['topics']) as $topic) $entry['topics'][$topic] = FinanceSettings::text($topics[$topic] ?? '', 12000);
            $result['months'][$month] = $entry;
        }
        ksort($result['months']);
        if (strlen(self::json($result)) > 60000) throw new \InvalidArgumentException('Evidências excedem 60.000 bytes.');
        return $result;
    }

    public static function forMonth(array $settings, string $month): array
    {
        FinanceSettings::month($month);
        $config = self::fromSettings($settings);
        return $config['months'][$month] ?? self::emptyMonth();
    }
    public static function json(array $config): string
    {
        $config['months'] = (object)($config['months'] ?? []);
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function revision(array $config): string { return hash('sha256', self::json($config)); }

    private static function emptyMonth(): array
    {
        return ['services' => [], 'controls' => [], 'controls_applicable' => null,
            'controls_scope_reference' => '', 'phone' => ['forwarded_calls' => null, 'answered_calls' => null, 'desisted_calls' => null,
                'evidence_reference' => '', 'valid' => false, 'validation_errors' => []],
            'topics' => ['rdm' => '', 'vip' => '', 'trs' => '', 'improvements' => '', 'availability' => '', 'siem' => ''],
            'validation_errors' => []];
    }
    private static function optionalNumber($value, float $maximum): ?float
    {
        return $value === '' || $value === null ? null : FinanceSettings::number($value, $maximum);
    }
    private static function optionalCount($value): ?int
    {
        if ($value === '' || $value === null) return null;
        $number = FinanceSettings::number($value, 1000000000);
        if ($number !== floor($number)) throw new \InvalidArgumentException('Contagem de chamadas deve ser inteira.');
        return (int)$number;
    }
}
