<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** Temporal volumes from the preserved extraction; never changes indicator populations. */
class HeatmapData
{
    public static function build(array $snapshot): array
    {
        $month = $snapshot['period']['month'] ?? '';
        $dateKey = ($snapshot['input']['scope_basis'] ?? 'closed') === 'solved' ? 'solved_at' : 'closed_at';
        return [
            'tickets_solution' => self::matrix('tickets_solution', 'Chamados por dia da semana e hora de ' . ($dateKey === 'solved_at' ? 'solução' : 'fechamento'),
                $snapshot['tickets'] ?? [], $dateKey, 'tickets', $month,
                $dateKey === 'solved_at' ? 'Soluções no período.' : 'Fechamentos no período.'),
            'calls_entry' => self::matrix('calls_entry', 'Ligações por dia da semana e hora de entrada',
                $snapshot['calls'] ?? [], 'entered_at', 'calls', $month,
                'Entrada das ligações consideradas no período.'),
        ];
    }

    /** Month length for the same report range accepted by Settings; zero means invalid. */
    public static function daysInMonth($month): int
    {
        if (!is_string($month) || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)) {
            return 0;
        }
        return (int)(new \DateTimeImmutable($month . '-01 12:00:00', new \DateTimeZone('America/Sao_Paulo')))->format('t');
    }

    /**
     * Strict SQL local timestamp, including optional MySQL microseconds.
     * No timezone conversion: day/hour are taken from the recorded wall time.
     * Relative strings, offsets, impossible dates and dates outside the month are rejected.
     */
    public static function timestampParts($value, $month): ?array
    {
        if (!is_string($value) || !is_string($month)
            || !preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/D', $month)
            || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) return null;
        try {
            $time = new \DateTimeImmutable($value, new \DateTimeZone('America/Sao_Paulo'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
            $value = $time->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) { return null; }
        if (substr($value, 0, 7) !== $month
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $parts)) return null;
        $year = (int)$parts[1];
        $monthNumber = (int)$parts[2];
        $day = (int)$parts[3];
        $hour = (int)$parts[4];
        if (!checkdate($monthNumber, $day, $year) || $hour > 23 || (int)$parts[5] > 59 || (int)$parts[6] > 59) {
            return null;
        }
        // Noon avoids a local daylight-saving boundary when calculating the weekday.
        $date = new \DateTimeImmutable(substr($value, 0, 10) . ' 12:00:00', new \DateTimeZone('America/Sao_Paulo'));
        return ['day' => $day, 'hour' => $hour, 'weekday' => (int)$date->format('N') - 1];
    }

    private static function matrix(string $id, string $title, array $rows, string $dateKey, string $source, $month, string $basis): array
    {
        $values = array_fill(0, 24, array_fill(0, 7, 0));
        $total = 0;
        $omitted = 0;
        $maximum = 0;
        foreach ($rows as $row) {
            $parts = self::timestampParts($row[$dateKey] ?? null, $month);
            if ($parts === null) {
                $omitted++;
                continue;
            }
            $count = ++$values[$parts['hour']][$parts['weekday']];
            $maximum = max($maximum, $count);
            $total++;
        }
        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hours[] = sprintf('%02dh', $hour);
        }
        $note = $basis . ' Horário de Brasília. Total: ' . $total . '.';
        if ($omitted > 0) $note .= ' Registros sem data válida no período: ' . $omitted . '.';
        return ['id' => $id, 'title' => $title, 'row_labels' => $hours,
            'column_labels' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], 'values' => $values,
            'available' => $total > 0, 'total' => $total, 'omitted' => $omitted, 'maximum' => $maximum,
            'note' => $note, 'date_key' => $dateKey, 'source' => $source];
    }
}
