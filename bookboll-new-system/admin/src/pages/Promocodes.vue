<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import {
  Search, Plus, Ticket, Trash2, Pencil, X, Copy, Check,
  ShieldCheck, Store, AlertCircle, BarChart3,
} from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import EmptyState from '../components/EmptyState.vue'
import FilterBar from '../components/FilterBar.vue'
import MultiSelect from '../components/MultiSelect.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { usePremium } from '../lib/premium.js'
import { CAP } from '../lib/permissions.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import {
  loadRedemptions, AUDIENCE_LABEL,
  fetchPromocodes, fetchPromocodeOptions,
  createPromocode, updatePromocode, deletePromocode, firstError,
} from '../lib/promocodes.js'

const { isSuperAdmin, isCountryAdmin, role: actorRole, isProviderScoped, currentOwnerUserId, currentCountryId,
  assignedCountryIds, assignedLocationIds, currentUser, can } = useCurrentUser()
const isPlatformAdmin = computed(() => isSuperAdmin.value || isCountryAdmin.value || actorRole.value === 'platform_staff')
const canManagePlatformPromos = computed(() => can(CAP.MANAGE_PROMOCODES_PLATFORM))
const canManageProviderPromos = computed(() => can(CAP.MANAGE_PROMOCODES))
const canManagePromos = computed(() => canManagePlatformPromos.value || canManageProviderPromos.value)
const { isPremium, requirePremium } = usePremium()
const canManageCode = (c) => c.recipient === 'bookboll' ? canManagePlatformPromos.value : canManageProviderPromos.value

const codes     = ref([])
const locations = ref([])
const listings  = ref([])
const programs  = ref([])
const customers = ref([])
const countries = ref([])
const cities    = ref([])
const businessOwners = ref([])
const loading   = ref(true)
const custSearch = ref('')
const search    = ref('')
const filterTab = ref('all')
const filterCountry = ref([])
const filterCity    = ref([])
const showModal = ref(false)
const editing   = ref(null)
const saving    = ref(false)
const formError = ref('')
const triedSave = ref(false)
const form      = ref(defaultForm())
const copied    = ref(null)

const promoPage = ref(1)
const promoPerPage = ref(25)
const promoTotal = ref(0)
const promoTotalPages = computed(() => Math.max(1, Math.ceil(promoTotal.value / promoPerPage.value) || 1))
const promoVisiblePages = computed(() => {
  const total = promoTotalPages.value
  const cur = promoPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  return Array.from(pages).sort((a, b) => a - b)
})

async function requestClose() {
  if (await confirmDiscardIfDirty()) showModal.value = false
}

function defaultForm() {
  return {
    code:           '',
    description:    '',
    discount_type:  'percentage',
    discount_value: 10,
    min_order:      0,
    usage_limit:    100,
    status:         'active',
    valid_from:     new Date().toISOString().slice(0, 10),
    valid_to:       '',
    // Architecture fields. Platform admins default to a Bookboll-fee code only if they
    // can manage those; otherwise a provider-share code.
    recipient:      isPlatformAdmin.value ? (canManagePlatformPromos.value ? 'bookboll' : 'provider') : 'provider',
    creator_role:   isPlatformAdmin.value ? actorRole.value : 'provider',
    owner_user_id:  isProviderScoped.value ? currentOwnerUserId.value : null,
    applicable_to:  'all',
    // Multi-scope (arrays). Empty = "any" on that dimension.
    owner_user_ids: [],   // businesses / providers (platform admins)
    location_ids:   [],   // locations
    listing_ids:    [],   // venue listings
    program_ids:    [],   // membership programs
    // Targeting
    audience:           'all',   // 'all' | 'new' | 'specific'
    target_emails:      [],      // selected customer emails (searchable picker)
    per_customer_limit: 0,       // 0 = unlimited per customer
  }
}

// Provider-plan promocodes: a super-admin-only discount on a business's Bookboll
// Premium upgrade ($59/mo). Always a Bookboll-fee code; booking scope doesn't apply.
const isProviderPlan = computed(() => form.value.applicable_to === 'provider_plan')
const applicableOptions = computed(() => {
  const base = [
    { value: 'all',      label: 'All',              desc: 'Bookings and memberships' },
    { value: 'facility', label: 'Bookings only',    desc: 'Venue bookings — courts, spaces, hourly rentals' },
    { value: 'program',  label: 'Memberships only', desc: 'Membership programs only' },
  ]
  if (isPlatformAdmin.value && canManagePlatformPromos.value)
    base.push({ value: 'provider_plan', label: 'Provider Premium plan', desc: 'Discount a business upgrading to Bookboll Premium ($59/mo)' })
  return base
})
watch(() => form.value.applicable_to, (v) => {
  if (v === 'provider_plan') {
    form.value.recipient = 'bookboll'                 // always a Bookboll-fee discount
    form.value.owner_user_ids = []; form.value.location_ids = []
    form.value.listing_ids = []; form.value.program_ids = []
  }
})

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadOptions() {
  const res = await fetchPromocodeOptions()
  if (!res.success) {
    pushToast(res.message || 'Could not load promocode options.', 'error')
    return
  }
  const d = res.data || {}
  locations.value = d.locations || []
  listings.value  = d.listings || []
  programs.value  = d.programs || []
  customers.value = d.customers || []
  countries.value = d.countries || []
  cities.value    = d.cities || []
  businessOwners.value = d.businesses || []
}

async function load(page = promoPage.value) {
  loading.value = true
  const res = await fetchPromocodes({
    page,
    records_per_page: promoPerPage.value,
    search: search.value.trim() || undefined,
    recipient: filterTab.value === 'all' ? undefined : filterTab.value,
    country_ids: filterCountry.value.length ? filterCountry.value : undefined,
    city_ids: filterCity.value.length ? filterCity.value : undefined,
  })
  loading.value = false
  if (!res.success) {
    pushToast(res.message || 'Could not load promocodes.', 'error')
    codes.value = []
    promoTotal.value = 0
    return
  }
  codes.value = res.data?.promocodes || []
  const pagination = res.data?.pagination || {}
  promoTotal.value = Number(pagination.total ?? codes.value.length) || 0
  if (pagination.current_page) promoPage.value = Number(pagination.current_page)
}

let searchTimer
function scheduleLoad() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    promoPage.value = 1
    load(1)
  }, 300)
}

