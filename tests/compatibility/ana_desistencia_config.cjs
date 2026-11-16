'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(__dirname + '/../../assets/js/ana_financial.js','utf8');
const tick = () => new Promise(resolve=>setImmediate(resolve));
let passed=0;
async function check(label,fn){await fn();passed++;console.log('PASS '+label);}
function setup(saved={}){
  const d=new JSDOM('<form id="ana-report-form"><input name="month" value="2026-10"></form><button data-admin-tab="methodology"></button><button data-admin-tab="evidence"></button><div id="ana-admin-body"></div><p id="ana-admin-status"></p>',{url:'https://glpi.example/plugins/g4freportsana/front/ana.php',runScripts:'outside-only'});
  const w=d.window,requests=[],invalidations=[];
  const el=(tag,text,cls)=>{const e=w.document.createElement(tag);if(text!==null&&text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
  const cfg={can_configure:true,policy_labels:{call_unit:['Unidade da telefonia',{interaction:'Atendimento consolidado entre filas',queue_leg:'Entrada individual em fila'}]},methodology:{entity_id:0,n1_group_ids:[4],n2_group_ids:[1],n3_group_ids:[22],call_unit:'interaction',methodology_confirmed:false,methodology_reference:'',contractual_holidays:[],holiday_reference:'',vip_ticket_ids:[],priority_value_map:{},approved_pauses:[],priority_overrides:[],scope_overrides:[],...saved},financial_config:{baseline:{},months:{}},evidence_config:{months:{}},test_numbers:[],methodology_revision:'m0',financial_revision:'f0',evidence_revision:'e0',test_revision:'t0',financial_url:'/finance.php'};
  w.ANA_UI={cfg,el,month:()=>w.document.querySelector('[name=month]').value,request:(url,data)=>new Promise(resolve=>requests.push({url,data,reply:resolve})),invalidate:message=>invalidations.push(message),methodStatus:()=>{}};
  w.eval(source);
  return {d,w,cfg,requests,invalidations};
}
function field(w,label){const input=w.document.querySelector('[aria-label="'+label+'"]');assert(input,label);return input;}
async function save(ctx,expected){
  const f=ctx.w.document.querySelector('#ana-admin-body form');f.dispatchEvent(new ctx.w.Event('submit',{bubbles:true,cancelable:true}));
  const request=ctx.requests.at(-1);assert(request);const config=JSON.parse(request.data.config);
  assert.equal(config.ips6_include_desistencias,expected);assert.equal(request.data.kind,'methodology');
  assert.equal(ctx.w.document.querySelector('[name=month]').disabled,true);
  request.reply({ok:true,config,revision:'m1'});await tick();
  assert.equal(ctx.w.document.querySelector('[name=month]').disabled,false);
  return config;
}
(async()=>{
  await check('new option defaults to include without adding a monthly request override',async()=>{
    const ctx=setup(),input=field(ctx.w,'Incluir desistências no IPS6');assert.equal(input.type,'checkbox');assert.equal(input.checked,true);
    assert.match(input.closest('label').textContent,/inferior a 30 segundos/);
    assert.equal(ctx.w.document.getElementById('ana-report-form').querySelector('[name=ips6_include_desistencias]'),null);
    assert.equal(new ctx.w.FormData(ctx.w.document.getElementById('ana-report-form')).has('ips6_include_desistencias'),false);
    assert.equal(ctx.requests.length,0);ctx.d.window.close();
  });
  await check('exclude persists false and reloads; include can be saved again',async()=>{
    const ctx=setup(),input=field(ctx.w,'Incluir desistências no IPS6');input.checked=false;input.dispatchEvent(new ctx.w.Event('change'));
    const saved=await save(ctx,false);assert.equal(ctx.cfg.methodology.ips6_include_desistencias,false);assert.equal(field(ctx.w,'Incluir desistências no IPS6').checked,false);assert.equal(ctx.invalidations.length,1);
    assert.equal(JSON.parse(ctx.requests[0].data.config).call_unit,'interaction');ctx.d.window.close();
    const reopened=setup(saved),reloaded=field(reopened.w,'Incluir desistências no IPS6');assert.equal(reloaded.checked,false);reloaded.checked=true;reloaded.dispatchEvent(new reopened.w.Event('change'));await save(reopened,true);
    assert.equal(reopened.cfg.methodology.ips6_include_desistencias,true);reopened.d.window.close();
  });
  await check('external desistência evidence preserves blank versus zero',async()=>{
    const ctx=setup({ips6_include_desistencias:false});ctx.w.document.querySelector('[data-admin-tab=evidence]').click();
    const count=field(ctx.w,'Desistências (<30 s)');assert.equal(count.type,'number');assert.equal(count.min,'0');assert.equal(count.step,'1');assert.equal(count.value,'');
    count.value='0';count.dispatchEvent(new ctx.w.Event('input'));
    const f=ctx.w.document.querySelector('#ana-admin-body form');f.dispatchEvent(new ctx.w.Event('submit',{bubbles:true,cancelable:true}));
    let request=ctx.requests.at(-1),config=JSON.parse(request.data.config);assert.equal(config.months['2026-10'].phone.desisted_calls,0);request.reply({ok:true,config,revision:'e1'});await tick();
    const cleared=field(ctx.w,'Desistências (<30 s)');cleared.value='';cleared.dispatchEvent(new ctx.w.Event('input'));
    ctx.w.document.querySelector('#ana-admin-body form').dispatchEvent(new ctx.w.Event('submit',{bubbles:true,cancelable:true}));request=ctx.requests.at(-1);config=JSON.parse(request.data.config);assert.equal(config.months['2026-10'].phone.desisted_calls,null);request.reply({ok:true,config,revision:'e2'});await tick();ctx.d.window.close();
  });
  console.log(passed+' desistência configuration checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
