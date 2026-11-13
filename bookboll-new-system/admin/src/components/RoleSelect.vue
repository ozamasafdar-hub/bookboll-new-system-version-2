<script setup>
/**
 * RoleSelect.vue — a rich role picker. Shows the selected role with its tagline,
 * and a dropdown of role cards (label · tagline · what they can do) so the admin
 * understands each tier while choosing. Drop-in replacement for a <select>.
 */
import { ref, computed, onBeforeUnmount } from 'vue'
import { ROLE_INFO } from '../lib/permissions.js'
import { Check, ChevronDown } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: String, default: '' },
  roles:      { type: Array,  default: () => [] },   // ordered role keys to offer
  inline:     { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const root = ref(null)
const current = computed(() => ROLE_INFO[props.modelValue] || { label: props.modelValue || 'Select a role', tagline: '' })

function pick(role) { emit('update:modelValue', role); open.value = false }
function onDocClick(e) { if (root.value && !root.value.contains(e.target)) open.value = false }
function toggle() {
  open.value = !open.value
  if (open.value) document.addEventListener('mousedown', onDocClick)
  else document.removeEventListener('mousedown', onDocClick)
}
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocClick))
</script>

<template>
  <div class="rs" :class="{ 'rs-inline': inline }" ref="root" @keydown.esc.stop="open = false">
    <button type="button" class="rs-trigger" :aria-expanded="open" :class="{ open }" @click="toggle">
      <span class="rs-dot" :style="{ background: current.color || '#94a3b8' }"></span>
      <span class="rs-trigger-text">
        <span class="rs-trigger-label">{{ current.label }}</span>
        <span v-if="current.tagline" class="rs-trigger-tag">{{ current.tagline }}</span>
      </span>
      <ChevronDown :size="17" class="rs-chev" :class="{ rot: open }" />
    </button>

    <div v-if="open" class="rs-menu">
      <button
        v-for="r in roles" :key="r" type="button"
        class="rs-opt" :class="{ active: r === modelValue }"
        @click="pick(r)"
      >
        <span class="rs-opt-dot" :style="{ background: ROLE_INFO[r]?.color || '#94a3b8' }"></span>
        <span class="rs-opt-body">
          <span class="rs-opt-top">
            <span class="rs-opt-label">{{ ROLE_INFO[r]?.label || r }}</span>
            <span class="rs-opt-tag">{{ ROLE_INFO[r]?.tagline }}</span>
          </span>
          <ul class="rs-opt-can">
            <li v-for="c in (ROLE_INFO[r]?.can || [])" :key="c"><Check :size="11" />{{ c }}</li>
          </ul>
        </span>
        <Check v-if="r === modelValue" :size="16" class="rs-opt-check" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.rs { position: relative; }
.rs-inline .rs-menu { position: static; margin-top: 8px; max-height: none; box-shadow: none; }
.rs-trigger { width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); cursor: pointer; font: inherit; text-align: left; }
.rs-trigger:hover { border-color: var(--primary); }
.rs-trigger.open { border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 14%, transparent); }
.rs-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.rs-trigger-text { display: flex; flex-direction: column; line-height: 1.2; min-width: 0; flex: 1; }
.rs-trigger-label { font-size: 14px; font-weight: 700; color: var(--text); }
.rs-trigger-tag { font-size: 12px; color: var(--text-muted); }
.rs-chev { color: var(--text-muted); transition: transform .15s; flex-shrink: 0; }
.rs-chev.rot { transform: rotate(180deg); }

.rs-menu {
  position: absolute; z-index: 60; top: calc(100% + 6px); left: 0; right: 0;
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  box-shadow: 0 14px 40px rgba(15,23,42,.16); padding: 6px; max-height: 360px; overflow-y: auto;
}
.rs-opt { width: 100%; display: flex; align-items: flex-start; gap: 10px; padding: 11px 12px; border: none; background: none; border-radius: 9px; cursor: pointer; text-align: left; }
.rs-opt:hover { background: var(--surface-hover, #f8fafc); }
.rs-opt.active { background: var(--primary-soft, #f0fdfa); }
.rs-opt-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.rs-opt-body { flex: 1; min-width: 0; }
.rs-opt-top { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.rs-opt-label { font-size: 13.5px; font-weight: 800; color: var(--text); }
.rs-opt-tag { font-size: 12px; color: var(--text-muted); }
.rs-opt-can { list-style: none; margin: 4px 0 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }
.rs-opt-can li { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-secondary, #475569); }
.rs-opt-can li svg { color: var(--positive-ink, #16a34a); flex-shrink: 0; }
.rs-opt-check { color: var(--primary-ink); flex-shrink: 0; margin-top: 3px; }
</style>
