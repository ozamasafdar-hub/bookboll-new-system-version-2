import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dateError, bookingPaymentLabel, periodDates } from '../src/lib/bookingActivity.js'

const source=readFileSync(new URL('../src/lib/bookings.js',import.meta.url),'utf8')
function exporter(api) {
  return new Function('apiFetch',source.replace(/^import .*api.*$/m,'').replaceAll('export async function','async function').replaceAll('export function','function')+'\nreturn fetchBookingsForExport;')(api)
}
test('export follows every server cursor and carries the selected filters across batches',async()=>{
  const calls=[]
  const run=exporter(async(path,{query})=>{
    calls.push(query)
    return {success:true,data:query.after_id ? {bookings:[{id:'3'}],next_after_id:null} : {bookings:[{id:'1'},{id:'2'}],next_after_id:2}}
  })
  const result=await run({venue_id:['4'],channel:['manual'],date_from:'2026-10-01',date_to:'2026-10-31'})
  assert.deepEqual(result.data.bookings.map(b=>b.id),['1','2','3'])
  assert.equal(calls.length,2)
  for(const q of calls) { assert.equal(q.venue_id,'4'); assert.equal(q.channel,'manual'); assert.equal(q.date_to,'2026-10-31') }
})
test('a failed export batch never returns a partial successful download',async()=>{
  const run=exporter(async(path,{query})=>query.after_id ? {success:false,message:'Connection lost'} : {success:true,data:{bookings:[{id:'1'}],next_after_id:1}})
  const result=await run({})
  assert.equal(result.success,false); assert.equal(result.data,undefined)
})
test('a repeated export cursor stops instead of looping indefinitely',async()=>{
  const run=exporter(async()=>({success:true,data:{bookings:[{id:'1'}],next_after_id:1}}))
  assert.equal((await run({})).success,false)
})
test('recorded receipt state wins over legacy state and reversed dates are rejected',()=>{
  assert.equal(bookingPaymentLabel({payment_status:'pending',recorded_payment_summary:{status:'Paid'}}),'Paid')
  assert.equal(bookingPaymentLabel({payment_status:'deposit_paid'}),'Deposit paid')
  assert.equal(dateError('2026-11-02','2026-11-01'),'End date must be on or after start date.')
  assert.equal(dateError('2026-11-01','2026-11-01'),'')
  assert.deepEqual(periodDates('last_month',new Date(2026,10,11)),['2026-10-01','2026-10-31'])
})
