'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const root = __dirname + '/../..';
const source = fs.readFileSync(root + '/assets/js/ana.js', 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
let checked = 0;
async function check(name, fn) { await fn(); checked++; console.log('PASS ' + name); }
function setup() {
  const d = new JSDOM(`<form id="ana-report-form"><input name="month" value="2026-10"><input name="entity_id" value="0"><input name="n1_group_ids" value="4"><input name="n2_group_ids" value="1"><input name="n3_group_ids" value="22"><details id="ana-method-options"><summary>Critérios de apuração</summary><select name="scope_basis"><option value="closed">Fechamento no mês</option><option value="solved">Solução no mês</option></select></details><textarea name="notes" data-narrative="1"></textarea><button id="ana-generate" type="submit">Gerar</button></form><p id="ana-method-state"></p><p id="ana-status"></p><button id="ana-update-texts"></button><button data-export="docx"></button><button data-export="xlsx"></button><button data-export="json"></button><section id="ana-preview" hidden><h2 id="ana-preview-title"></h2><span id="ana-generated-at"></span><div id="ana-totals"></div><div id="ana-financial-summary"></div><div id="ana-warnings"></div><div id="ana-metrics"></div><div id="ana-rule-notes" hidden></div><select id="ana-source"><option value="tickets">Chamados</option><option value="unconfirmed_tickets">Soluções</option><option value="reopening_events">Reaberturas</option></select><input id="ana-source-search"><div id="ana-source-table"></div><span id="ana-page-label"></span><button id="ana-prev"></button><button id="ana-next"></button><select id="ana-evidence-metric"></select><select id="ana-evidence-filter"><option value="exceptions">Exceções</option><option value="all">Todos</option><option value="numerator">Numerador</option></select><div id="ana-evidence-summary"></div><div id="ana-evidence-table"></div><span id="ana-heatmap-title"></span><div id="ana-heatmap"></div></section>`, {url:'https://glpi.example/plugins/g4freportsana/front/ana.php', runScripts:'outside-only'});
  const w = d.window, requests = [];
  const callsOption=w.document.createElement('option');callsOption.value='calls';callsOption.textContent='Ligações recebidas';w.document.getElementById('ana-source').append(callsOption);
  w.G4FREPORTSANA = {api_url:'/api.php',export_url:'/export.php',nonce:'native-nonce',csrf_token:'',policy_labels:{scope_basis:['Competência',{closed:'Fechamento',solved:'Solução'}]},methodology:{entity_id:0,n1_group_ids:[4],n2_group_ids:[1],n3_group_ids:[22],scope_basis:'closed',methodology_confirmed:false}};
  w.fetch = (url, options) => new Promise(resolve => requests.push({url,options,reply:json => resolve({ok:true,json:async()=>json})}));
  w.eval(source);
  return {d,w,requests};
}
function snapshot() {
  const tickets = Array.from({length:101}, (_,i)=>({id:i+1,type:2,title:'Chamado '+(i+1),status:6,scope_reason:'Grupo ANA',priority_contract:'P2',tit_seconds:0,tms_seconds:0,opened_at:'2026-10-01 09:00:00-03:00',solved_at:'2026-10-01 10:00:00-03:00'}));
  return {
    input:{report_title:'Relatório ANA',external_evidence:{}},period:{month:'2026-10'},generated_at:'2026-11-04T11:00:00Z',
    totals:{tickets:101,requests:101,incidents:0,scope_review:1,calls_received:null,calls_answered:null},
    financial:{complete:false,gross_value:100,deduction_total:null,adjustment_total:0,net_value:null,notes:['Referência financeira ausente.'],point_items:[]},
    metrics:[{id:'IPS1',label:'Satisfação',status:'provisional',value:4.5,target:4,value_kind:'mean_score',numerator:9,denominator:2,numerator_label:'Soma das notas',denominator_label:'Respostas',coverage_percent:2/101*100,response_rate_percent:2/101*100,evaluated_count:2,cohort_count:101,method:'Soma das notas dividida pelas respostas.',note:'Internal unused note',denominator_ids:[1,2],evidence_ids:[1,2],unresolved_ids:[]}],
    tickets,scope_review:[{id:999}],unconfirmed_tickets:[{ticket_id:1,solution_status:2,reason:'Última solução sem aceite explícito registrado'},{ticket_id:2,solution_status:4,reason:'Última solução sem aceite explícito registrado'},{ticket_id:3,solution_status:null,reason:'Registro de solução ausente na evidência'}],
    reopening_events:[{ticket_id:4,occurred_at:'2026-10-02 09:00:00-03:00',from_status:5,to_status:2}],
    warnings:['Cada consulta é uma leitura independente. Esta é informação interna.','Metodologia ainda não confirmada com referência: informação interna.','Há chamados com responsabilidade/escopo pendentes; confira a lista.','Telefonia indisponível nesta extração: queue_log.time metadata internal error.','P1/P2 excedem 70% da população. Instrução interna.'],
    priority_cap:{high_priority_count:100,maximum:70,excess:30},rule_notes:['Internal rule note'],external_evidence:{}
  };
}
async function generate(s = snapshot()) {
  const ctx = setup();
  ctx.w.document.getElementById('ana-report-form').dispatchEvent(new ctx.w.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(ctx.requests.length,1);
  ctx.requests[0].reply({ok:true,token:'snapshot-token',snapshot:s});
  await tick();
  return ctx;
}
(async()=>{
  await check('closed criteria disclosure still submits saved parameters',async()=>{
    const {d,w,requests}=await generate();
    assert.equal(w.document.getElementById('ana-method-options').open,false);
    const payload=JSON.parse(requests[0].options.body.get('payload'));
    assert.equal(payload.scope_basis,'closed');assert.equal(payload.n1_group_ids,'4');assert.equal(payload.entity_id,'0');
    assert.equal(w.document.querySelector('[data-export=json]').disabled,false);d.window.close();
  });
  await check('metric results persist with concise labels and closed calculation details',async()=>{
    const {d,w}=await generate(), card=w.document.querySelector('.ana-metric');
    assert.match(card.textContent,/4,50 \/ 5/);assert.match(card.textContent,/Taxa de resposta/);
    assert.equal(card.querySelector('.ana-method-detail').open,false);
    assert.match(card.querySelector('.ana-method-detail').textContent,/Soma das notas/);
    assert(!card.textContent.includes('Internal unused note'));d.window.close();
  });
  await check('actual warning conditions remain without technical boilerplate',async()=>{
    const {d,w}=await generate(), text=w.document.getElementById('ana-warnings').textContent;
    assert(text.includes('Telefonia indisponível nesta extração.'));assert(text.includes('1 chamados com escopo em revisão.'));
    assert(text.includes('limite: 70'));assert(!text.includes('queue_log'));assert(!text.includes('Cada consulta'));
    assert.equal(w.document.getElementById('ana-rule-notes').textContent,'');d.window.close();
  });
  await check('solution status list separates waiting, refused and absent solution records',async()=>{
    const {d,w}=await generate(), select=w.document.getElementById('ana-source');select.value='unconfirmed_tickets';select.dispatchEvent(new w.Event('change'));
    const table=w.document.getElementById('ana-source-table');
    assert(table.textContent.includes('Situação da solução'));assert(table.textContent.includes('Aguardando aceite'));assert(table.textContent.includes('Recusada'));assert(table.textContent.includes('Sem registro de solução'));
    assert(!table.textContent.includes('solution_status'));assert.equal(table.querySelector('pre'),null);d.window.close();
  });
  await check('readable details preserve values and escape user-entered content',async()=>{
    const {d,w}=setup();const detail=w.ANA_UI.details({id:2100,title:'<img src=x onerror=alert(1)>',tit_seconds:0,status:6,solutions:[{id:10,status:4,solver_id:7,solution_at:'2026-10-16 11:13:00-03:00'}],source_manifest:{secret:'internal'}});
    w.document.body.append(detail);detail.open=true;detail.dispatchEvent(new w.Event('toggle'));
    assert(detail.textContent.includes('<img src=x'));assert.equal(detail.querySelector('img'),null);assert(detail.textContent.includes('Fechado'));assert(detail.textContent.includes('Recusada'));assert(detail.textContent.includes('TIT apurado (s)'));assert(!detail.textContent.includes('source_manifest'));assert.equal(detail.querySelector('pre'),null);d.window.close();
  });
  await check('source pagination and search still operate on the complete snapshot',async()=>{
    const {d,w}=await generate();assert.equal(w.document.querySelectorAll('#ana-source-table tbody tr').length,50);
    w.document.getElementById('ana-next').click();assert.equal(w.document.querySelector('#ana-source-table tbody tr td').textContent,'51');
    const search=w.document.getElementById('ana-source-search');search.value='Chamado 101';search.dispatchEvent(new w.Event('input'));assert.equal(w.document.querySelectorAll('#ana-source-table tbody tr').length,1);assert.equal(w.document.querySelector('#ana-source-table tbody tr td').textContent,'101');d.window.close();
  });
  await check('narrative and criteria edits still invalidate the applicable export state',async()=>{
    const {d,w}=await generate(), f=w.document.getElementById('ana-report-form');f.elements.notes.value='Texto da emissão';f.elements.notes.dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(w.document.querySelector('[data-export=docx]').disabled,true);assert.equal(w.document.getElementById('ana-update-texts').disabled,false);
    f.elements.scope_basis.value='solved';f.elements.scope_basis.dispatchEvent(new w.Event('change',{bubbles:true}));assert.equal(w.document.getElementById('ana-update-texts').disabled,true);assert.equal(f.elements.notes.value,'Texto da emissão');d.window.close();
  });
  await check('heatmap follows the selected ticket boundary and the local reporting month',async()=>{
    const epoch = text => Date.parse(text) / 1000;
    const cases = [
      {closed_epoch:epoch('2026-10-02T09:15:00-03:00'),solved_epoch:epoch('2026-09-30T23:30:00-03:00')},
      {closed_epoch:epoch('2026-11-01T00:00:00-03:00'),solved_epoch:epoch('2026-10-03T10:00:00-03:00')},
      {closed_epoch:epoch('2026-11-01T01:00:00Z'),solved_epoch:epoch('2026-10-01T01:00:00Z')},
      {closed_epoch:0,solved_epoch:0},
      {closed_epoch:'invalid',solved_epoch:null},
      {closed_epoch:1e20,solved_epoch:1e20}
    ];
    for (const basis of ['closed','reference','solved']) {
      const s=snapshot();s.input.scope_basis=basis;s.tickets=cases.map((dates,i)=>({...s.tickets[i],...dates}));
      const {d,w}=await generate(s), rows=[...w.document.querySelectorAll('#ana-heatmap tbody tr')];
      const count=(day,hour)=>Number(rows.find(row=>row.querySelector('th').textContent===day).querySelectorAll('td')[hour].textContent)||0;
      const total=rows.reduce((sum,row)=>sum+[...row.querySelectorAll('td')].reduce((a,cell)=>a+(Number(cell.textContent)||0),0),0);
      if(basis==='solved') {assert.equal(total,1);assert.equal(count('Sáb',10),1);assert.match(w.document.getElementById('ana-heatmap-title').textContent,/soluções/);}
      else {assert.equal(total,2);assert.equal(count('Sex',9),1);assert.equal(count('Sáb',22),1);assert.match(w.document.getElementById('ana-heatmap-title').textContent,/fechamentos/);}
      d.window.close();
    }
  });
  await check('telephone outcomes and IPS6 exclusions remain distinct in the received-call base',async()=>{
    const s=snapshot();s.input.ips6_include_desistencias=false;
    s.calls=[
      {call_key:'short',queue:'ana',answered:false,desisted:true,abandoned:false,wait_seconds:20,ips6_included:false,ips6_exclusion_reason:'Desistência com espera inferior a 30 segundos.'},
      {call_key:'long',queue:'ana',answered:false,desisted:false,abandoned:true,wait_seconds:30,ips6_included:true},
      {call_key:'unknown-wait',queue:'ana',answered:false,desisted:false,abandoned:false,abandon_wait_unknown:true,wait_seconds:null,ips6_included:true},
      {call_key:'answered',queue:'ana',answered:true,desisted:false,abandoned:false,wait_seconds:0,ips6_included:true},
      {call_key:'unknown-outcome',queue:'ana',answered:false,unknown_outcome:true,wait_seconds:null,ips6_included:true}
    ];
    s.totals.calls_received=5;s.totals.calls_answered=1;s.totals.calls_desisted=1;s.totals.calls_abandoned=1;s.totals.calls_abandon_wait_unknown=1;
    s.metrics.push({id:'IPS6',label:'Ligações atendidas',status:'provisional',value:25,target:99,numerator:1,denominator:4,evidence_ids:['answered'],denominator_ids:['long','unknown-wait','answered','unknown-outcome'],unresolved_ids:['unknown-wait','unknown-outcome'],excluded_ids:['short'],excluded_count:1});
    const before=JSON.stringify(s),{d,w}=await generate(s);
    const select=w.document.getElementById('ana-source');select.value='calls';select.dispatchEvent(new w.Event('change'));
    const rows=[...w.document.querySelectorAll('#ana-source-table tbody tr')].map(row=>[...row.querySelectorAll('td')].map(c=>c.textContent));
    assert.equal(rows.length,5);assert.equal(rows[0][8],'Desistência');assert.match(rows[0][9],/^Excluída: Desistência/);assert.equal(rows[0][6],'0:00:20');
    assert.equal(rows[1][8],'Abandonada');assert.equal(rows[1][9],'Incluída');assert.equal(rows[2][8],'Abandono sem tempo de espera');assert.equal(rows[2][6],'—');assert.equal(rows[3][8],'Atendida');assert.equal(rows[4][8],'Desfecho desconhecido');
    const metric=w.document.getElementById('ana-evidence-metric');metric.value='IPS6';metric.dispatchEvent(new w.Event('change'));
    assert(!w.document.getElementById('ana-evidence-table').textContent.includes('short'));
    const filter=w.document.getElementById('ana-evidence-filter');filter.value='numerator';filter.dispatchEvent(new w.Event('change'));
    assert.equal(w.document.querySelectorAll('#ana-evidence-table tbody tr').length,1);assert.equal(w.document.querySelector('#ana-evidence-table tbody tr td').textContent,'answered');
    assert.equal(JSON.stringify(s),before);d.window.close();
  });
  await check('external-source IPS6 does not assign queue calls metric membership',async()=>{
    const s=snapshot();s.input.ips6_include_desistencias=false;
    s.calls=[
      {call_key:'short',queue:'ana',answered:false,desisted:true,abandoned:false,wait_seconds:20,ips6_included:false,ips6_exclusion_reason:'Desistência com espera inferior a 30 segundos.'},
      {call_key:'answered',queue:'ana',answered:true,desisted:false,abandoned:false,wait_seconds:0,ips6_included:true}
    ];
    s.metrics.push({id:'IPS6',label:'Ligações atendidas',status:'met',value:100,target:99,numerator:10,denominator:10,evidence_reference:'Relatório de telefonia',evidence_ids:[],denominator_ids:[],unresolved_ids:[],excluded_ids:[],excluded_count:1});
    const before=JSON.stringify(s),{d,w}=await generate(s);
    const select=w.document.getElementById('ana-source');select.value='calls';select.dispatchEvent(new w.Event('change'));
    const rows=[...w.document.querySelectorAll('#ana-source-table tbody tr')];
    assert.equal(rows.length,2);assert.equal(rows[0].children[8].textContent,'Desistência');assert.equal(rows[1].children[8].textContent,'Atendida');
    rows.forEach(row=>{
      assert.equal(row.children[9].textContent,'—');
      const detail=row.querySelector('details');detail.open=true;detail.dispatchEvent(new w.Event('toggle'));
      const labels=[...detail.querySelectorAll('dt')];
      assert.equal(labels.find(label=>label.textContent==='Incluída no IPS6').nextElementSibling.textContent,'—');
      const reason=labels.find(label=>label.textContent==='Motivo da exclusão do IPS6');if(reason)assert.equal(reason.nextElementSibling.textContent,'—');
    });
    assert.equal(JSON.stringify(s),before);d.window.close();
  });
  await check('unknown-wait abandon eligibility is pending only when saved IPS6 excludes desistencias',async()=>{
    for(const config of [
      {input:false,expected:'Pendente'},
      {input:true,metric:false,expected:'Pendente'},
      {input:false,metric:true,expected:'Incluída'},
      {input:true,expected:'Incluída'},
      {input:false,metric:false,external:true,expected:'—'}
    ]) {
      const s=snapshot();s.input.ips6_include_desistencias=config.input;
      s.calls=[
        {call_key:'unknown-wait',queue:'ana',answered:false,abandon_wait_unknown:true,wait_seconds:null,ips6_included:true},
        {call_key:'unknown-outcome',queue:'ana',answered:false,unknown_outcome:true,ips6_included:true}
      ];
      const metric={id:'IPS6',label:'Ligações atendidas',status:'pending',value:null,target:99,numerator:0,denominator:null,evidence_ids:[],denominator_ids:['unknown-wait','unknown-outcome'],unresolved_ids:['unknown-wait','unknown-outcome']};
      if(config.metric!==undefined)metric.desistencias_included=config.metric;
      if(config.external)metric.evidence_reference='Relatório de telefonia';
      s.metrics.push(metric);
      const before=JSON.stringify(s),{d,w}=await generate(s);
      const select=w.document.getElementById('ana-source');select.value='calls';select.dispatchEvent(new w.Event('change'));
      const rows=[...w.document.querySelectorAll('#ana-source-table tbody tr')];
      assert.equal(rows[0].children[8].textContent,'Abandono sem tempo de espera');assert.equal(rows[0].children[9].textContent,config.expected);
      assert.equal(rows[1].children[8].textContent,'Desfecho desconhecido');assert.equal(rows[1].children[9].textContent,config.external?'—':'Incluída');
      const detail=rows[0].querySelector('details');detail.open=true;detail.dispatchEvent(new w.Event('toggle'));
      const label=[...detail.querySelectorAll('dt')].find(label=>label.textContent==='Incluída no IPS6');
      assert.equal(label.nextElementSibling.textContent,config.expected==='Incluída'?'Sim':config.expected);
      assert.equal(JSON.stringify(s),before);d.window.close();
    }
  });
  await check('IPS6 criteria use the saved snapshot and preserve legacy default inclusion',async()=>{
    for (const include of [true,false,undefined]) {
      const s=snapshot();if(include!==undefined)s.input.ips6_include_desistencias=include;
      s.metrics=[{id:'IPS6',label:'Ligações atendidas',status:'provisional',value:100,target:99,numerator:1,denominator:1,evidence_ids:[],denominator_ids:[],unresolved_ids:[]}];
      const {d,w}=await generate(s);w.G4FREPORTSANA.methodology.ips6_include_desistencias=include===false;
      const card=w.document.querySelector('.ana-metric');assert(card.textContent.includes(include===false?'Desistências (<30 s): excluídas do IPS6.':'Desistências (<30 s): incluídas no IPS6.'));
      const old=w.ANA_UI.details({callid:'legacy',abandoned:true,wait_seconds:null});w.document.body.append(old);old.open=true;old.dispatchEvent(new w.Event('toggle'));assert(old.textContent.includes('Abandonada'));assert(!old.textContent.includes('Desistência'));
      d.window.close();
    }
  });
  console.log(checked+' ANA report interface checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
