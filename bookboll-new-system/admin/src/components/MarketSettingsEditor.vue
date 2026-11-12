<script setup>
import { ref, onMounted } from 'vue'
import { X, ArrowRight } from 'lucide-vue-next'
import { loadMarketSettings, saveMarketSettings } from '../lib/geography.js'

const props = defineProps({ scope: String, scopeId: Number, name: String })
const emit = defineEmits(['close', 'saved'])
const config = ref(null), values = ref({}), busy = ref(false), error = ref('')
const fees = [{ key: 'facility', label: 'Venue bookings' }, { key: 'program', label: 'Memberships' }, { key: 'event', label: 'Event seats' }]
const websiteFees = [{ key: 'site_free', label: 'Free plan', fallback: 'Match marketplace rate' }, { key: 'site_premium', label: 'Paid Premium plan', fallback: 'Half the marketplace rate' }]
const modules = [{ key: 'module_listings', label: 'New booking listings' }, { key: 'module_classes', label: 'New membership programs' }, { key: 'module_matchmaking', label: 'New events & tournaments' }]
const parentName = props.scope === 'city' ? 'country' : 'global'
onMounted(async () => {
  try {
    const res = await loadMarketSettings(props.scope, props.scopeId)
    if (!res.success) throw new Error(res.message || 'Could not load settings.')
    config.value = res.data.settings
    values.value = Object.fromEntries([...fees, ...websiteFees, ...modules].map(({ key }) => [key, config.value.overrides[key] ?? null]))
  } catch (e) { error.value = e.message }
})
function inheritAll() { values.value = Object.fromEntries(Object.keys(values.value).map(k => [k, null])) }
function websiteDefault(fee) {
  const rate = config.value.parent[fee.key]
  return rate == null ? fee.fallback : `${rate}%`
}
function websiteExample(fee) {
  const inherited = props.scope === 'global' ? null : config.value.parent[fee.key]
  return values.value[fee.key] ?? inherited ?? Number(values.value.facility ?? config.value.parent.facility) * (fee.key === 'site_premium' ? .5 : 1)
}
async function save() {
  if (busy.value) return
  error.value = ''
  for (const { key, label } of [...fees, ...websiteFees]) {
    const n = values.value[key]
    if (n !== null && (n === '' || !Number.isFinite(Number(n)) || Number(n) < 0 || Number(n) > 100)) {
      error.value = `${label}: enter a percentage from 0 to 100.`; return
    }
  }
  busy.value = true
  try {
    const res = await saveMarketSettings(props.scope, props.scopeId, values.value)
    if (!res.success) throw new Error(res.message || 'Could not save settings.')
    emit('saved', res.data.settings)
  } catch (e) { error.value = e.message }
  finally { busy.value = false }
}
</script>

