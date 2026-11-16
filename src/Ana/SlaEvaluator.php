<?php
namespace GlpiPlugin\G4freportsana\Ana;

/** ANA clocks. Native GLPI counters remain evidence, never an implicit contractual clock. */
final class SlaEvaluator
{
    private array $context;
    private array $input;
    public function __construct(array $context, array $input) { $this->context = $context; $this->input = $input; }

    public function evaluate(array $ticket): array
    {
        $nativeTit = self::seconds($ticket['takeintoaccount_seconds'] ?? null);
        $nativeTms = self::seconds($ticket['solve_seconds'] ?? null);
        $mode = $this->input['clock_mode'] ?? 'native_reference';
        $result = ['native_tit_seconds' => $nativeTit, 'native_tms_seconds' => $nativeTms,
            'tit_seconds' => $nativeTit, 'tms_seconds' => $nativeTms, 'clock_mode' => $mode,
            'clock_method' => 'Contadores nativos GLPI; sem substituição de zero e sem novo desconto de pendências.',
            'clock_evidence_complete' => $nativeTit !== null && $nativeTms !== null,
            'clock_issues' => [], 'approved_pause_seconds' => 0, 'tit_approved_pause_seconds' => 0,
            'contract_start_epoch' => null, 'contract_end_epoch' => null,
            'sla_provisional' => !self::confirmed($this->input)];
        if ($mode !== 'contractual') return $result;
        $start = self::epoch($ticket['first_responsible_epoch'] ?? null);
        $open = self::epoch($ticket['opened_epoch'] ?? null);
        $endpoint = ($this->input['tms_endpoint'] ?? 'closed') === 'solved' ? 'solved_epoch' : 'closed_epoch';
        $end = self::epoch($ticket[$endpoint] ?? null);
        $result['contract_start_epoch'] = $start;
        $result['contract_end_epoch'] = $end;
        $result['tit_seconds'] = $result['tms_seconds'] = null;
        $result['clock_method'] = 'TIT: registro → primeira atribuição responsável (tempo corrido). TMS: primeira atribuição responsável → '
            . ($endpoint === 'closed_epoch' ? 'fechamento' : 'solução') . '; sem reinício por escalonamento; calendário '
            . (($this->input['calendar_mode'] ?? 'onsite') === 'onsite' ? 'presencial, dias úteis 08:00–20:00' : 'corrido')
            . '; somente intervalos de suspensão com referência de aprovação.';
        if ($start === null || $open === null || $start < $open) $result['clock_issues'][] = 'Atribuição inicial responsável ausente ou anterior ao registro.';
        if ($start !== null && $end !== null && $end >= $start) {
            $gross = $this->activeSeconds($start, $end, ($this->input['calendar_mode'] ?? 'onsite') === 'onsite');
            [$pause, $issues] = $this->pauses((int)$ticket['id'], $start, $end, ($this->input['calendar_mode'] ?? 'onsite') === 'onsite');
            $result['tms_seconds'] = max(0, $gross - $pause);
            $result['approved_pause_seconds'] = $pause;
            $result['clock_issues'] = array_merge($result['clock_issues'], $issues);
        } else $result['clock_issues'][] = 'Marco final contratual ausente ou anterior à atribuição responsável.';
        if ($open !== null && $start !== null && $start >= $open) {
            [$pause, $issues] = $this->pauses((int)$ticket['id'], $open, $start, false);
            $result['tit_seconds'] = max(0, $start - $open - $pause);
            $result['tit_approved_pause_seconds'] = $pause;
            $result['clock_issues'] = array_merge($result['clock_issues'], $issues);
        }
        if (empty($ticket['group_history_complete'] ?? $ticket['history_complete'] ?? false)) {
            $result['clock_issues'][] = 'Histórico de atribuição não integralmente comprovado.';
        }
        if (($this->input['calendar_mode'] ?? 'onsite') === 'onsite' && (!array_key_exists('contractual_holidays', $this->input) || trim((string)($this->input['holiday_reference'] ?? '')) === '')) {
            $result['clock_issues'][] = 'Calendário contratual de feriados não informado; candidato calculado apenas com fins de semana.';
        }
        $result['clock_issues'] = array_values(array_unique($result['clock_issues']));
        $result['clock_evidence_complete'] = $result['tit_seconds'] !== null && $result['tms_seconds'] !== null && !$result['clock_issues'];
        $result['sla_provisional'] = !self::confirmed($this->input) || !$result['clock_evidence_complete'];
        return $result;
    }

    public static function confirmed(array $input): bool
    { return !empty($input['methodology_confirmed']) && trim((string)($input['methodology_reference'] ?? '')) !== ''; }
    public static function seconds($value): ?int
    { return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? (int)$value : null; }
    private static function epoch($value): ?int
    { return is_numeric($value) && (int)$value > 0 ? (int)$value : null; }

    /** Intervals are clipped and merged before counting, so overlap is never subtracted twice. */
    private function pauses(int $ticketId, int $start, int $end, bool $onsite): array
    {
        $intervals = []; $issues = [];
        foreach ($this->input['approved_pauses'] ?? [] as $pause) {
            if (!is_array($pause) || (int)($pause['ticket_id'] ?? 0) !== $ticketId) continue;
            $a = $this->parseTime($pause['start_epoch'] ?? $pause['start'] ?? null);
            $b = $this->parseTime($pause['end_epoch'] ?? $pause['end'] ?? null);
            if ($a === null || $b === null || $b <= $a || trim((string)($pause['approval_reference'] ?? '')) === '') {
                $issues[] = 'Suspensão não descontada: intervalo inválido ou aprovação ausente.'; continue;
            }
            $a = max($a, $start); $b = min($b, $end);
            if ($b > $a) $intervals[] = [$a, $b];
        }
        usort($intervals, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as $p) {
            $i = count($merged) - 1;
            if ($i >= 0 && $p[0] <= $merged[$i][1]) $merged[$i][1] = max($merged[$i][1], $p[1]);
            else $merged[] = $p;
        }
        $total = 0;
        foreach ($merged as [$a, $b]) $total += $this->activeSeconds($a, $b, $onsite);
        return [$total, $issues];
    }
    private function parseTime($value): ?int
    {
        if (is_numeric($value)) return self::epoch($value);
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) return null;
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone($this->input['timezone'] ?? 'America/Sao_Paulo'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
            return $date->getTimestamp();
        }
        catch (\Exception $e) { return null; }
    }
    private function activeSeconds(int $start, int $end, bool $onsite): int
    {
        if ($end <= $start) return 0;
        if (!$onsite) return $end - $start;
        $tz = new \DateTimeZone($this->input['timezone'] ?? 'America/Sao_Paulo');
        $day = (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->setTime(0, 0);
        $holidays = array_fill_keys($this->input['contractual_holidays'] ?? [], true);
        $seconds = 0;
        // Dates beyond the supported reporting range cannot cause unbounded iteration.
        if ($end - $start > 20 * 366 * 86400) throw new \RuntimeException('Intervalo contratual superior a 20 anos; revisar dados do chamado.');
        while ($day->getTimestamp() < $end) {
            if ((int)$day->format('N') <= 5 && !isset($holidays[$day->format('Y-m-d')])) {
                $a = max($start, $day->setTime(8, 0)->getTimestamp());
                $b = min($end, $day->setTime(20, 0)->getTimestamp());
                $seconds += max(0, $b - $a);
            }
            $day = $day->modify('+1 day');
        }
        return $seconds;
    }
}
