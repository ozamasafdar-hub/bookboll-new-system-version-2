import test from 'node:test'
import assert from 'node:assert/strict'
import { businessTeam, businessFormErrors } from '../src/lib/businessTeam.js'

test('owner is included exactly once with real identity and mixed ID types', () => {
  const owner = { id: 4, full_name: 'Example Club', role: 'provider_admin' }
  const rows = businessTeam(owner, [
    { id: '4', full_name: 'Alex Owner', owner_user_id: '4' },
    { id: 5, full_name: 'Sam Staff', owner_user_id: '4' },
    { id: '5', full_name: 'Duplicate', owner_user_id: 4 },
    { id: 6, full_name: 'Different business', owner_user_id: 9 },
  ])
  assert.deepEqual(rows.map(r => String(r.id)), ['4', '5'])
  assert.equal(rows[0].full_name, 'Alex Owner')
  assert.equal(rows[0].role, 'provider_admin')
  assert.equal(rows[0].isBusinessOwner, true)
  assert.equal(businessTeam(owner, []).length, 1)
  assert.deepEqual(businessTeam(null), [])
})

test('business creation validates business details without requiring an owner', () => {
  assert.equal(Object.keys(businessFormErrors({})).length, 4)
  const form = { phone: '+97455500001', full_name: 'Alex Owner', email: 'alex@example.test', business_name: 'Example Club', username: 'example-club', business_types: ['sports_facility'] }
  assert.deepEqual(businessFormErrors(form), {})
  assert.ok(businessFormErrors({ ...form, username: '../admin' }).username)
  assert.ok(businessFormErrors({ ...form, business_types: ['unknown'] }).business_types)
  assert.deepEqual(businessFormErrors({ ...form, full_name:'', email:'' }), {})
})

test('ownerless businesses group by business ID and never pull in unrelated platform users', () => {
  const people=[{id:1,business_id:10,role:'staff'},{id:2,business_id:11,role:'staff'},{id:3,owner_user_id:null,role:'staff'},{id:4,business_id:'10',role:'provider_admin'}]
  assert.deepEqual(businessTeam({id:null,business_id:10},people).map(p=>p.id),[1,4])
  assert.deepEqual(businessTeam({id:null,business_id:11},people).map(p=>p.id),[2])
  assert.deepEqual(businessTeam({id:null,business_id:12},people),[])
  assert.equal(businessTeam({id:null,business_id:10},people)[1].isBusinessOwner,true)
})
test('business membership takes precedence over stale owner linkage',()=>{
  assert.deepEqual(businessTeam({id:4,business_id:10},[{id:8,business_id:11,owner_user_id:4}]).map(p=>p.id),[4])
})
