<script setup>
import { ref, onMounted } from 'vue'
import { apiFetch } from '../../lib/api.js'
const preferences = ref(null), busy = ref(false), message = ref(''), error = ref('')
async function load() { const r = await apiFetch('/customer/messages/preferences'); if (r.success) preferences.value = r.data.preferences; else error.value = r.message }
async function save() { busy.value = true; error.value = ''; message.value = ''; const r = await apiFetch('/customer/messages/preferences', { method: 'PUT', body: preferences.value }); busy.value = false; if (r.success) message.value = 'Chat preferences saved'; else error.value = r.message }
onMounted(load)
</script>
<template><section class="chat-prefs"><h2>Chat alerts</h2><p>Get a reminder when you have unread marketplace messages or group @mentions. Messages you’ve already read won’t trigger an alert.</p><template v-if="preferences"><label><input type="checkbox" v-model="preferences.email" /> Email me about unread chats</label><label><input type="checkbox" v-model="preferences.whatsapp" /> Send chat alerts to my account phone number on WhatsApp</label><small>WhatsApp is optional. You can turn it off here at any time. Bookboll’s notification settings also apply.</small><button type="button" :disabled="busy" @click="save">{{ busy ? 'Saving…' : 'Save chat alerts' }}</button></template><p v-if="message" role="status">{{ message }}</p><p v-if="error" role="alert">{{ error }}</p></section></template>
<style scoped>.chat-prefs{padding:24px;border:1px solid var(--border,#334155);border-radius:14px;margin:16px 0}.chat-prefs h2{font-size:18px;margin:0 0 8px}.chat-prefs p,.chat-prefs small{font-size:13px;line-height:1.6;color:var(--muted,#94a3b8)}.chat-prefs label{display:flex;gap:10px;align-items:center;margin:16px 0}.chat-prefs small{display:block}.chat-prefs button{margin-top:16px;padding:10px 16px;border:0;border-radius:8px;background:var(--brand,#2563eb);color:white;cursor:pointer}</style>
