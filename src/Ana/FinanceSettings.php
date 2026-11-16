<?php

namespace GlpiPlugin\G4freportsana\Ana;

/** ANA service-order baseline and separately approved, versioned monthly decisions. */
final class FinanceSettings
{
    public const CONFIG_KEY = 'ana_financial_json';

    public static function fromSettings(array $settings): array
    {
        return self::normalize($settings[self::CONFIG_KEY] ?? []);
    }

    public static function defaultBaseline(): array
    {
        return [
            'order_reference' => 'OS 002/2025 — SEI 0162759, assinada em 27/02/2026',
            'effective_from' => '2026-03-01', 'effective_to' => '2027-04-03',
            'monthly_value' => '340610.90',
            'services' => [
                ['id' => 'suporte', 'label' => 'Suporte N1/N2', 'amount' => '64267.45'],
                ['id' => 'aplicacoes', 'label' => 'Aplicações e bancos de dados', 'amount' => '97775.83'],
                ['id' => 'infraestrutura', 'label' => 'Serviços de infraestrutura', 'amount' => '87491.54'],
                ['id' => 'melhoria', 'label' => 'Melhoria contínua', 'amount' => '0.00'],
                ['id' => 'seguranca', 'label' => 'Segurança', 'amount' => '61935.20'],
                ['id' => 'monitoramento', 'label' => 'Monitoramento', 'amount' => '29140.88'],
                ['id' => 'siem', 'label' => 'SIEM', 'amount' => '0.00'],
            ],
        ];
    }

