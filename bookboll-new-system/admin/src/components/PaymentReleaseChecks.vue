<script setup>
import { computed, onMounted, ref } from 'vue'
import { ShieldCheck, LockKeyhole, RefreshCw, Check, CircleAlert } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'

const status = ref(null), loading = ref(false), error = ref('')
const blocked = computed(() => status.value?.checks?.filter(c => !c.passed) || [])
async function load() {
  loading.value = true; error.value = ''
  try {
    const result = await apiFetch('/admin/payments/release-checks')
    if (!result.success || !Array.isArray(result.data?.checks)) throw new Error('Could not verify release checks.')
    status.value = result.data
  } catch (e) { error.value = 'Release checks could not be verified. Live payment actions stay locked until the server verifies them.' }
  finally { loading.value = false }
}
onMounted(load)
</script>

<template>
  <section class="pay-release" aria-label="Payment release checks">
    <div class="pay-release-heading">
      <ShieldCheck v-if="status?.refunds_ready" :size="22" /><LockKeyhole v-else :size="22" />
      <div><h2>{{ status?.mode === 'workspace' ? 'Test workspace · live payments locked' : status?.production_ready ? 'Payment release verified' : 'Production launch checks required' }}</h2>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-else-if="loading && !status">Checking the deployed code and database…</p>
        <p v-else-if="status?.mode === 'workspace'">You can continue testing here. Production needs verified migration and payment evidence before money can move.</p>
        <p v-else-if="!status?.production_ready">This environment is read-only until the release owner verifies the historical data and payment rules.</p>
        <p v-else>{{ status?.refunds_ready ? 'Release evidence and refund prerequisites match this deployment.' : 'Payments are verified. Online refunds remain locked until their separate checks pass.' }}</p>
      </div>
      <button class="btn" :disabled="loading" @click="load" aria-label="Refresh payment release checks"><RefreshCw :size="15" /> Recheck</button>
    </div>
    <details v-if="status" class="pay-release-details">
      <summary>{{ blocked.length ? `${blocked.length} checks required before live use` : 'View verified release checks' }}</summary>
      <p class="pay-release-note">These are enforced server checks. They cannot be dismissed or marked complete from the dashboard.</p>
      <ul><li v-for="check in status.checks" :key="check.id" :class="{ passed:check.passed }">
        <Check v-if="check.passed" :size="16" /><CircleAlert v-else :size="16" />
        <div><strong>{{ check.label }} <small v-if="check.scope === 'refunds'">Refunds</small></strong><p>{{ check.detail }}</p></div>
        <span>{{ check.passed ? 'Passed' : 'Required' }}</span>
      </li></ul>
    </details>
  </section>
</template>

<style scoped>
.pay-release{border:1px solid var(--border);border-radius:12px;background:var(--card);padding:18px 20px;margin-bottom:20px}
.pay-release-heading{display:flex;align-items:flex-start;gap:12px}.pay-release-heading>svg{color:var(--primary);flex-shrink:0;margin-top:2px}
.pay-release-heading>div{flex:1;min-width:0}.pay-release h2{font-size:14px;margin:0 0 5px}.pay-release p{font-size:12px;line-height:1.6;color:var(--muted);margin:0}
.pay-release-heading .btn{white-space:nowrap}.pay-release-details{border-top:1px solid var(--border);margin-top:14px;padding-top:12px}
.pay-release summary{font-size:12px;font-weight:600;cursor:pointer}.pay-release .pay-release-note{margin:12px 0}
.pay-release ul{padding:0;list-style:none;margin:0;display:grid;grid-template-columns:1fr 1fr;gap:0 24px}
.pay-release li{display:flex;gap:8px;padding:12px 0;border-top:1px solid var(--border);font-size:12px}.pay-release li>svg{flex-shrink:0;margin-top:2px;color:var(--muted)}
.pay-release li.passed>svg{color:var(--primary)}.pay-release li>div{flex:1}.pay-release li>span{font-size:10px;color:var(--muted)}.pay-release small{font-size:10px;font-weight:400;margin-left:5px;color:var(--muted)}
@media(max-width:700px){.pay-release ul{grid-template-columns:1fr}.pay-release-heading{flex-wrap:wrap}.pay-release-heading .btn{margin-left:34px}}
</style>
