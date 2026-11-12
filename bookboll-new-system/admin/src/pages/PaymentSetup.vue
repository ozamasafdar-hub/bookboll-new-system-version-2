<script setup>
import { computed, onMounted, ref } from 'vue'
import { ArrowRight, Check, CreditCard, ExternalLink, RefreshCw, ShieldCheck } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useRoute } from 'vue-router'
const { isProviderScoped } = useCurrentUser()
const route = useRoute()
const data = ref(null), error = ref(''), loading = ref(true), busy = ref(''), search = ref('')
const businesses = computed(() => (data.value?.businesses || []).filter(b => `${b.name} ${b.username}`.toLowerCase().includes(search.value.toLowerCase())))
async function load() {
  loading.value = true; error.value = ''
  const r = await apiFetch('/admin/payment-connections')
  if (r.success) data.value = r.data
  else error.value = r.message
  loading.value = false
}
async function action(b, verb) {
  busy.value = `${b.id}:${verb}`; error.value = ''
  const r = await apiFetch(`/admin/payment-connections/${b.id}/${verb}`, { method: 'POST' })
  if (!r.success) error.value = r.message
  else if (r.data?.url) window.location.assign(r.data.url)
  else await load()
  busy.value = ''
}
function status(b) { return b.ready ? 'Connected · full online ready' : b.connection?.status === 'disconnected' ? 'Disconnected' : b.connection ? 'Stripe setup incomplete' : 'Not connected' }
onMounted(load)
</script>

<template>
  <div class="page payment-setup">
    <header class="page-header"><div><h1>Payment setup</h1><p class="muted">{{ isProviderScoped ? 'Choose how customers pay your business.' : 'Supervise provider Stripe connections and online payment readiness.' }}</p></div><button class="btn" :disabled="loading" @click="load"><RefreshCw :size="15" /> Refresh</button></header>
    <p v-if="error" class="ps-notice error" role="alert">{{ error }}</p>
    <p v-if="loading && !data" role="status">Loading payment setup…</p>
    <template v-if="data">
      <p v-if="data.preview" class="ps-notice"><ShieldCheck :size="18" /> Test workspace: connecting real Stripe accounts and taking payments are disabled. Connection status is shown for review.</p>
      <p v-else-if="!data.configured" class="ps-notice">Stripe Connect configuration is still needed from Bookboll. You can continue using deposit + payment on arrival.</p>
      <p v-if="route.query.stripe === 'connected'" class="ps-notice">Your Stripe account was connected. Check its readiness below; Stripe may still require business details.</p>
      <p v-if="route.query.stripe === 'cancelled'" class="ps-notice">Connection cancelled. You can start again whenever you are ready.</p>
      <section class="ps-options">
        <article class="ps-option"><span class="ps-eyebrow">DEFAULT · NO GATEWAY NEEDED</span><h2>Deposit + on arrival</h2><p>The customer reserves online and pays you the balance at the venue.</p><div class="ps-flow"><span><strong>Online deposit</strong><small>Bookboll’s platform fee</small></span><ArrowRight :size="18" /><span><strong>Remaining balance</strong><small>You collect at the venue</small></span></div><footer><Check :size="16" /> No Stripe account needed</footer></article>
        <article class="ps-option"><span class="ps-eyebrow">OPTIONAL · CONNECT YOUR STRIPE</span><h2>Full payment online</h2><p>Stripe splits each booking payment using its recorded fee and recipient.</p><div class="ps-flow"><span><strong>Platform fee</strong><small>To Bookboll</small></span><ArrowRight :size="18" /><span><strong>Your remaining share</strong><small>To your Stripe balance</small></span></div><footer><CreditCard :size="16" /> Stripe manages payouts to your bank</footer></article>
      </section>
      <section class="ps-panel">
        <div class="ps-heading"><div><h2>{{ isProviderScoped ? 'Your Stripe connection' : 'Business connections' }}</h2><p class="muted">Only the business owner can connect its account. No API keys or bank details are entered here.</p></div><input v-if="!isProviderScoped" v-model="search" type="search" placeholder="Search businesses…" aria-label="Search businesses" /></div>
        <p v-if="!businesses.length" class="ps-empty">{{ search ? 'No matching businesses.' : 'No business is available for this account.' }}</p>
        <article v-for="b in businesses" :key="b.id" class="ps-business">
          <div><strong>{{ b.name }}</strong><small>@{{ b.username }}</small><code v-if="b.connection">{{ b.connection.stripe_account_id }}</code></div>
          <div class="ps-state"><span :class="['ps-status', { ready: b.ready }]">{{ status(b) }}</span><small v-if="b.connection?.verified_at">Checked {{ new Date(b.connection.verified_at).toLocaleString() }}</small><small v-if="b.connection && !b.ready">Complete any outstanding requirements in Stripe, then refresh status.</small></div>
          <div class="ps-actions" v-if="b.can_connect">
            <template v-if="b.connection && b.connection.status !== 'disconnected'"><a class="btn" href="https://dashboard.stripe.com/" target="_blank" rel="noopener">Open Stripe <ExternalLink :size="14" /></a><button class="btn" :disabled="!!busy || data.preview" @click="action(b, 'refresh')">Check status</button></template>
            <button v-else class="btn btn-primary" :disabled="!!busy || data.preview || !data.configured" @click="action(b, 'connect')">Connect Stripe <ArrowRight :size="15" /></button>
          </div>
          <small v-else class="muted">{{ isProviderScoped ? 'Ask your business owner to connect Stripe.' : 'Managed by the business owner' }}</small>
        </article>
      </section>
      <div class="ps-next"><p>Once connected, enable full online payment in each listing’s payment settings. Deposit + on arrival remains available.</p><router-link class="btn" to="/listings">Manage listings <ArrowRight :size="15" /></router-link><router-link to="/payments">View payment records</router-link></div>
      <p class="muted ps-footnote">Provider bookings entered manually by staff keep their recorded venue allocation. Bookboll-operated venues use Bookboll’s payment account and their own internal fee split. Refunds are managed by authorized Bookboll platform admins.</p>
    </template>
  </div>
