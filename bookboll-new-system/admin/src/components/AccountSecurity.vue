<script setup>
import { ref, onMounted } from 'vue'
import { X, KeyRound, Mail, LogOut, ShieldCheck } from 'lucide-vue-next'
import { managementRequest } from '../lib/managementApi.js'
import { pushToast } from '../lib/toast.js'
const props = defineProps({ account: { type: Object, required: true } })
const emit = defineEmits(['close', 'saved'])
const action = ref('reset_email'), reason = ref(''), password = ref(''), confirmation = ref('')
const busy = ref(false), loading = ref(true), error = ref(''), state = ref(null), acknowledged = ref(false)
async function load() {
  loading.value = true
  const r = await managementRequest(`/admin/users/${props.account.id}/security`)
  loading.value = false
  if (r.ok) state.value = r.data
  else error.value = r.message || 'Account details could not be loaded.'
}
onMounted(load)
function close() { if (!busy.value) emit('close') }
async function save() {
  error.value = ''
  if (!acknowledged.value || reason.value.trim().length < 5) { error.value = 'Enter a reason and confirm the selected action.'; return }
  if (action.value === 'password' && (password.value.length < 12 || !/[a-z]/i.test(password.value) || !/\d/.test(password.value) || password.value !== confirmation.value)) { error.value = 'Use at least 12 characters including a letter and number, and match both passwords.'; return }
  busy.value = true
  const r = await managementRequest(`/admin/users/${props.account.id}/security`, { method: 'POST', body: {
    action: action.value, reason: reason.value.trim(),
    ...(action.value === 'password' ? { password: password.value, password_confirmation: confirmation.value } : {}),
  } })
  busy.value = false
  password.value = ''; confirmation.value = ''
  if (!r.ok) { error.value = r.message || 'The action could not be completed.'; return }
  pushToast(r.message || 'Account updated.', 'success'); acknowledged.value = false; reason.value = ''; emit('saved'); await load()
}
function historyReason(item) { try { return JSON.parse(item.details)?.reason || '' } catch { return '' } }
const labels = { 'account.password': 'Password changed', 'account.reset_email': 'Reset email requested', 'account.revoke_sessions': 'Sessions signed out', 'account.access_updated': 'Access updated' }
</script>

<template>
  <div class="modal-backdrop" @click.self="close">
    <form class="modal management-dialog" v-dialog="close" @submit.prevent="save">
      <header class="modal-header"><div><h2>Account security</h2><p>{{ account.full_name || account.name }} · {{ account.email }}</p></div><button type="button" class="btn-icon" @click="close" aria-label="Close account security"><X :size="19" /></button></header>
      <div class="modal-body">
        <p v-if="loading">Loading account…</p>
        <template v-if="state">
          <div class="management-context"><ShieldCheck :size="19" /><span><strong>{{ state.active_sessions }} active sign-in tokens</strong><small>Password changes sign the account out on every device. Email resets sign it out when the reset is completed.</small></span></div>
          <fieldset class="management-choices"><legend>Choose an action</legend>
            <label><input v-model="action" type="radio" value="reset_email" @change="acknowledged=false" /><Mail :size="18" /><span><strong>Send password reset email</strong><small>The user chooses their own password with an email code.</small></span></label>
            <label><input v-model="action" type="radio" value="password" @change="acknowledged=false" /><KeyRound :size="18" /><span><strong>Set a new password</strong><small>Use this when the person needs help accessing their account.</small></span></label>
            <label><input v-model="action" type="radio" value="revoke_sessions" @change="acknowledged=false" /><LogOut :size="18" /><span><strong>Sign out all devices</strong><small>Keep the password and require a fresh sign-in.</small></span></label>
          </fieldset>
          <div v-if="action==='password'" class="management-grid">
            <label>New password<input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required /><small>12+ characters, including letters and numbers.</small></label>
            <label>Confirm password<input v-model="confirmation" type="password" autocomplete="new-password" required /></label>
          </div>
          <label class="management-field">Reason for this action<textarea v-model="reason" minlength="5" maxlength="500" required rows="2" placeholder="For example: account holder requested help signing in" /></label>
          <label class="management-confirm"><input v-model="acknowledged" type="checkbox" /><span>I have checked the account and want to {{ action==='reset_email' ? 'send a reset email' : action==='password' ? 'change its password and sign out all devices' : 'sign out all devices' }}.</span></label>
          <details class="management-history"><summary>Recent account changes</summary><p v-if="!state.history?.length">No recorded management actions yet.</p><article v-for="item in state.history" :key="item.id"><strong>{{ labels[item.action] || item.action }}</strong><small>{{ item.actor_email }} · {{ new Date(item.timestamp).toLocaleString() }}</small><span>{{ historyReason(item) }}</span></article></details>
        </template>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      </div>
      <footer class="modal-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Close</button><button class="btn btn-primary" :disabled="busy || !state || !acknowledged">{{ busy ? 'Applying…' : action==='reset_email' ? 'Send reset email' : action==='password' ? 'Change password' : 'Sign out all devices' }}</button></footer>
    </form>
  </div>
</template>
