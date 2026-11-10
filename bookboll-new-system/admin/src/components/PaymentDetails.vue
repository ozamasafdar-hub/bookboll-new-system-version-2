<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { X, ArrowLeft, ArrowUpRight, Link2, ReceiptText, RotateCcw, RefreshCw, ChevronRight } from 'lucide-vue-next'
import { fetchPaymentDetails, previewPaymentRefund, requestPaymentRefund, refreshPaymentRefund } from '../lib/payments.js'
import { money, paymentCategories, channelOptions, methodOptions, statusOptions, labelFor } from '../lib/paymentReport.js'
import { safeStripeUrl, stripeInvoiceUrl, isStripeOnlinePayment } from '../lib/stripeInvoice.js'
import { dialogFocus as vDialogFocus } from '../lib/dialogFocus.js'

const props = defineProps({ payment:{type:Object,required:true}, startRefund:Boolean, canRefund:Boolean, canCollectPayment:{type:Function, default:()=>false} })
const emit = defineEmits(['close','booking','changed'])
const active = ref(props.payment), data = ref(null), loading = ref(false), error = ref('')
const refundOpen = ref(false), refundPreview = ref(null), refundBusy = ref(false), refundError = ref(''), reason = ref('requested_by_customer')
const history = ref([])
let request = 0
const current = computed(() => data.value?.payment || active.value)
const connected = computed(() => data.value?.connected_transactions || [])
const refund = computed(() => data.value?.refund)
const sourceId = computed(() => data.value?.refund_source_payment_id || active.value.source_payment_id || active.value.id)
const mayRefund = computed(() => props.canRefund && data.value?.can_request_refund)
const refundText = computed(() => ({succeeded:'Refunded',pending:'Refund pending',requires_action:'Refund needs attention',failed:'Refund failed',canceled:'Refund canceled',submitting:'Submitting refund',unknown:'Check refund status'}[refund.value?.status] || 'Refund'))
const receiptUrl = computed(() => safeStripeUrl(refund.value?.receipt_url) || stripeInvoiceUrl(current.value))
const category = p => p.row_kind === 'receipt' ? 'Original payment' : labelFor(paymentCategories, p.category)
async function load(row = active.value) {
  const id = ++request
  active.value = row; data.value = null; error.value = ''; loading.value = true
  refundOpen.value = false; refundPreview.value = null; refundError.value = ''
  try {
    const response = await fetchPaymentDetails(row.source_payment_id || row.id, row.id)
    if (id !== request) return
    if (!response.success) throw new Error(response.message || 'Payment details could not be loaded.')
    data.value = response.data
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
function close() { if (!refundBusy.value) emit('close') }
onMounted(async () => { await load(); if (props.startRefund) await openRefund() })
onBeforeUnmount(() => { request++ })
</script>

<template>
  <Teleport to="body"><div class="pay-overlay" @click.self="close">
    <section v-dialog-focus="close" class="pay-detail pay-detail-expanded" role="dialog" aria-modal="true" aria-labelledby="payment-detail-title" :aria-busy="loading">
      <header class="pay-detail-header"><div><button v-if="history.length" class="pay-link pay-back" :disabled="refundBusy" @click="back"><ArrowLeft :size="14" /> Back to payment</button><h2 id="payment-detail-title">Payment details</h2><p>{{ current.payment_id || '#' + current.id }}</p></div><button class="pay-icon" :disabled="refundBusy" aria-label="Close payment details" @click="close"><X :size="20" /></button></header>
      <div class="pay-detail-body">
        <div class="pay-detail-hero"><div><span>{{ category(current) }}</span><strong>{{ money(current.amount,current.currency) }}</strong><p>{{ current.recipient_label }}</p></div><div class="pay-detail-state"><span class="pay-status" :class="'pay-status-' + current.status">{{ labelFor(statusOptions,current.status) }}</span><small>{{ current.payment_date || 'Date not recorded' }}</small></div></div>
        <p v-if="loading" class="pay-detail-note" role="status">Loading connected transactions…</p>
        <p v-if="error" class="pay-notice pay-error" role="alert">{{ error }} <button class="pay-link" @click="load()">Try again</button></p>
        <p v-if="current.review_reason" class="pay-notice">{{ current.review_reason }}</p>
        <div class="pay-detail-actions">
          <a v-if="receiptUrl" class="btn" :href="receiptUrl" target="_blank" rel="noopener noreferrer"><ReceiptText :size="16" /> {{ current.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt' }} <ArrowUpRight :size="14" /></a>
          <span v-else-if="isStripeOnlinePayment(current)" class="pay-muted-action"><ReceiptText :size="16" /> Stripe receipt not available</span>
          <button v-if="mayRefund && !refundOpen" class="btn pay-refund-button" @click="openRefund"><RotateCcw :size="15" /> Refund online payment</button>
        </div>

        <section v-if="refund" class="pay-refund-state" aria-label="Refund status"><div><h3>{{ refundText }}</h3><strong>{{ money(refund.amount,refund.currency) }}</strong><p v-if="refund.status === 'succeeded'">Stripe confirmed the refund to the original payment method.</p><p v-else-if="refund.status === 'unknown' || refund.status === 'submitting'">We are waiting for Stripe confirmation. Check the status before taking further action.</p><p v-else-if="refund.status === 'pending' || refund.status === 'requires_action'">Stripe has not completed the refund yet. The payment remains received until confirmation.</p><p v-else>The payment has not been refunded. Review the refund in Stripe.</p><small v-if="refund.id">{{ refund.id }}</small></div><button v-if="canRefund && data.can_refresh_refund" class="btn" :disabled="refundBusy" @click="refreshRefund"><RefreshCw :size="14" /> Check status</button></section>
        <section v-if="refundOpen" class="pay-refund-confirm" aria-labelledby="refund-title">
          <h3 id="refund-title">Refund the original Stripe payment</h3>
          <p v-if="data.refund_disabled_reason" class="pay-notice">{{ data.refund_disabled_reason }}</p>
          <p v-else-if="refundBusy && !refundPreview" role="status">Checking Stripe and connected payments…</p>
          <template v-if="refundPreview"><strong class="pay-refund-amount">{{ money(refundPreview.amount,refundPreview.currency) }}</strong><p>This refunds the full online payment, including its platform fee and venue share. On-site balances stay unchanged. The booking is not canceled.</p>
            <details class="pay-refund-records"><summary>{{ refundPreview.payments.length }} connected payment records</summary><ul><li v-for="p in refundPreview.payments" :key="p.id">{{ p.payment_id || '#' + p.id }} <span>{{ p.booking_ref }}</span></li></ul></details>
            <p v-if="refundPreview.reverses_provider_transfer">The connected provider transfer will also be reversed.</p>
            <label>Reason<select v-model="reason" :disabled="refundBusy"><option value="requested_by_customer">Requested by customer</option><option value="duplicate">Duplicate payment</option><option value="fraudulent">Fraudulent payment</option></select></label>
          </template>
          <p v-if="refundError" class="pay-notice pay-error" role="alert">{{ refundError }}</p>
          <div class="pay-actions"><button class="btn" :disabled="refundBusy" @click="refundOpen = false">{{ data.refund_disabled_reason ? 'Close' : 'Cancel' }}</button><button v-if="refundPreview" class="btn pay-refund-submit" :disabled="refundBusy" @click="confirmRefund">{{ refundBusy ? 'Requesting refund…' : `Refund ${money(refundPreview.amount,refundPreview.currency)}` }}</button><button v-else-if="refundError" class="btn" :disabled="refundBusy" @click="openRefund">Review again</button></div>
        </section>
        <p v-else-if="refundError" class="pay-notice pay-error" role="alert">{{ refundError }}</p>

        <section class="pay-detail-section"><h3>Booking &amp; collection</h3><dl class="pay-detail-facts">
          <div><dt>Customer</dt><dd>{{ current.customer_name }}</dd></div><div><dt>Venue</dt><dd>{{ current.venue_name }}<small v-if="current.listing_name">{{ current.listing_name }}</small></dd></div>
          <div><dt>Booking</dt><dd><button v-if="current.booking_id && current.can_view_booking !== false" class="pay-link" :disabled="refundBusy" @click="emit('booking',current,false)">{{ current.booking_ref || '#' + current.booking_id }}</button><span v-else>{{ current.booking_ref || '—' }}</span></dd></div><div><dt>Payment method</dt><dd>{{ labelFor(methodOptions,current.method) }}</dd></div>
          <div><dt>Booking channel</dt><dd>{{ labelFor(channelOptions,current.channel) }}</dd></div><div><dt>Booking created by</dt><dd>{{ current.created_by_name || 'Not recorded' }}<small v-if="current.created_by_kind === 'staff'">Staff booking · no platform fee</small></dd></div>
          <div v-if="current.fee_rate != null"><dt>{{ current.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'Historical fee policy' : 'Saved platform fee rate' }}</dt><dd>{{ current.fee_rate }}%</dd></div><div v-if="current.receipt_amount != null"><dt>Original receipt amount</dt><dd>{{ money(current.receipt_amount,current.currency) }}</dd></div>
        </dl></section>

        <section v-if="!loading && !error" class="pay-detail-section"><div class="pay-connected-heading"><h3><Link2 :size="17" /> Connected transactions</h3><span>{{ connected.length }}</span></div><p class="pay-detail-note">Original receipts, fee splits and other payments linked to this booking or Stripe payment.</p>
          <div v-if="connected.length" class="pay-connections"><button v-for="p in connected" :key="p.id" class="pay-connection" :disabled="refundBusy" @click="inspect(p)"><div><span class="pay-connection-relation">{{ p.row_kind === 'receipt' ? 'Original receipt · split below' : p.relationship }}</span><b>{{ category(p) }}</b><small>{{ p.payment_id || '#' + p.id }} · {{ p.recipient_label }}<template v-if="p.booking_ref"> · {{ p.booking_ref }}</template></small></div><div class="pay-connection-value"><b>{{ money(p.amount,p.currency) }}</b><span class="pay-status" :class="'pay-status-' + p.status">{{ labelFor(statusOptions,p.status) }}</span></div><ChevronRight :size="16" /></button></div>
          <p v-else class="pay-connections-empty">No connected transactions recorded.</p>
        </section>
        <details v-if="current.notes" class="pay-detail-note"><summary>Original payment note</summary><p>{{ current.notes }}</p></details>
        <p v-if="current.can_view_booking === false" class="pay-detail-note">This historical payment belongs to your business. The venue is now managed by another operator.</p>
      </div>
      <footer class="pay-detail-footer"><button class="btn" :disabled="refundBusy" @click="close">Close</button><button v-if="canCollectPayment(current) && !loading" class="btn primary" :disabled="refundBusy" @click="emit('booking',current,true)">Record payment</button></footer>
    </section>
  </div></Teleport>
</template>
