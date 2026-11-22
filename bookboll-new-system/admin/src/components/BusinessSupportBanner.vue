<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'
import { supportSession, returnToSuperadmin } from '../lib/businessSupport.js'
const session = supportSession(), busy = ref(false), error = ref(''), tick = ref(Date.now())
const minutes = computed(() => Math.max(0, Math.ceil((Date.parse(session?.expires_at) - tick.value) / 60000)))
let timer
async function leave() {
  busy.value = true; error.value = ''
  const res = await apiFetch('/business-support/exit', { method:'POST' })
  if (res.success || res.status === 401) returnToSuperadmin()
  else { error.value = 'Could not end the session. Please retry.'; busy.value = false }
}
function storageChanged(event) { if (event.key === 'bb_admin_token' || event.key === null) returnToSuperadmin('account-changed') }
onMounted(() => {
  if (!session) return
  timer = setInterval(() => { tick.value=Date.now(); if (session.invalid || minutes.value<=0) returnToSuperadmin('expired') },1000)
  window.addEventListener('storage',storageChanged)
})
onUnmounted(() => { clearInterval(timer); window.removeEventListener('storage',storageChanged) })
</script>
<template>
  <aside v-if="session" class="business-support-banner" aria-label="Business support session">
    <ShieldCheck :size="22" /><div><strong>{{ session.business_name || 'Business' }} <span>Support session · {{ minutes }} min left</span></strong><small>Routine edits enabled. Actions are logged as superadmin. Payments and account changes are restricted.</small><small v-if="error" role="alert">{{ error }}</small></div>
    <button class="btn btn-secondary" :disabled="busy" @click="leave"><ArrowLeft :size="16" />{{ busy?'Returning…':'Return to superadmin' }}</button>
  </aside>
</template>
<style scoped>
.business-support-banner{position:sticky;top:0;z-index:150;display:flex;gap:14px;align-items:center;padding:14px 24px;background:var(--bg-card,#172436);color:var(--text,#f1f5f9);border-bottom:2px solid var(--primary,#2563eb);box-shadow:0 3px 12px #0002}.business-support-banner>div{flex:1}.business-support-banner strong,.business-support-banner small{display:block}.business-support-banner strong span{font-size:12px;font-weight:500;color:var(--text-muted);margin-left:14px}.business-support-banner small{margin-top:5px;font-size:12px;color:var(--text-muted)}@media(max-width:700px){.business-support-banner{flex-wrap:wrap;padding:12px}.business-support-banner strong span{display:block;margin:4px 0}.business-support-banner button{margin-left:36px}}
</style>
