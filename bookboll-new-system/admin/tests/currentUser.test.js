import test from 'node:test'
import assert from 'node:assert/strict'

globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} }
const { mapLaravelUserToAdmin, applyLaravelUser, assignedLocationIds, scopeLocations, scopeListings, scopePrograms, can, clearLaravelUser } = await import('../src/lib/currentUser.js')

test('login context preserves canonical venue scope and permission overrides', () => {
  applyLaravelUser({ id: 12, assigned_location_ids: [99] }, ['facility_admin'], {
    owner_user_id: 5, assigned_location_ids: ['24', 24], assigned_country_ids: ['1'],
    assigned_listing_ids: [68], assigned_program_ids: [2],
  }, { grant: [], revoke: ['manage_team'] })
  assert.deepEqual(assignedLocationIds.value, [24])
  assert.equal(can('manage_team'), false)
  assert.equal(can('view_bookings'), true)
  assert.deepEqual(scopeLocations([{ id: '24' }, { id: 99 }]), [{ id: '24' }])
  assert.deepEqual(scopeListings([{ id: '68' }, { id: 99 }]), [{ id: '68' }])
  assert.deepEqual(scopePrograms([{ id: '2' }, { id: 99 }]), [{ id: '2' }])
  clearLaravelUser()
})

test('membership-only accounts keep their business identity and selected venues', () => {
  const user = mapLaravelUserToAdmin({ id: 12, active_business_membership: {
    scope: 'selected', location_ids: [24], business: { id: 25, name: 'QA Club', username: 'qa-club' },
  } }, ['staff'])
  assert.equal(user.business_id, '25')
  assert.equal(user.business_name, 'QA Club')
  assert.deepEqual(user.assigned_location_ids, [24])
})

test('an explicitly unrestricted server scope overrides stale row and membership pins', () => {
  const user = mapLaravelUserToAdmin({ id: 12, assigned_location_ids: [99],
    active_business_membership: { scope: 'selected', location_ids: [24] },
  }, ['facility_admin'], { assigned_location_ids: [] })
  assert.deepEqual(user.assigned_location_ids, [])
})

test('row fallback retains all scope types and effective grants when no envelope is available', () => {
  const user = mapLaravelUserToAdmin({ id: 12, assigned_location_ids: ['24'], venues: [{ id: 25 }],
    assigned_listing_ids: [68], assigned_program_ids: ['7'], assigned_country_ids: [1],
    custom_permissions: { grant: ['manage_team'], revoke: [] },
  }, ['staff'])
  assert.deepEqual(user.assigned_location_ids, [24, 25])
  assert.deepEqual(user.assigned_listing_ids, [68])
  assert.deepEqual(user.assigned_program_ids, [7])
  assert.deepEqual(user.assigned_country_ids, [1])
  applyLaravelUser({ id: 12 }, ['staff'], null, user.custom_permissions)
  assert.equal(can('manage_team'), true)
  clearLaravelUser()
})


test('co-owners use the canonical business key for owner-scoped pages',async()=>{
  const {currentOwnerUserId,currentUsername}=await import('../src/lib/currentUser.js')
  applyLaravelUser({id:42,active_business_membership:{scope:'all',business:{id:7,username:'shared-club'}}},['provider_admin'],{owner_user_id:5,assigned_location_ids:[]})
  assert.equal(currentOwnerUserId.value,'5');assert.equal(currentUsername.value,'shared-club')
  clearLaravelUser()
})