    public static function normalize($raw): array
    {
        $raw = self::object($raw, 'Configuração financeira');
        $baseline = $raw['baseline'] ?? self::defaultBaseline();
        if (!is_array($baseline)) throw new \InvalidArgumentException('Base contratual inválida.');
        $normalizedBaseline = [
            'order_reference' => self::text($baseline['order_reference'] ?? '', 1000),
            'effective_from' => self::date($baseline['effective_from'] ?? ''),
            'effective_to' => self::date($baseline['effective_to'] ?? ''),
            'monthly_value' => self::money($baseline['monthly_value'] ?? '0', false),
            'services' => [],
        ];
        if ($normalizedBaseline['effective_from'] !== '' && $normalizedBaseline['effective_to'] !== ''
            && $normalizedBaseline['effective_from'] > $normalizedBaseline['effective_to']) {
            throw new \InvalidArgumentException('Vigência financeira invertida.');
        }
        $ids = [];
        foreach (self::rows($baseline['services'] ?? [], 50, 'Serviços contratuais') as $row) {
            $id = self::text($row['id'] ?? '', 64);
            if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) || isset($ids[$id])) {
                throw new \InvalidArgumentException('Identificador de serviço inválido ou repetido.');
            }
            $ids[$id] = true;
            $normalizedBaseline['services'][] = ['id' => $id,
                'label' => self::text($row['label'] ?? '', 300),
                'amount' => self::money($row['amount'] ?? '0', false)];
        }
        $months = $raw['months'] ?? [];
        if (!is_array($months) || count($months) > 240) throw new \InvalidArgumentException('Competências financeiras inválidas.');
        $result = ['baseline' => $normalizedBaseline, 'months' => []];
        foreach ($months as $month => $row) {
            self::month((string)$month);
            if (!is_array($row)) throw new \InvalidArgumentException('Competência financeira inválida.');
            $entry = [
                'order_reference' => self::text($row['order_reference'] ?? '', 1000),
                'gross_value' => ($row['gross_value'] ?? '') === '' || ($row['gross_value'] ?? null) === null
                    ? null : self::money($row['gross_value'], false),
                'confirmed' => self::boolean($row['confirmed'] ?? false),
                'approval_reference' => self::text($row['approval_reference'] ?? '', 2000),
                'point_rounding' => self::choice($row['point_rounding'] ?? 'pending', ['pending', 'proportional', 'floor', 'ceil']),
                'rounding_reference' => self::text($row['rounding_reference'] ?? '', 2000),
                'money_rounding' => self::choice($row['money_rounding'] ?? 'pending', ['pending', 'half_up', 'floor', 'ceil']),
                'money_rounding_reference' => self::text($row['money_rounding_reference'] ?? '', 2000),
                'pao_applicable' => self::boolean($row['pao_applicable'] ?? null, true),
                'pao_reference' => self::text($row['pao_reference'] ?? '', 2000),
                'pao_from' => self::date($row['pao_from'] ?? ''),
                'pao_to' => self::date($row['pao_to'] ?? ''),
                'cap_pao_order' => self::choice($row['cap_pao_order'] ?? 'pending', ['pending', 'cap_then_pao', 'pao_then_cap']),
                'cap_pao_reference' => self::text($row['cap_pao_reference'] ?? '', 2000),
                'trs' => [], 'adjustments' => [], 'indicator_decisions' => [],
            ];
            if ($entry['pao_from'] !== '' && $entry['pao_to'] !== '' && $entry['pao_from'] > $entry['pao_to']) {
                throw new \InvalidArgumentException('Período PAO invertido.');
            }
            foreach (self::rows($row['trs'] ?? [], 500, 'Ocorrências TRS') as $item) {
                $code = self::text((string)($item['code'] ?? ''), 16);
                if (!preg_match('/\A(?:[1-9]|[1-4][0-9])\z/D', $code)) throw new \InvalidArgumentException('O código TRS deve estar entre 1 e 49.');
                $quantity = self::number($item['quantity'] ?? 1, 1000000);
                if (floor($quantity) !== $quantity) throw new \InvalidArgumentException('Quantidade TRS deve ser inteira.');
                $entry['trs'][] = ['code' => $code, 'description' => self::text($item['description'] ?? '', 2000),
                    'points' => self::number($item['points'] ?? 0, 1000000), 'quantity' => (int)$quantity,
                    'approved' => self::boolean($item['approved'] ?? false), 'reference' => self::text($item['reference'] ?? '', 2000)];
            }
            foreach (self::rows($row['adjustments'] ?? [], 100, 'Ajustes financeiros') as $item) {
                $entry['adjustments'][] = ['description' => self::text($item['description'] ?? '', 2000),
                    'amount' => self::money($item['amount'] ?? '0', true), 'reference' => self::text($item['reference'] ?? '', 2000)];
            }
            $decisionIds = [];
            foreach (self::rows($row['indicator_decisions'] ?? [], 16, 'Decisões de indicadores') as $item) {
                $id = self::text($item['id'] ?? '', 20);
                if (!preg_match('/\A(?:IPS(?:[1-9]|10)|IPS[34]_P[123])\z/D', $id) || isset($decisionIds[$id])) {
                    throw new \InvalidArgumentException('Indicador financeiro inválido ou repetido.');
                }
                $decisionIds[$id] = true;
                $points = ($item['points'] ?? null) === null || ($item['points'] ?? '') === '' ? null : self::number($item['points'], 1000000);
                if ($points !== null && $id !== 'IPS9') throw new \InvalidArgumentException('Pontos acordados manualmente são exclusivos de IPS9; os demais seguem a matriz contratual.');
                $entry['indicator_decisions'][] = ['id' => $id, 'applicable' => self::boolean($item['applicable'] ?? null, true),
                    'points' => $points, 'reference' => self::text($item['reference'] ?? '', 2000)];
            }
            $result['months'][$month] = $entry;
        }
        ksort($result['months']);
        if (strlen(self::json($result)) > 60000) throw new \InvalidArgumentException('Configuração financeira excede 60.000 bytes.');
        return $result;
    }

    public static function json(array $config): string
    {
        $config['months'] = (object)($config['months'] ?? []);
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function revision(array $config): string { return hash('sha256', self::json($config)); }

    /** Parse decimal strings using integer cents; never use binary floats for money parsing. */
    public static function cents($value): int
    {
        $value = self::money($value, true);
        $negative = $value[0] === '-';
        if ($negative) $value = substr($value, 1);
        [$whole, $fraction] = explode('.', $value);
        $result = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$result : $result;
    }
    public static function amount(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        return ($negative ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
    public static function money($value, bool $signed = false): string
    {
        if (!is_scalar($value) || is_bool($value)) throw new \InvalidArgumentException('Valor financeiro inválido.');
        $value = trim((string)$value);
        $value = preg_replace('/\AR\$\s*/u', '', $value);
        if (strpos($value, ',') !== false) {
            if (!preg_match('/\A-?(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}\z/D', $value)) throw new \InvalidArgumentException('Formato monetário inválido.');
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        $pattern = $signed ? '/\A(-?)(\d{1,9})(?:\.(\d{1,2}))?\z/D' : '/\A()(\d{1,9})(?:\.(\d{1,2}))?\z/D';
        if (!preg_match($pattern, $value, $matches)) throw new \InvalidArgumentException('Informe valor financeiro com até duas casas decimais.');
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        return ($matches[1] === '-' && ($whole !== '0' || $fraction !== '00') ? '-' : '') . $whole . '.' . $fraction;
    }

    public static function object($raw, string $label): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > 60000 || substr(ltrim($raw), 0, 1) !== '{') throw new \InvalidArgumentException($label . ': informe objeto JSON de até 60.000 bytes.');
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException($label . ': JSON inválido.');
        }
        if (!is_array($raw)) throw new \InvalidArgumentException($label . ' inválida.');
        return $raw;
    }
    public static function rows($rows, int $limit, string $label): array
    {
        if (!is_array($rows) || ($rows && array_keys($rows) !== range(0, count($rows) - 1)) || count($rows) > $limit) throw new \InvalidArgumentException($label . ': lista inválida ou acima do limite.');
        foreach ($rows as $row) if (!is_array($row)) throw new \InvalidArgumentException($label . ': linha inválida.');
        return $rows;
    }
    public static function text($value, int $limit): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Texto inválido.');
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
        if (mb_strlen($value, 'UTF-8') > $limit) throw new \InvalidArgumentException('Texto acima do limite de ' . $limit . ' caracteres.');
        return $value;
    }
    public static function number($value, float $maximum = 1000000000000): float
    {
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > $maximum) throw new \InvalidArgumentException('Número não negativo e finito obrigatório.');
        return (float)$value;
    }
    public static function boolean($value, bool $nullable = false): ?bool
    {
        if ($nullable && ($value === null || $value === '')) return null;
        if ($value === true || $value === 1 || $value === '1') return true;
        if ($value === false || $value === 0 || $value === '0') return false;
        throw new \InvalidArgumentException('Valor booleano inválido.');
    }
    public static function choice($value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new \InvalidArgumentException('Opção de cálculo inválida.');
        return $value;
    }
    public static function month(string $value): string
    {
        if (!preg_match('/\A(?:20\d{2}|2100)-(?:0[1-9]|1[0-2])\z/D', $value)) throw new \InvalidArgumentException('Competência inválida (AAAA-MM).');
        return $value;
    }
    public static function date($value): string
    {
        if ($value === '') return '';
        if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new \InvalidArgumentException('Data inválida (AAAA-MM-DD).');
        return $value;
    }
}
