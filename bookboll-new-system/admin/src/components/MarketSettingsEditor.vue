<script setup>
import { ref, computed, onMounted } from 'vue'
import { X, ArrowRight, RotateCcw, Check } from 'lucide-vue-next'
import { loadMarketSettings, saveMarketSettings } from '../lib/geography.js'
import { MARKET_GROUPS, marketDraft, effectiveDraft, marketValue, marketChanges, validateMarketDraft } from '../lib/marketSettings.js'
const props = defineProps({ scope: String, scopeId: Number, name: String, initialSection: { type: String, default: 'marketplace' } })
const emit = defineEmits(['close', 'saved'])
const config = ref(null), values = ref({}), initial = ref({}), busy = ref(false), error = ref(''), discard = ref(false)
const section = ref(props.initialSection), review = ref(false)
const group = computed(() => MARKET_GROUPS.find(g => g.key === section.value) || MARKET_GROUPS[0])
const parentName = props.scope === 'city' ? 'country' : 'global'
const effective = computed(() => effectiveDraft(values.value, props.scope === 'global' ? {} : config.value?.parent || {}))
const changes = computed(() => marketChanges(initial.value, values.value))
const sectionOverrides = computed(() => group.value.fields.some(f => values.value[f.key] !== null))
async function load() {
  error.value = ''
  try {
    const res = await loadMarketSettings(props.scope, props.scopeId)
    if (!res.success) throw new Error(res.message || 'Could not load settings.')
    config.value = res.data.settings; values.value = marketDraft(config.value); initial.value = { ...values.value }
  } catch (e) { error.value = e.message }
}
onMounted(load)
function close() { if (busy.value) return; if (changes.value.length) discard.value = true; else emit('close') }
function resetSection() { for (const f of group.value.fields) values.value[f.key] = null }
function setSource(field, source) {
  values.value[field.key] = source === 'inherit' ? null : field.boolean ? Boolean(effective.value[field.key]) : (field.currency ? Number(effective.value[field.key] ?? 20) : Number.parseFloat(marketValue(effective.value, field.key)))
}
function valueLabel(field, draft) {
  if (draft[field.key] === null) return props.scope === 'global' ? field.automatic : `Use ${parentName} default`
  return field.boolean ? (draft[field.key] ? 'Enabled' : 'Disabled') : (field.currency ? `USD ${draft[field.key]}` : `${draft[field.key]}%`)
}
async function save() {
  if (busy.value || !changes.value.length) return
  const invalid = validateMarketDraft(values.value)
  if (invalid) { section.value = MARKET_GROUPS.find(g => g.fields.some(f => f.key === invalid.key)).key; error.value = invalid.message; return }
  busy.value = true; error.value = ''
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
    <div class="market-backdrop" @click.self="close">
      <section class="market-dialog" v-dialog="close" role="dialog" aria-modal="true" aria-labelledby="market-settings-title">
        <header><div><h2 id="market-settings-title">{{ scope === 'global' ? 'Global defaults' : name + ' settings' }}</h2><p>{{ scope === 'global' ? 'Shared settings for countries without an override.' : 'Inherited settings stay connected to ' + parentName + ' defaults.' }}</p></div><button class="btn btn-secondary btn-icon" :disabled="busy" aria-label="Close settings" @click="close"><X :size="18" /></button></header>
        <template v-if="config">
          <nav class="market-nav" aria-label="Settings sections"><button v-for="g in MARKET_GROUPS" :key="g.key" type="button" :class="{ active: section === g.key }" :aria-pressed="section === g.key" @click="section = g.key"><span>{{ g.short }}</span><i v-if="changes.some(f => g.fields.some(x => x.key === f.key))" aria-label="Unsaved changes"></i></button></nav>
          <form @submit.prevent="save" novalidate>
            <div class="market-body">
              <div class="market-scope"><span :class="{ current: scope === 'global' }">Global</span><ArrowRight :size="13"/><span :class="{ current: scope === 'country' }">Country</span><ArrowRight :size="13"/><span :class="{ current: scope === 'city' }">City</span></div>
              <div class="market-section-title"><h3>{{ group.label }}</h3><button v-if="scope !== 'global' && sectionOverrides" type="button" class="market-link" @click="resetSection"><RotateCcw :size="13"/> Use {{ parentName }} defaults</button></div>
              <p class="market-help">{{ group.help }}</p>
              <div v-for="field in group.fields" :key="field.key" class="market-row" :class="{ inherited: values[field.key] === null, changed: changes.some(f => f.key === field.key) }">
                <div class="market-field-label"><label :for="'setting-' + field.key">{{ field.label }}</label><small v-if="scope !== 'global'">{{ parentName }}: {{ marketValue(config.parent, field.key) }}<template v-if="field.automatic && config.parent[field.key] == null"> · {{ field.automatic }}</template></small><small v-else-if="field.automatic">Automatic: {{ field.automatic }}</small></div>
                <div class="market-controls">
                  <select v-if="!field.boolean && (scope !== 'global' || field.automatic)" :aria-label="field.label + ' setting source'" :value="values[field.key] === null ? 'inherit' : 'custom'" @change="setSource(field, $event.target.value)"><option value="inherit">{{ scope === 'global' ? 'Automatic' : 'Use ' + parentName }}</option><option value="custom">Custom rate</option></select>
                  <select v-if="field.boolean" :id="'setting-' + field.key" v-model="values[field.key]"><option v-if="scope !== 'global'" :value="null">Use {{ parentName }} · {{ config.parent[field.key] ? 'Enabled' : 'Disabled' }}</option><option :value="true">Enabled</option><option :value="false">Disabled</option></select>
                  <div v-else class="market-percent"><input :id="'setting-' + field.key" type="number" :min="field.currency ? 1 : 0" :max="field.currency ? 100000 : 100" step="0.01" :disabled="values[field.key] === null" :value="values[field.key] ?? (field.currency ? Number(effective[field.key] ?? 20) : Number.parseFloat(marketValue(effective, field.key)))" @input="values[field.key] = $event.target.value === '' ? '' : Number($event.target.value)" /><span>{{ field.currency ? '$' : '%' }}</span></div>
                </div>
              </div>
              <div v-if="section === 'website'" class="market-example"><strong>On a booking worth 100</strong><div><span>Free: <b>{{ marketValue(effective, 'site_free').replace('%', '') }}</b> platform fee</span><span>Premium: <b>{{ marketValue(effective, 'site_premium').replace('%', '') }}</b> platform fee</span></div><small>Automatic examples use this market’s venue rate. A listing’s explicit marketplace rate can change the automatic website fee. Premium requires an active paid subscription.</small></div>
              <p v-if="section === 'marketplace'" class="market-note">Listing-specific venue commissions take priority over these defaults.</p>
              <div v-if="changes.length" class="market-review"><button type="button" :aria-expanded="review" @click="review = !review">{{ review ? 'Hide' : 'Review' }} {{ changes.length }} unsaved change{{ changes.length === 1 ? '' : 's' }}</button><dl v-if="review"><div v-for="field in changes" :key="field.key"><dt>{{ field.label }}</dt><dd><s>{{ valueLabel(field, initial) }}</s><ArrowRight :size="13"/><b>{{ valueLabel(field, values) }}</b></dd></div></dl></div>
              <p v-if="error" class="market-error" role="alert">{{ error }}</p>
              <div v-if="discard" class="market-discard" role="alert"><strong>Discard your unsaved changes?</strong><div><button type="button" class="btn btn-secondary btn-sm" @click="discard = false">Keep editing</button><button type="button" class="btn btn-secondary btn-sm" @click="emit('close')">Discard changes</button></div></div>
            </div>
            <footer><span>{{ changes.length ? 'Applies to new quotes. Recorded fees stay unchanged.' : 'No unsaved changes' }}</span><div><button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button><button class="btn btn-primary" :disabled="busy || !changes.length"><Check :size="15"/>{{ busy ? 'Saving…' : 'Save changes' }}</button></div></footer>
          </form>
        </template>
        <div v-else class="market-body"><p :role="error ? 'alert' : 'status'">{{ error || 'Loading settings…' }}</p><button v-if="error" class="btn btn-secondary" @click="load">Try again</button></div>
      </section>
    </div>
  </Teleport>
</template>
<style scoped>
.market-backdrop{position:fixed;inset:0;background:#08111bb3;z-index:1100;display:flex;align-items:center;justify-content:center;padding:24px}.market-dialog{width:760px;max-width:100%;max-height:calc(100dvh - 48px);display:flex;flex-direction:column;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:14px;box-shadow:0 24px 80px #0004;overflow:hidden}.market-dialog form{display:flex;flex-direction:column;min-height:0}header,footer{display:flex;justify-content:space-between;gap:20px;padding:22px 26px;align-items:center;flex-shrink:0}header{border-bottom:1px solid var(--border)}h2{font-size:21px;margin:0}h3{font-size:16px;margin:0}p{margin:5px 0 0;color:var(--text-muted);font-size:13px;line-height:1.6}.market-nav{display:flex;overflow-x:auto;gap:6px;padding:12px 26px;border-bottom:1px solid var(--border);flex-shrink:0}.market-nav button{flex-shrink:0;border:0;background:transparent;color:var(--text-muted);padding:10px 14px;border-radius:6px;font-size:13px;display:flex;align-items:center;gap:8px;cursor:pointer}.market-nav button.active{color:var(--primary-ink);background:var(--primary-soft,var(--bg));font-weight:700}.market-nav i{width:6px;height:6px;border-radius:50%;background:var(--primary)}.market-body{padding:22px 26px;overflow-y:auto;min-height:0}.market-scope{display:flex;align-items:center;gap:10px;font-size:11px;color:var(--text-muted);margin-bottom:18px}.market-scope .current{font-weight:700;color:var(--primary-ink)}.market-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px}.market-link{display:flex;align-items:center;gap:5px;color:var(--primary-ink);background:none;border:0;font-size:12px;cursor:pointer}.market-help{margin-bottom:18px;max-width:65ch}.market-row{display:flex;gap:18px;justify-content:space-between;align-items:center;padding:18px 0;border-bottom:1px solid var(--border)}.market-field-label{min-width:0}.market-field-label label{font-weight:600;font-size:13px}.market-field-label small{display:block;color:var(--text-muted);font-size:11px;margin-top:5px;max-width:32ch;line-height:1.5}.market-controls{display:flex;align-items:center;gap:10px;flex-shrink:0}.market-controls select{width:160px;font-size:12px}.market-percent{position:relative;width:100px}.market-percent input{width:100%;padding-right:25px;font-size:14px;font-variant-numeric:tabular-nums}.market-percent span{position:absolute;right:12px;top:10px;color:var(--text-muted)}.market-percent input:disabled{opacity:.7;background:var(--bg)}.market-row.changed label:after{content:'Edited';font-size:10px;font-weight:500;margin-left:8px;color:var(--primary-ink)}.market-note{font-size:12px;margin-top:16px}.market-example{padding:16px;background:var(--bg);border:1px solid var(--border);border-radius:8px;margin-top:20px;font-size:12px}.market-example strong{font-size:13px}.market-example>div{display:flex;gap:20px;margin:10px 0}.market-example small{font-size:11px;color:var(--text-muted);line-height:1.6;display:block}.market-review{margin-top:20px}.market-review>button{color:var(--primary-ink);font-size:12px;border:0;background:none;padding:0;cursor:pointer;text-decoration:underline}.market-review dl{margin:12px 0 0}.market-review dl>div{padding:8px 0;display:flex;justify-content:space-between;gap:10px;font-size:12px}.market-review dd{margin:0;display:flex;gap:10px;align-items:center}.market-review s{color:var(--text-muted)}footer{border-top:1px solid var(--border);font-size:11px;color:var(--text-muted);padding-top:16px;padding-bottom:16px}footer>div{display:flex;gap:8px;flex-shrink:0}.market-error{color:var(--danger-ink);margin-top:16px}.market-discard{padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;margin-top:16px;font-size:13px}.market-discard>div{display:flex;gap:8px;margin-top:10px}
@media(max-width:600px){.market-backdrop{padding:0}.market-dialog{border-radius:0;max-height:100dvh;height:100dvh}.market-dialog form{flex:1}header,footer,.market-body{padding:18px}.market-body{flex:1}.market-nav{padding:10px 12px;gap:2px}.market-nav button{padding:10px;font-size:12px}.market-row{align-items:stretch;flex-direction:column;gap:10px}.market-controls select{flex:1}.market-controls input,.market-controls select{font-size:16px}.market-section-title{align-items:flex-start;flex-direction:column}.market-review dl>div{flex-direction:column}footer{flex-direction:column;align-items:stretch;gap:10px}footer>div>.btn{flex:1}.market-example>div{flex-direction:column;gap:6px}}
</style>
