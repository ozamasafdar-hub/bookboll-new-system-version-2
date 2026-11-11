import { test } from 'node:test'
import assert from 'node:assert/strict'
import { entryAllocations, selectionState, updateSelection, paymentBatches, combineBulkPreviews, collectionMethods } from '../src/lib/paymentBulk.js'

const a={id:'10:platform'},b={id:'10:venue'},c={id:'11'}
const group={bookings:[{allocations:[a,b]},{allocations:[c]}]}
test('group selection uses exact matching allocation IDs, deduplicates and survives pagination',()=>{
  const first=updateSelection(new Map(),[group],true)
  assert.deepEqual([...first.keys()],['10:platform','10:venue','11'])
  const next=updateSelection(first,[c,{id:'12'}],true)
  assert.equal(next.size,4)
  assert.equal(first.size,3)
  assert.deepEqual(selectionState(group,next),{checked:true,partial:false})
  const reduced=updateSelection(next,[b],false)
  assert.deepEqual(selectionState(group,reduced),{checked:false,partial:true})
  assert.equal(reduced.has('12'),true)
})
test('partial filtered checkouts never expand the selection to hidden allocations',()=>{
  const filtered={bookings:[{allocations:[a]}],partial:true}
  assert.deepEqual(entryAllocations(filtered),[a])
  assert.deepEqual([...updateSelection(new Map(),[filtered],true).keys()],['10:platform'])
})
test('all filtered selections exceed one page and 500 allocations without losing or duplicating IDs',()=>{
  const rows=Array.from({length:27586},(_,i)=>({id:String(i+20),booking_id:String(Math.floor(i/2))}))
  const selected=updateSelection(new Map(),rows,true), batches=paymentBatches([...selected.values()])
  assert.equal(selected.size,27586)
  assert.equal(batches.length,56)
  assert.equal(new Set(batches.flat()).size,27586)
  assert.ok(batches.every(batch=>batch.length<=500))
  assert.deepEqual(selectionState(rows[501],selected),{checked:true,partial:false})
})
test('batch boundaries keep booking siblings, subscription siblings and split receipts together',()=>{
  const rows=[{id:'1',booking_id:'8'},{id:'2',booking_id:'9'},{id:'3',booking_id:'8'},{id:'4',subscription_id:'5'},{id:'5',subscription_id:'5'},{id:'6:platform'},{id:'6:venue'}]
  assert.deepEqual(paymentBatches(rows,3),[['1','3','2'],['4','5'],['6:platform','6:venue']])
  assert.throws(()=>paymentBatches([{id:'1',booking_id:'8'},{id:'2',booking_id:'8'}],1),/One booking/)
})
test('all preview batches combine counts and integer currency amounts exactly',()=>{
  const plans=[.1,.2].map((amount,i)=>({data:{changes:[{row_id:String(i)}],excluded:[{row_id:String(i+5)}],impact:[{currency:'QAR',platform_fees:amount,provider_received:-amount},{currency:'KWD',platform_fees:.001}],collection_method:'card'}}))
  const result=combineBulkPreviews(plans)
  assert.equal(result.allocation_count,2)
  assert.equal(result.excluded.length,2)
  assert.equal(result.impact[0].platform_fees,.3)
  assert.equal(result.impact[0].provider_received,-.3)
  assert.equal(result.impact[1].platform_fees,.002)
  assert.equal(result.collection_method,'card')
  assert.deepEqual(collectionMethods.map(m=>m.value),['cash','card','cheque','wire_transfer'])
})
