<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { Globe2, MapPin, ChevronDown, Search, Check, Percent, Award, X, ToggleLeft, ToggleRight, Plus, Trash2, RefreshCw, Settings2 } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import MarketSettingsEditor from '../components/MarketSettingsEditor.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { fetchGeographyCountries, fetchGeographyCities, updateCountryStatus, updateCityStatus, bulkUpdateCityStatus, resetCountryRewards } from '../lib/geography.js'
import { DEFAULT_LOYALTY, loadLoyaltyPrograms, saveLoyaltyProgram } from '../lib/loyalty.js'
const { isSuperAdmin, isCountryAdmin } = useCurrentUser()
const canManageGeo = computed(() => isSuperAdmin.value || isCountryAdmin.value)
const countries = ref([]), globalSettings = ref(null), globalRewards = ref(DEFAULT_LOYALTY)
const loading = ref(true), loadError = ref(''), search = ref(''), statusFilter = ref(''), sort = ref('venues')
const expanded = ref(new Set()), saving = ref(new Set()), savedRecently = ref(new Set())
const citiesByCountry = ref({}), citiesLoaded = ref(new Set()), citiesLoading = ref(new Set())
const citySearch = ref({}), cityLimit = ref({}), editor = ref(null), bulkSaving = ref(false), bulkTarget = ref(null)
const countryPage = ref(1), countryPerPage = ref(25), countryTotal = ref(0)
const countryTotalPages = computed(() => Math.max(1, Math.ceil(countryTotal.value / countryPerPage.value)))
const countryVisiblePages = computed(() => Array.from({ length: countryTotalPages.value }, (_, i) => i + 1).filter(p => p === 1 || p === countryTotalPages.value || Math.abs(p - countryPage.value) <= 1))
const fees = [{ key: 'facility', label: 'Venue bookings' }, { key: 'program', label: 'Memberships' }, { key: 'event', label: 'Event seats' }]
const websiteFees = [{ key: 'site_free', label: 'Free plan' }, { key: 'site_premium', label: 'Paid Premium' }]
function websiteRateLabel(settings, key) {
  return settings.effective[key] == null ? (key === 'site_premium' ? 'Half the marketplace rate' : 'Match marketplace rate') : settings.effective[key] + '%'
}
let loadSeq = 0, searchTimer
async function load(page = countryPage.value) {
  const seq = ++loadSeq
  loading.value = true; loadError.value = ''
  try {
    const res = await fetchGeographyCountries({ page, records_per_page: countryPerPage.value, search: search.value.trim() || undefined, status: statusFilter.value || undefined, sort: sort.value })
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
watch([statusFilter, sort, countryPerPage], () => load(1))
onBeforeUnmount(() => { clearTimeout(searchTimer); loadSeq++ })
function countryGoTo(page) { load(page) }
function citiesOf(id) { return citiesByCountry.value[id] || [] }
function matchingCities(id) { const q = (citySearch.value[id] || '').toLowerCase(); return citiesOf(id).filter(c => c.name.toLowerCase().includes(q)) }
function shownCities(id) { return matchingCities(id).slice(0, cityLimit.value[id] || 12) }
async function ensureCities(id, force = false) {
  if ((!force && citiesLoaded.value.has(id)) || citiesLoading.value.has(id)) return
  citiesLoading.value = new Set(citiesLoading.value).add(id)
  try {
    const res = await fetchGeographyCities(id)
    if (!res.success) throw new Error(res.message || 'Could not load cities.')
    citiesByCountry.value[id] = res.data.cities || []
    citiesLoaded.value = new Set(citiesLoaded.value).add(id)
  } catch (e) { pushToast(e.message, 'error') }
  finally { citiesLoading.value.delete(id); citiesLoading.value = new Set(citiesLoading.value) }
}
function toggle(id) { expanded.value = expanded.value.has(id) ? new Set() : new Set([id]); if (expanded.value.has(id)) ensureCities(id) }
async function refresh() { await load(); for (const id of expanded.value) await ensureCities(id, true); await loadLoyalty() }
function openSettings(scope, item = {}) { editor.value = { scope, id: Number(item.id || 0), name: item.name || 'Global defaults' } }
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
function overrideLabel(item) { const count = Object.keys(item.settings?.overrides || {}).length; return count ? `${count} custom setting${count === 1 ? '' : 's'}` : 'Uses parent defaults' }
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

function loyaltySummary(country) {
  const p = effectiveProgram(country.id)
  const cur = country.currency_code || 'unit'
  return `${p.earn_rate} pt per ${cur} · ${p.redeem_rate} pts = ${cur} 1 off`
}

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
const lTriedSave = ref(false)

function openLoyalty(country) {
  loyaltyCountry.value = country
  lForm.value = { ...effectiveProgram(country.id) }
  lTiers.value = tiersForProgram(programFor(country.id)?.id ?? null)
  lTriedSave.value = false
}
function closeLoyalty() { loyaltyCountry.value = null }

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
  const res = await saveLoyaltyProgram(country.id, lForm.value, lTiers.value)
  lSaving.value = false
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
  closeLoyalty()
  await loadLoyalty()
}

/** Summary-card toggle: flips is_active on the saved row, creating it if needed. */
async function toggleLoyaltyActive(country) {
  const current = effectiveProgram(country.id)
  const res = await saveLoyaltyProgram(country.id, { ...current, is_active: !current.is_active })
  if (res.error) { pushToast(res.error, 'error'); return }
  if (country.id === null) globalRewards.value = res.program
  else loyaltyPrograms.value = { ...loyaltyPrograms.value, [country.id]: res.program }
  logAudit({
    action: res.program.is_active ? AUDIT.ENABLED : AUDIT.DISABLED,
    entity_type: 'loyalty_program',
    entity_id: country.id,
    details: `Loyalty program ${res.program.is_active ? 'activated' : 'deactivated'} for ${country.name}`,
  })
}


onMounted(() => { load(1); loadLoyalty() })
</script>

<template>
  <div class="page admin-workspace countries-workspace">
    <div class="page-header"><div><h1 class="page-title">Countries &amp; cities</h1><p class="page-subtitle">Manage market availability, platform fees and rewards.</p></div><button class="btn btn-secondary" :disabled="loading" @click="refresh"><RefreshCw :size="15"/> Refresh</button></div>
    <section v-if="globalSettings" class="geo-defaults" aria-label="Global defaults">
      <div class="geo-defaults-head"><div class="geo-title-icon"><Globe2 :size="22"/><div><h2>Global defaults</h2><p>Global → country → city. More specific settings take priority.</p></div></div><button v-if="isSuperAdmin" class="btn btn-primary" @click="openSettings('global')"><Settings2 :size="15"/> Edit defaults</button></div>
      <div class="geo-default-values"><div v-for="fee in fees" :key="fee.key"><span>{{ fee.label }}</span><strong>{{ globalSettings.effective[fee.key] }}<small>%</small></strong></div><div class="geo-rewards-default"><span>Country rewards</span><strong>{{ globalRewards.is_active ? 'Enabled' : 'Disabled' }}</strong><button v-if="isSuperAdmin && loyaltyLoaded" class="geo-link" @click="openLoyalty({ id: null, name: 'Global defaults', currency_code: 'currency unit' })">Edit rewards <Award :size="13"/></button></div></div>
      <div class="geo-website-defaults"><strong>Provider website fees</strong><span v-for="fee in websiteFees" :key="fee.key">{{ fee.label }} <b>{{ websiteRateLabel(globalSettings, fee.key) }}</b></span></div>
      <p class="geo-inheritance-note">Existing country rates and city switches are kept as overrides. Choose “Use parent default” to follow changes here. Rewards are shared by all cities within each country.</p>
    </section>
    <div class="geo-toolbar"><label class="geo-search"><Search :size="16"/><input v-model="search" aria-label="Search countries or cities" placeholder="Search countries or cities…" /></label><label>Availability<select v-model="statusFilter"><option value="">All countries</option><option value="active">Enabled</option><option value="inactive">Disabled</option></select></label><label>Sort by<select v-model="sort"><option value="venues">Most venues first</option><option value="businesses">Most businesses first</option><option value="name">Country name</option></select></label></div>
    <div class="geo-list-caption"><strong>{{ countryTotal }} countries</strong><span>Venue counts include all onboarded locations. Businesses count once per country where registered or operating.</span></div>
    <p v-if="loadError" role="alert" class="geo-error">{{ loadError }} <button class="geo-link" @click="load()">Try again</button></p>
    <div v-else class="geo-table" :aria-busy="loading">
      <div class="geo-columns" aria-hidden="true"><span>Country</span><span>Venues</span><span>Businesses</span><span>Availability</span><span></span></div>
      <article v-for="country in countries" :key="country.id" class="geo-country" :class="{ expanded: expanded.has(country.id) }">
        <div class="geo-country-row">
          <button class="geo-country-name" :aria-expanded="expanded.has(country.id)" @click="toggle(country.id)"><span class="geo-iso">{{ country.iso_code }}</span><span><strong>{{ country.name }}</strong><small>{{ country.currency_code || '—' }} · {{ country.cities_count.toLocaleString() }} cities</small></span></button>
          <div class="geo-count"><strong>{{ country.venues_count.toLocaleString() }}</strong><small>venues</small></div><div class="geo-count"><strong>{{ country.businesses_count.toLocaleString() }}</strong><small>businesses</small></div>
          <button class="geo-status" :class="{ enabled: country.status === 'active' }" :disabled="!canManageGeo || saving.has('country' + country.id)" :aria-label="`${country.name} availability: ${country.status === 'active' ? 'enabled' : 'disabled'}`" :aria-pressed="country.status === 'active'" @click="toggleStatus(country, 'country')"><span></span>{{ country.status === 'active' ? 'Enabled' : 'Disabled' }}</button>
          <button class="btn btn-secondary btn-sm geo-manage" :aria-label="`Manage ${country.name}`" :aria-expanded="expanded.has(country.id)" @click="toggle(country.id)">Manage <ChevronDown :size="14" :class="{ rotated: expanded.has(country.id) }"/></button>
        </div>
        <div v-if="expanded.has(country.id)" class="geo-country-body">
          <div class="geo-country-settings"><div><h3>{{ country.name }} settings <small>{{ overrideLabel(country) }}</small></h3><p><span v-for="fee in fees" :key="fee.key">{{ fee.label }} <b>{{ country.settings.effective[fee.key] }}%</b><small> · {{ country.settings.sources[fee.key] }}</small></span></p><p><span v-for="fee in websiteFees" :key="fee.key">Website {{ fee.label }} <b>{{ websiteRateLabel(country.settings, fee.key) }}</b><small> · {{ country.settings.sources[fee.key] }}</small></span></p></div><button v-if="canManageGeo" class="btn btn-secondary btn-sm" @click="openSettings('country', country)">Edit country settings</button></div>
          <div v-if="isSuperAdmin && loyaltyLoaded" class="geo-rewards-row"><div><h3>Rewards <small>{{ programFor(country.id) ? 'Country override' : 'Global default' }}</small></h3><p>{{ effectiveProgram(country.id).is_active ? loyaltySummary(country) : 'Rewards disabled' }}</p></div><div class="geo-actions"><button v-if="programFor(country.id)" class="geo-link" @click="useGlobalRewards(country)">Use global rewards</button><button class="btn btn-secondary btn-sm" @click="openLoyalty(country)">Edit rewards</button></div></div>
          <div class="geo-city-heading"><div><h3>Cities in {{ country.name }}</h3><p>Availability controls location pickers. Service settings control what can be added.</p></div><div v-if="canManageGeo" class="geo-actions"><button class="geo-link" :disabled="citiesLoading.has(country.id)" @click="bulkTarget = { country, status: 'active' }">Enable all</button><button class="geo-link" :disabled="citiesLoading.has(country.id)" @click="bulkTarget = { country, status: 'inactive' }">Disable all</button></div></div>
          <label class="geo-search geo-city-search"><Search :size="15"/><input v-model="citySearch[country.id]" :aria-label="'Search cities in ' + country.name" placeholder="Find a city…" @input="cityLimit[country.id] = 12" /></label>
          <p v-if="citiesLoading.has(country.id)" role="status">Loading cities…</p>
          <div v-else class="geo-city-list"><div v-for="city in shownCities(country.id)" :key="city.id" class="geo-city"><div><strong>{{ city.name }}</strong><small>{{ overrideLabel(city) }} · Bookings {{ city.settings.effective.facility }}% · Events {{ city.settings.effective.event }}%</small></div><button class="geo-status" :class="{ enabled: city.status === 'active' }" :disabled="!canManageGeo || saving.has('city' + city.id)" :aria-label="`${city.name} availability: ${city.status === 'active' ? 'enabled' : 'disabled'}`" :aria-pressed="city.status === 'active'" @click="toggleStatus(city, 'city')"><span></span>{{ city.status === 'active' ? 'Enabled' : 'Disabled' }}</button><button v-if="canManageGeo" class="btn btn-secondary btn-sm" :aria-label="'Edit ' + city.name + ' settings'" @click="openSettings('city', city)">Settings</button></div><p v-if="!matchingCities(country.id).length" class="geo-empty">No cities match this search.</p></div>
          <button v-if="matchingCities(country.id).length > shownCities(country.id).length" class="btn btn-secondary btn-sm geo-more" @click="cityLimit[country.id] = (cityLimit[country.id] || 12) + 24">Show more cities ({{ shownCities(country.id).length }} of {{ matchingCities(country.id).length }})</button>
        </div>
      </article>
      <p v-if="loading && !countries.length" role="status" class="geo-empty">Loading markets…</p><p v-else-if="!loading && !countries.length" class="geo-empty">No countries match these filters.</p>
    </div>
    <PaginationBar v-if="countryTotal > 0" :page="countryPage" :perPage="countryPerPage" :totalPages="countryTotalPages" :total="countryTotal" :visiblePages="countryVisiblePages" @update:perPage="countryPerPage = $event" @goTo="countryGoTo"/>
    <MarketSettingsEditor v-if="editor" :scope="editor.scope" :scope-id="editor.id" :name="editor.name" @close="editor = null" @saved="settingsSaved"/>
    <Teleport to="body"><div v-if="bulkTarget" class="ly-backdrop" @click.self="!bulkSaving && (bulkTarget = null)"><section class="ly-modal geo-confirm" v-dialog role="dialog" aria-modal="true" aria-labelledby="geo-bulk-title"><div class="ly-head"><h2 id="geo-bulk-title">{{ bulkTarget.status === 'active' ? 'Enable' : 'Disable' }} all cities in {{ bulkTarget.country.name }}?</h2></div><div class="ly-body"><p>This changes availability for every city in this country, including cities outside the search results. Service overrides and existing bookings stay unchanged.</p></div><div class="ly-foot"><button class="btn btn-secondary" :disabled="bulkSaving" @click="bulkTarget = null">Cancel</button><button class="btn btn-primary" :disabled="bulkSaving" @click="applyBulkAvailability">{{ bulkSaving ? 'Saving…' : 'Confirm availability change' }}</button></div></section></div></Teleport>
    <!-- ── Loyalty program editor (per country) ─────────────────────────── -->
    <Teleport to="body">
      <div v-if="loyaltyCountry" class="ly-backdrop" @click.self="closeLoyalty">
        <div class="ly-modal" v-dialog>
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

          <div class="ly-body">
            <div class="ly-card">
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
            <div class="ly-card">
              <div class="ly-card-head">
                <Award :size="15" />Membership Tiers
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

          <div class="ly-foot">
            <div class="ly-foot-hint">Turning rewards off disables them for this scope. Countries with their own program keep their settings.</div>
            <div style="display:flex; align-items:center; gap:10px;">
              <button class="btn btn-secondary" @click="closeLoyalty">Cancel</button>
              <button class="btn btn-primary" :disabled="lSaving" @click="saveLoyalty">
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
.geo-website-defaults{display:flex;align-items:baseline;flex-wrap:wrap;gap:12px 28px;padding:16px 24px;border-top:1px solid var(--border);font-size:13px}.geo-website-defaults>strong{font-size:14px}.geo-website-defaults span{color:var(--text-muted)}.geo-website-defaults b{display:block;color:var(--text);font-weight:600;margin-top:4px}
.geo-defaults,.geo-table{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden}.geo-defaults{margin:24px 0}.geo-defaults-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px}.geo-title-icon{display:flex;align-items:center;gap:14px}.geo-title-icon>svg{color:var(--primary-ink);flex-shrink:0}h2{font-size:18px;margin:0}h3{font-size:14px;margin:0}p{font-size:13px;color:var(--text-muted);line-height:1.55;margin:5px 0 0}.geo-default-values{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--border)}.geo-default-values>div{padding:16px 24px;border-right:1px solid var(--border);position:relative}.geo-default-values>div:last-child{border-right:0}.geo-default-values span{display:block;font-size:12px;color:var(--text-muted);margin-bottom:6px}.geo-default-values strong{font-size:24px;line-height:1.2}.geo-default-values strong small{font-size:16px;margin-left:3px}.geo-rewards-default strong{font-size:18px}.geo-rewards-default .geo-link{display:flex;margin-top:8px;gap:5px;align-items:center}.geo-inheritance-note{padding:12px 24px;background:var(--bg);border-top:1px solid var(--border);margin:0;font-size:12px}.geo-toolbar{display:flex;align-items:flex-end;gap:12px;margin:26px 0 16px}.geo-toolbar>label:not(.geo-search){font-size:12px;color:var(--text-muted);min-width:165px}.geo-toolbar select{display:block;margin-top:6px;width:100%}.geo-search{position:relative;display:block;flex:1}.geo-search>svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted)}.geo-search input{width:100%;padding-left:38px}.geo-list-caption{display:flex;gap:15px;justify-content:space-between;align-items:center;margin:12px 0;font-size:12px;color:var(--text-muted)}.geo-list-caption strong{white-space:nowrap;color:var(--text)}.geo-columns,.geo-country-row{display:grid;grid-template-columns:minmax(190px,1fr) 85px 95px 108px 102px;align-items:center;gap:16px;padding:16px 20px}.geo-columns{font-size:11px;color:var(--text-muted);background:var(--bg);padding-top:12px;padding-bottom:12px}.geo-country+.geo-country{border-top:1px solid var(--border)}.geo-country-name{display:flex;align-items:center;gap:12px;background:none;border:0;color:var(--text);text-align:left;cursor:pointer;padding:0}.geo-country-name strong{font-size:14px}.geo-country-name small,.geo-city small{display:block;font-size:12px;color:var(--text-muted);margin-top:4px}.geo-iso{background:var(--bg);border:1px solid var(--border);border-radius:6px;width:36px;padding:8px 0;text-align:center;font-size:11px;font-weight:700;flex-shrink:0}.geo-count strong{font-size:17px;font-variant-numeric:tabular-nums}.geo-count small{display:none}.geo-status{display:inline-flex;gap:7px;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:6px;padding:7px 8px;background:var(--bg);color:var(--text-muted);font-size:12px;cursor:pointer}.geo-status span{width:6px;height:6px;border-radius:50%;background:currentColor}.geo-status.enabled{color:var(--positive-ink);background:var(--positive-bg);border-color:var(--positive-border,var(--border))}.geo-status:disabled{cursor:default;opacity:.65}.geo-country.expanded>.geo-country-row{background:var(--primary-soft,var(--bg))}.rotated{transform:rotate(180deg)}.geo-country-body{padding:20px 24px 24px;border-top:1px solid var(--border);background:var(--bg)}.geo-country-settings,.geo-rewards-row,.geo-city-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.geo-country-settings h3 small,.geo-rewards-row h3 small{font-size:11px;color:var(--text-muted);font-weight:400;margin-left:8px}.geo-country-settings p{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;margin-top:8px}.geo-country-settings p small{font-size:11px}.geo-rewards-row{padding:18px 0;margin:16px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}.geo-actions{display:flex;align-items:center;gap:14px}.geo-link{border:0;background:none;padding:0;color:var(--primary-ink);font-size:12px;text-decoration:underline;cursor:pointer}.geo-city-heading{margin-top:24px}.geo-city-search{max-width:400px;margin:14px 0}.geo-city-list{border:1px solid var(--border);border-radius:8px;background:var(--surface);overflow:hidden}.geo-city{display:grid;grid-template-columns:1fr 105px 78px;gap:16px;align-items:center;padding:13px 16px}.geo-city+.geo-city{border-top:1px solid var(--border)}.geo-city strong{font-size:13px}.geo-more{margin-top:12px}.geo-empty{text-align:center;padding:24px}.geo-error{color:var(--danger-ink);padding:18px}.geo-confirm{max-width:520px}.geo-confirm h2{font-size:18px}.geo-confirm p{margin:0}
@media(max-width:900px){.geo-columns,.geo-country-row{grid-template-columns:minmax(160px,1fr) 60px 75px 98px 90px;gap:10px;padding:14px}.geo-default-values>div{padding:14px}.geo-list-caption{align-items:flex-start;flex-direction:column;gap:4px}.geo-country-settings{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){.geo-defaults-head{align-items:flex-start;flex-direction:column;padding:18px}.geo-default-values{grid-template-columns:1fr 1fr}.geo-default-values>div{border-bottom:1px solid var(--border)}.geo-toolbar{flex-wrap:wrap}.geo-search{flex-basis:100%}.geo-toolbar>label:not(.geo-search){flex:1;min-width:130px}.geo-toolbar input,.geo-toolbar select{font-size:16px}.geo-columns{display:none}.geo-country-row{grid-template-columns:1fr 1fr;gap:12px;padding:16px}.geo-country-name{grid-column:1/-1}.geo-count small{display:inline;margin-left:5px;font-size:12px;color:var(--text-muted)}.geo-manage{justify-self:end}.geo-status{min-height:36px}.geo-country-body{padding:16px}.geo-country-settings p{gap:6px;flex-direction:column}.geo-rewards-row,.geo-city-heading{flex-direction:column;align-items:flex-start}.geo-city{grid-template-columns:1fr auto;padding:12px;gap:10px}.geo-city>div{grid-column:1/-1}.geo-city-search{max-width:none}.geo-defaults .btn{width:100%}}
/* ── Loyalty editor modal ─────────────────────────────────────────────────── */
.ly-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; z-index: 1000; overflow-y: auto; }
.ly-modal { width: 100%; max-width: 760px; background: var(--surface, #ffffff); border-radius: 16px; box-shadow: 0 24px 60px rgba(0,0,0,.25); display: flex; flex-direction: column; }
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
.ly-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
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
