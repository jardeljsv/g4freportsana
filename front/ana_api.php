<?php
// Native GLPI bootstrap validates the POST CSRF token before this endpoint runs.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\Repository;
use GlpiPlugin\G4freportsana\Ana\Calculator;
use GlpiPlugin\G4freportsana\Ana\SnapshotStore;
use GlpiPlugin\G4freportsana\Ana\FinancialSummary;
use GlpiPlugin\G4freportsana\Ana\OfficePackage;
use GlpiPlugin\G4freportsana\Database\ConnectionException;

@ini_set('display_errors', '0');
@set_time_limit(120);
header('Cache-Control: no-store');
$cfg = Config::merged();
$owner = Security::currentUserId();
if ($owner <= 0 || !Security::canUse($cfg)) g4fra_json_response(403, ['ok'=>false,'error'=>'Acesso negado.']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST'); g4fra_json_response(405, ['ok'=>false,'error'=>'Método não permitido.']);
}
$nonce=$_POST['_ana_nonce'] ?? null;
$expected=$_SESSION['g4freportsana_ana_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected==='' || !hash_equals($expected,$nonce)) {
    g4fra_json_response(403,['ok'=>false,'error'=>'Sessão expirada. Recarregue a área ANA.']);
}
$csrf=Security::csrfToken();
$reply=static function(int $code,array $data) use($csrf): void {
    if (isset($data['snapshot']['financial']) && is_array($data['snapshot']['financial'])) {
        $data['snapshot']['financial']['notes'] = OfficePackage::financialNotes($data['snapshot']['financial']);
    }
    g4fra_json_response($code,array_merge($data,['csrf_token'=>$csrf]));
};
$action=$_POST['action'] ?? '';
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {
    $raw=$_POST['payload'] ?? '{}';
    if (!is_string($raw) || strlen($raw)>300000) throw new InvalidArgumentException('Os parâmetros excedem o limite permitido.');
    $input=json_decode($raw,true);
    if (!is_array($input) || json_last_error()!==JSON_ERROR_NONE) throw new InvalidArgumentException('Parâmetros de relatório inválidos.');
    if ($action==='generate') {
        $normalized=Settings::normalizeInput($input,$cfg);
        $dataset=Repository::extract($normalized);
        $snapshot=Calculator::build($normalized,$dataset);
        $snapshot['financial']=FinancialSummary::build($snapshot);
        // Freeze only this competence, never expose another month's approvals.
        $financial=$snapshot['input']['financial_config'] ?? [];
        $selectedMonth=$normalized['month'];
        $snapshot['input']['financial_config']=['baseline'=>$financial['baseline'] ?? [],'months'=>isset($financial['months'][$selectedMonth]) ? [$selectedMonth=>$financial['months'][$selectedMonth]] : []];
        unset($dataset);
        if (function_exists('gc_mem_caches')) gc_mem_caches();
        $token=SnapshotStore::save($snapshot,$owner);
        $reply(200,['ok'=>true,'token'=>$token,'snapshot'=>$snapshot]);
    }
    if ($action==='update_manual') {
        $token=$input['token'] ?? null;
        if (!is_string($token)) throw new InvalidArgumentException('Identificador de relatório inválido.');
        $snapshot=SnapshotStore::updateNarrative($token,$owner,$input);
        $reply(200,['ok'=>true,'token'=>$token,'snapshot'=>$snapshot]);
    }
    throw new InvalidArgumentException('Ação desconhecida.');
} catch (ConnectionException $e) {
    $reply(200,['ok'=>false,'reason'=>$e->getReason(),'error'=>'Não foi possível consultar a fonte de dados. Confira a configuração da conexão e o diagnóstico do plugin.']);
} catch (InvalidArgumentException $e) {
    $reply(400,['ok'=>false,'error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    $reply(200,['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    $reply(500,['ok'=>false,'error'=>'Não foi possível concluir o relatório. Nenhuma extração parcial foi disponibilizada. Consulte o diagnóstico e tente novamente.']);
}
