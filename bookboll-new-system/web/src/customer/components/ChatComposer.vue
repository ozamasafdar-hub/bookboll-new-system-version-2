<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue'
import { Send } from 'lucide-vue-next'
import { apiFetch } from '../../lib/api.js'
import { mentionQuery, moveMentions } from '../../lib/chatMentions.js'
const props = defineProps({ modelValue: { type: String, default: '' }, threadKey: { type: String, default: '' }, busy: Boolean, placeholder: { type: String, default: 'Write a message…' } })
const emit = defineEmits(['update:modelValue', 'send'])
const field = ref(null), tags = ref([]), query = ref(null), people = ref([]), active = ref(0), pending = ref(false), error = ref('')
let timer, version = 0
function close() { clearTimeout(timer); version++; query.value = null; people.value = []; pending.value = false }
watch(() => props.threadKey, () => { tags.value = []; close() })
watch(() => props.modelValue, (next, old) => { tags.value = moveMentions(old, next, tags.value); if (!next) close() })
onBeforeUnmount(close)
function search() {
  const q = props.threadKey ? mentionQuery(field.value.value, field.value.selectionStart) : null
  close(); error.value = ''; active.value = 0
  if (!q || tags.value.some(t => t.start === q.start && field.value.selectionStart >= t.start + t.length)) return
  query.value = q; pending.value = true
  const request = version, key = props.threadKey
  timer = setTimeout(async () => {
    const r = await apiFetch('/customer/messages/participants', { query: { key, q: q.query } })
    if (request !== version || key !== props.threadKey) return
    pending.value = false
    if (!r.success) error.value = r.message || 'Could not load participants.'
    people.value = r.data?.participants || []
  }, 180)
}
async function input(e) { emit('update:modelValue', e.target.value); await nextTick(); search() }
async function pick(person) {
  const q = query.value; if (!q) return
  const label = '@' + person.name.trim(), before = props.modelValue
  const next = before.slice(0, q.start) + label + ' ' + before.slice(q.end)
  emit('update:modelValue', next)
  await nextTick()
  tags.value.push({ user_id: person.id, start: q.start, length: label.length, label })
  close(); field.value.focus(); field.value.setSelectionRange(q.start + label.length + 1, q.start + label.length + 1)
}
function send() {
  if (props.busy || !props.modelValue.trim()) return
  const trim = props.modelValue.length - props.modelValue.trimStart().length
  emit('send', tags.value.filter(t => props.modelValue.slice(t.start, t.start + t.length) === t.label).map(t => ({ user_id: t.user_id, start: t.start - trim, length: t.length })))
  close()
}
function keydown(e) {
  if (e.isComposing) return
  if (query.value && e.key === 'Escape') { e.preventDefault(); close(); return }
  if (query.value && ['ArrowDown', 'ArrowUp'].includes(e.key)) { e.preventDefault(); active.value = (active.value + (e.key === 'ArrowDown' ? 1 : -1) + people.value.length) % Math.max(1, people.value.length); return }
  if (e.key === 'Enter') { e.preventDefault(); if (query.value && people.value.length) pick(people.value[active.value]); else if (!pending.value) send() }
}
</script>
<template>
  <div class="chat-composer">
    <div v-if="query" id="chat-mention-options" class="chat-mention-options" role="listbox" aria-label="Tag a participant">
      <small>Tag someone in this chat</small>
      <p v-if="pending" role="status">Finding participants…</p><p v-else-if="error" role="alert">{{ error }}</p><p v-else-if="!people.length">No matching participants.</p>
      <button v-for="(person, i) in people" :id="'mention-option-'+i" :key="person.id" type="button" role="option" :aria-selected="i === active" :class="{ selected: i === active }" @mousedown.prevent="pick(person)"><span class="chat-person-avatar">{{ person.name[0] }}</span><span>{{ person.name }}</span><span class="chat-person-at">@</span></button>
    </div>
    <input ref="field" :value="modelValue" :disabled="busy" :placeholder="placeholder" :maxlength="threadKey ? 5000 : 4000" aria-label="Message" :role="threadKey ? 'combobox' : undefined" :aria-expanded="threadKey ? !!query : undefined" :aria-controls="query ? 'chat-mention-options' : undefined" :aria-activedescendant="query && people.length ? 'mention-option-'+active : undefined" aria-autocomplete="list" @input="input" @keydown="keydown" @keyup.left="search" @keyup.right="search" @click="search" @blur="() => { setTimeout(close, 150) }" />
    <button class="chat-send" type="button" aria-label="Send message" :disabled="busy || !modelValue.trim()" @click="send"><Send :size="18" /></button>
    <small v-if="threadKey" class="chat-compose-hint">Type @ to tag a participant</small>
  </div>
</template>
<style scoped>
.chat-composer{position:relative;display:flex;gap:10px;flex-wrap:wrap;padding:12px 14px;border-top:1px solid var(--border,#334155);background:var(--surface,#fff)}.chat-composer input{flex:1;min-width:0;border:1px solid var(--border,#334155);border-radius:22px;padding:11px 16px;font:inherit;font-size:14px;background:transparent;color:var(--text);outline:none}.chat-composer input:focus{border-color:var(--brand,#2563eb)}.chat-send{width:42px;height:42px;border:0;border-radius:50%;background:var(--brand,#2563eb);color:white;display:grid;place-items:center;cursor:pointer}.chat-send:disabled{opacity:.5}.chat-compose-hint{flex-basis:100%;font-size:11px;color:var(--muted,#94a3b8)}.chat-mention-options{position:absolute;bottom:calc(100% - 1px);left:14px;right:14px;max-height:240px;overflow:auto;background:var(--surface,#fff);border:1px solid var(--border,#334155);border-radius:12px;box-shadow:0 8px 28px #0003;padding:6px;z-index:10}.chat-mention-options>small,.chat-mention-options>p{display:block;padding:8px 10px;color:var(--muted,#94a3b8);margin:0}.chat-mention-options button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:9px;border:0;border-radius:7px;color:var(--text);background:transparent;cursor:pointer;font:inherit}.chat-mention-options button.selected,.chat-mention-options button:hover{background:#3b82f626}.chat-person-avatar{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#3b82f633;font-weight:bold}.chat-person-at{margin-left:auto;color:var(--muted,#94a3b8)}
</style>
