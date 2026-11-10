import test from 'node:test'
import assert from 'node:assert/strict'
import { periodDates, filterError, queryFilters, legacyPaymentRows, money } from '../src/lib/paymentReport.js'
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
