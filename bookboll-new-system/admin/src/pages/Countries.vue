<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { Globe2, MapPin, ChevronRight, Pencil, ChevronDown, Search, Award, X, ToggleLeft, ToggleRight, Plus, Trash2, RefreshCw } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import MarketSettingsEditor from '../components/MarketSettingsEditor.vue'
import { MARKET_GROUPS, marketValue, overrideCount } from '../lib/marketSettings.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { fetchGeographyCountries, fetchGeographyCities, updateCountryStatus, updateCityStatus, bulkUpdateCityStatus, resetCountryRewards } from '../lib/geography.js'
import { DEFAULT_LOYALTY, loadLoyaltyPrograms, saveLoyaltyProgram } from '../lib/loyalty.js'
const { isSuperAdmin, isCountryAdmin } = useCurrentUser()
const canManageGeo = computed(() => isSuperAdmin.value || isCountryAdmin.value)
const countries = ref([]), globalSettings = ref(null), globalRewards = ref(DEFAULT_LOYALTY)
const marketView = ref('onboarded'), countryPanels = ref({}), cityViews = ref({}), cityErrors = ref({})
const loading = ref(true), loadError = ref(''), search = ref(''), statusFilter = ref(''), sort = ref('venues')
const expanded = ref(new Set()), saving = ref(new Set())
const citiesByCountry = ref({}), citiesLoaded = ref(new Set()), citiesLoading = ref(new Set())
const citySearch = ref({}), cityLimit = ref({}), editor = ref(null), bulkSaving = ref(false), bulkTarget = ref(null)
const countryPage = ref(1), countryPerPage = ref(25), countryTotal = ref(0)
const countryTotalPages = computed(() => Math.max(1, Math.ceil(countryTotal.value / countryPerPage.value)))
const countryVisiblePages = computed(() => Array.from({ length: countryTotalPages.value }, (_, i) => i + 1).filter(p => p === 1 || p === countryTotalPages.value || Math.abs(p - countryPage.value) <= 1))
let loadSeq = 0, searchTimer
async function load(page = countryPage.value) {
  const seq = ++loadSeq
  loading.value = true; loadError.value = ''
  try {
    const res = await fetchGeographyCountries({ page, records_per_page: countryPerPage.value, search: search.value.trim() || undefined, status: statusFilter.value || undefined, sort: sort.value, view: marketView.value })
    if (seq !== loadSeq) return
    if (!res.success) throw new Error(res.message || 'Could not load countries.')
    countries.value = res.data.countries || []
    globalSettings.value = res.data.global_settings
    countryTotal.value = Number(res.data.pagination?.total || 0)
    countryPage.value = Number(res.data.pagination?.current_page || page)
  } catch (e) { if (seq === loadSeq) loadError.value = e.message }
  finally { if (seq === loadSeq) loading.value = false }
}
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => load(1), 300) })
watch([statusFilter, sort, countryPerPage, marketView], () => load(1))
onBeforeUnmount(() => { clearTimeout(searchTimer); loadSeq++ })
function countryGoTo(page) { load(page) }
function citiesOf(id) { return citiesByCountry.value[id] || [] }
function matchingCities(id) { const q = (citySearch.value[id] || '').toLowerCase(); return citiesOf(id).filter(c => c.name.toLowerCase().includes(q) && (cityViews.value[id] !== 'overrides' || overrideCount(c.settings)) && (cityViews.value[id] !== 'disabled' || c.status === 'inactive')) }
function shownCities(id) { return matchingCities(id).slice(0, cityLimit.value[id] || 12) }
async function ensureCities(id, force = false) {
  if ((!force && citiesLoaded.value.has(id)) || citiesLoading.value.has(id)) return
  citiesLoading.value = new Set(citiesLoading.value).add(id)
  cityErrors.value[id] = ''
  cityViews.value[id] ||= 'all'
  try {
    const res = await fetchGeographyCities(id)
    if (!res.success) throw new Error(res.message || 'Could not load cities.')
    citiesByCountry.value[id] = res.data.cities || []
    citiesLoaded.value = new Set(citiesLoaded.value).add(id)
  } catch (e) { cityErrors.value[id] = e.message }
  finally { citiesLoading.value.delete(id); citiesLoading.value = new Set(citiesLoading.value) }
}
function toggle(id) { expanded.value = expanded.value.has(id) ? new Set() : new Set([id]); if (expanded.value.has(id)) ensureCities(id) }
async function refresh() { await load(); for (const id of expanded.value) await ensureCities(id, true); await loadLoyalty() }
function openSettings(scope, item = {}, section = 'marketplace') { editor.value = { scope, id: Number(item.id || 0), name: item.name || 'Global defaults', section } }
async function settingsSaved() { editor.value = null; pushToast('Settings saved.', 'success'); citiesLoaded.value = new Set(); await refresh() }
async function toggleStatus(item, type) {
  const key = type + item.id
  if (!canManageGeo.value || saving.value.has(key)) return
  saving.value = new Set(saving.value).add(key)
  try {
    const target = item.status === 'active' ? 'inactive' : 'active'
    const res = await (type === 'country' ? updateCountryStatus : updateCityStatus)(item.id, target)
    if (!res.success) throw new Error(res.message || 'Could not save availability.')
    item.status = target
    pushToast(`${item.name} ${target === 'active' ? 'enabled' : 'disabled'}.`, 'success')
  } catch (e) { pushToast(e.message, 'error') }
  finally { saving.value.delete(key); saving.value = new Set(saving.value) }
}
async function applyBulkAvailability() {
  const { country, status } = bulkTarget.value
  bulkSaving.value = true
  try {
    await ensureCities(country.id)
    if (cityErrors.value[country.id]) throw new Error(cityErrors.value[country.id])
    const ids = citiesOf(country.id).filter(c => c.status !== status).map(c => c.id)
    if (ids.length) {
      const res = await bulkUpdateCityStatus(country.id, status, ids)
      if (!res.success) throw new Error(res.message || 'Could not update cities.')
      await ensureCities(country.id, true)
    }
    bulkTarget.value = null
    pushToast('City availability updated.', 'success')
  } catch (e) { pushToast(e.message, 'error') }
  finally { bulkSaving.value = false }
}
async function useGlobalRewards(country) {
  const res = await resetCountryRewards(country.id)
  if (!res.success) return pushToast(res.message || 'Could not reset rewards.', 'error')
  await loadLoyalty(); pushToast(`${country.name} now uses global rewards.`, 'success')
}
/* ── Loyalty programs (per country) ──────────────────────────────────────────
   A country with no saved row falls back to DEFAULT_LOYALTY, so every country
   always has an effective program — saving is what creates the row. */
