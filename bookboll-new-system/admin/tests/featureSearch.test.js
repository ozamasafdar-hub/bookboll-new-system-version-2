import test from 'node:test'
import assert from 'node:assert/strict'
import { searchFeatures, FEATURE_DESTINATIONS } from '../src/lib/featureSearch.js'
import { CAP } from '../src/lib/permissions.js'

const owner = { role: 'provider_admin', providerScoped: true }
test('providers and read-only staff can find the manual member register without a gateway', () => {
  for (const role of ['provider_admin','staff','provider_viewer']) {
    assert.equal(searchFeatures('attendance', {role,providerScoped:true})[0]?.to, '/subscriptions')
  }
})
test('online payments finds booking payouts without retired membership wallets', () => {
  for (const query of ['online payments', 'how do I set up online payments', 'add payout method', 'get paid']) {
    const results = searchFeatures(query, owner)
    assert.equal(results[0]?.id, 'booking-payout')
    assert.ok(!results.some(item => item.to.includes('/wallet/subscription')))
    assert.equal(results.find(item => item.id === 'booking-payout').to, '/wallet/bookings?setup=payout')
  }
})
test('synonyms, partial words and small typos find the relevant features', () => {
  for (const [query, expected] of [['website colours', 'branding'], ['website color', 'branding'], ['discount codes', 'promos'], ['logo', 'branding'], ['opening hours', 'listings'], ['calendr', 'calendar'], ['calen', 'calendar']]) {
    assert.equal(searchFeatures(query, owner)[0]?.id, expected, query)
  }
  assert.ok(!searchFeatures('payout', owner).some(item => item.id === 'sections'), 'known words should not fuzzily match unrelated words')
  assert.deepEqual(searchFeatures('quantum zxz', owner), [])
  assert.ok(searchFeatures('', owner).length > 0)
})
test('search honors page capabilities and payout overrides', () => {
  assert.equal(searchFeatures('online payments', { role: 'provider_viewer', providerScoped: true }).length, 0)
  assert.ok(!searchFeatures('online payments', { role: 'facility_admin', providerScoped: true }).some(item => item.id.endsWith('-payout')))
  const granted = { role: 'facility_admin', providerScoped: true, overrides: { grant: [CAP.MANAGE_PAYOUTS] } }
  assert.equal(searchFeatures('online payments', granted)[0].id, 'booking-payout')
  assert.ok(!searchFeatures('payout', { ...owner, overrides: { revoke: [CAP.MANAGE_PAYOUTS] } }).some(item => item.id.endsWith('-payout')))
  assert.equal(searchFeatures('countries', owner).length, 0)
  assert.ok(!searchFeatures('online payments', { role: 'super_admin', providerScoped: false }).some(item => item.id.endsWith('-payout')))
  assert.ok(!searchFeatures('website sections', { role: 'super_admin', providerScoped: false }).some(item => item.id === 'sections'))
})
test('destination catalog has unique ids and only internal paths', () => {
  assert.equal(new Set(FEATURE_DESTINATIONS.map(item => item.id)).size, FEATURE_DESTINATIONS.length)
  assert.ok(FEATURE_DESTINATIONS.every(item => /^\/[a-z]/.test(item.to)))
})
