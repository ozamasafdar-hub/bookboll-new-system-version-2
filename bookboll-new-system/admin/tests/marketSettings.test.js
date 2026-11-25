import test from 'node:test'
import assert from 'node:assert/strict'
import { MARKET_GROUPS, marketDraft, effectiveDraft, marketValue, marketChanges, validateMarketDraft, overrideCount, feeExample } from '../src/lib/marketSettings.js'

const parent = {facility:20, program:15, event:10, site:10, module_listings:true, module_classes:true, module_matchmaking:true}
test('fee examples hide invalid drafts and preserve zero and fractional fees', () => {
  for (const value of ['', null, 101, -1, NaN]) {
    assert.equal(feeExample(value), '—')
    assert.equal(feeExample(value, true), '—')
  }
  assert.equal(feeExample(0, true), '100.00')
  assert.equal(feeExample(8.25), '8.25')
  assert.equal(feeExample(8.25, true), '91.75')
})
test('country drafts preserve zero and disabled overrides, with website fees inherited independently of the marketplace rate', () => {
  const draft = marketDraft({overrides:{facility:12, site:0, module_classes:false}})
  const current = effectiveDraft(draft,parent)
  assert.equal(current.site,0)
  assert.equal(marketValue(current,'site'),'0%')
  assert.equal(marketValue(current,'module_classes'),'Disabled')
  assert.equal(overrideCount({overrides:draft}),3)
})
test('resetting a section leaves the other fee and service edits intact', () => {
  const draft = marketDraft({overrides:{facility:12, site:8, module_classes:false}})
  const initial = {...draft}
  for (const field of MARKET_GROUPS.find(g=>g.key==='marketplace').fields) draft[field.key] = null
  assert.deepEqual(marketChanges(initial,draft).map(f=>f.key),['facility','site'])
  assert.equal(draft.facility,null)
  assert.equal(draft.module_classes,false)
  const current=effectiveDraft(draft,parent)
  assert.equal(marketValue(current,'site'),'10%')
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
    draft.site=bad
    assert.equal(validateMarketDraft(draft).key,'site')
  }
  for (const good of [null,0,7.5,100]) {
    draft.site=good
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
