<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { X, ArrowLeft, ArrowUpRight, Link2, ReceiptText, RotateCcw, RefreshCw, ChevronRight } from 'lucide-vue-next'
import { fetchPaymentDetails, previewPaymentRefund, requestPaymentRefund, refreshPaymentRefund, previewCheckoutCollection, collectCheckoutPayment } from '../lib/payments.js'
import CheckoutPaymentBreakdown from './CheckoutPaymentBreakdown.vue'
import PaymentReviewPanel from './PaymentReviewPanel.vue'
import { money, paymentCategories, channelOptions, methodOptions, statusOptions, labelFor } from '../lib/paymentReport.js'
import { safeStripeUrl, stripeInvoiceUrl, isStripeOnlinePayment } from '../lib/stripeInvoice.js'
import { dialogFocus as vDialogFocus } from '../lib/dialogFocus.js'

const props = defineProps({ payment:{type:Object,required:true}, startRefund:Boolean, canRefund:Boolean, canCollectPayment:{type:Function, default:()=>false} })
const emit = defineEmits(['close','booking','changed'])
const active = ref(props.payment), data = ref(null), loading = ref(false), error = ref('')
const refundOpen = ref(false), refundPreview = ref(null), refundBusy = ref(false), refundError = ref(''), reason = ref('requested_by_customer')
const history = ref([])
const reviewBusy = ref(false)
const collection = ref(null), collectionBusy = ref(false), collectionError = ref(''), collectionMethod = ref('cash')
let request = 0
const current = computed(() => data.value?.payment || active.value)
const checkout = computed(() => data.value?.checkout_payment)
const display = computed(() => checkout.value || current.value)
const checkoutConnections = computed(() => data.value?.checkout_payments || [])
const mayCollectCheckout = computed(() => checkout.value?.kind === 'remaining' && checkout.value.bookings.flatMap(b => b.allocations).some(props.canCollectPayment))
const status = row => row.status === 'partially_paid' ? 'Partly collected' : row.status === 'mixed' ? 'Mixed statuses' : labelFor(statusOptions,row.status)
const connected = computed(() => data.value?.connected_transactions || [])
const refund = computed(() => data.value?.refund)
const sourceId = computed(() => data.value?.refund_source_payment_id || active.value.source_payment_id || active.value.id)
const mayRefund = computed(() => props.canRefund && data.value?.can_request_refund)
const refundText = computed(() => ({succeeded:'Refunded',pending:'Refund pending',requires_action:'Refund needs attention',failed:'Refund failed',canceled:'Refund canceled',submitting:'Submitting refund',unknown:'Check refund status'}[refund.value?.status] || 'Refund'))
const receiptUrl = computed(() => safeStripeUrl(refund.value?.receipt_url) || stripeInvoiceUrl(current.value))
const category = p => p.row_kind === 'receipt' ? 'Original payment' : labelFor(paymentCategories, p.category)
async function load(row = active.value, original = false) {
  const id = ++request
  active.value = row; data.value = null; error.value = ''; loading.value = true
  refundOpen.value = false; refundPreview.value = null; refundError.value = ''
  collection.value = null; collectionError.value = ''
  try {
    const response = await fetchPaymentDetails(row.source_payment_id || row.id, original ? undefined : row.id)
    if (id !== request) return
    if (!response.success) throw new Error(response.message || 'Payment details could not be loaded.')
    data.value = response.data
    active.value = response.data.payment
  } catch(e) { if (id === request) error.value = e.message }
  finally { if (id === request) loading.value = false }
}
async function inspect(row) { history.value.push(active.value); await load(row) }
async function back() { await load(history.value.pop()) }
async function openRefund() {
  if (!mayRefund.value) return
  refundOpen.value = true; refundError.value = ''; refundPreview.value = null
  if (data.value.refund_disabled_reason) return
  refundBusy.value = true
  try {
    const response = await previewPaymentRefund(sourceId.value)
    if (!response.success) throw new Error(response.message)
    refundPreview.value = response.data
  } catch(e) { refundError.value = e.message }
  finally { refundBusy.value = false }
}
async function confirmRefund() {
  if (!mayRefund.value || !refundPreview.value || refundBusy.value) return
  refundBusy.value = true; refundError.value = ''
  try {
    const response = await requestPaymentRefund(sourceId.value, {fingerprint:refundPreview.value.fingerprint, reason:reason.value})
    if (!response.success) throw new Error(response.message)
    await load(); emit('changed')
  } catch(e) { refundError.value = e.message; refundPreview.value = null }
  finally { refundBusy.value = false }
}
async function refreshRefund() {
  refundBusy.value = true; refundError.value = ''
  try {
    const response = await refreshPaymentRefund(sourceId.value)
    if (!response.success) throw new Error(response.message)
    await load(); emit('changed')
  } catch(e) { refundError.value = e.message }
  finally { refundBusy.value = false }
}
async function reviewCollection() {
  collectionBusy.value = true; collectionError.value = ''
  try {
    const response = await previewCheckoutCollection(current.value.source_payment_id || current.value.id,current.value.id)
    if (!response.success) throw new Error(response.message)
    collection.value = response.data
  } catch(e) { collectionError.value = e.message }
  finally { collectionBusy.value = false }
}
async function confirmCollection() {
  if (!collection.value || collectionBusy.value) return
  collectionBusy.value = true; collectionError.value = ''
  try {
    const response = await collectCheckoutPayment(current.value.source_payment_id || current.value.id, {row_id:current.value.id, fingerprint:collection.value.fingerprint, method:collectionMethod.value})
    if (!response.success) throw new Error(response.message)
    await load(); emit('changed')
  } catch(e) { collectionError.value = e.message; collection.value = null }
  finally { collectionBusy.value = false }
}
async function reviewSaved() { await load(current.value,true); emit('changed') }
function close() { if (!refundBusy.value && !collectionBusy.value && !reviewBusy.value) emit('close') }
onMounted(async () => { await load(); if (props.startRefund) await openRefund() })
onBeforeUnmount(() => { request++ })
</script>

