import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as phone from '../src/lib/businessPhone.js'
import * as team from '../src/lib/businessTeam.js'
import * as types from '../src/lib/businessTypes.js'
import * as dial from '../src/customer/phone.js'

function setup(t, file, props={}, api=async()=>({success:true,data:{business:{id:42,phone:'+97455500001'}}})) {
  let code=compileScript(parse(readFileSync(new URL('../src/components/'+file,import.meta.url),'utf8')).descriptor,{id:'test'}).content
  for(const n of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()) {
    if(n.type==='ImportDeclaration')code=code.slice(0,n.start)+n.specifiers.map(s=>`const ${s.local.name}=imports[${JSON.stringify(n.source.value)}][${JSON.stringify(s.type==='ImportDefaultSpecifier'?'default':s.imported.name)}];`).join('\n')+code.slice(n.end)
    else if(n.type==='ExportDefaultDeclaration')code=code.slice(0,n.start)+'return '+code.slice(n.declaration.start)
  }
  const requests=[],events=[],scope=vue.effectScope(),noop=()=>{}
  const imports=new Proxy({vue:{...vue,onBeforeUnmount:noop},'../lib/businessPhone.js':phone,'../lib/businessTeam.js':team,'../lib/businessTypes.js':types,'../customer/phone.js':dial,
    '../lib/api.js':{apiFetch:async(path,options)=>{requests.push({path,...options});return api(path,options)}}
  },{get:(o,k)=>o[k]||new Proxy({},{get:()=>noop})})
  const state=scope.run(()=>new Function('imports',code)(imports).setup(props,{emit:(...args)=>events.push(args),expose:noop}))
  t.after(()=>scope.stop());return {state,requests,events}
}
const valid={business_name:'QA Phone Club',username:'qa-phone-club',business_types:['sports_facility'],country_id:1,phone:'+974 5550 0001'}

test('active business wizard requires a phone before advancing and preserves it through review',async t=>{
  const {state:s,requests,events}=setup(t,'BusinessSetup.vue',{countries:[{id:1,name:'Qatar',iso2:'QA'}]})
  Object.assign(s.form.value,valid,{phone:''});s.next();assert.equal(s.step.value,1);assert.match(s.error.value,/phone/)
  s.form.value.phone=valid.phone;s.next();s.next();assert.equal(s.step.value,3);assert.equal(s.phoneIso.value,'QA');assert.equal(requests.length,0)
  await s.create();assert.equal(requests[0].body.phone,'+97455500001');assert.equal(requests[0].body.owner_user_id,null)
  assert.equal(events.find(e=>e[0]==='created')[1].id,42)
})
test('server contact validation leaves the business draft available for correction',async t=>{
  const {state:s,events}=setup(t,'BusinessSetup.vue',{countries:[]},async()=>({success:false,message:'Invalid business phone.'}))
  Object.assign(s.form.value,valid);s.next();s.next();await s.create()
  assert.equal(s.created.value,null);assert.equal(s.form.value.phone,valid.phone);assert.match(s.error.value,/phone/);assert.equal(events.length,0)
})
test('phone editor handles a country default and an international number pasted in full',async t=>{
  const props=vue.reactive({modelValue:'',defaultIso:'QA',inputId:'test-phone'})
  const {state:s,events}=setup(t,'BusinessPhoneInput.vue',props)
  assert.equal(s.dial.value,'+974');s.updateNumber({target:{value:'5550 0001'}});assert.equal(events.at(-1)[1],'+9745550 0001')
  props.modelValue='+44 7700 900123';await vue.nextTick();assert.equal(s.dial.value,'+44');assert.equal(s.national.value,'7700900123')
  s.updateNumber({target:{value:''}});assert.equal(events.at(-1)[1],'')
})
