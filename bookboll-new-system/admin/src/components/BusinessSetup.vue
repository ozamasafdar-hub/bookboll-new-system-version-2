<script setup>
import { computed, ref, onBeforeUnmount, watch } from 'vue'
import { pushDirtyScope, popDirtyScope, markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { X, Building2, Check, ExternalLink, ArrowRight } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'
import { BUSINESS_TYPES } from '../lib/businessTypes.js'
import { businessFormErrors } from '../lib/businessTeam.js'
import { brandPreviewUrl } from '../lib/brand.js'
const props = defineProps({ countries: { type: Array, default: () => [] } })
const emit = defineEmits(['close', 'created', 'invite', 'assign'])
const step = ref(1), busy = ref(false), error = ref(''), created = ref(null)
const teamChoice = ref('later')
const form = ref({ business_name: '', username: '', business_types: [], country_id: '', publish_website: false })
const key = crypto.randomUUID()
pushDirtyScope()
onBeforeUnmount(popDirtyScope)
watch(form, markDirty, { deep: true })
async function close() { if (!busy.value && (created.value || await confirmDiscardIfDirty())) { clearDirty(); emit('close') } }
const siteUrl = computed(() => brandPreviewUrl(form.value.username, { preview: !form.value.publish_website }))
function next() {
  error.value = ''
  if (step.value === 1) {
    const errors = businessFormErrors(form.value)
    if (Object.keys(errors).length) { error.value = Object.values(errors).join(' '); return }
  }
  step.value++
}
async function create() {
  if (busy.value) return
  busy.value = true; error.value = ''
  const r = await apiFetch('/admin/businesses', { method: 'POST', body: { ...form.value, country_id: form.value.country_id || null, status: 'active', owner_user_id: null, idempotency_key: key } })
  busy.value = false
  if (!r.success || !r.data?.business?.id) { error.value = r.message || 'Could not create business. Your details are still here.'; return }
  created.value = r.data.business
  clearDirty()
  emit('created', created.value)
}
function continueTeam() { emit(teamChoice.value === 'assign' ? 'assign' : 'invite', created.value); emit('close') }
</script>

<template>
  <div class="modal-backdrop" @click.self="close">
    <section class="modal business-setup" v-dialog="close" aria-label="Add business">
      <header class="modal-header"><div><h2>{{ created ? 'Business ready' : 'Add a business' }}</h2><p>{{ created ? 'Its workspace and website are ready to set up.' : 'Create the business first. Add its team whenever you’re ready.' }}</p></div><button class="btn-icon" :disabled="busy" aria-label="Close" @click="close"><X :size="18" /></button></header>
      <div v-if="created" class="modal-body setup-success">
        <Check :size="32" /><h3>{{ created.business_name }}</h3><p>Free plan · {{ form.publish_website ? 'Website published' : 'Website saved as draft' }}</p>
        <a class="setup-site" :href="siteUrl" target="_blank" rel="noopener"><ExternalLink :size="18" />{{ form.publish_website ? 'Open website' : 'Preview website' }}</a>
        <ol><li><strong>Add the team</strong><span>Invite the owner or staff, or assign an existing platform account.</span></li><li><strong>Add a venue and listings</strong><span>The team can manage courts, schedules and bookings from its dashboard.</span></li><li><strong>Make the website their own</strong><span>Add a logo, colours and contact details in My Website.</span></li></ol>
        <button class="btn btn-primary" @click="continueTeam">{{ teamChoice === 'assign' ? 'Assign platform account' : 'Invite owner or staff' }}<ArrowRight :size="16" /></button>
      </div>
      <template v-else>
        <nav class="setup-steps" aria-label="Business setup steps"><span v-for="(label, i) in ['Business', 'Team access', 'Review']" :key="label" :class="{ active: step === i + 1, done: step > i + 1 }">{{ i + 1 }}. {{ label }}</span></nav>
        <div class="modal-body">
          <div v-if="step === 1" class="setup-fields">
            <div class="form-group"><label for="setup-name" class="form-label">Business name *</label><input id="setup-name" v-model="form.business_name" placeholder="e.g. Doha Padel Club" /></div>
            <div class="form-group"><label for="setup-slug" class="form-label">Website address *</label><div class="setup-address"><input id="setup-slug" v-model="form.username" maxlength="30" @input="form.username = form.username.toLowerCase().replace(/\s/g, '')" placeholder="dohapadel" /><span>.bookboll.com</span></div><small>3–30 lowercase letters, numbers or hyphens. This is the public business web address.</small></div>
            <fieldset><legend>Business type *</legend><div class="setup-types"><label v-for="t in BUSINESS_TYPES" :key="t.key"><input type="checkbox" v-model="form.business_types" :value="t.key" />{{ t.label }}</label></div></fieldset>
            <div class="form-group"><label class="form-label" for="setup-country">Home country</label><select id="setup-country" v-model="form.country_id"><option value="">Set later</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option></select><small>Each venue uses its own country’s currency and payment settings.</small></div>
          </div>
          <div v-else-if="step === 2" class="setup-choices">
            <h3>Who will manage this business?</h3>
            <label v-for="choice in [{key:'later',title:'Assign later',desc:'Create it without an owner or staff account.'},{key:'invite',title:'Invite a new owner or staff member next',desc:'They receive an email and set their own password.'},{key:'assign',title:'Move an existing platform account next',desc:'Review the move before removing their platform access.'}]" :key="choice.key" :class="{ selected: teamChoice === choice.key }"><input type="radio" v-model="teamChoice" :value="choice.key" /><span><strong>{{ choice.title }}</strong><small>{{ choice.desc }}</small></span></label>
            <p class="setup-note">Business team members only get access to their business. You can manage the team from its card.</p>
          </div>
          <div v-else class="setup-review">
            <Building2 :size="26" /><h3>{{ form.business_name }}</h3><p>{{ form.username }}.bookboll.com</p>
            <dl><dt>Plan</dt><dd>Free</dd><dt>Country</dt><dd>{{ countries.find(c => String(c.id) === String(form.country_id))?.name || 'Set later' }}</dd><dt>Team access</dt><dd>{{ teamChoice === 'later' ? 'Assign later' : teamChoice === 'invite' ? 'Invite after creation' : 'Review assignment after creation' }}</dd></dl>
            <label class="setup-publish"><input type="checkbox" v-model="form.publish_website" /><span><strong>Publish its website now</strong><small>Leave off to prepare the website as a draft first.</small></span></label>
          </div>
          <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        </div>
      </template>
      <footer class="modal-footer"><button class="btn btn-secondary" :disabled="busy" @click="created ? close() : step > 1 ? step-- : close()">{{ created ? 'Done' : step > 1 ? 'Back' : 'Cancel' }}</button><button v-if="!created" class="btn btn-primary" :disabled="busy" @click="step < 3 ? next() : create()">{{ busy ? 'Creating…' : step < 3 ? 'Continue' : 'Create business' }}</button></footer>
    </section>
  </div>
</template>

<style scoped>
.business-setup{max-width:660px}.modal-header h2{font-size:20px;margin:0}.modal-header p{font-size:13px;color:var(--text-muted);margin:5px 0 0}.setup-steps{display:flex;padding:16px 24px;border-bottom:1px solid var(--border);gap:24px;color:var(--text-muted);font-size:13px}.setup-steps .active{color:var(--primary-ink);font-weight:700}.setup-steps .done{color:var(--positive-ink)}.setup-fields small,.setup-choices small,.setup-publish small{display:block;color:var(--text-muted);font-size:12px;line-height:1.5;margin-top:4px}.setup-address{display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden}.setup-address input{border:0;min-width:0;flex:1}.setup-address span{padding-right:12px;font-size:13px;color:var(--text-muted)}fieldset{border:0;padding:0;margin:0 0 20px}legend{font-weight:600;font-size:13px;margin-bottom:8px}.setup-types{display:flex;flex-wrap:wrap;gap:8px}.setup-types label{display:flex;gap:8px;align-items:center;padding:9px 12px;border:1px solid var(--border);border-radius:7px;font-size:13px}.setup-choices>label{display:flex;align-items:flex-start;gap:12px;border:1px solid var(--border);border-radius:10px;padding:16px;margin:12px 0;cursor:pointer}.setup-choices>label.selected{border-color:var(--primary);background:var(--primary-soft)}input[type=radio],input[type=checkbox]{width:auto;flex-shrink:0}.setup-note{font-size:13px;line-height:1.6;color:var(--text-muted)}.setup-review dl{display:grid;grid-template-columns:120px 1fr;gap:16px;padding:18px 0;border-block:1px solid var(--border)}dt{color:var(--text-muted)}dd{margin:0;font-weight:600}.setup-publish{display:flex;gap:12px;margin-top:20px}.setup-review h3,.setup-success h3{font-size:22px;margin:8px 0}.setup-review>p,.setup-success>p{color:var(--text-muted)}.setup-site{display:inline-flex;gap:8px;align-items:center;font-weight:600}.setup-success ol{padding-left:20px;margin:25px 0}.setup-success li{padding:8px 0}.setup-success li span{display:block;color:var(--text-muted);font-size:13px;line-height:1.5;margin-top:4px}@media(max-width:600px){.setup-steps{gap:14px;padding:14px;font-size:12px}.setup-address span{font-size:11px}}
</style>