const loyaltyPrograms = ref({})    // { [country_id]: normalized program }
const loyaltyLoaded = ref(false)

async function loadLoyalty() {
  if (!isSuperAdmin.value) return
  const res = await loadLoyaltyPrograms()
  if (res.error) { pushToast(res.error, 'error'); return }
  globalRewards.value = res.defaults || DEFAULT_LOYALTY
  loyaltyPrograms.value = res.byCountry
  loyaltyTiers.value = (res.tiers || []).filter(t => t.is_active !== false)
  loyaltyLoaded.value = true
}

/** The saved row for a country, or null when it's still on platform defaults. */
const programFor = (countryId) => loyaltyPrograms.value[countryId] || null
/** What actually applies — the saved row, else the platform defaults. */
const effectiveProgram = (countryId) => programFor(countryId) || globalRewards.value

// ── Loyalty tiers ────────────────────────────────────────────────────────────
// Each country's own ladder. A customer's tier is the highest one whose
// threshold their lifetime points reach, so the ladder is per country: what
// counts as "Legend" in one market is not the same spend in another.
function defaultLTier(sortOrder = 0) {
  return { name: '', color: '#92400e', bg_color: '#fef3c7', threshold: 0, multiplier: 1, sort_order: sortOrder }
}
const loyaltyTiers = ref([])       // every tier row the server holds, all countries
const lTiers = ref([])             // the ladder being edited in the modal
function addLTier() { lTiers.value.push({ ...defaultLTier(lTiers.value.length), name: '' }) }
function removeLTier(i) { lTiers.value.splice(i, 1) }

/** This country's ladder, falling back to the presets when it has none yet. */
function tiersForProgram(programId) {
  const mine = loyaltyTiers.value.filter(t => t.program_id === programId)
  const base = mine
  return base
    .slice()
    .sort((a, b) => Number(a.threshold || 0) - Number(b.threshold || 0))
    .map((t, i) => ({
      name: t.name, color: t.color || '#92400e', bg_color: t.bg_color || '#fef3c7',
      threshold: Number(t.threshold || 0), multiplier: Number(t.multiplier ?? 1), sort_order: i,
    }))
}

const loyaltyCountry = ref(null)   // the country whose program is open in the editor
const lForm = ref({ ...DEFAULT_LOYALTY })
const lSaving = ref(false)
const lTriedSave = ref(false), rewardSection = ref('rules'), rewardInitial = ref(''), rewardDiscard = ref(false)
const rewardSnapshot = () => JSON.stringify([lForm.value, lTiers.value])
const rewardDirty = computed(() => rewardSnapshot() !== rewardInitial.value)

function openLoyalty(country) {
  loyaltyCountry.value = country
  lForm.value = { ...effectiveProgram(country.id) }
  lTiers.value = tiersForProgram(programFor(country.id)?.id ?? null)
  lTriedSave.value = false; rewardSection.value = 'rules'; rewardDiscard.value = false; rewardInitial.value = rewardSnapshot()
}
function closeLoyalty() { if (lSaving.value) return; if (rewardDirty.value) rewardDiscard.value = true; else loyaltyCountry.value = null }

async function saveLoyalty() {
  const country = loyaltyCountry.value
  if (!country || lSaving.value) return
  lTriedSave.value = true
  if (!lForm.value.name?.trim()) { pushToast('Program name is required.', 'error'); return }
  for (const [key, min, max] of [['earn_rate', 0, 999999], ['redeem_rate', 1, 2147483647], ['min_redeem', 0, 2147483647], ['max_redeem_pct', 0, 100], ['expiry_months', 0, 600]]) {
    const value = Number(lForm.value[key])
    if (lForm.value[key] === '' || !Number.isFinite(value) || value < min || value > max || (key !== 'earn_rate' && !Number.isInteger(value))) {
      pushToast('Check reward rates, limits and expiry before saving.', 'error'); return
    }
  }

  lSaving.value = true
  let res
  try { res = await saveLoyaltyProgram(country.id, lForm.value, lTiers.value) }
  catch (e) { pushToast(e.message || 'Could not save rewards.', 'error'); return }
  finally { lSaving.value = false }
  if (res.error) { pushToast(res.error, 'error'); return }

  if (country.id === null) globalRewards.value = res.program
  else loyaltyPrograms.value = { ...loyaltyPrograms.value, [country.id]: res.program }
  // Replace this program's tiers with what the server actually stored.
  if (res.tiers) {
    const pid = country.id === null ? null : res.program?.id ?? null
    loyaltyTiers.value = [...loyaltyTiers.value.filter(t => t.program_id !== pid), ...res.tiers]
  }
  logAudit({
    action: AUDIT.UPDATED,
    entity_type: 'loyalty_program',
    entity_id: country.id,
    details: `Saved loyalty program "${res.program.name}" for ${country.name}`,
  })
  pushToast(`Loyalty program saved for ${country.name}.`, 'success')
  loyaltyCountry.value = null
  await loadLoyalty()
}

onMounted(() => { load(1); loadLoyalty() })
</script>

