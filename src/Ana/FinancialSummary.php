<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** ANA points: 1 point = 0.1% of the approved monthly base; IPS + TRS capped at 30%. */
final class FinancialSummary
{
    public static function build(array $snapshot): array
    {
        $input = $snapshot['input'] ?? [];
        $month = (string)($snapshot['period']['month'] ?? $input['month'] ?? '');
        FinanceSettings::month($month);
        $config = FinanceSettings::normalize($input['financial_config'] ?? []);
        $baseline = $config['baseline'];
        $monthly = $config['months'][$month] ?? [];
        $notes = [];
        $pendingIndicators = [];
        $complete = true;
        $monthStart = $month . '-01';
        $monthEnd = (new \DateTimeImmutable($monthStart, new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-t');
        $baselineCoversMonth = $baseline['effective_from'] !== '' && $baseline['effective_to'] !== ''
            && $baseline['effective_from'] <= $monthStart && $baseline['effective_to'] >= $monthEnd;
        $gross = $monthly['gross_value'] ?? ($baselineCoversMonth ? $baseline['monthly_value'] : null);
        $orderReference = $monthly['order_reference'] ?? '';
        if ($orderReference === '' && $baselineCoversMonth) $orderReference = $baseline['order_reference'];
        $available = $gross !== null && $orderReference !== '';
        $grossCents = $available ? FinanceSettings::cents($gross) : null;
        if (!$available) {
            $complete = false;
            $notes[] = 'OS, vigência ou valor mensal da competência não definidos.';
        }
        if (!($monthly['confirmed'] ?? false) || ($monthly['approval_reference'] ?? '') === '') {
            $complete = false;
            $notes[] = 'Aprovação financeira da competência não registrada.';
        }
        $serviceSum = array_sum(array_map(static function (array $row): int { return FinanceSettings::cents($row['amount']); }, $baseline['services']));
        if ($serviceSum !== FinanceSettings::cents($baseline['monthly_value'])) {
            $notes[] = 'Soma dos serviços diferente do valor total da OS.';
        }
        $metrics = [];
        foreach ($snapshot['metrics'] ?? [] as $metric) if (is_array($metric) && isset($metric['id'])) $metrics[(string)$metric['id']] = $metric;
        $decisions = [];
        foreach ($monthly['indicator_decisions'] ?? [] as $decision) $decisions[$decision['id']] = $decision;
        // The saved IPS9 scope decision already supplies a dated/report-month basis.
        // Do not require the administrator to enter the same approval twice.
        $external = $input['external_evidence'] ?? $snapshot['external_evidence'] ?? [];
        if (!isset($decisions['IPS9']) && ($external['controls_applicable'] ?? null) === false
            && trim((string)($external['controls_scope_reference'] ?? '')) !== '') {
            $decisions['IPS9'] = ['id' => 'IPS9', 'applicable' => false, 'points' => null,
                'reference' => trim((string)$external['controls_scope_reference'])];
        }
        $items = [];
        $knownPoints = 0.0;
        $allPointsKnown = true;
        for ($number = 1; $number <= 10; $number++) {
            $id = 'IPS' . $number;
            $parent = $metrics[$id] ?? ['id' => $id, 'label' => $id, 'status' => 'pending'];
            $assessments = [$parent];
            if ($number === 3 || $number === 4) {
                $children = [];
                foreach ($parent['children'] ?? [] as $child) if (is_array($child) && isset($child['id'])) $children[$child['id']] = $child;
                $assessments = [];
                for ($priority = 1; $priority <= 3; $priority++) {
                    $childId = $id . '_P' . $priority;
                    $assessments[] = $children[$childId] ?? ['id' => $childId, 'label' => $id . ' / P' . $priority, 'status' => 'pending'];
                }
            }
            foreach ($assessments as $metric) {
                // Unknown classification belongs to the whole priority population. It
                // must not disappear merely because each known child cohort is valid.
                if (($number === 3 || $number === 4)
                    && (!empty($parent['unresolved_ids']) || !($parent['evidence_complete'] ?? false)
                        || in_array($parent['status'] ?? 'pending', ['pending', 'provisional'], true))) {
                    $metric['evidence_complete'] = false;
                    $metric['status'] = 'provisional';
                }
                $metricId = (string)$metric['id'];
                $decision = $decisions[$metricId] ?? $decisions[$id] ?? [];
                $item = self::assess($id, $metric, $decision, $monthly);
                $item['amount'] = null;
                $item['amount_before_cap'] = null;
                if ($item['points'] === null) {
                    $allPointsKnown = false;
                    $complete = false;
                    $pendingIndicators[$item['note']][] = $metricId;
                } else {
                    $knownPoints += $item['points'];
                    if ($grossCents !== null) {
                        $amount = self::roundCents($grossCents * $item['points'] / 1000, $monthly);
                        $item['amount'] = $amount === null ? null : FinanceSettings::amount($amount);
                        $item['amount_before_cap'] = $item['amount'];
                    }
                }
                $items[] = $item;
            }
        }
        foreach ($pendingIndicators as $reason => $ids) $notes[] = implode(', ', $ids) . ': ' . $reason;
        $trsItems = [];
        foreach ($monthly['trs'] ?? [] as $index => $row) {
            $approved = $row['approved'] && $row['reference'] !== '' && $row['description'] !== '';
            $points = $approved ? $row['points'] * $row['quantity'] : null;
            $item = ['metric_id' => 'TRS' . $row['code'] . '_' . ($index + 1),
                'label' => 'TRS ' . $row['code'] . ' — ' . $row['description'],
                'value' => $row['quantity'], 'unit' => 'occurrences', 'target' => null,
                'deficit' => null, 'step' => null, 'points' => $points,
                'amount' => null, 'amount_before_cap' => null,
                'status' => $approved ? 'assessed' : 'pending', 'reference' => $row['reference'],
                'note' => $approved ? 'Ocorrência e pontos aprovados.' : 'Ocorrência TRS aguarda descrição e aprovação referenciada.'];
            if (!$approved) {
                $allPointsKnown = false;
                $complete = false;
                $notes[] = $item['label'] . ': ' . $item['note'];
            } else {
                $knownPoints += $points;
                if ($grossCents !== null) {
                    $amount = self::roundCents($grossCents * $points / 1000, $monthly);
                    $item['amount'] = $amount === null ? null : FinanceSettings::amount($amount);
                    $item['amount_before_cap'] = $item['amount'];
                }
            }
            $items[] = $item;
            $trsItems[] = $item;
        }
        $calculatedRate = $allPointsKnown ? $knownPoints * 0.1 : null;
        $appliedRate = null;
        if ($calculatedRate !== null) {
            $pao = $monthly['pao_applicable'] ?? null;
            if ($calculatedRate == 0.0) {
                $appliedRate = 0.0;
            } elseif ($pao === null) {
                $complete = false;
                $notes[] = 'Aplicabilidade do PAO não definida para a competência.';
            } elseif ($pao === false) {
                $appliedRate = min(30.0, $calculatedRate);
            } else {
                $from = $monthly['pao_from'] ?? '';
                $to = $monthly['pao_to'] ?? '';
                $order = $monthly['cap_pao_order'] ?? 'pending';
                if (($monthly['pao_reference'] ?? '') === '' || $from === '' || $to === '' || $from > $monthEnd || $to < $monthStart) {
                    $complete = false;
                    $notes[] = 'Vigência ou aprovação do PAO não registrada para a competência.';
                } elseif ($order === 'pending' || ($monthly['cap_pao_reference'] ?? '') === '') {
                    $complete = false;
                    $notes[] = 'Ordem de aplicação do limite e do redutor PAO não definida.';
                } else {
                    $appliedRate = $order === 'cap_then_pao' ? min(30.0, $calculatedRate) * 0.7 : min(30.0, $calculatedRate * 0.7);
                }
            }
        }
        $adjustmentCents = 0;
        foreach ($monthly['adjustments'] ?? [] as $row) {
            $adjustmentCents += FinanceSettings::cents($row['amount']);
            if ($row['reference'] === '' || $row['description'] === '') {
                $complete = false;
                $notes[] = 'Ajuste financeiro sem descrição ou referência de aprovação.';
            }
        }
        $deductionCents = null;
        if ($grossCents !== null && $appliedRate !== null) {
            $deductionCents = self::roundCents($grossCents * $appliedRate / 100, $monthly);
            if ($deductionCents === null) {
                $complete = false;
                $notes[] = 'Regra de arredondamento monetário não definida para a glosa fracionária.';
            }
        }
        if (!$complete) $deductionCents = null;
        return [
            'available' => $available, 'complete' => $complete, 'month' => $month,
            'order_reference' => $orderReference, 'approval_reference' => $monthly['approval_reference'] ?? '',
            'contract_services' => $baseline['services'], 'contract_value_total' => $baseline['monthly_value'],
            'baseline_covers_month' => $baselineCoversMonth, 'baseline_effective_from' => $baseline['effective_from'],
            'baseline_effective_to' => $baseline['effective_to'],
            'gross_value' => $grossCents === null ? null : FinanceSettings::amount($grossCents), 'gross_cents' => $grossCents,
            'point_items' => $items, 'trs_items' => $trsItems,
            'points_total' => $allPointsKnown ? $knownPoints : null, 'known_points_subtotal' => $knownPoints,
            'calculated_rate_total' => $calculatedRate, 'applied_rate_total' => $appliedRate,
            'deduction_total' => $deductionCents === null ? null : FinanceSettings::amount($deductionCents),
            'deduction_cents' => $deductionCents, 'adjustments' => $monthly['adjustments'] ?? [],
            'adjustment_total' => FinanceSettings::amount($adjustmentCents),
            'net_value' => $deductionCents === null || $grossCents === null ? null : FinanceSettings::amount($grossCents - $deductionCents + $adjustmentCents),
            'pao_applicable' => $monthly['pao_applicable'] ?? null,
            'point_rounding' => $monthly['point_rounding'] ?? 'pending',
            'money_rounding' => $monthly['money_rounding'] ?? 'pending',
            'cap_pao_order' => $monthly['cap_pao_order'] ?? 'pending',
            'notes' => array_values(array_unique($notes)),
        ];
    }

    private static function assess(string $parentId, array $metric, array $decision, array $monthly): array
    {
        $policies = [
            'IPS1' => [4.0, 0.1, 2.0, 'score', 'mean_score'],
            'IPS2' => [98.0, 1.0, 5.0, 'percent', 'percent_ratio'],
            'IPS3' => [90.0, 1.0, 5.0, 'percent', 'percent_ratio'],
            'IPS4' => [90.0, 1.0, 5.0, 'percent', 'percent_ratio'],
            'IPS5' => [98.0, 1.0, 5.0, 'percent', 'percent_ratio'],
            'IPS6' => [99.0, 0.1, 10.0, 'percent', 'percent_ratio'],
            'IPS7' => [99.9, 0.1, 10.0, 'percent', 'percent_ratio'],
            'IPS8' => [99.7, 0.1, 10.0, 'percent', 'percent_ratio'],
            'IPS9' => [null, null, null, 'compliance', 'compliance'],
            'IPS10' => [99.5, 0.1, 10.0, 'percent', 'percent_ratio'],
        ];
        [$target, $step, $pointsPerStep, $unit, $kind] = $policies[$parentId];
        $value = $metric['value'] ?? null;
        $status = (string)($metric['status'] ?? 'pending');
        $item = ['metric_id' => (string)$metric['id'], 'label' => (string)($metric['label'] ?? $metric['id']),
            'value' => $value, 'unit' => $unit, 'target' => $target, 'deficit' => null,
            'step' => $step, 'points_per_step' => $pointsPerStep, 'points' => null,
            'status' => $status, 'reference' => $decision['reference'] ?? '', 'note' => 'Resultado não apurado ou sem evidência suficiente.'];
        if (($decision['applicable'] ?? null) === false && ($decision['reference'] ?? '') !== '') {
            $item['points'] = 0.0;
            $item['status'] = 'not_applicable';
            $item['note'] = 'Não aplicável conforme referência registrada.';
            return $item;
        }
        if ($status === 'no_occurrences' && ($metric['evidence_complete'] ?? false)) {
            $item['points'] = 0.0;
            $item['note'] = 'Sem ocorrências.';
            return $item;
        }
        if (!in_array($status, ['met', 'unmet'], true) || !($metric['evidence_complete'] ?? false)) {
            $item['note'] = $status === 'not_applicable' ? 'Não aplicabilidade requer decisão financeira com referência.'
                : ($status === 'provisional' ? 'Apuração provisória.' : $item['note']);
            return $item;
        }
        if (($metric['value_kind'] ?? '') !== $kind) {
            $item['status'] = 'pending';
            $item['note'] = 'Tipo de medição incompatível com o indicador.';
            return $item;
        }
        if ($parentId === 'IPS9') {
            if ($status === 'met') {
                $item['points'] = 0.0;
                $item['note'] = 'Escopo de controles comprovadamente atendido.';
            } elseif (array_key_exists('points', $decision) && $decision['points'] !== null && ($decision['reference'] ?? '') !== '') {
                $item['points'] = (float)$decision['points'];
                $item['note'] = 'Pontos do cronograma de implementação acordado, com referência de aprovação.';
            } else {
                $item['note'] = 'Pontuação do IPS9 sem definição ou aprovação registrada.';
                $item['status'] = 'pending';
            }
            return $item;
        }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > ($unit === 'score' ? 5 : 100)) {
            $item['status'] = 'pending';
            $item['note'] = 'Valor do indicador inválido.';
            return $item;
        }
        // The typed metric's value is authoritative. A mean score stays a mean score.
        $deficit = max(0.0, $target - (float)$value);
        $item['deficit'] = $deficit;
        $steps = $deficit / $step;
        $nearest = round($steps);
        if (abs($steps - $nearest) < 0.00000001) {
            $steps = $nearest;
        } else {
            $rounding = $monthly['point_rounding'] ?? 'pending';
            if ($rounding === 'pending' || ($monthly['rounding_reference'] ?? '') === '') {
                $item['status'] = 'pending';
                $item['note'] = 'Regra para frações de pontos não definida.';
                return $item;
            }
            if ($rounding === 'floor') $steps = floor($steps);
            elseif ($rounding === 'ceil') $steps = ceil($steps);
        }
        $item['points'] = $steps * $pointsPerStep;
        $item['note'] = $deficit == 0.0 ? 'Meta atendida.' : 'Pontos conforme déficit e regra de fração registrada.';
        return $item;
    }

    private static function roundCents(float $cents, array $monthly): ?int
    {
        if (!is_finite($cents) || $cents < 0 || $cents > PHP_INT_MAX) return null;
        $nearest = round($cents);
        if (abs($cents - $nearest) < 0.0000001) return (int)$nearest;
        $mode = $monthly['money_rounding'] ?? 'pending';
        if ($mode === 'pending' || ($monthly['money_rounding_reference'] ?? '') === '') return null;
        if ($mode === 'floor') return (int)floor($cents);
        if ($mode === 'ceil') return (int)ceil($cents);
        return (int)round($cents, 0, PHP_ROUND_HALF_UP);
    }
}
