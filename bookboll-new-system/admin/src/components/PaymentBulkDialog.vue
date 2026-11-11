<script setup>
import { computed, ref } from 'vue'
import { X, ArrowLeft, Check } from 'lucide-vue-next'
import { previewPaymentBulk, savePaymentBulk } from '../lib/payments.js'
import { money, labelFor, paymentCategories } from '../lib/paymentReport.js'
import { dialogFocus as vDialogFocus } from '../lib/dialogFocus.js'

const props=defineProps({ rows:Array, action:String })
const emit=defineEmits(['close','saved'])
const destination=ref(''),reason=ref(''),busy=ref(false),error=ref(''),preview=ref(null)
const title=computed(() => props.action === 'mark_paid' ? 'Mark pending payments as paid' : 'Change allocation / recipient')
const valid=computed(() => reason.value.trim().length >= 10 && (props.action === 'mark_paid' || destination.value))
const payload=() => ({row_ids:props.rows.map(row => String(row.id)),action:props.action,reason:reason.value.trim(),...(props.action === 'reassign' ? {destination:destination.value} : {})})
function close() { if (!busy.value) emit('close') }
async function check() {
  if (!valid.value || busy.value) return
  busy.value=true; error.value=''
  try { const result=await previewPaymentBulk(payload()); if (!result.success) throw new Error(result.message); preview.value=result.data }
  catch(e) { error.value=e.message }
  finally { busy.value=false }
}
async function save() {
  if (!preview.value?.changes.length || busy.value) return
  busy.value=true; error.value=''
  try { const result=await savePaymentBulk({...payload(),fingerprint:preview.value.fingerprint}); if (!result.success) throw new Error(result.message); emit('saved',result.data) }
  catch(e) { error.value=e.message; preview.value=null }
  finally { busy.value=false }
}
</script>
<template>
  <Teleport to="body"><div class="pay-overlay" @click.self="close"><section v-dialog-focus="close" class="pay-detail pay-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-title" :aria-busy="busy">
    <header class="pay-detail-header"><div><small>SUPERADMIN · {{ rows.length }} selected allocations</small><h2 id="bulk-title">{{ title }}</h2></div><button class="pay-icon" aria-label="Close selected payment action" :disabled="busy" @click="close"><X :size="20"/></button></header>
    <div class="pay-detail-body">
      <template v-if="!preview">
        <p class="pay-detail-note" v-if="action === 'mark_paid'">Record money already collected using each payment’s saved offline method. Collection is dated today. Paid records and online payments are excluded.</p>
        <template v-else><p class="pay-detail-note">Move each selected amount to the destination below. The venue’s existing business stays the same. Amounts and payment statuses stay unchanged.</p><label class="pay-review-field">Allocate the selected amounts to<select v-model="destination" :disabled="busy"><option value="">Choose a destination</option><option value="platform_fee">Bookboll · platform fee</option><option value="venue_revenue">Existing venue / business · revenue</option></select></label><p class="pay-detail-note">Staff-added bookings remain entirely venue revenue. Reassignment updates reporting only; it does not transfer or refund money.</p></template>
        <label class="pay-review-field">Reason for this change<textarea v-model="reason" rows="3" maxlength="1500" :disabled="busy" placeholder="Explain the collection or the allocation correction."/><small>At least 10 characters. Saved with your name, time and each amount’s before-and-after details.</small></label>
      </template>
      <template v-else>
        <div class="pay-bulk-summary"><strong>{{ preview.payment_count }} payments · {{ preview.allocation_count }} allocations will change</strong><span v-if="action === 'mark_paid'">Collection date: {{ preview.collection_date }}</span><span>{{ preview.excluded.length }} allocations excluded</span></div>
        <p>{{ preview.reason }}</p>
        <section v-for="impact in preview.impact" :key="impact.currency" class="pay-bulk-impact"><h3>Change in totals · {{ impact.currency }}</h3><dl class="pay-review-impact"><div v-for="[key,label] in [['bookboll_received','Bookboll received'],['platform_fees','Platform fees'],['owned_venue_received','Own venues revenue'],['provider_received','External businesses received']]" :key="key"><dt>{{ label }}</dt><dd>{{ money(impact[key],impact.currency) }}</dd></div></dl></section>
        <div v-if="preview.changes.length" class="pay-bulk-table"><table><thead><tr><th>Payment / amount</th><th>Before</th><th>After</th></tr></thead><tbody><tr v-for="change in preview.changes" :key="change.row_id"><td><b>{{ change.before.payment_id || change.row_id }}</b><small>{{ money(change.before.amount,change.before.currency) }}</small></td><td>{{ labelFor(paymentCategories,change.before.category) }}<small>{{ change.before.recipient_label }} · {{ change.before.status }}</small></td><td>{{ labelFor(paymentCategories,change.after.category) }}<small>{{ change.after.recipient_label }} · {{ change.after.status }}</small></td></tr></tbody></table></div>
        <details v-if="preview.excluded.length" class="pay-bulk-excluded" :open="!preview.changes.length"><summary>{{ preview.excluded.length }} excluded · view reasons</summary><ul><li v-for="item in preview.excluded" :key="item.row_id"><b>{{ item.payment_id || item.row_id }}</b> — {{ item.reason }}</li></ul></details>
        <p class="pay-detail-note">Only the changes listed above will be saved. Records are checked again when you confirm.</p>
      </template>
      <p v-if="error" role="alert" class="pay-notice pay-error">{{ error }}</p>
    </div>
    <footer class="pay-detail-footer"><button class="btn" :disabled="busy" @click="preview ? preview=null : close()"><ArrowLeft v-if="preview" :size="15"/>{{ preview ? 'Edit action' : 'Cancel' }}</button><button v-if="!preview" class="btn primary" :disabled="!valid || busy" @click="check">{{ busy ? 'Checking payments…' : 'Preview changes' }}</button><button v-else class="btn primary" :disabled="!preview.changes.length || busy" @click="save"><Check :size="15"/>{{ busy ? 'Saving…' : 'Confirm changes' }}</button></footer>
  </section></div></Teleport>
</template>
