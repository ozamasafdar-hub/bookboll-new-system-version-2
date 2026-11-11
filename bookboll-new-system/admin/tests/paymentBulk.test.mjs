import { test } from 'node:test'
import assert from 'node:assert/strict'
import { entryAllocations, selectionState, updateSelection } from '../src/lib/paymentBulk.js'

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
test('bounded batches fail without changing the existing selection',()=>{
  const existing=new Map([['10:platform',a]])
  assert.throws(()=>updateSelection(existing,Array.from({length:501},(_,i)=>({id:String(i+20)})),true),/500/)
  assert.equal(existing.size,1)
})
