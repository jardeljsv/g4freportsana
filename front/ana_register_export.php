<?php

// Normal GLPI bootstrap performs its native POST/CSRF validation.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\RegisterExporter;

@ini_set('display_errors', '0');
header('Cache-Control: no-store');
$csrf = Security::csrfToken();
$reply = static function (int $code, string $message) use ($csrf): void {
    g4fra_json_response($code, ['ok' => false, 'error' => $message, 'csrf_token' => $csrf]);
};
$cfg = Config::merged();
if (Security::currentUserId() <= 0 || !Security::canUse($cfg)) {
    $reply(403, 'É necessária permissão de acesso aos relatórios para exportar os cadastros.');
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    $reply(405, 'Método não permitido.');
}
$nonce = $_POST['_ana_nonce'] ?? null;
$expected = $_SESSION['g4freportsana_ana_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected === '' || !hash_equals($expected, $nonce)) {
    $reply(403, 'Sessão expirada. Recarregue a área ANA antes de exportar o cadastro.');
}
try {
    $kind = $_POST['kind'] ?? null;
    $raw = $_POST['rows'] ?? null;
    $scope = $_POST['scope'] ?? 'all';
    $month = $_POST['month'] ?? '';
    if (!is_string($kind) || !is_string($raw) || !is_string($scope) || !is_string($month)) {
        throw new InvalidArgumentException('Dados de exportação inválidos.');
    }
    if ($kind !== 'tests') throw new InvalidArgumentException('Cadastro não disponível no contrato ANA.');
    // Export the entered values only; no writes, register locks or source queries.
    $binary = RegisterExporter::export($kind, $raw, $scope, $month);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . RegisterExporter::filename($kind) . '"');
    header('X-Content-Type-Options: nosniff');
    if ($csrf !== '') header('X-G4F-CSRF-Token: ' . $csrf);
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
} catch (InvalidArgumentException $e) {
    $reply(400, $e->getMessage());
} catch (Throwable $e) {
    $reply(500, 'Não foi possível exportar o cadastro. Os dados continuam na tela; tente novamente.');
}