<template>
  <Teleport to="body"><div class="pay-overlay" @click.self="close">
    <section v-dialog-focus="close" class="pay-detail pay-detail-expanded" role="dialog" aria-modal="true" aria-labelledby="payment-detail-title" :aria-busy="loading">
      <header class="pay-detail-header"><div><button v-if="history.length" class="pay-link pay-back" :disabled="refundBusy || collectionBusy || reviewBusy" @click="back"><ArrowLeft :size="14" /> Back to payment</button><h2 id="payment-detail-title">Payment details</h2><p>{{ display.checkout_payment_reference || display.payment_id || '#' + display.id }}</p></div><button class="pay-icon" :disabled="refundBusy || collectionBusy || reviewBusy" aria-label="Close payment details" @click="close"><X :size="20" /></button></header>
      <div class="pay-detail-body">
        <div v-if="!loading && !error" class="pay-detail-hero"><div><span>{{ checkout ? checkout.title : category(current) }}</span><strong>{{ money(display.amount,display.currency) }}</strong><p>{{ display.recipient_label }}</p><small v-if="checkout?.status === 'partially_paid'">{{ money(checkout.paid_amount,checkout.currency) }} collected · {{ money(checkout.pending_amount,checkout.currency) }} pending</small></div><div class="pay-detail-state"><span class="pay-status" :class="'pay-status-' + display.status">{{ status(display) }}</span><small>{{ display.payment_date || 'Date not recorded' }}</small></div></div>
        <p v-if="loading" class="pay-detail-note" role="status">Loading connected transactions…</p>
        <p v-if="error" class="pay-notice pay-error" role="alert">{{ error }} <button class="pay-link" @click="load()">Try again</button></p>
        <p v-if="current.review_reason" class="pay-notice">{{ current.review_reason }}</p>
        <PaymentReviewPanel v-if="!loading && !error && data?.review" :key="current.id" :payment="current" :review="data.review" @busy="reviewBusy=$event" @saved="reviewSaved"/>
        <p v-if="checkout" class="pay-detail-note">Checkout {{ checkout.checkout_reference }} · {{ checkout.booking_count }} bookings. Each booking’s share is shown below.</p>
        <div class="pay-detail-actions">
          <a v-if="receiptUrl" class="btn" :href="receiptUrl" target="_blank" rel="noopener noreferrer"><ReceiptText :size="16" /> {{ current.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt' }} <ArrowUpRight :size="14" /></a>
          <span v-else-if="isStripeOnlinePayment(current)" class="pay-muted-action"><ReceiptText :size="16" /> Stripe receipt not available</span>
          <button v-if="mayRefund && !refundOpen" class="btn pay-refund-button" :disabled="reviewBusy" @click="openRefund"><RotateCcw :size="15" /> Refund online payment</button>
        </div>

        <section v-if="refund" class="pay-refund-state" aria-label="Refund status"><div><h3>{{ refundText }}</h3><strong>{{ money(refund.amount,refund.currency) }}</strong><p v-if="refund.status === 'succeeded'">Stripe confirmed the refund to the original payment method.</p><p v-else-if="refund.status === 'unknown' || refund.status === 'submitting'">We are waiting for Stripe confirmation. Check the status before taking further action.</p><p v-else-if="refund.status === 'pending' || refund.status === 'requires_action'">Stripe has not completed the refund yet. The payment remains received until confirmation.</p><p v-else>The payment has not been refunded. Review the refund in Stripe.</p><small v-if="refund.id">{{ refund.id }}</small></div><button v-if="canRefund && data.can_refresh_refund" class="btn" :disabled="refundBusy || reviewBusy" @click="refreshRefund"><RefreshCw :size="14" /> Check status</button></section>
        <section v-if="refundOpen" class="pay-refund-confirm" aria-labelledby="refund-title">
          <h3 id="refund-title">Refund the original Stripe payment</h3>
          <p v-if="data.refund_disabled_reason" class="pay-notice">{{ data.refund_disabled_reason }}</p>
          <p v-else-if="refundBusy && !refundPreview" role="status">Checking Stripe and connected payments…</p>
          <template v-if="refundPreview"><strong class="pay-refund-amount">{{ money(refundPreview.amount,refundPreview.currency) }}</strong><p>This refunds the full online payment, including its platform fee and venue share. On-site balances stay unchanged. The booking is not canceled.</p>
            <details class="pay-refund-records"><summary>{{ refundPreview.payments.length }} connected payment records</summary><ul><li v-for="p in refundPreview.payments" :key="p.id">{{ p.payment_id || '#' + p.id }} <span>{{ p.booking_ref }}</span></li></ul></details>
            <p v-if="refundPreview.reverses_provider_transfer">The connected provider transfer will also be reversed.</p>
            <label>Reason<select v-model="reason" :disabled="refundBusy || reviewBusy"><option value="requested_by_customer">Requested by customer</option><option value="duplicate">Duplicate payment</option><option value="fraudulent">Fraudulent payment</option></select></label>
          </template>
          <p v-if="refundError" class="pay-notice pay-error" role="alert">{{ refundError }}</p>
          <div class="pay-actions"><button class="btn" :disabled="refundBusy || reviewBusy" @click="refundOpen = false">{{ data.refund_disabled_reason ? 'Close' : 'Cancel' }}</button><button v-if="refundPreview" class="btn pay-refund-submit" :disabled="refundBusy || reviewBusy" @click="confirmRefund">{{ refundBusy ? 'Requesting refund…' : `Refund ${money(refundPreview.amount,refundPreview.currency)}` }}</button><button v-else-if="refundError" class="btn" :disabled="refundBusy || reviewBusy" @click="openRefund">Review again</button></div>
        </section>
        <p v-else-if="refundError" class="pay-notice pay-error" role="alert">{{ refundError }}</p>

        <section v-if="checkout" class="pay-detail-section"><dl class="pay-detail-facts"><div><dt>Customer</dt><dd>{{ checkout.customer_name }}</dd></div><div><dt>Collection method</dt><dd>{{ checkout.method === 'mixed' ? 'Multiple methods' : labelFor(methodOptions,checkout.method) }}</dd></div><div><dt>Venue</dt><dd>{{ checkout.venue_name }}</dd></div><div><dt>Booking channel</dt><dd>{{ checkout.channel === 'mixed' ? 'Multiple channels' : labelFor(channelOptions,checkout.channel) }}</dd></div></dl><h3>Booking allocations</h3><CheckoutPaymentBreakdown :payment="checkout" @booking="row => !collectionBusy && !refundBusy && !reviewBusy && emit('booking',row,false)" /></section>
        <section v-if="mayCollectCheckout" class="pay-detail-section">
          <h3>Record the remaining payment</h3><p class="pay-detail-note">Record money already received on site. This does not charge a card.</p>
          <button v-if="!collection" class="btn primary" :disabled="collectionBusy" @click="reviewCollection">{{ collectionBusy ? 'Checking balance…' : 'Review outstanding balance' }}</button>
          <template v-else><p><strong>{{ money(collection.amount,collection.currency) }}</strong> outstanding across {{ collection.booking_count }} bookings</p><label class="pay-collection-method">Received by<select v-model="collectionMethod" :disabled="collectionBusy"><option value="cash">Cash</option><option value="card">Card terminal</option><option value="wire_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></label><div class="pay-actions"><button class="btn" :disabled="collectionBusy" @click="collection = null">Cancel</button><button class="btn primary" :disabled="collectionBusy" @click="confirmCollection">{{ collectionBusy ? 'Recording…' : `Record ${money(collection.amount,collection.currency)} received` }}</button></div></template>
        </section>
        <p v-if="collectionError" class="pay-notice pay-error" role="alert">{{ collectionError }}</p>
        <section v-if="checkout && checkoutConnections.length" class="pay-detail-section"><div class="pay-connected-heading"><h3><Link2 :size="17" /> Other payment in this checkout</h3></div><div class="pay-connections"><button v-for="p in checkoutConnections" :key="p.id" class="pay-connection" :disabled="refundBusy || collectionBusy || reviewBusy" @click="inspect(p.anchor)"><div><b>{{ p.title }}</b><small>{{ p.payment_id }}</small><small>{{ p.booking_count }} bookings · {{ p.recipient_label }}</small></div><div class="pay-connection-value"><b>{{ money(p.amount,p.currency) }}</b><span class="pay-status" :class="'pay-status-' + p.status">{{ status(p) }}</span></div><ChevronRight :size="16" /></button></div></section>

        <section v-if="!checkout && !loading && !error" class="pay-detail-section"><h3>Booking &amp; collection</h3><dl class="pay-detail-facts">
          <div><dt>Customer</dt><dd>{{ current.customer_name }}</dd></div><div><dt>Venue</dt><dd>{{ current.venue_name }}<small v-if="current.listing_name">{{ current.listing_name }}</small></dd></div>
          <div><dt>Booking</dt><dd><button v-if="current.booking_id && current.can_view_booking !== false" class="pay-link" :disabled="refundBusy || reviewBusy" @click="emit('booking',current,false)">{{ current.booking_ref || '#' + current.booking_id }}</button><span v-else>{{ current.booking_ref || '—' }}</span></dd></div><div><dt>Payment method</dt><dd>{{ labelFor(methodOptions,current.method) }}</dd></div>
          <div><dt>Booking channel</dt><dd>{{ labelFor(channelOptions,current.channel) }}</dd></div><div><dt>Booking created by</dt><dd>{{ current.created_by_name || 'Not recorded' }}<small v-if="current.created_by_kind === 'staff'">Staff booking · no platform fee</small></dd></div>
          <div v-if="current.fee_rate != null"><dt>{{ current.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'Historical fee policy' : 'Saved platform fee rate' }}</dt><dd>{{ current.fee_rate }}%</dd></div><div v-if="current.receipt_amount != null"><dt>Original receipt amount</dt><dd>{{ money(current.receipt_amount,current.currency) }}</dd></div>
        </dl></section>

        <section v-if="!checkout && !loading && !error" class="pay-detail-section"><div class="pay-connected-heading"><h3><Link2 :size="17" /> Connected transactions</h3><span>{{ connected.length }}</span></div><p class="pay-detail-note">Original receipts, fee splits and other payments linked to this booking or Stripe payment.</p>
          <div v-if="connected.length" class="pay-connections"><button v-for="p in connected" :key="p.id" class="pay-connection" :disabled="refundBusy || reviewBusy" @click="inspect(p)"><div><span class="pay-connection-relation">{{ p.row_kind === 'receipt' ? 'Original receipt · split below' : p.relationship }}</span><b>{{ category(p) }}</b><small>{{ p.payment_id || '#' + p.id }} · {{ p.recipient_label }}<template v-if="p.booking_ref"> · {{ p.booking_ref }}</template></small></div><div class="pay-connection-value"><b>{{ money(p.amount,p.currency) }}</b><span class="pay-status" :class="'pay-status-' + p.status">{{ labelFor(statusOptions,p.status) }}</span></div><ChevronRight :size="16" /></button></div>
          <p v-else class="pay-connections-empty">No connected transactions recorded.</p>
        </section>
        <details v-if="current.notes" class="pay-detail-note"><summary>Original payment note</summary><p>{{ current.notes }}</p></details>
        <p v-if="current.can_view_booking === false" class="pay-detail-note">This historical payment belongs to your business. The venue is now managed by another operator.</p>
      </div>
      <footer class="pay-detail-footer"><button class="btn" :disabled="refundBusy || collectionBusy || reviewBusy" @click="close">Close</button><button v-if="!checkout && canCollectPayment(current) && !loading" class="btn primary" :disabled="refundBusy || reviewBusy" @click="emit('booking',current,true)">Record payment</button></footer>
    </section>
  </div></Teleport>
</template>