onMounted(async () => {
  await loadOptions()
  await load(1)
})
watch(() => currentUser.value?.id, async () => {
  await loadOptions()
  promoPage.value = 1
  await load(1)
})
watch(search, () => scheduleLoad())
watch([filterTab, filterCountry, filterCity, promoPerPage], () => {
  promoPage.value = 1
  load(1)
})
function promoGoTo(p) {
  promoPage.value = p
  load(p)
}

// ── Jurisdiction (platform roles see only their countries / assigned venues) ──
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null
  const assigned = (assignedCountryIds.value || []).filter(Boolean)
  if (assigned.length) return new Set(assigned)
  if (currentCountryId.value) return new Set([currentCountryId.value])
  return new Set()
})
function ownerCountryId(ownerId) {
  return businessOwners.value.find(u => String(u.id) === String(ownerId))?.country_id || null
}
function locationCountryId(locId) {
  const loc = locations.value.find(l => l.id === locId)
  const city = loc && cities.value.find(c => c.id === loc.city_id)
  return city?.country_id || null
}
// The country a code belongs to: its location's country, else its owner's country, else
// null (a global / platform-wide code).
function codeCountryId(c) {
  if (c.location_id) return locationCountryId(c.location_id)
  if (c.owner_user_id) return ownerCountryId(c.owner_user_id)
  return null
}
function codeInJurisdiction(c) {
  if (isSuperAdmin.value) return true
  if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
    const owners = new Set(locations.value.filter(l => assignedLocationIds.value.includes(l.id)).map(l => l.owner_user_id))
    return (c.location_id && assignedLocationIds.value.includes(c.location_id)) ||
           (c.owner_user_id && owners.has(c.owner_user_id)) || !codeCountryId(c)
  }
  const set = jurisdictionCountryIds.value
  if (!set) return true
  const cid = codeCountryId(c)
  return cid ? set.has(cid) : true   // global codes visible to platform admins
}

// ── Computed: locations available to scope a code to ──────────────────────────
// Super admin → all; other platform admins → locations in their jurisdiction;
// providers → their own.
const myLocations = computed(() => {
  if (isSuperAdmin.value) return locations.value
  if (isPlatformAdmin.value) {
    if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
      return locations.value.filter(l => assignedLocationIds.value.includes(l.id))
    }
    const set = jurisdictionCountryIds.value
    if (!set) return locations.value
    return locations.value.filter(l => set.has(locationCountryId(l.id)))
  }
  if (!currentOwnerUserId.value) return []
  return locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value)
})

// Locations for the currently-selected owner_user_id in the platform-admin form
const scopedLocations = computed(() => {
  if (!isPlatformAdmin.value) return myLocations.value
  if (!form.value.owner_user_id) return myLocations.value
  return myLocations.value.filter(l => l.owner_user_id === form.value.owner_user_id)
})
// Business owners the platform user may scope a code to (jurisdiction-limited).
const scopableOwners = computed(() => {
  if (isSuperAdmin.value) return businessOwners.value
  const set = jurisdictionCountryIds.value
  let list = businessOwners.value
  if (set) list = list.filter(o => set.has(o.country_id) || set.has(String(o.country_id)) || set.has(Number(o.country_id)))
  if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
    const owners = new Set(locations.value.filter(l => assignedLocationIds.value.includes(l.id)).map(l => l.owner_user_id))
    list = businessOwners.value.filter(o => owners.has(o.id) || owners.has(String(o.id)) || owners.has(Number(o.id)))
  }
  return list
})

// ── Multi-scope option lists (each narrows by the selection above it) ──────────
const ownerOptions = computed(() => scopableOwners.value.map(o => ({ value: o.id, label: o.full_name || o.username || 'Provider' })))
// Locations: providers → their own; platform → jurisdiction, narrowed to the picked businesses.
const scopeLocationsList = computed(() => {
  let list = myLocations.value
  if (isPlatformAdmin.value && form.value.owner_user_ids?.length) {
    const set = new Set(form.value.owner_user_ids)
    list = list.filter(l => set.has(l.owner_user_id))
  }
  return list
})
const locationOptions = computed(() => scopeLocationsList.value.map(l => ({ value: l.id, label: l.name })))
// Listings / programs: scoped to the same owner set, narrowed further by picked locations.
function scopeItems(items) {
  let list = items
  if (!isPlatformAdmin.value && currentOwnerUserId.value) list = list.filter(i => i.owner_user_id === currentOwnerUserId.value)
  else if (form.value.owner_user_ids?.length) { const s = new Set(form.value.owner_user_ids); list = list.filter(i => s.has(i.owner_user_id)) }
  if (form.value.location_ids?.length) { const s = new Set(form.value.location_ids); list = list.filter(i => s.has(i.location_id)) }
  return list
}
const listingOptions = computed(() => scopeItems(listings.value).map(l => ({ value: l.id, label: l.name })))
const programOptions = computed(() => scopeItems(programs.value).map(p => ({ value: p.id, label: p.name })))

// Searchable customer picker: matches on name/email, excludes already-picked, capped for perf.
const customerMatches = computed(() => {
  const q = custSearch.value.trim().toLowerCase()
  const chosen = new Set((form.value.target_emails || []).map(e => e.toLowerCase()))
  let list = customers.value.filter(c => c.email && !chosen.has(c.email.toLowerCase()))
  if (q) list = list.filter(c => (c.email || '').toLowerCase().includes(q) || (c.full_name || '').toLowerCase().includes(q))
  return list.slice(0, 8)
})
function addEmail(email) {
  const e = (email || '').trim().toLowerCase()
  if (!e) return
  if (!form.value.target_emails.includes(e)) form.value.target_emails.push(e)
  custSearch.value = ''
  markDirty()
}
function addTypedEmail() {
  const e = custSearch.value.trim().toLowerCase()
  if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)) addEmail(e)
}
function removeEmail(email) {
  form.value.target_emails = form.value.target_emails.filter(e => e !== email)
  markDirty()
}
function customerNameForEmail(email) {
  const c = customers.value.find(x => (x.email || '').toLowerCase() === (email || '').toLowerCase())
  return c?.full_name || ''
}

