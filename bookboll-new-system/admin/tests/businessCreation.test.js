import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as permissions from '../src/lib/permissions.js'
import * as types from '../src/lib/businessTypes.js'
import * as team from '../src/lib/businessTeam.js'
import * as phone from '../src/lib/businessPhone.js'
import { usePagination } from '../src/lib/usePagination.js'
import * as finance from '../src/lib/finance.js'

let code = compileScript(parse(readFileSync(new URL('../src/pages/Team.vue', import.meta.url), 'utf8')).descriptor, { id: 'team' }).content
for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') code = code.slice(0,node.start) + node.specifiers.map(s => `const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n') + code.slice(node.end)
  else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0,node.start) + 'return ' + code.slice(node.declaration.start)
}

function mount(t, { role = 'super_admin', fail = false, supported = true } = {}) {
  const scope = vue.effectScope(), requests = [], noop = () => {}
  const context = { loadUserContext: noop, isSuperAdmin: vue.ref(role === 'super_admin'), isCountryAdmin: vue.ref(false), role: vue.ref(role), currentUser: vue.ref({ id: 1 }), currentOwnerUserId: vue.ref(null), currentUsername: vue.ref(''), assignedCountryIds: vue.ref([]), assignedLocationIds: vue.ref([]) }
  const imports = new Proxy({
    vue: { ...vue, onMounted: noop },
    '../lib/businessTeam.js': team,
    '../lib/businessPhone.js': phone,
    '../lib/businessTypes.js': types,
    '../lib/permissions.js': permissions,
    '../lib/usePagination.js': { usePagination },
    '../lib/currentUser.js': { useCurrentUser: () => context },
    '../lib/audit.js': { logAudit: noop, AUDIT: {} },
    '../lib/finance.js': finance,
    '../lib/financeApi.js': { financeRequest:async(path,options={})=>{
      requests.push({path,...options})
      if(path.endsWith('/capabilities')) return {ok:supported,data:{create_without_owner:supported}}
      return fail ? {ok:false,message:'Username already exists.'} : {ok:true,data:{business:{id:44}}}
    } },
    '../lib/api.js': { apiFetch: async (path, options = {}) => {
      requests.push({ path, ...options })
      return fail && options.method === 'POST' ? { success: false, errors: { email: ['Email already exists.'] } } : { success: true, data: {} }
    } },
  }, { get: (o,k) => o[k] || new Proxy({}, { get: () => noop }) })
  const state = scope.run(() => new Function('imports', code)(imports).setup({}, { expose: noop }))
  t.after(() => scope.stop())
  return { state, requests }
}

const example = { phone: '+97455500001', business_name: 'Example Club', full_name: 'Example Owner', email: 'owner@example.test', username: 'example-club', business_types: ['sports_facility'], country_id: 1 }
test('business creation submits a business without creating an owner account', async t => {
  const { state: s, requests } = mount(t)
  s.openBusinessNew(); s.adminForm.value.role='provider_admin'; s.showModal.value=true; await s.reviewBusiness()
  assert.equal(s.businessReview.value, false)
  assert.equal(requests.length, 0)
  Object.assign(s.adminForm.value, example,{full_name:'',email:''})
  await s.reviewBusiness()
  assert.equal(s.businessReview.value, true)
  assert.equal(requests.filter(r=>r.method==='POST').length, 0)
  await s.saveAdmin()
  const writes = requests.filter(r => r.method === 'POST')
  assert.equal(writes.length, 1)
  assert.equal(writes[0].path, '/admin/businesses')
  assert.equal('role' in writes[0].body, false)
  assert.equal('name' in writes[0].body, false)
  assert.equal('email' in writes[0].body, false)
  assert.equal(writes[0].body.business_name, 'Example Club')
  assert.equal(writes[0].body.phone, '+97455500001')
  assert.deepEqual(writes[0].body.business_types, ['sports_facility'])
  assert.equal(writes[0].body.owner_user_id, null)
  assert.equal('assigned_location_ids' in writes[0].body, false)
  assert.equal(s.showModal.value, false)
})
test('server validation errors retain the business draft', async t => {
  const { state: s } = mount(t, { fail: true })
  s.openBusinessNew(); s.adminForm.value.role='provider_admin'; s.showModal.value=true; Object.assign(s.adminForm.value, example); await s.reviewBusiness()
  await s.saveAdmin()
  assert.equal(s.showModal.value, true)
  assert.match(s.formError.value, /Username already exists/)
  assert.equal(s.adminForm.value.business_name, 'Example Club')
  assert.equal(s.saving.value, false)
})
test('old backend cannot fall back to creating a fake owner',async t=>{
  const {state:s,requests}=mount(t,{supported:false})
  s.openBusinessNew(); s.adminForm.value.role='provider_admin'; s.showModal.value=true;Object.assign(s.adminForm.value,example);await s.reviewBusiness();await s.saveAdmin()
  assert.equal(s.showModal.value,true)
  assert.equal(requests.filter(r=>r.method==='POST').length,0)
})
test('a provider staff role cannot create a business owner', async t => {
  const { state: s, requests } = mount(t, { role: 'staff' })
  s.openBusinessNew(); s.adminForm.value.role='provider_admin'; s.showModal.value=true; Object.assign(s.adminForm.value, example); await s.saveAdmin()
  assert.equal(requests.length, 0)
  assert.match(s.formError.value, /permission/)
})
test('ordinary user editor cannot move a platform account into a business without review',async t=>{
  const {state:s,requests}=mount(t)
  s.openAdminEdit({id:7,role:'platform_staff',full_name:'Example',email:'example@example.test',owner_user_id:null})
  s.adminForm.value.owner_user_id=9;s.adminForm.value.role='staff'
  await s.saveAdmin();assert.equal(requests.length,0);assert.match(s.formError.value,/reviewed assignment/)
})
