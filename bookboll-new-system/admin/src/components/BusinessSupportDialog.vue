<script setup>
import { ref } from 'vue'
import { X, LogIn, ShieldCheck } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'
import { saveSupportSession } from '../lib/businessSupport.js'
defineProps({ business: Object })
const emit = defineEmits(['close'])
const busy = ref(false), error = ref('')
async function start(business) {
  busy.value = true; error.value = ''
  const res = await apiFetch(`/admin/businesses/${business.business_id}/support-session`, { method:'POST', timeout:20000 })
  if (!res.success) { error.value = res.message; busy.value = false; return }
  try {
    saveSupportSession(res.data)
    window.location.assign('/admin')
  } catch {
    await apiFetch('/business-support/exit', { method:'POST', token:res.data.token, timeout:20000 })
    error.value = 'Allow session storage in this browser before starting support.'; busy.value = false
  }
}
</script>
<template>
  <div class="modal-backdrop" @click.self="!busy && emit('close')" @keydown.esc="!busy && emit('close')">
    <section class="modal support-dialog" role="dialog" aria-modal="true" aria-labelledby="support-title">
      <header class="modal-header"><h2 id="support-title">Log in as business</h2><button class="btn btn-ghost" aria-label="Close" :disabled="busy" @click="emit('close')"><X :size="18" /></button></header>
      <div class="modal-body">
        <div class="support-destination"><ShieldCheck :size="24" /><div><strong>{{ business.business_name || business.name }}</strong><span>Business dashboard · 30-minute session</span></div></div>
        <p>View the business workspace and help with bookings, listings and website settings.</p>
        <ul><li>Changes save to this business and are logged under your superadmin account.</li><li>Payments, cancellations, team access, passwords and integrations stay in your normal superadmin controls.</li><li>Use <strong>Return to superadmin</strong> at any time. Other open tabs stay signed in as you.</li></ul>
        <p v-if="error" role="alert" class="support-error">{{ error }}</p>
      </div>
      <footer class="modal-footer"><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button><button class="btn btn-primary" :disabled="busy" @click="start(business)"><LogIn :size="16" />{{ busy?'Opening business…':'Open business dashboard' }}</button></footer>
    </section>
  </div>
</template>
<style scoped>
.support-dialog{width:min(600px,calc(100vw - 32px));max-width:600px}.support-eyebrow{font-size:11px;letter-spacing:.08em;color:var(--text-muted)}.support-destination{display:flex;gap:14px;align-items:center;background:var(--bg-soft);border:1px solid var(--border);border-radius:10px;padding:18px;margin-bottom:20px}.support-destination strong,.support-destination span{display:block}.support-destination span{font-size:13px;color:var(--text-muted);margin-top:4px}.support-dialog p,.support-dialog li{line-height:1.6}.support-dialog ul{padding-left:20px;color:var(--text-muted)}.support-dialog li+li{margin-top:10px}.support-error{color:var(--danger,#ef4444)}
</style>