// ── Filtering ─────────────────────────────────────────────────────────────────
watch(() => (form.value.owner_user_ids || []).join(','), () => {
  if (!isPlatformAdmin.value) return
  const locOk = new Set(scopeLocationsList.value.map(l => l.id))
  form.value.location_ids = (form.value.location_ids || []).filter(id => locOk.has(id))
  const liOk = new Set(scopeItems(listings.value).map(i => i.id))
  form.value.listing_ids = (form.value.listing_ids || []).filter(id => liOk.has(id))
  const pgOk = new Set(scopeItems(programs.value).map(i => i.id))
  form.value.program_ids = (form.value.program_ids || []).filter(id => pgOk.has(id))
})

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openNew() {
  editing.value   = null
  form.value      = defaultForm()
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openEdit(c) {
  editing.value   = c
  form.value      = {
    code:          c.code           ?? '',
    description:   c.description    ?? '',
    discount_type: c.discount_type  ?? 'percentage',
    discount_value: c.discount_value ?? 10,
    min_order:     c.min_order      ?? 0,
    usage_limit:   c.usage_limit    ?? 100,
    status:        c.status         ?? 'active',
    valid_from:    c.valid_from     ?? '',
    valid_to:      c.valid_to       ?? '',
    recipient:     c.recipient      ?? 'bookboll',
    creator_role:  c.creator_role   ?? 'super_admin',
    owner_user_id: c.owner_user_id  ?? null,
    applicable_to: c.applicable_to  ?? 'all',
    // Hydrate arrays; fall back to legacy single columns for pre-migration codes.
    owner_user_ids: (Array.isArray(c.owner_user_ids) && c.owner_user_ids.length) ? [...c.owner_user_ids] : (c.owner_user_id ? [c.owner_user_id] : []),
    location_ids:   (Array.isArray(c.location_ids) && c.location_ids.length) ? [...c.location_ids] : (c.location_id ? [c.location_id] : []),
    listing_ids:    Array.isArray(c.listing_ids) ? [...c.listing_ids] : [],
    program_ids:    Array.isArray(c.program_ids) ? [...c.program_ids] : [],
    audience:           c.audience  ?? 'all',
    target_emails:      Array.isArray(c.target_emails) ? [...c.target_emails] : [],
    per_customer_limit: c.per_customer_limit ?? 0,
  }
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}

async function save() {
  formError.value = ''
  triedSave.value = true
  if (!form.value.code.trim())              { formError.value = 'Code is required.'; return }
  if (!form.value.discount_value || Number(form.value.discount_value) <= 0)
                                            { formError.value = 'Discount value must be greater than 0.'; return }
  if (form.value.discount_type === 'percentage' && Number(form.value.discount_value) > 100)
                                            { formError.value = 'Percentage cannot exceed 100%.'; return }

  // Provider codes discount the price before percentage fees; fixed deposits stay fixed. A Premium-plan code is a
  // Bookboll-fee discount (it reduces what a business pays Bookboll for its subscription).
  const recipient    = form.value.applicable_to === 'provider_plan'
    ? 'bookboll'
    : (isPlatformAdmin.value ? form.value.recipient : 'provider')
  const creator_role = isPlatformAdmin.value ? actorRole.value : 'provider'
  // Creator ownership (legacy single column): providers → their id; platform → the single
  // scoped business if exactly one, else null (a global / multi-business code).
  const owner_user_id = isPlatformAdmin.value
    ? ((form.value.owner_user_ids || []).length === 1 ? form.value.owner_user_ids[0] : null)
    : currentOwnerUserId.value
  // Fee-type capability guard: Bookboll-fee codes need the platform cap, provider-share
  // codes need the provider cap.
  if (recipient === 'bookboll' && !canManagePlatformPromos.value) {
    formError.value = "You don't have permission to create Bookboll-fee codes."; return
  }
  if (recipient === 'provider' && !canManageProviderPromos.value) {
    formError.value = "You don't have permission to create provider-share codes."; return
  }

  const payload = {
    code:           form.value.code.toUpperCase().trim(),
    description:    form.value.description.trim(),
    discount_type:  form.value.discount_type,
    discount_value: Number(form.value.discount_value),
    min_order:      Number(form.value.min_order) || 0,
    usage_limit:    Number(form.value.usage_limit) || 0,
    status:         form.value.status,
    valid_from:     form.value.valid_from || null,
    valid_to:       form.value.valid_to   || null,
    recipient,
    creator_role,
    owner_user_id,
    applicable_to:  form.value.applicable_to,
    // Multi-scope arrays (the new source of truth) + legacy singles kept in sync (first id)
    // so existing display / geo-filter / jurisdiction keep working.
    owner_user_ids: isPlatformAdmin.value ? [...(form.value.owner_user_ids || [])] : [],
    location_ids:   [...(form.value.location_ids || [])],
    listing_ids:    [...(form.value.listing_ids || [])],
    program_ids:    [...(form.value.program_ids || [])],
    location_id:    (form.value.location_ids || [])[0] || null,
    audience:       form.value.audience || 'all',
    target_emails:  form.value.audience === 'specific'
      ? [...new Set((form.value.target_emails || []).map(e => String(e).trim().toLowerCase()).filter(Boolean))]
      : [],
    per_customer_limit: Number(form.value.per_customer_limit) || 0,
  }

  saving.value = true
  const res = editing.value
    ? await updatePromocode(editing.value.id, payload)
    : await createPromocode(payload)
  saving.value = false

  if (!res.success) {
    formError.value = firstError(res)
    pushToast('Could not save promocode.', 'error')
    return
  }
  clearDirty()
  logAudit({
    action: editing.value ? AUDIT.UPDATED : AUDIT.CREATED,
    entity_type: 'promocode', entity_id: editing.value?.id || payload.code,
    details: `${editing.value ? 'Updated' : 'Created'} ${recipient === 'bookboll' ? 'Bookboll-fee' : 'provider-share'} promocode ${payload.code} (${payload.discount_type === 'percentage' ? payload.discount_value + '%' : payload.discount_value} off)`,
  })
  pushToast(editing.value ? 'Promocode updated' : 'Promocode created', 'success')
  showModal.value = false
  load()
}

async function remove(id) {
  if (!(await askConfirm({
    title: 'Delete promocode?',
    message: 'This promocode will be permanently removed and can no longer be redeemed.',
    confirmText: 'Delete',
  }))) return
  const codeStr = codes.value.find(c => c.id === id)?.code || id
  const res = await deletePromocode(id)
  if (!res.success) { pushToast(res.message || 'Could not delete promocode.', 'error'); return }
  logAudit({ action: AUDIT.DELETED, entity_type: 'promocode', entity_id: id, details: `Deleted promocode ${codeStr}` })
  pushToast('Promocode deleted', 'success')
  load()
}

function copy(code) {
  navigator.clipboard.writeText(code)
  copied.value = code
  setTimeout(() => { if (copied.value === code) copied.value = null }, 1800)
}

// ── Usage / redemption tracking drawer ─────────────────────────────────────────
const usageCode = ref(null)
const usageRows = ref([])
const usageLoading = ref(false)
async function openUsage(c) {
  usageCode.value = c; usageRows.value = []; usageLoading.value = true
  usageRows.value = await loadRedemptions(c.id)
  usageLoading.value = false
}
function closeUsage() { usageCode.value = null }
const usageTotalDiscount = computed(() => usageRows.value.reduce((s, r) => s + Number(r.discount_amount || 0), 0))
const usageTotalOrders = computed(() => usageRows.value.reduce((s, r) => s + Number(r.order_amount || 0), 0))
const usageCurrency = computed(() => usageRows.value[0]?.currency || '')
function audienceLabel(a) { return AUDIENCE_LABEL[a] || 'Everyone' }
function fmtDateTime(d) { return d ? new Date(d).toLocaleString() : '—' }

// ── Display helpers ───────────────────────────────────────────────────────────
function recipientLabel(r)  {
  return r === 'bookboll' ? 'Bookboll Fee' : "Provider's Share"
}
function applicableLabel(a) {
  return { all: 'All', facility: 'Bookings only', program: 'Memberships only', provider_plan: 'Premium plan' }[a] || a
}
function providerName(ownerId) {
  if (!ownerId) return 'Super Admin (global)'
  const o = businessOwners.value.find(u => String(u.id) === String(ownerId))
  return o ? (o.full_name || o.username || 'Provider') : 'Provider'
}
function locationName(locId) {
  if (!locId) return null
  return locations.value.find(l => l.id === locId)?.name || null
}
function listingName(id) { return listings.value.find(l => l.id === id)?.name || null }
function programName(id) { return programs.value.find(p => p.id === id)?.name || null }
// Compact scope summary for a code card (multi-scope aware, with legacy fallback).
function scopeSummary(c) {
  const out = []
  const owners = (c.owner_user_ids?.length ? c.owner_user_ids : (c.owner_user_id ? [c.owner_user_id] : []))
  const locs = (c.location_ids?.length ? c.location_ids : (c.location_id ? [c.location_id] : []))
  if (owners.length === 1) out.push(providerName(owners[0]))
  else if (owners.length > 1) out.push(`${owners.length} businesses`)
  if (locs.length === 1) out.push(`📍 ${locationName(locs[0]) || 'Location'}`)
  else if (locs.length > 1) out.push(`📍 ${locs.length} locations`)
  if (c.listing_ids?.length) out.push(`🎾 ${c.listing_ids.length === 1 ? (listingName(c.listing_ids[0]) || 'Listing') : c.listing_ids.length + ' listings'}`)
  if (c.program_ids?.length) out.push(`🎟️ ${c.program_ids.length === 1 ? (programName(c.program_ids[0]) || 'Program') : c.program_ids.length + ' programs'}`)
  return out
}
function isExpired(c) {
  return c.valid_to && new Date(c.valid_to + 'T23:59:59') < new Date()
}
function usagePercent(c) {
  if (!c.usage_limit) return 0
  return Math.min(100, Math.round((c.usage_count / c.usage_limit) * 100))
}
</script>

<template>
  <div class="page admin-workspace promos-workspace">
    <div class="page-header">
      <div>
        <h1 class="page-title">Promo codes</h1>
        <div class="page-subtitle">
          {{ isPlatformAdmin ? 'Platform-wide and provider discount codes' : 'Create discount codes and track how customers use them.' }}
        </div>
      </div>
      <button v-if="canManagePromos" class="btn btn-primary" @click="requirePremium('promocodes') && openNew()"><Plus :size="16" />New promo code<span v-if="!isPremium" class="prem-lock">Premium</span></button>
    </div>

    <!-- Toolbar -->
    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="countries"
      :cities="cities"
      :extra-count="filterTab !== 'all' ? 1 : 0"
      search-placeholder="Search codes…"
      @clear="filterTab = 'all'"
    >
      <div v-if="isPlatformAdmin" class="lf-row">
        <span class="lf-label">Type</span>
        <div class="lf-chips">
          <button type="button" :class="{ on: filterTab === 'all' }" @click="filterTab = 'all'">All</button>
          <button type="button" :class="{ on: filterTab === 'bookboll' }" @click="filterTab = 'bookboll'"><ShieldCheck :size="13" />Bookboll Fee</button>
          <button type="button" :class="{ on: filterTab === 'provider' }" @click="filterTab = 'provider'"><Store :size="13" />Provider Share</button>
        </div>
      </div>
    </FilterBar>

    <div v-if="isPlatformAdmin" class="promo-type-tabs" aria-label="Discount funding"><button :aria-pressed="filterTab === 'all'" @click="filterTab = 'all'">All codes</button><button :aria-pressed="filterTab === 'bookboll'" @click="filterTab = 'bookboll'"><ShieldCheck :size="16" />Bookboll fee</button><button :aria-pressed="filterTab === 'provider'" @click="filterTab = 'provider'"><Store :size="16" />Provider discount</button></div>
    <!-- Architecture info banner (platform admins) -->
    <div v-if="isPlatformAdmin" class="arch-banner">
      <div><ShieldCheck :size="17" class="arch-icon bookboll" /><span><strong>Bookboll fee</strong>Discount funded by Bookboll’s commission.</span></div>
      <div><Store :size="17" class="arch-icon provider" /><span><strong>Provider discount</strong>Booking price reduced first; percentage fee follows the new price. Fixed deposits stay fixed.</span></div>
    </div>

    <div class="workspace-section-heading"><h2>Discount codes</h2><span>{{ loading ? 'Loading…' : `${promoTotal} results` }}</span></div>
    <!-- Cards -->
    <div class="promo-grid">
      <div
        v-for="c in codes" :key="c.id"
        class="promo-card"
        :class="c.recipient === 'bookboll' ? 'card-bookboll' : 'card-provider'"
      >
        <!-- Top row: recipient badge + status -->
        <div class="pc-top">
          <div class="pc-recipient" :class="c.recipient">
            <ShieldCheck v-if="c.recipient === 'bookboll'" :size="12" />
            <Store       v-else                            :size="12" />
            {{ recipientLabel(c.recipient) }}
          </div>
          <span
            class="badge"
            :class="isExpired(c) ? 'badge-error' : c.status === 'active' ? 'badge-success' : 'badge-neutral'"
          >{{ isExpired(c) ? 'Expired' : c.status }}</span>
        </div>

        <!-- Code -->
        <button type="button" class="pc-code" :aria-label="`Copy code ${c.code}`" @click="copy(c.code)" :title="copied === c.code ? 'Copied!' : 'Click to copy'">
          <span>{{ c.code }}</span>
          <Check v-if="copied === c.code" :size="14" class="copy-check" />
          <Copy v-else :size="14" />
        </button>

        <!-- Description -->
        <div v-if="c.description" class="pc-desc">{{ c.description }}</div>

        <!-- Discount value -->
        <div class="pc-discount">
          {{ c.discount_type === 'percentage' ? `${c.discount_value}% OFF` : `${c.discount_value} OFF` }}
        </div>

        <!-- Scope pills -->
        <div class="pc-pills">
          <span class="pill pill-scope">{{ applicableLabel(c.applicable_to) }}</span>
          <span v-if="c.audience && c.audience !== 'all'" class="pill pill-audience">{{ audienceLabel(c.audience) }}</span>
          <span v-for="s in scopeSummary(c)" :key="s" class="pill pill-location">{{ s }}</span>
        </div>

        <!-- Creator -->
        <div class="pc-creator">
          by {{ providerName(c.owner_user_id) }}
        </div>

        <!-- Usage bar -->
        <div class="pc-usage-wrap">
          <div class="pc-usage-label">
            <span>Used</span>
            <strong>{{ c.usage_count }}{{ c.usage_limit ? ` / ${c.usage_limit}` : '' }}</strong>
          </div>
          <div v-if="c.usage_limit" class="pc-usage-bar">
            <div class="pc-usage-fill" :style="{ width: usagePercent(c) + '%', background: usagePercent(c) >= 90 ? '#ef4444' : 'var(--primary)' }"></div>
          </div>
        </div>

        <!-- Meta row -->
        <div class="pc-meta">
          <div v-if="c.min_order"><span>Min order</span><strong>{{ c.min_order }}</strong></div>
          <div v-if="c.valid_from || c.valid_to">
            <span>Valid</span>
            <strong>{{ c.valid_from || '—' }} → {{ c.valid_to || '∞' }}</strong>
          </div>
        </div>

        <!-- Actions -->
        <div class="pc-footer">
          <button class="btn btn-secondary btn-sm" @click="openUsage(c)" title="View redemptions"><BarChart3 :size="13" />Usage</button>
          <button v-if="canManageCode(c)" class="btn btn-secondary btn-sm" @click="openEdit(c)"><Pencil :size="13" />Edit</button>
          <button v-if="canManageCode(c)" class="btn btn-sm btn-danger"    @click="remove(c.id)"><Trash2 :size="13" /></button>
          <span v-if="!canManageCode(c)" class="muted" style="font-size:12px">View only</span>
        </div>
      </div>

      <div v-if="loading" class="card" style="grid-column:1/-1">
        <EmptyState :icon="Ticket" title="Loading promocodes…" message="Fetching discount codes from the server." compact />
      </div>
      <div v-else-if="!codes.length" class="card" style="grid-column:1/-1">
        <EmptyState
          v-if="!search && filterTab === 'all' && !filterCountry.length && !filterCity.length"
          :icon="Ticket"
          title="No promo codes yet"
          message="Create your first discount code to run a promotion for your customers."
          :action-label="canManagePromos ? 'New promo code' : ''"
          @action="openNew"
        />
        <EmptyState
          v-else
          :icon="Search"
          title="No matching promocodes"
          message="No codes match your search or filter. Try clearing them."
          compact
        />
      </div>
    </div>

    <PaginationBar
      v-if="!loading && promoTotal > 0"
      :page="promoPage" :perPage="promoPerPage" :totalPages="promoTotalPages"
      :total="promoTotal" :visiblePages="promoVisiblePages"
      @update:perPage="promoPerPage = $event"
      @goTo="promoGoTo"
    />

    <!-- ── Modal ────────────────────────────────────────────────────────────── -->
    <teleport to="body">
    <div v-if="showModal" class="bb-drawer-backdrop" style="z-index:3000" @click.self="requestClose">
      <div class="bb-drawer" v-dialog="requestClose" @input="markDirty" @change="markDirty">
        <div class="bb-drawer-header">
          <div>
            <div class="bb-drawer-title">{{ editing ? 'Edit promo code' : 'New promo code' }}</div>
            <div class="bb-drawer-subtitle">Set the discount, what it applies to, and who can use it</div>
          </div>
          <button class="bb-drawer-close" @click="requestClose" aria-label="Close"><X :size="18" /></button>
        </div>

        <div class="bb-drawer-body">

          <!-- ── Basic ─────────────────────────────────────────────────── -->
          <div class="form-section-label">Basic Information</div>
          <div class="grid grid-2">
            <div class="form-group">
              <label class="form-label">Code <span class="req">*</span></label>
              <input v-model="form.code" class="code-input" :class="{ 'input-invalid': triedSave && !form.code.trim() }" placeholder="e.g. SUMMER20" />
              <div v-if="triedSave && !form.code.trim()" class="field-error-text"><AlertCircle :size="12" />Code is required</div>
              <div class="form-hint">Customers enter this at checkout. Auto-uppercased.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Status</label>
              <select v-model="form.status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <input v-model="form.description" placeholder="Internal note — not shown to customers" />
          </div>

          <!-- ── Discount ───────────────────────────────────────────────── -->
          <div class="form-section-label" style="margin-top:18px">Discount</div>
          <div class="grid grid-3">
            <div class="form-group">
              <label class="form-label">Type</label>
              <select v-model="form.discount_type">
                <option value="percentage">Percentage (%)</option>
                <option value="fixed">Fixed Amount</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Value <span class="req">*</span></label>
              <input type="number" v-model="form.discount_value" :class="{ 'input-invalid': triedSave && (!form.discount_value || Number(form.discount_value) <= 0) }" min="0.01" :max="form.discount_type === 'percentage' ? 100 : undefined" step="0.01" />
              <div v-if="triedSave && (!form.discount_value || Number(form.discount_value) <= 0)" class="field-error-text"><AlertCircle :size="12" />Enter a value greater than 0</div>
            </div>
            <div class="form-group">
              <label class="form-label">Min Order Value</label>
              <input type="number" v-model="form.min_order" min="0" step="0.01" placeholder="0 = no minimum" />
            </div>
          </div>

          <!-- ── Usage & Validity ───────────────────────────────────────── -->
          <div class="form-section-label" style="margin-top:18px">Usage & Validity</div>
          <div class="grid grid-3">
            <div class="form-group">
              <label class="form-label">Usage Limit</label>
              <input type="number" v-model="form.usage_limit" min="0" placeholder="0 = unlimited" />
            </div>
            <div class="form-group">
              <label class="form-label">Valid From</label>
              <input type="date" v-model="form.valid_from" />
            </div>
            <div class="form-group">
              <label class="form-label">Valid To</label>
              <input type="date" v-model="form.valid_to" />
            </div>
          </div>

          <!-- ── Recipient & Scope ──────────────────────────────────────── -->
          <div class="form-section-label" style="margin-top:18px">Recipient & Scope</div>

          <!-- Recipient selector — platform admins; each option needs the matching cap.
               Premium-plan codes are always Bookboll-fee, so the picker is hidden for them. -->
          <div v-if="isPlatformAdmin && !isProviderPlan" class="recipient-picker">
            <button
              v-if="canManagePlatformPromos"
              class="recipient-btn"
              :class="{ active: form.recipient === 'bookboll' }"
              @click="form.recipient = 'bookboll'"
              type="button"
            >
              <div class="rb-icon bookboll"><ShieldCheck :size="18" /></div>
              <div class="rb-text">
                <div class="rb-title">Bookboll Platform Fee</div>
                <div class="rb-desc">Bookboll absorbs this discount from its commission. Protects provider revenue.</div>
              </div>
              <div class="rb-check"><Check :size="14" /></div>
            </button>
            <button
              v-if="canManageProviderPromos"
              class="recipient-btn"
              :class="{ active: form.recipient === 'provider' }"
              @click="form.recipient = 'provider'"
              type="button"
            >
              <div class="rb-icon provider"><Store :size="18" /></div>
              <div class="rb-text">
                <div class="rb-title">Provider discount</div>
                <div class="rb-desc">Discount the booking price first, then calculate Bookboll's percentage. Fixed deposits stay fixed.</div>
              </div>
              <div class="rb-check"><Check :size="14" /></div>
            </button>
          </div>

          <!-- Provider role: show read-only label -->
          <div v-else-if="!isProviderPlan" class="recipient-note">
            <Store :size="14" />
            Your code reduces the booking price first. Bookboll's percentage fee uses that discounted price. A fixed deposit stays fixed; the discount cannot exceed the remaining venue share.
          </div>

          <!-- Applicable To -->
          <div class="form-group" style="margin-top:14px">
            <label class="form-label">Applicable To</label>
            <div class="applicable-picker">
              <label class="ap-option" v-for="opt in applicableOptions" :key="opt.value">
                <input type="radio" v-model="form.applicable_to" :value="opt.value" />
                <div class="ap-label">
                  <span class="ap-title">{{ opt.label }}</span>
                  <span class="ap-desc">{{ opt.desc }}</span>
                </div>
              </label>
            </div>
            <div v-if="isProviderPlan" class="recipient-note" style="margin-top:10px">
              <Store :size="14" />
              This code is applied when a business <strong>upgrades to Bookboll Premium</strong> (US$588/year). It discounts <strong>Bookboll's subscription fee</strong> — no venue scope applies.
            </div>
          </div>

          <!-- ── Targeting ─────────────────────────────────────────────── -->
          <div class="form-section-label" style="margin-top:18px">Targeting</div>
          <div class="form-group">
            <label class="form-label">Who can use this code</label>
            <div class="applicable-picker">
              <label class="ap-option" v-for="opt in [
                { value:'all',      label:'Everyone',           desc:'Any customer can redeem' },
                { value:'new',      label:'New customers',      desc:'First-time customers only' },
                { value:'specific', label:'Specific customers', desc:'Only the emails you list' },
              ]" :key="opt.value">
                <input type="radio" v-model="form.audience" :value="opt.value" />
                <div class="ap-label">
                  <span class="ap-title">{{ opt.label }}</span>
                  <span class="ap-desc">{{ opt.desc }}</span>
                </div>
              </label>
            </div>
          </div>
          <div v-if="form.audience === 'specific'" class="form-group">
            <label class="form-label">Allowed customers</label>
            <div class="cust-picker">
              <!-- selected chips -->
              <div v-if="form.target_emails.length" class="cust-chips">
                <span v-for="em in form.target_emails" :key="em" class="cust-chip">
                  <span class="cust-chip-txt">{{ customerNameForEmail(em) || em }}<em v-if="customerNameForEmail(em)"> · {{ em }}</em></span>
                  <button type="button" @click="removeEmail(em)" :aria-label="`Remove ${em}`"><X :size="12" /></button>
                </span>
              </div>
              <!-- search box -->
              <div class="cust-search">
                <Search :size="14" />
                <input v-model="custSearch" placeholder="Search customers by name or email…" @keydown.enter.prevent="addTypedEmail" />
              </div>
              <!-- matches dropdown -->
              <div v-if="custSearch.trim()" class="cust-matches">
                <button v-for="c in customerMatches" :key="c.id" type="button" class="cust-match" @click="addEmail(c.email)">
                  <span class="cust-match-name">{{ c.full_name || c.email }}</span>
                  <span class="cust-match-email">{{ c.email }}</span>
                </button>
                <div v-if="!customerMatches.length" class="cust-none">
                  No customer found. <button type="button" v-if="/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(custSearch.trim())" class="cust-add-raw" @click="addTypedEmail">Add “{{ custSearch.trim().toLowerCase() }}”</button>
                </div>
              </div>
            </div>
            <div class="form-hint">Search &amp; pick customers, or type an email and press Enter. Matched against the email on their booking / membership.</div>
          </div>
          <div class="grid grid-2">
            <div class="form-group">
              <label class="form-label">Max uses per customer <span class="form-opt">optional</span></label>
              <input type="number" v-model="form.per_customer_limit" min="0" placeholder="0 = unlimited" />
            </div>
          </div>

          <!-- Scope — each dropdown is multi-select; leave empty for "any". Each narrows the next.
               (Hidden for Premium-plan codes, which target the subscription, not venues.) -->
          <template v-if="!isProviderPlan">
          <div class="form-section-label" style="margin-top:18px">Scope <span class="form-opt" style="text-transform:none;letter-spacing:0">optional — leave empty to apply everywhere</span></div>
          <div class="grid grid-2">
            <div v-if="isPlatformAdmin" class="form-group">
              <label class="form-label">Businesses</label>
              <MultiSelect v-model="form.owner_user_ids" :options="ownerOptions" placeholder="All businesses" />
              <div class="form-hint">Redeemable only at the selected businesses.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Locations</label>
              <MultiSelect v-model="form.location_ids" :options="locationOptions" placeholder="Any location" />
              <div class="form-hint">Limit to specific venues/locations.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Listings</label>
              <MultiSelect v-model="form.listing_ids" :options="listingOptions" placeholder="All listings" />
              <div class="form-hint">Limit to specific court/venue listings.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Membership programs</label>
              <MultiSelect v-model="form.program_ids" :options="programOptions" placeholder="All programs" />
              <div class="form-hint">Limit to specific membership programs.</div>
            </div>
          </div>
          </template>

          <!-- Error -->
          <div v-if="formError" class="form-error">
            <AlertCircle :size="14" />{{ formError }}
          </div>
        </div>

        <div class="bb-drawer-footer">
          <button class="btn btn-secondary" @click="requestClose">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">
            {{ saving ? 'Saving…' : editing ? 'Save Changes' : 'Create Promocode' }}
          </button>
        </div>
      </div>
    </div>
    </teleport>

    <!-- ── Usage / redemptions drawer ─────────────────────────────────────── -->
    <div v-if="usageCode" class="modal-backdrop" @click.self="closeUsage">
      <div class="modal modal-lg" v-dialog>
        <div class="modal-header">
          <div class="modal-title"><BarChart3 :size="18" />Usage — <span class="mono" style="font-family:monospace">{{ usageCode.code }}</span></div>
          <button class="btn-icon" @click="closeUsage" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="usage-stats">
            <div class="usage-stat"><div class="us-val">{{ usageCode.usage_count || 0 }}<span v-if="usageCode.usage_limit"> / {{ usageCode.usage_limit }}</span></div><div class="us-lbl">Redemptions</div></div>
            <div class="usage-stat"><div class="us-val">{{ usageCurrency }} {{ usageTotalDiscount.toLocaleString() }}</div><div class="us-lbl">Total discount given</div></div>
            <div class="usage-stat"><div class="us-val">{{ usageCurrency }} {{ usageTotalOrders.toLocaleString() }}</div><div class="us-lbl">Orders driven</div></div>
            <div class="usage-stat"><div class="us-val">{{ usageCode.recipient === 'bookboll' ? 'Bookboll' : 'Provider' }}</div><div class="us-lbl">Absorbed by</div></div>
          </div>

          <div v-if="usageLoading" class="muted" style="padding:18px;text-align:center">Loading…</div>
          <div v-else-if="!usageRows.length" class="muted" style="padding:18px;text-align:center">No redemptions yet.</div>
          <div v-else class="table-wrap">
            <table class="usage-table">
              <thead><tr><th>When</th><th>Customer</th><th>On</th><th style="text-align:right">Order</th><th style="text-align:right">Discount</th></tr></thead>
              <tbody>
                <tr v-for="r in usageRows" :key="r.id">
                  <td>{{ fmtDateTime(r.redeemed_at) }}</td>
                  <td>{{ r.customer_name || r.customer_email || '—' }}</td>
                  <td><span class="source-chip" :class="r.source_type === 'subscription' ? 'sub' : 'booking'">{{ r.source_type === 'subscription' ? 'Membership' : 'Booking' }}</span></td>
                  <td style="text-align:right">{{ r.currency }} {{ Number(r.order_amount).toLocaleString() }}</td>
                  <td style="text-align:right;font-weight:700;color: var(--positive-ink)">− {{ r.currency }} {{ Number(r.discount_amount).toLocaleString() }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="closeUsage">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Filter tabs ─────────────────────────────────────────────────────────────── */
.filter-tabs {
  display: flex; gap: 4px;
  background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
  padding: 3px;
}
.filter-tabs button {
  display: flex; align-items: center; gap: 5px;
  padding: 5px 12px; border-radius: 6px;
  font-size: 13px; font-weight: 500; color: var(--text-muted);
  transition: all .12s;
}
.filter-tabs button.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: var(--shadow-sm); }

