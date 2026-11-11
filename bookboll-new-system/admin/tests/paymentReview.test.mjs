import { after, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const server=await createServer({server:{middlewareMode:true,hmr:false},appType:'custom'})
after(()=>server.close())
const {default:Entry}=await server.ssrLoadModule('/src/components/PaymentLedgerEntry.vue')
const {default:Review}=await server.ssrLoadModule('/src/components/PaymentReviewPanel.vue')
const payment={id:'42',payment_id:'QA-42',category:'unclassified',status:'cancelled',amount:549,currency:'QAR',method:'online',row_kind:'allocation',can_review:true}
const review={options:['cancelled_uncollected','note'],history:[],evidence:{original_status:'cancelled',booking_status:'cancelled',paid_at:null,processor_reference:null}}
test('reviewable rows expose an action, and provider rows do not',async()=>{
  const html=await renderToString(createSSRApp(Entry,{payment,canCollect:()=>false}))
  assert.match(html,/Review payment/)
  const denied=await renderToString(createSSRApp(Entry,{payment:{...payment,can_review:false},canCollect:()=>false}))
  assert.doesNotMatch(denied,/Review payment/)
})
test('review starts with evidence and no preselected financial decision',async()=>{
  const html=await renderToString(createSSRApp(Review,{payment,review}))
  assert.match(html,/Original payment status/)
  assert.match(html,/Confirm no money received/)
  assert.match(html,/Keep open with a note/)
  assert.match(html,/<button[^>]*disabled[^>]*>Preview action<\/button>/)
  assert.doesNotMatch(html,/Confirm action/)
  assert.doesNotMatch(html,/<input[^>]*checked/)
})
test('history escapes administrator notes and shows who made the decision',async()=>{
  const html=await renderToString(createSSRApp(Review,{payment,review:{...review,history:[{id:1,action:'note',reason:'<script>alert(1)</script>',actor_name:'QA Admin',created_at:'2026-11-11'}]}}))
  assert.match(html,/QA Admin/)
  assert.match(html,/&lt;script&gt;/)
  assert.doesNotMatch(html,/<script>alert/)
})
test('stale decisions explain why the saved attribution no longer applies',async()=>{
  const html=await renderToString(createSSRApp(Review,{payment,review:{...review,stale:true}}))
  assert.match(html,/previous decision is no longer applied/)
})
