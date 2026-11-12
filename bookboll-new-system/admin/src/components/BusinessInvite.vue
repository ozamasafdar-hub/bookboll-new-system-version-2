<script setup>
import { ref, computed } from 'vue'
import { X } from 'lucide-vue-next'
import RoleSelect from './RoleSelect.vue'
import { apiFetch } from '../lib/api.js'
const props = defineProps({ business: { type: Object, required: true } })
const emit = defineEmits(['close','saved'])
const hasOwner = computed(() => !!(props.business.owner_user_id || (props.business.business_id && props.business.id)))
const roles = computed(() => hasOwner.value ? ['facility_admin','provider_supervisor','staff','provider_viewer'] : ['provider_admin'])
const name = ref(''), email = ref(''), role = ref(roles.value[0]), busy = ref(false), error = ref(''), result = ref(null)
async function save() {
  busy.value = true; error.value = ''
  const r = await apiFetch(`/admin/businesses/${props.business.business_id || props.business.id}/invitations`, { method:'POST', body:{ name:name.value, email:email.value, role:role.value } })
  busy.value = false
  if (!r.success) { error.value = r.message || 'Could not invite this person.'; return }
  result.value = r.data; emit('saved')
}
</script>
<template><div class="modal-backdrop" @click.self="!busy && emit('close')"><form class="modal" v-dialog="() => !busy && emit('close')" @submit.prevent="save">
  <header class="modal-header"><div><h3>Invite to {{ business.business_name || business.name }}</h3><p class="field-hint">Business access only</p></div><button type="button" class="btn-icon" aria-label="Close" @click="emit('close')"><X :size="18" /></button></header>
  <div class="modal-body" v-if="!result"><div class="form-group"><label class="form-label" for="business-invite-name">Full name *</label><input id="business-invite-name" required v-model="name" /></div><div class="form-group"><label class="form-label" for="business-invite-email">Email *</label><input id="business-invite-email" type="email" required v-model="email" /></div><div class="form-group"><label class="form-label">Business role</label><RoleSelect v-model="role" :roles="roles" /></div><p class="field-hint">They will set their password through the sign-in page. Existing platform accounts must use “Assign platform account” instead.</p><p v-if="error" role="alert" class="form-error">{{ error }}</p></div>
  <div v-else class="modal-body"><h3>Team member added</h3><p>{{ result.delivery === 'preview' ? 'This test workspace does not send invitations. The account is linked to this business.' : result.delivery === 'sent' ? 'Invitation sent. They can set their password and sign in to this business.' : 'The account was created, but the email could not be delivered. Ask them to use Forgot password on the sign-in page.' }}</p></div>
  <footer class="modal-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('close')">{{ result ? 'Done' : 'Cancel' }}</button><button v-if="!result" class="btn btn-primary" :disabled="busy">{{ busy ? 'Adding…' : 'Add team member' }}</button></footer>
</form></div></template>
