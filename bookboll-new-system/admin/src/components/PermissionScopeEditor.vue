<script setup>
/**
 * PermissionScopeEditor.vue — per-user capability overrides + location/listing/
 * program scope, for the My Team / Users editor.
 *
 * The chosen ROLE already sets sensible permissions. This component lets you
 * pin the person to specific locations/listings/programs (searchable pickers),
 * and fine-tune beyond the role under an "Advanced — custom permissions" collapse
 * (toggling a role-default cap OFF records a `revoke`; toggling an extra cap ON
 * records a `grant`).
 */
import { ref, computed } from 'vue'
import { CAP_INFO, roleBaseCaps, can, editableCapsForRole, prereqsOf, dependentsOf } from '../lib/permissions.js'
import { Check, ChevronDown, SlidersHorizontal, MapPin, X } from 'lucide-vue-next'
import MultiSelect from './MultiSelect.vue'

const props = defineProps({
  role:        { type: String, default: 'staff' },
  permissions: { type: Object, default: () => ({}) },     // { grant:[], revoke:[] }
  locationIds: { type: Array,  default: () => [] },
  listingIds:  { type: Array,  default: () => [] },
  programIds:  { type: Array,  default: () => [] },
  availableLocations: { type: Array, default: () => [] },  // [{id,name}]
  availableListings:  { type: Array, default: () => [] },  // [{id,name,location_id}]
  availablePrograms:  { type: Array, default: () => [] },  // [{id,name,location_id}]
  editableCapabilities: { type: Array, default: null },
  allowWholeBusiness: { type: Boolean, default: true },
})
const emit = defineEmits([
  'update:permissions', 'update:locationIds', 'update:listingIds', 'update:programIds',
])

const advancedOpen = ref(false)
const baseCaps = computed(() => roleBaseCaps(props.role))
const grant = computed(() => props.permissions?.grant || [])
const revoke = computed(() => props.permissions?.revoke || [])
const hasCustom = computed(() => grant.value.length > 0 || revoke.value.length > 0)

// Group capabilities by their CAP_INFO.group — limited to the caps that make sense
// for this role's side (e.g. provider Staff never see platform-only powers).
const groups = computed(() => {
  const m = {}
  for (const key of editableCapsForRole(props.role).filter(c => !props.editableCapabilities || props.editableCapabilities.includes(c) || baseCaps.value.includes(c))) {
    const info = CAP_INFO[key]
    if (!info || info.retired) continue
    ;(m[info.group] ||= []).push({ key, label: info.label })
  }
  return Object.entries(m).map(([group, caps]) => ({ group, caps }))
})

const isOn = (cap) => can(cap, props.role, props.permissions)
const isBase = (cap) => baseCaps.value.includes(cap)

// Toggle a capability, cascading dependencies so a contradictory state is
// impossible: turning a cap ON also turns on its prerequisites (e.g. Manage
// bookings ⇒ View bookings); turning a cap OFF also turns off anything that
// depends on it (View bookings off ⇒ Manage bookings off). The resulting
// effective set is converted back into { grant, revoke } relative to the role.
function toggleCap(cap) {
  const editable = editableCapsForRole(props.role)
  const on = new Set(editable.filter(c => isOn(c)))
  if (on.has(cap)) {
    on.delete(cap)
    for (const d of dependentsOf(cap)) on.delete(d)
  } else {
    on.add(cap)
    for (const p of prereqsOf(cap)) on.add(p)
  }
  const base = new Set(baseCaps.value)
  const g = [], r = []
  for (const c of editable) {
    const want = on.has(c)
    if (want && !base.has(c)) g.push(c)
    else if (!want && base.has(c)) r.push(c)
  }
  emit('update:permissions', { grant: g, revoke: r })
}
function resetToRole() { emit('update:permissions', { grant: [], revoke: [] }) }

// ── Scope pickers ──
const asOptions = (items) => items.map(i => ({ value: i.id, label: i.name }))
const locationOptions = computed(() => asOptions(props.availableLocations))
const selectedLocations = computed(() => props.availableLocations.filter(l => props.locationIds.includes(l.id)))
const allLocationsSelected = computed(() => props.availableLocations.length > 0 && props.availableLocations.every(l => props.locationIds.includes(l.id)))
function selectAllLocations() { emit('update:locationIds', props.availableLocations.map(l => l.id)) }
function unselectAllLocations() { emit('update:locationIds', []) }
function removeLocation(id) { emit('update:locationIds', props.locationIds.filter(x => x !== id)) }
// Selected locations narrow the listing/program choices to those AT the chosen
// venues. Items with no venue (location_id null) are business-wide and stay
// pickable regardless of the location selection.
const scopedListings = computed(() =>
  props.locationIds.length
    ? props.availableListings.filter(l => !l.location_id || props.locationIds.includes(l.location_id))
    : props.availableListings)
