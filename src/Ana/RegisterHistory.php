<?php

namespace GlpiPlugin\G4freportsana\Ana;

use GlpiPlugin\G4freportsana\Config;

/** Ten recoverable pre-save revisions for each register, kept in GLPI configuration. */
final class RegisterHistory
{
    private const RETAIN = 10;

    private static function prefix(string $kind): string
    {
        if (!in_array($kind, ['tests'], true)) throw new \InvalidArgumentException('Cadastro desconhecido.');
        return 'ana_register_' . $kind . '_history_';
    }

    public static function entries(array $settings, string $kind): array
    {
        $raw = json_decode($settings[self::prefix($kind) . 'index'] ?? '[]', true);
        return is_array($raw) ? array_values(array_slice($raw, 0, self::RETAIN)) : [];
    }

    public static function revisionRows(array $settings, string $kind, string $id): array
    {
        foreach (self::entries($settings, $kind) as $entry) {
            if (($entry['id'] ?? '') !== $id) continue;
            $slot = (int)($entry['slot'] ?? -1);
            if ($slot < 0 || $slot >= self::RETAIN) break;
            $parts = (int)($entry['parts'] ?? 1);
            if ($parts < 1 || $parts > 4) break;
            $raw = '';
            for ($part = 0; $part < $parts; $part++) {
                $value = $settings[self::prefix($kind) . $slot . '_' . $part] ?? null;
                if (!is_string($value)) throw new \RuntimeException('Esta versão do histórico está incompleta.');
                $raw .= $value;
            }
            return Settings::testNumbers($raw);
        }
        throw new \InvalidArgumentException('Esta versão não está mais no histórico. Atualize a lista de versões.');
    }

    /** Includes the selected register's new value in the same configuration save. */
    public static function save(array $settings, string $kind, array $before, array $after, int $userId, string $userName): array
    {
        $key = 'ana_test_numbers_json';
        if (Settings::revision($before) === Settings::revision($after)) return self::entries($settings, $kind);
        $entries = self::entries($settings, $kind);
        $slot = $entries ? (((int)$entries[0]['slot'] + 1) % self::RETAIN) : 0;
        $idField = 'number';
        $oldMap = array_column($before, null, $idField);
        $newMap = array_column($after, null, $idField);
        $added = $removed = $updated = 0;
        foreach ($oldMap as $id => $row) {
            if (!isset($newMap[$id])) $removed++;
            elseif ($newMap[$id] !== $row) $updated++;
        }
        foreach ($newMap as $id => $row) if (!isset($oldMap[$id])) $added++;
        // Keep each GLPI configuration value below the TEXT limit, including a
        // large phone register. mb_strcut does not split UTF-8 characters.
        $priorJson = Settings::json($before);
        $chunks = [];
        while ($priorJson !== '') {
            $chunk = mb_strcut($priorJson, 0, 50000, 'UTF-8');
            $chunks[] = $chunk;
            $priorJson = substr($priorJson, strlen($chunk));
        }
        if (count($chunks) > 4) throw new \InvalidArgumentException('O cadastro excede o limite de recuperação do histórico.');
        array_unshift($entries, [
            'id' => bin2hex(random_bytes(16)), 'slot' => $slot, 'parts' => count($chunks),
            'saved_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            'user_id' => $userId, 'user_name' => mb_substr($userName, 0, 120),
            'added' => $added, 'removed' => $removed, 'updated' => $updated,
            'previous_count' => count($before), 'saved_count' => count($after),
        ]);
        $entries = array_slice($entries, 0, self::RETAIN);
        $values = [];
        for ($part = 0; $part < 4; $part++) $values[self::prefix($kind) . $slot . '_' . $part] = $chunks[$part] ?? '';
        $values[self::prefix($kind) . 'index'] = Settings::json($entries);
        $values[$key] = Settings::json($after);
        Config::save($values);
        return $entries;
    }

    /** Serialize revision-check + save across PHP workers using the native GLPI DB. */
    public static function lock(): string
    {
        global $DB;
        $identity = defined('GLPI_ROOT') ? GLPI_ROOT : dirname(__DIR__, 3);
        $name = 'g4fra_registers_' . substr(hash('sha256', $identity), 0, 24);
        // GLPI 11 forbids plugin calls to DBmysql::query(). The native lock
        // API is also available in GLPI 10 and applies the database namespace.
        if (!$DB->getLock($name)) {
            throw new \RuntimeException('Outro cadastro está sendo salvo. Aguarde alguns segundos e tente novamente; suas alterações continuam na tela.');
        }
        return $name;
    }

    public static function unlock(string $name): void
    {
        global $DB;
        if ($name !== '' && !$DB->releaseLock($name)) {
            throw new \RuntimeException('Não foi possível liberar o bloqueio do cadastro.');
        }
    }
}
