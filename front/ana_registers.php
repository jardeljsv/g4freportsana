<?php

// Normal GLPI bootstrap retains its native POST/CSRF validation. The session
// nonce below independently binds this endpoint to the open ANA reporting page.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\RegisterHistory;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
$cfg = Config::merged();
if (Security::currentUserId() <= 0 || !Security::canUse($cfg) || !Security::canConfigure()) {
    g4fra_json_response(403, ['ok' => false, 'error' => 'É necessária permissão de configuração para alterar os cadastros.']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    g4fra_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
Security::requireConfigurationReauthenticationJson();

$nonce = $_POST['_ana_nonce'] ?? null;
$expected = $_SESSION['g4freportsana_ana_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
    g4fra_json_response(403, ['ok' => false, 'error' => 'Sessão expirada. Recarregue a área ANA antes de alterar os cadastros.']);
}
$csrf = Security::csrfToken();
$reply = static function (int $code, array $result) use ($csrf): void {
    g4fra_json_response($code, array_merge($result, ['csrf_token' => $csrf]));
};

$lock = '';
$code = 200;
$result = [];
try {
    $action = $_POST['action'] ?? '';
    $kind = $_POST['kind'] ?? 'tests';
    if (!in_array($kind, ['tests'], true) || !in_array($action, ['save_tests', 'history', 'history_detail'], true)) {
        throw new InvalidArgumentException('Cadastro desconhecido.');
    }
    $lock = RegisterHistory::lock();
    // Reload while locked: the revision comparison and persistence form one operation.
    $cfg = Config::merged();
    if ($action === 'history' || $action === 'history_detail') {
        $result = ['ok' => true, 'history' => RegisterHistory::entries($cfg, $kind)];
        if ($action === 'history_detail') {
            $historyId = $_POST['history_id'] ?? '';
            if (!is_string($historyId)) throw new InvalidArgumentException('Versão inválida.');
            $result['rows'] = RegisterHistory::revisionRows($cfg, $kind, $historyId);
        }
    } else {
        $raw = $_POST['rows'] ?? null;
        $revision = $_POST['revision'] ?? null;
        if (!is_string($raw) || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
            throw new InvalidArgumentException('Conteúdo ou versão do cadastro inválidos. Recarregue a página.');
        }
        $kind = 'tests';
        $current = Settings::testNumbers($cfg['ana_test_numbers_json'] ?? '[]');
        if (!hash_equals(Settings::revision($current), $revision)) {
            $code = 409;
            $result = ['ok' => false, 'error' => 'Este cadastro foi alterado em outra sessão. Suas alterações ainda estão na tela; copie o que precisar e recarregue a página para conferir a versão salva antes de editar novamente.'];
        } else {
            $rows = Settings::testNumbers($raw);
            $history = RegisterHistory::save($cfg, $kind, $current, $rows, Security::currentUserId(), (string)($_SESSION['glpiname'] ?? ('Usuário ' . Security::currentUserId())));
            $savedConfig = Config::merged();
            $savedRows = Settings::testNumbers($savedConfig['ana_test_numbers_json'] ?? '[]');
            if (!hash_equals(Settings::revision($rows), Settings::revision($savedRows))) {
                throw new RuntimeException('O GLPI não confirmou o cadastro salvo. Recarregue a página para conferir os dados antes de tentar novamente.');
            }
            $result = ['ok' => true, 'rows' => $savedRows, 'revision' => Settings::revision($savedRows),
                'history' => $history];
        }
    }
} catch (InvalidArgumentException $e) {
    $code = 400; $result = ['ok' => false, 'error' => $e->getMessage()];
} catch (RuntimeException $e) {
    $code = 500; $result = ['ok' => false, 'error' => $e->getMessage()];
} catch (Throwable $e) {
    $code = 500; $result = ['ok' => false, 'error' => 'Não foi possível concluir a operação. Suas alterações continuam nesta tela. Verifique a sessão e tente novamente.'];
} finally {
    if ($lock !== '') {
        try {
            RegisterHistory::unlock($lock);
        } catch (Throwable $releaseError) {
            // Cleanup must not turn a confirmed save into an HTTP 500, nor
            // replace the original validation/persistence error. Do not log
            // database messages or submitted staff/phone values.
            try {
                g4fra_log(defined('GLPI_ROOT') ? GLPI_ROOT : null, 'ANA_REGISTER_LOCK_RELEASE_FAILED');
            } catch (Throwable $logError) {
                // Logging is best-effort; the operation's JSON still follows.
            }
        }
    }
}
$reply($code, $result);
