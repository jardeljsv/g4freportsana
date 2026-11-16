<?php
// Database diagnostics deliberately use SAVED settings. Only the source name
// travels in the GET URL, preserving the deployment's proxy-compatible flow.

require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
use GlpiPlugin\G4freportsana\Database\ConnectionException;
use GlpiPlugin\G4freportsana\Database\MysqlClient;

@ini_set('display_errors', '0');
@set_time_limit(45);

if (!Security::canConfigure()) {
    g4fra_json_response(403, ['ok' => false, 'reason' => 'access_denied', 'message' => 'Acesso negado.']);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    g4fra_json_response(405, ['ok' => false, 'reason' => 'method_not_allowed', 'message' => 'Método não permitido.']);
}

Security::requireConfigurationReauthenticationJson();

$source = $_GET['source'] ?? '';
if (!is_string($source) || !in_array($source, ['glpi', 'callcenter'], true)) {
    g4fra_json_response(400, ['ok' => false, 'reason' => 'invalid_source', 'message' => 'Selecione a conexão GLPI ou Callcenter.']);
}

try {
    $connection = ConnectionConfig::fromConfig($source, Config::merged());
    if (empty($connection['enabled'])) {
        g4fra_json_response(200, ['ok' => false, 'reason' => 'connection_disabled', 'message' => 'Habilite esta conexão e salve as configurações antes de testar.']);
    }
    // No changes to GLPI's own database handle or current API profile.
    $client = new MysqlClient($connection);
    $result = $client->test($source);
    $result['reason'] = !empty($result['ok']) ? 'connected' : 'schema_unavailable';
    $result['message'] = !empty($result['ok'])
        ? 'Conexão realizada. As tabelas e colunas necessárias estão acessíveis.'
        : 'Conexão realizada, mas algumas tabelas ou colunas necessárias não estão acessíveis. Consulte os detalhes.';
    g4fra_json_response(200, $result);
} catch (ConnectionException $e) {
    g4fra_json_response(200, [
        'ok' => false,
        'reason' => $e->getReason(),
        'message' => $e->getMessage(),
        'mysql_code' => $e->getMysqlCode(),
    ]);
} catch (InvalidArgumentException $e) {
    g4fra_json_response(200, ['ok' => false, 'reason' => 'invalid_configuration', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    // Never expose driver/GLPI exceptions: these may contain connection data.
    g4fra_json_response(200, [
        'ok' => false,
        'reason' => 'configuration_unavailable',
        'message' => 'Não foi possível carregar a conexão com segurança. Revise a configuração salva, a senha ou a variável de ambiente e a chave de criptografia do GLPI.',
    ]);
}
