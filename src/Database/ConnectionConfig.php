<?php

namespace GlpiPlugin\G4freportsana\Database;

/**
 * Dedicated report connections; never changes GLPI's global database handle.
 *
 * Native Config::setConfigurationValues encrypts the fields registered through
 * secured_configs. getConfigurationValues returns the ciphertext unchanged.
 */
final class ConnectionConfig
{
    private const CONTEXT = 'plugin:g4freportsana';
    private const SECRET_PREFIX = 'g4fr-db-v1:';
    private const SOURCES = ['ana' => 'ana_db_', 'callcenter' => 'callcenter_db_'];

    public static function defaults(): array
    {
        $result = [];
        foreach (self::SOURCES as $source => $prefix) {
            $values = [
                'enabled' => 0,
                'host' => '',
                'port' => $source === 'callcenter' ? 2210 : 3306,
                'name' => $source === 'callcenter' ? 'ipbx' : '',
                'user' => '',
                'password' => '',
                'password_env' => '',
                'tls_mode' => 'disabled',
                'tls_ca' => '',
                'connect_timeout' => 5,
                'read_timeout' => 5,
            ];
            foreach ($values as $key => $value) {
                $result[$prefix . $key] = $value;
            }
        }
        $result['callcenter_db_timezone'] = 'America/Sao_Paulo';
        return $result;
    }

    /** Value for $PLUGIN_HOOKS['secured_configs']['g4freportsana']. */
    public static function securedConfigs(): array
    {
        return ['ana_db_password', 'callcenter_db_password'];
    }

    /** Called by the static configUpdate callback of GLPI's native config form. */
    public static function prepareUpdate(array $input): array
    {
        $allowed = self::defaults();
        foreach (self::SOURCES as $prefix) {
            $passwordKey = $prefix . 'password';
            $clearKey = $prefix . 'clear_password';
            $nativeClearKey = '_blank_' . $passwordKey;
            $clear = self::flag($input[$clearKey] ?? 0)
                || self::flag($input[$nativeClearKey] ?? 0);
            unset($input[$clearKey], $input[$nativeClearKey]);

            foreach (array_keys($input) as $key) {
                if (strpos((string)$key, $prefix) !== 0) {
                    continue;
                }
                if (!array_key_exists($key, $allowed)) {
                    unset($input[$key]);
                    continue;
                }
                if ($key !== $passwordKey) {
                    $input[$key] = self::normalize(substr($key, strlen($prefix)), $input[$key]);
                }
            }

            if ($clear) {
                $input[$passwordKey] = '';
            } elseif (array_key_exists($passwordKey, $input)) {
                $password = self::scalar($input[$passwordKey]);
                if ($password === '') {
                    // Omitting this key preserves the existing encrypted value.
                    unset($input[$passwordKey]);
                } else {
                    if (strlen($password) > 4096 || strpos($password, "\0") !== false) {
                        throw new \InvalidArgumentException('A senha do banco contém um valor inválido.');
                    }
                    $key = self::keyService();
                    if (!$key->isConfigSecured(self::CONTEXT, $passwordKey)) {
                        throw new \RuntimeException('A proteção das senhas do banco não está inicializada.');
                    }
                    // Native GLPI only encrypts !empty(value). This envelope also
                    // protects the literal password "0" and preserves every byte.
                    // GLPI performs the actual encryption after this callback.
                    $input[$passwordKey] = self::SECRET_PREFIX . base64_encode($password);
                }
            }
        }
        return $input;
    }

