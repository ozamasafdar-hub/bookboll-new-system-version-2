import { after, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server = await createServer({ server:{ middlewareMode:true }, appType:'custom' })
after(() => server.close())
const { default:Entry } = await server.ssrLoadModule('/src/components/PaymentLedgerEntry.vue')
const group = { id:'online:test',row_kind:'online_group',kind:'online',title:'Online deposit',payment_id:'CHK-EXAMPLE-ONLINE',currency:'QAR',amount:45,status:'paid',
  booking_count:2,allocation_count:2,customer_name:'Example customer',venue_name:'Example court',
  recipient_label:'Bookboll',channel:'marketplace',anchor:{method:'online',status:'paid'},
  can_request_refund:true,bookings:[] }
const render = props => renderToString(createSSRApp(Entry,{canCollect:()=>false,...props}))

test('shared checkout is one collapsed payment with a combined amount and a single refund action', async () => {
  const html=await render({payment:group,canRefund:true})
  assert.match(html,/Online deposit/)
  assert.match(html,/CHK-EXAMPLE-ONLINE/)
  assert.match(html,/aria-expanded="false"/)
  assert.match(html,/2 bookings/)
  assert.match(html,/45\.00/)
  assert.equal((html.match(/aria-label="Review refund of shared online payment"/g)||[]).length,1)
  assert.doesNotMatch(html,/Separate arrival balance/)
})
test('provider and partial views do not claim a full charge or offer a parent refund', async () => {
  const provider=await render({payment:{...group,scoped:true,can_request_refund:false},canRefund:false})
  assert.match(provider,/Amounts visible to your business/)
  assert.doesNotMatch(provider,/aria-label="Review refund/)
  const partial=await render({payment:{...group,partial:true,amount:25,can_request_refund:false},canRefund:true})
  assert.match(partial,/Matching allocations only/)
  assert.doesNotMatch(partial,/aria-label="Review refund/)
})
test('remaining checkout is one payment with paid and pending parts', async () => {
  const html=await render({payment:{...group,row_kind:'remaining_group',kind:'remaining',title:'Remaining payment',payment_id:'CHK-EXAMPLE-BAL',amount:180,status:'partially_paid',paid_amount:100,pending_amount:80,can_request_refund:false},canRefund:true})
  assert.match(html,/Remaining payment/)
  assert.match(html,/CHK-EXAMPLE-BAL/)
  assert.match(html,/180\.00/)
  assert.match(html,/100\.00 collected/)
  assert.match(html,/80\.00 pending/)
  assert.match(html,/Partly collected/)
  assert.doesNotMatch(html,/aria-label="Review refund/)
})
