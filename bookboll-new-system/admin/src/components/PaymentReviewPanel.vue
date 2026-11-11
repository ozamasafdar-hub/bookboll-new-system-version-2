<script setup>
import { computed, ref, watch } from 'vue'
import { ClipboardCheck, Check, ArrowLeft } from 'lucide-vue-next'
import { previewPaymentReview, savePaymentReview } from '../lib/payments.js'
import { money, labelFor, paymentCategories, statusOptions } from '../lib/paymentReport.js'

const props = defineProps({ payment:Object, review:Object })
const emit = defineEmits(['saved','busy'])
const action = ref(''), reason = ref(''), fee = ref(''), preview = ref(null), busy = ref(false), error = ref('')
const actions = {
  cancelled_uncollected:{ title:'Confirm no money received', description:'Close this cancelled, uncollected record. It stays visible with zero earnings.' },
  retained_deposit:{ title:'Confirm retained online deposit', description:'The entire recorded amount was Bookboll’s provider booking fee.' },
  provider_split:{ title:'Record the fee and provider share', description:'Enter the actual platform fee. The rest belongs to the external business.' },
  note:{ title:'Keep open with a note', description:'Record what needs checking. Keep the amount out of earnings.' },
  reopen:{ title:'Reopen this review', description:'Remove the saved reporting decision and review the original evidence again.' },
}
const options = computed(() => (props.review.options || []).map(key => ({key,...actions[key]})))
const valid = computed(() => action.value && reason.value.trim().length >= 10 && (action.value !== 'provider_split' || (fee.value !== '' && Number(fee.value) >= 0 && Number(fee.value) <= Number(props.payment.receipt_amount ?? props.payment.amount))))
const sourceId = computed(() => props.payment.source_payment_id || props.payment.id)
const payload = () => ({action:action.value,reason:reason.value.trim(),...(action.value === 'provider_split' ? {fee_amount:fee.value} : {})})
watch(() => props.payment.id, () => { action.value=''; reason.value=''; fee.value=''; preview.value=null; error.value='' })
function setBusy(value) { busy.value=value; emit('busy',value) }
async function check() {
  if (!valid.value || busy.value) return
  setBusy(true); error.value=''
  try {
    const response=await previewPaymentReview(sourceId.value,payload())
    if (!response.success) throw new Error(response.message)
    preview.value=response.data
  } catch(e) { error.value=e.message }
  finally { setBusy(false) }
}
async function save() {
  if (!preview.value || busy.value) return
  setBusy(true); error.value=''
  try {
    const response=await savePaymentReview(sourceId.value,{...payload(),fingerprint:preview.value.fingerprint})
    if (!response.success) throw new Error(response.message)
    emit('saved')
  } catch(e) { error.value=e.message; preview.value=null }
  finally { setBusy(false) }
}
</script>

<template>
  <section class="pay-review" aria-labelledby="pay-review-title">
    <div class="pay-review-heading"><ClipboardCheck :size="20"/><div><h3 id="pay-review-title">{{ review.resolved ? 'Review resolved' : 'Resolve this payment' }}</h3><p>{{ review.resolved ? 'Your saved decision is included in reporting.' : 'Check the evidence, choose an action, then preview its effect.' }}</p></div></div>
    <p v-if="review.stale" class="pay-notice">The payment changed after its last review. The previous decision is no longer applied.</p>
    <dl class="pay-review-evidence">
      <div><dt>Original payment status</dt><dd>{{ labelFor(statusOptions,review.evidence.original_status) }}</dd></div>
      <div><dt>Booking status</dt><dd>{{ review.evidence.booking_status || 'Not recorded' }}</dd></div>
      <div><dt>Collection date</dt><dd>{{ review.evidence.paid_at || 'Not recorded' }}</dd></div>
      <div><dt>Stripe reference</dt><dd>{{ review.evidence.processor_reference || 'Not recorded' }}</dd></div>
    </dl>
    <template v-if="!preview && options.length">
      <fieldset class="pay-review-options" :disabled="busy"><legend>Choose an action</legend><label v-for="option in options" :key="option.key" :class="{'is-selected':action === option.key}"><input v-model="action" type="radio" name="payment-review-action" :value="option.key"/><span><b>{{ option.title }}</b><small>{{ option.description }}</small></span></label></fieldset>
      <p v-if="options.length === 1 && options[0].key === 'note'" class="pay-detail-note">The saved evidence needs a source-data correction before this record can be resolved. Record the missing evidence below and keep the review open.</p>
      <label v-if="action === 'provider_split'" class="pay-review-field">Platform fee ({{ payment.currency }})<input v-model="fee" inputmode="decimal" type="number" min="0" step="any" :max="payment.receipt_amount ?? payment.amount" :disabled="busy"/><small>The provider receives {{ money(Math.max(0,Number(payment.receipt_amount ?? payment.amount)-Number(fee)),payment.currency) }}.</small></label>
      <label v-if="action" class="pay-review-field">Reason and supporting evidence<textarea v-model="reason" rows="3" maxlength="1500" :disabled="busy" placeholder="Explain what you checked and why this action is correct."/><small>Required · at least 10 characters. Saved with your name and the time.</small></label>
      <button class="btn primary" :disabled="!valid || busy" @click="check">{{ busy ? 'Checking evidence…' : 'Preview action' }}</button>
    </template>
    <section v-if="preview" class="pay-review-preview" aria-label="Review action preview">
      <h4>{{ actions[preview.action].title }}</h4>
      <p>{{ preview.reason }}</p>
      <dl class="pay-review-impact"><div><dt>Change to Bookboll received</dt><dd>{{ money(preview.change.bookboll_received,preview.currency) }}</dd></div><div><dt>Change to platform fees</dt><dd>{{ money(preview.change.platform_fees,preview.currency) }}</dd></div><div><dt>Change to provider received</dt><dd>{{ money(preview.change.provider_received,preview.currency) }}</dd></div></dl>
      <div class="pay-review-result" v-for="(row,index) in preview.after" :key="index"><span>{{ labelFor(paymentCategories,row.category) }}<small>{{ row.recipient_label }} · {{ labelFor(statusOptions,row.status) }}</small></span><b>{{ money(row.amount,row.currency) }}</b></div>
      <p class="pay-detail-note">{{ preview.remains_in_review ? 'This record stays in Needs review.' : 'This record leaves Needs review and stays in All records.' }} Original receipts are preserved. This does not collect, refund or transfer money.</p>
      <div class="pay-actions"><button class="btn" :disabled="busy" @click="preview=null"><ArrowLeft :size="14"/> Edit action</button><button class="btn primary" :disabled="busy" @click="save"><Check :size="15"/> {{ busy ? 'Saving…' : 'Confirm action' }}</button></div>
    </section>
    <p v-if="error" class="pay-notice pay-error" role="alert">{{ error }}</p>
    <details v-if="review.history.length" class="pay-review-history"><summary>Review history ({{ review.history.length }})</summary><ol><li v-for="entry in review.history" :key="entry.id"><b>{{ actions[entry.action]?.title || entry.action }}</b><small>{{ entry.actor_name }} · {{ entry.created_at }}</small><p>{{ entry.reason }}</p></li></ol></details>
  </section>
</template>