</template>

<style scoped>
.payment-setup{max-width:1440px}.ps-notice{display:flex;align-items:center;gap:12px;border:1px solid var(--border);background:var(--surface);padding:16px;border-radius:12px;line-height:1.5;margin:18px 0}.ps-notice svg{flex-shrink:0}.ps-notice.error{color:var(--danger,#ef4444)}.ps-options{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:24px 0}.ps-option,.ps-panel{border:1px solid var(--border);background:var(--surface);border-radius:14px}.ps-option{padding:24px}.ps-eyebrow{color:var(--primary);font-size:11px;letter-spacing:.06em;font-weight:750}.ps-option h2{margin:12px 0;font-size:22px}.ps-option p,.ps-heading p{color:var(--muted);line-height:1.55;font-size:13px}.ps-flow{display:flex;align-items:center;gap:16px;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:22px 0;margin:20px 0}.ps-flow>span{flex:1}.ps-flow strong,.ps-flow small{display:block}.ps-flow small{margin-top:8px;color:var(--muted);font-size:12px}.ps-option footer{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted)}.ps-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px}.ps-heading h2{font-size:18px;margin:0 0 7px}.ps-heading p{margin:0}.ps-heading input{max-width:280px}.ps-business{border-top:1px solid var(--border);padding:22px;display:grid;grid-template-columns:minmax(180px,1fr) minmax(200px,1fr) auto;align-items:center;gap:20px}.ps-business small,.ps-business code{display:block;color:var(--muted);margin-top:7px;font-size:12px;overflow-wrap:anywhere}.ps-status{display:inline-flex;border-radius:6px;padding:7px 9px;background:var(--bg);border:1px solid var(--border);font-size:12px}.ps-status.ready{color:var(--positive-ink,#0d9488);background:var(--positive-bg)}.ps-actions{display:flex;gap:8px;flex-wrap:wrap}.ps-next{display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin:24px 0}.ps-next p{flex:1;min-width:260px;line-height:1.6;font-size:13px}.ps-footnote{font-size:12px;line-height:1.6}.ps-empty{padding:24px;color:var(--muted)}@media(max-width:850px){.ps-options{grid-template-columns:1fr}.ps-business{grid-template-columns:1fr}.ps-heading{align-items:stretch;flex-direction:column}.ps-heading input{max-width:none}}
</style>
