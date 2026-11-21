import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as businessPhone from '../../business/src/lib/businessPhone.js'

let code = compileScript(parse(readFileSync(new URL('../../business/src/pages/Signup.vue',import.meta.url),'utf8')).descriptor,{id:'signup'}).content
for (const node of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()) {
  if (node.type==='ImportDeclaration') code=code.slice(0,node.start)+node.specifiers.map(s=>`const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type==='ImportDefaultSpecifier'?'default':s.imported.name)}];`).join('\n')+code.slice(node.end)
  else if (node.type==='ExportDefaultDeclaration') code=code.slice(0,node.start)+'return '+code.slice(node.declaration.start)
}
function signup(t,api={}) {
  const cleanups=[], scope=vue.effectScope(), calls=[]
  const noop=()=>{}
  const imports=new Proxy({
    vue:{...vue,onMounted:noop,onBeforeUnmount:fn=>cleanups.push(fn)},
    '../lib/businessPhone.js': businessPhone,
    '../lib/businessTypes.js':{BUSINESS_TYPES:[{key:'gym',label:'Gym'}]},
    '../lib/businessAuth.js':{
      checkUsername:async()=>({success:true,data:{available:true}}),
      registerBusiness:async body=>{calls.push(body);return {success:true}},
      firstApiError:res=>res.message,
      ...api,
    },
  },{get:(obj,key)=>obj[key]||new Proxy({},{get:()=>noop})})
  const state=scope.run(()=>new Function('imports',code)(imports).setup({}, {expose:noop}))
  t.after(()=>{cleanups.forEach(fn=>fn());scope.stop()})
  return {state,calls}
}
test('business details validate before account panel; registration still requires verified email',async t=>{
  const {state:s,calls}=signup(t)
  s.continueBusiness()
  assert.equal(s.accountPanel.value,1)
  assert.equal(s.invalid('businessName'),true)
  s.businessPhone.value='+974 5550 0001';s.businessName.value='Example Club';s.username.value='example-club';s.businessTypes.value=['gym']
  await vue.nextTick();s.userStatus.value='ok';s.continueBusiness()
  assert.equal(s.accountPanel.value,2)
  assert.equal(calls.length,0)
  s.fullName.value='Test Owner';s.email.value='owner@example.test';s.password.value='test-only-password'
  await vue.nextTick();await s.createAccount()
  assert.equal(calls.length,0)
  assert.match(s.err1.value,/Verify your email/)
  s.emailVerified.value=true;s.emailToken.value='test-proof';await s.createAccount()
  assert.equal(calls.length,1)
  assert.equal(calls[0].email_verification_token,'test-proof')
  assert.equal(calls[0].business_name,'Example Club')
  assert.equal(calls[0].phone,'+97455500001')
  assert.equal(s.step.value,2)
})
test('email verification and username checks ignore responses for an edited value',async t=>{
  let resolveVerify,resolveUser
  const {state:s}=signup(t,{verifyEmailOtp:()=>new Promise(r=>resolveVerify=r),checkUsername:()=>new Promise(r=>resolveUser=r)})
  s.email.value='first@example.test';await vue.nextTick();s.otp.value='123456'
  const pending=s.confirmOtp()
  s.email.value='second@example.test';await vue.nextTick()
  resolveVerify({success:true,data:{email_verification_token:'old-proof'}});await pending
  assert.equal(s.emailVerified.value,false)
  assert.equal(s.emailToken.value,'')
  s.username.value='old-club';await vue.nextTick();const checking=s.checkUsername()
  s.username.value='new-club';await vue.nextTick()
  resolveUser({success:true,data:{available:false}});await checking
  assert.notEqual(s.userStatus.value,'taken')
})

test('website setup saves only branding and skips without writing', async t => {
  const saved = []
  const { state: s } = signup(t, { getApiToken: () => 'test-session', updateBusinessBrand: async body => { saved.push(body); return { success: true } } })
  s.step.value = 2; s.brandName.value = 'Example Club'; s.brandColor.value = '#075e54'; s.accentColor.value = '#14b8a6'
  s.logoBusy.value = true
  await s.finishWebsite()
  assert.equal(saved.length, 0, 'wait for the logo upload before saving')
  s.logoBusy.value = false; s.brandColor.value = 'invalid'
  await s.finishWebsite()
  assert.equal(saved.length, 0)
  s.brandColor.value = '#075e54'
  await s.finishWebsite()
  assert.equal(saved.length, 1)
  assert.deepEqual(Object.keys(saved[0]).sort(), ['brand_name', 'brand_tagline', 'brand_logo_url', 'brand_primary', 'brand_accent'].sort())
  assert.equal(s.step.value, 3)
  s.step.value = 2; s.skipWebsite()
  assert.equal(saved.length, 1)
  assert.equal(s.step.value, 3)
})
