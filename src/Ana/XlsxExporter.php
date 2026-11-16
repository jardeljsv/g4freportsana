<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Native OOXML audit workbook. Metric states and financial policy come from one snapshot. */
final class XlsxExporter
{
    private array $sheets = [];
    private array $snapshot = [];
    private array $chartData = [];
    private array $metricOperands = [];

    public function filename(array $snapshot): string
    {
        return 'Relatorio_Mensal_ANA_' . preg_replace('/[^0-9-]/', '', $snapshot['period']['month'] ?? '') . '.xlsx';
    }

    public function toBinary(array $snapshot): string
    {
        $this->snapshot = $snapshot;
        $this->sheets = [];
        $this->chartData = ChartData::build($snapshot);
        $operandSheet = $this->operands();
        $this->sheets[] = $this->overview();
        $this->sheets[] = $this->financial();
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            $this->sheets[] = $this->metric($metric);
            if (($metric['value_kind'] ?? '') === 'priority_percent_ratios') foreach ($metric['children'] ?? [] as $child) $this->sheets[] = $this->metric($child);
        }
        $this->sheets[] = $this->tickets();
        if (!empty($snapshot['scope_review'])) $this->sheets[] = $this->scope('Escopo em revisao', $snapshot['scope_review']);
        if (!empty($snapshot['excluded_tickets'])) $this->sheets[] = $this->scope('Chamados excluidos', $snapshot['excluded_tickets']);
        if ($this->phoneAvailable()) {
            $this->sheets[] = $this->calls('Ligacoes consideradas', $snapshot['calls'] ?? []);
            $this->sheets[] = $this->calls('Passagens por fila', $snapshot['call_legs'] ?? []);
            if (!empty($snapshot['excluded_calls'])) $this->sheets[] = $this->calls('Ligacoes excluidas', $snapshot['excluded_calls']);
            $this->sheets[] = $this->phoneEvents();
        }
        $this->sheets[] = $this->histories();
        if (!empty($snapshot['reopening_events'])) $this->sheets[] = $this->records('Reaberturas', $snapshot['reopening_events']);
        foreach ($this->solutionSheets() as $sheet) $this->sheets[] = $sheet;
        $this->sheets[] = $this->surveys();
        $this->sheets[] = $operandSheet;
        $this->sheets[] = $this->temporal();
        $external = $snapshot['external_evidence'] ?? $snapshot['input']['external_evidence'] ?? [];
        if (!empty($external['services'])) $this->sheets[] = $this->records('Disponibilidade', $external['services']);
        if (!empty($external['controls'])) $this->sheets[] = $this->records('Controles IPS9', $external['controls']);
        $this->sheets[] = $this->methodology();
        return $this->package();
    }

    private function title(array &$sheet, string $title, int $end = 7): void
    {
        $this->merged($sheet, 1, $title, 1, $end, 44);
        $this->merged($sheet, 2, 'G4F | ANA | Contrato 006/2025 | ' . ($this->snapshot['period']['label'] ?? $this->snapshot['period']['month'] ?? ''), 2, $end, 28);
    }

    private function overview(): array
    {
        $sheet = $this->sheet('Resumo', [15, 54, 21, 21, 28, 18, 18]);
        $this->title($sheet, 'Relatório mensal gerencial ANA');
        $this->headers($sheet, 6, ['IPS', 'Indicador', 'Resultado', 'Meta', 'Situação', 'Numerador', 'Base']);
        $row = 7;
        foreach (OfficePackage::metricRows($this->snapshot) as $metric) {
            $id = (string)$metric['id'];
            $this->put($sheet, $row, 1, $id, 4);
            $this->put($sheet, $row, 2, $metric['label'] ?? $id);
            [$value, $style] = $this->metricCell($metric);
            $this->put($sheet, $row, 3, $value, $style, "'" . $id . "'!B10");
            $this->put($sheet, $row, 4, OfficePackage::metricValue($metric, 'target'), 4);
            $this->put($sheet, $row, 5, OfficePackage::status($metric), $this->statusStyle($metric), "'" . $id . "'!B12");
            $this->put($sheet, $row, 6, $metric['numerator'] ?? null, 5, isset($metric['numerator']) ? "'" . $id . "'!B8" : null);
            $this->put($sheet, $row, 7, $metric['denominator'] ?? null, 5, isset($metric['denominator']) ? "'" . $id . "'!B9" : null);
            $sheet['links'][] = ['ref' => 'A' . $row, 'location' => "'" . $id . "'!A1"];
            $sheet['heights'][$row++] = 42;
        }
        $row += 2;
        $this->merged($sheet, $row++, 'Síntese operacional', 2);
        $totals = OfficePackage::phoneTotals($this->snapshot);
        $totalLabels = ['tickets' => 'Chamados incluídos', 'incidents' => 'Incidentes', 'requests' => 'Requisições', 'scope_review' => 'Escopo em revisão', 'calls_received' => 'Ligações recebidas', 'calls_answered' => 'Ligações atendidas', 'calls_abandoned' => 'Ligações abandonadas'];
        if (isset($totals['calls_desisted'])) $totalLabels['calls_desisted'] = 'Desistências (espera inferior a 30 segundos)';
        if (!empty($totals['calls_abandon_wait_unknown'])) $totalLabels['calls_abandon_wait_unknown'] = 'Abandonos sem tempo de espera apurado';
        foreach ($totalLabels as $key => $label) {
            $this->put($sheet, $row, 2, $label);
            $this->put($sheet, $row++, 3, $totals[$key] ?? 'Não apurado', 5);
        }
        if (!$this->phoneAvailable()) $this->merged($sheet, ++$row, 'Registros telefônicos indisponíveis nesta extração.', 9, 7, 30);
        foreach (['tickets_types', 'calls_daily', 'tickets_daily'] as $key) {
            if (!empty($this->chartData[$key]['available'])) {
                $row += 2;
                $this->attachChart($sheet, $this->chartData[$key], $row);
                $row += 21 + count($this->chartData[$key]['categories']);
            }
        }
        return $sheet;
    }

    private function financial(): array
    {
        $f = $this->snapshot['financial'] ?? [];
        $sheet = $this->sheet('Financeiro', [22, 53, 22, 21, 21, 22, 50]);
        $this->title($sheet, 'Composição financeira da medição');
        if (trim((string)($f['order_reference'] ?? '')) !== '') $this->merged($sheet, 4, 'Ordem de serviço: ' . $f['order_reference'], 9, 7, 42);
        $this->headers($sheet, 6, ['Item', 'Serviço', 'Valor mensal (R$)']);
        $row = 7;
        foreach ($f['contract_services'] ?? [] as $service) {
            $this->put($sheet, $row, 1, $service['id'] ?? '');
            $this->put($sheet, $row, 2, $service['label'] ?? '');
            $this->put($sheet, $row++, 3, isset($service['amount']) ? (float)$service['amount'] : null, 17);
        }
        $compositionSum = array_sum(array_map(static fn(array $service): float => (float)($service['amount'] ?? 0), $f['contract_services'] ?? []));
        $this->put($sheet, $row, 2, 'Soma da composição dos serviços', 4);
        $this->put($sheet, $row, 3, $compositionSum, 17, $row > 7 ? 'SUM(C7:C' . ($row - 1) . ')' : null);
        ++$row;
        $this->put($sheet, $row, 2, 'Valor bruto da competência', 4);
        $this->put($sheet, $row, 3, isset($f['gross_value']) ? (float)$f['gross_value'] : null, 17);
        $grossRow = $row;
        $row += 3;
        $this->merged($sheet, $row++, 'Pontos calculados pela política contratual', 2);
        $this->headers($sheet, $row++, ['IPS / ocorrência', 'Descrição', 'Resultado', 'Meta', 'Déficit', 'Pontos', 'Situação / referência']);
        $first = $row;
        foreach ($f['point_items'] ?? [] as $point) {
            $state = ($point['status'] ?? '') === 'assessed' ? 'Apurado' : OfficePackage::status($point);
            $reference = trim((string)($point['reference'] ?? ''));
            $values = [$point['metric_id'] ?? '', $point['label'] ?? '', $point['value'] ?? null, $point['target'] ?? null, $point['deficit'] ?? null, $point['points'] ?? null, $state . ($reference !== '' ? ' — ' . $reference : '')];
            foreach ($values as $i => $value) $this->put($sheet, $row, $i + 1, $value, $i >= 2 && $i <= 5 ? 17 : 0);
            $sheet['heights'][$row++] = 38;
        }
        $this->put($sheet, $row, 2, 'Total de pontos', 4);
        $pointsKnown = isset($f['points_total']) && is_numeric($f['points_total']);
        foreach ($f['point_items'] ?? [] as $point) {
            if (!isset($point['points']) || !is_numeric($point['points'])) $pointsKnown = false;
        }
        $this->put($sheet, $row, 6, $pointsKnown ? $f['points_total'] : null, 17,
            $pointsKnown && $row > $first ? 'IF(COUNT(F' . $first . ':F' . ($row - 1) . ')=' . ($row - $first) . ',SUM(F' . $first . ':F' . ($row - 1) . '),"")' : null);
        $row += 3;
        $this->headers($sheet, $row++, ['Composição', 'Critério', 'Valor (R$)']);
        $moneyRows = [
            ['Valor bruto', 'Serviços da ordem de serviço', 'gross_value'],
            ['Glosa', 'Apuração central de IPS e TRS, com teto e PAO aplicáveis', 'deduction_total'],
            ['Ajustes aprovados', 'Créditos e débitos documentados', 'adjustment_total'],
            ['Valor líquido', !empty($f['complete']) ? 'Apuração completa' : 'Pendente de apuração completa', 'net_value'],
        ];
        $consolidationStart = $row;
        foreach ($moneyRows as $index => [$label, $description, $key]) {
            $this->put($sheet, $row, 1, $label, 4);
            $this->put($sheet, $row, 2, $description);
            $formula = $index === 0 && isset($f[$key]) ? 'C' . $grossRow : null;
            if ($index === 3 && !empty($f['complete']) && isset($f[$key])) $formula = 'ROUND(C' . $consolidationStart . '-C' . ($consolidationStart + 1) . '+C' . ($consolidationStart + 2) . ',2)';
            $this->put($sheet, $row++, 3, isset($f[$key]) ? (float)$f[$key] : null, 17, $formula);
        }
        $row += 2;
        foreach (OfficePackage::financialNotes($f) as $note) $this->merged($sheet, $row++, $note, 9, 7, 42);
        if (!empty($f['adjustments'])) {
            $row += 2;
            $this->headers($sheet, $row++, ['Ajuste', 'Referência', 'Valor (R$)']);
            foreach ($f['adjustments'] as $adjustment) {
                $this->put($sheet, $row, 1, $adjustment['description'] ?? $adjustment['label'] ?? '');
                $this->put($sheet, $row, 2, $adjustment['reference'] ?? $adjustment['approval_reference'] ?? '');
                $this->put($sheet, $row++, 3, isset($adjustment['amount']) ? (float)$adjustment['amount'] : null, 17);
            }
        }
        return $sheet;
    }

    private function metricCell(array $m): array
    {
        if (!isset($m['value'])) return [OfficePackage::metricValue($m), 4];
        if (($m['unit'] ?? '') === 'percent') return [(float)$m['value'] / 100, OfficePackage::belowTarget($m) ? 54 : 6];
        if (($m['unit'] ?? '') === 'score') return [(float)$m['value'], OfficePackage::belowTarget($m) ? 55 : 17];
        return [OfficePackage::metricValue($m), 4];
    }

    private function metric(array $m): array
    {
        $id = (string)$m['id'];
        $sheet = $this->sheet($id, [50, 25, 25, 25, 25, 25, 25]);
        $this->title($sheet, $id . ' ' . ($m['label'] ?? ''));
        $this->merged($sheet, 4, (string)($m['method'] ?? ''), 9, 7, 48);
        $this->headers($sheet, 6, ['Medição', 'Valor']);
        $values = [8 => [$m['numerator_label'] ?? 'Numerador', $m['numerator'] ?? null], 9 => [$m['denominator_label'] ?? 'Base de cálculo', $m['denominator'] ?? null]];
        foreach ($values as $row => [$label, $value]) {
            $this->put($sheet, $row, 1, $label);
            $operand = $this->metricOperands[$id][$row === 8 ? 'numerator' : 'denominator'] ?? null;
            $this->put($sheet, $row, 2, $value, 5, $operand);
        }
        $this->put($sheet, 10, 1, 'Resultado', 4);
        [$value, $style] = $this->metricCell($m);
        $formula = null;
        if (isset($m['value'], $m['numerator'], $m['denominator']) && (float)$m['denominator'] > 0 && in_array($m['value_kind'] ?? '', ['mean_score', 'percent_ratio'], true) && preg_match('/^IPS[1-6](?:_P[123])?$/D', $id)) $formula = 'IF(B9>0,B8/B9,"Sem ocorrências")';
        $this->put($sheet, 10, 2, $value, $style, $formula);
        $this->put($sheet, 11, 1, 'Meta');
        $this->put($sheet, 11, 2, OfficePackage::metricValue($m, 'target'), 4);
        $this->put($sheet, 12, 1, 'Situação da medição');
        $this->put($sheet, 12, 2, OfficePackage::status($m), $this->statusStyle($m));
        if (isset($m['value'], $m['target']) && in_array($m['unit'] ?? '', ['percent', 'score'], true)) {
            $this->put($sheet, 13, 1, 'Comparação matemática com a meta');
            $target = (float)$m['target'] / (($m['unit'] ?? '') === 'percent' ? 100 : 1);
            $this->put($sheet, 13, 2, OfficePackage::belowTarget($m) ? 'Abaixo da meta' : 'Meta alcançada', 4,
                'IF(ISNUMBER(B10),IF(B10>=' . sprintf('%.15g', $target) . ',"Meta alcançada","Abaixo da meta"),"Não apurado")');
        }
        if (isset($m['coverage_percent'])) {
            $coverageLabel = $id === 'IPS1' ? 'Taxa de resposta' : 'Cobertura da apuração';
            $this->merged($sheet, 15, $coverageLabel . ': ' . OfficePackage::percent($m['coverage_percent']), 9, 7, 30);
        }
        if (!empty($m['unresolved_ids'])) $this->merged($sheet, 16,
            'Registros com evidência incompleta: ' . count($m['unresolved_ids']), 9, 7, 30);
        if ($id === 'IPS6' && !empty($m['excluded_count'])) $this->merged($sheet, 17,
            'Desistências excluídas do IPS6: ' . OfficePackage::number($m['excluded_count']), 9, 7, 30);
        $row = 18;
        if (!empty($this->chartData[$id]['available'])) {
            $this->attachChart($sheet, $this->chartData[$id], $row);
            $row += 22 + count($this->chartData[$id]['categories']);
        }
        if (($m['value_kind'] ?? '') === 'priority_percent_ratios') {
            $this->headers($sheet, $row++, ['Prioridade', 'Resultado', 'Meta', 'Situação']);
            foreach ($m['children'] as $child) {
                $this->put($sheet, $row, 1, $child['priority'] ?? $child['id']);
                [$v, $sty] = $this->metricCell($child);
                $this->put($sheet, $row, 2, $v, $sty, "'" . $child['id'] . "'!B10");
                $this->put($sheet, $row, 3, OfficePackage::metricValue($child, 'target'));
                $this->put($sheet, $row++, 4, OfficePackage::status($child), $this->statusStyle($child));
            }
        }
        $table = MetricEvidence::table($this->snapshot, $id, $id === 'IPS6' ? 'all' : 'denominator');
        if (!empty($table['headers']) && !empty($table['rows'])) {
            $table = $this->metricEvidence($id, $table);
            $row += 2;
            $this->merged($sheet, $row++, $id === 'IPS6' ? 'Registros telefônicos e participação no IPS6' : 'Registros da base de cálculo', 2);
            $this->tabulate($sheet, $row, $table['headers'], $table['rows'] ?? [], 'Evidence_' . $id);
        }
        return $sheet;
    }

    /** The full source remains in Base de chamados; each metric lists its relevant evidence. */
    private function metricEvidence(string $id, array $table): array
    {
        $columns = [
            'IPS1' => ['Chamado', 'Título', 'Nota', 'Resposta válida no corte', 'Numerador', 'Denominador', 'Pendente'],
            'IPS2' => ['Chamado', 'Título', 'TIT nativo (s)', 'TIT apurado (s)', 'Numerador', 'Denominador', 'Pendente'],
            'IPS3' => ['Chamado', 'Título', 'Prioridade ANA', 'TMS nativo (s)', 'TMS apurado (s)', 'Limite TMS (s)', 'Numerador', 'Denominador', 'Pendente'],
            'IPS4' => ['Chamado', 'Título', 'Prioridade ANA', 'TMS nativo (s)', 'TMS apurado (s)', 'Limite TMS (s)', 'Numerador', 'Denominador', 'Pendente'],
            'IPS5' => ['Chamado', 'Título', 'Reaberto', 'Eventos de reabertura', 'Solução documentada', 'Histórico de reabertura completo', 'Numerador', 'Denominador', 'Pendente'],
        ][explode('_', $id)[0]] ?? null;
        if ($columns === null) return $table;
        $indices = [];
        foreach ($table['headers'] as $index => $header) if (in_array($header, $columns, true)) $indices[] = $index;
        return ['headers' => array_map(static fn(int $index) => $table['headers'][$index], $indices),
            'rows' => array_map(static fn(array $row) => array_map(static fn(int $index) => $row[$index] ?? null, $indices), $table['rows'])];
    }

    private function phoneAvailable(): bool
    {
        return !empty($this->snapshot['totals']['calls_available']) || !empty($this->snapshot['calls'])
            || !empty($this->snapshot['call_legs']) || !empty($this->snapshot['excluded_calls']);
    }

    private function solutionSheets(): array
    {
        $acceptance = $missing = [];
        foreach ($this->snapshot['unconfirmed_tickets'] ?? [] as $row) {
            if (!is_array($row)) $row = ['ticket_id' => $row];
            if (!array_key_exists('solution_status', $row)) {
                $acceptance[] = ['ticket_id' => $row['ticket_id'] ?? $row['id'] ?? '', 'solution_status' => 'Situação não registrada'];
            } elseif ($row['solution_status'] === null) {
                $missing[] = ['ticket_id' => $row['ticket_id'] ?? $row['id'] ?? '', 'state' => 'Sem registro de solução'];
            } else {
                $status = (int)$row['solution_status'];
                $acceptance[] = ['ticket_id' => $row['ticket_id'] ?? $row['id'] ?? '',
                    'solution_status' => [1 => 'Solução sem aprovação', 2 => 'Aguardando aceite', 3 => 'Aceita', 4 => 'Recusada'][$status] ?? ('Status GLPI ' . $status)];
            }
        }
        $sheets = [];
        if ($acceptance) $sheets[] = $this->fieldSheet('Aceite de solucoes', 'Soluções sem aceite registrado ou recusadas', $acceptance,
            ['ticket_id' => 'Chamado', 'solution_status' => 'Situação da última solução']);
        if ($missing) $sheets[] = $this->fieldSheet('Solucoes ausentes', 'Chamados sem registro de solução na extração', $missing,
            ['ticket_id' => 'Chamado', 'state' => 'Situação na extração']);
        return $sheets;
    }

    private function tickets(): array
    {
        $spec = [
            'id' => 'ID', 'title' => 'Título', 'type' => 'Tipo GLPI', 'status' => 'Status GLPI', 'priority' => 'Prioridade GLPI', 'priority_contract' => 'Prioridade contratual', 'priority_basis' => 'Critério de prioridade',
            'category_id' => 'Categoria ID', 'category' => 'Categoria', 'category_code' => 'Código do catálogo', 'opened_at' => 'Abertura local', 'created_at' => 'Criação local', 'taken_at' => 'Assunção local', 'solved_at' => 'Solução local', 'closed_at' => 'Fechamento local',
            'group_ids' => 'Grupos atuais IDs', 'group_names' => 'Grupos atuais', 'responsibility_group_ids' => 'Grupos no marco responsável', 'technician_ids' => 'Técnicos atuais IDs',
            'tit_seconds' => 'TIT considerado (s)', 'tms_seconds' => 'TMS considerado (s)', 'native_tit_seconds' => 'TIT nativo (s)', 'native_tms_seconds' => 'TMS nativo (s)', 'waiting_seconds' => 'Pendência nativa (s)', 'approved_pause_seconds' => 'Suspensões aprovadas (s)',
            'tit_within_target' => 'TIT no prazo', 'tms_within_target' => 'TMS no prazo', 'tms_limit_seconds' => 'Limite TMS (s)', 'clock_method' => 'Método do relógio', 'clock_evidence_complete' => 'Relógio com evidência completa', 'clock_issues' => 'Limitações do relógio',
            'satisfaction' => 'Nota', 'satisfaction_valid' => 'Nota válida no recorte', 'survey_answered_epoch' => 'Pesquisa epoch', 'reopened' => 'Reaberto', 'reopening_evidence_complete' => 'Histórico de reabertura completo', 'has_solution_evidence' => 'Evidência de solução', 'scope_reason' => 'Critério de inclusão',
            'opened_epoch' => 'Abertura epoch UTC', 'solved_epoch' => 'Solução epoch UTC', 'closed_epoch' => 'Fechamento epoch UTC',
        ];
        return $this->fieldSheet('Base de chamados', 'Base de chamados incluídos', $this->snapshot['tickets'] ?? [], $spec);
    }

    private function scope(string $name, array $tickets): array
    {
        return $this->fieldSheet($name, $name, $tickets, ['id' => 'ID', 'title' => 'Título', 'scope_reason' => 'Motivo', 'category' => 'Categoria', 'group_ids' => 'Grupos atuais', 'responsibility_group_ids' => 'Grupos responsáveis', 'opened_at' => 'Abertura local', 'solved_at' => 'Solução local', 'closed_at' => 'Fechamento local', 'history_complete' => 'Histórico completo']);
    }

    private function calls(string $name, array $calls): array
    {
        foreach ($calls as &$call) {
            $call['leg_id'] = $call['leg_id'] ?? $call['id'] ?? null;
            $call['leg_ids'] = $call['leg_ids'] ?? $call['leg_keys'] ?? [];
            $call['exclusion_reason'] = $call['exclusion_reason'] ?? $call['excluded_reason'] ?? '';
            if (isset($call['ips6_included']) && !empty($call['abandon_wait_unknown'])
                && !($this->snapshot['input']['ips6_include_desistencias'] ?? true)) $call['ips6_included'] = 'Pendente';
        }
        unset($call);
        $spec = ['id' => 'Registro ID', 'call_key' => 'Chave de contagem', 'interaction_id' => 'Interação ID', 'callid' => 'Call ID', 'leg_id' => 'Passagem ID', 'queue' => 'Fila', 'agent' => 'Agente', 'caller' => 'Origem', 'entered_at' => 'Entrada local', 'answered_at' => 'Atendimento local', 'ended_at' => 'Fim local', 'wait_seconds' => 'Espera (s)', 'talk_seconds' => 'Conversação (s)', 'answered' => 'Atendida', 'abandoned' => 'Abandonada', 'unknown_outcome' => 'Desfecho desconhecido', 'transferred' => 'Transferida', 'overflow' => 'Transbordo', 'linkedid' => 'Linked ID', 'leg_ids' => 'Passagens vinculadas', 'leg_count' => 'Quantidade de passagens', 'linkage_basis' => 'Critério de vínculo', 'linkage_uncertain' => 'Vínculo incerto', 'outcome' => 'Desfecho', 'inclusion_reason' => 'Critério de inclusão', 'exclusion_reason' => 'Motivo da exclusão', 'entered_epoch' => 'Entrada epoch UTC',
            'in_period' => 'Entrada da passagem no mês', 'context_only' => 'Passagem de contexto', 'wait_basis' => 'Critério da espera', 'talk_basis' => 'Critério da conversação', 'event_ids' => 'IDs dos eventos', 'entry_event_id' => 'Evento de entrada ID', 'source_time_type' => 'Tipo temporal da origem', 'source_timezone' => 'Fuso da origem',
            'abandon_recorded' => 'Abandono registrado', 'desisted' => 'Desistência (espera inferior a 30 segundos)', 'abandon_wait_unknown' => 'Abandono sem espera apurada',
            'ips6_included' => 'Elegível nas filas para IPS6', 'ips6_exclusion_reason' => 'Motivo de inelegibilidade nas filas'];
        return $this->fieldSheet($name, $name, $calls, $spec);
    }

    /** One row per source event; excluded and carried context legs remain auditable. */
    private function phoneEvents(): array
    {
        $legs = [];
        foreach (['call_legs' => 'Passagens por fila', 'excluded_calls' => 'Ligações excluídas'] as $source => $label) {
            foreach ($this->snapshot[$source] ?? [] as $leg) {
                $id = (string)($leg['id'] ?? $leg['leg_id'] ?? $leg['call_key'] ?? '');
                if (!isset($legs[$id])) $legs[$id] = ['leg' => $leg, 'scopes' => [], 'events' => [], 'excluded_reason' => ''];
                $legs[$id]['scopes'][$source] = $label;
                $reason = (string)($leg['excluded_reason'] ?? $leg['exclusion_reason'] ?? '');
                if ($reason !== '') $legs[$id]['excluded_reason'] = $reason;
                foreach ($leg['events'] ?? [] as $event) $legs[$id]['events'][(string)($event['id'] ?? hash('sha256', OfficePackage::xml(json_encode($event))))] = $event;
            }
        }
        $rows = [];
        $start = $this->snapshot['input']['start_epoch'] ?? null;
        $end = $this->snapshot['input']['end_epoch'] ?? null;
        foreach ($legs as $id => $item) {
            $leg = $item['leg'];
            foreach ($item['events'] as $event) $rows[] = [
                'leg_id' => $id, 'call_key' => $leg['call_key'] ?? null, 'callid' => $event['callid'] ?? $leg['callid'] ?? null,
                'scope' => implode('; ', $item['scopes']), 'excluded_reason' => $item['excluded_reason'],
                'in_period' => $leg['in_period'] ?? null, 'context_only' => $leg['context_only'] ?? null,
                'event_in_period' => isset($event['epoch'], $start, $end) ? $event['epoch'] >= $start && $event['epoch'] < $end : null,
                'event_id' => $event['id'] ?? null, 'event' => $event['event'] ?? null,
                'source_time' => $event['source_time'] ?? $event['time'] ?? null, 'occurred_at' => $event['occurred_at'] ?? null,
                'epoch' => $event['epoch'] ?? null, 'queue' => $event['queuename'] ?? $event['queue'] ?? $leg['queue'] ?? null,
                'agent' => $event['agent'] ?? null, 'data1' => $event['data1'] ?? null, 'data2' => $event['data2'] ?? null, 'data3' => $event['data3'] ?? null, 'data4' => $event['data4'] ?? null,
                'source_time_type' => $leg['source_time_type'] ?? null, 'source_timezone' => $leg['source_timezone'] ?? null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$a['epoch'] ?? 0, $a['event_id'] ?? 0, $a['leg_id']] <=> [$b['epoch'] ?? 0, $b['event_id'] ?? 0, $b['leg_id']]);
        return $this->fieldSheet('Eventos telefonicos', 'Eventos brutos da telefonia, incluindo exclusões e contexto', $rows, [
            'leg_id' => 'Passagem ID', 'call_key' => 'Chave da passagem', 'callid' => 'Call ID', 'scope' => 'Base da passagem',
            'excluded_reason' => 'Motivo da exclusão', 'in_period' => 'Entrada da passagem no mês', 'context_only' => 'Passagem de contexto', 'event_in_period' => 'Evento no mês',
            'event_id' => 'Evento ID', 'event' => 'Evento', 'source_time' => 'Data/hora original', 'occurred_at' => 'Data/hora local', 'epoch' => 'Evento epoch UTC',
            'queue' => 'Fila original', 'agent' => 'Agente original', 'data1' => 'data1 original', 'data2' => 'data2 original', 'data3' => 'data3 original',
            'source_time_type' => 'Tipo temporal da origem', 'source_timezone' => 'Fuso da origem',
            'data4' => 'data4 original',
        ]);
    }

    private function surveys(): array
    {
        return $this->fieldSheet('Pesquisas GLPI', 'Pesquisas dos chamados incluídos', $this->snapshot['tickets'] ?? [], ['id' => 'Chamado ID', 'survey_id' => 'Pesquisa ID', 'satisfaction_raw' => 'Nota original', 'satisfaction' => 'Nota considerada', 'satisfaction_valid' => 'Resposta válida no recorte', 'survey_answered_epoch' => 'Resposta epoch UTC', 'closed_at' => 'Fechamento local']);
    }

    /** One contribution per eligible record; event decisions remain auditable snapshot facts. */
    private function operands(): array
    {
        $this->metricOperands = [];
        $tickets = [];
        foreach ($this->snapshot['tickets'] ?? [] as $ticket) $tickets[(string)$ticket['id']] = $ticket;
        $rows = [];
        foreach (OfficePackage::metricRows($this->snapshot) as $metric) {
            $id = (string)$metric['id'];
            if (!preg_match('/^IPS[1-6](?:_P[123])?$/D', $id) || !isset($metric['denominator'], $metric['numerator']) || !empty($metric['children'])) continue;
            $denominatorIds = array_values(array_unique(array_map('strval', $metric['denominator_ids'] ?? [])));
            if (count($denominatorIds) !== (int)$metric['denominator'] || !$denominatorIds) continue;
            $numeratorIds = array_fill_keys(array_map('strval', $metric['evidence_ids'] ?? []), true);
            $block = [];
            $sum = 0;
            foreach ($denominatorIds as $recordId) {
                $contribution = $id === 'IPS1' ? ($tickets[$recordId]['satisfaction'] ?? null) : (isset($numeratorIds[$recordId]) ? 1 : 0);
                if (!is_numeric($contribution)) { $block = []; break; }
                $sum += (float)$contribution;
                $block[] = [$id, $recordId, (float)$contribution, 1, $id === 'IPS1' ? 'Nota válida' : 'Registro conforme'];
            }
            if (!$block || abs($sum - (float)$metric['numerator']) > 0.000001) continue;
            $first = count($rows) + 7;
            $rows = array_merge($rows, $block);
            $last = count($rows) + 6;
            $this->metricOperands[$id] = ['numerator' => "SUM('Memoria IPS'!C" . $first . ':C' . $last . ')', 'denominator' => "SUM('Memoria IPS'!D" . $first . ':D' . $last . ')'];
        }
        $sheet = $this->sheet('Memoria IPS', [20, 22, 24, 24, 42]);
        $this->title($sheet, 'Contribuições dos registros aos indicadores', 5);
        $this->tabulate($sheet, 6, ['IPS', 'Registro ID', 'Contribuição numerador', 'Contribuição denominador', 'Unidade'], $rows, 'ANA_MemoriaIPS');
        return $this->serializeSheet($sheet);
    }

    private function histories(): array
    {
        $rows = [];
        foreach (['tickets', 'scope_review', 'excluded_tickets'] as $scope) foreach ($this->snapshot[$scope] ?? [] as $ticket) {
            foreach (['status_events', 'group_events', 'solutions'] as $kind) foreach ($ticket[$kind] ?? [] as $event) {
                $rows[] = ['ticket_id' => $ticket['id'] ?? '', 'scope' => $scope, 'kind' => $kind] + $event;
            }
        }
        return $this->records('Historicos e solucoes', $rows);
    }

    private function fieldSheet(string $name, string $title, array $records, array $spec): array
    {
        $rows = [];
        foreach ($records as $record) {
            if (!is_array($record)) $record = ['id' => $record];
            $row = [];
            foreach ($spec as $key => $label) $row[] = $this->literal($record[$key] ?? null);
            $rows[] = $row;
        }
        $sheet = $this->sheet($name, array_map(static fn($label) => strpos($label, 'Título') !== false || strpos($label, 'Motivo') !== false || strpos($label, 'Critério') !== false ? 50 : 26, array_values($spec)));
        $this->title($sheet, $title, min(7, count($spec)));
        $this->tabulate($sheet, 6, array_values($spec), $rows, preg_replace('/[^A-Za-z0-9]/', '', 'ANA_' . $name));
        return $this->serializeSheet($sheet);
    }

    private function records(string $name, array $records): array
    {
        $labels = ['ticket_id' => 'Chamado', 'tickets_id' => 'Chamado ID', 'scope' => 'Escopo', 'kind' => 'Tipo de registro',
            'log_id' => 'Histórico ID', 'epoch' => 'Instante UTC (s)', 'occurred_epoch' => 'Ocorrência UTC (s)', 'occurred_at' => 'Data da ocorrência',
            'actor_id' => 'Autor ID', 'actor_raw' => 'Autor registrado', 'from_status' => 'Estado anterior', 'to_status' => 'Novo estado',
            'old_status' => 'Código do estado anterior', 'new_status' => 'Código do novo estado', 'action' => 'Ação', 'linked_action' => 'Ação GLPI',
            'group_id' => 'Grupo ID', 'raw_value' => 'Valor registrado', 'id' => 'ID', 'solution_at' => 'Data da solução',
            'solver_id' => 'Solucionador ID', 'solver_name' => 'Solucionador', 'status' => 'Situação',
            'approval_epoch' => 'Aceite UTC (s)', 'approval_user_id' => 'Responsável pelo aceite ID', 'administrative_exempt' => 'Dispensa administrativa',
            'metric_id' => 'IPS', 'service' => 'Serviço', 'window_seconds' => 'Janela de medição (s)', 'downtime_seconds' => 'Indisponibilidade (s)',
            'excluded_seconds' => 'Exclusões aprovadas (s)', 'evidence_reference' => 'Referência da evidência', 'approval_reference' => 'Referência da aprovação',
            'valid' => 'Evidência válida', 'validation_errors' => 'Inconsistências', 'description' => 'Descrição', 'due_date' => 'Prazo',
            'completion_percent' => 'Execução (%)', 'target_percent' => 'Meta (%)', 'acceptance_reference' => 'Referência do aceite'];
        $statuses = [1 => 'Novo', 2 => 'Em atendimento (atribuído)', 3 => 'Em atendimento (planejado)', 4 => 'Pendente', 5 => 'Solucionado', 6 => 'Fechado'];
        foreach ($records as &$record) {
            if (!is_array($record)) continue;
            if (isset($record['scope'])) $record['scope'] = ['tickets' => 'Incluído', 'scope_review' => 'Em revisão', 'excluded_tickets' => 'Excluído'][$record['scope']] ?? $record['scope'];
            $kind = $record['kind'] ?? '';
            if (isset($record['kind'])) $record['kind'] = ['status_events' => 'Alteração de estado', 'group_events' => 'Atribuição de grupo', 'solutions' => 'Solução'][$kind] ?? $kind;
            foreach (['from_status', 'to_status'] as $key) if (isset($record[$key])) $record[$key] = $statuses[(int)$record[$key]] ?? $record[$key];
            if (isset($record['action'])) $record['action'] = ['add' => 'Inclusão', 'remove' => 'Remoção'][$record['action']] ?? $record['action'];
            if ($kind === 'solutions' && isset($record['status'])) $record['status'] = [1 => 'Solução sem aprovação', 2 => 'Aguardando aceite', 3 => 'Aceita', 4 => 'Recusada'][(int)$record['status']] ?? $record['status'];
        }
        unset($record);
        $keys = [];
        foreach ($records as $record) if (is_array($record)) foreach (array_keys($record) as $key) {
            if ($key === 'validation_errors' && empty($record[$key]) && !isset($keys[$key])) continue;
            $keys[(string)$key] = $labels[$key] ?? ucfirst(str_replace('_', ' ', (string)$key));
        }
        if (!$keys) $keys = ['id' => 'ID'];
        return $this->fieldSheet($name, $name, $records, $keys);
    }

    private function literal($value)
    {
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_array($value)) {
            if (array_is_list($value)) return implode('; ', array_map(fn($item) => (string)$this->literal($item), $value));
            $entries = [];
            foreach ($value as $key => $item) $entries[] = (string)$key . ': ' . (string)$this->literal($item);
            return implode('; ', $entries);
        }
        return $value;
    }

    private function methodology(): array
    {
        $rows = array_values(array_filter(OfficePackage::methodology($this->snapshot),
            static fn(array $row): bool => $row[0] !== 'Versão das regras' && trim((string)$row[1]) !== ''));
        $manifest = $this->snapshot['source_manifest'] ?? [];
        foreach (['extraction_started_at' => 'Início da extração', 'extraction_finished_at' => 'Fim da extração'] as $key => $label) {
            if (!empty($manifest[$key])) $rows[] = [$label, OfficePackage::date($manifest[$key])];
        }
        if (isset($manifest['candidate_count'])) $rows[] = ['Chamados consultados', $manifest['candidate_count']];
        $rows[] = ['Registros telefônicos', $this->phoneAvailable() ? 'Disponíveis' : 'Indisponíveis'];
        if (!empty($this->snapshot['scope_review'])) $rows[] = ['Chamados em revisão de escopo', count($this->snapshot['scope_review'])];
        if (!empty($this->snapshot['priority_cap']['excess'])) {
            $rows[] = ['Chamados P1/P2 acima do limite contratual', $this->snapshot['priority_cap']['excess']];
        }
        $narrative = OfficePackage::narrativeTexts($this->snapshot);
        $narrativeLabels = ['report_title' => 'Título do relatório', 'executive_summary' => 'Resumo executivo',
            'actions' => 'Ações e justificativas', 'notes' => 'Observações complementares', 'phone_survey_actions' => 'Acompanhamento da telefonia',
            'availability_notes' => 'Disponibilidade dos serviços', 'siem_notes' => 'Operação do SIEM', 'rdm_notes' => 'Mudanças e RDM',
            'vip_notes' => 'Atendimento VIP', 'trs_notes' => 'Registros de sanções — TRS', 'improvement_notes' => 'Melhoria contínua'];
        foreach ($narrative as $key => $text) if ($text !== '') $rows[] = [$narrativeLabels[$key], $text];
        $external = $this->snapshot['external_evidence'] ?? $this->snapshot['input']['external_evidence'] ?? $this->snapshot['manual'] ?? [];
        if (isset($external['controls_applicable'])) $rows[] = ['Controles IPS9 aplicáveis', $external['controls_applicable'] ? 'Sim' : 'Não'];
        if (trim((string)($external['controls_scope_reference'] ?? '')) !== '') $rows[] = ['Referência de escopo IPS9', $external['controls_scope_reference']];
        foreach (['forwarded_calls' => 'Chamadas encaminhadas — evidência da rede', 'answered_calls' => 'Chamadas atendidas — evidência da rede',
            'desisted_calls' => 'Desistências — evidência da rede',
            'evidence_reference' => 'Referência da evidência de telefonia'] as $key => $label) {
            $value = $external['phone'][$key] ?? null;
            if ($value !== null && $value !== '') $rows[] = [$label, $value];
        }
        if (!empty($external['validation_errors'])) $rows[] = ['Evidências externas com inconsistências', count($external['validation_errors'])];
        $sheet = $this->sheet('Metodologia', [38, 120]);
        $this->title($sheet, 'Metodologia e informações gerenciais', 2);
        $this->tabulate($sheet, 6, ['Parâmetro', 'Valor'], $rows, 'ANA_Metodologia');
        return $sheet;
    }

    private function temporal(): array
    {
        $sheet = $this->sheet('Distribuicao temporal', [24, 18, 18, 18, 18, 18, 18, 18]);
        $this->title($sheet, 'Distribuição temporal da operação', 8);
        $sheet['freeze'] = 3;
        $start = 6;
        foreach (HeatmapData::build($this->snapshot) as $map) {
            if (empty($map['available'])) continue;
            $title = $map['source'] === 'tickets' ? 'Chamados por dia da semana e hora de ' . ($map['date_key'] === 'solved_at' ? 'solução' : 'fechamento') : $map['title'];
            $this->merged($sheet, $start++, $title, 2, 8);
            $this->headers($sheet, $start++, array_merge(['Hora local'], $map['column_labels']));
            $first = $start;
            foreach ($map['row_labels'] as $hour => $label) {
                $this->put($sheet, $start, 1, $label, 4);
                foreach ($map['column_labels'] as $weekday => $unused) $this->put($sheet, $start, $weekday + 2, $map['values'][$hour][$weekday], 15);
                ++$start;
            }
            $last = $start - 1;
            $sheet['conditional'][] = 'B' . $first . ':H' . $last;
            $this->put($sheet, $start, 1, 'Total geral', 4);
            foreach ($map['column_labels'] as $weekday => $unused) {
                $column = self::col($weekday + 2);
                $this->put($sheet, $start, $weekday + 2, array_sum(array_column($map['values'], $weekday)), 5, 'SUM(' . $column . $first . ':' . $column . $last . ')');
            }
            $note = 'Horário de Brasília. Registros no período: ' . $map['total'] . '.';
            if ($map['omitted'] > 0) $note .= ' Registros fora do mapa por data ausente, inválida ou fora do período: ' . $map['omitted'] . '.';
            $this->merged($sheet, $start + 2, $note, 9, 8, 34);
            $start += 6;
        }
        return $sheet;
    }

    private function tabulate(array &$sheet, int $header, array $headers, array $rows, string $name): void
    {
        $this->headers($sheet, $header, $headers);
        foreach ($headers as $index => $unused) if (!isset($sheet['widths'][$index])) $sheet['widths'][$index] = 28;
        $row = $header + 1;
        foreach ($rows as $values) {
            $cells = [];
            foreach (array_values($values) as $column => $value) $cells[$column + 1] = ['value' => $this->literal($value), 'style' => is_int($value) || is_float($value) ? 5 : 0, 'formula' => null];
            $sheet['evidence_xml'] .= $this->rowXml($row++, $cells, 32);
        }
        $sheet['last_evidence_row'] = $row - 1;
        if ($rows) {
            $ref = 'A' . $header . ':' . self::col(count($headers)) . ($row - 1);
            $sheet['tables'][] = ['name' => $name, 'ref' => $ref, 'headers' => $headers];
        }
        $sheet['freeze'] = $header;
    }

    private function attachChart(array &$sheet, array $definition, int $row): void
    {
        $header = $row + 18;
        $this->headers($sheet, $header, array_merge(['Categoria'], array_column($definition['series'], 'name')));
        foreach ($definition['categories'] as $i => $category) {
            $this->put($sheet, $header + 1 + $i, 1, $category);
            foreach ($definition['series'] as $j => $series) {
                $formula = null;
                if ($j === 1 && isset($series['values'][$i]) && preg_match('/^IPS\d+$/D', $definition['id'] ?? '') && in_array($definition['unit'] ?? '', ['score', 'percent'], true)) {
                    $formula = in_array($definition['id'], ['IPS3', 'IPS4'], true)
                        ? "'" . $definition['id'] . '_' . $category . "'!B10" : '$B$10';
                }
                $this->put($sheet, $header + 1 + $i, $j + 2, $series['values'][$i] ?? null, ($definition['unit'] ?? '') === 'percent' ? 6 : 17, $formula);
            }
        }
        $sheet['charts'][] = $this->chartSpec($sheet['name'], $definition, $header + 1, 1, [0, $row - 1, 7, $row + 16]);
    }

    private function sheet(string $name, array $widths): array
    {
        return ['name' => $name, 'widths' => $widths, 'rows' => [], 'merges' => [], 'heights' => [], 'filter' => '', 'freeze' => 6, 'charts' => [], 'conditional' => [], 'tables' => [], 'links' => [], 'evidence_xml' => '', 'last_evidence_row' => 0];
    }

    private function put(array &$sheet, int $row, int $col, $value, int $style = 0, ?string $formula = null): void
    {
        $sheet['rows'][$row][$col] = ['value' => $value, 'style' => $style, 'formula' => $formula];
    }

    private function merged(array &$sheet, int $row, string $text, int $style, int $end = 7, int $height = 32): void
    {
        $this->put($sheet, $row, 1, $text, $style);
        $sheet['merges'][] = 'A' . $row . ':' . self::col($end) . $row;
        $sheet['heights'][$row] = $height;
    }

    private function headers(array &$sheet, int $row, array $headers): void
    {
        $height = 36;
        foreach ($headers as $i => $header) {
            $this->put($sheet, $row, $i + 1, $header, 3);
            $characters = function_exists('mb_strlen') ? mb_strlen($header, 'UTF-8') : strlen($header);
            $width = max(8, ($sheet['widths'][$i] ?? 25) - 4);
            $height = max($height, min(160, 12 + 16 * (int)ceil($characters / $width)));
        }
        $sheet['heights'][$row] = $height;
    }

    private function chartSpec(string $sheetName, array $definition, int $firstRow, int $column, array $anchor): array
    {
        $prefix = "'" . str_replace("'", "''", $sheetName) . "'!";
        $lastRow = $firstRow + count($definition['categories']) - 1;
        $definition['category_ref'] = $prefix . '$' . self::col($column) . '$' . $firstRow . ':$' . self::col($column) . '$' . $lastRow;
        foreach ($definition['series'] as $index => &$series) {
            $col = self::col($column + 1 + $index);
            $series['value_ref'] = $prefix . '$' . $col . '$' . $firstRow . ':$' . $col . '$' . $lastRow;
        }
        unset($series);
        $definition['anchor'] = $anchor;
        return $definition;
    }

    private function statusStyle(array $m): int
    {
        return $m['status'] === 'met' ? 11 : ($m['status'] === 'unmet' ? 12 : 13);
    }

    private static function col(int $value): string
    {
        $name = '';
        do { $value--; $name = chr(65 + $value % 26) . $name; $value = intdiv($value, 26); } while ($value > 0);
        return $name;
    }

    private function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $sheet['freeze'] . '" topLeftCell="A' . ($sheet['freeze'] + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . ($sheet['freeze'] + 1) . '" sqref="A' . ($sheet['freeze'] + 1) . '"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="25"/><cols>';
        foreach ($sheet['widths'] as $i => $width) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        ksort($sheet['rows']);
        foreach ($sheet['rows'] as $r => $cells) {
            $xml .= $this->rowXml($r, $cells, $sheet['heights'][$r] ?? null);
        }
        // Serialized rows follow the retained headings/summary block in both
        // raw worksheets and per-indicator evidence tables.
        $xml .= $sheet['evidence_xml'];
        $xml .= '</sheetData>';
        if ($sheet['filter'] !== '') { $xml .= '<autoFilter ref="' . $sheet['filter'] . '"/>'; }
        if ($sheet['merges']) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $range) { $xml .= '<mergeCell ref="' . $range . '"/>'; }
            $xml .= '</mergeCells>';
        }
        foreach ($sheet['conditional'] as $index => $range) {
            $xml .= '<conditionalFormatting sqref="' . $range . '"><cfRule type="colorScale" priority="' . ($index + 1) . '"><colorScale><cfvo type="num" val="0"/><cfvo type="max"/><color rgb="FF' . ReportStyle::WHITE . '"/><color rgb="FF' . ReportStyle::HEATMAP_MAX . '"/></colorScale></cfRule></conditionalFormatting>';
        }
        if ($sheet['links']) {
            $xml .= '<hyperlinks>';
            foreach ($sheet['links'] as $link) {
                $xml .= '<hyperlink ref="' . OfficePackage::xml($link['ref']) . '" location="' . OfficePackage::xml($link['location']) . '"/>';
            }
            $xml .= '</hyperlinks>';
        }
        $xml .= '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>&amp;LG4F | ANA&amp;R&amp;P / &amp;N</oddFooter></headerFooter>';
        if ($sheet['charts']) { $xml .= '<drawing r:id="rIdChart"/>'; }
        if ($sheet['tables']) {
            $xml .= '<tableParts count="' . count($sheet['tables']) . '">';
            foreach ($sheet['tables'] as $index => $table) { $xml .= '<tablePart r:id="rIdEvidenceTable' . ($index + 1) . '"/>'; }
            $xml .= '</tableParts>';
        }
        return $xml . '</worksheet>';
    }

    /** Keep completed sheets compressed while the remaining indicators are built. */
    private function serializeSheet(array $sheet): array
    {
        // Do not duplicate the large evidence body by concatenating it into a
        // second worksheet XML string. Feed wrapper and body into zlib in order.
        $wrapper = $sheet;
        $wrapper['evidence_xml'] = '';
        $xml = $this->sheetXml($wrapper);
        unset($wrapper);
        $split = strpos($xml, '</sheetData>');
        $deflater = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        if ($split === false || $deflater === false) {
            throw new \RuntimeException('Não foi possível preparar a planilha para exportação.');
        }
        $head = deflate_add($deflater, substr($xml, 0, $split), ZLIB_NO_FLUSH);
        $body = deflate_add($deflater, $sheet['evidence_xml'], ZLIB_NO_FLUSH);
        $tail = deflate_add($deflater, substr($xml, $split), ZLIB_FINISH);
        if (!is_string($head) || !is_string($body) || !is_string($tail)) {
            throw new \RuntimeException('Não foi possível compactar os registros da planilha.');
        }
        $compressed = $head . $body . $tail;
        $checksum = hash_init('crc32b');
        hash_update($checksum, substr($xml, 0, $split));
        hash_update($checksum, $sheet['evidence_xml']);
        hash_update($checksum, substr($xml, $split));
        $sheet['serialized_entry'] = ['deflated' => $compressed,
            'size' => strlen($xml) + strlen($sheet['evidence_xml']), 'crc' => (int)hexdec(hash_final($checksum))];
        $sheet['rows'] = [];
        $sheet['heights'] = [];
        $sheet['evidence_xml'] = '';
        return $sheet;
    }

    private function rowXml(int $row, array $cells, ?int $height = null): string
    {
        $xml = '<row r="' . $row . '"' . ($height !== null ? ' ht="' . $height . '" customHeight="1"' : '') . '>';
        ksort($cells);
        foreach ($cells as $column => $cell) {
            $ref = self::col($column) . $row;
            $value = $cell['value'];
            $numeric = is_int($value) || is_float($value);
            if ($cell['formula'] !== null) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"' . ($numeric ? '' : ' t="str"') . '><f>' . OfficePackage::xml($cell['formula']) . '</f><v>' . OfficePackage::xml($numeric ? sprintf('%.15g', $value) : $value) . '</v></c>';
            } elseif ($value === null) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"/>';
            } elseif ($numeric) {
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '"><v>' . sprintf('%.15g', $value) . '</v></c>';
            } else {
                // Source text remains literal even when it starts with =,+,-,@.
                $xml .= '<c r="' . $ref . '" s="' . $cell['style'] . '" t="inlineStr"><is><t xml:space="preserve">' . OfficePackage::xml($value) . '</t></is></c>';
            }
        }
        return $xml . '</row>';
    }

    private function styles(): string
    {
        $formats = [164 => '0.00%', 165 => 'dd/mm/yyyy hh:mm:ss', 166 => 'dd/mm/yyyy', 167 => '[h]:mm:ss'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="4">';
        foreach ($formats as $id => $code) { $xml .= '<numFmt numFmtId="' . $id . '" formatCode="' . $code . '"/>'; }
        $xml .= '</numFmts><fonts count="6"><font><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Arial"/></font><font><b/><sz val="21"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF' . ReportStyle::NAVY . '"/><name val="Arial"/></font><font><sz val="10"/><color rgb="FF' . ReportStyle::MUTED . '"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF9C1C1C"/><name val="Arial"/></font></fonts><fills count="10"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ([ReportStyle::NAVY, ReportStyle::BLUE_DARK, ReportStyle::LIGHT_GRAY, ReportStyle::POSITIVE_FILL, ReportStyle::CAUTION_FILL, ReportStyle::LIGHT_GRAY, ReportStyle::HEATMAP_MAX, ReportStyle::WHITE] as $color) { $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>'; }
        $xml .= '</fills><borders count="2"><border/><border><bottom style="thin"><color rgb="FF' . ReportStyle::BORDER . '"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="56">';
        // font, fill, format, border, horizontal alignment
        $styles = [[0,0,0,1,'left'],[1,2,0,0,'left'],[2,3,0,0,'left'],[2,2,0,1,'left'],[3,4,0,1,'left'],[0,0,3,1,'right'],[3,4,164,1,'right'],[0,0,165,1,'left'],[0,0,166,1,'left'],[4,0,0,0,'left'],[0,0,167,1,'right'],[3,5,0,1,'left'],[5,0,0,1,'left'],[3,7,0,1,'left'],[0,0,49,1,'left'],[0,0,3,1,'center'],[0,8,3,1,'center'],[0,0,4,1,'right']];
        // Two explicit evidence-row variants of the existing formats: white
        // and light blue. Preserve the original number/date/text formats.
        $baseStyles = $styles;
        foreach ([9, 4] as $fillId) {
            foreach ($baseStyles as $base) {
                $base[0] = 0;
                $base[1] = $fillId;
                $styles[] = $base;
            }
        }
        $styles[] = [5,0,164,1,'right'];
        $styles[] = [5,0,4,1,'right'];
        foreach ($styles as $s) { $xml .= '<xf numFmtId="' . $s[2] . '" fontId="' . $s[0] . '" fillId="' . $s[1] . '" borderId="' . $s[3] . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . $s[4] . '" vertical="center" wrapText="1"/></xf>'; }
        return $xml . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleLight1" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
    }

    private function package(): string
    {
        $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files = [];
        // Index by reference so releasing each processed sheet also releases its
        // buffers; a by-value foreach would retain the pre-mutation array.
        for ($i = 0, $count = count($this->sheets); $i < $count; $i++) {
            $sheet =& $this->sheets[$i];
            $n = $i + 1;
            $workbook .= '<sheet name="' . OfficePackage::xml($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if (isset($sheet['serialized_entry'])) {
                $files['xl/worksheets/sheet' . $n . '.xml'] = $sheet['serialized_entry'];
            } else {
                $files['xl/worksheets/sheet' . $n . '.xml'] = $this->sheetXml($sheet);
            }
            // Only table/chart metadata is needed by the remaining package steps.
            $this->sheets[$i]['rows'] = [];
            $this->sheets[$i]['heights'] = [];
            $this->sheets[$i]['evidence_xml'] = '';
            unset($this->sheets[$i]['serialized_entry']);
        }
        unset($sheet);
        $files['xl/workbook.xml'] = $workbook . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $rels . '</Relationships>';
        $files['xl/styles.xml'] = $this->styles();
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $this->addTables($files, $types);
        $this->addCharts($files, $types);
        $files['[Content_Types].xml'] = $types . '</Types>';
        return OfficePackage::write($files);
    }

    private function addTables(array &$files, string &$types): void
    {
        $number = 0;
        foreach ($this->sheets as $sheetIndex => $sheet) {
            foreach ($sheet['tables'] as $index => $table) {
                $number++;
                $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $number . '" name="' . OfficePackage::xml($table['name']) . '" displayName="' . OfficePackage::xml($table['name']) . '" ref="' . $table['ref'] . '" totalsRowShown="0"><autoFilter ref="' . $table['ref'] . '"/><tableColumns count="' . count($table['headers']) . '">';
                foreach ($table['headers'] as $column => $header) {
                    $xml .= '<tableColumn id="' . ($column + 1) . '" name="' . OfficePackage::xml($header) . '"/>';
                }
                $files['xl/tables/table' . $number . '.xml'] = $xml . '</tableColumns><tableStyleInfo name="TableStyleLight1" showFirstColumn="0" showLastColumn="0" showRowStripes="0" showColumnStripes="0"/></table>';
                $types .= '<Override PartName="/xl/tables/table' . $number . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
                $this->sheetRelationship($files, $sheetIndex + 1, '<Relationship Id="rIdEvidenceTable' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table' . $number . '.xml"/>');
            }
        }
    }

    private function sheetRelationship(array &$files, int $sheetNumber, string $relationship): void
    {
        $path = 'xl/worksheets/_rels/sheet' . $sheetNumber . '.xml.rels';
        $xml = $files[$path] ?? '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        $files[$path] = str_replace('</Relationships>', $relationship . '</Relationships>', $xml);
    }

    private function addCharts(array &$files, string &$types): void
    {
        $chartNumber = 0;
        foreach ($this->sheets as $sheetIndex => $sheet) {
            if (!$sheet['charts']) { continue; }
            $drawingNumber = $sheetIndex + 1;
            $this->sheetRelationship($files, $drawingNumber, '<Relationship Id="rIdChart" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $drawingNumber . '.xml"/>');
            $relationships = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $drawing = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
            foreach ($sheet['charts'] as $index => $chart) {
                $chartNumber++;
                $relationship = 'rId' . ($index + 1);
                $relationships .= '<Relationship Id="' . $relationship . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $chartNumber . '.xml"/>';
                $drawing .= XlsxChart::anchor($chart, $relationship, $chartNumber);
                $files['xl/charts/chart' . $chartNumber . '.xml'] = XlsxChart::xml($chart);
                $types .= '<Override PartName="/xl/charts/chart' . $chartNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
            }
            $files['xl/drawings/drawing' . $drawingNumber . '.xml'] = $drawing . '</xdr:wsDr>';
            $files['xl/drawings/_rels/drawing' . $drawingNumber . '.xml.rels'] = $relationships . '</Relationships>';
            $types .= '<Override PartName="/xl/drawings/drawing' . $drawingNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
    }
}
