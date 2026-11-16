<?php
require_once dirname(__DIR__) . '/inc/bootstrap.php';

use GlpiPlugin\G4freportsana\Config;
use GlpiPlugin\G4freportsana\Security;
use GlpiPlugin\G4freportsana\Ana\Settings;
use GlpiPlugin\G4freportsana\Ana\FinanceSettings;
use GlpiPlugin\G4freportsana\Ana\EvidenceSettings;

if (class_exists('Session')) Session::checkLoginUser();
$cfg=Config::merged();
if (!Security::canUse($cfg)) { if (class_exists('Html')) Html::displayRightError(); http_response_code(403); exit; }
$canConfigure=Security::canConfigure();
if ($canConfigure) Security::requireConfigurationReauthentication();
$month=(new DateTimeImmutable('first day of last month',new DateTimeZone('America/Sao_Paulo')))->format('Y-m');
$initial=Settings::normalizeInput(['month'=>$month],$cfg);
$methodology=Settings::methodologyFromSettings($cfg);
$financial=FinanceSettings::fromSettings($cfg);
$evidence=EvidenceSettings::fromSettings($cfg);
$tests=Settings::testNumbers($cfg['ana_test_numbers_json'] ?? '[]');
$root=rtrim((string)($GLOBALS['CFG_GLPI']['root_doc'] ?? ''),'/');
$front=$root.'/plugins/g4freportsana/front/';
$csrf=Security::csrfToken();
if (empty($_SESSION['g4freportsana_ana_nonce'])) $_SESSION['g4freportsana_ana_nonce']=bin2hex(random_bytes(24));
$h=static function($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); };
$connections=[];
foreach (['ana'=>'GLPI','callcenter'=>'Telefonia'] as $source=>$label) {
    $prefix=$source.'_db_'; $ready=(int)($cfg[$prefix.'enabled'] ?? 0)===1;
    foreach (['host','name','user'] as $part) $ready=$ready && trim((string)($cfg[$prefix.$part] ?? ''))!=='';
    $connections[$source]=['label'=>$label,'ready'=>$ready];
}
$policyLabels=[
    'scope_basis'=>['Competência dos chamados',['closed'=>'Fechamento no mês','solved'=>'Solução no mês','reference'=>'Abertura UTC e fechamento no mês']],
    'responsibility_basis'=>['Responsabilidade',['solution'=>'Equipe na última solução','closure'=>'Equipe no fechamento','current'=>'Equipe atualmente atribuída']],
    'clock_mode'=>['Medição dos tempos',['native_reference'=>'Referência nativa do GLPI','contractual'=>'Relógio contratual por eventos']],
    'tms_endpoint'=>['Evento final do TMS',['closed'=>'Fechamento','solved'=>'Solução']],
    'calendar_mode'=>['Calendário contratual',['onsite'=>'Presencial: dias úteis, 08h–20h','elapsed'=>'Tempo corrido']],
    'survey_cutoff'=>['Corte das pesquisas e reaberturas',['period_end'=>'Até o fim do mês','extraction'=>'Até a extração']],
    'reopening_mode'=>['Eventos de reabertura',['solved_or_closed'=>'Retorno após solução ou fechamento','closed_only'=>'Retorno após fechamento']],
    'call_unit'=>['Unidade da telefonia',['interaction'=>'Atendimento consolidado entre filas','queue_leg'=>'Entrada individual em fila']],
];
if (class_exists('Html')) Html::header('Relatório mensal ANA — G4F',$_SERVER['PHP_SELF'],'tools','PluginG4freportsanaMenu');
?>
<style><?php readfile(dirname(__DIR__).'/assets/css/ana.css'); ?></style>
<div class="g4fr-ana" id="g4fr-ana">
  <header class="ana-header"><div><p class="ana-eyebrow">G4F · RELATÓRIOS DE SERVIÇOS</p><h1>Relatório mensal ANA</h1><p>Apuração contratual, evidências e composição financeira.</p></div><nav><a class="btn btn-outline-secondary" href="<?php echo $h($front.'report.php'); ?>">Relatórios por profissional</a><?php if ($canConfigure): ?> <a class="btn btn-outline-secondary" href="<?php echo $h($root.'/front/config.form.php'); ?>">Configurar conexões</a><?php endif; ?></nav></header>
  <div class="ana-connections"><?php foreach ($connections as $connection): ?><span class="ana-tag <?php echo $connection['ready']?'met':'pending'; ?>"><?php echo $h($connection['label']); ?>: <?php echo $connection['ready']?'configurada':'configuração pendente'; ?></span><?php endforeach; ?></div>
  <form id="ana-report-form">
    <section class="ana-card"><h2>Competência</h2><div class="ana-grid">
      <label>Mês de referência<input class="form-control" type="month" name="month" value="<?php echo $h($month); ?>" required min="2000-01" max="2100-12"></label>
      <label>Entidade GLPI<input class="form-control" type="number" name="entity_id" min="0" max="2147483647" value="<?php echo $h($initial['entity_id']); ?>" readonly></label>
      <label>Filas do contrato<input class="form-control" type="text" value="ana, ana-transbordo" readonly></label>
    </div><details id="ana-method-options"><summary>Critérios de apuração</summary><div class="ana-grid">
      <?php foreach ($policyLabels as $name=>$definition): ?><label><?php echo $h($definition[0]); ?><select class="form-select" name="<?php echo $h($name); ?>"><?php foreach ($definition[1] as $value=>$label): ?><option value="<?php echo $h($value); ?>" <?php echo $initial[$name]===$value?'selected':''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    </div><div class="ana-grid ana-team-fields"><?php foreach (['n1_group_ids'=>'Grupos N1','n2_group_ids'=>'Grupos N2','n3_group_ids'=>'Grupos N3'] as $name=>$label): ?><label><?php echo $h($label); ?><input class="form-control" name="<?php echo $h($name); ?>" value="<?php echo $h(implode(', ',$initial[$name])); ?>" readonly></label><?php endforeach; ?></div>
    <p id="ana-method-state" class="ana-help"></p></details></section>
    <section class="ana-card"><details><summary>Textos desta emissão</summary><p class="ana-help">Os textos de procedimentos desta emissão substituem os do registro mensal.</p><div class="ana-grid"><label class="ana-wide">Título<input class="form-control" name="report_title" value="<?php echo $h($initial['report_title']); ?>" maxlength="180" data-narrative="1"></label>
    <?php foreach (['executive_summary'=>'Resumo executivo','actions'=>'Ações e justificativas','availability_notes'=>'Disponibilidade dos serviços','siem_notes'=>'Operação do SIEM','rdm_notes'=>'Mudanças e RDM','vip_notes'=>'Atendimento VIP','trs_notes'=>'Registros de sanções — TRS','improvement_notes'=>'Melhoria contínua','phone_survey_actions'=>'Acompanhamento da telefonia','notes'=>'Observações complementares'] as $name=>$label): ?><label><?php echo $h($label); ?><textarea class="form-control" name="<?php echo $h($name); ?>" rows="3" maxlength="20000" data-narrative="1"></textarea></label><?php endforeach; ?></div></details></section>
  </form>
  <?php if ($canConfigure): ?>
  <section class="ana-card"><details id="ana-admin"><summary>Cadastros do contrato</summary>
    <div class="ana-admin-tabs" role="group" aria-label="Cadastro"><button type="button" class="btn btn-outline-secondary" data-admin-tab="methodology">Metodologia</button><button type="button" class="btn btn-outline-secondary" data-admin-tab="evidence">Evidências mensais</button><button type="button" class="btn btn-outline-secondary" data-admin-tab="financial">Composição financeira</button><button type="button" class="btn btn-outline-secondary" data-admin-tab="tests">Números de teste</button></div>
    <div id="ana-admin-body"></div><p id="ana-admin-status" class="ana-help" role="status" aria-live="polite"></p>
  </details></section>
  <?php endif; ?>
  <div class="ana-action-bar"><div><button type="submit" form="ana-report-form" class="btn btn-primary" id="ana-generate">Carregar dados e gerar relatório</button><button type="button" class="btn btn-outline-secondary" id="ana-update-texts" disabled>Atualizar textos</button></div><div><button type="button" class="btn btn-success" data-export="docx" disabled>Word</button><button type="button" class="btn btn-success" data-export="xlsx" disabled>Excel</button><button type="button" class="btn btn-outline-secondary" data-export="json" disabled>JSON de auditoria</button></div></div>
  <p id="ana-status" role="status" aria-live="polite"></p>
  <section id="ana-preview" hidden><section class="ana-card"><div class="ana-preview-heading"><h2 id="ana-preview-title"></h2><span id="ana-generated-at" class="ana-help"></span></div><div id="ana-totals" class="ana-totals"></div><div id="ana-financial-summary"></div><div id="ana-warnings"></div></section>
  <section class="ana-card"><h2>Indicadores de prestação de serviços</h2><div id="ana-metrics" class="ana-metrics"></div><div id="ana-rule-notes" hidden></div></section>
  <section class="ana-card"><h2>Evidências dos indicadores</h2><div class="ana-grid"><label>Indicador<select class="form-select" id="ana-evidence-metric"></select></label><label>Mostrar<select class="form-select" id="ana-evidence-filter"><option value="exceptions">Exceções e pendências</option><option value="all">Toda a população do indicador</option><option value="numerator">Registros do numerador</option></select></label></div><p id="ana-evidence-summary"></p><div id="ana-evidence-table" class="ana-table-wrap"></div></section>
  <section class="ana-card"><details><summary id="ana-heatmap-title">Distribuição dos fechamentos por dia e hora</summary><p class="ana-help">Horário de Brasília.</p><div id="ana-heatmap" class="ana-table-wrap"></div></details></section>
  <section class="ana-card"><h2>Bases de apuração</h2><div class="ana-grid"><label>Base<select class="form-select" id="ana-source"><option value="tickets">Chamados incluídos</option><option value="scope_review">Revisão de escopo</option><option value="excluded_tickets">Chamados fora do escopo</option><option value="calls">Ligações recebidas</option><option value="call_legs">Passagens por fila</option><option value="excluded_calls">Ligações excluídas</option><option value="reopening_events">Eventos de reabertura</option><option value="unconfirmed_tickets">Soluções não aceitas ou ausentes</option></select></label><label>Buscar<input class="form-control" type="search" id="ana-source-search" placeholder="ID, equipe, categoria ou situação"></label></div><div id="ana-source-table" class="ana-table-wrap"></div><div class="ana-pager"><button type="button" class="btn btn-outline-secondary" id="ana-prev">Anterior</button><span id="ana-page-label"></span><button type="button" class="btn btn-outline-secondary" id="ana-next">Próxima</button></div></section></section>
