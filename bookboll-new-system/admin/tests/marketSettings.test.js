import test from 'node:test'
import assert from 'node:assert/strict'
import { MARKET_GROUPS, marketDraft, effectiveDraft, marketValue, marketChanges, validateMarketDraft, overrideCount } from '../src/lib/marketSettings.js'

const parent = {facility:20, program:15, event:10, site_free:null, site_premium:null, module_listings:true, module_classes:true, module_matchmaking:true}
test('country drafts preserve zero and disabled overrides, with inherited website fees computed from the local booking rate', () => {
  const draft = marketDraft({overrides:{facility:12, site_free:0, module_classes:false}})
  const current = effectiveDraft(draft,parent)
  assert.equal(current.site_free,0)
  assert.equal(marketValue(current,'site_free'),'0%')
  assert.equal(marketValue(current,'site_premium'),'6%')
  assert.equal(marketValue(current,'module_classes'),'Disabled')
  assert.equal(overrideCount({overrides:draft}),3)
})
test('resetting a section leaves the other fee and service edits intact', () => {
  const draft = marketDraft({overrides:{facility:12, site_free:8, site_premium:3, module_classes:false}})
  const initial = {...draft}
  for (const field of MARKET_GROUPS.find(g=>g.key==='website').fields) draft[field.key] = null
  assert.deepEqual(marketChanges(initial,draft).map(f=>f.key),['site_free','site_premium'])
  assert.equal(draft.facility,12)
  assert.equal(draft.module_classes,false)
  const current=effectiveDraft(draft,parent)
  assert.equal(marketValue(current,'site_free'),'12%')
  assert.equal(marketValue(current,'site_premium'),'6%')
})
test('review distinguishes fixed rates from inheritance even when the numerical amount matches', () => {
  const draft=marketDraft({overrides:{facility:20}}), initial={...draft}
  assert.equal(marketChanges({},{}).length,0)
  draft.facility='20'
  assert.equal(marketChanges(initial,draft).length,0)
  draft.facility=null
  assert.deepEqual(marketChanges(initial,draft).map(f=>f.key),['facility'])
  assert.equal(marketValue(effectiveDraft(draft,parent),'facility'),'20%')
})
test('bad percentages are detected even when the field belongs to another editor section', () => {
  const draft=marketDraft({overrides:{}})
  for (const bad of ['',-1,101,NaN,'not a number']) {
    draft.site_premium=bad
    assert.equal(validateMarketDraft(draft).key,'site_premium')
  }
  for (const good of [null,0,7.5,100]) {
    draft.site_premium=good
    assert.equal(validateMarketDraft(draft),null)
  }
})

test('listing subscriptions use a USD amount with inheritance, independently of percentages', () => {
  const draft=marketDraft({overrides:{}})
  assert.equal(marketValue(effectiveDraft(draft,{...parent,membership_listing_monthly:20}),'membership_listing_monthly'),'USD 20.00')
  for (const value of [1,20,125,100000]) { draft.membership_listing_monthly=value; assert.equal(validateMarketDraft(draft),null) }
  for (const value of ['',0,-1,100001,'invalid']) { draft.membership_listing_monthly=value; assert.equal(validateMarketDraft(draft).key,'membership_listing_monthly') }
  draft.membership_listing_monthly=null
  assert.equal(validateMarketDraft(draft),null)
})
