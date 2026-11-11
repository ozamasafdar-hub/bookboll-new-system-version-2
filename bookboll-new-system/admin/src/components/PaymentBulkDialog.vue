<script setup>
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { X, ArrowLeft, Check } from 'lucide-vue-next'
import { previewPaymentBulk, savePaymentBulk } from '../lib/payments.js'
import { collectionMethods, collectionMethodLabel, paymentBatches, combineBulkPreviews } from '../lib/paymentBulk.js'
import { money, labelFor, paymentCategories } from '../lib/paymentReport.js'
import { dialogFocus as vDialogFocus } from '../lib/dialogFocus.js'

const props=defineProps({ rows:Array, action:String })
const emit=defineEmits(['close','saved'])
const destination=ref(''),method=ref(''),reason=ref(''),busy=ref(false),error=ref(''),preview=shallowRef(null)
const plans=shallowRef([]), progress=ref(''),started=ref(false),completed=ref(0),audits=ref([]),previewPage=ref(1),excludedPage=ref(1)
const title=computed(() => props.action === 'mark_paid' ? 'Mark pending payments as paid' : 'Change allocation / recipient')
const valid=computed(() => reason.value.trim().length >= 10 && (props.action === 'mark_paid' ? method.value : destination.value))
const shownChanges=computed(() => preview.value?.changes.slice((previewPage.value-1)*100,previewPage.value*100) || [])
const shownExcluded=computed(() => preview.value?.excluded.slice((excludedPage.value-1)*100,excludedPage.value*100) || [])
function close() {
  if (busy.value) return
  if (started.value) emit('saved',{partial:true,audit_ids:audits.value})
  else emit('close')
}
function back() { if (started.value || !preview.value) close(); else { preview.value=null; plans.value=[] } }
async function check() {
  if (!valid.value || busy.value) return
  busy.value=true; error.value=''; plans.value=[]; previewPage.value=1; excludedPage.value=1
  try {
    const batches=paymentBatches(props.rows), next=[]
    const choices={action:props.action,reason:reason.value.trim(),...(props.action === 'mark_paid' ? {method:method.value} : {destination:destination.value})}
    for (const [index,ids] of batches.entries()) {
      progress.value=`Checking batch ${index+1} of ${batches.length}…`
      const input={...choices,row_ids:ids}, result=await previewPaymentBulk(input)
      if (!result.success) throw new Error(result.message)
      next.push({input,data:result.data})
    }
    plans.value=next; preview.value=combineBulkPreviews(next)
  } catch(e) { error.value=e.message; plans.value=[] }
  finally { busy.value=false; progress.value='' }
}
async function save() {
  if (!preview.value?.changes.length || busy.value) return
  busy.value=true; started.value=true; error.value=''
  try {
    const eligible=plans.value.filter(plan => plan.data.changes.length)
    while (completed.value < eligible.length) {
      const plan=eligible[completed.value]
      progress.value=`Saving batch ${completed.value+1} of ${eligible.length} · ${completed.value} completed`
      const result=await savePaymentBulk({...plan.input,fingerprint:plan.data.fingerprint})
      if (!result.success) throw new Error(result.message)
      audits.value.push(result.data.audit_id); completed.value++
    }
    emit('saved',{audit_ids:audits.value})
  } catch(e) { error.value=`${completed.value} batches confirmed saved. ${e.message} Retry uses the same checks and cannot collect the same payment twice. You can also close and review the latest records.` }
  finally { busy.value=false; progress.value='' }
}
function leaving(event) { if (busy.value) { event.preventDefault(); event.returnValue='' } }
window.addEventListener('beforeunload',leaving)
onBeforeUnmount(() => window.removeEventListener('beforeunload',leaving))
onBeforeRouteLeave(() => !busy.value)
</script>
<template>
  <Teleport to="body"><div class="pay-overlay" @click.self="close"><section v-dialog-focus="close" class="pay-detail pay-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-title" :aria-busy="busy">
    <header class="pay-detail-header"><div><small>SUPERADMIN · {{ rows.length.toLocaleString() }} selected allocations</small><h2 id="bulk-title">{{ title }}</h2></div><button class="pay-icon" aria-label="Close selected payment action" :disabled="busy" @click="close"><X :size="20"/></button></header>
    <div class="pay-detail-body">
      <template v-if="!preview">
        <template v-if="action === 'mark_paid'"><p class="pay-detail-note">Record money already collected. Choose how you received it. This method applies to every eligible payment in your selection.</p><fieldset class="pay-bulk-methods" :disabled="busy"><legend>Payment method <span aria-hidden="true">*</span></legend><label v-for="option in collectionMethods" :key="option.value" :class="{active:method === option.value}"><input v-model="method" type="radio" name="bulk-collection-method" :value="option.value"/>{{ option.label }}</label></fieldset><p class="pay-detail-note">Collection is dated today. Paid records and online payments are excluded. For mixed methods, select and record each method separately.</p></template>
        <template v-else><p class="pay-detail-note">Move each selected amount to the destination below. The venue’s existing business stays the same. Amounts and payment statuses stay unchanged.</p><label class="pay-review-field">Allocate the selected amounts to<select v-model="destination" :disabled="busy"><option value="">Choose a destination</option><option value="platform_fee">Bookboll · platform fee</option><option value="venue_revenue">Existing venue / business · revenue</option></select></label><p class="pay-detail-note">Staff-added bookings remain entirely venue revenue. Reassignment updates reporting only; it does not transfer or refund money.</p></template>
        <label class="pay-review-field">Reason for this change<textarea v-model="reason" rows="3" maxlength="1500" :disabled="busy" placeholder="Explain the collection or the allocation correction."/><small>At least 10 characters. Saved with your name, time and each amount’s before-and-after details.</small></label>
      </template>
      <template v-else>
        <div class="pay-bulk-summary"><strong>{{ preview.allocation_count.toLocaleString() }} {{ preview.allocation_count === 1 ? 'allocation' : 'allocations' }} will change</strong><span v-if="action === 'mark_paid'">{{ collectionMethodLabel(preview.collection_method) }} · Collection date: {{ preview.collection_date }}</span><span>{{ preview.excluded.length.toLocaleString() }} {{ preview.excluded.length === 1 ? 'allocation' : 'allocations' }} excluded</span></div>
        <p>{{ preview.reason }}</p>
        <section v-for="impact in preview.impact" :key="impact.currency" class="pay-bulk-impact"><h3>Change in totals · {{ impact.currency }}</h3><dl class="pay-review-impact"><div v-for="[key,label] in [['bookboll_received','Bookboll received'],['platform_fees','Platform fees'],['owned_venue_received','Own venues revenue'],['provider_received','External businesses received']]" :key="key"><dt>{{ label }}</dt><dd>{{ money(impact[key],impact.currency) }}</dd></div></dl></section>
        <div v-if="preview.changes.length" class="pay-bulk-table"><table><thead><tr><th>Allocation / amount</th><th>Before</th><th>After</th></tr></thead><tbody><tr v-for="change in shownChanges" :key="change.row_id"><td><b>{{ change.before.payment_id || change.row_id }}</b><small>{{ money(change.before.amount,change.before.currency) }}</small></td><td>{{ labelFor(paymentCategories,change.before.category) }}<small>{{ change.before.recipient_label }} · {{ change.before.status }}</small><small v-if="action === 'mark_paid'">{{ collectionMethodLabel(change.before.method) }}</small></td><td>{{ labelFor(paymentCategories,change.after.category) }}<small>{{ change.after.recipient_label }} · {{ change.after.status }}</small><small v-if="action === 'mark_paid'">{{ collectionMethodLabel(change.after.method) }}</small></td></tr></tbody></table></div>
        <div v-if="preview.changes.length > 100" class="pay-actions"><button class="btn" :disabled="previewPage <= 1" @click="previewPage--">Previous changes</button><span>{{ previewPage }} / {{ Math.ceil(preview.changes.length/100) }}</span><button class="btn" :disabled="previewPage*100 >= preview.changes.length" @click="previewPage++">Next changes</button></div>
        <details v-if="preview.excluded.length" class="pay-bulk-excluded" :open="!preview.changes.length"><summary>{{ preview.excluded.length.toLocaleString() }} excluded · view reasons</summary><ul><li v-for="item in shownExcluded" :key="item.row_id"><b>{{ item.payment_id || item.row_id }}</b> — {{ item.reason }}</li></ul><div v-if="preview.excluded.length > 100" class="pay-actions"><button class="btn" :disabled="excludedPage <= 1" @click="excludedPage--">Previous exclusions</button><span>{{ excludedPage }} / {{ Math.ceil(preview.excluded.length/100) }}</span><button class="btn" :disabled="excludedPage*100 >= preview.excluded.length" @click="excludedPage++">Next exclusions</button></div></details>
        <p class="pay-detail-note">Only the previewed changes will be saved. Records are checked again when you confirm.</p><p v-if="plans.length > 1" class="pay-detail-note">This selection is processed in {{ plans.length }} batches. Keep this page open until it finishes. If a batch fails, earlier completed batches stay saved and the remaining work stops for review.</p>
      </template>
      <p v-if="progress" role="status" aria-live="polite" class="pay-notice">{{ progress }}</p>
      <p v-if="error" role="alert" class="pay-notice pay-error">{{ error }}</p>
    </div>
    <footer class="pay-detail-footer"><button class="btn" :disabled="busy" @click="back"><ArrowLeft v-if="preview && !started" :size="15"/>{{ started ? 'Close' : preview ? 'Edit action' : 'Cancel' }}</button><button v-if="!preview" class="btn primary" :disabled="!valid || busy" @click="check">{{ busy ? 'Checking payments…' : 'Preview changes' }}</button><button v-else class="btn primary" :disabled="!preview.changes.length || busy" @click="save"><Check :size="15"/>{{ busy ? 'Saving…' : started ? 'Retry remaining batches' : 'Confirm changes' }}</button></footer>
  </section></div></Teleport>
</template>