<template>
  <div class="page admin-workspace countries-workspace">
    <div class="page-header"><div><h1 class="page-title">Countries &amp; cities</h1><p class="page-subtitle">Set the defaults. Keep each market in view.</p></div><button class="btn btn-secondary" :disabled="loading" @click="refresh"><RefreshCw :size="15"/> Refresh</button></div>
    <section v-if="globalSettings" class="geo-defaults" aria-label="Global defaults">
      <div class="geo-defaults-head"><div class="geo-title-icon"><Globe2 :size="23"/><div><h2>Global defaults</h2><p>Countries inherit these settings. Country and city overrides take priority.</p></div></div><span class="geo-path">Global <ChevronRight :size="13"/> Country <ChevronRight :size="13"/> City</span></div>
      <div class="geo-policy-grid">
        <section v-for="group in MARKET_GROUPS" :key="group.key" class="geo-policy"><div class="geo-policy-title"><h3>{{ group.short === 'Services' ? 'Available services' : group.label }}</h3><button v-if="isSuperAdmin" class="geo-link" :aria-label="'Edit global ' + group.label.toLowerCase()" @click="openSettings('global', {}, group.key)">Edit <Pencil :size="12"/></button></div><dl><div v-for="field in group.fields" :key="field.key"><dt>{{ field.label }}<small v-if="field.automatic && globalSettings.effective[field.key] == null">{{ field.automatic }}</small></dt><dd :class="{ 'geo-off': field.boolean && !globalSettings.effective[field.key] }">{{ marketValue(globalSettings.effective, field.key) }}</dd></div></dl></section>
        <section class="geo-policy"><div class="geo-policy-title"><h3>Customer rewards</h3><button v-if="isSuperAdmin && loyaltyLoaded" class="geo-link" @click="openLoyalty({ id: null, name: 'Global defaults', currency_code: 'currency unit' })">Edit <Pencil :size="12"/></button></div><template v-if="loyaltyLoaded"><strong class="geo-reward-state">{{ globalRewards.is_active ? 'Enabled' : 'Disabled' }}</strong><p>Earn {{ globalRewards.earn_rate }} point{{ globalRewards.earn_rate === 1 ? '' : 's' }} per currency unit.</p><p>{{ globalRewards.redeem_rate }} points = 1 unit off.</p><small>Shared by all cities in a country.</small></template><p v-else>{{ isSuperAdmin ? 'Loading rewards…' : 'Managed by superadmin' }}</p></section>
      </div>
    </section>
    <div class="geo-directory-heading"><div><h2>Country overview</h2><p>Compare fees, spot overrides, then open a country to manage it.</p></div><span>{{ countryTotal }} matching countries</span></div>
    <div class="geo-toolbar"><label class="geo-search"><Search :size="16"/><input v-model="search" aria-label="Search countries or cities" placeholder="Find a country or city…" /></label><label>Show<select v-model="marketView"><option value="onboarded">Onboarded markets</option><option value="all">All countries</option><option value="overrides">Fee / service overrides</option></select></label><label>Availability<select v-model="statusFilter"><option value="">All statuses</option><option value="active">Enabled</option><option value="inactive">Disabled</option></select></label><label>Sort<select v-model="sort"><option value="venues">Most venues</option><option value="businesses">Most businesses</option><option value="name">Country name</option></select></label></div>
    <p class="geo-table-note">Website percentages show the market default. Automatic rates follow each listing’s marketplace rate.</p>
    <p v-if="loadError" role="alert" class="geo-error">{{ loadError }} <button class="geo-link" @click="load()">Try again</button></p>
    <div v-else class="geo-table" :aria-busy="loading">
      <div class="geo-columns" aria-hidden="true"><span>Country</span><span>Onboarded</span><span>Booking fee</span><span>Website fee · Free / Premium</span><span>Settings source</span><span></span></div>
      <article v-for="country in countries" :key="country.id" class="geo-country" :class="{ expanded: expanded.has(country.id) }">
        <div class="geo-country-row">
          <button class="geo-country-name" :aria-expanded="expanded.has(country.id)" @click="toggle(country.id)"><span class="geo-iso">{{ country.iso_code }}</span><span><strong>{{ country.name }}</strong><small><i :class="{ enabled: country.status === 'active' }"></i>{{ country.status === 'active' ? 'Enabled' : 'Disabled' }} · {{ country.currency_code || '—' }}</small></span></button>
          <div class="geo-footprint"><strong>{{ country.venues_count.toLocaleString() }} <small>venues</small></strong><span>{{ country.businesses_count.toLocaleString() }} businesses</span></div>
          <div class="geo-rate"><small class="geo-mobile-label">Booking fee</small><strong>{{ marketValue(country.settings.effective, 'facility') }}</strong><small>{{ country.settings.sources.facility === 'country' ? 'Country rate' : 'Global default' }}</small></div>
          <div class="geo-rate"><small class="geo-mobile-label">Website · Free / Premium</small><strong>{{ marketValue(country.settings.effective, 'site_free') }} <span>/</span> {{ marketValue(country.settings.effective, 'site_premium') }}</strong><small>{{ country.settings.effective.site_free == null || country.settings.effective.site_premium == null ? 'Includes automatic rate' : 'Fixed website rates' }}</small></div>
          <span class="geo-source" :class="{ custom: overrideCount(country.settings) }">{{ overrideCount(country.settings) ? overrideCount(country.settings) + ' overrides' : 'Global defaults' }}</span>
          <button class="btn btn-secondary btn-sm geo-manage" :aria-label="`Manage ${country.name}`" :aria-expanded="expanded.has(country.id)" @click="toggle(country.id)">{{ expanded.has(country.id) ? 'Close' : 'Manage' }}<ChevronDown :size="14" :class="{ rotated: expanded.has(country.id) }"/></button>
        </div>
        <div v-if="expanded.has(country.id)" class="geo-country-body">
          <div class="geo-country-head"><nav class="geo-tabs" :aria-label="country.name + ' management'"><button v-for="panel in ['settings', 'cities', 'rewards']" :key="panel" :class="{ active: (countryPanels[country.id] || 'settings') === panel }" :aria-pressed="(countryPanels[country.id] || 'settings') === panel" @click="countryPanels[country.id] = panel">{{ panel === 'settings' ? 'Fees & services' : panel === 'cities' ? 'Cities (' + country.cities_count + ')' : 'Rewards' }}</button></nav><button class="geo-status" :class="{ enabled: country.status === 'active' }" :disabled="!canManageGeo || saving.has('country' + country.id)" :aria-label="`${country.name} availability: ${country.status === 'active' ? 'enabled' : 'disabled'}`" :aria-pressed="country.status === 'active'" @click="toggleStatus(country, 'country')">{{ country.status === 'active' ? 'Country enabled' : 'Country disabled' }}<ToggleRight v-if="country.status === 'active'" :size="18"/><ToggleLeft v-else :size="18"/></button></div>
          <template v-if="(countryPanels[country.id] || 'settings') === 'settings'">
            <div class="geo-country-intro"><h3>{{ country.name }} fees &amp; services</h3><p>Each setting shows its source. A country override stays fixed when global defaults change.</p></div>
            <div class="geo-country-policy-grid"><section v-for="group in MARKET_GROUPS" :key="group.key" class="geo-policy"><div class="geo-policy-title"><h3>{{ group.label }}</h3><button v-if="canManageGeo" class="geo-link" :aria-label="'Edit ' + country.name + ' ' + group.label.toLowerCase()" @click="openSettings('country', country, group.key)">Edit <Pencil :size="12"/></button></div><dl><div v-for="field in group.fields" :key="field.key"><dt>{{ field.label }}<small>{{ country.settings.sources[field.key] === 'country' ? 'Country override' : 'From global' }}<template v-if="field.automatic && country.settings.effective[field.key] == null"> · Automatic</template></small></dt><dd>{{ marketValue(country.settings.effective, field.key) }}</dd></div></dl></section></div>
            <p class="geo-context-note"><MapPin :size="14"/>Cities may override these defaults. Open Cities to check their individual settings.</p>
          </template>
          <template v-else-if="countryPanels[country.id] === 'cities'">
            <div class="geo-city-heading"><div><h3>Cities in {{ country.name }}</h3><p>{{ citiesOf(country.id).filter(c => overrideCount(c.settings)).length }} cities have fee or service overrides.</p></div><details v-if="canManageGeo" class="geo-bulk-menu"><summary>Bulk availability</summary><div><button @click="bulkTarget = { country, status: 'active' }">Enable all cities</button><button @click="bulkTarget = { country, status: 'inactive' }">Disable all cities</button></div></details></div>
            <div class="geo-city-filters"><label class="geo-search"><Search :size="15"/><input v-model="citySearch[country.id]" :aria-label="'Search cities in ' + country.name" placeholder="Find a city…" @input="cityLimit[country.id] = 12" /></label><select v-model="cityViews[country.id]" :aria-label="country.name + ' city filter'" @change="cityLimit[country.id] = 12"><option value="all">All cities</option><option value="overrides">With overrides</option><option value="disabled">Disabled cities</option></select></div>
            <p v-if="citiesLoading.has(country.id)" role="status">Loading cities…</p><p v-else-if="cityErrors[country.id]" class="geo-error" role="alert">{{ cityErrors[country.id] }} <button class="geo-link" @click="ensureCities(country.id, true)">Retry</button></p>
            <div v-else class="geo-city-list"><div v-for="city in shownCities(country.id)" :key="city.id" class="geo-city"><div><strong>{{ city.name }}</strong><small>{{ overrideCount(city.settings) ? overrideCount(city.settings) + ' overrides' : 'Uses country defaults' }}</small></div><div class="geo-city-fees"><span>Bookings <b>{{ marketValue(city.settings.effective, 'facility') }}</b></span><span>Website <b>{{ marketValue(city.settings.effective, 'site_free') }} / {{ marketValue(city.settings.effective, 'site_premium') }}</b></span></div><button class="geo-status" :class="{ enabled: city.status === 'active' }" :disabled="!canManageGeo || saving.has('city' + city.id)" :aria-label="`${city.name} availability: ${city.status === 'active' ? 'enabled' : 'disabled'}`" :aria-pressed="city.status === 'active'" @click="toggleStatus(city, 'city')">{{ city.status === 'active' ? 'Enabled' : 'Disabled' }}</button><button v-if="canManageGeo" class="btn btn-secondary btn-sm" :aria-label="'Edit ' + city.name + ' settings'" @click="openSettings('city', city)">Edit</button></div><p v-if="!matchingCities(country.id).length" class="geo-empty">No cities match these filters.</p></div>
            <button v-if="matchingCities(country.id).length > shownCities(country.id).length" class="btn btn-secondary btn-sm geo-more" @click="cityLimit[country.id] = (cityLimit[country.id] || 12) + 24">Show more cities ({{ shownCities(country.id).length }} of {{ matchingCities(country.id).length }})</button>
          </template>
          <section v-else class="geo-rewards-panel"><template v-if="isSuperAdmin && loyaltyLoaded"><div class="geo-policy-title"><div><h3>{{ country.name }} customer rewards</h3><p>{{ programFor(country.id) ? 'This country has its own rewards rules.' : 'Following global rewards. Changes to global rewards apply here.' }}</p></div><button class="btn btn-secondary btn-sm" @click="openLoyalty(country)">Edit rewards</button></div><dl class="geo-reward-facts"><div><dt>Status</dt><dd>{{ effectiveProgram(country.id).is_active ? 'Enabled' : 'Disabled' }}</dd></div><div><dt>Earn per {{ country.currency_code || 'unit' }}</dt><dd>{{ effectiveProgram(country.id).earn_rate }} points</dd></div><div><dt>Redeem {{ country.currency_code || 'unit' }} 1</dt><dd>{{ effectiveProgram(country.id).redeem_rate }} points</dd></div><div><dt>Expiry</dt><dd>{{ effectiveProgram(country.id).expiry_months ? effectiveProgram(country.id).expiry_months + ' months' : 'No expiry' }}</dd></div></dl><p>Rewards apply to marketplace customers and are shared by every city in this country.</p><button v-if="programFor(country.id)" class="geo-link geo-more" @click="useGlobalRewards(country)">Restore global rewards</button></template><p v-else>{{ isSuperAdmin ? 'Loading rewards…' : 'Rewards are managed by the platform superadmin.' }}</p></section>
        </div>
      </article>
      <p v-if="loading" role="status" class="geo-empty">{{ countries.length ? 'Updating country overview…' : 'Loading markets…' }}</p><p v-else-if="!countries.length" class="geo-empty">No countries match these filters. <button class="geo-link" @click="marketView = 'all'; search = ''; statusFilter = ''">Show all countries</button></p>
    </div>
    <PaginationBar v-if="countryTotal > 0" :page="countryPage" :perPage="countryPerPage" :totalPages="countryTotalPages" :total="countryTotal" :visiblePages="countryVisiblePages" @update:perPage="countryPerPage = $event" @goTo="countryGoTo"/>
    <MarketSettingsEditor v-if="editor" :scope="editor.scope" :scope-id="editor.id" :name="editor.name" :initial-section="editor.section" @close="editor = null" @saved="settingsSaved"/>
    <Teleport to="body"><div v-if="bulkTarget" class="ly-backdrop" @click.self="!bulkSaving && (bulkTarget = null)"><section class="ly-modal geo-confirm" v-dialog role="dialog" aria-modal="true" aria-labelledby="geo-bulk-title"><div class="ly-head"><h2 id="geo-bulk-title">{{ bulkTarget.status === 'active' ? 'Enable' : 'Disable' }} all cities in {{ bulkTarget.country.name }}?</h2></div><div class="ly-body"><p>This changes availability for every city in this country, including cities outside the search results. Service overrides and existing bookings stay unchanged.</p></div><div class="ly-foot"><button class="btn btn-secondary" :disabled="bulkSaving" @click="bulkTarget = null">Cancel</button><button class="btn btn-primary" :disabled="bulkSaving" @click="applyBulkAvailability">{{ bulkSaving ? 'Saving…' : 'Confirm availability change' }}</button></div></section></div></Teleport>

    <!-- ── Loyalty program editor (per country) ─────────────────────────── -->
    <Teleport to="body">
      <div v-if="loyaltyCountry" class="ly-backdrop" @click.self="closeLoyalty">
        <div class="ly-modal" v-dialog="closeLoyalty">
          <div class="ly-head">
            <div>
              <div class="ly-title"><Award :size="17" />Loyalty Program — {{ loyaltyCountry.name }}</div>
              <div class="ly-sub">{{ loyaltyCountry.id === null ? 'Shared defaults for every country without its own rewards program' : 'Country rewards · shared by all cities in this country' }}</div>
            </div>
            <div class="ly-head-right">
              <button class="ly-toggle" @click="lForm.is_active = !lForm.is_active" :title="lForm.is_active ? 'Click to deactivate' : 'Click to activate'">
                <ToggleRight v-if="lForm.is_active" :size="22" class="toggle-on" />
                <ToggleLeft v-else :size="22" class="toggle-off" />
                <span>{{ lForm.is_active ? 'Active' : 'Inactive' }}</span>
              </button>
              <button class="ly-x" @click="closeLoyalty" aria-label="Close"><X :size="18" /></button>
            </div>
          </div>

          <nav class="ly-nav" aria-label="Reward settings"><button :class="{ active: rewardSection === 'rules' }" :aria-pressed="rewardSection === 'rules'" @click="rewardSection = 'rules'">Earn &amp; redeem</button><button :class="{ active: rewardSection === 'tiers' }" :aria-pressed="rewardSection === 'tiers'" @click="rewardSection = 'tiers'">Customer tiers ({{ lTiers.length }})</button></nav>
          <div class="ly-body">
            <div v-show="rewardSection === 'rules'" class="ly-card">
              <div class="ly-card-head"><Award :size="15" />Earn &amp; Redeem Rules</div>
              <div class="ly-card-body">
                <div class="form-group">
                  <label class="form-label">Program Name</label>
                  <input v-model="lForm.name" :class="{ 'ly-invalid': lTriedSave && !lForm.name?.trim() }" placeholder="e.g. Bookboll Rewards" />
                  <div v-if="lTriedSave && !lForm.name?.trim()" class="ly-err">Program name is required</div>
                </div>
                <div class="ly-rules-grid">
                  <div class="form-group">
                    <label class="form-label">Points per {{ loyaltyCountry.currency_code || 'unit' }} spent</label>
                    <input type="number" v-model="lForm.earn_rate" min="0" step="0.1" /><div class="ly-hint">New marketplace bookings earn this rate × the customer’s tier multiplier, on the booking total after discounts. Replaces fixed listing points.</div>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Points for {{ loyaltyCountry.currency_code || 'unit' }} 1 discount</label>
                    <input type="number" v-model="lForm.redeem_rate" min="1" />
                    <div class="ly-hint">e.g. 100 = 100 points redeem {{ loyaltyCountry.currency_code || 'unit' }} 1 off. Redemptions come out of Bookboll's fee, never the provider's share.</div>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Minimum points to redeem</label>
                    <input type="number" v-model="lForm.min_redeem" min="0" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Max redeem % of booking</label>
                    <input type="number" v-model="lForm.max_redeem_pct" min="0" max="100" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Points expire after (months, 0 = never)</label>
                    <input type="number" v-model="lForm.expiry_months" min="0" max="600" /><div class="ly-hint">Applies to rewards promised by new bookings. Existing points keep their original expiry.</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Tiers — this country's own ladder -->
            <div v-show="rewardSection === 'tiers'" class="ly-card">
              <div class="ly-card-head">
                <Award :size="15" />Customer reward tiers
                <button class="btn btn-secondary btn-sm" style="margin-left:auto" @click="addLTier"><Plus :size="13" />Add Tier</button>
              </div>
              <div class="ly-card-body">
                <div v-if="!lTiers.length" class="ly-empty-tiers">No tiers yet. Add at least one.</div>
                <div v-for="(t, i) in lTiers" :key="i" class="ly-tier-row">
                  <div class="ly-tier-preview" :style="`background:${t.bg_color}; color:${t.color}`">
                    <Award :size="13" /><span>{{ t.name || '…' }}</span>
                  </div>
                  <div class="ly-tier-fields">
                    <div class="form-group sm"><label class="form-label">Name</label><input v-model="t.name" placeholder="e.g. Legend" /></div>
                    <div class="form-group sm"><label class="form-label">Threshold</label><input type="number" v-model="t.threshold" min="0" /></div>
                    <div class="form-group sm"><label class="form-label">Multiplier</label><input type="number" v-model="t.multiplier" min="0" step="0.1" /></div>
                    <div class="ly-color-pair">
                      <div class="form-group sm"><label class="form-label">Text</label><input type="color" v-model="t.color" class="ly-color-input" /></div>
                      <div class="form-group sm"><label class="form-label">Bg</label><input type="color" v-model="t.bg_color" class="ly-color-input" /></div>
                    </div>
                  </div>
                  <button class="ly-x danger" @click="removeLTier(i)"><Trash2 :size="15" /></button>
                </div>
                <div class="ly-hint">A customer sits in the highest tier their lifetime points reach.</div>
              </div>
            </div>
          </div>

          <div v-if="rewardDiscard" class="ly-discard" role="alert"><strong>Discard your unsaved reward changes?</strong><div><button class="btn btn-secondary btn-sm" @click="rewardDiscard = false">Keep editing</button><button class="btn btn-secondary btn-sm" @click="loyaltyCountry = null">Discard changes</button></div></div>
          <div class="ly-foot">
            <div class="ly-foot-hint">Turning rewards off disables them for this scope. Countries with their own program keep their settings.</div>
            <div style="display:flex; align-items:center; gap:10px;">
              <button class="btn btn-secondary" @click="closeLoyalty">Cancel</button>
              <button class="btn btn-primary" :disabled="lSaving || !rewardDirty" @click="saveLoyalty">
                {{ lSaving ? 'Saving…' : 'Save rewards' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.countries-workspace h2{font-size:18px;margin:0}.countries-workspace h3{font-size:14px;margin:0}.countries-workspace p{font-size:13px;color:var(--text-muted);line-height:1.55;margin:6px 0 0}.geo-defaults,.geo-table{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}.geo-defaults{margin:24px 0 30px}.geo-defaults-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 22px}.geo-title-icon{display:flex;align-items:center;gap:14px}.geo-title-icon>svg{color:var(--primary-ink);flex-shrink:0}.geo-path{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text-muted);white-space:nowrap}.geo-policy-grid{display:grid;grid-template-columns:1.05fr 1.25fr 1.15fr 1fr;border-top:1px solid var(--border)}.geo-policy{padding:20px;border-right:1px solid var(--border);min-width:0}.geo-policy:last-child{border-right:0}.geo-policy-title{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:16px}.geo-policy-title h3{font-size:13px}.geo-policy dl{margin:0}.geo-policy dl>div{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-top:13px;font-size:12px}.geo-policy dt{color:var(--text-muted)}.geo-policy dt small{display:block;font-size:10px;margin-top:4px;line-height:1.4}.geo-policy dd{margin:0;font-weight:650;white-space:nowrap;font-variant-numeric:tabular-nums}.geo-off{color:var(--text-muted)}.geo-policy>small{display:block;font-size:11px;line-height:1.6;color:var(--text-muted);margin-top:10px}.geo-reward-state{font-size:14px}.geo-policy p{font-size:12px}.geo-directory-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.geo-directory-heading>span{font-size:12px;color:var(--text-muted);padding-top:4px}.geo-toolbar{display:flex;align-items:flex-end;gap:12px;margin:20px 0 12px}.geo-toolbar>label:not(.geo-search){font-size:11px;color:var(--text-muted)}.geo-toolbar select{display:block;margin-top:6px;width:100%;min-width:135px}.geo-search{position:relative;display:block;flex:1;min-width:150px}.geo-search>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted)}.geo-search input{width:100%;padding-left:38px}.geo-table-note{font-size:11px!important;margin-bottom:14px!important}.geo-columns,.geo-country-row{display:grid;grid-template-columns:minmax(165px,1.4fr) minmax(85px,.75fr) minmax(80px,.7fr) minmax(145px,1fr) 105px 90px;align-items:center;gap:18px;padding:18px 20px}.geo-columns{font-size:11px;color:var(--text-muted);background:var(--bg);padding-top:12px;padding-bottom:12px}.geo-country+.geo-country{border-top:1px solid var(--border)}.geo-country-name{display:flex;align-items:center;gap:10px;background:none;border:0;color:var(--text);text-align:left;cursor:pointer;padding:0;min-width:0}.geo-country-name strong{font-size:14px}.geo-country-name small,.geo-rate small,.geo-footprint>span{display:block;font-size:11px;color:var(--text-muted);margin-top:5px}.geo-country-name small i{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--text-muted);margin-right:5px}.geo-country-name small i.enabled{background:var(--positive-ink)}.geo-iso{background:var(--bg);border:1px solid var(--border);border-radius:6px;min-width:32px;padding:8px 0;text-align:center;font-size:10px;font-weight:700;flex-shrink:0}.geo-footprint strong,.geo-rate strong{font-size:15px;font-variant-numeric:tabular-nums}.geo-footprint strong small{font-size:11px;font-weight:400}.geo-rate strong>span{font-weight:400;margin:0 6px;color:var(--text-muted)}.geo-rate .geo-mobile-label{display:none}.geo-source{font-size:11px;color:var(--text-muted)}.geo-source.custom{color:var(--primary-ink);font-weight:600}.geo-country.expanded>.geo-country-row{background:var(--primary-soft,var(--bg))}.rotated{transform:rotate(180deg)}.geo-country-body{padding:0 22px 22px;border-top:1px solid var(--border);background:var(--bg)}.geo-country-head{display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--border);padding:12px 0}.geo-tabs{display:flex;gap:5px}.geo-tabs>button{border:0;background:none;color:var(--text-muted);padding:9px 12px;font-size:12px;border-radius:6px;cursor:pointer}.geo-tabs>button.active{color:var(--primary-ink);background:var(--surface);font-weight:650;box-shadow:0 1px 3px #0001}.geo-status{display:inline-flex;gap:8px;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:6px;padding:6px 9px;background:var(--surface);color:var(--text-muted);font-size:11px;cursor:pointer;white-space:nowrap}.geo-status.enabled{color:var(--positive-ink);background:var(--positive-bg);border-color:var(--positive-border,var(--border))}.geo-status:disabled{cursor:default;opacity:.65}.geo-country-intro{padding:20px 0 16px}.geo-country-intro p{font-size:12px}.geo-country-policy-grid{display:grid;grid-template-columns:repeat(3,1fr);background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}.geo-context-note{display:flex;gap:7px;align-items:center;font-size:11px!important;margin-top:14px!important}.geo-link{display:inline-flex;align-items:center;gap:5px;border:0;background:none;padding:0;color:var(--primary-ink);font-size:12px;cursor:pointer;white-space:nowrap}.geo-link:hover{text-decoration:underline}.geo-city-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:20px 0 16px}.geo-city-heading p{font-size:12px}.geo-city-filters{display:flex;gap:12px;margin-bottom:16px;max-width:660px}.geo-city-filters select{width:170px}.geo-city-list{border:1px solid var(--border);border-radius:8px;background:var(--surface);overflow:hidden}.geo-city{display:grid;grid-template-columns:1fr 1fr 90px 60px;gap:16px;align-items:center;padding:15px 18px}.geo-city+.geo-city{border-top:1px solid var(--border)}.geo-city strong{font-size:13px}.geo-city small{display:block;font-size:11px;color:var(--text-muted);margin-top:5px}.geo-city-fees{display:flex;gap:12px;font-size:11px;color:var(--text-muted)}.geo-city-fees b{display:block;font-size:13px;margin-top:5px;color:var(--text)}.geo-bulk-menu{position:relative;font-size:12px;color:var(--text-muted)}.geo-bulk-menu summary{cursor:pointer;padding:8px}.geo-bulk-menu>div{position:absolute;right:0;min-width:180px;padding:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;z-index:2;box-shadow:0 6px 20px #0002}.geo-bulk-menu button{border:0;background:none;color:var(--text);padding:10px;width:100%;text-align:left;cursor:pointer}.geo-bulk-menu button:hover{background:var(--bg)}.geo-more{margin-top:14px}.geo-empty{text-align:center;padding:24px}.geo-error{color:var(--danger-ink)!important;padding:16px}.geo-confirm{max-width:520px!important}.geo-rewards-panel{padding:22px 0 0}.geo-reward-facts{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid var(--border);border-radius:8px;background:var(--surface);margin:18px 0}.geo-reward-facts>div{padding:18px;border-right:1px solid var(--border)}.geo-reward-facts>div:last-child{border:0}.geo-reward-facts dt{font-size:11px;color:var(--text-muted)}.geo-reward-facts dd{margin:8px 0 0;font-size:15px;font-weight:650}
@media(max-width:1150px){.geo-policy-grid{grid-template-columns:1fr 1fr}.geo-policy-grid>.geo-policy:nth-child(2){border-right:0}.geo-policy-grid>.geo-policy:nth-child(n+3){border-top:1px solid var(--border)}.geo-columns,.geo-country-row{gap:12px;grid-template-columns:minmax(155px,1.2fr) 90px 80px minmax(130px,1fr) 85px}.geo-columns>span:nth-child(5),.geo-country-row>.geo-source{display:none}.geo-country-policy-grid{grid-template-columns:1fr 1fr}.geo-country-policy-grid>.geo-policy:nth-child(2){border-right:0}.geo-country-policy-grid>.geo-policy:last-child{grid-column:1/-1;border-top:1px solid var(--border)}.geo-country-policy-grid>.geo-policy:last-child dl{display:flex;gap:24px}.geo-country-policy-grid>.geo-policy:last-child dl>div{flex:1}.geo-path{display:none}.geo-toolbar{flex-wrap:wrap}.geo-toolbar>.geo-search{flex-basis:100%}.geo-toolbar>label:not(.geo-search){flex:1}}
@media(max-width:700px){.geo-defaults-head{padding:18px}.geo-policy-grid,.geo-country-policy-grid{grid-template-columns:1fr}.geo-policy{padding:18px;border-right:0;border-top:1px solid var(--border)}.geo-policy:first-child{border-top:0}.geo-country-policy-grid>.geo-policy:last-child dl{display:block}.geo-columns{display:none}.geo-country-row{grid-template-columns:1fr 1fr;gap:16px;padding:18px}.geo-country-name{grid-column:1}.geo-country-row>.geo-footprint{text-align:right}.geo-country-row>.geo-source{display:block}.geo-rate .geo-mobile-label{display:block;margin-bottom:6px}.geo-manage{justify-self:end}.geo-directory-heading{flex-direction:column;gap:6px}.geo-toolbar select,.geo-toolbar input,.geo-city-filters input,.geo-city-filters select{font-size:16px}.geo-country-body{padding:0 16px 18px}.geo-country-head{flex-direction:column;align-items:flex-start}.geo-tabs{width:100%}.geo-tabs button{flex:1;padding:9px 7px}.geo-city{grid-template-columns:1fr auto;gap:12px;padding:14px}.geo-city-fees{grid-column:1;grid-row:2}.geo-city>.geo-status{grid-column:2;grid-row:1}.geo-city-filters{flex-direction:column}.geo-city-filters select{width:100%}.geo-city-heading{gap:8px}.geo-reward-facts{grid-template-columns:1fr 1fr}.geo-reward-facts>div:nth-child(2){border-right:0}.geo-reward-facts>div:nth-child(n+3){border-top:1px solid var(--border)}.geo-policy-title{align-items:flex-start}.geo-context-note{align-items:flex-start}.geo-context-note svg{flex-shrink:0}.geo-table-note{line-height:1.6}}
.ly-nav{display:flex;gap:8px;padding:12px 22px;border-bottom:1px solid var(--border)}.ly-nav button{border:0;background:none;color:var(--text-muted);padding:9px 14px;border-radius:6px;cursor:pointer}.ly-nav button.active{background:var(--primary-soft,var(--bg));color:var(--primary-ink);font-weight:650}.ly-discard{margin:0 22px 16px;padding:14px;border:1px solid var(--border);background:var(--bg);border-radius:8px;font-size:13px}.ly-discard>div{display:flex;gap:8px;margin-top:10px}

/* ── Loyalty editor modal ─────────────────────────────────────────────────── */
.ly-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: 1000; }
.ly-modal { width: 100%; max-width: 760px; max-height:calc(100dvh - 48px); overflow:hidden; background: var(--surface, #ffffff); border-radius: 16px; box-shadow: 0 24px 60px rgba(0,0,0,.25); display: flex; flex-direction: column; }
.ly-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.ly-title { display: flex; align-items: center; gap: 8px; font-size: 17px; font-weight: 800; color: var(--text); }
.ly-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
.ly-head-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.ly-toggle { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 600; background: none; border: none; color: var(--text); }
.toggle-on { color: var(--positive-ink, #10b981); }
.toggle-off { color: var(--text-light, #94a3b8); }
.ly-x { width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.ly-x:hover { background: var(--border, #e2e8f0); }
.ly-x.danger { background: transparent; color: var(--danger-ink, #dc2626); width: auto; height: auto; padding: 6px; }
.ly-x.danger:hover { background: var(--danger-bg, #fef2f2); }
.ly-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; min-height:0; }
.ly-card { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.ly-card-head { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 700; color: var(--text); background: var(--surface-hover, #f8fafc); }
.ly-card-body { padding: 16px; }
.ly-rules-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
@media (max-width: 640px) { .ly-rules-grid { grid-template-columns: 1fr; } }
.ly-invalid { border-color: #ef4444 !important; }
.ly-err { font-size: 12px; color: var(--danger-ink, #dc2626); margin-top: 4px; }
.ly-hint { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 4px; line-height: 1.45; }
.ly-empty-tiers { text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; background: var(--surface-hover, #fafafa); border-radius: 8px; }
.ly-tier-row { display: flex; align-items: flex-start; gap: 10px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.ly-tier-row:last-child { border-bottom: none; }
.ly-tier-preview { min-width: 88px; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; justify-content: center; flex-shrink: 0; margin-top: 22px; }
.ly-tier-fields { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.ly-color-pair { display: flex; gap: 10px; align-items: flex-end; }
.form-group.sm { margin-bottom: 8px; }
.ly-color-input { width: 52px; height: 32px; padding: 0; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; }
.ly-foot { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 22px; border-top: 1px solid var(--border); }
.ly-foot-hint { font-size: 12px; color: var(--text-muted); max-width: 320px; line-height: 1.4; }

/* ── Phone (≤600px) — mobile-only adjustments, desktop untouched ─────────── */
@media (max-width: 600px) {
  /* iOS zoom guard: all interactive text inputs ≥16px */
  .search-input,
  .commission-input,
  .ly-modal input { font-size: 16px; }

  /* Country header row: tighter gaps, ellipsize long names so pills never overflow */
  .country-row { gap: 8px; padding: 12px 12px; }
  .country-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  /* Commission fields: stack full-width with comfortable tap targets */
  .commission-row { flex-direction: column; align-items: stretch; gap: 12px; }
  .commission-field { width: 100%; }
  .commission-input-wrap { width: 100%; }
  .commission-input { width: 100%; min-height: 44px; }

  /* City module pills: readable size + bigger tap target */
  /* Finger-sized module toggles + bulk links (these are the page's most-tapped controls) */
  .mod-pill { font-size: 12px; padding: 9px 12px; min-height: 38px; }
  .link-btn { padding: 10px 12px; min-height: 40px; display: inline-flex; align-items: center; }

  /* Enable all / Disable all: real tap targets */
  .link-btn { padding: 8px 4px; font-size: 13px; }

  /* Loyalty editor modal → full-screen bottom sheet; content scrolls inside body */
  .ly-backdrop { padding: 0; align-items: flex-end; overflow-y: hidden; }
  .ly-modal { max-width: none; height: 100dvh; max-height: 100dvh; border-radius: 0; }
  .ly-head { padding: 14px 16px; }
  .ly-title { font-size: 16px; }
  .ly-body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px 16px; }

  /* Tier rows: wrap — preview pill + delete on line 1, fields full-width below */
  .ly-tier-row { flex-wrap: wrap; }
  .ly-tier-preview { margin-top: 0; }
  .ly-tier-row .ly-x.danger { order: 1; margin-left: auto; }
  .ly-tier-fields { grid-template-columns: 1fr; min-width: 0; flex: 1 1 100%; order: 2; }

  /* Footer: stack hint under full-width button row */
  .ly-foot { flex-direction: column-reverse; align-items: stretch; gap: 10px; padding: 14px 16px; }
  .ly-foot > div:last-child { width: 100%; }
  .ly-foot .btn { flex: 1; min-height: 44px; }
  .ly-foot-hint { max-width: none; }
}

/* Very narrow phones (≤400px): drop the currency pill, keep the city count */
@media (max-width: 400px) {
  .country-meta .meta-pill:first-child { display: none; }
  .country-meta .meta-pill.city-count { display: inline-flex; }
}

</style>