const scopedPrograms = computed(() =>
  props.locationIds.length
    ? props.availablePrograms.filter(p => !p.location_id || props.locationIds.includes(p.location_id))
    : props.availablePrograms)
const listingOptions = computed(() => asOptions(scopedListings.value))
const programOptions = computed(() => asOptions(scopedPrograms.value))
</script>

<template>
  <div class="pse">
    <!-- Scope: locations / listings / programs -->
    <section class="pse-scope-card" aria-labelledby="pse-scope-title">
      <div class="pse-scope-top">
        <div>
          <div id="pse-scope-title" class="pse-section-title">Access scope</div>
          <p class="pse-hint">{{ allowWholeBusiness ? 'Leave locations empty to give access to the whole business.' : 'Select at least one venue within your own access.' }}</p>
        </div>
        <span class="pse-scope-pill" :class="{ all: !locationIds.length }"><MapPin :size="13" />{{ !locationIds.length ? (allowWholeBusiness ? 'Whole business' : 'Select venues') : `${locationIds.length} of ${availableLocations.length} locations` }}</span>
      </div>

      <div class="pse-field">
        <div class="pse-field-head">
          <span class="pse-scope-head">Locations</span>
          <div v-if="availableLocations.length" class="pse-bulk">
            <button type="button" :disabled="allLocationsSelected" @click="selectAllLocations">Select all</button>
            <span aria-hidden="true">·</span>
            <button type="button" :disabled="!locationIds.length" @click="unselectAllLocations">Unselect all</button>
          </div>
        </div>
        <MultiSelect v-if="availableLocations.length" :model-value="locationIds" :options="locationOptions" :placeholder="allowWholeBusiness ? 'All locations' : 'Select venues'" :max-display="2" @update:model-value="emit('update:locationIds', $event)" />
        <div v-else class="pse-empty">No locations yet.</div>
        <div v-if="selectedLocations.length" class="pse-selected" aria-label="Selected locations">
          <span v-for="l in selectedLocations" :key="l.id" class="pse-tagchip">{{ l.name }}<button type="button" :aria-label="`Remove ${l.name}`" @click="removeLocation(l.id)"><X :size="12" /></button></span>
        </div>
      </div>

      <div v-if="availableListings.length || availablePrograms.length" class="pse-sub-grid">
        <div v-if="availableListings.length" class="pse-field">
          <span class="pse-scope-head">Listings <span class="pse-opt">optional</span></span>
          <MultiSelect v-if="scopedListings.length" :model-value="listingIds" :options="listingOptions" placeholder="All listings" :max-display="1" @update:model-value="emit('update:listingIds', $event)" />
          <div v-else class="pse-empty">No listings at the selected locations.</div>
        </div>
        <div v-if="availablePrograms.length" class="pse-field">
          <span class="pse-scope-head">Programs <span class="pse-opt">optional</span></span>
          <MultiSelect v-if="scopedPrograms.length" :model-value="programIds" :options="programOptions" placeholder="All programs" :max-display="1" @update:model-value="emit('update:programIds', $event)" />
          <div v-else class="pse-empty">No programs at the selected locations.</div>
        </div>
      </div>
    </section>

    <!-- Advanced: custom permissions (collapsed by default) -->
    <div class="pse-adv">
      <button type="button" class="pse-adv-head" :aria-expanded="advancedOpen" :class="{ open: advancedOpen }" @click="advancedOpen = !advancedOpen">
        <SlidersHorizontal :size="15" />
        <span class="pse-adv-title">Advanced — custom permissions</span>
        <span v-if="hasCustom" class="pse-adv-badge">customised</span>
        <ChevronDown :size="16" class="pse-adv-chev" :class="{ rot: advancedOpen }" />
      </button>

      <div v-show="advancedOpen" class="pse-adv-body">
        <div class="pse-adv-note">
          Start from the role’s defaults, then tick to add or untick to remove for this person.
          <button v-if="hasCustom" type="button" class="pse-reset" @click="resetToRole">Reset to role defaults</button>
        </div>
        <div class="pse-groups">
          <div v-for="grp in groups" :key="grp.group" class="pse-group">
            <div class="pse-group-name">{{ grp.group }}</div>
            <label v-for="c in grp.caps" :key="c.key" class="pse-cap">
              <span class="pse-check" :class="{ on: isOn(c.key) }"><Check v-if="isOn(c.key)" :size="12" /></span>
              <input type="checkbox" :checked="isOn(c.key)" @change="toggleCap(c.key)" class="pse-native-check" />
              <span class="pse-cap-label">{{ c.label }}</span>
              <span v-if="!isBase(c.key) && isOn(c.key)" class="pse-tag granted">added</span>
              <span v-else-if="isBase(c.key) && !isOn(c.key)" class="pse-tag revoked">removed</span>
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pse { display: flex; flex-direction: column; gap: 14px; }
.pse-section-title { font-size: 14px; font-weight: 700; color: var(--text); }
.pse-hint { font-weight: 500; color: var(--text-muted); }

