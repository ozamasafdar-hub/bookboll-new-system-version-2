<script setup>
import { ref, computed } from 'vue'
import { X } from 'lucide-vue-next'
import BusinessPhoneInput from './BusinessPhoneInput.vue'
import { businessPhoneError, normalizeBusinessPhone } from '../lib/businessPhone.js'
import { apiFetch } from '../lib/api.js'
const props = defineProps({ business: { type: Object, required: true }, defaultIso: { type: String, default: '' } })
const emit = defineEmits(['close', 'saved'])
const phone = ref(props.business.phone || ''), busy = ref(false), attempted = ref(false), error = ref('')
const validation = computed(() => businessPhoneError(phone.value))
function close() { if (!busy.value) emit('close') }
async function save() {
  attempted.value = true; error.value = ''
  if (busy.value || validation.value) return
  busy.value = true
  const expected = normalizeBusinessPhone(phone.value)
  const result = await apiFetch(`/admin/businesses/${props.business.business_id}/contact`, { method: 'PUT', body: { phone: expected } })
  busy.value = false
  if (!result.success) { error.value = result.errors?.phone?.[0] || result.message || 'Could not save the business phone.'; return }
  if (result.data?.business?.phone !== expected) { error.value = 'The saved number could not be confirmed. Refresh and check before trying again.'; return }
  emit('saved'); emit('close')
}
</script>
<template>
  <div class="modal-backdrop" @click.self="close"><form class="modal business-contact-modal" v-dialog="close" @submit.prevent="save">
    <header class="modal-header"><div><h2>Business contact</h2><p>{{ business.business_name || business.full_name }}</p></div><button type="button" class="btn-icon" :disabled="busy" aria-label="Close business contact" @click="close"><X :size="18" /></button></header>
    <div class="modal-body"><label class="form-label" for="business-contact-phone">Business phone number *</label><BusinessPhoneInput v-model="phone" input-id="business-contact-phone" :default-iso="defaultIso" :invalid="attempted && !!validation" described-by="business-phone-help" :disabled="busy" /><p id="business-phone-help" class="contact-help">Use the business’s main contact number. This is separate from team members’ personal numbers.</p><p v-if="attempted && validation || error" class="form-error" role="alert">{{ error || validation }}</p></div>
    <footer class="modal-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button><button class="btn btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save business phone' }}</button></footer>
  </form></div>
</template>
<style scoped>.business-contact-modal{max-width:600px}.modal-header h2{margin:0}.modal-header p,.contact-help{color:var(--text-muted);font-size:13px;line-height:1.5;margin:8px 0}</style>
