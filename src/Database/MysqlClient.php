<?php

namespace GlpiPlugin\G4freportsana\Database;

/**
 * Isolated, on-demand MySQL/MariaDB diagnostics for monthly-report sources.
 * This class does not use GLPI's global database or change mysqli_report().
 */
class MysqlClient
{
    private array $profile;
    private array $fieldTypes = [];

    public function __construct(array $profile)
    {
        $host = trim((string)($profile['host'] ?? ''));
        $database = trim((string)($profile['database'] ?? ''));
        $username = trim((string)($profile['username'] ?? ''));
        $tlsMode = (string)($profile['tls_mode'] ?? 'disabled');
        $tlsCa = trim((string)($profile['tls_ca'] ?? ''));

        // A dedicated host field must not silently select a persistent handle,
        // socket, URL or a port embedded in a JDBC connection string.
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isHostname = strlen($host) <= 253
            && preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?\z/D', $host);
        if ($host === '' || (!$isIp && !$isHostname)) {
            throw new ConnectionException('invalid_host', 'Informe somente o nome ou IP do servidor MySQL; use o campo separado para a porta.');
        }
        if ($database === '' || strlen($database) > 64 || preg_match('/[\x00-\x1F\x7F]/', $database)) {
            throw new ConnectionException('invalid_database', 'Informe um nome de banco de dados válido, com até 64 bytes.');
        }
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new ConnectionException('invalid_username', 'Informe um usuário MySQL válido.');
        }
        if (!in_array($tlsMode, ['disabled', 'verify'], true)) {
            throw new ConnectionException('invalid_tls_mode', 'Selecione um modo TLS válido.');
        }
        if ($tlsCa !== '' && (strpos($tlsCa, "\0") !== false || strpos($tlsCa, '://') !== false)) {
            throw new ConnectionException('invalid_tls_ca', 'O certificado CA deve ser um arquivo local no servidor GLPI.');
        }
        $this->profile = [
            'host' => $host,
            'port' => self::integerOption($profile['port'] ?? 3306, 1, 65535, 'invalid_port', 'A porta MySQL deve estar entre 1 e 65535.'),
            'database' => $database,
            'username' => $username,
            // Spaces can be part of a valid database password.
            'password' => (string)($profile['password'] ?? ''),
            'connect_timeout' => self::integerOption($profile['connect_timeout'] ?? 5, 1, 15, 'invalid_timeout', 'O limite de conexão deve estar entre 1 e 15 segundos.'),
            'read_timeout' => self::integerOption($profile['read_timeout'] ?? 5, 1, 30, 'invalid_timeout', 'O limite de leitura deve estar entre 1 e 30 segundos.'),
            'tls_mode' => $tlsMode,
            'tls_ca' => $tlsCa,
        ];
    }

    /** Do not expose the stored password if the object is inspected in a log. */
    public function __debugInfo(): array
    {
        return ['source' => 'MySQL/MariaDB', 'credentials' => '[redacted]'];
    }

    /** Prepared, bounded reads for fixed SQL owned by the ANA repositories. */
    public function fetchAll(string $sql, array $parameters = [], int $maxRows = 100000): array
    {
        $this->fieldTypes = [];
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql) || $maxRows < 1 || $maxRows > 250000) {
            throw new ConnectionException('invalid_query', 'Consulta de leitura inválida.');
        }
        if (!class_exists('mysqli')) {
            throw new ConnectionException('driver_missing', 'A extensão PHP mysqli não está disponível no servidor GLPI.');
        }
        $connection = null;
        $statement = null;
        try {
            $connection = self::quietly(static function () { return new \mysqli(); });
            $this->connect($connection);
            if ($this->profile['tls_mode'] === 'verify') {
                $result = $this->query($connection, "SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                $row = $result->fetch_assoc();
                $result->free();
                if (empty($row['Value'])) {
                    throw new ConnectionException('tls_required', 'A consulta exige uma conexão TLS verificada.');
                }
            }
            $started = self::quietly(static function () use ($connection) {
                return $connection->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
            });
            if (!$started) {
                throw new ConnectionException('read_only_failed', 'Não foi possível iniciar a leitura protegida do relatório.');
            }
            $statement = self::quietly(static function () use ($connection, $sql) { return $connection->prepare($sql); });
            if ($statement === false) {
                throw self::driverError((int)$connection->errno, 'query');
            }
            if ($parameters) {
                $values = [];
                foreach ($parameters as $value) {
                    if (!is_scalar($value) && $value !== null) {
                        throw new ConnectionException('invalid_parameter', 'Parâmetro de consulta inválido.');
                    }
                    $values[] = $value === null ? null : (string)$value;
                }
                $types = str_repeat('s', count($values));
                $statement->bind_param($types, ...$values);
            }
            $executed = self::quietly(static function () use ($statement) { return $statement->execute(); });
            if (!$executed) {
                throw self::driverError((int)$statement->errno, 'query');
            }
            $metadata = $statement->result_metadata();
            if ($metadata === false) {
                throw new ConnectionException('query_failed', 'A consulta não retornou uma tabela de dados.');
            }
            $names = [];
            $values = [];
            $references = [];
            foreach ($metadata->fetch_fields() as $field) {
                $names[] = $field->name;
                $this->fieldTypes[$field->name] = (int)$field->type;
                $values[] = null;
            }
            $metadata->free();
            foreach ($values as $index => &$value) { $references[$index] =& $value; }
            unset($value);
            $statement->bind_result(...$references);
            $rows = [];
            while (true) {
                $fetched = self::quietly(static function () use ($statement) { return $statement->fetch(); });
                if ($fetched === null) break;
                if ($fetched === false) throw self::driverError((int)$statement->errno, 'query');
                if (count($rows) >= $maxRows) {
                    throw new ConnectionException('row_limit_exceeded', 'O volume excedeu o limite seguro de extração. O relatório não foi gerado com dados parciais; restrinja o escopo.');
                }
                $row = [];
                foreach ($names as $index => $name) { $row[$name] = $values[$index]; }
                $rows[] = $row;
            }
            return $rows;
        } catch (ConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::driverError((int)$e->getCode(), 'query');
        } finally {
            if ($statement) {
                try { self::quietly(static function () use ($statement) { $statement->close(); }); } catch (\Throwable $ignored) {}
            }
            if ($connection) {
                try { self::quietly(static function () use ($connection) { $connection->rollback(); $connection->close(); }); } catch (\Throwable $ignored) {}
            }
        }
    }

    /** Native result metadata, with no source rows fetched. */
    public function columnTypes(string $sql): array
    {
        if (!preg_match('/\bLIMIT\s+0\s*$/i', $sql)) {
            throw new ConnectionException('invalid_query', 'A consulta de estrutura deve usar LIMIT 0.');
        }
        $this->fetchAll($sql, [], 1);
        return $this->fieldTypes;
    }

    /** Text storage still requires strict calendar-date validation by the reader. */
    public static function dateTimeStorageType(int $fieldType): ?string
    {
        foreach ([
            'timestamp' => ['MYSQLI_TYPE_TIMESTAMP', 'MYSQLI_TYPE_TIMESTAMP2'],
            'datetime' => ['MYSQLI_TYPE_DATETIME', 'MYSQLI_TYPE_DATETIME2'],
            'varchar' => ['MYSQLI_TYPE_VARCHAR', 'MYSQLI_TYPE_VAR_STRING'],
            'char' => ['MYSQLI_TYPE_STRING'],
        ] as $storage => $constants) {
            foreach ($constants as $name) {
                if (defined($name) && $fieldType === constant($name)) return $storage;
            }
        }
        return null;
    }

    /**
     * All SQL is fixed and read-only. LIMIT 0 verifies schema and SELECT rights
     * without fetching tickets, telephone numbers or scanning queue history.
     */
    public function test(string $source = 'callcenter'): array
    {
        $tables = self::schemaFor($source);
        if (!class_exists('mysqli')) {
            throw new ConnectionException('driver_missing', 'A extensão PHP mysqli não está disponível no servidor GLPI.');
        }

        $connection = null;
        try {
            $connection = self::quietly(static function () {
                return new \mysqli();
            });
            $this->connect($connection);
            $tlsCipher = '';
            if ($this->profile['tls_mode'] === 'verify') {
                $tlsResult = $this->query($connection, "SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                try {
                    $tlsRow = $tlsResult->fetch_assoc();
                    $tlsCipher = self::safeServerText((string)($tlsRow['Value'] ?? ''));
                } finally {
                    $tlsResult->free();
                }
                if ($tlsCipher === '') {
                    throw new ConnectionException('tls_required', 'O servidor não estabeleceu uma sessão TLS. A conexão foi encerrada porque a verificação TLS está habilitada.');
                }
            }

            $versionResult = $this->query($connection, 'SELECT VERSION() AS server_version');
            try {
                $versionRow = $versionResult->fetch_assoc();
                $version = self::safeServerText((string)($versionRow['server_version'] ?? ''));
            } finally {
                $versionResult->free();
            }

            $schema = [];
            $ok = true;
            foreach ($tables as $table => $definition) {
                $schema[$table] = $this->checkTable($connection, $table, $definition);
                if ($definition['required'] && $schema[$table]['status'] !== 'available') {
                    $ok = false;
                }
            }
            return [
                'ok' => $ok,
                'source' => $source,
                'connection' => [
                    'status' => 'connected',
                    'server_version' => $version,
                    'database' => $this->profile['database'],
                    'tls_mode' => $this->profile['tls_mode'],
                    'tls_cipher' => $tlsCipher,
                ],
                'schema' => $schema,
            ];
        } catch (ConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::driverError((int)$e->getCode(), 'connection');
        } finally {
            if ($connection !== null) {
                try {
                    self::quietly(static function () use ($connection) {
                        $connection->close();
                    });
                } catch (\Throwable $ignored) {
                    // Cleanup must not replace the sanitized diagnostic.
                }
            }
        }
    }

    private function connect($connection): void
    {
        foreach ([
            'MYSQLI_OPT_CONNECT_TIMEOUT' => $this->profile['connect_timeout'],
            'MYSQLI_OPT_READ_TIMEOUT' => $this->profile['read_timeout'],
            'MYSQLI_OPT_LOCAL_INFILE' => 0,
        ] as $option => $value) {
            $this->setOption($connection, $option, $value);
        }

        $flags = 0;
        if ($this->profile['tls_mode'] === 'verify') {
            if (!defined('MYSQLI_CLIENT_SSL')) {
                throw new ConnectionException('tls_unsupported', 'O driver MySQL deste servidor não oferece a conexão TLS solicitada.');
            }
            $ca = $this->profile['tls_ca'];
            if ($ca !== '' && (!is_file($ca) || !is_readable($ca))) {
                throw new ConnectionException('invalid_tls_ca', 'O arquivo CA informado não está disponível para leitura no servidor GLPI.');
            }
            // Some mysqli builds expose the option constant but reject it in
            // options(). Prefer the documented connection verification flag.
            $flags = constant('MYSQLI_CLIENT_SSL');
            if (defined('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT')) {
                $flags |= constant('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT');
            } else {
                $this->setOption($connection, 'MYSQLI_OPT_SSL_VERIFY_SERVER_CERT', 1);
            }
            $sslSet = self::quietly(static function () use ($connection, $ca) {
                return $connection->ssl_set(null, null, $ca !== '' ? $ca : null, null, null);
            });
            if (!$sslSet) {
                throw new ConnectionException('tls_unsupported', 'Não foi possível configurar a verificação do certificado TLS.');
            }
            // Never pass MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT.
        }

        try {
            $connected = self::quietly(function () use ($connection, $flags) {
                return $connection->real_connect(
                    $this->profile['host'],
                    $this->profile['username'],
                    $this->profile['password'],
                    $this->profile['database'],
                    $this->profile['port'],
                    null,
                    $flags
                );
            });
        } catch (\Throwable $e) {
            throw self::driverError((int)$e->getCode(), $this->profile['tls_mode'] === 'verify' ? 'tls_connection' : 'connection');
        }
        if (!$connected) {
            throw self::driverError((int)$connection->connect_errno, $this->profile['tls_mode'] === 'verify' ? 'tls_connection' : 'connection');
        }
        try {
            $charsetOk = self::quietly(static function () use ($connection) {
                return $connection->set_charset('utf8mb4');
            });
        } catch (\Throwable $e) {
            throw new ConnectionException('charset_failed', 'Não foi possível configurar a codificação UTF-8 da conexão.', (int)$e->getCode());
        }
        if (!$charsetOk) {
            throw new ConnectionException('charset_failed', 'Não foi possível configurar a codificação UTF-8 da conexão.', (int)$connection->errno);
        }
        // This handle exists only for one bounded read. Session settings neither
        // modify source rows nor change global database configuration. UTC keeps
        // TIMESTAMP comparisons and returned epoch anchors consistent.
        $this->query($connection, "SET SESSION time_zone = '+00:00'");
        $version = (string)$connection->server_info;
        $limitSql = stripos($version, 'MariaDB') !== false
            ? 'SET SESSION max_statement_time = 3'
            : 'SET SESSION max_execution_time = 3000';
        try {
            $this->query($connection, $limitSql);
        } catch (ConnectionException $e) {
            throw new ConnectionException('execution_timeout_unavailable',
                'O banco não aceitou o limite de execução de 3 segundos. A extração foi interrompida antes de consultar os dados.',
                $e->getMysqlCode());
        }
    }

    private function setOption($connection, string $name, int $value): void
    {
        if (!defined($name)) {
            throw new ConnectionException('driver_unsupported', 'O driver MySQL não oferece as opções necessárias de limite de tempo e conexão.');
        }
        try {
            $ok = self::quietly(static function () use ($connection, $name, $value) {
                return $connection->options(constant($name), $value);
            });
        } catch (\Throwable $e) {
            throw new ConnectionException('driver_unsupported', 'O driver MySQL não aceitou uma opção necessária para esta conexão.', (int)$e->getCode());
        }
        if (!$ok) {
            throw new ConnectionException('driver_unsupported', 'O driver MySQL não aceitou uma opção necessária para esta conexão.');
        }
    }

    private function query($connection, string $sql)
    {
        try {
            $result = self::quietly(static function () use ($connection, $sql) {
                return $connection->query($sql);
            });
        } catch (\Throwable $e) {
            throw self::driverError((int)$e->getCode(), 'query');
        }
        if ($result === false) {
            throw self::driverError((int)$connection->errno, 'query');
        }
        return $result;
    }

    private function checkTable($connection, string $table, array $definition): array
    {
        $check = [
            'required' => $definition['required'],
            'status' => 'available',
            'message' => 'Tabela e colunas acessíveis para leitura.',
            'mysql_code' => 0,
            'columns' => $definition['columns'],
        ];
        // Identifiers come exclusively from schemaFor(), never request input.
        $columns = '`' . implode('`, `', $definition['columns']) . '`';
        try {
            $result = $this->query($connection, 'SELECT ' . $columns . ' FROM `' . $table . '` LIMIT 0');
            try {
                if ($table === 'queue_log') {
                    $timeType = null;
                    foreach ($result->fetch_fields() as $field) {
                        if ($field->name === 'time') $timeType = self::dateTimeStorageType((int)$field->type);
                    }
                    if ($timeType === null) {
                        $check['status'] = 'unsupported_time_type';
                        $check['message'] = 'O tipo de queue_log.time não permite a leitura de datas.';
                    } else {
                        $check['time_storage'] = $timeType;
                    }
                }
            } finally {
                $result->free();
            }
        } catch (ConnectionException $e) {
            $check['status'] = in_array($e->getReason(), ['missing_table', 'missing_columns', 'permission_denied'], true)
                ? $e->getReason() : 'unavailable';
            $check['message'] = $e->getMessage();
            $check['mysql_code'] = $e->getMysqlCode();
        }
        return $check;
    }

    private static function schemaFor(string $source): array
    {
        if ($source === 'callcenter') {
            return [
                'queue_log' => ['required' => true, 'columns' => ['id', 'time', 'callid', 'queuename', 'agent', 'event', 'data1', 'data2', 'data3']],
            ];
        }
        if ($source === 'glpi') {
            return [
                'glpi_tickets' => ['required' => true, 'columns' => ['id', 'name', 'type', 'entities_id', 'date', 'date_creation', 'takeintoaccountdate', 'solvedate', 'closedate', 'status', 'priority', 'urgency', 'impact', 'requesttypes_id', 'itilcategories_id', 'slas_id_tto', 'slas_id_ttr', 'time_to_own', 'time_to_resolve', 'waiting_duration', 'sla_waiting_duration', 'takeintoaccount_delay_stat', 'solve_delay_stat', 'close_delay_stat', 'is_deleted']],
                'glpi_groups_tickets' => ['required' => true, 'columns' => ['tickets_id', 'groups_id', 'type']],
                'glpi_tickets_users' => ['required' => true, 'columns' => ['tickets_id', 'users_id', 'type']],
                'glpi_groups' => ['required' => true, 'columns' => ['id', 'completename', 'entities_id', 'groups_id']],
                'glpi_itilcategories' => ['required' => true, 'columns' => ['id', 'completename', 'entities_id', 'code', 'groups_id']],
                'glpi_logs' => ['required' => true, 'columns' => ['id', 'items_id', 'itemtype', 'date_mod', 'itemtype_link', 'linked_action', 'id_search_option', 'old_value', 'new_value', 'old_id', 'new_id', 'user_name']],
                'glpi_itilsolutions' => ['required' => true, 'columns' => ['id', 'items_id', 'itemtype', 'status', 'date_creation', 'date_approval', 'users_id', 'user_name', 'users_id_approval']],
                'glpi_ticketsatisfactions' => ['required' => true, 'columns' => ['id', 'tickets_id', 'satisfaction', 'satisfaction_scaled_to_5', 'date_answered']],
                'glpi_slas' => ['required' => false, 'columns' => ['id', 'name', 'entities_id', 'is_recursive', 'type', 'number_time', 'definition_time', 'end_of_working_day', 'use_ticket_calendar', 'calendars_id', 'slms_id']],
                'glpi_slms' => ['required' => false, 'columns' => ['id', 'name', 'calendars_id', 'use_ticket_calendar']],
                'glpi_entities' => ['required' => false, 'columns' => ['id', 'name', 'entities_id', 'calendars_id', 'calendars_strategy']],
                'glpi_calendars' => ['required' => false, 'columns' => ['id', 'name', 'entities_id', 'is_recursive']],
                'glpi_calendarsegments' => ['required' => false, 'columns' => ['id', 'calendars_id', 'day', 'begin', 'end']],
                'glpi_holidays' => ['required' => false, 'columns' => ['id', 'name', 'begin_date', 'end_date', 'is_perpetual']],
                'glpi_calendars_holidays' => ['required' => false, 'columns' => ['calendars_id', 'holidays_id']],
                'glpi_configs' => ['required' => false, 'columns' => ['context', 'name', 'value']],
            ];
        }
        throw new ConnectionException('invalid_source', 'Selecione uma origem de dados válida para o teste de conexão.');
    }

    private static function driverError(int $code, string $context): ConnectionException
    {
        if (in_array($code, [1317, 1969, 3024], true)) {
            return new ConnectionException('execution_timeout', 'A consulta atingiu o limite de execução de 3 segundos ou foi interrompida. Nenhum resultado truncado foi utilizado.', $code);
        }
        if (in_array($code, [1045, 1698], true)) {
            return new ConnectionException('authentication_failed', 'Autenticação recusada. Verifique o usuário, a senha e a permissão de acesso a partir do servidor GLPI.', $code);
        }
        if ($code === 1049) {
            return new ConnectionException('unknown_database', 'O banco de dados informado não existe neste servidor MySQL.', $code);
        }
        if (in_array($code, [1044, 1142, 1143, 1227, 1370], true)) {
            return new ConnectionException('permission_denied', 'O usuário não tem permissão para ler o banco, a tabela ou as colunas necessárias.', $code);
        }
        if (in_array($code, [1146, 1109], true)) {
            return new ConnectionException('missing_table', 'A tabela esperada não foi encontrada no banco selecionado.', $code);
        }
        if ($code === 1054) {
            return new ConnectionException('missing_columns', 'A tabela existe, mas não contém todas as colunas esperadas.', $code);
        }
        if ($code === 2026 || ($context === 'tls_connection' && $code === 0)) {
            return new ConnectionException('tls_failed', 'Falha na conexão TLS. Verifique o certificado CA, o nome do servidor e a configuração TLS do MySQL.', $code);
        }
        if (in_array($code, [1251, 2054, 2059, 2061], true)) {
            return new ConnectionException('authentication_unsupported', 'O driver MySQL não concluiu o método de autenticação exigido pelo servidor. Verifique a compatibilidade do driver e a configuração TLS.', $code);
        }
        if (in_array($code, [1040, 1129, 1130, 1203, 2002, 2003, 2005, 2006, 2013, 2055], true)) {
            return new ConnectionException('connection_unavailable', 'O servidor MySQL não está acessível ou a conexão foi interrompida. Verifique endereço, porta, rede, autorização do host e limites de tempo.', $code);
        }
        return new ConnectionException(
            $context === 'query' ? 'query_failed' : 'connection_failed',
            $context === 'query' ? 'Não foi possível concluir a consulta de diagnóstico do banco.' : 'Não foi possível estabelecer a conexão com o banco de dados.',
            $code
        );
    }

    private static function integerOption($value, int $min, int $max, string $reason, string $message): int
    {
        if (is_bool($value) || !is_scalar($value)) {
            throw new ConnectionException($reason, $message);
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            throw new ConnectionException($reason, $message);
        }
        return $number;
    }

    private static function safeServerText(string $value): string
    {
        return substr((string)preg_replace('/[^a-zA-Z0-9._ +~:\/-]/', '', $value), 0, 160);
    }

    /**
     * mysqli can emit warnings even when it also throws. Keep the application's
     * error handler from recording native connection details, then restore it.
     * This does not modify the process-wide mysqli reporting mode.
     */
    private static function quietly(callable $operation)
    {
        set_error_handler(static function () {
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