/* Scope card + searchable pickers */
.pse-scope-card { border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 16px; background: var(--surface, #fff); }
.pse-scope-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.pse-scope-top .pse-hint { margin: 3px 0 0; font-size: 12.5px; }
.pse-scope-pill { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink); }
.pse-scope-pill.all { background: var(--bg, #f1f5f9); color: var(--text-muted); }
.pse-field { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
.pse-field-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.pse-scope-head { font-size: 13px; font-weight: 700; color: var(--text); }
.pse-opt { font-size: 11.5px; font-weight: 500; color: var(--text-muted); margin-left: 4px; }
.pse-bulk { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted); }
.pse-bulk button { background: none; border: 0; padding: 4px 2px; font: inherit; font-weight: 600; color: var(--primary-ink, var(--primary)); cursor: pointer; }
.pse-bulk button:disabled { color: var(--text-muted); opacity: .55; cursor: default; }
.pse-bulk button:focus-visible { outline: 2px solid var(--primary-ink); outline-offset: 2px; border-radius: 4px; }
.pse-selected { display: flex; flex-wrap: wrap; gap: 6px; max-height: 112px; overflow-y: auto; }
.pse-tagchip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 3px 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink); border: 1px solid color-mix(in srgb, var(--primary) 30%, transparent); }
.pse-tagchip button { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; border: 0; background: transparent; color: inherit; cursor: pointer; }
.pse-tagchip button:hover { background: color-mix(in srgb, var(--primary) 18%, transparent); }
.pse-tagchip button:focus-visible { outline: 2px solid var(--primary-ink); outline-offset: 1px; }
.pse-sub-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.pse-empty { font-size: 12.5px; color: var(--text-muted); }

/* Advanced collapse */
.pse-adv { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.pse-adv-head { width: 100%; display: flex; align-items: center; gap: 9px; padding: 12px 14px; background: var(--surface-hover, #f8fafc); border: none; cursor: pointer; font: inherit; color: var(--text); }
.pse-adv-head:hover { background: var(--bg, #f1f5f9); }
.pse-adv-head.open { border-bottom: 1px solid var(--border); }
.pse-adv-head svg:first-child { color: var(--text-muted); }
.pse-adv-title { font-size: 13.5px; font-weight: 700; }
.pse-adv-badge { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px; background: var(--info-bg, #eff6ff); color: var(--info-ink, #2563eb); }
.pse-adv-chev { margin-left: auto; color: var(--text-muted); transition: transform .15s; }
.pse-adv-chev.rot { transform: rotate(180deg); }
.pse-adv-body { padding: 13px; }
.pse-adv-note { font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pse-reset { font-size: 12px; font-weight: 700; color: var(--primary-ink); background: none; border: none; cursor: pointer; text-decoration: underline; }

.pse-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.pse-group { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.pse-group-name { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.pse-cap { position: relative; display: flex; align-items: center; gap: 8px; padding: 5px 0; cursor: pointer; font-size: 13px; }
.pse-check { width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.pse-check.on { background: var(--primary); border-color: var(--primary); }
.pse-cap-label { flex: 1; }
.pse-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 1px 6px; border-radius: 999px; }
.pse-tag.granted { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #047857); }
.pse-tag.revoked { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #b91c1c); }
.pse-native-check { position: absolute; width: 1px; height: 1px; padding: 0; margin: 0; opacity: 0; }
.pse-cap:has(input:focus-visible) { outline: 2px solid var(--primary-ink); outline-offset: 3px; }

@media (max-width: 600px) {
  .pse-scope-card { padding: 14px; }
  .pse-scope-top { flex-direction: column; gap: 8px; }
  .pse-bulk button { min-height: 36px; }
}
</style>
