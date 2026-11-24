<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** ANA managerial report built from the same frozen dataset as the workbook. */
final class DocxExporter
{
    private const VIOLATION_TEXT = '9C1C1C';

    private WordChart $charts;
    private array $tocEntries = [];

    public function filename(array $snapshot): string
    {
        return 'Relatorio_Mensal_ANA_' . preg_replace('/[^0-9-]/', '', $snapshot['period']['month'] ?? '') . '.docx';
    }

    public function toBinary(array $snapshot): string
    {
        $path = dirname(__DIR__, 2) . '/resources/docx/papel_timbrado_G4F.docx';
        $binary = @file_get_contents($path);
        if (!is_string($binary)) {
            throw new \RuntimeException('O papel timbrado G4F não foi encontrado no pacote do plugin.');
        }
        $files = OfficePackage::read($binary);
        if (!isset($files['word/document.xml'], $files['word/_rels/document.xml.rels'])) {
            throw new \RuntimeException('O modelo G4F não contém os componentes necessários.');
        }
        $this->charts = new WordChart();
        $files['word/document.xml'] = $this->document($snapshot);
        $this->charts->attach($files);
        $files['word/styles.xml'] = $this->styles();
        $files['word/settings.xml'] = $this->updateFieldSettings($files['word/settings.xml'] ?? null);
        // Inline header/footer crops of the unchanged supplied artwork repeat on
        // automatically continued pages in both Word and LibreOffice. Full-page
        // floating header drawings do not reliably repeat in all Office renderers.
        $files['word/header2.xml'] = $this->letterheadPart(false);
        $files['word/footer1.xml'] = $this->letterheadPart(true);
        $files['word/_rels/footer1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.png"/></Relationships>';
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Relatório mensal ANA</dc:title><dc:creator>G4F</dc:creator><dc:description>' . OfficePackage::xml(($snapshot['period']['month'] ?? '') . ' | ' . ($snapshot['rules_version'] ?? '')) . '</dc:description></cp:coreProperties>';
        return OfficePackage::write($files);
    }

    private function document(array $s): string
    {
        $this->tocEntries = [];
        $input = $s['input'] ?? [];
        $totals = OfficePackage::phoneTotals($s);
        $narrative = OfficePackage::narrativeTexts($s);
        $figures = ChartData::build($s);
        $title = $narrative['report_title'] ?: 'Relatório mensal gerencial ANA';
        $body = $this->p('Agência Nacional de Águas e Saneamento Básico', '', false, 24, 'center');
        $body .= '<w:p><w:pPr><w:spacing w:before="2400" w:after="320"/></w:pPr></w:p>';
        $body .= $this->p($title, 'Title', true, 40, 'center');
        $body .= $this->p('Contrato nº 006/2025', '', true, 26, 'center');
        $body .= $this->p($this->periodLabel($s), '', false, 26, 'center');
        $body .= $this->p('Serviços de operação de infraestrutura e atendimento a usuários de TIC', '', false, 22, 'center');
        $body .= $this->p('G4F Soluções Corporativas Ltda.', '', false, 22, 'center');
        $body .= $this->page();
        $body .= '<!-- G4F_ANA_TOC -->';
        $body .= $this->page();
        $body .= $this->heading('1 Apresentação e composição financeira');
        $body .= $this->p('Relatório dos serviços de TIC, dos indicadores de prestação de serviços e da medição financeira do Contrato nº 006/2025, referente a ' . $this->periodLabel($s) . '.');
        $body .= $this->financial($s['financial'] ?? []);
        $body .= $this->heading('2 Síntese operacional');
        if ($narrative['executive_summary'] !== '') $body .= $this->text($narrative['executive_summary']);
        $body .= $this->table(['Chamados na competência', 'Quantidade'], [
            ['Chamados incluídos', OfficePackage::number($totals['tickets'] ?? count($s['tickets'] ?? []))],
            ['Incidentes', OfficePackage::number($totals['incidents'] ?? 0)],
            ['Requisições', OfficePackage::number($totals['requests'] ?? 0)],
            ['Registros com escopo em revisão', OfficePackage::number(count($s['scope_review'] ?? []))],
            ['Registros excluídos do escopo', OfficePackage::number(count($s['excluded_tickets'] ?? []))],
        ], [7200, 2300]);
        if (isset($figures['tickets_types'])) $body .= $this->figure($figures['tickets_types'], 3400, false);
        $body .= $this->heading('2.1 Atendimento telefônico', 2);
        if (isset($totals['calls_received'])) {
            $phoneRows = [
                ['Ligações recebidas', OfficePackage::number($totals['calls_received'])],
                ['Ligações atendidas', isset($totals['calls_answered']) ? OfficePackage::number($totals['calls_answered']) : 'Não apurado'],
                ['Ligações abandonadas', isset($totals['calls_abandoned']) ? OfficePackage::number($totals['calls_abandoned']) : 'Não apurado'],
            ];
            if (!empty($totals['calls_short_unanswered_excluded'])) $phoneRows[] = ['Não atendidas/abandonadas excluídas (até 4 segundos)', OfficePackage::number($totals['calls_short_unanswered_excluded'])];
            if (isset($totals['calls_desisted'])) $phoneRows[] = ['Desistências (espera inferior a 30 segundos)', OfficePackage::number($totals['calls_desisted'])];
            if (!empty($totals['calls_abandon_wait_unknown'])) $phoneRows[] = ['Abandonos sem tempo de espera apurado', OfficePackage::number($totals['calls_abandon_wait_unknown'])];
            $phoneRows[] = ['Tempo médio de atendimento', OfficePackage::duration($totals['mean_talk_seconds'] ?? null)];
            $phoneRows[] = ['Tempo médio de espera', OfficePackage::duration($totals['mean_wait_seconds'] ?? null)];
            $body .= $this->table(['Medição', 'Resultado'], $phoneRows, [7200, 2300]);
        }
        else $body .= $this->p('Dados telefônicos indisponíveis nesta medição.');
        if ($narrative['phone_survey_actions'] !== '') $body .= $this->text($narrative['phone_survey_actions']);
        if (isset($figures['calls_daily'])) $body .= $this->figure($figures['calls_daily'], 2900, false);
        $body .= $this->heading('2.2 Distribuição temporal da operação', 2);
        if (isset($figures['tickets_daily'])) $body .= $this->figure($figures['tickets_daily'], 2500, false);
        foreach (HeatmapData::build($s) as $map) {
            if (empty($map['available'])) continue;
            $mapTitle = ($map['source'] ?? '') === 'tickets'
                ? 'Chamados por dia da semana e hora de ' . (($map['date_key'] ?? '') === 'solved_at' ? 'solução' : 'fechamento')
                : $map['title'];
            $body .= $this->heading($mapTitle, 2);
            $body .= $this->heatmap($map);
        }
        $body .= $this->page();
        $body .= $this->heading('3 Indicadores de prestação de serviços');
        $rows = $violations = [];
        foreach (OfficePackage::metricRows($s) as $m) {
            $rows[] = [(string)$m['id'], (string)($m['label'] ?? $m['id']), OfficePackage::metricValue($m, 'target'), OfficePackage::metricValue($m), OfficePackage::status($m)];
            $violations[] = OfficePackage::belowTarget($m);
        }
        $body .= $this->table(['IPS', 'Descrição', 'Meta', 'Resultado', 'Situação'], $rows, [1000, 3400, 1300, 1550, 2250], 18, $violations);
        foreach ($s['metrics'] ?? [] as $index => $m) {
            $block = $this->heading('3.' . ($index + 1) . ' ' . $m['id'] . ' ' . ($m['label'] ?? ''), 2);
            if (($m['value_kind'] ?? '') === 'priority_percent_ratios') {
                $rows = $violations = [];
                foreach ($m['children'] as $child) {
                    $rows[] = [(string)($child['priority'] ?? $child['id']), OfficePackage::number($child['numerator'] ?? null), OfficePackage::number($child['denominator'] ?? null), OfficePackage::metricValue($child), OfficePackage::status($child)];
                    $violations[] = OfficePackage::belowTarget($child);
                }
                $block .= $this->table(['Prioridade', 'No prazo', 'Base', 'Resultado', 'Situação'], $rows, [1300, 1500, 1500, 2200, 3000], 20, $violations);
            } else {
                $rows = [];
                if (isset($m['numerator'])) $rows[] = [(string)($m['numerator_label'] ?? 'Numerador'), OfficePackage::number($m['numerator'])];
                if (isset($m['denominator'])) $rows[] = [(string)($m['denominator_label'] ?? 'Base de cálculo'), OfficePackage::number($m['denominator'])];
                if (($m['id'] ?? '') === 'IPS6' && !empty($m['excluded_count'])) $rows[] = ['Desistências excluídas do IPS6', OfficePackage::number($m['excluded_count'])];
                $rows[] = ['Resultado', OfficePackage::metricValue($m)];
                $rows[] = ['Meta', OfficePackage::metricValue($m, 'target')];
                if (OfficePackage::metricValue($m) !== OfficePackage::status($m)) $rows[] = ['Situação', OfficePackage::status($m)];
                $violations = array_map(static fn(array $row): bool => $row[0] === 'Resultado' && OfficePackage::belowTarget($m), $rows);
                $block .= $this->table(['Medição', 'Valor'], $rows, [6500, 3000], 20, $violations);
            }
            if (($m['id'] ?? '') === 'IPS1' && isset($m['response_rate_percent'])) {
                $block .= $this->p('Taxa de resposta: ' . OfficePackage::percent($m['response_rate_percent']) . '.', '', false, 18);
            } elseif (isset($m['coverage_percent']) && count($m['unresolved_ids'] ?? []) > 0) {
                $incompleteLabel = ($m['id'] ?? '') === 'IPS6' ? 'Registros com evidência incompleta' : 'Registros fora da base avaliada';
                $block .= $this->p('Cobertura da apuração: ' . OfficePackage::percent($m['coverage_percent']) . '. ' . $incompleteLabel . ': ' . count($m['unresolved_ids']) . '.', '', false, 18);
            }
            if (!empty($figures[$m['id']]['available'])) $body .= $this->keepWithNext($block) . $this->figure($figures[$m['id']], 2800, false);
            else $body .= $block;
        }
        $body .= $this->heading('4 Continuidade e disponibilidade dos serviços');
        $services = $s['external_evidence']['services'] ?? $input['external_evidence']['services'] ?? [];
        if ($services) {
            $rows = [];
            foreach ($services as $service) $rows[] = [(string)($service['metric_id'] ?? ''), (string)($service['service'] ?? ''), OfficePackage::duration($service['window_seconds'] ?? null), OfficePackage::duration($service['downtime_seconds'] ?? null), (string)($service['evidence_reference'] ?? '')];
            $body .= $this->table(['IPS', 'Serviço', 'Janela', 'Indisponibilidade', 'Evidência'], $rows, [800, 2600, 1500, 1800, 2800], 18);
        }
        $topicNumber = 0;
        $missingTopics = [];
        foreach (['availability_notes' => 'Disponibilidade e continuidade', 'siem_notes' => 'Operação do SIEM', 'rdm_notes' => 'Requisições de mudança', 'vip_notes' => 'Atendimento a usuários VIP', 'trs_notes' => 'Termos de recebimento e sanções', 'improvement_notes' => 'Melhoria contínua'] as $key => $label) {
            $content = $narrative[$key];
            if ($content === '') { $missingTopics[] = [$label, 'Não informado']; continue; }
            $body .= $this->heading('4.' . (++$topicNumber) . ' ' . $label, 2);
            $body .= $this->text($content);
        }
        if ($missingTopics) $body .= $this->table(['Tópico', 'Registro complementar'], $missingTopics, [6500, 3000], 18);
        $body .= $this->heading('5 Reaberturas');
        $reopened = [];
        foreach ($s['reopening_events'] ?? [] as $event) {
            $id = $event['ticket_id'] ?? $event['tickets_id'] ?? $event['id'] ?? '';
            $reopened[(string)$id] = ($reopened[(string)$id] ?? 0) + 1;
        }
        if ($reopened) {
            $body .= $this->p('Chamados com reabertura registrada: ' . count($reopened) . '. Eventos registrados: ' . array_sum($reopened) . '.');
            $rows = [];
            foreach ($reopened as $id => $count) $rows[] = [$id, (string)$count];
            $body .= $this->table(['Chamado reaberto', 'Eventos registrados'], $rows, [6000, 3500], 18, [], 'left', count($rows) <= 12);
        } else $body .= $this->p('Nenhum evento de reabertura identificado na base extraída.');
        foreach ($s['metrics'] ?? [] as $metric) if (($metric['id'] ?? '') === 'IPS5' && !empty($metric['unresolved_ids'])) {
            $ids = $metric['unresolved_ids'];
            $body .= $this->p('Registros fora da base avaliada do IPS5: ' . count($ids) . (count($ids) <= 20 ? ' (chamados ' . implode(', ', $ids) . ')' : '') . '.', '', false, 18);
        }
        $sectionNumber = 6;
        if ($narrative['actions'] !== '') {
            $body .= $this->heading($sectionNumber++ . ' Ações gerenciais');
            $body .= $this->text($narrative['actions']);
        }
        $body .= $this->heading($sectionNumber++ . ' Critérios da medição');
        $body .= $this->table(['Parâmetro', 'Critério'], $this->methodology($s), [3200, 6300], 18);
        $body .= $this->text($narrative['notes']);
        $body .= $this->heading($sectionNumber . ' Aprovação');
        $body .= $this->keepWithNext($this->p('Responsável pela elaboração: ____________________________________'));
        $body .= $this->keepWithNext($this->p('Responsável pela validação: _____________________________________'));
        $body .= $this->p('Data: ____/____/________');
        $body = str_replace('<!-- G4F_ANA_TOC -->', $this->toc(), $body);
        $section = '<w:sectPr><w:headerReference w:type="even" r:id="rId12"/><w:headerReference w:type="default" r:id="rId12"/><w:footerReference w:type="default" r:id="rId13"/><w:footerReference w:type="even" r:id="rId13"/><w:footerReference w:type="first" r:id="rId13"/><w:headerReference w:type="first" r:id="rId12"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1660" w:right="1134" w:bottom="2000" w:left="1134" w:header="480" w:footer="500"/><w:cols w:space="708"/></w:sectPr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><w:body>' . $body . $section . '</w:body></w:document>';
    }

    private function financial(array $f): string
    {
        $xml = $this->heading('1.1 Composição dos serviços da ordem de serviço', 2);
        $rows = [];
        foreach ($f['contract_services'] ?? [] as $service) $rows[] = [(string)($service['label'] ?? $service['service'] ?? ''), 'Mensal', OfficePackage::money($service['amount'] ?? null)];
        if ($rows) {
            $compositionSum = array_sum(array_map(static fn(array $service): float => (float)($service['amount'] ?? 0), $f['contract_services'] ?? []));
            $rows[] = ['Soma dos serviços de referência', '', OfficePackage::money($compositionSum)];
            $xml .= $this->table(['Serviço', 'Métrica', 'Valor mensal'], $rows, [5500, 1700, 2300], 18);
        }
        if (trim((string)($f['order_reference'] ?? '')) !== '') $xml .= $this->p('Ordem de serviço: ' . $f['order_reference'] . '.');
        $xml .= $this->heading('1.2 Consolidação da medição', 2);
        $xml .= $this->table(['Composição financeira', 'Valor'], [
            ['Valor bruto da competência', OfficePackage::money($f['gross_value'] ?? null)],
            ['Glosa', OfficePackage::money($f['deduction_total'] ?? null)],
            ['Ajustes documentados (créditos + / débitos -)', OfficePackage::signedMoney($f['adjustment_total'] ?? null)],
            ['Valor líquido', OfficePackage::money($f['net_value'] ?? null)],
            ['Situação da medição', !empty($f['complete']) ? 'Apuração completa' : 'Apuração incompleta ou provisória'],
        ], [6500, 3000], 20);
        $xml .= $this->p('Valor líquido = valor bruto - glosa + ajustes documentados.', '', false, 18);
        if (!empty($f['adjustments'])) {
            $rows = [];
            foreach ($f['adjustments'] as $row) $rows[] = [(string)($row['description'] ?? ''), OfficePackage::signedMoney($row['amount'] ?? null), (string)($row['reference'] ?? '')];
            $xml .= $this->table(['Ajuste documentado', 'Valor assinado', 'Referência de aprovação'], $rows, [3900, 2100, 3500], 18);
        }
        if (!empty($f['point_items'])) {
            $rows = [];
            foreach ($f['point_items'] as $row) {
                $observation = ($row['status'] ?? '') === 'assessed' ? 'Apurado' : OfficePackage::status($row);
                if (str_starts_with((string)($row['metric_id'] ?? ''), 'TRS')) $observation = (string)($row['label'] ?? '') . ' ' . $observation;
                if (trim((string)($row['reference'] ?? '')) !== '') $observation .= ' Referência: ' . $row['reference'];
                $rows[] = [(string)($row['metric_id'] ?? ''), (string)($row['points'] ?? 'Pendente'), OfficePackage::money($row['amount'] ?? null), trim($observation)];
            }
            $xml .= $this->table(['IPS / ocorrência', 'Pontos', 'Valor', 'Situação'], $rows, [1300, 1200, 2200, 4800], 18);
        }
        foreach (OfficePackage::financialNotes($f) as $warning) $xml .= $this->p($warning, '', false, 18);
        return $xml;
    }

    private function periodLabel(array $snapshot): string
    {
        $month = (string)($snapshot['period']['month'] ?? '');
        $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $month, $parts)) return $months[(int)$parts[2]] . ' de ' . $parts[1];
        return (string)($snapshot['period']['label'] ?? $month);
    }

    private function methodology(array $snapshot): array
    {
        $rows = [];
        foreach (OfficePackage::methodology($snapshot) as [$label, $value]) {
            if (trim($value) === '' || in_array($label, ['Versão das regras', 'Extração'], true)) continue;
            if ($label === 'Competência') $value = $this->periodLabel($snapshot);
            if ($label === 'Metodologia confirmada') $label = 'Validação dos critérios';
            $rows[] = [$label, $value];
        }
        $cap = $snapshot['priority_cap'] ?? [];
        if (!empty($cap['excess'])) {
            $rows[] = ['Chamados P1 e P2', OfficePackage::number($cap['high_priority_count'] ?? null) . ' de ' . OfficePackage::number($cap['population'] ?? null)];
            $rows[] = ['Limite de P1 e P2', OfficePackage::number($cap['maximum'] ?? null) . ' chamados'];
            $rows[] = ['Classificação em revisão', OfficePackage::number($cap['excess']) . ' chamados excedentes em P1 e P2'];
        }
        return $rows;
    }

    private function letterheadPart(bool $footer): string
    {
        $tag = $footer ? 'ftr' : 'hdr';
        $height = $footer ? 833000 : 729000;
        $crop = $footer ? 'l="9000" t="88500" r="9000" b="3500"' : 'l="9000" t="3000" r="9000" b="90000"';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:' . $tag . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/><w:jc w:val="center"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="6032500" cy="' . $height . '"/><wp:docPr id="' . ($footer ? 9102 : 9101) . '" name="Papel timbrado G4F"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Arte original G4F"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rId1"/><a:srcRect ' . $crop . '/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="6032500" cy="' . $height . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:' . $tag . '>';
    }
    private function keepWithNext(string $xml): string
    {
        return preg_replace_callback('/<w:pPr>(.*?)<\/w:pPr>/s', static function (array $match): string {
            return strpos($match[1], '<w:keepNext') !== false ? $match[0] : '<w:pPr><w:keepNext/>' . $match[1] . '</w:pPr>';
        }, $xml);
    }

    private function figure(array $definition, int $height, bool $showNote = true): string
    {
        if (empty($definition['available'])) {
            return '';
        }
        // A chart ends its visual block. Keeping it with the following heading
        // would join every indicator into one unbreakable pagination chain.
        $xml = str_replace('<w:keepNext/>', '', $this->charts->paragraph($definition, $height));
        return $xml . ($showNote ? $this->p((string)($definition['note'] ?? ''), '', false, 19) : '');
    }


    private function heatmap(array $definition): string
    {
        if (empty($definition['available'])) {
            return '';
        }
        $maximum = max(1, (int)$definition['maximum']);
        $xml = $this->p('Volume representado: ' . OfficePackage::number($definition['total']) . '.', '', false, 20);
        $xml .= '<w:tbl><w:tblPr><w:tblW w:w="9500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $xml .= '<w:' . $edge . ' w:val="single" w:sz="4" w:color="' . ReportStyle::BORDER . '"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="50" w:type="dxa"/><w:left w:w="60" w:type="dxa"/><w:bottom w:w="50" w:type="dxa"/><w:right w:w="60" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="1100"/>' . str_repeat('<w:gridCol w:w="1200"/>', 7) . '</w:tblGrid>';
        $rows = array_merge([array_merge(['Hora'], $definition['column_labels'])], array_map(static function ($label, $values) { return array_merge([$label], $values); }, $definition['row_labels'], $definition['values']));
        $totals = ['Total geral'];
        foreach ($definition['column_labels'] as $weekday => $label) $totals[] = array_sum(array_column($definition['values'], $weekday));
        $rows[] = $totals;
        foreach ($rows as $rowIndex => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($rowIndex === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            foreach ($row as $columnIndex => $value) {
                $heading = $rowIndex === 0 || $columnIndex === 0 || $rowIndex === count($rows) - 1;
                $ratio = $heading ? 0 : (int)$value / $maximum;
                $fill = $heading ? ReportStyle::NAVY : ReportStyle::heatmapColor($ratio);
                $color = $heading ? ReportStyle::WHITE : ReportStyle::NAVY;
                $text = $heading ? (string)$value : OfficePackage::number((int)$value);
                $paragraph = str_replace('w:after="140" w:line="270"', 'w:after="0" w:line="210"', $this->p($text, '', $heading, 19, 'center', $color));
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . ($columnIndex === 0 ? '1100' : '1200') . '" w:type="dxa"/><w:shd w:val="clear" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr>' . $paragraph . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl>' . $this->p('Volume acumulado no mês. Horário de Brasília. Escala: 0 a ' . OfficePackage::number($maximum) . '.', '', false, 18);
        if (!empty($definition['omitted'])) $xml .= $this->p('Registros sem data válida no mês: ' . OfficePackage::number($definition['omitted']) . '.', '', false, 18);
        return $xml;
    }

    private function text(string $text): string
    {
        $xml = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) !== '') {
                $xml .= $this->p($line);
            }
        }
        return $xml;
    }

    private function heading(string $text, int $level = 1): string
    {
        // Each real chapter becomes a bookmark. The TOC is generated from this
        // exact list, so optional sections cannot be listed if they were omitted.
        $index = count($this->tocEntries) + 1;
        $bookmark = 'G4F_ANA_TOC_' . $index;
        $this->tocEntries[] = ['level' => $level, 'title' => $text, 'bookmark' => $bookmark, 'id' => $index];
        $paragraph = $this->p($text, 'Heading' . $level, true, $level === 1 ? 28 : 23, 'left');
        $paragraph = preg_replace('~</w:pPr>~', '</w:pPr><w:bookmarkStart w:id="' . $index . '" w:name="' . $bookmark . '"/>', $paragraph, 1);
        return substr($paragraph, 0, -6) . '<w:bookmarkEnd w:id="' . $index . '"/></w:p>';
    }

    /** A Word-native TOC field with a readable cached outline and PAGEREF fields. */
    private function toc(): string
    {
        $xml = $this->p('SUMÁRIO', 'Title', true, 32, 'center');
        $xml .= '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr>'
            . '<w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
            . '<w:r><w:instrText xml:space="preserve"> TOC \o "1-2" \h \z \u </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r></w:p>';
        foreach ($this->tocEntries as $entry) {
            $level = (int)$entry['level'];
            $bookmark = $entry['bookmark'];
            $xml .= '<w:p><w:pPr><w:pStyle w:val="TOC' . $level . '"/>'
                . '<w:tabs><w:tab w:val="right" w:leader="dot" w:pos="9500"/></w:tabs>'
                . '<w:ind w:left="' . ($level === 1 ? '0' : '320') . '"/>'
                . '<w:spacing w:before="0" w:after="0" w:line="230" w:lineRule="auto"/>'
                . '</w:pPr><w:hyperlink w:anchor="' . $bookmark . '" w:history="1">'
                . '<w:r><w:t xml:space="preserve">' . OfficePackage::xml($entry['title']) . '</w:t></w:r>'
                . '</w:hyperlink><w:r><w:tab/></w:r>'
                . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                . '<w:r><w:instrText xml:space="preserve"> PAGEREF ' . $bookmark . ' \h </w:instrText></w:r>'
                . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                . '<w:r><w:t>—</w:t></w:r>'
                . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
        }
        return $xml . '<w:p><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
    }

    /** Let Word refresh TOC and page fields when the exported document is opened. */
    private function updateFieldSettings(?string $xml): string
    {
        if (!is_string($xml) || strpos($xml, '<w:settings') === false) {
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:updateFields w:val="true"/></w:settings>';
        }
        if (preg_match('~<w:updateFields\b[^>]*/>~', $xml)) {
            return preg_replace('~<w:updateFields\b[^>]*/>~', '<w:updateFields w:val="true"/>', $xml, 1);
        }
        return str_replace('</w:settings>', '<w:updateFields w:val="true"/></w:settings>', $xml);
    }

    private function p(string $text, string $style = '', bool $bold = false, int $size = 21, string $align = 'both', string $color = ReportStyle::NAVY): string
    {
        return '<w:p><w:pPr>' . ($style !== '' ? '<w:pStyle w:val="' . $style . '"/>' : '') . '<w:jc w:val="' . $align . '"/><w:widowControl/><w:spacing w:after="140" w:line="270" w:lineRule="auto"/>' . ($style === 'Heading1' || $style === 'Heading2' ? '<w:keepNext/>' : '') . '</w:pPr><w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>' . ($bold ? '<w:b/>' : '') . '<w:color w:val="' . ($style !== '' ? ReportStyle::NAVY : $color) . '"/><w:sz w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . OfficePackage::xml($text) . '</w:t></w:r></w:p>';
    }

    private function page(): string
    {
        return '<w:p><w:pPr><w:pageBreakBefore/><w:spacing w:after="0" w:before="0"/></w:pPr></w:p>';
    }

    private function table(array $headers, array $rows, array $widths, int $size = 20, array $violatedRows = [], string $align = 'left', bool $keepTogether = false): string
    {
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9500" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $edge) {
            $xml .= '<w:' . $edge . ' w:val="single" w:sz="4" w:color="' . ReportStyle::BORDER . '"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:top w:w="65" w:type="dxa"/><w:left w:w="95" w:type="dxa"/><w:bottom w:w="65" w:type="dxa"/><w:right w:w="95" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($widths as $width) {
            $xml .= '<w:gridCol w:w="' . $width . '"/>';
        }
        $xml .= '</w:tblGrid>';
        foreach (array_merge([$headers], $rows) as $i => $row) {
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($i === 0 ? '<w:tblHeader/>' : '') . '</w:trPr>';
            $violated = $i > 0 && ($violatedRows[$i - 1] ?? false);
            foreach ($row as $j => $cell) {
                $total = $i > 0 && strpos((string)($row[0] ?? ''), 'Total ') === 0;
                $fill = $i === 0 ? ReportStyle::NAVY : ($total || $i % 2 === 0 ? ReportStyle::POSITIVE_FILL : ReportStyle::WHITE);
                $color = $i === 0 ? ReportStyle::WHITE : ($violated ? self::VIOLATION_TEXT : ReportStyle::NAVY);
                $bold = $i === 0 || $total || ($violated && $j === count($row) - 1);
                $cellParagraph = str_replace('w:after="140" w:line="270"', 'w:after="20" w:line="220"', $this->p((string)$cell, '', $bold, $size, $align, $color));
                if (($keepTogether || count($rows) <= 5) && $i < count($rows)) $cellParagraph = $this->keepWithNext($cellParagraph);
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$j] . '" w:type="dxa"/><w:shd w:val="clear" w:fill="' . $fill . '"/><w:vAlign w:val="center"/></w:tcPr>' . $cellParagraph . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="80"/></w:pPr></w:p>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="21"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/><w:sz w:val="40"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="180"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="140"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:color w:val="' . ReportStyle::NAVY . '"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="20"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="Normal"/><w:rPr><w:sz w:val="19"/></w:rPr></w:style></w:styles>';
    }
}
