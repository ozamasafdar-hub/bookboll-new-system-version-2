import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as finance from '../src/lib/finance.js'
import * as permissions from '../src/lib/permissions.js'

function setup(t,file,props={},handler=async()=>({ok:false,message:'Unavailable'})){
  let code=compileScript(parse(readFileSync(new URL(`../src/components/${file}.vue`,import.meta.url),'utf8')).descriptor,{id:'finance-flow'}).content
  for(const node of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()){
    if(node.type==='ImportDeclaration')code=code.slice(0,node.start)+node.specifiers.map(s=>`const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type==='ImportDefaultSpecifier'?'default':s.imported.name)}];`).join('\n')+code.slice(node.end)
    else if(node.type==='ExportDefaultDeclaration')code=code.slice(0,node.start)+'return '+code.slice(node.declaration.start)
  }
  const requests=[],events=[],noop=()=>{}
  const imports=new Proxy({vue:{...vue,onMounted:noop},'../lib/finance.js':finance,'../lib/permissions.js':permissions,'../lib/financeApi.js':{financeRequest:async(path,options={})=>{requests.push({path,...options});return handler(path,options)}}},{get:(o,k)=>o[k] || new Proxy({},{get:()=>noop})})
  const scope=vue.effectScope();t.after(()=>scope.stop())
  const state=scope.run(()=>new Function('imports',code)(imports).setup(vue.reactive(props),{expose:noop,emit:(...args)=>events.push(args)}))
  return {state,requests,events}
}
const options={capabilities:{reports:true,marketing_costs:true},currencies:['QAR'],countries:[{id:1,name:'Qatar'}]}
const preview={preview_token:'test',expires_at:'2099-01-01T00:00:00Z',business_id:20,effective_from:'2026-10-15',history_mode:'legacy_deposit_fee',eligible_count:1,pending_arrival_count:1,excluded_count:0,landlord_obligation_count:0,totals:[{currency:'QAR',deposit_to_fee:'20',provider_cash:'80',earnings_delta:'0'}],warnings:[],blockers:[]}

test('legacy transfer cannot commit without acknowledgement, and edits invalidate reviewed amounts',async t=>{
  const {state:s,requests,events}=setup(t,'VenueTransfer',{venueId:1,venueName:'Example'},async(path)=>path.endsWith('/transfer-options')?{ok:true,data:{can_transfer:true,reconciliation_version:3,businesses:[]}}:path.endsWith('/transfer-preview')?{ok:true,data:preview}:{ok:true,data:{transfer:{id:1},venue:{id:1,business_id:20}}})
  await vue.nextTick();Object.assign(s.form.value,{business_id:20,effective_from:'2026-10-15',history_mode:'legacy_deposit_fee',from_date:'2025-01-01',to_date:'2026-10-14'})
  await s.review();await s.commit()
  assert.equal(requests.filter(r=>r.path.endsWith('/transfers')).length,0)
  s.agreed.value=true;s.form.value.business_id=21
  assert.equal(s.preview.value,null);assert.equal(s.agreed.value,false)
  s.form.value.business_id=20;await s.review();s.agreed.value=true;await s.commit()
  const write=requests.find(r=>r.path.endsWith('/transfers'))
  assert.equal(write.body.acknowledge_legacy_cash,true)
  assert.equal('amount' in write.body,false)
  assert.deepEqual(events,[['transferred',{id:1,business_id:20}]])
})
test('blocked/expired transfer previews do not write',async t=>{
  const {state:s,requests}=setup(t,'VenueTransfer',{venueId:1})
  s.preview.value={...preview,blockers:['Settled to another provider']};s.agreed.value=true;await s.commit()
  s.preview.value={...preview,expires_at:'2000-01-01'};await s.commit()
  assert.equal(requests.filter(r=>r.method==='POST').length,0)
})
test('business assignment restricts roles to provider roles and invalidates account/role changes',async t=>{
  const {state:s,requests}=setup(t,'BusinessAssignment',{business:{business_id:20}},async(path,{body}={})=>path.endsWith('/assignment-options')?{ok:true,data:{can_assign:true,roles:['super_admin','provider_admin','staff'],candidates:[]}}:{ok:true,data:{preview_token:'test',expires_at:'2099-01-01',business_id:20,user_id:body.user_id,role:body.role,blockers:[],warnings:[]}})
  await s.load();assert.deepEqual(s.roles.value,['provider_admin','staff'])
  s.form.value.user_id=8;s.form.value.role='super_admin';await s.review()
  assert.equal(requests.filter(r=>r.method==='POST').length,0)
  s.form.value.role='staff';await vue.nextTick();await s.review();assert.ok(s.preview.value)
  s.form.value.user_id=9;assert.equal(s.preview.value,null)
})
test('renewal saves a successor and preserves the old contract unless explicitly reused',async t=>{
  const original={id:1,version:1,deal_type:'fixed_rent',monthly_rent:'3100',currency:'QAR',landlord_name:'Example',effective_from:'2026-01-01',effective_to:'2026-12-31',contract_document_id:9,contract_name:'Old contract.pdf'}
  let latest=null
  const {state:s,requests}=setup(t,'VenueCommercial',{venueId:1,currency:'QAR'},async(path,{body}={})=>{
    if(body){latest={...body,id:2,version:2};return {ok:true,data:{agreement:latest}}}
    return {ok:true,data:{can_manage:true,agreement_history_version:2,version:latest?2:1,currencies:['QAR'],current:original,history:latest?[latest]:[]}}
  })
  await s.load();assert.equal(s.editing.value,false);s.startAgreement()
  assert.equal(s.form.value.effective_from,'2027-01-01')
  s.form.value.change_reason='Annual renewal';s.form.value.monthly_rent='3500';await s.save()
  const write=requests.find(row=>row.method==='POST')
  assert.equal(write.body.supersedes_agreement_id,1);assert.equal(write.body.contract_document_id,null)
  assert.equal(write.body.monthly_rent,'3500');assert.equal(original.monthly_rent,'3100')
  assert.equal(s.timeline.value.length,2);assert.match(s.saved.value,/recorded/)
  assert.equal(s.appliedThrough(original),'2026-12-31')
  latest.effective_from='2026-07-01';s.versions.value=[{...latest}]
  assert.equal(s.appliedThrough(original),'2026-06-30')
  assert.equal(original.effective_to,'2026-12-31','contract dates remain immutable when applied terms end earlier')
})
test('old agreement backend cannot silently overwrite terms and failed writes keep the renewal',async t=>{
  const {state:s,requests}=setup(t,'VenueCommercial',{venueId:1,currency:'QAR'})
  await s.load();s.options.value={can_manage:true,version:1};s.startAgreement()
  Object.assign(s.form.value,{landlord_name:'Example',monthly_rent:'3100',effective_from:'2026-10-01'})
  await s.save();assert.equal(requests.some(row=>row.method==='POST'),false)
  s.options.value.agreement_history_version=2;await s.save()
  assert.equal(s.editing.value,true);assert.equal(s.form.value.monthly_rent,'3100');assert.equal(s.dirty.value,true)
})