/* ── Architecture banner ──────────────────────────────────────────────────────── */
.arch-banner {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 10px 16px; margin-bottom: 4px;
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border); border-radius: 10px;
  font-size: 13px; color: var(--text-muted); line-height: 1.5;
}
.arch-banner strong { color: var(--text); }
.arch-icon.bookboll { color: var(--purple-ink, #4f46e5); flex-shrink: 0; }
.arch-icon.provider { color: var(--positive-ink, #059669); flex-shrink: 0; }
.arch-sep { color: var(--border); font-size: 16px; }

/* ── Card grid ────────────────────────────────────────────────────────────────── */
.promo-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;
}

/* ── Promo card ───────────────────────────────────────────────────────────────── */
.promo-card {
  background: var(--surface, #ffffff); border-radius: 12px; padding: 18px;
  border: 1.5px solid var(--border);
  box-shadow: var(--shadow-sm);
  transition: transform .15s, box-shadow .15s;
  display: flex; flex-direction: column; gap: 10px;
}
.promo-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.card-bookboll { border-top: 3px solid #4f46e5; }
.card-provider  { border-top: 3px solid #059669; }

/* Top row */
.pc-top { display: flex; justify-content: space-between; align-items: center; }
.pc-recipient {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px; border-radius: 99px;
  font-size: 11px; font-weight: 600;
}
.pc-recipient.bookboll { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #4f46e5); }
.pc-recipient.provider  { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #059669); }

/* Code */
.pc-code {
  display: flex; justify-content: space-between; align-items: center;
  padding: 9px 13px;
  background: var(--bg); border: 1px dashed var(--border); border-radius: 8px;
  font-family: 'SF Mono', Monaco, monospace; font-size: 16px; font-weight: 700;
  letter-spacing: .06em; cursor: pointer; color: var(--text);
  transition: border-color .12s, color .12s;
}
.pc-code:hover { border-color: var(--primary); color: var(--primary-ink); }
.copy-check { color: var(--positive-ink, #16a34a); }

/* Description */
.pc-desc { font-size: 13px; color: var(--text-muted); line-height: 1.4; }

/* Discount */
.pc-discount { font-size: 24px; font-weight: 800; color: var(--primary-ink); line-height: 1; }

/* Scope pills */
.pc-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.pill {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 500;
}
.pill-scope    { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }
.pill-location { background: var(--warning-bg, #fef9c3); color: var(--warning-ink, #854d0e); }
.pill-audience { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #6d28d9); }

/* Usage drawer */
.usage-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px; }
.usage-stat { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; }
.us-val { font-size: 17px; font-weight: 800; color: var(--text); line-height: 1.2; }
.us-lbl { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
.usage-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.usage-table th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); padding: 8px 10px; border-bottom: 1px solid var(--border); }
.usage-table td { padding: 9px 10px; border-bottom: 1px solid var(--border); }
.source-chip { display: inline-flex; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.source-chip.sub { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border: 1px solid var(--positive-border, #a7f3d0); }
.source-chip.booking { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1e40af); border: 1px solid var(--info-border, #bfdbfe); }
@media (max-width: 560px) { .usage-stats { grid-template-columns: 1fr 1fr; } }

/* Creator */
.pc-creator { font-size: 11px; color: var(--text-muted); }

/* Usage bar */
.pc-usage-wrap { display: flex; flex-direction: column; gap: 4px; }
.pc-usage-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); }
.pc-usage-label strong { color: var(--text); }
.pc-usage-bar { height: 4px; background: var(--border, #e5e7eb); border-radius: 99px; overflow: hidden; }
.pc-usage-fill { height: 100%; border-radius: 99px; transition: width .3s; }

/* Meta */
.pc-meta { display: flex; gap: 16px; font-size: 12px; color: var(--text-muted); }
.pc-meta div { display: flex; flex-direction: column; gap: 2px; }
.pc-meta strong { color: var(--text); font-size: 12px; }

/* Footer */
.pc-footer { display: flex; gap: 6px; justify-content: flex-end; margin-top: auto; }

/* ── Modal ────────────────────────────────────────────────────────────────────── */
.modal-lg { max-width: 620px; }
.form-section-label {
  font-size: 11px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .07em;
  padding-bottom: 6px; border-bottom: 1px solid var(--border); margin-bottom: 14px;
}
.req { color: var(--danger-ink, #ef4444); }
.form-opt { font-size: 11px; font-weight: 400; color: var(--text-muted); margin-left: 4px; }
.form-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
.form-error {
  display: flex; align-items: center; gap: 7px;
  padding: 10px 13px; background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca);
  border-radius: 8px; font-size: 13px; color: var(--danger-ink, #dc2626); margin-top: 12px;
}

/* Recipient picker */
.recipient-picker { display: flex; flex-direction: column; gap: 8px; }
.recipient-btn {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px; border-radius: 10px;
  border: 1.5px solid var(--border); background: var(--bg);
  cursor: pointer; text-align: left; transition: all .15s; width: 100%;
}
.recipient-btn:hover  { border-color: #94a3b8; background: var(--surface-hover, #f8fafc); }
.recipient-btn.active { border-color: var(--primary); background: var(--info-bg, #f0f9ff); }
.rb-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.rb-icon.bookboll { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #4f46e5); }
.rb-icon.provider  { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #059669); }
.rb-text { flex: 1; }
.rb-title { font-size: 13.5px; font-weight: 600; color: var(--text); }
.rb-desc  { font-size: 12px; color: var(--text-muted); margin-top: 2px; line-height: 1.4; }
.rb-check {
  width: 20px; height: 20px; border-radius: 50%;
  border: 1.5px solid var(--border); background: var(--surface, #ffffff);
  display: flex; align-items: center; justify-content: center;
  color: transparent; flex-shrink: 0;
}
.recipient-btn.active .rb-check {
  background: var(--primary); border-color: var(--primary); color: #fff;
}

/* Provider note */
.recipient-note {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 11px 14px; background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0);
  border-radius: 9px; font-size: 13px; color: var(--positive-ink, #166534);
}
.recipient-note svg { color: var(--positive-ink, #16a34a); flex-shrink: 0; margin-top: 1px; }

/* Applicable picker */
.applicable-picker { display: flex; gap: 8px; flex-wrap: wrap; }
.ap-option {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 10px 13px; border-radius: 9px;
  border: 1.5px solid var(--border); background: var(--bg);
  cursor: pointer; flex: 1; min-width: 130px;
  transition: border-color .12s, background .12s;
}
.ap-option:has(input:checked) { border-color: var(--primary); background: var(--info-bg, #f0f9ff); }
.ap-option input { margin-top: 2px; flex-shrink: 0; accent-color: var(--primary); }
.ap-title { font-size: 13px; font-weight: 600; color: var(--text); display: block; }
.ap-desc  { font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 2px; line-height: 1.3; }

.grid-3 { grid-template-columns: repeat(3, 1fr); }

/* Searchable customer picker */
.cust-picker { border: 1px solid var(--border); border-radius: 10px; padding: 8px; background: var(--bg); }
.cust-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.cust-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); border: 1px solid var(--info-border, #bfdbfe); border-radius: 999px; padding: 3px 6px 3px 10px; font-size: 12.5px; font-weight: 600; }
.cust-chip-txt em { color: var(--text-muted, #64748b); font-style: normal; font-weight: 500; }
.cust-chip button { display: inline-flex; align-items: center; justify-content: center; width: 17px; height: 17px; border-radius: 50%; border: none; background: var(--info-bg, #dbeafe); color: var(--info-ink, #1d4ed8); cursor: pointer; }
.cust-chip button:hover { background: #1d4ed8; color: #fff; }
.cust-search { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 8px; color: var(--text-muted); }
.cust-search input { flex: 1; border: none; outline: none; font: inherit; font-size: 14px; background: transparent; color: var(--text); }
.cust-matches { margin-top: 6px; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--surface, #ffffff); }
.cust-match { display: flex; flex-direction: column; gap: 1px; width: 100%; text-align: left; padding: 8px 11px; border: none; border-bottom: 1px solid var(--border); background: var(--surface, #ffffff); cursor: pointer; }
.cust-match:last-child { border-bottom: none; }
.cust-match:hover { background: var(--surface-hover, #f8fafc); }
.cust-match-name { font-size: 13.5px; font-weight: 600; color: var(--text); }
.cust-match-email { font-size: 12px; color: var(--text-muted); }
.cust-none { padding: 9px 11px; font-size: 13px; color: var(--text-muted); }
.cust-add-raw { border: none; background: none; color: var(--primary-ink); font-weight: 700; cursor: pointer; font-size: 13px; }

/* Code input (was inline style — moved to a class so phone media can override) */
.code-input { text-transform: uppercase; font-family: monospace; font-size: 14px; letter-spacing: .05em; }

/* ── Phone (≤700px) ─────────────────────────────────────────── */
@media (max-width: 700px) {
  /* 16px input font prevents iOS auto-zoom on focus */
  .code-input { font-size: 16px; }
  /* long "Name · email" chips wrap instead of pushing sideways scroll */
  .cust-chip { max-width: 100%; }
  .cust-chip-txt { min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
  .cust-chip button { flex-shrink: 0; }
  /* very long promo codes ellipsize inside the card */
  .pc-code { gap: 8px; }
  .pc-code span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
}
@media (max-width: 400px) {
  /* usage-drawer stat values (e.g. "AED 1,234,567") fit 2-up cells at 360px */
  .us-val { font-size: 15px; overflow-wrap: anywhere; }
}
</style>