    /** Produce connection options only on the server, immediately before use. */
    public static function fromConfig(string $source, array $cfg): array
    {
        if ($source === 'glpi') {
            $source = 'ana';
        }
        if (!isset(self::SOURCES[$source])) {
            throw new \InvalidArgumentException('Fonte de banco de dados inválida.');
        }
        $prefix = self::SOURCES[$source];
        $cfg = array_replace(self::defaults(), $cfg);
        $result = ['source' => $source];
        foreach (['enabled', 'host', 'port', 'name', 'user', 'password_env', 'tls_mode', 'tls_ca', 'connect_timeout', 'read_timeout'] as $field) {
            $option = $field === 'name' ? 'database' : ($field === 'user' ? 'username' : $field);
            $result[$option] = self::normalize($field, $cfg[$prefix . $field]);
        }
        $result['password'] = '';
        if (!$result['enabled']) {
            return $result;
        }

        if ($result['password_env'] !== '') {
            $password = getenv($result['password_env']);
            if ($password === false || $password === '') {
                throw new \RuntimeException('A variável de ambiente da senha do banco não está definida no servidor GLPI.');
            }
            if (strlen($password) > 4096 || strpos($password, "\0") !== false) {
                throw new \RuntimeException('A variável de ambiente da senha do banco contém um valor inválido.');
            }
            $result['password'] = $password;
            return $result;
        }

        $ciphertext = self::scalar($cfg[$prefix . 'password']);
        if ($ciphertext === '') {
            return $result;
        }
        try {
            $decrypted = @self::keyService()->decrypt($ciphertext);
            if (!is_string($decrypted) || strpos($decrypted, self::SECRET_PREFIX) !== 0) {
                throw new \RuntimeException('invalid_secret');
            }
            $password = base64_decode(substr($decrypted, strlen(self::SECRET_PREFIX)), true);
            if ($password === false || $password === '' || strlen($password) > 4096 || strpos($password, "\0") !== false) {
                throw new \RuntimeException('invalid_secret');
            }
            $result['password'] = $password;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Não foi possível ler a senha protegida do banco. Salve novamente a senha na configuração.');
        }
        return $result;
    }

    /** Remove even ciphertext before passing configuration to a view. */
    public static function redact(array $cfg): array
    {
        foreach (self::securedConfigs() as $key) {
            $cfg[$key] = '';
        }
        return $cfg;
    }

    public static function passwordSet(array $cfg): array
    {
        $result = [];
        foreach (self::SOURCES as $source => $prefix) {
            $value = $cfg[$prefix . 'password'] ?? '';
            $result[$source] = is_string($value) && $value !== '';
        }
        return $result;
    }

    private static function scalar($value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new \InvalidArgumentException('A configuração de banco contém um valor inválido.');
        }
        return (string)$value;
    }

    private static function flag($value): int
    {
        $value = strtolower(trim(self::scalar($value)));
        if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
            return 1;
        }
        if (in_array($value, ['', '0', 'false', 'off', 'no'], true)) {
            return 0;
        }
        throw new \InvalidArgumentException('A opção de conexão habilitada contém um valor inválido.');
    }

    private static function normalize(string $field, $value)
    {
        if ($field === 'enabled') {
            return self::flag($value);
        }
        $value = trim(self::scalar($value));
        if ($field === 'timezone') {
            if ($value === '') return self::defaults()['callcenter_db_timezone'];
            if (!in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                throw new \InvalidArgumentException('Informe um fuso horário IANA válido para o callcenter.');
            }
            return $value;
        }
        if ($field === 'port' || $field === 'connect_timeout' || $field === 'read_timeout') {
            $max = $field === 'port' ? 65535 : ($field === 'read_timeout' ? 30 : 15);
            if (!preg_match('/^[0-9]+$/D', $value) || (int)$value < 1 || (int)$value > $max) {
                throw new \InvalidArgumentException('Porta ou tempo limite do banco fora do intervalo permitido.');
            }
            return (int)$value;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('A configuração de banco contém caracteres inválidos.');
        }
        if ($field === 'host' && ($value !== '' && !preg_match('/^[A-Za-z0-9_.:\[\]-]{1,253}$/D', $value))) {
            throw new \InvalidArgumentException('Informe somente o host ou IP do banco, sem URL, porta ou credenciais.');
        }
        if ($field === 'name' && $value !== '' && !preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $value)) {
            throw new \InvalidArgumentException('Nome do banco de dados inválido.');
        }
        if ($field === 'user' && strlen($value) > 128) {
            throw new \InvalidArgumentException('O usuário do banco excede o tamanho permitido.');
        }
        if ($field === 'password_env' && $value !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $value)) {
            throw new \InvalidArgumentException('Nome da variável de ambiente da senha inválido.');
        }
        if ($field === 'tls_mode' && !in_array($value, ['disabled', 'verify'], true)) {
            throw new \InvalidArgumentException('Modo TLS do banco inválido.');
        }
        if ($field === 'tls_ca' && strlen($value) > 4096) {
            throw new \InvalidArgumentException('O caminho do certificado CA excede o tamanho permitido.');
        }
        return $value;
    }

    private static function keyService()
    {
        try {
            if (!class_exists('GLPIKey')) {
                throw new \RuntimeException('missing_key_service');
            }
            $key = new \GLPIKey();
            if (!is_string($key->get()) || $key->get() === '') {
                throw new \RuntimeException('missing_key');
            }
            return $key;
        } catch (\Throwable $e) {
            throw new \RuntimeException('A chave de proteção do GLPI não está disponível para salvar ou ler a senha do banco.');
        }
    }
}
