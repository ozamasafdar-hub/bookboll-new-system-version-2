import test from 'node:test'
import assert from 'node:assert/strict'
import { periodDates, filterError, queryFilters, legacyPaymentRows, money, statusOptions, listingsForVenues, keepAvailableListings } from '../src/lib/paymentReport.js'
test('custom dates and amount ranges reject contradictory filters', () => {
  assert.match(filterError({from_date:'2026-11-10',to_date:'2026-11-01',amount_min:'',amount_max:''}), /End date/)
  assert.match(filterError({amount_min:'20',amount_max:'10'}), /Maximum/)
  assert.match(filterError({amount_min:'-1',amount_max:''}), /negative/)
  assert.equal(filterError({amount_min:'0',amount_max:'0'}),'')
})
test('calendar presets are inclusive and cross month boundaries', () => {
  assert.deepEqual(periodDates('7',new Date(2026,10,3)),['2026-10-28','2026-11-03'])
  assert.deepEqual(periodDates('month',new Date(2026,10,3)),['2026-11-01','2026-11-03'])
  assert.deepEqual(periodDates('all'),['',''])
  assert.deepEqual(periodDates('last_month',new Date(2026,0,14)),['2025-12-01','2025-12-31'])
  assert.deepEqual(periodDates('last_month',new Date(2024,2,1)),['2024-02-01','2024-02-29'])
  assert.deepEqual(periodDates('last_month',new Date(2026,10,11)),['2026-10-01','2026-10-31'])
})
test('filters preserve zero and send all multi-select values', () => {
  assert.deepEqual(queryFilters({amount_min:0,status:['paid','pending'],method:[],search:''}),{amount_min:0,status:'paid,pending'})
})
test('legacy payments remain inspectable but are not claimed as verified earnings', () => {
  const [r] = legacyPaymentRows({payments:{data:[{id:'1',amount:100,recipient:null,booking_id:'2'}]},bookings:[{id:'2',customer_id:3,location_id:4}],customers:[{id:3,full_name:'Fixture Customer'}],locations:[{id:4,name:'Fixture Court'}]})
  assert.equal(r.recipient_kind,'unknown'); assert.equal(r.category,'unclassified')
  assert.equal(r.customer_name,'Fixture Customer'); assert.equal(r.venue_name,'Fixture Court'); assert.equal(r.can_collect,false)
})
test('currency formatting preserves currency and precision without inventing zeros', () => {
  assert.equal(money(null,'QAR'),'—')
  assert.match(money(10.123,'KWD'),/10\.123/)
  assert.match(money(1000,'JPY'),/1,000/)
})
test('venue changes scope listing choices and remove only incompatible selections', () => {
  const options = [{value:'11',venue_id:'1'}, {value:'12',venue_id:'1'}, {value:'21',venue_id:'2'}]
  assert.deepEqual(listingsForVenues(options, []), options)
  const scoped = listingsForVenues(options, ['1'])
  assert.deepEqual(scoped.map(x=>x.value), ['11','12'])
  assert.deepEqual(keepAvailableListings(['11','21'], scoped), ['11'])
  assert.deepEqual(keepAvailableListings(['11'], listingsForVenues(options, [])), ['11'])
  assert.deepEqual(listingsForVenues(options, ['1','2']), options)
  assert.deepEqual(keepAvailableListings(['11'], listingsForVenues(options, ['2'])), [])
  assert.deepEqual(queryFilters({location_id:['1'],listing_id:['11','12'],business_id:['bookboll','9']}),
    {location_id:'1',listing_id:'11,12',business_id:'bookboll,9'})
})
test('payment status filters do not offer wallet clearing or payout states', () => {
  assert.deepEqual(statusOptions.map(x=>x.value), ['paid','pending','refunded','cancelled'])
})
