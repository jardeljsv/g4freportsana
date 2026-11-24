<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Small OOXML package helper; no ZipArchive or Composer dependency. */
final class OfficePackage
{
    public static function xml($text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$text);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function read(string $binary): array
    {
        $end = strrpos($binary, "PK\x05\x06");
        if ($end === false || strlen($binary) < $end + 22) {
            throw new \RuntimeException('O modelo de documento não é um arquivo OOXML válido.');
        }
        $e = unpack('Vsignature/vdisk/vstart/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($binary, $end, 22));
        $offset = $e['offset'];
        $files = [];
        for ($i = 0; $i < $e['entries']; $i++) {
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($binary, $offset, 46));
            if ($h['sig'] !== 0x02014b50) {
                throw new \RuntimeException('O modelo OOXML está incompleto.');
            }
            $name = substr($binary, $offset + 46, $h['name']);
            $local = unpack('Vsig/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr($binary, $h['offset'], 30));
            $data = substr($binary, $h['offset'] + 30 + $local['name'] + $local['extra'], $h['compressed']);
            if (substr($name, -1) !== '/') {
                if ($h['method'] === 8) {
                    $data = @gzinflate($data);
                } elseif ($h['method'] !== 0) {
                    $data = false;
                }
                if (!is_string($data) || strlen($data) !== $h['size']) {
                    throw new \RuntimeException('Não foi possível ler o modelo de documento.');
                }
                $files[$name] = $data;
            }
            $offset += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
        return $files;
    }

    /** @param array<string,string|\Closure|array{deflated:string,size:int,crc:int}> $files */
    public static function write(array $files): string
    {
        $data = '';
        $central = '';
        foreach ($files as $name => $content) {
            if ($content instanceof \Closure) {
                $content = $content();
            }
            if (is_array($content) && isset($content['deflated'], $content['size'], $content['crc'])) {
                // Large worksheets are already compressed incrementally. Their
                // verified CRC and original length let the ZIP writer avoid
                // inflating the complete worksheet again in PHP memory.
                $packed = $content['deflated'];
                $size = $content['size'];
                $crc = $content['crc'];
                if (!is_string($packed) || !is_int($size) || $size < 0 || !is_int($crc)) {
                    throw new \RuntimeException('Componente compactado inválido no relatório.');
                }
            } else {
                $content = (string)$content;
                $size = strlen($content);
                $packed = gzdeflate($content, 6);
                if (!is_string($packed)) {
                    throw new \RuntimeException('Não foi possível compactar o relatório.');
                }
                $crc = crc32($content);
            }
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x800, 8, 0, 33, $crc, strlen($packed), $size, strlen($name), 0) . $name . $packed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x800, 8, 0, 33, $crc, strlen($packed), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    public static function status(array $metric): string
    {
        return ['met' => 'Meta atingida', 'unmet' => 'Meta não atingida', 'pending' => 'Pendente de informação', 'not_applicable' => 'Não aplicável', 'provisional' => 'Apurado', 'no_occurrences' => 'Sem ocorrências'][$metric['status'] ?? 'pending'] ?? 'Pendente de informação';
    }

    /** Typed presentation: a score is never multiplied by 100 or shown as a percentage. */
    public static function metricValue(array $metric, string $key = 'value'): string
    {
        $value = $metric[$key] ?? null;
        if ($value === null) {
            return ($metric['value_kind'] ?? '') === 'priority_percent_ratios' ? 'Por prioridade' : ($key === 'value' ? self::status($metric) : 'Conforme escopo');
        }
        if (($metric['unit'] ?? '') === 'score' || ($metric['value_kind'] ?? '') === 'mean_score') return number_format((float)$value, 2, ',', '.') . ' / 5';
        if (($metric['unit'] ?? '') === 'compliance' || ($metric['value_kind'] ?? '') === 'compliance') return $value ? 'Atendido' : 'Não atendido';
        return self::percent($value);
    }

    public static function metricRows(array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['metrics'] ?? [] as $metric) {
            $rows[] = $metric;
            if (($metric['value_kind'] ?? '') === 'priority_percent_ratios') foreach ($metric['children'] ?? [] as $child) $rows[] = $child;
        }
        return $rows;
    }

    public static function money($value): string
    {
        return $value === null ? 'Não apurado' : 'R$ ' . number_format((float)$value, 2, ',', '.');
    }

    /** Presentation only: older snapshots retain their original calculations. */
    public static function financialNotes(array $financial): array
    {
        $rows = [];
        $legacy = [
            'Base financeira da competência não definida:' => 'OS, vigência ou valor mensal da competência não definidos.',
            'A base e as decisões financeiras da competência ainda' => 'Aprovação financeira da competência não registrada.',
            'A soma dos serviços difere do total da OS;' => 'Soma dos serviços diferente do valor total da OS.',
            'PAO exige referência aprovada' => 'Vigência ou aprovação do PAO não registrada para a competência.',
            'Confirme a ordem de aplicação' => 'Ordem de aplicação do limite e do redutor PAO não definida.',
            'Glosa com fração de centavo:' => 'Regra de arredondamento monetário não definida para a glosa fracionária.',
        ];
        foreach ($financial['notes'] ?? [] as $note) {
            $note = trim((string)$note);
            if ($note === '' || str_starts_with($note, 'As linhas monetárias representam')
                || str_starts_with($note, 'Composição financeira por serviços da OS.')) continue;
            // These states already appear on each point line.
            if (preg_match('/^IPS\d+(?:_P[123])?(?:, IPS\d+(?:_P[123])?)*: (?:Resultado provisório:.*|Apuração provisória\.|Resultado não apurado ou sem evidência suficiente\.)$/u', $note)) continue;
            foreach ($legacy as $prefix => $text) if (str_starts_with($note, $prefix)) { $note = $text; break; }
            $note = str_replace([
                'Déficit fracionário: confirme tratamento proporcional, piso ou teto e a referência aprovada.',
                'IPS9 não atendido: informe pontos do cronograma acordado e referência de aprovação.',
                'Tipo do indicador incompatível com a regra ANA; médias não são percentuais.',
            ], [
                'Regra para frações de pontos não definida.',
                'Pontuação do IPS9 sem definição ou aprovação registrada.',
                'Tipo de medição incompatível com o indicador.',
            ], $note);
            $rows[] = $note;
        }
        return array_values(array_unique($rows));
    }

    public static function signedMoney($value): string
    {
        if ($value === null) return self::money(null);
        $number = (float)$value;
        return ($number > 0 ? '+ ' : ($number < 0 ? '- ' : '')) . self::money(abs($number));
    }

    public static function belowTarget(array $metric): bool
    {
        return isset($metric['value'], $metric['target']) && is_numeric($metric['value']) && is_numeric($metric['target'])
            && (float)$metric['value'] < (float)$metric['target'];
    }

    public static function phoneTotals(array $snapshot): array
    {
        $totals = $snapshot['totals'] ?? [];
        $metric = [];
        foreach ($snapshot['metrics'] ?? [] as $candidate) if (($candidate['id'] ?? '') === 'IPS6') $metric = $candidate;
        if (!empty($metric['evidence_reference'])) {
            $source = $snapshot['external_evidence']['phone'] ?? $snapshot['input']['external_evidence']['phone'] ?? $snapshot['manual']['phone'] ?? [];
            // The eligible IPS6 denominator can exclude desistências; it is
            // not the raw number forwarded by the network. Never mix these
            // totals with abandonment or duration statistics from the queues.
            $totals['calls_received'] = $source['forwarded_calls'] ?? $totals['calls_network_forwarded']
                ?? (($metric['desistencias_included'] ?? $snapshot['input']['ips6_include_desistencias'] ?? true) ? ($metric['denominator'] ?? null) : null);
            $totals['calls_answered'] = $source['answered_calls'] ?? $totals['calls_network_answered'] ?? $metric['numerator'] ?? null;
            $totals['calls_desisted'] = $source['desisted_calls'] ?? null;
            foreach (['calls_abandoned', 'calls_abandon_wait_unknown', 'mean_talk_seconds', 'mean_wait_seconds'] as $key) $totals[$key] = null;
        } elseif (!isset($metric['denominator']) && empty($totals['calls_available'])) {
            foreach (['calls_received', 'calls_answered', 'calls_abandoned', 'calls_desisted', 'calls_abandon_wait_unknown', 'mean_talk_seconds', 'mean_wait_seconds'] as $key) $totals[$key] = null;
        }
        return $totals;
    }

    /** Report-specific text takes precedence; an empty topic uses its saved monthly record. */
    public static function narrativeTexts(array $snapshot): array
    {
        $input = $snapshot['input'] ?? [];
        $manual = $snapshot['manual'] ?? [];
        $topics = $snapshot['external_evidence']['topics'] ?? $input['external_evidence']['topics'] ?? $manual['topics'] ?? [];
        $topicFields = ['availability_notes' => 'availability', 'siem_notes' => 'siem', 'rdm_notes' => 'rdm',
            'vip_notes' => 'vip', 'trs_notes' => 'trs', 'improvement_notes' => 'improvements'];
        $texts = [];
        foreach (['report_title', 'executive_summary', 'actions', 'notes', 'phone_survey_actions',
            'availability_notes', 'siem_notes', 'rdm_notes', 'vip_notes', 'trs_notes', 'improvement_notes'] as $field) {
            $text = trim((string)($manual[$field] ?? $input[$field] ?? ''));
            if ($text === '' && isset($topicFields[$field])) $text = trim((string)($topics[$topicFields[$field]] ?? ''));
            $texts[$field] = $text;
        }
        return $texts;
    }

    /** Explicit selection provenance travels with both exports. */
    public static function methodology(array $snapshot): array
    {
        $input = $snapshot['input'] ?? [];
        $scope = ['closed' => 'Fechamento no período, inclusive chamados abertos anteriormente', 'solved' => 'Solução no período, inclusive chamados abertos anteriormente', 'reference' => 'Comparação histórica: abertura UTC e fechamento no período'];
        $responsibility = ['solution' => 'Grupos responsáveis na solução', 'closure' => 'Grupos responsáveis no fechamento', 'current' => 'Atribuição atual na extração'];
        return [
            ['Competência', (string)($snapshot['period']['label'] ?? $snapshot['period']['month'] ?? '')],
            ['Fuso horário', (string)($input['timezone'] ?? 'America/Sao_Paulo')],
            ['Seleção de chamados', $scope[$input['scope_basis'] ?? 'closed'] ?? (string)$input['scope_basis']],
            ['Responsabilidade', $responsibility[$input['responsibility_basis'] ?? 'solution'] ?? (string)$input['responsibility_basis']],
            ['Relógio de medição', ($input['clock_mode'] ?? 'native_reference') === 'contractual' ? 'Contratual com eventos e suspensões aprovadas' : 'Contadores nativos GLPI para reconciliação'],
            ['Marco final de TMS', ($input['tms_endpoint'] ?? 'closed') === 'solved' ? 'Solução' : 'Fechamento'],
            ['Calendário contratual', ($input['calendar_mode'] ?? 'onsite') === 'elapsed' ? 'Tempo corrido' : 'Janela presencial'],
            ['Corte das pesquisas e reaberturas', ($input['survey_cutoff'] ?? 'period_end') === 'extraction' ? 'Data da extração' : 'Final da competência'],
            ['Eventos de reabertura', ($input['reopening_mode'] ?? 'solved_or_closed') === 'closed_only' ? 'Após fechamento' : 'Após solução ou fechamento'],
            ['Filas telefônicas', implode(', ', (array)($input['queue_names'] ?? ['ana', 'ana-transbordo']))],
            ['Unidade telefônica', ($input['call_unit'] ?? 'interaction') === 'queue_leg' ? 'Passagem por fila' : 'Interação identificada'],
            ['Desistências no IPS6 (espera inferior a 30 segundos)', ($input['ips6_include_desistencias'] ?? true) ? 'Incluídas' : 'Excluídas'],
            ['Metodologia confirmada', !empty($input['methodology_confirmed']) ? 'Sim' : 'Pendente de confirmação'],
            ['Referência da metodologia', (string)($input['methodology_reference'] ?? '')],
            ['Versão das regras', (string)($snapshot['rules_version'] ?? '')],
            ['Extração', self::date($snapshot['generated_at'] ?? '')],
        ];
    }

    public static function percent($number): string
    {
        return $number === null ? 'Não apurado' : number_format((float)$number, 2, ',', '.') . '%';
    }

    public static function number($number): string
    {
        return $number === null ? 'Não informado' : number_format((float)$number, 0, ',', '.');
    }

    /** Names belong to the reporting source at extraction time, never the hosting GLPI. */
    public static function selectionRows(array $snapshot, string $kind, array $ids): array
    {
        $catalog = $snapshot['selection_catalog'][$kind] ?? [];
        $rows = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            $name = (string)($catalog[$id]['name'] ?? '');
            $rows[] = [(string)$id, $name !== '' ? $name : 'Nome não registrado nesta extração'];
        }
        return $rows;
    }

    public static function selectionLabels(array $snapshot, string $kind, array $ids, string $empty): string
    {
        $labels = [];
        foreach (self::selectionRows($snapshot, $kind, $ids) as $row) {
            $labels[] = $row[0] . ' — ' . $row[1];
        }
        return $labels ? implode('; ', $labels) : $empty;
    }

    public static function kbCriterion(array $snapshot): string
    {
        return ($snapshot['input']['kb_mode'] ?? '') === 'category_proxy'
            ? 'Associação da categoria do chamado à base de conhecimento'
            : 'Vínculo direto do chamado a artigo previamente validado';
    }

    public static function duration($seconds): string
    {
        if ($seconds === null) {
            return 'Não apurado';
        }
        $seconds = (int)round($seconds);
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public static function date($value): string
    {
        $value = (string)$value;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1] . str_replace('T', ' ', $m[4]);
        }
        return $value;
    }
}