</div>
<script>window.G4FREPORTSANA = <?php echo json_encode([
    'api_url'=>$front.'ana_api.php','export_url'=>$front.'ana_export.php','financial_url'=>$front.'ana_financial.php','registers_url'=>$front.'ana_registers.php','register_export_url'=>$front.'ana_register_export.php',
    'nonce'=>$_SESSION['g4freportsana_ana_nonce'],'csrf_token'=>$csrf,'can_configure'=>$canConfigure,
    'policy_labels'=>$policyLabels,'methodology'=>$canConfigure ? $methodology : array_intersect_key($methodology,array_flip(array_merge(array_keys($policyLabels),['entity_id','n1_group_ids','n2_group_ids','n3_group_ids','methodology_confirmed','methodology_reference']))),'methodology_revision'=>$canConfigure ? Settings::revision($methodology) : '',
    'financial_config'=>$canConfigure ? $financial : null,'financial_revision'=>$canConfigure ? FinanceSettings::revision($financial) : '',
    'evidence_config'=>$canConfigure ? $evidence : null,'evidence_revision'=>$canConfigure ? EvidenceSettings::revision($evidence) : '',
    'test_numbers'=>$canConfigure ? $tests : [],'test_revision'=>$canConfigure ? Settings::revision($tests) : '',
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;</script>
<script><?php readfile(dirname(__DIR__).'/assets/js/ana.js'); ?></script>
<script><?php readfile(dirname(__DIR__).'/assets/js/ana_financial.js'); ?></script>
<?php if (class_exists('Html')) Html::footer(); ?>