<template>
  <Teleport to="body">
    <div class="market-backdrop" @click.self="!busy && emit('close')">
      <section class="market-dialog" v-dialog role="dialog" aria-modal="true" aria-labelledby="market-settings-title">
        <header><div><h2 id="market-settings-title">{{ scope === 'global' ? 'Global defaults' : name + ' settings' }}</h2><p>{{ scope === 'global' ? 'Used wherever a country or city has no override.' : 'Choose an override only where this market needs different settings.' }}</p></div><button class="btn btn-secondary btn-icon" :disabled="busy" aria-label="Close settings" @click="emit('close')"><X :size="18" /></button></header>
        <form v-if="config" @submit.prevent="save">
          <div class="market-body">
            <div class="inherit-path"><span :class="{ current: scope === 'global' }">Global</span><ArrowRight :size="14"/><span :class="{ current: scope === 'country' }">Country</span><ArrowRight :size="14"/><span :class="{ current: scope === 'city' }">City</span></div>
            <div class="market-section-title"><h3>Marketplace fees</h3><button v-if="scope !== 'global'" type="button" class="btn btn-secondary btn-sm" @click="inheritAll">Use {{ parentName }} defaults for all</button></div>
            <p class="market-help">The same marketplace rates apply on Free and Premium. Listing and existing membership agreements keep their explicit rates.</p>
            <div v-for="fee in fees" :key="fee.key" class="market-setting-row">
              <label :for="'fee-' + fee.key">{{ fee.label }}<small v-if="scope !== 'global'">{{ parentName }} default: {{ config.parent[fee.key] }}%</small></label>
              <select v-if="scope !== 'global'" :aria-label="fee.label + ' setting source'" :value="values[fee.key] === null ? 'inherit' : 'custom'" @change="values[fee.key] = $event.target.value === 'inherit' ? null : config.effective[fee.key]"><option value="inherit">Use {{ parentName }} default</option><option value="custom">Custom rate</option></select>
              <div class="market-percent"><input :id="'fee-' + fee.key" type="number" min="0" max="100" step="0.01" :disabled="values[fee.key] === null" :value="values[fee.key] ?? config.parent[fee.key]" @input="values[fee.key] = $event.target.value === '' ? '' : Number($event.target.value)" required /><span>%</span></div>
            </div>
            <h3 class="services-heading">Provider website fees</h3>
            <p class="market-help">Venue bookings made on a business’s own website. Set a separate rate for each plan, for deposits and full online payments.</p>
            <div v-for="fee in websiteFees" :key="fee.key" class="market-setting-row">
              <label :for="'fee-' + fee.key">{{ fee.label }}<small>{{ scope === 'global' ? 'Automatic: ' + fee.fallback : parentName + ' default: ' + websiteDefault(fee) }}</small></label>
              <select :aria-label="fee.label + ' setting source'" :value="values[fee.key] === null ? 'inherit' : 'custom'" @change="values[fee.key] = $event.target.value === 'inherit' ? null : websiteExample(fee)"><option value="inherit">{{ scope === 'global' ? 'Automatic rate' : 'Use ' + parentName + ' default' }}</option><option value="custom">Custom rate</option></select>
              <div class="market-percent"><input :id="'fee-' + fee.key" type="number" min="0" max="100" step="0.01" :disabled="values[fee.key] === null" :value="websiteExample(fee)" @input="values[fee.key] = $event.target.value === '' ? '' : Number($event.target.value)" required /><span>%</span></div>
            </div>
            <p class="market-help">Premium applies during an active paid subscription. Automatic rates follow each listing’s marketplace rate; custom website rates replace that calculation.</p>
            <h3 class="services-heading">Services businesses can add</h3>
            <p class="market-help">Turning a service off stops new listings, programs or events. Existing published content stays available.</p>
            <div v-for="mod in modules" :key="mod.key" class="market-setting-row modules">
              <label :for="'module-' + mod.key">{{ mod.label }}<small v-if="scope !== 'global'">{{ parentName }} default: {{ config.parent[mod.key] ? 'Enabled' : 'Disabled' }}</small></label>
              <select :id="'module-' + mod.key" v-model="values[mod.key]"><option v-if="scope !== 'global'" :value="null">Use {{ parentName }} default</option><option :value="true">Enabled</option><option :value="false">Disabled</option></select>
            </div>
            <p class="market-footnote">Saved rates apply to new quotes. Existing booking and payment records keep their recorded fees. Country and city availability is managed separately.</p>
          </div>
          <footer><p v-if="error" class="market-error" role="alert">{{ error }}</p><span v-else>Changes take effect after saving.</span><div><button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button><button class="btn btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save settings' }}</button></div></footer>
        </form>
        <div v-else class="market-body"><p :role="error ? 'alert' : 'status'">{{ error || 'Loading settings…' }}</p></div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.market-backdrop{position:fixed;inset:0;background:#08111bb3;z-index:1100;display:flex;align-items:center;justify-content:center;padding:24px}
.market-dialog{width:760px;max-width:100%;max-height:calc(100dvh - 48px);overflow:auto;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:16px;box-shadow:0 24px 80px #0004}
header,footer{display:flex;justify-content:space-between;gap:20px;padding:22px 26px;align-items:center}header{border-bottom:1px solid var(--border)}h2{font-size:21px;margin:0}h3{font-size:15px;margin:0}p{margin:5px 0 0;color:var(--text-muted);font-size:13px;line-height:1.6}.market-body{padding:24px 26px}.inherit-path{display:flex;align-items:center;gap:12px;font-size:12px;color:var(--text-muted);margin-bottom:24px}.inherit-path .current{font-weight:700;color:var(--primary-ink)}.market-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px}.market-help{margin-bottom:12px}.market-setting-row{display:grid;grid-template-columns:1fr 190px 100px;gap:12px;align-items:center;padding:14px 0;border-bottom:1px solid var(--border)}.market-setting-row label{font-weight:600;font-size:13px}.market-setting-row small{display:block;font-weight:400;color:var(--text-muted);font-size:12px;margin-top:4px}.market-setting-row.modules{grid-template-columns:1fr 240px}.market-percent{position:relative}.market-percent input{width:100%;padding-right:25px}.market-percent span{position:absolute;right:12px;top:10px;color:var(--text-muted)}.market-percent input:disabled{opacity:.65}.services-heading{margin-top:26px}.market-footnote{margin-top:20px}footer{position:sticky;bottom:0;background:var(--surface);border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)}footer>div{display:flex;gap:8px;flex-shrink:0}.market-error{color:var(--danger-ink)}
@media(max-width:600px){.market-backdrop{padding:0}.market-dialog{border-radius:0;max-height:100dvh;height:100dvh}header,footer,.market-body{padding:18px}.market-setting-row{grid-template-columns:1fr 90px}.market-setting-row label{grid-column:1/-1}.market-setting-row.modules{grid-template-columns:1fr}.market-setting-row input,.market-setting-row select{font-size:16px}.market-section-title{align-items:flex-start;flex-direction:column}footer{flex-direction:column;align-items:stretch}footer>div>.btn{flex:1}}
</style>
