<script setup>
import { ref, watch } from 'vue'
import { X } from 'lucide-vue-next'
import RoleSelect from './RoleSelect.vue'
import PermissionScopeEditor from './PermissionScopeEditor.vue'
import { apiFetch } from '../lib/api.js'
import './teamDialogs.css'
const props = defineProps({ business: { type: Object, required: true } })
const emit = defineEmits(['close','saved'])
const options = ref(null), loading = ref(true)
const name = ref(''), email = ref(''), role = ref(''), status = ref('active')
const locations = ref([]), permissions = ref({grant:[],revoke:[]})
const busy = ref(false), error = ref(''), result = ref(null)
const endpoint = `/admin/businesses/${props.business.business_id || props.business.id}`
watch(role, () => { permissions.value = {grant:[],revoke:[]}; if(role.value === 'provider_admin') locations.value = [] })
async function load() {
  const r = await apiFetch(`${endpoint}/invitation-options`)
  loading.value = false
  if(!r.success) { error.value = r.message || 'Could not load business access settings.'; return }
  options.value = r.data; role.value = r.data.roles?.[0] || ''
}
async function save() {
  if(busy.value || !options.value) return
  busy.value = true; error.value = ''
  const r = await apiFetch(`${endpoint}/invitations`, { method:'POST', body:{ name:name.value, email:email.value, role:role.value, status:status.value, location_ids:locations.value, permissions:permissions.value } })
  busy.value = false
  if (!r.success) { error.value = r.message || 'Could not invite this person.'; return }
  result.value = r.data; emit('saved')
}
load()
</script>
<template><div class="modal-backdrop" @click.self="!busy && emit('close')"><form class="modal team-dialog" role="dialog" aria-modal="true" aria-labelledby="invite-title" v-dialog="() => !busy && emit('close')" @submit.prevent="save">
  <header class="modal-header"><div><p class="team-eyebrow">Business team</p><h2 id="invite-title">Invite to {{ business.business_name || business.name }}</h2><p class="team-help">Choose their role and the venues they can manage.</p></div><button type="button" class="btn-icon" :disabled="busy" aria-label="Close" @click="emit('close')"><X :size="18" /></button></header>
  <div class="modal-body" v-if="!result">
    <p v-if="loading" class="team-help">Loading business access…</p>
    <fieldset v-if="options" :disabled="busy" class="team-fieldset">
      <section class="team-form-section"><h3>1. Person & sign-in</h3><div class="team-form-grid">
        <label class="form-group"><span class="form-label">Full name *</span><input required v-model="name" autocomplete="name" /></label>
        <label class="form-group"><span class="form-label">Email *</span><input type="email" required v-model="email" autocomplete="email" /></label>
      </div><p class="team-help">They choose their password through Forgot password on the sign-in page.</p></section>
      <section class="team-form-section"><h3>2. Role & account status</h3>
        <p v-if="options.has_owner" class="team-help">This business already has an owner. Adding a team member keeps that owner in place.</p>
        <p v-else class="team-help">Add the first owner to give this business someone who can manage its team and website.</p>
        <RoleSelect v-model="role" :roles="options.roles" inline />
        <label class="form-group team-status"><span class="form-label">Sign-in access</span><select v-model="status"><option value="active">Active — can sign in</option><option value="inactive">Inactive — sign-in disabled</option></select></label>
      </section>
      <section v-if="role !== 'provider_admin'" class="team-form-section"><h3>3. Venue access & permissions</h3>
        <p v-if="!options.all_venues_allowed" class="team-help">Choose specific venues within your own access.</p>
        <PermissionScopeEditor :role="role" v-model:permissions="permissions" v-model:location-ids="locations" :available-locations="options.venues" :editable-capabilities="options.capabilities" :allow-whole-business="options.all_venues_allowed" />
      </section>
      <p v-else class="team-help">The owner has access to every venue in this business.</p>
    </fieldset>
    <p v-if="error" role="alert" class="form-error">{{ error }}</p>
  </div>
  <div v-else class="modal-body"><h3>Team member added</h3><p>{{ result.delivery === 'preview' ? 'This test workspace does not send invitations. The account and its access settings have been saved.' : result.delivery === 'sent' ? 'Invitation sent. They can set their password and sign in to this business.' : 'The account was created, but the email could not be delivered. Ask them to use Forgot password on the sign-in page.' }}</p></div>
  <footer class="modal-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('close')">{{ result ? 'Done' : 'Cancel' }}</button><button v-if="!result" class="btn btn-primary" :disabled="busy || !role || loading">{{ busy ? 'Adding…' : 'Add team member' }}</button></footer>
</form></div></template>
