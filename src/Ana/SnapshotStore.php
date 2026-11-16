<?php

namespace GlpiPlugin\G4freportsana\Ana;

final class SnapshotStore
{
    private const TTL = 86400;

    private static function directory(): string
    {
        $base = defined('GLPI_TMP_DIR') ? (string)GLPI_TMP_DIR
            : (defined('GLPI_ROOT') ? GLPI_ROOT . '/files/_tmp' : sys_get_temp_dir());
        $identity = defined('GLPI_ROOT') ? (string)GLPI_ROOT : dirname(__DIR__, 3);
        $directory = rtrim($base, '/\\') . '/g4freportsana_ana_' . substr(hash('sha256', $identity), 0, 12);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar o armazenamento temporário do relatório.');
        }
        return $directory;
    }

    private static function path(string $token): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            throw new \InvalidArgumentException('Identificador de relatório inválido.');
        }
        return self::directory() . '/' . $token . '.json';
    }

    public static function save(array $snapshot, int $owner): string
    {
        if ($owner <= 0) throw new \RuntimeException('Sessão inválida.');
        self::cleanup();
        $token = bin2hex(random_bytes(32));
        self::write($token, ['owner' => $owner, 'created_at' => time(), 'snapshot' => $snapshot]);
        return $token;
    }

    private static function read(string $token, int $owner): array
    {
        $path = self::path($token);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (int)($data['owner'] ?? 0) !== $owner || $owner <= 0) {
            throw new \RuntimeException('Relatório indisponível para este usuário. Gere o relatório novamente.');
        }
        if (time() - (int)($data['created_at'] ?? 0) > self::TTL) {
            @unlink($path);
            throw new \RuntimeException('O rascunho temporário expirou. Gere o relatório novamente.');
        }
        if (!is_array($data['snapshot'] ?? null)) throw new \RuntimeException('Rascunho de relatório inválido.');
        return $data;
    }

    public static function load(string $token, int $owner): array
    {
        return self::read($token, $owner)['snapshot'];
    }

    public static function assertCurrentRules(array $snapshot): void
    {
        // V2 snapshots use unconditional telephone inclusion and the same
        // schema. Export/narrative updates preserve their frozen values;
        // only a new extraction applies the administrator's current policy.
        $compatibleV2 = ($snapshot['rules_version'] ?? '') === 'ana-2026-10-16-v2'
            && ($snapshot['schema_version'] ?? null) === 3;
        if (($snapshot['rules_version'] ?? '') !== Settings::RULES_VERSION && !$compatibleV2) {
            throw new \RuntimeException('Este rascunho usa regras anteriores. Gere novamente o mês para aplicar o mapeamento selecionado e atualizar as evidências, totais e rótulos antes de exportar.');
        }
    }

    public static function updateNarrative(string $token, int $owner, array $input): array
    {
        $data = self::read($token, $owner);
        self::assertCurrentRules($data['snapshot']);
        foreach (Settings::normalizeNarrative($input) as $field => $text) {
            $data['snapshot']['input'][$field] = $text;
            $data['snapshot']['manual'][$field] = $text;
        }
        $data['snapshot']['narrative_updated_at'] = date(DATE_ATOM);
        self::write($token, $data);
        return $data['snapshot'];
    }

    private static function write(string $token, array $data): void
    {
        $path = self::path($token);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível salvar o rascunho do relatório.');
        }
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Não foi possível concluir o salvamento do relatório.');
        }
    }

    private static function cleanup(): void
    {
        foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
            if (is_file($path) && time() - (int)filemtime($path) > self::TTL) @unlink($path);
        }
    }
}
