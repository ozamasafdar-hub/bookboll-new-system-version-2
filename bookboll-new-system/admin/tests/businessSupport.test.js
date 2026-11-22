import test from 'node:test'
import assert from 'node:assert/strict'
import { supportSession, saveSupportSession, returnToSuperadmin, SUPPORT_KEY } from '../src/lib/businessSupport.js'
import { endExpiredSession, resetSessionExpiryLatch } from '../src/lib/sessionExpiry.js'

function store() { const m=new Map(); return { getItem:k=>m.get(k)||null, setItem:(k,v)=>m.set(k,v), removeItem:k=>m.delete(k) } }
test.beforeEach(()=>{ globalThis.localStorage=store(); globalThis.sessionStorage=store(); localStorage.setItem('bb_admin_token','original'); resetSessionExpiryLatch() })
const session={token:'scoped',support_session:{session_id:1,business_id:2,business_name:'QA',expires_at:'2099-01-01'}}
test('support identity stays in this tab and preserves the original bearer',()=>{
  saveSupportSession(session); assert.equal(supportSession().token,'scoped'); assert.equal(localStorage.getItem('bb_admin_token'),'original')
  globalThis.sessionStorage=store(); assert.equal(supportSession(),null)
})
test('expiry returns to superadmin without deleting its credentials',()=>{
  saveSupportSession(session); const paths=[]; globalThis.window={location:{replace:p=>paths.push(p)}}
  endExpiredSession(); endExpiredSession(); assert.deepEqual(paths,['/team?support=expired']); assert.equal(localStorage.getItem('bb_admin_token'),'original'); assert.equal(supportSession(),null)
})
test('invalid stored support data fails closed until a full return reload',()=>{
  sessionStorage.setItem(SUPPORT_KEY,'bad JSON'); assert.equal(supportSession().invalid,true)
  globalThis.window={location:{replace:()=>{}}}; returnToSuperadmin(); assert.equal(supportSession(),null)
})
test('storage failures never replace the superadmin credential',()=>{
  globalThis.sessionStorage={setItem(){throw Error('blocked')},getItem(){return null}}
  assert.throws(()=>saveSupportSession(session)); assert.equal(localStorage.getItem('bb_admin_token'),'original')
})
