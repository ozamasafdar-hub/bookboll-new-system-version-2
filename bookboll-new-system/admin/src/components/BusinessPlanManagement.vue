<script setup>
import { ref, onMounted } from 'vue'
import { X, Crown } from 'lucide-vue-next'
import { managementRequest } from '../lib/managementApi.js'
import { pushToast } from '../lib/toast.js'
const props = defineProps({ business: { type: Object, required: true } })
const emit = defineEmits(['close', 'saved'])
const state = ref(null), plan = ref('premium'), reason = ref(''), busy = ref(false), error = ref(''), acknowledged = ref(false)
async function load() {
  const r = await managementRequest(`/admin/businesses/${props.business.business_id}/plan-management`)
  if (r.ok) { state.value = r.data; plan.value = r.data.plan === 'premium' ? 'free' : 'premium' }
  else error.value = r.message || 'Could not load the business plan.'
}
onMounted(load)
function close() { if (!busy.value) emit('close') }
async function save() {
  error.value = ''
  if (!acknowledged.value || reason.value.trim().length < 5) { error.value = 'Enter a reason and confirm the plan change.'; return }
  busy.value = true
  const r = await managementRequest(`/admin/businesses/${props.business.business_id}/plan-management`, { method: 'PUT', body: { plan: plan.value, reason: reason.value.trim(), expected_plan: state.value.plan, expected_override: state.value.override } })
  busy.value = false
  if (!r.ok) { error.value = r.message || 'Could not change this plan. Refresh and try again.'; return }
  pushToast(r.message || 'Plan updated.', 'success'); emit('saved'); emit('close')
}
function entry(item) { try { const d=JSON.parse(item.details); return `${d.before?.plan || 'free'} → ${d.after?.plan} · ${d.reason}` } catch { return '' } }
</script>
<template>
  <div class="modal-backdrop" @click.self="close"><form class="modal management-dialog" v-dialog="close" @submit.prevent="save">
    <header class="modal-header"><div><h2>Manage business plan</h2><p>{{ business.business_name || business.full_name }}</p></div><button type="button" class="btn-icon" @click="close" aria-label="Close plan management"><X :size="19" /></button></header>
    <div class="modal-body">
      <template v-if="state">
        <div class="management-context"><Crown :size="21" /><span><strong>Current plan: {{ state.plan==='premium' ? 'Premium' : 'Free' }}</strong><small>{{ state.override ? 'Managed by superadmin' : 'Managed by billing' }}</small></span></div>
        <p v-if="state.billing_conflict" class="workspace-note">This business has a Stripe subscription or unfinished checkout. Resolve that billing first to avoid ongoing charges. No subscription will be cancelled here.</p>
        <fieldset class="management-choices"><legend>Choose the plan</legend>
          <label><input v-model="plan" type="radio" value="premium" :disabled="state.billing_conflict" @change="acknowledged=false" /><span><strong>Complimentary Premium</strong><small>Premium features and the configured Premium website booking fee. No payment or expiry.</small></span></label>
          <label><input v-model="plan" type="radio" value="free" :disabled="state.billing_conflict" @change="acknowledged=false" /><span><strong>Free</strong><small>Free features and website fee. Premium website features become unavailable; saved content stays.</small></span></label>
          <label v-if="state.override"><input v-model="plan" type="radio" value="billing" @change="acknowledged=false" /><span><strong>Return to normal billing</strong><small>Remove the manual setting. Without an active subscription the business becomes Free and can purchase Premium itself.</small></span></label>
        </fieldset>
        <p class="management-note">Marketplace membership listing subscriptions stay separate on every plan.</p>
        <label class="management-field">Reason for the change<textarea v-model="reason" rows="2" minlength="5" maxlength="500" required placeholder="For example: complimentary access agreed with the business" /></label>
        <label class="management-confirm"><input v-model="acknowledged" type="checkbox" /><span>Apply this plan to the business and all its team members. No payment will be taken.</span></label>
        <details class="management-history"><summary>Recent plan changes</summary><p v-if="!state.history?.length">No recorded plan changes yet.</p><article v-for="item in state.history" :key="item.id"><strong>{{ entry(item) }}</strong><small>{{ item.actor_email }} · {{ new Date(item.timestamp).toLocaleString() }}</small></article></details>
      </template><p v-else-if="!error">Loading plan…</p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    </div>
    <footer class="modal-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button><button class="btn btn-primary" :disabled="busy || !state || !acknowledged || (state.billing_conflict && plan!=='billing')">{{ busy ? 'Saving…' : 'Apply plan' }}</button></footer>
  </form></div>
</template>
