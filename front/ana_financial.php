<?php
// Native GLPI bootstrap validates POST/CSRF; nonce and revision bind the save.
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\FinanceSettings;
use GlpiPlugin\G4freportsana\Ana\EvidenceSettings;
use GlpiPlugin\G4freportsana\Ana\RegisterHistory;

@ini_set('display_errors','0');
header('Cache-Control: no-store');
$cfg=Config::merged();
if (Security::currentUserId()<=0 || !Security::canUse($cfg) || !Security::canConfigure()) g4fra_json_response(403,['ok'=>false,'error'=>'É necessária permissão de configuração para salvar decisões e evidências.']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))!=='POST') {
    header('Allow: POST'); g4fra_json_response(405,['ok'=>false,'error'=>'Método não permitido.']);
}
Security::requireConfigurationReauthenticationJson();

$nonce=$_POST['_ana_nonce'] ?? null; $expected=$_SESSION['g4freportsana_ana_nonce'] ?? null;
if (!is_string($nonce) || !is_string($expected) || $expected==='' || !hash_equals($expected,$nonce)) g4fra_json_response(403,['ok'=>false,'error'=>'Sessão expirada. Recarregue a área ANA.']);
$csrf=Security::csrfToken();
$code=200; $result=[]; $lock='';
try {
    $kind=$_POST['kind'] ?? '';
    if (($_POST['action'] ?? '')!=='save' || !in_array($kind,['financial','evidence','methodology'],true)) throw new InvalidArgumentException('Operação de configuração desconhecida.');
    $raw=$_POST['config'] ?? null; $revision=$_POST['revision'] ?? null;
    if (!is_string($raw) || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D',$revision)) throw new InvalidArgumentException('Conteúdo ou versão de configuração inválidos.');
    $class=$kind==='financial' ? FinanceSettings::class : ($kind==='evidence' ? EvidenceSettings::class : Settings::class);
    $key=$kind==='methodology' ? Settings::METHOD_CONFIG_KEY : $class::CONFIG_KEY;
    $next=$kind==='methodology' ? Settings::methodology($raw) : $class::normalize($raw);
    $lock=RegisterHistory::lock();
    $cfg=Config::merged();
    $current=$kind==='methodology' ? Settings::methodologyFromSettings($cfg) : $class::fromSettings($cfg);
    if (!hash_equals($class::revision($current),$revision)) {
        $code=409; $result=['ok'=>false,'error'=>'A configuração foi alterada em outra sessão. Suas alterações continuam na tela; copie o necessário e recarregue a página antes de salvar.'];
    } else {
        Config::save([$key=>$kind==='methodology' ? Settings::methodologyJson($next) : $class::json($next)]);
        $saved=$kind==='methodology' ? Settings::methodologyFromSettings(Config::merged()) : $class::fromSettings(Config::merged());
        if (!hash_equals($class::revision($next),$class::revision($saved))) throw new RuntimeException('O GLPI não confirmou o salvamento. Recarregue a página para conferir.');
        $result=['ok'=>true,'kind'=>$kind,'config'=>$saved,'revision'=>$class::revision($saved)];
    }
} catch (InvalidArgumentException $e) { $code=400; $result=['ok'=>false,'error'=>$e->getMessage()];
} catch (RuntimeException $e) { $code=500; $result=['ok'=>false,'error'=>$e->getMessage()];
} catch (Throwable $e) { $code=500; $result=['ok'=>false,'error'=>'Não foi possível salvar a configuração. Suas alterações continuam na tela.'];
} finally { if ($lock!=='') { try { RegisterHistory::unlock($lock); } catch (Throwable $e) {} } }
g4fra_json_response($code,array_merge($result,['csrf_token'=>$csrf]));
