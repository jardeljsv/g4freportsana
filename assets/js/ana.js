(function () {
  'use strict';
  const cfg = window.G4FREPORTSANA;
  if (!cfg) return;
  const $ = id => document.getElementById(id);
  const form = $('ana-report-form');
  const state = { snapshot: null, token: '', dirty: false, narrativeDirty: false, busy: false, page: 0, metrics: [], inputRevision: 0 };
  const labels = { met: 'Meta atingida', unmet: 'Meta não atingida', pending: 'Apuração pendente', provisional: 'Apuração provisória', no_occurrences: 'Sem ocorrências', not_applicable: 'Não aplicável' };
  const n = (value, digits = 2) => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('pt-BR', { maximumFractionDigits: digits, minimumFractionDigits: digits });
  const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined && text !== null) e.textContent = String(text); if (cls) e.className = cls; return e; };
  const obj = value => value && typeof value === 'object' ? value : {};
  const list = value => Array.isArray(value) ? value : [];
  const display = value => value === null || value === undefined || value === '' ? '—' : typeof value === 'boolean' ? (value ? 'Sim' : 'Não') : Array.isArray(value) ? value.filter(v => typeof v !== 'object').map(display).join(', ') || '—' : typeof value === 'object' ? '—' : String(value);
  const duration = value => { if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '—'; let s = Math.max(0, Math.round(Number(value))); return Math.floor(s / 3600) + ':' + String(Math.floor(s % 3600 / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
  const month = () => form.elements.month.value;
  function showReauthentication(value) {
    let url;
    try { url = new URL(value, location.href); } catch (_) { return; }
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
    let notice = $('ana-reauth-notice');
    if (!notice) { notice = el('p', null, 'ana-notice'); notice.id = 'ana-reauth-notice'; notice.setAttribute('role', 'status'); $('ana-status').before(notice); }
    const link = el('a', 'Confirmar identidade no GLPI'); link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer';
    notice.replaceChildren(el('span', 'Suas alterações continuam nesta página. '), link, el('span', ' em outra aba; depois volte e tente salvar novamente.'));
  }
  async function request(url, data) {
    const body = new URLSearchParams(Object.assign({}, data, { _ana_nonce: cfg.nonce }));
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    // GLPI 11 preserves the AJAX token; GLPI 12 validates browser-origin
    // metadata natively and must not receive a fabricated replacement token.
    if (cfg.csrf_token) { body.set('_glpi_csrf_token', cfg.csrf_token); headers['X-Glpi-Csrf-Token'] = cfg.csrf_token; }
    else body.delete('_glpi_csrf_token');
    const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers });
    let json;
    try { json = await response.json(); } catch (_) { throw new Error('O servidor não retornou uma resposta válida. Confira a sessão e recarregue a página.'); }
    if (json.csrf_token) cfg.csrf_token = json.csrf_token;
    if (json.reauth_required && typeof json.reauth_url === 'string') showReauthentication(json.reauth_url);
    if (!response.ok || !json.ok) throw new Error(json.error || 'Não foi possível concluir a operação.');
    const reauthNotice = $('ana-reauth-notice'); if (reauthNotice) reauthNotice.remove();
    return json;
  }
  function status(text, error) { $('ana-status').textContent = text; $('ana-status').className = error ? 'ana-error' : ''; }
  function buttons() {
    $('ana-generate').disabled = state.busy;
    $('ana-update-texts').disabled = state.busy || !state.token || state.dirty;
    document.querySelectorAll('[data-export]').forEach(b => { b.disabled = state.busy || !state.token || state.dirty || state.narrativeDirty; });
  }
  function invalidate(message) { state.inputRevision++; if (state.token) { state.dirty = true; status(message || 'Os parâmetros mudaram. Gere novamente para atualizar a apuração e as exportações.'); } buttons(); }
  function payload() { const result = {}; for (const [key, value] of new FormData(form)) result[key] = value; return result; }
  function methodStatus() {
    const current = payload(), saved = cfg.methodology;
    let changed = false;
    Object.keys(cfg.policy_labels).concat(['entity_id', 'n1_group_ids', 'n2_group_ids', 'n3_group_ids']).forEach(key => {
      const expected = Array.isArray(saved[key]) ? saved[key].join(',') : String(saved[key]);
      const actual = Array.isArray(saved[key]) ? String(current[key] || '').split(/[\s,;]+/).filter(Boolean).join(',') : String(current[key]);
      if (actual !== expected) changed = true;
    });
    $('ana-method-state').textContent = saved.methodology_confirmed && !changed
      ? 'Referência da metodologia: ' + saved.methodology_reference
      : changed ? 'Critérios diferentes da metodologia cadastrada.' : 'Metodologia sem aprovação registrada.';
  }
  function table(rows, columns, target, empty) {
    target.replaceChildren();
    if (!rows.length) { target.append(el('p', empty || 'Sem registros nesta seleção.', 'ana-empty')); return; }
    const t = el('table', null, 'ana-table'), head = el('thead'), tr = el('tr'), body = el('tbody');
    columns.forEach(c => tr.append(el('th', c[0]))); head.append(tr); t.append(head);
    rows.forEach(row => { const r = el('tr'); columns.forEach(c => { const cell = el('td'); const value = typeof c[1] === 'function' ? c[1](row) : row[c[1]]; if (value instanceof Node) cell.append(value); else cell.textContent = display(value); r.append(cell); }); body.append(r); });
    t.append(body); target.append(t);
  }
  const fieldLabels = {
    id:'ID',ticket_id:'Chamado',tickets_id:'Chamado',title:'Título',type:'Tipo',status:'Situação',category:'Categoria',category_code:'Código do catálogo',
    group_names:'Equipes atuais',group_ids:'IDs das equipes atuais',responsibility_group_names:'Equipes responsáveis',responsibility_group_ids:'IDs das equipes responsáveis',technician_names:'Técnicos',technician_ids:'IDs dos técnicos',
    priority:'Prioridade GLPI',priority_contract:'Prioridade contratual',priority_basis:'Critério de prioridade',opened_at:'Abertura',created_at:'Criação',taken_at:'Início de atendimento',solved_at:'Solução',closed_at:'Fechamento',
    native_tit_seconds:'TIT nativo (s)',native_tms_seconds:'TMS nativo (s)',tit_seconds:'TIT apurado (s)',tms_seconds:'TMS apurado (s)',tms_limit_seconds:'Limite TMS (s)',tit_within_target:'TIT no prazo',tms_within_target:'TMS no prazo',approved_pause_seconds:'Suspensões aprovadas (s)',
    scope_reason:'Critério de escopo',scope_review:'Escopo em revisão',scope_included:'Incluído no escopo',clock_issues:'Pendências da medição de tempo',
    satisfaction:'Nota de satisfação',satisfaction_valid:'Resposta válida no período',satisfaction_exclusion_reason:'Critério da pesquisa',reopened:'Reaberto',has_solution_evidence:'Registro de solução',reopening_evidence_complete:'Histórico de reabertura completo',
    solution_status:'Situação da solução',reason:'Motivo',solution_at:'Data da solução',solver_name:'Responsável pela solução',solver_id:'ID do responsável pela solução',approval_user_id:'ID do responsável pelo aceite',
    log_id:'Registro do histórico',occurred_at:'Data do evento',actor_id:'ID do responsável',actor_raw:'Responsável',from_status:'Situação anterior',to_status:'Situação posterior',
    solutions:'Soluções',reopening_events:'Reaberturas',status_events:'Histórico de situações',group_events:'Histórico de equipes',group_id:'Equipe ID',action:'Ação',
    call_key:'Identificador de contagem',callid:'Identificador da ligação',interaction_id:'Interação',queue:'Fila',queues:'Filas',caller:'Origem',agent:'Agente',entered_at:'Entrada',answered_at:'Atendimento',ended_at:'Conclusão',
    answered:'Atendida',abandoned:'Abandonada',desisted:'Desistência',abandon_recorded:'Desligamento antes do atendimento',abandon_wait_unknown:'Desligamento sem tempo de espera registrado',ips6_included:'Incluída no IPS6',ips6_exclusion_reason:'Motivo da exclusão do IPS6',unknown_outcome:'Desfecho desconhecido',transferred:'Transferida',overflow:'Transbordo',wait_seconds:'Espera (s)',talk_seconds:'Conversação (s)',outcome:'Desfecho',leg_count:'Passagens por fila',
    inclusion_reason:'Critério de inclusão',exclusion_reason:'Motivo da exclusão',excluded_reason:'Motivo da exclusão',in_period:'Entrada no período',context_only:'Registro de contexto',events:'Eventos',event:'Evento',
    data1:'Dado do evento 1',data2:'Dado do evento 2',data3:'Dado do evento 3',data4:'Dado do evento 4',data5:'Dado do evento 5',
    metric_id:'Indicador',label:'Descrição',description:'Descrição',service:'Serviço',window_seconds:'Janela de medição (s)',downtime_seconds:'Indisponibilidade (s)',excluded_seconds:'Exclusões (s)',
    evidence_reference:'Referência da evidência',approval_reference:'Referência de aprovação',reference:'Referência',due_date:'Prazo',target_percent:'Meta (%)',completion_percent:'Realizado (%)',acceptance_reference:'Referência de aceite',
    value:'Resultado',target:'Meta',deficit:'Déficit',points:'Pontos',amount:'Valor (R$)',quantity:'Quantidade',code:'Código',note:'Observação',date:'Data',at:'Data',date_mod:'Atualização'
  };
  const ticketStates = {1:'Novo',2:'Em atendimento',3:'Planejado',4:'Pendente',5:'Solucionado',6:'Fechado'};
  const solutionStates = {1:'Sem validação',2:'Aguardando aceite',3:'Aceita',4:'Recusada'};
  const externalIps6 = () => list(obj(state.snapshot).metrics).some(metric => metric.id === 'IPS6' && metric.evidence_reference);
  const pendingCallInclusion = row => {
    const metric = list(obj(state.snapshot).metrics).find(metric => metric.id === 'IPS6') || {};
    const include = metric.desistencias_included === undefined ? obj(obj(state.snapshot).input).ips6_include_desistencias : metric.desistencias_included;
    return include === false && row.abandon_wait_unknown === true;
  };
  function fieldValue(key, value, row, kind) {
    if ((key === 'ips6_included' || key === 'ips6_exclusion_reason') && externalIps6()) return '—';
    if ((key === 'ips6_included' || key === 'ips6_exclusion_reason') && pendingCallInclusion(row)) return key === 'ips6_included' ? 'Pendente' : '—';
    if (key === 'solution_status') return value === null || value === undefined ? 'Sem registro de solução' : solutionStates[value] || display(value);
    if (key === 'from_status' || key === 'to_status') return ticketStates[value] || display(value);
    if (key === 'status') return labels[value] || ((kind === 'solutions' || Object.prototype.hasOwnProperty.call(row,'solver_id')) ? solutionStates[value] : ticketStates[value]) || display(value);
    if (key === 'type') return Number(value) === 1 ? 'Incidente' : Number(value) === 2 ? 'Requisição' : display(value);
    if (key === 'action') return {add:'Inclusão',remove:'Remoção'}[value] || display(value);
    return display(value);
  }
  function recordDetails(row, kind) {
    const dl = el('dl', null, 'ana-record-details');
    Object.keys(fieldLabels).forEach(key => {
      if (!Object.prototype.hasOwnProperty.call(row,key)) return;
      const value = row[key];
      if ((value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) && key !== 'solution_status') return;
      const dd = el('dd');
      if (Array.isArray(value) && value.some(v => v && typeof v === 'object')) {
        value.forEach((item,index) => { const section = el('details'); section.append(el('summary','Registro ' + (index + 1)), recordDetails(obj(item),key)); dd.append(section); });
      } else dd.textContent = fieldValue(key,value,row,kind);
      dl.append(el('dt',fieldLabels[key]),dd);
    });
    return dl;
  }
  const details = row => { const d = el('details'); d.append(el('summary', 'Detalhes')); let loaded = false; d.addEventListener('toggle', () => { if (d.open && !loaded) { d.append(recordDetails(row,'')); loaded = true; } }); return d; };
  const ticketColumns = [['ID', 'id'], ['Tipo', r => Number(r.type) === 1 ? 'Incidente' : Number(r.type) === 2 ? 'Requisição' : r.type], ['Categoria', 'category'], ['Equipe responsável', r => r.responsibility_group_names || r.responsibility_group_ids || r.group_names || r.group_ids], ['Prioridade', r => r.contract_priority || r.priority_contract || r.priority], ['Abertura', 'opened_at'], ['Solução', 'solved_at'], ['Fechamento', 'closed_at'], ['TIT', r => duration(Object.prototype.hasOwnProperty.call(r,'tit_seconds') ? r.tit_seconds : r.takeintoaccount_seconds)], ['TMS', r => duration(Object.prototype.hasOwnProperty.call(r,'tms_seconds') ? r.tms_seconds : r.solve_seconds)], ['Situação / escopo', r => r.scope_reason || r.metric_note || r.status], ['Detalhes', details]];
  const callStatus = row => row.answered ? 'Atendida' : row.desisted ? 'Desistência' : row.abandon_wait_unknown ? 'Abandono sem tempo de espera' : row.abandoned ? 'Abandonada' : row.unknown_outcome ? 'Desfecho desconhecido' : 'Registrada';
  const callInclusion = row => {
    if (externalIps6()) return '—';
    if (pendingCallInclusion(row)) return 'Pendente';
    const reason = row.ips6_exclusion_reason || row.exclusion_reason || row.excluded_reason || '';
    if (row.ips6_included === false || reason) return reason ? 'Excluída: ' + reason : 'Excluída';
    return row.ips6_included === true ? 'Incluída' : '—';
  };
  const callColumns = [['ID / correlação', r => r.call_key || r.id || r.interaction_id || r.callid], ['Fila(s)', r => r.queues || r.queue], ['Entrada', 'entered_at'], ['Origem', 'caller'], ['Agente', 'agent'], ['Atendida', r => r.answered ? 'Sim' : 'Não'], ['Espera', r => duration(r.wait_seconds)], ['Conversação', r => duration(r.talk_seconds)], ['Situação', callStatus], ['IPS6', callInclusion], ['Detalhes', details]];
  function genericColumns(rows) {
    const preferred = ['ticket_id', 'id', 'occurred_at', 'date', 'at', 'date_mod', 'solution_status', 'from_status', 'to_status', 'status', 'service', 'metric_id', 'description', 'reference'];
    const keys = preferred.filter(k => rows.some(r => Object.prototype.hasOwnProperty.call(r, k)));
    return keys.slice(0, 7).map(k => [fieldLabels[k], r => fieldValue(k,r[k],r,'')]).concat([['Detalhes', details]]);
  }
  function renderSources() {
    const source = $('ana-source').value, term = $('ana-source-search').value.trim().toLocaleLowerCase('pt-BR');
    const rows = list(state.snapshot && state.snapshot[source]).map(r => typeof r === 'object' ? r : { id: r });
    const filtered = term ? rows.filter(row => JSON.stringify(row).toLocaleLowerCase('pt-BR').includes(term)) : rows;
    const pages = Math.max(1, Math.ceil(filtered.length / 50)); state.page = Math.max(0, Math.min(state.page, pages - 1));
    const visible = filtered.slice(state.page * 50, state.page * 50 + 50);
    const cols = ['tickets', 'scope_review', 'excluded_tickets'].includes(source) ? ticketColumns : ['calls', 'call_legs', 'excluded_calls'].includes(source) ? callColumns : genericColumns(visible);
    table(visible, cols, $('ana-source-table'));
    $('ana-page-label').textContent = n(filtered.length, 0) + ' registros · página ' + (state.page + 1) + ' de ' + pages;
    $('ana-prev').disabled = state.page === 0; $('ana-next').disabled = state.page >= pages - 1;
  }
  function metricValue(metric) { return metric.value === null || metric.value === undefined ? '—' : metric.value_kind === 'mean_score' ? n(metric.value) + ' / 5' : metric.unit === 'compliance' ? (Number(metric.value) === 1 ? 'Conforme' : 'Não conforme') : n(metric.value) + '%'; }
  function evidenceButton(metric) { const b = el('button', 'Ver evidências', 'btn btn-outline-secondary btn-sm'); b.type = 'button'; b.addEventListener('click', () => { $('ana-evidence-metric').value = metric.id; $('ana-evidence-filter').value = 'exceptions'; renderEvidence(); $('ana-evidence-table').scrollIntoView({ behavior: 'smooth', block: 'center' }); }); return b; }
  function renderMetrics() {
    const target = $('ana-metrics'); target.replaceChildren(); state.metrics = [];
    list(state.snapshot.metrics).forEach(metric => {
      state.metrics.push(metric); const card = el('article', null, 'ana-metric ' + metric.status), heading = el('div', null, 'ana-metric-head');
      heading.append(el('h3', metric.id + ' · ' + metric.label), el('span', labels[metric.status] || metric.status, 'ana-tag ' + metric.status)); card.append(heading);
      if (list(metric.children).length) {
        const children = el('div', null, 'ana-children');
        metric.children.forEach(child => { if (metric.id==='IPS3'||metric.id==='IPS4') state.metrics.push(child); const line = el('div', null, 'ana-child'); line.append(el('strong', (child.priority || child.label || child.id) + ': ' + metricValue(child)), el('span', labels[child.status] || child.status, 'ana-tag ' + child.status), el('span', display(child.numerator) + ' / ' + display(child.denominator)), evidenceButton((metric.id==='IPS3'||metric.id==='IPS4') ? child : metric)); children.append(line); });
        card.append(children);
        if (!['IPS3','IPS4'].includes(metric.id)) card.append(el('div', metricValue(metric), 'ana-metric-value'));
        if (metric.target !== null && metric.target !== undefined) card.append(el('p', 'Meta: ' + n(metric.target) + '%'));
      } else {
        card.append(el('div', metricValue(metric), 'ana-metric-value'));
        if (metric.value !== null && metric.value !== undefined && Number.isFinite(Number(metric.value))) {
          const meter = el('div', null, 'ana-meter'), fill = el('span'); fill.style.width = Math.min(100, Math.max(0, Number(metric.value) * (metric.value_kind === 'mean_score' ? 20 : 1))) + '%'; meter.append(fill); card.append(meter);
        }
        card.append(el('p', 'Meta: ' + (metric.target === null || metric.target === undefined ? 'conforme evidência' : n(metric.target) + (metric.value_kind === 'mean_score' ? ' / 5' : '%'))));
        if (metric.numerator !== null && metric.denominator !== null) card.append(el('p', (metric.numerator_label || 'Numerador') + ': ' + display(metric.numerator) + ' · ' + (metric.denominator_label || 'Denominador') + ': ' + display(metric.denominator)));
        card.append(evidenceButton(metric));
      }
      if (metric.coverage_percent !== undefined && metric.coverage_percent !== null) card.append(el('p', (metric.id === 'IPS1' ? 'Taxa de resposta: ' : 'Cobertura da avaliação: ') + n(metric.id === 'IPS1' ? metric.response_rate_percent ?? metric.coverage_percent : metric.coverage_percent) + '% · ' + display(metric.evaluated_count) + ' de ' + display(metric.cohort_count), 'ana-help'));
      if (list(metric.unresolved_ids).length) card.append(el('p', n(metric.unresolved_ids.length,0) + ' registros com informação insuficiente.', 'ana-help'));
      if (metric.id === 'IPS6') {
        const include = obj(state.snapshot.input).ips6_include_desistencias !== false;
        card.append(el('p','Desistências (<30 s): ' + (include ? 'incluídas no IPS6.' : 'excluídas do IPS6.'),'ana-help'));
        if (Number(metric.excluded_count) > 0) card.append(el('p',n(metric.excluded_count,0) + ' desistências excluídas.','ana-help'));
      }
      if (metric.method) { const method = el('details',null,'ana-method-detail'); method.append(el('summary','Como foi calculado'),el('p',metric.method)); card.append(method); }
      target.append(card);
    });
    const select = $('ana-evidence-metric'), prior = select.value; select.replaceChildren();
    state.metrics.filter(m => !['IPS3','IPS4'].includes(m.id)).forEach(m => { const option = el('option', m.id + ' · ' + (m.label || m.priority)); option.value = m.id; select.append(option); });
    if (state.metrics.some(m => m.id === prior)) select.value = prior;
    renderEvidence();
  }
  function renderEvidence() {
    const metric = state.metrics.find(m => m.id === $('ana-evidence-metric').value); if (!metric) return;
    const allIds = new Set(list(metric.denominator_ids).map(String)), good = new Set(list(metric.evidence_ids).map(String)), unknown = new Set(list(metric.unresolved_ids).map(String));
    const mode = $('ana-evidence-filter').value;
    let base = metric.id === 'IPS6' ? list(state.snapshot.calls) : list(state.snapshot.tickets);
    if (['IPS7','IPS8','IPS10'].includes(metric.id)) base = list(obj(state.snapshot.external_evidence).services || obj(state.snapshot.input.external_evidence).services).filter(r => r.metric_id === metric.id);
    if (metric.id === 'IPS9') base = list(obj(state.snapshot.external_evidence).controls || obj(state.snapshot.input.external_evidence).controls);
    const external = ['IPS7','IPS8','IPS9','IPS10'].includes(metric.id);
    let rows = external ? base : base.filter(r => allIds.has(String(r.call_key || r.id || r.interaction_id || r.callid)) || unknown.has(String(r.call_key || r.id || r.interaction_id || r.callid)));
    if (!external && mode !== 'all') rows = rows.filter(r => { const id = String(r.call_key || r.id || r.interaction_id || r.callid); return mode === 'numerator' ? good.has(id) : !good.has(id) || unknown.has(id); });
    $('ana-evidence-summary').textContent = metric.id + ' · ' + n(rows.length, 0) + ' registros' + (rows.length > 100 ? ' · exibindo 100' : '') + (unknown.size ? ' · ' + unknown.size + ' com informação insuficiente' : '');
    table(rows.slice(0, 100), external ? genericColumns(rows) : metric.id === 'IPS6' ? callColumns : ticketColumns, $('ana-evidence-table'));
  }
  function renderHeatmap() {
    const counts = Array.from({ length: 7 }, () => Array(24).fill(0));
    const bySolution = obj(state.snapshot.input).scope_basis === 'solved';
    const dateKey = bySolution ? 'solved_epoch' : 'closed_epoch';
    const reportMonth = obj(state.snapshot.period).month;
    const title = $('ana-heatmap-title');
    if (title) title.textContent = 'Distribuição ' + (bySolution ? 'das soluções' : 'dos fechamentos') + ' por dia e hora';
    const formatter = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', weekday: 'short', hour: '2-digit', hourCycle: 'h23' });
    const weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    list(state.snapshot.tickets).forEach(t => {
      const epoch = Number(t[dateKey]);
      if (!Number.isFinite(epoch) || epoch <= 0) return;
      const date = new Date(epoch * 1000);
      if (!Number.isFinite(date.getTime())) return;
      const parts = Object.fromEntries(formatter.formatToParts(date).map(part => [part.type, part.value]));
      if (parts.year + '-' + parts.month !== reportMonth) return;
      const day = weekdays.indexOf(parts.weekday), hour = Number(parts.hour);
      if (day >= 0 && hour >= 0 && hour < 24) counts[day][hour]++;
    });
    const max = Math.max(1, ...counts.flat()), target = $('ana-heatmap'); target.replaceChildren();
    const tableEl = el('table', null, 'ana-table ana-heatmap'), head = el('tr'); head.append(el('th', 'Dia'));
    for (let h = 0; h < 24; h++) head.append(el('th', String(h).padStart(2,'0'))); const thead = el('thead'); thead.append(head); tableEl.append(thead);
    const tbody = el('tbody'); ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'].forEach((day, i) => { const tr = el('tr'); tr.append(el('th',day)); counts[i].forEach(count => { const td = el('td', count || '·'); td.style.backgroundColor = 'rgba(22,77,82,' + (count / max * .7) + ')'; if (count / max > .6) td.style.color = '#fff'; tr.append(td); }); tbody.append(tr); }); tableEl.append(tbody); target.append(tableEl);
  }
  function renderFinancial() {
    const f = obj(state.snapshot.financial), box = $('ana-financial-summary'); box.replaceChildren(); box.append(el('h3','Composição financeira'));
    const money = value => value === null || value === undefined ? 'Pendente' : Number(value).toLocaleString('pt-BR',{ style:'currency',currency:'BRL' });
    const grid = el('div',null,'ana-totals'); [['Base mensal',f.gross_value],['Glosa apurada',f.deduction_total],['Ajustes',f.adjustment_total],['Valor líquido',f.net_value]].forEach(([label,value])=>{ const stat=el('div',null,'ana-stat');stat.append(el('strong',money(value)),el('span',label));grid.append(stat);});box.append(grid);
    if (!f.complete) box.append(el('p','Apuração financeira incompleta.','ana-notice'));
    const financialNotes = [...new Set(list(f.notes).filter(note => typeof note === 'string' && note.trim() !== ''))];
    if (financialNotes.length && !f.complete) { const notes=el('details');notes.append(el('summary','Pendências da medição'));financialNotes.forEach(note=>notes.append(el('p',note,'ana-help')));box.append(notes); }
    if (list(f.point_items).length) { const d=el('details'),wrap=el('div',null,'ana-table-wrap');d.append(el('summary','Memória de pontos e glosas'));table(f.point_items,[['Indicador',r=>r.id||r.metric_id],['Resultado','value'],['Pontos','points'],['Situação',r=>labels[r.status]||r.status],['Detalhes',details]],wrap);d.append(wrap);box.append(d); }
  }
  function measurementWarnings(snapshot) {
    const warnings = [];
    list(snapshot.warnings).forEach(entry => {
      const message = typeof entry === 'string' ? entry : entry && typeof entry.message === 'string' ? entry.message : '';
      if (!message || message.startsWith('Cada consulta é uma leitura independente.')) return;
      if (message.startsWith('Metodologia ainda não confirmada')) warnings.push('Metodologia sem aprovação registrada.');
      else if (message.startsWith('Há chamados com responsabilidade/escopo pendentes')) warnings.push(n(list(snapshot.scope_review).length,0) + ' chamados com escopo em revisão.');
      else if (message.startsWith('P1/P2 excedem 70%')) { const cap=obj(snapshot.priority_cap); warnings.push('Prioridades P1/P2: ' + n(cap.high_priority_count,0) + ' chamados; limite: ' + n(cap.maximum,0) + '; excedentes: ' + n(cap.excess,0) + '.'); }
      else if (message.startsWith('Telefonia indisponível nesta extração:') || message.startsWith('Configuração da telefonia indisponível.')) warnings.push('Telefonia indisponível nesta extração.');
      else if (message.startsWith('Conexão de telefonia desabilitada.')) warnings.push('Conexão de telefonia desabilitada.');
      else warnings.push(message);
    });
    return [...new Set(warnings)];
  }
  function render() {
    const s=state.snapshot; $('ana-preview').hidden=false; $('ana-preview-title').textContent=s.input.report_title||'Relatório mensal ANA'; $('ana-generated-at').textContent='Competência '+s.period.month+' · '+display(s.generated_at);
    const totals=$('ana-totals');totals.replaceChildren(); [['Chamados',s.totals.tickets],['Incidentes',s.totals.incidents],['Requisições',s.totals.requests],['Revisão de escopo',s.totals.scope_review],['Ligações recebidas',s.totals.calls_received],['Ligações atendidas',s.totals.calls_answered]].forEach(([name,value])=>{const box=el('div',null,'ana-stat');box.append(el('strong',n(value,0)),el('span',name));totals.append(box);});
    if (Object.prototype.hasOwnProperty.call(s.totals,'calls_desisted')) {
      const outcomes = [['Desistências',s.totals.calls_desisted],['Abandonos',s.totals.calls_abandoned]];
      if (Number(s.totals.calls_abandon_wait_unknown) > 0) outcomes.push(['Abandonos sem espera registrada',s.totals.calls_abandon_wait_unknown]);
      outcomes.forEach(([name,value])=>{const box=el('div',null,'ana-stat');box.append(el('strong',n(value,0)),el('span',name));totals.append(box);});
    }
    const warnings=$('ana-warnings');warnings.replaceChildren();measurementWarnings(s).forEach(w=>warnings.append(el('p',w,'ana-notice')));
    $('ana-rule-notes').replaceChildren();
    renderFinancial();renderMetrics();renderSources();renderHeatmap();buttons();
  }
  form.addEventListener('submit',async event=>{event.preventDefault();if(state.busy||!form.reportValidity())return;const submitted=JSON.stringify(payload()),revision=state.inputRevision;state.busy=true;buttons();status('Gerando relatório…');try{const result=await request(cfg.api_url,{action:'generate',payload:submitted});if(revision!==state.inputRevision||submitted!==JSON.stringify(payload()))throw new Error('Os parâmetros, textos ou cadastros mudaram durante a consulta. Suas alterações foram mantidas; gere novamente para atualizar o relatório.');state.snapshot=result.snapshot;state.token=result.token;state.dirty=false;state.narrativeDirty=false;state.page=0;render();status('Relatório gerado.');}catch(error){status(error.message,true);}finally{state.busy=false;buttons();}});
  form.addEventListener('input',event=>{if(event.target.dataset.narrative){state.narrativeDirty=!!state.token;buttons();if(state.token)status('Atualize os textos antes de exportar.');}else{invalidate();methodStatus();}});
  form.addEventListener('change',event=>{if(!event.target.dataset.narrative){invalidate();methodStatus();}if(event.target.name==='month')document.dispatchEvent(new CustomEvent('ana-month-change',{detail:{month:month()}}));});
  $('ana-update-texts').addEventListener('click',async()=>{if(state.busy||!state.token||state.dirty)return;const input=payload(),submitted=JSON.stringify(input),revision=state.inputRevision;state.busy=true;buttons();try{input.token=state.token;const result=await request(cfg.api_url,{action:'update_manual',payload:JSON.stringify(input)});if(revision!==state.inputRevision||submitted!==JSON.stringify(payload()))throw new Error('Os parâmetros, textos ou cadastros mudaram durante a atualização. Suas alterações foram mantidas; atualize os textos ou gere novamente antes de exportar.');state.snapshot=result.snapshot;state.narrativeDirty=false;render();status('Textos atualizados na mesma extração.');}catch(e){status(e.message,true);}finally{state.busy=false;buttons();}});
  document.querySelectorAll('[data-export]').forEach(button=>button.addEventListener('click',()=>{if(!state.token||state.dirty||state.narrativeDirty)return;const url=new URL(cfg.export_url,location.href);url.searchParams.set('token',state.token);url.searchParams.set('format',button.dataset.export);window.location.assign(url.href);}));
  $('ana-source').addEventListener('change',()=>{state.page=0;renderSources();});$('ana-source-search').addEventListener('input',()=>{state.page=0;renderSources();});$('ana-prev').addEventListener('click',()=>{state.page--;renderSources();});$('ana-next').addEventListener('click',()=>{state.page++;renderSources();});$('ana-evidence-metric').addEventListener('change',renderEvidence);$('ana-evidence-filter').addEventListener('change',renderEvidence);
  window.ANA_UI={cfg,el,request,month,invalidate,methodStatus,table,details,display,n};methodStatus();buttons();
}());
