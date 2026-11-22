import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
const source=fs.readFileSync(new URL('../src/lib/api.js',import.meta.url),'utf8')
  .replace(/^import .*$/gm,'').replaceAll('export ','').replace('import.meta.env.VITE_API_URL',"'https://qa.example/api'")
function fixture(request) {
  let current='original', ended=0, support=null
  const api=new Function('axios','endExpiredSession','isSessionExpired','supportSession','localStorage',source+'\nreturn {apiFetch,getAdminToken}')(
    {create:()=>({request})},()=>ended++,(status,token)=>status===401&&Boolean(token),()=>support,{getItem:()=>current})
  return {...api, ended:()=>ended, setToken:t=>current=t, setSupport:s=>support=s}
}
test('an old request failing after identity switch cannot clear the new session',async()=>{
  let resolve; const f=fixture(()=>new Promise(r=>resolve=r));const pending=f.apiFetch('/me'); f.setToken('new-login');resolve({status:401,data:{success:false}})
  await pending;assert.equal(f.ended(),0)
})
test('current support bearer gets sent and its 401 invokes session recovery',async()=>{
  let sent;const f=fixture(async options=>{sent=options;return {status:401,data:{success:false}}});f.setSupport({token:'support-only'})
  await f.apiFetch('/me');assert.equal(sent.headers.Authorization,'Bearer support-only');assert.equal(f.ended(),1)
})
test('interrupted support controls have a bounded timeout and a retryable error',async()=>{
  let sent;const f=fixture(async options=>{sent=options;throw Error('connection interrupted')})
  const result=await f.apiFetch('/business-support/exit',{method:'POST',timeout:20000});assert.equal(sent.timeout,20000);assert.equal(result.status,0);assert.equal(result.success,false);assert.equal(f.ended(),0)
})
test('corrupt support context never sends the original superadmin bearer',async()=>{
  let sent;const f=fixture(async options=>{sent=options;return {status:401,data:{success:false}}});f.setSupport({invalid:true})
  await f.apiFetch('/me');assert.notEqual(sent.headers.Authorization,'Bearer original');assert.equal(f.ended(),1)
})
