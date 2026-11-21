<script setup>
import { ref, watch } from 'vue'
import { DIAL_OPTIONS, dialForIso } from '../customer/phone.js'
import { normalizeBusinessPhone } from '../lib/businessPhone.js'
const props = defineProps({ modelValue: { type: String, default: '' }, defaultIso: { type: String, default: '' }, inputId: { type: String, required: true }, invalid: Boolean, describedBy: String, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const dial = ref(dialForIso(props.defaultIso)), national = ref('')
function read(value) {
  const phone = normalizeBusinessPhone(value)
  if (!phone) { national.value = ''; return }
  const match = [...DIAL_OPTIONS].sort((a,b) => b.dial.length-a.dial.length).find(c => phone.startsWith(c.dial))
  dial.value = match?.dial || ''
  national.value = match ? phone.slice(match.dial.length) : phone
}
watch(() => props.modelValue, read, { immediate: true })
watch(() => props.defaultIso, iso => { if (!props.modelValue) dial.value = dialForIso(iso) })
function updateNumber(event) {
  national.value = event.target.value
  const raw = national.value.trim()
  emit('update:modelValue', !raw ? '' : /^(\+|00)/.test(raw) ? raw : `${dial.value}${raw}`)
}
function updateCode() { emit('update:modelValue', national.value.trim() ? `${dial.value}${national.value.trim()}` : '') }
</script>
<template>
  <div class="business-phone-input" :class="{ invalid }">
    <select v-model="dial" :disabled="disabled" :aria-label="'Country calling code for ' + inputId" @change="updateCode">
      <option value="">Country code</option>
      <option v-for="c in DIAL_OPTIONS" :key="c.iso" :value="c.dial">{{ c.name }} ({{ c.dial }})</option>
    </select>
    <input :id="inputId" :value="national" :disabled="disabled" :aria-invalid="invalid" :aria-describedby="describedBy" type="tel" inputmode="tel" autocomplete="tel-national" placeholder="Phone number" maxlength="32" @input="updateNumber" />
  </div>
</template>
<style scoped>
.business-phone-input{display:flex;gap:8px;min-width:0}.business-phone-input select{width:42%;min-width:100px}.business-phone-input input{flex:1;min-width:0}.business-phone-input select,.business-phone-input input{box-sizing:border-box;border:1px solid var(--border,#d6dce5);border-radius:8px;background:var(--surface,#fff);color:var(--text,#162234);padding:11px 12px;font:inherit;font-size:14px;height:44px}.business-phone-input.invalid input,.business-phone-input.invalid select{border-color:var(--danger,#dc2626)}.business-phone-input :focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:2px}.business-phone-input :disabled{opacity:.7}@media(max-width:400px){.business-phone-input{flex-wrap:wrap}.business-phone-input select{width:100%}}
</style>
