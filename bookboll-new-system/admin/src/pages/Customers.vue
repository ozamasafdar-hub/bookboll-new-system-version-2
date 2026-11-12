<script setup>
import ExportDialog from '../components/ExportDialog.vue'
import { customerExportFields } from '../lib/customerExport.js'
import { CUSTOMER_TYPES, customerTypeInfo } from '../lib/customerTypes.js'
import { isCommercialBooking } from '../lib/bookingMeta.js'
import { ref, onMounted, computed, watch, nextTick } from 'vue'
import {
  fetchCustomersDashboard, createCustomer, updateCustomer, deleteCustomer, firstError,
} from '../lib/customers.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { openCustomer, requestEditCustomerId, requestBlockCustomerId } from '../lib/customerModal.js'
import { Search, Plus, Mail, Phone, Trash2, Pencil as Edit2, X, Award, History, ChevronUp, ChevronDown, ChevronsUpDown, SlidersHorizontal, GraduationCap, Users, TrendingUp, Ban, CheckCircle, Tag, Download, Info, Eye, MailCheck, ChevronRight, Loader, Send, ImagePlus } from 'lucide-vue-next'
import WhatsappIcon from '../components/WhatsappIcon.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { CAP } from '../lib/permissions.js'
import { marketingEmailHtml, recordCampaign, deleteCampaign, sendCampaignEmails, unsubscribeUrl } from '../lib/marketing.js'
import { useProviderSmtp } from '../lib/emailSender.js'
import { loadWhatsappConfig, isWhatsappConfigured, invokeWhatsapp, normalizeWaNumber, renderTemplateBody, personalizeVariable, queueWaCampaign, processWaQueue, createWaTemplate, slugifyTemplateName } from '../lib/whatsapp.js'
import { uploadWhatsappMedia, uploadImage } from '../lib/imageUpload.js'
import { fmt12 } from '../lib/slots.js'
import { useRouter } from 'vue-router'
import { downloadCSV } from '../lib/useExport.js'
import { usePremium } from '../lib/premium.js'
import CustomerHistoryModal from '../components/CustomerHistoryModal.vue'
import BookingDetailModal from '../components/BookingDetailModal.vue'
import EmptyState from '../components/EmptyState.vue'
import PaginationBar from '../components/PaginationBar.vue'
import MultiSelect from '../components/MultiSelect.vue'
import FilterBar from '../components/FilterBar.vue'

const { role, isSuperAdmin, isCountryAdmin, currentOwnerUserId, scopeLocations, can, currentUser, allUsers } = useCurrentUser()
const canExport = computed(() => can(CAP.EXPORT_CUSTOMERS))
const { isPremium, requirePremium } = usePremium()   // exports + marketing emails are Premium features
const isPlatformAdmin = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')
const ownedLocationIds = computed(() => {
  if (isPlatformAdmin.value) return allowedLocationIds.value   // null = super admin (no restriction), Set = jurisdiction
  const owned = locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value)
  return new Set(scopeLocations(owned).map(l => l.id))   // narrow to assigned venues
})
// Bookboll-side = PLATFORM roles only. A provider staff row with no owner link
// is NOT platform — it falls to the provider branch and sees nothing until the
// owner link is fixed (previously it saw every business's customers + loyalty).
const isBookbollSide = computed(() => isPlatformAdmin.value)
const canSeeLoyalty  = computed(() => isBookbollSide.value)
// Server already tenant-scopes the paginated roster.
const scopedCustomers = computed(() => customers.value)

// ── Data ────────────────────────────────────────────────────────────────────────
const customers     = ref([])
const matchingTotal = ref(0)
const rosterTotal   = ref(0)
const loadingList   = ref(false)
const lookupsLoaded = ref(false)
const filterTags    = ref([])
const kpisFromApi   = ref({ total: 0, active: 0, activeSubs: 0, avgSpend: 0, vip: 0 })
const countries     = ref([])
const cities        = ref([])
const bookings      = ref([])
const listings      = ref([])
const locations     = ref([])
const subscriptions = ref([])
const programs      = ref([])
const customerLoyalty = ref([])   // per-country loyalty balances
const loyaltyPrograms = ref([])   // one program per country (earn/redeem config)
const loyaltyTiers    = ref([])   // tier thresholds per program

// Platform jurisdiction (country_admin / platform_staff only see their countries' venues)
const { allowedLocationIds, inJurisdictionOwner, scopeCountries, scopeCities } = useJurisdiction({ locations, cities })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))
// FilterBar geo options derive from THIS roster: countries customers are actually
// registered in, cities they have actually booked in. A provider stops seeing
// every platform country/city. Falls back to jurisdiction lists while loading.
const fbCountries = computed(() => jCountries.value)
const fbCities = computed(() => jCities.value)

// ── Filter state ────────────────────────────────────────────────────────────────
const search          = ref('')
const tierFilter      = ref('')
const filtersOpen     = ref(false)

// Multi-select dropdown filters
const locationFilter  = ref([])   // location IDs
const listingFilter   = ref([])   // listing IDs
const programFilter   = ref([])   // program IDs
const filterCountry   = ref([])   // multi (FilterBar) — customer's registered country
const filterCity      = ref([])   // multi (FilterBar) — derived from their booking cities
const sportFilter     = ref([])   // sport category strings
const currencyFilter  = ref([])   // currency codes
const customerTypeFilter = ref([])
const customerTypeMatch = ref('any')
const directoryError = ref('')
const typeInfoByCustomer = computed(() => Object.fromEntries(customers.value.map(customer=>[customer.id,customerTypeInfo(customer,{bookings:scopedBookingRows.value,subscriptions:scopedSubscriptions.value})])))

// Chip multi-select filters
const statusFilter    = ref([])   // 'active' | 'inactive'
const genderFilter    = ref([])   // 'male' | 'female' | 'other' (other = not specified)
const tagFilter       = ref([])   // manual CRM tags (options = tags actually in use)
const subStatusFilter = ref([])   // 'active' | 'in_trial' | 'ex' | 'none'
const marketingFilter = ref([])   // 'email' | 'sms' | 'whatsapp' | 'none' (marketing consent)

// Single-select
const lastActivityFilter = ref('')   // '' | '7d' | '30d' | '90d' | 'inactive' | 'never'

// Range filters
const spendMin   = ref('')
const spendMax   = ref('')
const bkMin      = ref(0)
const bkMax      = ref(0)
const joinedFrom = ref('')
const joinedTo   = ref('')

// ── Selection & bulk actions ─────────────────────────────────────────────────────
const selectedIds    = ref(new Set())
const showTagDialog  = ref(false)
const tagInput       = ref('')
const showBlockDialog = ref(false)
const blockForm = ref({ locationIds: [], listingIds: [], programIds: [], reason: '' })

// ── Sorting ─────────────────────────────────────────────────────────────────────
const sortKey = ref('created_at')
const sortDir = ref('desc')

// ── Modals ───────────────────────────────────────────────────────────────────────
const showModal     = ref(false)
const editing       = ref(null)
const form          = ref(defaultForm())
const viewHistoryId = ref(null)
const viewBookingId = ref(null)

function defaultForm() {
  return { full_name: '', email: '', phone: '', gender: '', country_id: '', loyalty_tier: 'Rookie', loyalty_points: 0, status: 'active', tags: '', loyalty: [] }
}

const custPage = ref(1)
const custPerPage = ref(25)
const custTotalPages = ref(1)
const listRequestId = ref(0)
let filterQuiet = false

function buildCustomerQuery({ useFilters = true, includeLookups = false, exportAll = false, idsOnly = false } = {}) {
  const q = {
    page: custPage.value,
    records_per_page: exportAll ? 5000 : custPerPage.value,
    sort: sortKey.value,
    sort_dir: sortDir.value,
    include_lookups: includeLookups || !lookupsLoaded.value ? 1 : 0,
  }
  if (idsOnly) q.ids_only = 1
  if (exportAll) {
    q.export = 1
    q.page = 1
  }
  if (!useFilters) return q

  if (search.value.trim()) q.search = search.value.trim()
  if (customerTypeFilter.value.length) { q.customer_types = customerTypeFilter.value; q.customer_types_match = customerTypeMatch.value }
  if (statusFilter.value.length) q.status = statusFilter.value
  if (genderFilter.value.length) q.gender = genderFilter.value
  if (filterCountry.value.length) q.country_id = filterCountry.value
  if (filterCity.value.length) q.city_id = filterCity.value
  if (locationFilter.value.length) q.location_id = locationFilter.value
  if (listingFilter.value.length) q.listing_id = listingFilter.value
  if (sportFilter.value.length) q.sport = sportFilter.value
  // These three were collected by the modal and never sent — the server could not
  // apply a filter it never received, so "All programs" was the only program.
  if (programFilter.value.length) q.program_id = programFilter.value
  if (currencyFilter.value.length) q.currency = currencyFilter.value
  if (subStatusFilter.value.length) q.sub_status = subStatusFilter.value
  if (tagFilter.value.length) q.tag = tagFilter.value
  if (marketingFilter.value.length) q.marketing = marketingFilter.value
  if (tierFilter.value) q.tier = tierFilter.value
  if (lastActivityFilter.value) q.last_activity = lastActivityFilter.value
  if (joinedFrom.value) q.joined_from = joinedFrom.value
  if (joinedTo.value) q.joined_to = joinedTo.value
  if (spendMin.value !== '') q.spend_min = spendMin.value
  if (spendMax.value !== '') q.spend_max = spendMax.value
  if (bkMin.value > 0) q.bk_min = bkMin.value
  if (bkMax.value > 0) q.bk_max = bkMax.value
  return q
}

function applyDashboardPayload(d, { mergeLookups = true } = {}) {
  const p = d.customers && !Array.isArray(d.customers) ? d.customers : null
  customers.value = p ? (p.data || []) : (d.customers || [])
  matchingTotal.value = p ? Number(p.total || 0) : customers.value.length
  custPage.value = p ? Number(p.current_page || custPage.value) : custPage.value
  custPerPage.value = p ? Number(p.per_page || custPerPage.value) : custPerPage.value
  custTotalPages.value = p ? Math.max(1, Number(p.last_page || 1)) : 1

  if (mergeLookups) {
    if (d.countries) countries.value = d.countries
    if (d.cities) cities.value = d.cities
    if (d.listings) listings.value = d.listings
    if (d.locations) locations.value = d.locations
    if (d.subscriptions) subscriptions.value = d.subscriptions
    if (d.programs) programs.value = d.programs
    if (d.loyalty_programs) loyaltyPrograms.value = d.loyalty_programs
    if (d.loyalty_tiers) loyaltyTiers.value = d.loyalty_tiers
    if (Array.isArray(d.filter_tags)) filterTags.value = d.filter_tags
    if (d.kpis) {
      kpisFromApi.value = { ...kpisFromApi.value, ...d.kpis }
      rosterTotal.value = Number(d.kpis.total || 0)
    }
    if (d.countries || d.locations) lookupsLoaded.value = true
  }

  bookings.value = d.bookings || []
  customerLoyalty.value = d.customer_loyalty || []
}

async function load(opts = {}) {
  const id = ++listRequestId.value
  loadingList.value = true
  directoryError.value = ''
  const res = await fetchCustomersDashboard(buildCustomerQuery({
    includeLookups: opts.includeLookups || !lookupsLoaded.value,
    useFilters: opts.useFilters !== false,
  }))
  if (id !== listRequestId.value) return
  loadingList.value = false
  if (!res.success) {
    customers.value = []; matchingTotal.value = 0; custTotalPages.value = 1
    selectedIds.value = new Set()
    directoryError.value = res.message || 'Could not load customers.'
    pushToast(res.message || 'Could not load customers.', 'error')
    return
  }
  applyDashboardPayload(res.data || {})
}

let searchTimer = null
function scheduleSearchLoad() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => resetToFirstPageAndLoad(), 400)
}

onMounted(() => load({ includeLookups: true }))
watch(custPage, () => { if (!filterQuiet) load() })
watch(custPerPage, () => resetToFirstPageAndLoad())
watch([
  statusFilter, genderFilter, filterCountry, filterCity, locationFilter, listingFilter,
  sportFilter, tagFilter, marketingFilter, subStatusFilter, programFilter, currencyFilter,
  tierFilter, lastActivityFilter, joinedFrom, joinedTo, spendMin, spendMax, bkMin, bkMax,
  sortKey, sortDir, customerTypeFilter, customerTypeMatch,
], () => resetToFirstPageAndLoad(), { deep: true })
watch(search, scheduleSearchLoad)

// Handoffs from the global Customer Details modal ("Edit" / "Block" actions).
watch(requestEditCustomerId, async (id) => {
  if (!id) return
  if (!customers.value.length) await load()
  const c = customers.value.find(x => x.id === id)
  requestEditCustomerId.value = null
  if (c) openEdit(c)
}, { immediate: true })
watch(requestBlockCustomerId, async (id) => {
  if (!id) return
  if (!customers.value.length) await load()
  const c = customers.value.find(x => x.id === id)
  requestBlockCustomerId.value = null
  if (c) openBlockForRow(c)
}, { immediate: true })

// ── Computed maps ────────────────────────────────────────────────────────────────
// Every per-customer analytic (spend, recency, cities visited, sports, booking
// counts) reads bookings at THIS viewer's venues only — a provider must never see
// or segment by a customer's activity at other businesses. Super admin (no scope)
// keeps the platform-wide set.
const scopedBookingRows = computed(() => {
  const locIds = ownedLocationIds.value
  return bookings.value.filter(b => isCommercialBooking(b) && (!locIds || locIds.has(b.location_id)))
})
const bkByCustomer = computed(() => {
  const map = {}
  for (const b of scopedBookingRows.value) {
    if (!b.customer_id) continue
    ;(map[b.customer_id] = map[b.customer_id] || []).push(b)
  }
  return map
})

// Bookings count within this viewer's scope (drives the column, filter and sort).
const scopedBookingsCount = (c) => (bkByCustomer.value[c.id] || []).length

const lastBookingByCustomer = computed(() => {
  const map = {}
  for (const [cid, bks] of Object.entries(bkByCustomer.value)) {
    const sorted = bks.slice().sort((a, b) => (b.booking_date || '').localeCompare(a.booking_date || ''))
    map[cid] = sorted[0]?.booking_date || null
  }
  return map
})

const autoTagsByCustomer = computed(() => {
  const map = {}
  for (const [cid, bks] of Object.entries(bkByCustomer.value)) {
    const seen = new Set()
    const tags = []
    for (const b of bks) {
      const lst = listings.value.find(l => l.id === b.listing_id)
      if (lst && !seen.has(lst.id)) {
        seen.add(lst.id)
        tags.push({ name: lst.name, color: lst.booking_color || '#64748b' })
      }
    }
    map[cid] = tags
  }
  return map
})

// Locations each customer has visited (resolved via listing if location_id missing)
const locationsByCustomer = computed(() => {
  const map = {}
  for (const [cid, bks] of Object.entries(bkByCustomer.value)) {
    const ids = new Set()
    for (const b of bks) {
      const locId = b.location_id || listings.value.find(l => l.id === b.listing_id)?.location_id
      if (locId) ids.add(locId)
    }
    map[cid] = ids
  }
  return map
})

// Listings each customer has booked
const listingsByCustomer = computed(() => {
  const map = {}
  for (const [cid, bks] of Object.entries(bkByCustomer.value)) {
    map[cid] = new Set(bks.map(b => b.listing_id).filter(Boolean))
  }
  return map
})

// Programs each customer (matched by email) is subscribed to
const programsByEmail = computed(() => {
  const map = {}
  for (const s of subscriptions.value) {
    const key = s.customer_email?.toLowerCase()
    if (!key || !s.program_id) continue
    ;(map[key] = map[key] || new Set()).add(s.program_id)
  }
  return map
})

// Best subscription per customer email
// Subscription state only counts memberships at THIS viewer's programs — a
// provider's "Subscriber" chip must not match someone subscribed elsewhere.
const scopedSubscriptions = computed(() => {
  if (isPlatformAdmin.value && !allowedLocationIds.value) return subscriptions.value  // super admin
  const locIds = ownedLocationIds.value
  const progIds = new Set(programs.value
    .filter(p => (locIds && locIds.has(p.location_id)) || (!isPlatformAdmin.value && p.owner_user_id === currentOwnerUserId.value) || (isPlatformAdmin.value && inJurisdictionOwner(p.owner_user_id)))
    .map(p => p.id))
  return subscriptions.value.filter(s => progIds.has(s.program_id))
})
const subByEmail = computed(() => {
  const map = {}
  for (const s of scopedSubscriptions.value) {
    const key = s.customer_email?.toLowerCase()
    if (!key) continue
    const existing = map[key]
    if (!existing || subPriority(s) > subPriority(existing)) map[key] = s
  }
  return map
})

function subPriority(s) {
  if (s.status === 'active' && s.trial_status === 'converted') return 3
  if (s.status === 'active' && s.trial_status === 'in_trial')  return 2
  if (s.status === 'active') return 1
  return 0
}

function subForCustomer(c) {
  return c.email ? (subByEmail.value[c.email.toLowerCase()] || null) : null
}

function subStatusValue(sub) {
  if (!sub) return 'none'
  if (sub.status === 'cancelled') return 'ex'
  if (sub.trial_status === 'in_trial') return 'in_trial'
  return 'active'
}

// ── Multi-currency spend (derived from each booking's location country) ──────────
function currencyForLoc(locId) {
  const loc = locations.value.find(l => l.id === locId)
  const city = cities.value.find(c => c.id === loc?.city_id)
  return countries.value.find(c => c.id === city?.country_id)?.currency_code || ''
}
// { customerId: { 'AED': 1200, 'SAR': 800 } }
const spendByCustomer = computed(() => {
  const map = {}
  for (const b of scopedBookingRows.value) {
    if (!b.customer_id) continue
    const amt = Number(b.total || 0)
    if (!amt) continue
    const cur = currencyForLoc(b.location_id)
    map[b.customer_id] = map[b.customer_id] || {}
    map[b.customer_id][cur] = (map[b.customer_id][cur] || 0) + amt
  }
  return map
})
// Sorted [ [cur, amount], ... ] for a customer
function customerSpendList(id) {
  const m = spendByCustomer.value[id] || {}
  return Object.entries(m).sort((a, b) => b[1] - a[1])
}
// All currencies present across customers (for the filter)
const allCurrencies = computed(() => {
  const set = new Set()
  for (const c of countries.value) if (c.currency_code) set.add(c.currency_code)
  for (const l of locations.value) if (l.currency_override) set.add(l.currency_override)
  for (const m of Object.values(spendByCustomer.value)) for (const cur of Object.keys(m)) set.add(cur)
  return [...set].sort()
})
const currencyOptions = computed(() => allCurrencies.value.map(c => ({ value: c, label: c })))
function fmtCur(n, cur) { return `${cur} ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}` }

// ── Per-country loyalty (points + derived tier) ─────────────────────────────────
// Fallback tier thresholds when a country has no configured loyalty program.
const PRESET_TIERS = [
  { name: 'Rookie', threshold: 0 }, { name: 'Star', threshold: 1000 },
  { name: 'Legend', threshold: 5000 }, { name: 'GOAT', threshold: 15000 },
]
// Tier chip options: every configured tier name in use across countries (plus
// presets when no program exists), ranked by threshold — never a hardcoded list.
const tierChipOptions = computed(() => {
  const byName = new Map()
  const programCountries = new Set(loyaltyPrograms.value.map(pr => pr.country_id))
  for (const pr of loyaltyPrograms.value) {
    for (const t of loyaltyTiers.value.filter(t => t.program_id === pr.id)) {
      const th = Number(t.threshold || 0)
      if (!byName.has(t.name) || th > byName.get(t.name)) byName.set(t.name, th)
    }
  }
  // Countries without a configured program fall back to presets — keep those names too.
  if (!programCountries.size || countries.value.some(c => !programCountries.has(c.id))) {
    for (const t of PRESET_TIERS) if (!byName.has(t.name)) byName.set(t.name, Number(t.threshold))
  }
  return [{ v: '', l: 'All' }, ...[...byName.entries()].sort((a, b) => a[1] - b[1]).map(([n]) => ({ v: n, l: n }))]
})
// name -> rank (threshold order) for the tier sort — replaces the hardcoded map
const tierRank = computed(() => {
  const m = {}
  tierChipOptions.value.forEach((t, i) => { if (t.v) m[t.v] = i })
  return m
})

// country_id -> tiers sorted DESC by threshold (program tiers, else presets)
function tiersForCountry(countryId) {
  const prog = loyaltyPrograms.value.find(p => p.country_id === countryId)
  let tiers = prog ? loyaltyTiers.value.filter(t => t.program_id === prog.id) : []
  if (!tiers.length) tiers = PRESET_TIERS
  return [...tiers].sort((a, b) => Number(b.threshold) - Number(a.threshold))
}
// Tier name for a LIFETIME-earned points total in a given country
function tierForPoints(countryId, lifetimePoints) {
  const pts = Number(lifetimePoints || 0)
  const tiers = tiersForCountry(countryId)
  const hit = tiers.find(t => pts >= Number(t.threshold || 0))
  return hit?.name || 'Rookie'
}
// customerId -> [ { country_id, countryName, points, lifetime_points, tier } ]
const loyaltyByCustomer = computed(() => {
  const map = {}
  for (const row of customerLoyalty.value) {
    const item = {
      country_id: row.country_id,
      countryName: countries.value.find(c => c.id === row.country_id)?.name || '—',
      points: Number(row.points || 0),
      lifetime_points: Number(row.lifetime_points || 0),
      tier: tierForPoints(row.country_id, row.lifetime_points),
    }
    ;(map[row.customer_id] = map[row.customer_id] || []).push(item)
  }
  for (const id in map) map[id].sort((a, b) => b.points - a.points)
  return map
})
function customerLoyaltyList(id) { return loyaltyByCustomer.value[id] || [] }
function customerTotalPoints(id) { return customerLoyaltyList(id).reduce((s, r) => s + r.points, 0) }
function customerHighestTier(id) {
  const rows = customerLoyaltyList(id)
  if (!rows.length) return null
  return rows.reduce((best, r) => ((tierRank.value[r.tier] || 0) > (tierRank.value[best] || 0) ? r.tier : best), 'Rookie')
}
// Inline expand toggle for the per-country breakdown in the table
const expandedLoyalty = ref(new Set())
function toggleLoyalty(id) {
  const s = new Set(expandedLoyalty.value)
  s.has(id) ? s.delete(id) : s.add(id)
  expandedLoyalty.value = s
}

// ── Selection computed ───────────────────────────────────────────────────────────
const allOnPageSelected = computed(() =>
  paginatedCustomers.value.length > 0 &&
  paginatedCustomers.value.every(c => selectedIds.value.has(c.id))
)

const someOnPageSelected = computed(() =>
  paginatedCustomers.value.some(c => selectedIds.value.has(c.id))
)

const allTags = computed(() => {
  const tags = new Set(filterTags.value)
  for (const c of customers.value) {
    parseField(c).tags.forEach(t => tags.add(t))
  }
  return [...tags].sort()
})
const tagOptions = computed(() => allTags.value.map(t => ({ value: t, label: t })))

// ── Block encoding (stored inside tags field, prefix ⛔) ──────────────────────────
// Format per entry:  ⛔TYPE|TARGET_ID|REASON
// Regular tags have no ⛔ prefix — they coexist in the same comma-separated field.

function parseField(c) {
  const raw = (c.tags || '').split(',').map(t => t.trim()).filter(Boolean)
  const tags = []
  const blks = []
  for (const entry of raw) {
    if (entry.startsWith('⛔')) {
      const [type, targetId, ...rest] = entry.slice(1).split('|')
      blks.push({ type, targetId: targetId || null, reason: rest.join('|') })
    } else {
      tags.push(entry)
    }
  }
  return { tags, blks }
}

function encodeField(tags, blks) {
  const encoded = blks.map(b => `⛔${b.type}|${b.targetId || ''}|${b.reason}`)
  return [...tags, ...encoded].join(', ')
}

const blocksByCustomer = computed(() => {
  const map = {}
  for (const c of customers.value) {
    const { blks } = parseField(c)
    if (blks.length) map[c.id] = blks
  }
  return map
})

function blockTargetName(b) {
  if (b.type === 'platform') return 'Entire Platform'
  if (b.type === 'location') return locations.value.find(l => l.id === b.targetId)?.name || 'Location'
  if (b.type === 'listing')  return listings.value.find(l => l.id === b.targetId)?.name  || 'Listing'
  if (b.type === 'program')  return programs.value.find(p => p.id === b.targetId)?.name  || 'Program'
  return '—'
}

// ── Selection functions ──────────────────────────────────────────────────────────
function toggleSelect(id) {
  const s = new Set(selectedIds.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selectedIds.value = s
}

// Header checkbox: only the rows on screen. Ticked adds this page to whatever is
// already selected; unticked removes just this page. Selecting EVERY matching
// customer across pages is the banner's "Select all N" link (toggleSelectAll).
function togglePage() {
  const s = new Set(selectedIds.value)
  const ids = paginatedCustomers.value.map(c => c.id)
  if (allOnPageSelected.value) ids.forEach(id => s.delete(id))
  else ids.forEach(id => s.add(id))
  selectedIds.value = s
}

function toggleSelectAll() {
  if (allFilteredSelected.value) {
    selectedIds.value = new Set()
    return
  }
  const query=buildCustomerQuery({ idsOnly: true, includeLookups: false }), snapshot=JSON.stringify(query)
  fetchCustomersDashboard(query).then((res) => {
    if(snapshot!==JSON.stringify(buildCustomerQuery({ idsOnly:true,includeLookups:false })))return
    if (!res.success) {
      selectedIds.value = new Set()
      pushToast(res.message || 'Could not select matching customers.', 'error')
      return
    }
    // A server without ids_only answers with a normal page and no `ids`. That
    // used to become an EMPTY selection — the header box ticked nothing. Fall
    // back to the rows on screen and say so, rather than silently doing nothing.
    if (!Array.isArray(res.data?.ids)) {
      selectedIds.value = new Set(paginatedCustomers.value.map(c => c.id))
      if (matchingTotal.value > paginatedCustomers.value.length) pushToast('Selected the customers on this page. Update the API to select every matching customer at once.', 'warning')
      return
    }
    selectedIds.value = new Set(res.data.ids.map(String))
  })
}
const allFilteredSelected = computed(() =>
  matchingTotal.value > 0 && selectedIds.value.size >= matchingTotal.value
)

function clearSelection() { selectedIds.value = new Set() }

// ── Bulk marketing email (consent-gated, record & preview) ──────────────────────
// ONLY customers who explicitly opted in to marketing email (customers.marketing_email)
// are eligible — the rest of the selection is skipped. We record the campaign + render the
// exact compliant HTML (sender disclaimer + one-click unsubscribe) for an in-dashboard preview.
const showEmailDialog = ref(false)
const emailForm = ref({ subject: '', body: '' })
const sendingCampaign = ref(false)
const campaignSent = ref(false)

// Who's emailing: a provider sends as their business ("via the Bookboll platform"); a
// Bookboll-side admin sends as Bookboll. Drives the sender label, brand colour & disclaimer.
const emailSender = computed(() => {
  const ownerId = currentOwnerUserId.value
  if (ownerId) {
    const owner = allUsers.value.find(u => u.id === ownerId)
    return { isProvider: true, label: owner?.business_name || owner?.brand_name || 'Your business', brandColor: owner?.brand_primary || '#0f766e', ownerUserId: ownerId }
  }
  return { isProvider: false, label: 'Bookboll', brandColor: '#0f766e', ownerUserId: null }
})

// Marketing to your own list REQUIRES your own verified sender (a business can't email its
// customers from Bookboll's shared address). Bookboll-side admins send as Bookboll — exempt.
const router = useRouter()
const { smtp: senderSmtp, configured: senderReady, load: loadSender } = useProviderSmtp()
const mustConnectSender = computed(() => emailSender.value.isProvider && !senderReady.value)

const eligibleEmailCustomers = computed(() =>
  customers.value.filter(c => selectedIds.value.has(c.id) && c.email && c.marketing_email === true)
)
const selectedEmails = computed(() => eligibleEmailCustomers.value.map(c => c.email))
// Selected but skipped, broken out so the admin understands the gating.
const skippedNoEmail = computed(() =>
  [...selectedIds.value].filter(id => { const c = customers.value.find(x => x.id === id); return c && !c.email }).length
)
const skippedNotOptedIn = computed(() =>
  customers.value.filter(c => selectedIds.value.has(c.id) && c.email && c.marketing_email !== true).length
)

// Live, compliant preview rendered for a representative recipient (real token when available).
const previewHtml = computed(() => marketingEmailHtml({
  subject: emailForm.value.subject,
  body: emailForm.value.body,
  senderLabel: emailSender.value.label,
  isProvider: emailSender.value.isProvider,
  brandColor: emailSender.value.brandColor,
  unsubUrl: unsubscribeUrl(eligibleEmailCustomers.value[0]?.unsubscribe_token || 'sample-token', 'email'),
}))

function openEmailDialog() { emailForm.value = { subject: '', body: '' }; campaignSent.value = false; showEmailDialog.value = true; loadSender() }
function goToSender() { showEmailDialog.value = false; router.push('/email-sender') }

async function sendCampaign() {
  const recipients = eligibleEmailCustomers.value
  if (!recipients.length || !emailForm.value.subject.trim()) return
  if (mustConnectSender.value) {
    pushToast('Connect your own email address first to send marketing emails.', 'error')
    return
  }
  sendingCampaign.value = true
  // The server re-checks both consent and whether each customer is actually yours, so
  // it may queue fewer than were selected — report what it really queued.
  const res = await sendCampaignEmails({
    subject: emailForm.value.subject.trim(),
    body: emailForm.value.body,
    customerIds: recipients.map(c => c.id),
    senderLabel: emailSender.value.label,
    audience: `Opted-in email · ${recipients.length} selected`,
  })
  sendingCampaign.value = false
  if (res.error) { pushToast(res.error, 'error'); return }

  campaignSent.value = true
  const dropped = res.requested - res.queued
  pushToast(
    dropped > 0
      ? `Sending to ${res.queued} customer${res.queued === 1 ? '' : 's'} — ${dropped} skipped (not your customer, or not opted in).`
      : `Sending to ${res.queued} opted-in customer${res.queued === 1 ? '' : 's'} — delivery is under way.`,
    'success',
  )
}

// ── Bulk WhatsApp campaign (consent-gated, template-based, REAL sends) ─────────
// WhatsApp marketing must use Meta-APPROVED templates (no free-form blasts), and only
// customers with marketing_whatsapp === true AND a phone number are eligible. Each business
// sends from ITS OWN connected number (providers → their own; Bookboll → its platform number),
// resolved server-side from `waOwnerId` by the send-whatsapp Edge Function.
const waOwnerId = computed(() => currentOwnerUserId.value || null)
const showWaDialog = ref(false)
const waConfig = ref(null)                 // this business's whatsapp_config (null until loaded)
const waTemplates = ref([])                // approved templates from the WABA
const waTemplatesState = ref('')           // '' | 'loading' | 'ready' | 'manual'
const waTemplate = ref(null)               // selected template object
const waManual = ref({ name: 'hello_world', language: 'en_US' })
const waVariables = ref([])                // values for {{1}}…{{n}} ({name} = first name)
const waSending = ref(false)
const waResult = ref(null)                 // { sent, failed } after a campaign
// Author a brand-new template inline (submit to Meta → auto-send on approval).
const waMode = ref('pick')                 // 'pick' (existing template) | 'new' (author one)
const waNew = ref({ name: '', category: 'MARKETING', language: 'en_US', body: '', headerImageUrl: '', headerVideoUrl: '' })
// The image sent with an IMAGE-header template. Starts as the template's own
// sample; a campaign can swap it (Meta only checks the format at send time).
const waHeaderImage = ref('')
const waHeaderVideo = ref('')
const waMediaDimensions = ref('')
const waPreviewMedia = computed(() => waMode.value === 'new' ? { image: waNew.value.headerImageUrl, video: waNew.value.headerVideoUrl } : { image: waHeaderImage.value, video: waHeaderVideo.value })
function waMediaLoaded(e) { waMediaDimensions.value = `${e.target.videoWidth || e.target.naturalWidth} × ${e.target.videoHeight || e.target.naturalHeight} px` }
const waImageUploading = ref('')          // '' | 'new' | 'campaign'
const waNeedsImage = computed(() => !!waTemplate.value && ['IMAGE', 'VIDEO'].includes(waTemplate.value.headerFormat))
const waImageMissing = computed(() => waNeedsImage.value && !(waTemplate.value.headerFormat === 'VIDEO' ? waHeaderVideo.value : waHeaderImage.value))
async function pickWaImage(event, target) {
  const file = event.target.files?.[0]
  if (!file || waImageUploading.value) return
  const video = file.type === 'video/mp4'
  if (target === 'campaign' && (video ? 'VIDEO' : 'IMAGE') !== waTemplate.value?.headerFormat) { pushToast('Choose the media type required by this template.', 'error'); return }
  if (!['image/jpeg', 'image/png', 'video/mp4'].includes(file.type)) { pushToast('Choose a JPEG, PNG or MP4 file.', 'error'); event.target.value = ''; return }
  if (file.size > (video ? 16 : 5) * 1024 * 1024) { pushToast(video ? 'Videos must be 16 MB or smaller.' : 'Images must be 5 MB or smaller.', 'error'); event.target.value = ''; return }
  waImageUploading.value = target
  try {
    const r = await uploadWhatsappMedia(file)
    if (r.error) { pushToast(r.error, 'error'); return }
    waMediaDimensions.value = ''
    if (target === 'new') { waNew.value.headerImageUrl = video ? '' : r.url; waNew.value.headerVideoUrl = video ? r.url : '' }
    else { waHeaderImage.value = video ? '' : r.url; waHeaderVideo.value = video ? r.url : '' }
  } catch { pushToast('The media could not be uploaded. Please try again.', 'error') }
  finally { waImageUploading.value = ''; event.target.value = '' }
}
const waCreating = ref(false)
// Meta placeholders are numbered ({{1}}…) — kept out of the template markup as a bound
// string so Vue's own {{ }} interpolation never sees them.
const WA_TPL_PLACEHOLDER = 'Hi {{1}}, great news at Bookboll — book your next session today! Use {{1}}, {{2}} for personalized fields.'
const waNewVarCount = computed(() => {
  let n = 0
  for (const m of String(waNew.value.body || '').matchAll(/\{\{(\d+)\}\}/g)) n = Math.max(n, Number(m[1]))
  return n
})

const eligibleWaCustomers = computed(() =>
  customers.value.filter(c => selectedIds.value.has(c.id) && normalizeWaNumber(c.phone) && c.marketing_whatsapp === true)
)
const skippedNoPhone = computed(() =>
  customers.value.filter(c => selectedIds.value.has(c.id) && c.marketing_whatsapp === true && !normalizeWaNumber(c.phone)).length
)
const skippedNotOptedInWa = computed(() =>
  customers.value.filter(c => selectedIds.value.has(c.id) && c.marketing_whatsapp !== true).length
)
const waConfigured = computed(() => isWhatsappConfigured(waConfig.value, waOwnerId.value))

async function openWaDialog() {
  waResult.value = null
  waTemplate.value = null
  waVariables.value = []
  waHeaderImage.value = ''; waHeaderVideo.value = ''; waMediaDimensions.value = ''
  waMode.value = 'pick'
  waNew.value = { name: '', category: 'MARKETING', language: 'en_US', body: '', headerImageUrl: '', headerVideoUrl: '' }
  showWaDialog.value = true
  waConfig.value = await loadWhatsappConfig()   // this business's own config, resolved server-side
  if (!isWhatsappConfigured(waConfig.value, waOwnerId.value)) return
  // Load the approved-template list; fall back to manual entry when the WABA id is
  // missing or the function isn't deployed.
  waTemplatesState.value = 'loading'
  // Auto-send pump first: any campaign queued on a template Meta has since APPROVED
  // fires now; a REJECTED template surfaces its reason. This is the "get notified"
  // touchpoint inside the composer itself.
  processWaQueue().then(pump => {
    for (const s of pump.sent || []) {
      const r = s.result || {}
      pushToast(r.error
        ? `Queued campaign "${s.template_name}" failed: ${r.error}`
        : `Template "${s.template_name}" approved — queued campaign auto-sent to ${r.sent} customer${r.sent === 1 ? '' : 's'}.`,
        r.error ? 'error' : 'success')
    }
    for (const r of pump.rejected || []) pushToast(`Template "${r.template_name}" was REJECTED by Meta: ${r.reject_reason}. Its queued campaign was not sent.`, 'error')
  })
  const res = await invokeWhatsapp({ action: 'list_templates', ownerId: waOwnerId.value })
  if (res.ok) {
    // APPROVED = sendable now; PENDING = pickable too — the campaign queues and
    // auto-sends the moment Meta approves the template (one platform, no waiting around).
    waTemplates.value = (res.templates || []).filter(t => ['APPROVED', 'PENDING'].includes(t.status))
    waTemplatesState.value = waTemplates.value.length ? 'ready' : 'manual'
  } else {
    waTemplates.value = []
    waTemplatesState.value = 'manual'
    if (res.notDeployed) pushToast(res.error, 'error')
  }
}
function goToWhatsappSetup() { showWaDialog.value = false; router.push('/whatsapp') }

function pickWaTemplate(t) {
  waTemplate.value = t
  waVariables.value = Array.from({ length: t.varCount || 0 }, () => '')
  waHeaderImage.value = t.headerFormat === 'IMAGE' ? (t.headerImage || '') : ''
  waHeaderVideo.value = t.headerFormat === 'VIDEO' ? (t.headerVideo || '') : ''
  waMediaDimensions.value = ''
}

// Author a new template → submit to Meta for review → select it so the SAME queue path
// (sendWaCampaign's PENDING branch) auto-sends the campaign the moment Meta approves it.
async function submitNewTemplate() {
  const name = slugifyTemplateName(waNew.value.name)
  const body = String(waNew.value.body || '').trim()
  if (!name) { pushToast('Give the template a short name (letters, numbers, underscores).', 'error'); return }
  if (!body) { pushToast('Write the message body first.', 'error'); return }
  waCreating.value = true
  const res = await createWaTemplate(waOwnerId.value, {
    name,
    language: waNew.value.language.trim() || 'en_US',
    category: waNew.value.category,
    bodyText: body,
    headerImageUrl: waNew.value.headerImageUrl || '',
    headerVideoUrl: waNew.value.headerVideoUrl || '',
    // Meta requires an example value per {{n}}; the real per-customer values are set at send.
    examples: Array.from({ length: waNewVarCount.value }, (_, i) => `Sample ${i + 1}`),
  })
  waCreating.value = false
  if (!res.ok) { pushToast(res.error || 'Meta rejected the template submission.', 'error'); return }
  // Submitted (PENDING). Select it — variables + preview + the queue/auto-send flow reuse this.
  const tpl = { name: res.name || name, language: waNew.value.language.trim() || 'en_US', category: waNew.value.category, bodyText: body, varCount: waNewVarCount.value, status: 'PENDING', headerFormat: waNew.value.headerVideoUrl ? 'VIDEO' : waNew.value.headerImageUrl ? 'IMAGE' : '', headerVideo: waNew.value.headerVideoUrl || '', headerImage: waNew.value.headerImageUrl || '' }
  waTemplates.value = [tpl, ...waTemplates.value.filter(t => !(t.name === tpl.name && t.language === tpl.language))]
  waTemplatesState.value = 'ready'
  waMode.value = 'pick'
  pickWaTemplate(tpl)
  pushToast(`“${tpl.name}” submitted to Meta for review. Fill any variables, then Queue — it auto-sends the moment Meta approves (usually minutes to a few hours).`, 'success')
}
// Preview for the first eligible recipient (tokens personalized like the real send).
const waPreviewBody = computed(() => {
  const sample = eligibleWaCustomers.value[0] || null
  if (waMode.value === 'new') return waNew.value.body || 'Your message will appear here.'
  if (waTemplate.value) {
    return renderTemplateBody(waTemplate.value.bodyText, waVariables.value.map(v => personalizeVariable(v, sample)))
  }
  return `[template: ${waManual.value.name || 'hello_world'}]`
})

// Any selected template variable still blank? (blank body parameters make Graph reject
// every message in the campaign, so Send stays disabled until they're all filled)
const waVarsMissing = computed(() =>
  !!waTemplate.value && waVariables.value.some(v => !String(v).trim())
)
// A template is chosen when one was picked from the list — or, when the list could
// not be loaded, when a name has been typed. sendWaCampaign already sends the typed
// name in that case; the button used to demand a picked one and stayed disabled.
const waTemplateChosen = computed(() =>
  !!waTemplate.value || (waMode.value === 'pick' && waTemplatesState.value === 'manual' && !!waManual.value.name.trim())
)

const WA_CAMPAIGN_CAP = 200   // must match the send-whatsapp function's per-run cap

async function sendWaCampaign() {
  if (waResult.value) return   // already sent — reopen the dialog for another campaign
  const all = eligibleWaCustomers.value
  if (!all.length) return
  if (!waConfigured.value) { pushToast('Connect the WhatsApp channel first.', 'error'); return }
  const tpl = waTemplate.value
    ? { name: waTemplate.value.name, language: waTemplate.value.language }
    : { name: waManual.value.name.trim(), language: waManual.value.language.trim() || 'en_US' }
  if (!tpl.name) { pushToast('Pick or enter a template.', 'error'); return }
  if (waHeaderImage.value) tpl.headerImageUrl = waHeaderImage.value
  if (waHeaderVideo.value) tpl.headerVideoUrl = waHeaderVideo.value
  if (waImageMissing.value) { pushToast('Add the image or video required by this template.', 'error'); return }

  // One message per NUMBER (two customer rows sharing a phone would double-message),
  // capped to the function's per-run limit — with the overflow surfaced, never silent.
  const seen = new Set()
  const unique = all.filter(c => { const n = normalizeWaNumber(c.phone); if (seen.has(n)) return false; seen.add(n); return true })
  const recipients = unique.slice(0, WA_CAMPAIGN_CAP)
  const overCap = unique.length - recipients.length

  // Template still in Meta review → QUEUE the campaign instead of sending. It fires
  // automatically the moment the template is approved (checked whenever this composer
  // or the WhatsApp page opens); a rejection surfaces here as a notification instead.
  if (waTemplate.value && waTemplate.value.status === 'PENDING') {
    const okQ = await askConfirm({
      title: `Queue campaign for ${recipients.length} customer${recipients.length !== 1 ? 's' : ''}?`,
      message: `Template “${tpl.name}” is still in Meta review. The campaign will be queued and sent AUTOMATICALLY as soon as Meta approves it. If Meta rejects the template you'll be notified and nothing is sent.`,
      confirmText: 'Queue & auto-send on approval', cancelText: 'Cancel',
    })
    if (!okQ) return
    waSending.value = true
    const q = await queueWaCampaign({
      ownerId: waOwnerId.value, template: tpl,
      payload: {
        bodyText: waTemplate.value?.bodyText || '',
        headerVideoUrl: waHeaderVideo.value, headerImageUrl: waHeaderImage.value || '',
        recipients: recipients.map(c => ({
          to: normalizeWaNumber(c.phone), customerId: c.id,
          variables: waVariables.value.map(v => personalizeVariable(v, c)),
        })),
        campaignMeta: {
          ownerUserId: emailSender.value.ownerUserId, createdBy: currentUser.value?.id || null,
          senderLabel: emailSender.value.label, channel: 'whatsapp',
          audience: `Opted-in WhatsApp · ${recipients.length} recipient${recipients.length !== 1 ? 's' : ''} (queued on approval)`,
          subject: tpl.name, body: waPreviewBody.value, html: '', recipientCount: recipients.length,
        },
      },
    })
    waSending.value = false
    if (q.error) { pushToast(q.error, 'error'); return }
    waResult.value = { queued: true, sent: 0, failed: 0, dropped: overCap }
    pushToast(`Queued — will auto-send to ${recipients.length} customer${recipients.length !== 1 ? 's' : ''} once Meta approves “${tpl.name}”. Track it on the WhatsApp page.`, 'success')
    return
  }

  const ok = await askConfirm({
    title: `Send WhatsApp to ${recipients.length} customer${recipients.length !== 1 ? 's' : ''}?`,
    message: `This sends REAL WhatsApp messages (template “${tpl.name}”) through Bookboll's number. Only opted-in customers with a phone number are included.`
      + (overCap > 0 ? ` Note: campaigns are limited to ${WA_CAMPAIGN_CAP} messages per run — ${overCap} of your selection will NOT be sent this time.` : ''),
    confirmText: 'Send now', cancelText: 'Cancel',
  })
  if (!ok) return
  waSending.value = true
  // Record the campaign first (same compliance ledger as email) — with the count we will
  // actually attempt. If the ledger write fails, no real messages go out unrecorded.
  const camp = await recordCampaign({
    ownerUserId: emailSender.value.ownerUserId,
    createdBy: currentUser.value?.id || null,
    senderLabel: emailSender.value.label,
    channel: 'whatsapp',
    audience: `Opted-in WhatsApp · ${recipients.length} recipient${recipients.length !== 1 ? 's' : ''}`,
    subject: tpl.name,
    body: waPreviewBody.value,
    html: '',
    recipientCount: recipients.length,
  })
  if (camp.error) {
    waSending.value = false
    pushToast('Could not record campaign: ' + (camp.error.message || camp.error) + ' (has the marketing migration been run?)', 'error')
    return
  }
  const res = await invokeWhatsapp({
    action: 'send_campaign',
    ownerId: waOwnerId.value,
    template: tpl,
    bodyText: waTemplate.value?.bodyText || '',
    campaignId: camp?.data?.id || null,
    recipients: recipients.map(c => ({
      to: normalizeWaNumber(c.phone),
      customerId: c.id,
      variables: waVariables.value.map(v => personalizeVariable(v, c)),
    })),
  })
  waSending.value = false
  if (!res.ok) {
    // Nothing was dispatched — remove the orphaned ledger row so history stays honest.
    if (camp.data?.id) await deleteCampaign(camp.data.id)
    pushToast(res.error || 'Campaign failed.', 'error')
    return
  }
  const dropped = (res.dropped || 0) + overCap
  waResult.value = { sent: res.sent, failed: res.failed, dropped }
  pushToast(
    `WhatsApp campaign: ${res.sent} sent${res.failed ? `, ${res.failed} failed` : ''}${dropped ? `, ${dropped} not sent (${WA_CAMPAIGN_CAP}/run limit)` : ''}.`,
    (res.failed || dropped) ? 'error' : 'success',
  )
}

// Per-row marketing consent chips (shown in the customers table).
function marketingChips(c) {
  const out = []
  if (c.marketing_email === true) out.push('Email')
  if (c.marketing_sms === true) out.push('SMS')
  if (c.marketing_whatsapp === true) out.push('WhatsApp')
  return out
}

function openBlockDialog() {
  blockForm.value = { locationIds: [], listingIds: [], programIds: [], reason: '' }
  showBlockDialog.value = true
}

// ── Per-row quick actions ──────────────────────────────────────────────────────
function emailCustomer(c) {
  if (!c.email) { pushToast('No email on file for this customer.', 'error'); return }
  window.location.href = `mailto:${encodeURIComponent(c.email)}`
}
function whatsappCustomer(c) {
  if (!c.phone) { pushToast('No phone number on file for this customer.', 'error'); return }
  window.open(`https://wa.me/${c.phone.replace(/\D/g, '')}`, '_blank')
}
function openBlockForRow(c) {
  selectedIds.value = new Set([c.id])   // block dialog acts on the selection
  openBlockDialog()
}
async function unblockCustomerRow(c) {
  await removeBlock(c.id, 'platform', null)
  pushToast(`${c.full_name || 'Customer'} unblocked`, 'success')
}

function clearBlockTargets() {
  blockForm.value.locationIds = []
  blockForm.value.listingIds  = []
  blockForm.value.programIds  = []
}

// Guard flag: prevents forward cascade from wiping siblings during a reverse cascade
let _skipLocCascade = false

// ── Forward cascade: location selected → auto-fill its listings & programs ──────
watch(() => [...blockForm.value.locationIds], (newIds, oldIds) => {
  if (_skipLocCascade) return          // reverse cascade triggered this — skip cleanup

  const added   = newIds.filter(id => !(oldIds || []).includes(id))
  const removed = (oldIds || []).filter(id => !newIds.includes(id))

  const lstSet  = new Set(blockForm.value.listingIds)
  const progSet = new Set(blockForm.value.programIds)

  listings.value.filter(l => added.includes(l.location_id)).forEach(l => lstSet.add(l.id))
  listings.value.filter(l => removed.includes(l.location_id)).forEach(l => lstSet.delete(l.id))

  programs.value.filter(p => added.includes(p.location_id)).forEach(p => progSet.add(p.id))
  programs.value.filter(p => removed.includes(p.location_id)).forEach(p => progSet.delete(p.id))

  blockForm.value.listingIds = [...lstSet]
  blockForm.value.programIds = [...progSet]
})

// ── Reverse cascade: listing removed → deselect its parent location ──────────────
watch(() => [...blockForm.value.listingIds], (newIds, oldIds) => {
  const removed = (oldIds || []).filter(id => !newIds.includes(id))
  if (!removed.length) return

  const locSet = new Set(blockForm.value.locationIds)
  let changed = false
  for (const listingId of removed) {
    const locId = listings.value.find(l => l.id === listingId)?.location_id
    if (locId && locSet.has(locId)) { locSet.delete(locId); changed = true }
  }
  if (changed) {
    _skipLocCascade = true
    blockForm.value.locationIds = [...locSet]
    nextTick(() => { _skipLocCascade = false })
  }
})

// ── Reverse cascade: program removed → deselect its parent location ──────────────
watch(() => [...blockForm.value.programIds], (newIds, oldIds) => {
  const removed = (oldIds || []).filter(id => !newIds.includes(id))
  if (!removed.length) return

  const locSet = new Set(blockForm.value.locationIds)
  let changed = false
  for (const programId of removed) {
    const locId = programs.value.find(p => p.id === programId)?.location_id
    if (locId && locSet.has(locId)) { locSet.delete(locId); changed = true }
  }
  if (changed) {
    _skipLocCascade = true
    blockForm.value.locationIds = [...locSet]
    nextTick(() => { _skipLocCascade = false })
  }
})

// Enriched options for the block dialog — show parent location name for context
const blockListingOptions = computed(() =>
  listings.value
    .slice()
    .sort((a, b) => {
      const la = locations.value.find(l => l.id === a.location_id)?.name || ''
      const lb = locations.value.find(l => l.id === b.location_id)?.name || ''
      return la.localeCompare(lb) || a.name.localeCompare(b.name)
    })
    .map(l => {
      const loc = locations.value.find(x => x.id === l.location_id)
      return { value: l.id, label: loc ? `${l.name}  ·  ${loc.name}` : l.name }
    })
)

const blockProgramOptions = computed(() =>
  programs.value.map(p => {
    const loc = locations.value.find(x => x.id === p.location_id)
    return { value: p.id, label: loc ? `${p.name}  ·  ${loc.name}` : p.name }
  })
)

const blockPreview = computed(() => {
  const { locationIds, listingIds, programIds } = blockForm.value
  if (!locationIds.length && !listingIds.length && !programIds.length) {
    // Providers can't ban platform-wide — prompt them to pick a scope instead.
    if (!isBookbollSide.value)
      return { isPlatform: false, needsScope: true, items: [], summary: 'Select a venue, listing or program' }
    return { isPlatform: true, items: [{ type: 'platform', name: 'Entire Platform' }], summary: 'Entire Platform' }
  }

  const items = [
    ...locationIds.map(id => ({ type: 'location', name: locations.value.find(l => l.id === id)?.name || 'Location' })),
    ...listingIds.map(id  => ({ type: 'listing',  name: listings.value.find(l  => l.id === id)?.name || 'Listing'  })),
    ...programIds.map(id  => ({ type: 'program',  name: programs.value.find(p  => p.id === id)?.name || 'Program'  })),
  ]
  const parts = []
  if (locationIds.length) parts.push(`${locationIds.length} location${locationIds.length > 1 ? 's' : ''}`)
  if (listingIds.length)  parts.push(`${listingIds.length} listing${listingIds.length > 1 ? 's' : ''}`)
  if (programIds.length)  parts.push(`${programIds.length} program${programIds.length > 1 ? 's' : ''}`)
  return { isPlatform: false, items, summary: parts.join(' · ') }
})

async function applyBlock() {
  const ids = [...selectedIds.value]
  const selected = customers.value.filter(c => ids.includes(c.id))
  const { locationIds, listingIds, programIds, reason } = blockForm.value
  const isPlatform = !locationIds.length && !listingIds.length && !programIds.length
  // Only Bookboll-side accounts can ban platform-wide; providers must pick a scope.
  if (isPlatform && !isBookbollSide.value) {
    pushToast('Select the venues, listings, or programs to block this customer from.', 'error')
    return
  }

  await Promise.all(selected.map(c => {
    const { tags, blks } = parseField(c)
    const newBlks = [...blks]
    const add = (type, targetId) => {
      if (!newBlks.some(b => b.type === type && b.targetId === targetId))
        newBlks.push({ type, targetId, reason })
    }
    if (isPlatform) {
      add('platform', null)
    } else {
      locationIds.forEach(id => add('location', id))
      listingIds.forEach(id  => add('listing',  id))
      programIds.forEach(id  => add('program',  id))
    }
    const updates = { tags: encodeField(tags, newBlks) }
    if (isPlatform) updates.status = 'blocked'
    return updateCustomer(c.id, updates)
  }))
  showBlockDialog.value = false
  clearSelection(); load()
}

async function removeBlock(customerId, blockType, blockTargetId) {
  const c = customers.value.find(x => x.id === customerId)
  if (!c) return
  const { tags, blks } = parseField(c)
  const remaining = blks.filter(b => !(b.type === blockType && b.targetId === blockTargetId))
  const updates = { tags: encodeField(tags, remaining) }
  if (blockType === 'platform' && !remaining.some(b => b.type === 'platform'))
    updates.status = 'active'
  await updateCustomer(customerId, updates)
  load()
}

async function unblockSelected() {
  if (!selectedIds.value.size) return
  const selected = customers.value.filter(c => selectedIds.value.has(c.id))
  await Promise.all(selected.map(c => {
    const { tags } = parseField(c)
    return updateCustomer(c.id, { tags: tags.join(', '), status: 'active' })
  }))
  clearSelection(); load()
}

function openTagDialog() { tagInput.value = ''; showTagDialog.value = true }

function toggleTagSuggestion(t) {
  const tags = tagInput.value.split(',').map(x => x.trim()).filter(Boolean)
  const idx = tags.indexOf(t)
  if (idx === -1) tags.push(t)
  else tags.splice(idx, 1)
  tagInput.value = tags.join(', ')
}

async function applyTag() {
  const newTags = tagInput.value.split(',').map(t => t.trim()).filter(Boolean)
  if (!newTags.length) return
  const ids = [...selectedIds.value]
  const selected = customers.value.filter(c => ids.includes(c.id))
  await Promise.all(selected.map(c => {
    const existing = (c.tags || '').split(',').map(t => t.trim()).filter(Boolean)
    const merged = [...new Set([...existing, ...newTags])]
    return updateCustomer(c.id, { tags: merged.join(', ') })
  }))
  showTagDialog.value = false; tagInput.value = ''
  clearSelection(); load()
}

async function removeTag(customerId, tag) {
  const c = customers.value.find(x => x.id === customerId)
  if (!c) return
  const remaining = (c.tags || '').split(',').map(t => t.trim()).filter(t => t && t !== tag)
  await updateCustomer(customerId, { tags: remaining.join(', ') })
  load()
}

function customerTags(c) { return parseField(c).tags }

// ── MultiSelect options ──────────────────────────────────────────────────────────
// Scoped option lists: providers only see their own venues/listings/programs;
// country_admin / platform_staff only their jurisdiction's (null set = super admin, all).
const scopedLocations = computed(() => ownedLocationIds.value ? locations.value.filter(l => ownedLocationIds.value.has(l.id)) : locations.value)
const scopedListings  = computed(() => ownedLocationIds.value ? listings.value.filter(l => ownedLocationIds.value.has(l.location_id)) : listings.value)
const scopedPrograms  = computed(() => {
  const set = ownedLocationIds.value
  if (!set) return programs.value
  if (isPlatformAdmin.value) return programs.value.filter(p => set.has(p.location_id))
  return programs.value.filter(p => p.owner_user_id === currentOwnerUserId.value || set.has(p.location_id))
})
const locationOptions = computed(() => scopedLocations.value.map(l => ({ value: l.id, label: l.name })))
const listingOptions  = computed(() => scopedListings.value.map(l => ({ value: l.id, label: l.name })))
const programOptions  = computed(() => scopedPrograms.value.map(p => ({ value: p.id, label: p.name })))
const countryOptions  = computed(() => countries.value.map(c => ({ value: c.id, label: c.name })))
const sportOptions    = computed(() => {
  const seen = new Set()
  const opts = []
  for (const l of scopedListings.value) {
    if (l.sport_category && !seen.has(l.sport_category)) {
      seen.add(l.sport_category)
      opts.push({ value: l.sport_category, label: l.sport_category })
    }
  }
  return opts.sort((a, b) => a.label.localeCompare(b.label))
})

// Sport listings set (for filter performance)
const listingsBySport = computed(() => {
  const map = {}
  for (const l of listings.value) {
    if (l.sport_category) (map[l.sport_category] = map[l.sport_category] || new Set()).add(l.id)
  }
  return map
})

// ── KPIs ─────────────────────────────────────────────────────────────────────────
const kpis = computed(() => kpisFromApi.value)

// ── Sorting ──────────────────────────────────────────────────────────────────────
// Rank derives from configured thresholds (tierRank computed above).

function setSort(key) {
  if (sortKey.value === key) { sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc' }
  else { sortKey.value = key; sortDir.value = key === 'full_name' ? 'asc' : 'desc' }
}
function sortIcon(key) {
  if (sortKey.value !== key) return 'none'
  return sortDir.value
}

// ── Active filter count ──────────────────────────────────────────────────────────
const activeFilterCount = computed(() =>
  [
    tierFilter.value,
    customerTypeFilter.value.length ? '1' : '',
    statusFilter.value.length      ? '1' : '',
    locationFilter.value.length    ? '1' : '',
    listingFilter.value.length     ? '1' : '',
    programFilter.value.length     ? '1' : '',
    sportFilter.value.length       ? '1' : '',
    genderFilter.value.length      ? '1' : '',
    tagFilter.value.length         ? '1' : '',
    subStatusFilter.value.length   ? '1' : '',
    marketingFilter.value.length   ? '1' : '',
    lastActivityFilter.value,
    currencyFilter.value.length    ? '1' : '',
    (spendMin.value !== '' || spendMax.value !== '') ? '1' : '',
    (bkMin.value > 0 || bkMax.value > 0)            ? '1' : '',
    (joinedFrom.value || joinedTo.value)             ? '1' : '',
  ].filter(Boolean).length
)

// City lookup for a customer via their booking locations
const citiesByCustomer = computed(() => {
  const map = {}
  for (const bk of scopedBookingRows.value) {
    if (!bk.customer_id) continue
    const loc  = locations.value.find(l => l.id === bk.location_id)
    const cityId = loc?.city_id
    if (cityId) {
      if (!map[bk.customer_id]) map[bk.customer_id] = new Set()
      map[bk.customer_id].add(cityId)
    }
  }
  return map
})

// Server already filtered + sorted this page.
const filteredSorted = computed(() => customers.value)
const paginatedCustomers = computed(() => customers.value)
const custVisiblePages = computed(() => {
  const total = custTotalPages.value
  const cur = custPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  return Array.from(pages).sort((a, b) => a - b)
})
function custGoTo(p) {
  custPage.value = Math.min(custTotalPages.value, Math.max(1, p))
}

function resetToFirstPageAndLoad() {
  if (filterQuiet) return
  selectedIds.value = new Set()
  if (custPage.value !== 1) custPage.value = 1
  else load()
}

function clearFilters() {
  filterQuiet = true
  tierFilter.value = ''
  customerTypeFilter.value = []; customerTypeMatch.value = 'any'; directoryError.value = ''
  statusFilter.value = [];    filterCountry.value = [];  filterCity.value = []
  locationFilter.value = [];  listingFilter.value = [];  programFilter.value = []
  genderFilter.value = [];    sportFilter.value = [];    subStatusFilter.value = []
  tagFilter.value = []
  marketingFilter.value = []
  currencyFilter.value = []
  lastActivityFilter.value = ''
  spendMin.value = '';  spendMax.value = ''
  bkMin.value = 0;     bkMax.value = 0
  joinedFrom.value = ''; joinedTo.value = ''
  search.value = ''
  filterQuiet = false
  resetToFirstPageAndLoad()
}

// ── Export ────────────────────────────────────────────────────────────────────
const exportBusy = ref(false)
const showExportModal = ref(false)
async function doExport(useFilters) {
  if (!canExport.value) return
  if (!requirePremium('exports')) { showExportModal.value = false; return }
  if (exportBusy.value) return
  exportBusy.value = true
  try {
  const res = await fetchCustomersDashboard(buildCustomerQuery({
    useFilters,
    exportAll: true,
    includeLookups: true,
  }))
  if (!res.success) throw new Error(res.message || 'Could not prepare the export.')
  if (res.data?.truncated) throw new Error('Too many customers to export at once. Apply filters and try again.')
  const p = res.data?.customers
  const rows = p?.data || (Array.isArray(res.data?.customers) ? res.data.customers : [])
  if (Number(p?.total) > rows.length) throw new Error('This export exceeds the server limit. Apply filters to export a smaller group.')
  if (!rows.length) { pushToast('No customers to export.', 'info'); return }
  const fields = customerExportFields(res.data)
  downloadCSV(rows, [
    { label: 'ID',            key: 'id' },
    { label: 'Full Name',     key: 'full_name' },
    { label: 'Email',         key: 'email' },
    { label: 'Phone',         key: 'phone' },
    { label: 'Customer types',fn: fields.types },
    { label: 'Gender',        key: 'gender' },
    ...(canSeeLoyalty.value ? [
      { label: 'Loyalty Tier (highest)', fn: fields.tier },
      { label: 'Loyalty Points by Country', fn: fields.points },
    ] : []),
    { label: 'Total Bookings',fn: fields.count },
    { label: 'Spend by Currency', fn: fields.spend },
    { label: 'Status',        key: 'status' },
    { label: 'Joined Date',   fn: r => r.created_at?.slice(0,10) },
  ], 'customers')
  showExportModal.value = false
  } catch (e) { pushToast(e.message, 'error') } finally { exportBusy.value = false }
}

// ── Chip helper ───────────────────────────────────────────────────────────────────
function toggleChip(arr, val) {
  const i = arr.indexOf(val)
  if (i === -1) arr.push(val)
  else arr.splice(i, 1)
}

// ── CRUD ─────────────────────────────────────────────────────────────────────────
const triedSave = ref(false)
function openNew()   { editing.value = null; form.value = defaultForm(); triedSave.value = false; clearDirty(); showModal.value = true }
function openEdit(c) {
  editing.value = c
  form.value = { ...c, loyalty: customerLoyaltyList(c.id).map(r => ({ country_id: r.country_id, points: r.points, lifetime_points: r.lifetime_points })) }
  triedSave.value = false; clearDirty(); showModal.value = true
}
// Per-country loyalty editor helpers
function addLoyaltyRow() {
  const used = new Set((form.value.loyalty || []).map(r => r.country_id))
  const next = countries.value.find(c => !used.has(c.id))
  if (!form.value.loyalty) form.value.loyalty = []
  form.value.loyalty.push({ country_id: next?.id || '', points: 0, lifetime_points: 0 })
}
function removeLoyaltyRow(i) { form.value.loyalty.splice(i, 1) }
// Live tier preview inside the edit form
function previewTier(row) { return row.country_id ? tierForPoints(row.country_id, row.lifetime_points) : '—' }
async function requestCloseForm() { if (await confirmDiscardIfDirty()) showModal.value = false }

async function save() {
  triedSave.value = true
  if (!form.value.full_name?.trim()) { pushToast('Customer name is required.', 'error'); return }
  if (form.value.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email.trim())) { pushToast('Please enter a valid email address.', 'error'); return }
  const { loyalty, loyalty_points, loyalty_tier, ...rest } = form.value
  const payload = {
    ...rest,
    country_id: rest.country_id || null,
    gender: rest.gender || null,
    loyalty: (loyalty || []).filter(r => r.country_id),
  }
  delete payload.id
  delete payload.created_at
  delete payload.updated_at
  delete payload.unsubscribe_token
  delete payload.customer_types
  delete payload.customer_types_complete
  const res = editing.value
    ? await updateCustomer(editing.value.id, payload)
    : await createCustomer(payload)
  if (!res.success) { pushToast(firstError(res), 'error'); return }
  clearDirty()
  pushToast(editing.value ? 'Customer updated' : 'Customer created', 'success')
  showModal.value = false; load()
}
async function remove(id) {
  if (!(await askConfirm({
    title: 'Delete customer?',
    message: 'This customer and their profile will be permanently removed. This cannot be undone.',
    confirmText: 'Delete',
  }))) return
  const res = await deleteCustomer(id)
  if (!res.success) { pushToast(firstError(res, 'Could not delete customer.'), 'error'); return }
  pushToast('Customer deleted', 'success')
  load()
}

// ── Display helpers ───────────────────────────────────────────────────────────────
const TIER_BADGE = { Rookie: 'badge-neutral', Star: 'badge-info', Legend: 'badge-warning', GOAT: 'badge-success' }
const tierBadge  = t => TIER_BADGE[t] || 'badge-neutral'

function subLabel(s) {
  if (!s) return null
  if (s.trial_status === 'in_trial')  return { text: 'In Trial',      cls: 'sub-trial' }
  if (s.trial_status === 'converted') return { text: 'Subscriber',    cls: 'sub-active' }
  if (s.status === 'cancelled')       return { text: 'Ex-Subscriber', cls: 'sub-ex' }
  return { text: 'Subscriber', cls: 'sub-active' }
}

function programName(sub) {
  if (!sub?.program_id) return null
  return programs.value.find(p => p.id === sub.program_id)?.name || null
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function countryName(id) { return countries.value.find(c => c.id === id)?.name || '' }

function avatarInitials(name) { return (name||'?').split(' ').map(s=>s[0]).slice(0,2).join('').toUpperCase() }
const AVG_COLORS = ['#0f766e','#0891b2','#7c3aed','#d97706','#dc2626','#059669']
function avatarColor(id) {
  const n = id ? parseInt(String(id).replace(/-/g,'').slice(0,8), 16) : 0
  return AVG_COLORS[n % AVG_COLORS.length]
}
</script>

<template>
  <ExportDialog :open="showExportModal" title="Export customers" noun="customers" :filtered-count="matchingTotal" :all-count="rosterTotal" :busy="exportBusy" @close="showExportModal = false" @export="doExport" />
  <div class="page admin-workspace customers-workspace">
    <div class="page-header">
      <div>
        <h1 class="page-title">Customers</h1>
        <div class="page-subtitle">Find customers, review their bookings and manage their details.</div>
      </div>
      <button v-if="isBookbollSide" class="btn btn-primary" @click="openNew"><Plus :size="16" />New Customer</button>
    </div>

    <!-- KPIs -->
    <div class="grid customer-overview" :class="canSeeLoyalty ? 'grid-4' : 'grid-3'">
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">Total Customers</div><div class="kpi-icon" style="background: var(--info-bg);color: var(--info-ink)"><Users :size="18" /></div></div>
        <div class="kpi-value">{{ kpis.total }}</div>
        <div class="kpi-delta">{{ kpis.active }} active</div>
      </div>
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">Active Members</div><div class="kpi-icon" style="background: var(--positive-bg);color: var(--positive-ink)"><GraduationCap :size="18" /></div></div>
        <div class="kpi-value">{{ kpis.activeSubs }}</div>
        <div class="kpi-delta">Membership program members</div>
      </div>
      <div v-if="canSeeLoyalty" class="card kpi">
        <div class="kpi-top"><div class="kpi-label">VIP (Legend+)</div><div class="kpi-icon" style="background: var(--warning-bg);color: var(--warning-ink)"><Award :size="18" /></div></div>
        <div class="kpi-value">{{ kpis.vip }}</div>
        <div class="kpi-delta">Legend &amp; GOAT tier</div>
      </div>
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">Customer status</div><div class="kpi-icon" style="background: var(--primary-soft);color: var(--primary-ink)"><TrendingUp :size="18" /></div></div>
        <div class="kpi-value">{{ Math.max(0, kpis.total - kpis.active).toLocaleString() }}</div>
        <div class="kpi-delta">Inactive or blocked</div>
      </div>
    </div>

    <div class="card customer-directory">
      <div class="workspace-section-heading"><div><h2>Customer directory</h2><p>Contact details, booking history and country rewards.</p></div><span>{{ directoryError ? 'Results unavailable' : loadingList ? 'Loading results…' : `${matchingTotal.toLocaleString()} results` }}</span></div>

      <!-- ── Toolbar (search + all filters in the dropdown) ──────────────────── -->
      <FilterBar
        v-model:search="search"
        v-model:country="filterCountry"
        v-model:city="filterCity"
        :countries="fbCountries"
        :cities="fbCities"
        country-label="Registered country"
        city-label="Booked in city"
        :extra-count="activeFilterCount"
        search-placeholder="Search name, email or phone..."
        @clear="clearFilters"
      >
        <template #actions>
          <button v-if="canExport" class="btn btn-secondary btn-sm" @click="showExportModal = true">
            <Download :size="14" />Export<span v-if="!isPremium" class="prem-lock">Premium</span>
          </button>
        </template>

        <!-- Row 1: Multi-select dropdowns -->
        <div class="filter-ms-row">
          <div class="filter-ms-col">
            <label class="filter-label">Customer type</label>
            <MultiSelect v-model="customerTypeFilter" :options="CUSTOMER_TYPES" placeholder="All customer types" />
          </div>
          <div v-if="customerTypeFilter.length > 1" class="filter-ms-col">
            <label class="filter-label" for="customer-type-match">Match selected types</label>
            <select id="customer-type-match" v-model="customerTypeMatch"><option value="any">Any selected type</option><option value="all">Every selected type</option></select>
          </div>
          <div class="filter-ms-col">
            <label class="filter-label">Location</label>
            <MultiSelect v-model="locationFilter" :options="locationOptions" placeholder="All locations" />
          </div>
          <div class="filter-ms-col">
            <label class="filter-label">Listing / Court</label>
            <MultiSelect v-model="listingFilter" :options="listingOptions" placeholder="All listings" />
          </div>
          <div class="filter-ms-col">
            <label class="filter-label">Plan / Program</label>
            <MultiSelect v-model="programFilter" :options="programOptions" placeholder="All programs" />
          </div>
          <div class="filter-ms-col">
            <label class="filter-label">Sport Type</label>
            <MultiSelect v-model="sportFilter" :options="sportOptions" placeholder="All sports" />
          </div>
          <div class="filter-ms-col">
            <label class="filter-label">Currency</label>
            <MultiSelect v-model="currencyFilter" :options="currencyOptions" placeholder="All currencies" />
          </div>
          <div v-if="tagOptions.length" class="filter-ms-col">
            <label class="filter-label">Tags</label>
            <MultiSelect v-model="tagFilter" :options="tagOptions" placeholder="All tags" />
          </div>
        </div>

        <hr class="filter-divider" />

        <!-- Row 2: Loyalty Tier + Status + Gender chips -->
        <div v-if="canSeeLoyalty" class="filter-chip-row">
          <div class="chip-group">
            <span class="chip-group-label">Loyalty Tier</span>
            <button v-for="t in tierChipOptions" :key="t.v"
              class="chip" :class="{ active: tierFilter === t.v }"
              @click="tierFilter = t.v">{{ t.l }}</button>
          </div>
        </div>
        <div class="filter-chip-row">
          <div class="chip-group">
            <span class="chip-group-label">Status</span>
            <button v-for="s in [{v:'active',l:'Active'},{v:'inactive',l:'Inactive'},{v:'blocked',l:'Blocked'}]" :key="s.v"
              class="chip" :class="{ active: statusFilter.includes(s.v) }"
              @click="toggleChip(statusFilter, s.v)">{{ s.l }}</button>
          </div>
          <div class="chip-group">
            <span class="chip-group-label">Gender</span>
            <button v-for="g in [{v:'male',l:'Male'},{v:'female',l:'Female'},{v:'other',l:'Not specified'}]" :key="g.v"
              class="chip" :class="{ active: genderFilter.includes(g.v) }"
              @click="toggleChip(genderFilter, g.v)">{{ g.l }}</button>
          </div>
        </div>

        <!-- Row 3: Subscription status chips -->
        <div class="filter-chip-row">
          <div class="chip-group">
            <span class="chip-group-label">Subscription</span>
            <button v-for="s in [{v:'active',l:'Subscriber'},{v:'in_trial',l:'In Trial'},{v:'ex',l:'Ex-Subscriber'},{v:'none',l:'No Subscription'}]" :key="s.v"
              class="chip" :class="{ active: subStatusFilter.includes(s.v) }"
              @click="toggleChip(subStatusFilter, s.v)">{{ s.l }}</button>
          </div>
          <div class="chip-group">
            <span class="chip-group-label">Marketing consent</span>
            <button v-for="m in [{v:'email',l:'Email'},{v:'sms',l:'SMS'},{v:'whatsapp',l:'WhatsApp'},{v:'none',l:'None'}]" :key="m.v"
              class="chip" :class="{ active: marketingFilter.includes(m.v) }"
              @click="toggleChip(marketingFilter, m.v)">{{ m.l }}</button>
          </div>
        </div>

        <!-- Row 4: Last Activity pills -->
        <div class="filter-chip-row">
          <div class="chip-group">
            <span class="chip-group-label">Last Activity</span>
            <button v-for="a in [
              {v:'',l:'Any time'},
              {v:'7d',l:'Last 7 days'},
              {v:'30d',l:'Last 30 days'},
              {v:'90d',l:'Last 90 days'},
              {v:'inactive',l:'Inactive 90d+'},
              {v:'never',l:'Never booked'},
            ]" :key="a.v"
              class="chip" :class="{ active: lastActivityFilter === a.v }"
              @click="lastActivityFilter = a.v">{{ a.l }}</button>
          </div>
        </div>

        <hr class="filter-divider" />

        <!-- Row 5: Ranges -->
        <div class="filter-ranges">
          <div class="range-group">
            <span class="filter-label">Bookings</span>
            <div class="range-inputs">
              <select v-model.number="bkMin">
                <option :value="0">Min: Any</option>
                <option :value="1">1+</option>
                <option :value="3">3+</option>
                <option :value="5">5+</option>
                <option :value="10">10+</option>
                <option :value="20">20+</option>
                <option :value="50">50+</option>
              </select>
              <span class="range-sep">–</span>
              <select v-model.number="bkMax">
                <option :value="0">Max: Any</option>
                <option :value="1">1</option>
                <option :value="3">3</option>
                <option :value="5">5</option>
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
              </select>
            </div>
          </div>

          <div class="range-group">
            <span class="filter-label">Spend {{ currencyFilter.length === 1 ? `(${currencyFilter[0]})` : '(any currency)' }}</span>
            <div class="range-inputs">
              <input type="number" v-model="spendMin" placeholder="Min" min="0" class="range-input" />
              <span class="range-sep">–</span>
              <input type="number" v-model="spendMax" placeholder="Max" min="0" class="range-input" />
            </div>
          </div>

          <div class="range-group">
            <span class="filter-label">Joined Date</span>
            <div class="range-inputs">
              <input type="date" v-model="joinedFrom" class="range-input date-input" />
              <span class="range-sep">–</span>
              <input type="date" v-model="joinedTo"   class="range-input date-input" />
            </div>
          </div>
        </div>

      </FilterBar>

      <!-- ── Bulk action bar ────────────────────────────────────────────────── -->
      <Transition name="bulk-slide">
        <div v-if="selectedIds.size > 0" class="bulk-bar">
          <div class="bulk-info">
            <div class="bulk-check-icon"><CheckCircle :size="15" /></div>
            <strong>{{ selectedIds.size }}</strong>&nbsp;customer{{ selectedIds.size !== 1 ? 's' : '' }} selected
            <button v-if="!allFilteredSelected && matchingTotal > selectedIds.size" class="bulk-selectall-link" @click="toggleSelectAll">
              Select all {{ matchingTotal }} matching
            </button>
          </div>
          <div class="bulk-actions">
            <button class="bulk-btn" @click="requirePremium('marketing') && openEmailDialog()"><Mail :size="14" />Email<span v-if="!isPremium" class="prem-lock">Premium</span></button>
            <button class="bulk-btn" @click="requirePremium('marketing') && openWaDialog()"><WhatsappIcon :size="14" />WhatsApp<span v-if="!isPremium" class="prem-lock">Premium</span></button>
            <button class="bulk-btn danger" @click="openBlockDialog"><Ban :size="14" />Block…</button>
            <button class="bulk-btn success" @click="unblockSelected"><CheckCircle :size="14" />Remove All Blocks</button>
            <button class="bulk-btn" @click="openTagDialog"><Tag :size="14" />Add Tag</button>
            <button class="bulk-deselect" @click="clearSelection" aria-label="Close"><X :size="15" /></button>
          </div>
        </div>
      </Transition>

      <!-- ── Table ───────────────────────────────────────────────────────────── -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="th-check">
                <input type="checkbox" class="row-checkbox"
                  :checked="allOnPageSelected"
                  :indeterminate.prop="someOnPageSelected && !allOnPageSelected"
                  title="Select the customers on this page"
                  aria-label="Select all customers on this page"
                  @change="togglePage" />
              </th>
              <th class="sortable" @click="setSort('full_name')">
                Customer
                <span class="sort-icon"><ChevronUp v-if="sortIcon('full_name')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('full_name')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th>Contact</th>
              <th class="sortable" @click="setSort('last_booking')">
                Last Booking
                <span class="sort-icon"><ChevronUp v-if="sortIcon('last_booking')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('last_booking')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th class="sortable" @click="setSort('total_bookings')">
                Bookings
                <span class="sort-icon"><ChevronUp v-if="sortIcon('total_bookings')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('total_bookings')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th class="sortable" @click="setSort('total_spent')">
                Spend by Currency
                <span class="sort-icon"><ChevronUp v-if="sortIcon('total_spent')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('total_spent')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th>Subscription</th>
              <th v-if="canSeeLoyalty" class="sortable" @click="setSort('loyalty_tier')">
                Tier
                <span class="sort-icon"><ChevronUp v-if="sortIcon('loyalty_tier')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('loyalty_tier')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th v-if="canSeeLoyalty" class="sortable" @click="setSort('loyalty_points')">
                Points
                <span class="sort-icon"><ChevronUp v-if="sortIcon('loyalty_points')==='asc'" :size="12"/><ChevronDown v-else-if="sortIcon('loyalty_points')==='desc'" :size="12"/><ChevronsUpDown v-else :size="12" class="muted-icon"/></span>
              </th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in paginatedCustomers" :key="c.id"
              :class="{ 'row-selected': selectedIds.has(c.id), 'row-blocked': c.status === 'blocked' }">

              <!-- Checkbox -->
              <td class="td-check">
                <input type="checkbox" class="row-checkbox"
                  :checked="selectedIds.has(c.id)"
                  @change="toggleSelect(c.id)" />
              </td>

              <!-- Customer: avatar + name + auto-tags + manual tags -->
              <td>
                <div class="cell-main">
                  <div class="avatar-sm" :style="{ background: avatarColor(c.id) }">{{ avatarInitials(c.full_name) }}</div>
                  <div class="cell-name-col">
                    <div class="cell-name">
                      <button class="cust-name-btn" @click="openCustomer(c.id)" title="View customer details">{{ c.full_name }}</button>
                      <span v-if="c.status === 'blocked'" class="blocked-badge"><Ban :size="9" />Blocked</span>
                    </div>
                    <!-- Booking auto-tags -->
                    <div class="customer-type-tags" :title="typeInfoByCustomer[c.id]?.complete ? 'Customer types across the full accessible history' : 'Based on available activity. Complete customer types need backend sync.'">
                      <span v-for="type in typeInfoByCustomer[c.id]?.types || []" :key="type" class="customer-type-tag" :class="'customer-type-'+type">{{ CUSTOMER_TYPES.find(item=>item.value===type)?.label }}</span>
                      <span v-if="!typeInfoByCustomer[c.id]?.types.length" class="no-tags">{{ typeInfoByCustomer[c.id]?.complete ? 'No qualifying activity' : 'Customer type unavailable' }}</span>
                    </div>
                    <div class="auto-tags">
                      <span v-for="tag in (autoTagsByCustomer[c.id] || []).slice(0, 3)" :key="tag.name" class="auto-tag" :style="{ borderLeftColor: tag.color }">
                        {{ tag.name }}
                      </span>
                      <span v-if="(autoTagsByCustomer[c.id] || []).length > 3" class="auto-tag-more">
                        +{{ (autoTagsByCustomer[c.id] || []).length - 3 }}
                      </span>
                    </div>
                    <!-- Manual admin tags -->
                    <div v-if="customerTags(c).length" class="manual-tags">
                      <span v-for="tag in customerTags(c)" :key="tag" class="manual-tag">
                        <Tag :size="9" />{{ tag }}
                        <button class="tag-x" @click.stop="removeTag(c.id, tag)" aria-label="Close"><X :size="8" /></button>
                      </span>
                    </div>
                    <!-- Block indicators -->
                    <div v-if="(blocksByCustomer[c.id] || []).length" class="block-indicators">
                      <span v-for="b in (blocksByCustomer[c.id] || [])" :key="b.type + b.targetId"
                        class="block-chip" :class="'block-' + b.type"
                        :title="b.reason || undefined">
                        <Ban :size="9" />
                        {{ blockTargetName(b) }}
                        <button class="block-chip-x" @click.stop="removeBlock(c.id, b.type, b.targetId)" aria-label="Close"><X :size="8" /></button>
                      </span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Contact -->
              <td>
                <div class="contact-line"><Mail :size="12" />{{ c.email || '—' }}</div>
                <div class="contact-line muted"><Phone :size="12" />{{ c.phone || '—' }}</div>
                <div v-if="c.country_id" class="contact-line muted" style="margin-top:2px;">{{ countryName(c.country_id) }}</div>
                <div class="consent-row">
                  <template v-if="marketingChips(c).length">
                    <span v-for="ch in marketingChips(c)" :key="ch" class="consent-chip" title="Opted in to marketing">{{ ch }}</span>
                  </template>
                  <span v-else class="consent-none" title="Not opted in to any marketing channel">No marketing consent</span>
                </div>
              </td>

              <!-- Last Booking -->
              <td style="white-space:nowrap; color:var(--text-muted); font-size:13px;">{{ fmtDate(lastBookingByCustomer[c.id]) }}</td>

              <!-- Bookings count -->
              <td style="font-weight:600; text-align:center;">{{ scopedBookingsCount(c) }}</td>

              <!-- Spend by currency -->
              <td>
                <div v-if="customerSpendList(c.id).length" class="spend-cur-list">
                  <span v-for="[cur, amt] in customerSpendList(c.id)" :key="cur" class="spend-cur-chip">
                    {{ fmtCur(amt, cur) }}
                  </span>
                </div>
                <span v-else class="muted">—</span>
              </td>

              <!-- Subscription -->
              <td>
                <template v-if="subLabel(subForCustomer(c))">
                  <div class="sub-cell">
                    <span class="sub-pill" :class="subLabel(subForCustomer(c)).cls">
                      <GraduationCap :size="11" />
                      {{ subLabel(subForCustomer(c)).text }}
                    </span>
                    <span v-if="programName(subForCustomer(c))" class="sub-plan-tag">
                      {{ programName(subForCustomer(c)) }}
                    </span>
                  </div>
                </template>
                <span v-else style="color:var(--text-muted);font-size:12px;">—</span>
              </td>

              <!-- Tier (highest across countries) -->
              <td v-if="canSeeLoyalty">
                <span v-if="customerHighestTier(c.id)" class="badge" :class="tierBadge(customerHighestTier(c.id))"><Award :size="11" />{{ customerHighestTier(c.id) }}</span>
                <span v-else style="color:var(--text-muted);font-size:12px;">—</span>
              </td>

              <!-- Points (total + expandable per-country breakdown) -->
              <td v-if="canSeeLoyalty" class="pts-cell" style="text-align:center;">
                <template v-if="customerLoyaltyList(c.id).length">
                  <button class="pts-toggle" @click.stop="toggleLoyalty(c.id)" :title="expandedLoyalty.has(c.id) ? 'Hide breakdown' : 'Show per-country breakdown'">
                    {{ customerTotalPoints(c.id).toLocaleString() }}
                    <ChevronDown :size="12" :style="{ transform: expandedLoyalty.has(c.id) ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }" />
                  </button>
                  <div v-if="expandedLoyalty.has(c.id)" class="pts-breakdown" @click.stop>
                    <div v-for="r in customerLoyaltyList(c.id)" :key="r.country_id" class="pts-row">
                      <span class="pts-country">{{ r.countryName }}</span>
                      <span class="badge badge-sm" :class="tierBadge(r.tier)">{{ r.tier }}</span>
                      <span class="pts-amt">{{ r.points.toLocaleString() }} pts</span>
                    </div>
                  </div>
                </template>
                <span v-else style="color:var(--text-muted);">0</span>
              </td>

              <!-- Actions -->
              <td>
                <div class="row-actions">
                  <button class="btn-icon" title="View details" @click="openCustomer(c.id)"><Eye :size="15" /></button>
                  <button v-if="c.email" class="btn-icon" title="Email customer" @click="emailCustomer(c)"><Mail :size="15" /></button>
                  <button v-if="c.phone" class="btn-icon" style="color: var(--positive-ink)" title="WhatsApp" @click="whatsappCustomer(c)"><WhatsappIcon :size="15" /></button>
                  <button class="btn-icon" title="Booking history" @click="viewHistoryId = c.id"><History :size="15" /></button>
                  <button v-if="c.status === 'blocked' && isBookbollSide" class="btn-icon" style="color: var(--positive-ink)" title="Unblock customer" @click="unblockCustomerRow(c)"><CheckCircle :size="15" /></button>
                  <button v-else class="btn-icon" title="Block customer" @click="openBlockForRow(c)"><Ban :size="15" /></button>
                  <button v-if="isBookbollSide" class="btn-icon" title="Edit" @click="openEdit(c)"><Edit2 :size="15" /></button>
                  <button v-if="isBookbollSide" class="btn-icon danger" title="Delete" @click="remove(c.id)"><Trash2 :size="15" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="loadingList">
              <td colspan="10" style="padding:28px;text-align:center;color:var(--text-muted)">
                <Loader :size="18" style="display:inline;margin-right:8px" />Loading customers…
              </td>
            </tr>
            <tr v-else-if="directoryError"><td colspan="10" class="fin-error" role="alert">{{ directoryError }} <button v-if="customerTypeFilter.length" type="button" class="btn btn-secondary btn-sm" style="margin:8px" @click="customerTypeFilter=[]">Clear customer type filter</button></td></tr>
            <tr v-else-if="!filteredSorted.length">
              <td colspan="10" style="padding:0">
                <EmptyState :icon="Users"
                  :title="rosterTotal ? 'No matching customers' : 'No customers yet'"
                  :message="rosterTotal ? 'No customers match your search or filters. Try clearing them.' : 'Add your first customer to start building your CRM.'"
                  :action-label="rosterTotal ? '' : 'New Customer'" @action="openNew" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar
        :page="custPage" :perPage="custPerPage" :totalPages="custTotalPages"
        :total="matchingTotal" :visiblePages="custVisiblePages"
        @update:perPage="custPerPage=$event"
        @goTo="custGoTo"
      />
    </div>

    <!-- ── Modals ────────────────────────────────────────────────────────────── -->
    <CustomerHistoryModal :customerId="viewHistoryId" @close="viewHistoryId=null" @viewBooking="viewHistoryId=null; viewBookingId=$event" />
    <BookingDetailModal   :bookingId="viewBookingId"  @close="viewBookingId=null" @viewBooking="viewBookingId=$event" />

    <!-- Block dialog -->
    <div v-if="showBlockDialog" class="modal-backdrop" @click.self="showBlockDialog=false">
      <div class="modal" v-dialog style="max-width:460px;">
        <div class="modal-header">
          <div class="modal-title"><Ban :size="16" style="margin-right:6px;color:var(--error)"/>Block Customers</div>
          <button class="btn-icon" @click="showBlockDialog=false" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <p class="block-hint">
            Blocking <strong>{{ selectedIds.size }}</strong> customer{{ selectedIds.size !== 1 ? 's' : '' }}.
            {{ isBookbollSide ? 'Use the quick ban button or target specific locations, listings, or programs.' : 'Choose the venues, listings, or programs to block them from.' }}
          </p>

          <!-- Quick platform ban (Bookboll-side only) -->
          <template v-if="isBookbollSide">
            <button
              class="platform-ban-btn"
              :class="{ active: blockPreview.isPlatform }"
              @click="clearBlockTargets">
              <Ban :size="15" />
              Ban from Entire Platform
              <span v-if="blockPreview.isPlatform" class="platform-ban-active-dot"></span>
            </button>

            <!-- Divider -->
            <div class="block-or-divider"><span>or target specific</span></div>
          </template>

          <!-- 3 dropdowns -->
          <div class="block-dropdowns">
            <div class="form-group">
              <label class="form-label">Location</label>
              <MultiSelect v-model="blockForm.locationIds" :options="locationOptions" placeholder="Any location" />
            </div>
            <div class="form-group">
              <label class="form-label">
                Listing / Court
                <span v-if="blockForm.locationIds.length" class="form-hint auto-hint">
                  ↑ auto-filled from selected location{{ blockForm.locationIds.length > 1 ? 's' : '' }}
                </span>
              </label>
              <MultiSelect v-model="blockForm.listingIds" :options="blockListingOptions" placeholder="Any listing" />
            </div>
            <div class="form-group">
              <label class="form-label">
                Program
                <span v-if="blockForm.locationIds.length" class="form-hint auto-hint">
                  ↑ auto-filled from selected location{{ blockForm.locationIds.length > 1 ? 's' : '' }}
                </span>
              </label>
              <MultiSelect v-model="blockForm.programIds" :options="blockProgramOptions" placeholder="Any program" />
            </div>
          </div>

          <!-- Live preview -->
          <div class="block-preview">
            <div class="block-preview-header">
              <div class="block-preview-label">Will block from</div>
              <div class="block-preview-summary" :class="{ 'needs-scope': blockPreview.needsScope }">{{ blockPreview.summary }}</div>
            </div>
            <div class="block-preview-chips">
              <span v-for="item in blockPreview.items" :key="item.type + item.name"
                class="block-chip" :class="'block-' + item.type">
                <Ban :size="9" />{{ item.name }}
              </span>
            </div>
          </div>

          <!-- Reason -->
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Reason <span class="form-hint">optional — shown on hover</span></label>
            <input v-model="blockForm.reason" placeholder="e.g. Violation of terms, Abusive behavior…" />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showBlockDialog=false">Cancel</button>
          <button class="btn btn-danger" :disabled="blockPreview.needsScope"
            :title="blockPreview.needsScope ? 'Select a venue, listing or program first' : ''" @click="applyBlock">
            <Ban :size="14" />Block {{ selectedIds.size }} customer{{ selectedIds.size !== 1 ? 's' : '' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Tag dialog -->
    <div v-if="showTagDialog" class="modal-backdrop" @click.self="showTagDialog=false">
      <div class="modal" v-dialog style="max-width:440px;">
        <div class="modal-header">
          <div class="modal-title"><Tag :size="16" style="margin-right:6px;"/>Tag Customers</div>
          <button class="btn-icon" @click="showTagDialog=false" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Tag name <span class="form-hint">Separate multiple with commas</span></label>
            <input v-model="tagInput" placeholder='e.g. VIP, Follow Up, High Risk' @keyup.enter="applyTag" autofocus />
          </div>
          <div v-if="allTags.length" class="tag-suggestion-row">
            <span class="form-hint" style="margin-bottom:6px;display:block;">Existing tags</span>
            <div class="tag-suggestions">
              <button v-for="t in allTags" :key="t"
                class="tag-suggestion-chip"
                :class="{ active: tagInput.split(',').map(x=>x.trim()).includes(t) }"
                @click="toggleTagSuggestion(t)">{{ t }}</button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showTagDialog=false">Cancel</button>
          <button class="btn btn-primary" @click="applyTag" :disabled="!tagInput.trim()">
            Apply to {{ selectedIds.size }} customer{{ selectedIds.size !== 1 ? 's' : '' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Marketing email composer (consent-gated, record & preview) -->
    <div v-if="showEmailDialog" class="modal-backdrop" @click.self="showEmailDialog=false">
      <div class="modal mkt-modal" v-dialog>
        <div class="modal-header">
          <div class="modal-title"><Mail :size="16" style="margin-right:6px;"/>Marketing email</div>
          <button class="btn-icon" @click="showEmailDialog=false" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body mkt-body">
          <!-- Compose -->
          <div class="mkt-compose">
            <div class="email-recipients">
              <Users :size="14" />
              <span><strong>{{ selectedEmails.length }}</strong> opted-in recipient{{ selectedEmails.length !== 1 ? 's' : '' }}</span>
            </div>
            <div v-if="skippedNotOptedIn || skippedNoEmail" class="mkt-skip">
              <Info :size="12" />
              <span>
                <template v-if="skippedNotOptedIn">{{ skippedNotOptedIn }} skipped — not opted in to email marketing.</template>
                <template v-if="skippedNotOptedIn && skippedNoEmail"> </template>
                <template v-if="skippedNoEmail">{{ skippedNoEmail }} skipped — no email on file.</template>
              </span>
            </div>

            <!-- Mandatory: providers must connect their own sender to email their list -->
            <div v-if="mustConnectSender" class="mkt-gate">
              <MailCheck :size="18" />
              <div class="mkt-gate-txt">
                <strong>Connect your own email address to send marketing</strong>
                <span>Campaigns to your customer list must come from your own verified sender — not Bookboll's shared address.</span>
              </div>
              <button class="btn btn-primary btn-sm" @click="goToSender">Connect email <ChevronRight :size="14" /></button>
            </div>

            <div class="mkt-sender">
              From:
              <strong v-if="senderReady">{{ senderSmtp.from_name || emailSender.label }} &lt;{{ senderSmtp.from_email }}&gt;</strong>
              <template v-else><strong>{{ emailSender.label }}</strong><span v-if="emailSender.isProvider"> · not connected yet</span></template>
            </div>

            <div class="form-group">
              <label class="form-label">Subject</label>
              <input v-model="emailForm.subject" placeholder="e.g. Special offer for our members" autofocus />
            </div>
            <div class="form-group">
              <label class="form-label">Message</label>
              <textarea v-model="emailForm.body" rows="8" placeholder="Write your email… (blank lines start new paragraphs)" style="width:100%;resize:vertical;font-family:inherit;"></textarea>
            </div>
            <div class="email-note">
              <Info :size="13" />
              Every email automatically includes a <strong>one-click unsubscribe</strong> button and a disclaimer naming the sender — required to stay out of spam folders.
            </div>
            <div v-if="campaignSent" class="mkt-sent">
              <CheckCircle :size="14" /> Campaign queued for {{ selectedEmails.length }} opted-in customer{{ selectedEmails.length !== 1 ? 's' : '' }} — delivery is under way from your own email sender.
            </div>
          </div>

          <!-- Live compliant preview -->
          <div class="mkt-preview">
            <div class="mkt-preview-h"><Eye :size="13" /> Email preview</div>
            <iframe class="mkt-frame" :srcdoc="previewHtml" title="Email preview"></iframe>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showEmailDialog=false">Close</button>
          <button class="btn btn-primary" :disabled="!selectedEmails.length || !emailForm.subject.trim() || sendingCampaign || mustConnectSender" :title="mustConnectSender ? 'Connect your own email address first' : ''" @click="sendCampaign">
            <Mail :size="14" />{{ sendingCampaign ? 'Recording…' : (campaignSent ? 'Recorded ✓' : `Send to ${selectedEmails.length}`) }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Bulk WhatsApp campaign dialog ────────────────────────────────────── -->
    <div v-if="showWaDialog" class="modal-backdrop" @click.self="showWaDialog=false">
      <div class="modal mkt-modal" v-dialog>
        <div class="modal-header">
          <div class="modal-title"><WhatsappIcon :size="16" style="margin-right:6px;color:#25d366"/>WhatsApp campaign</div>
          <button class="btn-icon" @click="showWaDialog=false" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body mkt-body">
          <div class="mkt-compose">
            <div class="email-recipients">
              <Users :size="14" />
              <span><strong>{{ eligibleWaCustomers.length }}</strong> opted-in recipient{{ eligibleWaCustomers.length !== 1 ? 's' : '' }} with a phone number</span>
            </div>
            <div v-if="skippedNotOptedInWa || skippedNoPhone" class="mkt-skip">
              <Info :size="12" />
              <span>
                <template v-if="skippedNotOptedInWa">{{ skippedNotOptedInWa }} skipped — not opted in to WhatsApp marketing.</template>
                <template v-if="skippedNotOptedInWa && skippedNoPhone"> </template>
                <template v-if="skippedNoPhone">{{ skippedNoPhone }} skipped — no usable phone number.</template>
              </span>
            </div>

            <!-- Mandatory: the platform WhatsApp channel must be connected -->
            <div v-if="waConfig && !waConfigured" class="mkt-gate">
              <WhatsappIcon :size="18" />
              <div class="mkt-gate-txt">
                <strong>Connect the WhatsApp channel first</strong>
                <span>Campaigns send through Bookboll's WhatsApp Business number — it isn't set up yet.</span>
              </div>
              <button class="btn btn-primary btn-sm" @click="goToWhatsappSetup">Set up <ChevronRight :size="14" /></button>
            </div>

            <template>
              <div class="mkt-sender">Messages use a <strong>Meta-approved template</strong> — WhatsApp doesn't allow free-form marketing texts.</div>

              <!-- Template: pick an existing one, OR author a new one and submit to Meta -->
              <div class="form-group">
                <div class="wa-mode-tabs">
                  <button type="button" class="wa-mode-tab" :class="{ on: waMode === 'pick' }" @click="waMode = 'pick'">Use a template</button>
                  <button type="button" class="wa-mode-tab" :class="{ on: waMode === 'new' }" @click="waMode = 'new'; waTemplate = null"><Plus :size="13" /> New template</button>
                </div>

                <!-- NEW: author + submit to Meta for approval, right here -->
                <div v-if="waMode === 'new'" class="wa-newtpl">
                  <div class="wa-newtpl-row">
                    <label class="wa-newtpl-f"><span>Template name</span>
                      <input v-model="waNew.name" placeholder="e.g. summer_offer" spellcheck="false" />
                    </label>
                    <label class="wa-newtpl-f wa-newtpl-sm"><span>Category</span>
                      <select v-model="waNew.category"><option value="MARKETING">Marketing</option><option value="UTILITY">Utility</option></select>
                    </label>
                    <label class="wa-newtpl-f wa-newtpl-xs"><span>Language</span>
                      <input v-model="waNew.language" placeholder="en_US" spellcheck="false" />
                    </label>
                  </div>
                  <div class="wa-newtpl-f"><span>Photo or video <em>optional</em></span>
                    <div class="wa-img-pick">
                      <img v-if="waNew.headerImageUrl" :src="waNew.headerImageUrl" alt="Uploaded photo" /><video v-if="waNew.headerVideoUrl" :src="waNew.headerVideoUrl" controls preload="metadata" />
                      <label class="btn btn-secondary btn-sm wa-img-btn" :class="{ busy: waImageUploading === 'new' }">
                        <component :is="waImageUploading === 'new' ? Loader : ImagePlus" :size="14" :class="{ 'spin-icon': waImageUploading === 'new' }" />
                        {{ waImageUploading === 'new' ? 'Uploading…' : (waNew.headerImageUrl || waNew.headerVideoUrl ? 'Change media' : 'Add photo or video') }}
                        <input type="file" accept="image/jpeg,image/png,video/mp4" :disabled="!!waImageUploading" @change="pickWaImage($event, 'new')" />
                      </label>
                      <button v-if="waNew.headerImageUrl || waNew.headerVideoUrl" type="button" class="link-btn" @click="waNew.headerImageUrl = ''; waNew.headerVideoUrl = ''; waMediaDimensions = ''">Remove</button>
                    </div>
                    <div class="form-hint">Photos: JPEG/PNG up to 5 MB. Videos: MP4 (H.264/AAC), up to 16 MB. Your original proportions are preserved.</div>
                  </div>
                  <label class="wa-newtpl-f"><span>Message</span>
                    <textarea v-model="waNew.body" rows="4" :placeholder="WA_TPL_PLACEHOLDER"></textarea>
                  </label>
                  <div class="form-hint">
                    Use <code v-text="'{{1}}'"></code>, <code v-text="'{{2}}'"></code> where you want personalized values (e.g. the customer's name) — you'll fill them after submitting.
                    Meta reviews marketing templates (usually minutes to a few hours). Once approved, your campaign auto-sends.
                  </div>
                  <button type="button" class="btn btn-secondary btn-sm wa-newtpl-submit" :disabled="waCreating || !!waImageUploading || !waConfigured" @click="submitNewTemplate">
                    <component :is="waCreating ? Loader : Send" :size="14" :class="{ 'spin-icon': waCreating }" />
                    {{ waCreating ? 'Submitting to Meta…' : 'Submit to Meta &amp; use' }}
                  </button>
                </div>

                <template v-else>
                <label class="form-label">Template</label>
                <div v-if="waTemplatesState === 'loading'" class="wa-tpl-loading"><Info :size="13" /> Loading your approved templates…</div>
                <div v-else-if="waTemplatesState === 'ready'" class="wa-tpl-list">
                  <button
                    v-for="t in waTemplates" :key="t.name + t.language" type="button"
                    class="wa-tpl" :class="{ active: waTemplate?.name === t.name && waTemplate?.language === t.language }"
                    @click="pickWaTemplate(t)"
                  >
                    <span class="wa-tpl-name">{{ t.name }}<span v-if="t.status === 'PENDING'" class="wa-tpl-pending">In review — queues &amp; auto-sends on approval</span></span>
                    <span class="wa-tpl-meta">{{ t.language }} · {{ String(t.category || '').toLowerCase() }}<template v-if="t.headerFormat === 'IMAGE'"> · <ImagePlus :size="11" /> image</template></span>
                  </button>
                </div>
                <div v-else class="wa-manual">
                  <input v-model="waManual.name" placeholder="Template name (e.g. hello_world)" spellcheck="false" />
                  <input v-model="waManual.language" placeholder="en_US" spellcheck="false" style="max-width:110px" />
                  <div class="form-hint">
                    Couldn't load your templates yet. You don't need Meta's website for this —
                    templates are created, submitted for Meta review and tracked right here in Bookboll:
                    finish the guided connection on the <router-link to="/whatsapp" class="wa-hint-link">WhatsApp page</router-link>
                    (WhatsApp Business Account ID + access token), then use its <b>Message templates</b> section.
                    Approved templates appear in this picker automatically, and campaigns composed against
                    an in-review template queue and auto-send the moment Meta approves it.
                  </div>
                </div>
                </template>
              </div>

              <!-- Header image: required by an IMAGE template, swappable per campaign -->
              <div v-if="waNeedsImage" class="form-group">
                <label class="form-label">{{ waTemplate.headerFormat === 'VIDEO' ? 'Header video' : 'Header photo' }} <span class="req">*</span></label>
                <div class="wa-img-pick">
                  <img v-if="waHeaderImage" :src="waHeaderImage" alt="Uploaded photo" /><video v-if="waHeaderVideo" :src="waHeaderVideo" controls preload="metadata" />
                  <label class="btn btn-secondary btn-sm wa-img-btn" :class="{ busy: waImageUploading === 'campaign' }">
                    <component :is="waImageUploading === 'campaign' ? Loader : ImagePlus" :size="14" :class="{ 'spin-icon': waImageUploading === 'campaign' }" />
                    {{ waImageUploading === 'campaign' ? 'Uploading…' : (waHeaderImage || waHeaderVideo ? 'Change media' : 'Add media') }}
                    <input type="file" accept="image/jpeg,image/png,video/mp4" :disabled="!!waImageUploading" @change="pickWaImage($event, 'campaign')" />
                  </label>
                  <button v-if="waHeaderImage && waTemplate.headerImage && waHeaderImage !== waTemplate.headerImage" type="button" class="link-btn" @click="waHeaderImage = waTemplate.headerImage">Use template's image</button>
                </div>
                <div class="form-hint">{{ waHeaderImage || waHeaderVideo ? 'Sent above the message, with its original proportions.' : 'Upload the media type required by this template.' }}</div>
              </div>

              <!-- Variables -->
              <div v-if="waTemplate && waVariables.length" class="form-group">
                <label class="form-label">Template variables</label>
                <div v-for="(v, i) in waVariables" :key="i" class="wa-var-row">
                  <span class="wa-var-n" v-text="'{{' + (i + 1) + '}}'"></span>
                  <input v-model="waVariables[i]" :placeholder="'Value — use {name} for the customer\'s first name'" />
                </div>
                <div class="form-hint"><code>{name}</code> is replaced per customer with their first name.</div>
              </div>

              <div v-if="waResult && waResult.queued" class="mkt-sent">
                <History :size="14" /> Queued — auto-sends to {{ Math.min(eligibleWaCustomers.length, 200) }} customer{{ Math.min(eligibleWaCustomers.length, 200) !== 1 ? 's' : '' }} the moment Meta approves this template<template v-if="waResult.dropped"> · {{ waResult.dropped }} beyond the 200-per-run limit</template>. Track it on the WhatsApp page.
              </div>
              <div v-else-if="waResult" class="mkt-sent">
                <CheckCircle :size="14" /> Sent to {{ waResult.sent }} customer{{ waResult.sent !== 1 ? 's' : '' }}<template v-if="waResult.failed"> · {{ waResult.failed }} failed (see the WhatsApp page log)</template><template v-if="waResult.dropped"> · {{ waResult.dropped }} not sent — 200-per-run limit, run another campaign for the rest</template>.
              </div>
            </template>
          </div>

          <!-- WhatsApp-style preview -->
          <div class="mkt-preview">
            <div class="mkt-preview-h"><Eye :size="13" /> Message preview</div>
            <div class="wa-chat">
              <div class="wa-bubble">
                <img v-if="waPreviewMedia.image" :src="waPreviewMedia.image" alt="Message header preview" class="wa-bubble-img" @load="waMediaLoaded" /><video v-if="waPreviewMedia.video" :src="waPreviewMedia.video" class="wa-bubble-img" controls playsinline preload="metadata" @loadedmetadata="waMediaLoaded" />
                <div class="wa-bubble-body">{{ waPreviewBody || 'Pick a template to preview it.' }}</div>
                <div class="wa-bubble-meta">{{ fmt12(new Date()) }} ✓✓</div>
              </div>
              <div v-if="waPreviewMedia.image || waPreviewMedia.video" class="wa-chat-note">{{ waMediaDimensions }} · Full media shown without cropping. WhatsApp layout varies by device.</div>
              <div v-if="eligibleWaCustomers[0]" class="wa-chat-note">Preview personalized for {{ eligibleWaCustomers[0].full_name }}</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showWaDialog=false">Close</button>
          <button
            class="btn btn-primary"
            :disabled="!eligibleWaCustomers.length || waSending || !waConfigured || (waMode === 'pick' && waTemplatesState === 'loading') || !waTemplateChosen || waVarsMissing || waImageMissing || !!waImageUploading || !!waResult"
            :title="!waConfigured ? 'Connect the WhatsApp channel first' : (waMode === 'new' && !waTemplate ? 'Submit your template to Meta first' : (waVarsMissing ? 'Fill in every template variable first' : ''))"
            @click="sendWaCampaign"
          >
            <WhatsappIcon :size="14" />{{ waSending ? (waTemplate?.status === 'PENDING' ? 'Queuing…' : 'Sending…') : (waResult ? 'Sent ✓' : (waTemplate?.status === 'PENDING' ? `Queue for ${Math.min(eligibleWaCustomers.length, 200)}` : `Send to ${Math.min(eligibleWaCustomers.length, 200)}`)) }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="requestCloseForm">
      <div class="modal" v-dialog="requestCloseForm" @input="markDirty" @change="markDirty">
        <div class="modal-header">
          <div class="modal-title">{{ editing ? 'Edit Customer' : 'New Customer' }}</div>
          <button class="btn-icon" @click="requestCloseForm" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Full Name <span class="req-star">*</span></label>
            <input aria-label="Customer full name" v-model="form.full_name" :class="{ 'input-invalid': triedSave && !form.full_name?.trim() }" />
            <div v-if="triedSave && !form.full_name?.trim()" class="field-error-text"><Info :size="12" />Name is required</div>
          </div>
          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">Email</label><input aria-label="Customer email" v-model="form.email" type="email" :class="{ 'input-invalid': triedSave && form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) }" /></div>
            <div class="form-group"><label class="form-label">Phone</label><input aria-label="Customer phone" v-model="form.phone" /></div>
            <div class="form-group"><label class="form-label">Gender</label>
              <select aria-label="Gender" v-model="form.gender"><option value="">—</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
            </div>
            <div class="form-group"><label class="form-label">Country</label>
              <select aria-label="Country" v-model="form.country_id"><option value="">Select</option><option v-for="co in countries" :key="co.id" :value="co.id">{{ co.name }}</option></select>
            </div>
          </div>

          <!-- Per-country loyalty points (each country has its own balance + tier) -->
          <div v-if="canSeeLoyalty" class="form-group">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
              <label class="form-label" style="margin:0;">Loyalty points by country</label>
              <button type="button" class="link-btn" @click="addLoyaltyRow"><Plus :size="12" />Add country</button>
            </div>
            <div v-if="!form.loyalty || !form.loyalty.length" class="muted" style="font-size:12.5px;padding:2px 0 4px;">
              No loyalty balances yet. Add a country — its tier is auto-derived from lifetime points.
            </div>
            <div v-for="(row, i) in form.loyalty" :key="i" class="loyalty-edit-row">
              <select v-model="row.country_id" style="flex:1.4;">
                <option value="">Select country</option>
                <option v-for="co in countries" :key="co.id" :value="co.id">{{ co.name }}</option>
              </select>
              <input type="number" min="0" v-model.number="row.points" placeholder="Balance" title="Current spendable balance" style="flex:1;" />
              <input type="number" min="0" v-model.number="row.lifetime_points" placeholder="Lifetime" title="Lifetime earned — determines tier" style="flex:1;" />
              <span class="badge badge-sm" :class="tierBadge(previewTier(row))" style="flex-shrink:0;">{{ previewTier(row) }}</span>
              <button type="button" class="btn-icon danger" @click="removeLoyaltyRow(i)" title="Remove"><Trash2 :size="14" /></button>
            </div>
            <div v-if="form.loyalty && form.loyalty.length" class="muted" style="font-size:11.5px;margin-top:4px;">
              <strong>Balance</strong> = spendable now · <strong>Lifetime</strong> = total ever earned (sets the tier).
            </div>
          </div>
          <div class="form-group"><label class="form-label">Status</label>
            <select aria-label="Customer status" v-model="form.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="requestCloseForm">Cancel</button>
          <button class="btn btn-primary" @click="save">Save</button>
        </div>
      </div>
    </div>

    <!-- ── Export modal ───────────────────────────────────────────────────── -->

  </div>
</template>

<style scoped>
/* Per-country loyalty points */
.pts-toggle { display: inline-flex; align-items: center; gap: 4px; background: none; border: none; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text); padding: 2px 4px; border-radius: 6px; }
.pts-toggle:hover { background: var(--bg, #f1f5f9); }
.pts-cell { position: relative; }
.pts-breakdown { position: absolute; right: 4px; top: calc(100% - 2px); z-index: 20; display: flex; flex-direction: column; gap: 5px; padding: 8px 10px; min-width: 215px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 9px; box-shadow: 0 10px 28px rgba(15,23,42,0.16); text-align: left; }
.pts-row { display: flex; align-items: center; gap: 8px; font-size: 12px; white-space: nowrap; }
.pts-country { flex: 1; color: var(--text); font-weight: 500; text-align: left; }
.pts-amt { color: var(--text-muted); font-weight: 600; }
.badge-sm { font-size: 10px; padding: 1px 6px; }
.loyalty-edit-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.loyalty-edit-row select, .loyalty-edit-row input { min-width: 0; }
/* ── Spend-by-currency cell ─────────────────────────────────────────────────────── */
.spend-cur-list { display: flex; flex-direction: column; gap: 3px; }
.spend-cur-chip { font-size: 12.5px; font-weight: 600; color: var(--text); white-space: nowrap; }
.spend-cur-chip:not(:first-child) { font-size: 11.5px; font-weight: 500; color: var(--text-muted); }

/* ── Bulk select-all link + email dialog ────────────────────────────────────────── */
.bulk-selectall-link { margin-left: 10px; font-size: 12px; font-weight: 600; color: #fff; text-decoration: underline; background: none; border: none; cursor: pointer; opacity: .9; }
.bulk-selectall-link:hover { opacity: 1; }
.email-recipients { display: flex; align-items: center; gap: 7px; padding: 10px 12px; background: var(--info-bg, #eff6ff); border: 1px solid var(--info-border, #bfdbfe); border-radius: 8px; font-size: 13px; color: var(--info-ink, #1e40af); margin-bottom: 10px; }
.email-skip { color: var(--warning-ink, #92400e); font-size: 12px; }
.email-note { display: flex; align-items: flex-start; gap: 7px; font-size: 12px; color: var(--text-muted); margin-top: 4px; line-height: 1.5; }

/* Marketing-consent chips in the customers table */
.consent-row { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.consent-chip { display: inline-flex; align-items: center; font-size: 10px; font-weight: 800; color: var(--positive-ink, #15803d); background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 999px; padding: 1px 7px; letter-spacing: .02em; }
.consent-none { font-size: 10.5px; color: var(--text-muted); font-style: italic; }

/* Marketing email composer (two-pane: compose + live preview) */
.mkt-modal { max-width: 880px; width: 100%; }
.mkt-body { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }
.mkt-compose { min-width: 0; }
.mkt-sender { font-size: 12.5px; color: var(--text-muted); margin-bottom: 12px; }
.mkt-gate { display: flex; align-items: center; gap: 11px; padding: 12px 14px; margin-bottom: 12px; border: 1px solid var(--primary-border, #99f6e4); background: var(--primary-soft, #f0fdfa); border-radius: 10px; }
.mkt-gate > svg { color: var(--primary-ink); flex-shrink: 0; }
.mkt-gate-txt { flex: 1; min-width: 0; }
.mkt-gate-txt strong { display: block; font-size: 13px; color: var(--text); }
.mkt-gate-txt span { font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.mkt-gate .btn-sm { flex-shrink: 0; display: inline-flex; align-items: center; gap: 3px; }

/* WhatsApp campaign dialog */
.wa-tpl-loading { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted); }
.wa-tpl-list { display: flex; flex-direction: column; gap: 6px; max-height: 200px; overflow-y: auto; }
.wa-tpl { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; text-align: start; border: 1.5px solid var(--border); background: var(--surface, #ffffff); border-radius: 10px; padding: 9px 12px; cursor: pointer; }
.wa-tpl:hover { border-color: #86efac; }
.wa-tpl.active { border-color: #25d366; background: var(--positive-bg, #f0fdf4); }
.wa-tpl-name { font-size: 13px; font-weight: 700; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.wa-tpl-pending { display: inline-block; margin-inline-start: 7px; font-family: inherit; font-size: 10.5px; font-weight: 800; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fef3c7); padding: 2px 8px; border-radius: 999px; vertical-align: 1px; }
.wa-tpl-meta { font-size: 11px; color: var(--text-muted); flex-shrink: 0; }
.wa-manual { display: flex; gap: 8px; flex-wrap: wrap; }
.wa-manual input { flex: 1; min-width: 180px; }
.wa-manual .form-hint { flex-basis: 100%; line-height: 1.5; }
.wa-hint-link { color: var(--primary-ink, #0f766e); font-weight: 800; text-decoration: underline; }
/* Pick-existing vs. author-new mode tabs */
.wa-mode-tabs { display: inline-flex; gap: 4px; padding: 3px; background: var(--bg, #f1f5f9); border-radius: 10px; margin-bottom: 10px; }
.wa-mode-tab { display: inline-flex; align-items: center; gap: 5px; border: 0; background: transparent; color: var(--text-muted); font-size: 12.5px; font-weight: 700; padding: 6px 12px; border-radius: 8px; cursor: pointer; }
.wa-mode-tab.on { background: var(--surface, #ffffff); color: var(--primary-ink, #0f766e); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
/* Inline new-template author form */
.wa-newtpl { display: flex; flex-direction: column; gap: 10px; border: 1.5px dashed #86efac; background: var(--positive-bg, #f0fdf4); border-radius: 12px; padding: 12px; }
.wa-newtpl-row { display: flex; gap: 8px; flex-wrap: wrap; }
.wa-newtpl-f { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 160px; }
.wa-newtpl-f > span { font-size: 11.5px; font-weight: 700; color: var(--text-muted); }
.wa-newtpl-f input, .wa-newtpl-f select, .wa-newtpl-f textarea { width: 100%; }
.wa-newtpl-f textarea { resize: vertical; min-height: 84px; line-height: 1.5; }
.wa-newtpl-sm { flex: 0 0 140px; min-width: 120px; }
.wa-newtpl-xs { flex: 0 0 110px; min-width: 96px; }
.wa-newtpl .form-hint code { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #166534); padding: 1px 5px; border-radius: 5px; font-weight: 700; }
.wa-newtpl-submit { align-self: flex-start; }
.spin-icon { animation: wa-spin 0.9s linear infinite; }
@keyframes wa-spin { to { transform: rotate(360deg); } }
.wa-var-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.wa-var-n { font-size: 11.5px; font-weight: 700; color: var(--primary-ink, #0f766e); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; flex-shrink: 0; width: 44px; }
.wa-var-row input { flex: 1; }
/* WhatsApp-style chat preview */
.wa-chat { background: #e5ddd5 url('') center/cover; background-color: #e5ddd5; border-radius: 12px; padding: 18px 14px; min-height: 140px; }
.wa-bubble { background: #dcf8c6; border-radius: 10px 10px 10px 2px; padding: 8px 10px 6px; max-width: 92%; box-shadow: 0 1px 1px rgba(0,0,0,.12); }
.wa-bubble-body { font-size: 13px; line-height: 1.5; color: #111b21; white-space: pre-wrap; word-break: break-word; }
.wa-bubble-img { display: block; width: 100%; height: auto; max-height: 420px; object-fit: contain; border-radius: 8px; margin: -2px 0 8px; }
.wa-img-pick { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.wa-img-pick img, .wa-img-pick video { width: 92px; height: 60px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border); }
.wa-img-btn { position: relative; cursor: pointer; }
.wa-img-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.wa-img-btn.busy { pointer-events: none; opacity: .7; }
.wa-tpl-meta svg { vertical-align: -1px; }
.wa-bubble-meta { font-size: 10.5px; color: #667781; text-align: right; margin-top: 3px; }
.wa-chat-note { font-size: 11px; color: #54656f; text-align: center; margin-top: 10px; }
.mkt-sender strong { color: var(--text); }
.mkt-skip { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 8px; padding: 8px 10px; margin-bottom: 12px; line-height: 1.45; }
.mkt-sent { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; font-weight: 600; color: var(--positive-ink, #15803d); background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 8px; padding: 9px 11px; margin-top: 12px; line-height: 1.45; }
.mkt-preview { min-width: 0; }
.mkt-preview-h { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-bottom: 8px; }
.mkt-frame { width: 100%; height: 460px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg, #f1f5f9); }
@media (max-width: 760px) {
  .mkt-body { grid-template-columns: 1fr; }
  .mkt-frame { height: 260px; }
  .wa-tpl-list { max-height: 150px; }
}

/* ── Export modal ───────────────────────────────────────────────────────────────── */
.export-choice-btn {
  display:flex;align-items:center;gap:14px;padding:13px 16px;
  background:var(--bg);border:1.5px solid var(--border);border-radius:10px;
  cursor:pointer;text-align:left;width:100%;transition:all .15s;
}
.export-choice-btn:hover { border-color:var(--primary);background:var(--info-bg, #f0f9ff); }
.export-choice-btn svg { color:var(--primary-ink);flex-shrink:0; }
.export-choice-btn strong { font-size:13.5px;color:var(--text); }
.export-choice-btn span { font-size:12px;color:var(--text-muted); }

/* ── Table ─────────────────────────────────────────────────────────────────────── */
.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.sortable:hover { background: var(--surface-hover, #f8fafc); }
.sort-icon { display: inline-flex; align-items: center; margin-left: 4px; vertical-align: middle; color: var(--text-muted); }
.muted-icon { opacity: 0.35; }

/* ── Customer cell ─────────────────────────────────────────────────────────────── */
.cell-main { display: flex; align-items: flex-start; gap: 10px; }
.avatar-sm { width: 36px; height: 36px; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; margin-top: 2px; }
.cell-name-col { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.cell-name { font-weight: 600; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cust-name-btn { background: none; border: none; padding: 0; font: inherit; font-weight: 600; color: var(--text); cursor: pointer; }
.cust-name-btn:hover { color: var(--primary-ink, #0f766e); text-decoration: underline; }

/* Auto-tags */
.auto-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.auto-tag {
  display: inline-flex; align-items: center;
  font-size: 11px; color: var(--text-muted);
  background: var(--bg, #f1f5f9); border-left: 3px solid #64748b;
  padding: 1px 6px 1px 5px; border-radius: 0 4px 4px 0;
  white-space: nowrap; max-width: 140px; overflow: hidden; text-overflow: ellipsis;
}
.auto-tag-more { font-size: 11px; color: var(--text-muted); padding: 1px 4px; background: var(--border, #e2e8f0); border-radius: 4px; }
.no-tags { font-size: 11px; color: var(--text-light, #cbd5e1); font-style: italic; }

/* Contact */
.contact-line { display: flex; align-items: center; gap: 5px; font-size: 12.5px; }
.contact-line.muted { color: var(--text-muted); }

/* Subscription cell */
.sub-cell { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.sub-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.sub-active { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #065f46); }
.sub-trial  { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.sub-ex     { background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }
.sub-plan-tag {
  font-size: 11px;
  color: var(--text-muted);
  background: var(--bg, #f1f5f9);
  border-left: 2px solid #94a3b8;
  padding: 1px 6px 1px 5px;
  border-radius: 0 4px 4px 0;
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Toolbar ───────────────────────────────────────────────────────────────────── */
.tier-pills { display: flex; gap: 4px; }
.tier-pill {
  padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 500;
  border: 1px solid var(--border); color: var(--text-muted); background: var(--surface, #ffffff);
  cursor: pointer; transition: all 0.12s;
}
.tier-pill:hover { border-color: var(--primary); color: var(--primary-ink); }
.tier-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }

.filter-btn { display: flex; align-items: center; gap: 6px; position: relative; }
.filter-btn.filter-active { border-color: var(--primary); color: var(--primary-ink); }
.filter-badge { background: var(--primary); color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 0 6px; min-width: 18px; text-align: center; }

/* ── Filter panel ──────────────────────────────────────────────────────────────── */
.filter-panel {
  padding: 18px 20px;
  background: var(--surface-hover, #f8fafc);
  border-bottom: 1px solid var(--border);
}

/* Multi-select dropdown row */
.filter-ms-row {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}
.filter-ms-col { display: flex; flex-direction: column; gap: 5px; }

/* Labels */
.filter-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
}

/* Dividers */
.filter-divider { border: none; border-top: 1px solid var(--border); margin: 14px 0; }

/* Chip rows */
.filter-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 10px;
  align-items: flex-start;
}
.chip-group {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
}
.chip-group-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
  white-space: nowrap;
  margin-right: 2px;
}
.chip {
  padding: 4px 11px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 500;
  border: 1px solid var(--border);
  color: var(--text-muted);
  background: var(--surface, #ffffff);
  cursor: pointer;
  transition: all 0.12s;
}
.chip:hover { border-color: var(--primary); color: var(--primary-ink); }
.chip.active { background: var(--primary); color: #fff; border-color: var(--primary); }

/* Range inputs */
.filter-ranges {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  margin-bottom: 16px;
  align-items: flex-end;
}
.range-group { display: flex; flex-direction: column; gap: 6px; }
.range-inputs { display: flex; align-items: center; gap: 6px; }
.range-sep { color: var(--text-muted); font-size: 14px; font-weight: 500; }
.range-input {
  width: 100px;
  height: 34px;
  padding: 0 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 13px;
  color: var(--text);
  background: var(--surface, #ffffff);
}
.range-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(20,184,166,0.12); }
.date-input { width: 140px; }

/* Filter footer */
.filter-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 4px;
}

/* ── Checkbox column ───────────────────────────────────────────────────────────── */
.th-check, .td-check { width: 40px; padding-left: 16px !important; padding-right: 4px !important; }
.row-checkbox { width: 15px; height: 15px; accent-color: var(--primary); cursor: pointer; display: block; }

/* ── Row states ────────────────────────────────────────────────────────────────── */
.row-selected td { background: var(--primary-soft) !important; }
.row-blocked td  { background: var(--danger-bg) !important; opacity: 0.8; }

/* ── Blocked badge ─────────────────────────────────────────────────────────────── */
.blocked-badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #dc2626);
  padding: 1px 6px; border-radius: 4px; margin-left: 6px; vertical-align: middle;
}

/* ── Manual tags ───────────────────────────────────────────────────────────────── */
.manual-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 2px; }
.manual-tag {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 500;
  background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #6d28d9);
  padding: 2px 4px 2px 6px; border-radius: 4px;
  white-space: nowrap;
}
.tag-x {
  display: inline-flex; align-items: center; justify-content: center;
  width: 14px; height: 14px; border-radius: 3px;
  color: var(--purple-ink, #6d28d9); cursor: pointer; opacity: 0.6;
}
.tag-x:hover { opacity: 1; background: rgba(109,40,217,0.15); }

/* ── Bulk action bar ───────────────────────────────────────────────────────────── */
.bulk-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 20px;
  background: linear-gradient(90deg, #0f766e 0%, #0d9488 100%);
  color: #fff;
  font-size: 13px;
}
.bulk-info { display: flex; align-items: center; gap: 8px; font-weight: 500; }
.bulk-check-icon { opacity: 0.8; display: flex; }
.bulk-actions { display: flex; align-items: center; gap: 8px; }
.bulk-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 12px; border-radius: 7px; font-size: 12px; font-weight: 600;
  background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25);
  cursor: pointer; transition: all 0.15s;
}
.bulk-btn:hover   { background: rgba(255,255,255,0.28); }
.bulk-btn.danger  { background: rgba(220,38,38,0.25);  border-color: rgba(220,38,38,0.5); }
.bulk-btn.danger:hover  { background: rgba(220,38,38,0.4); }
.bulk-btn.success { background: rgba(5,150,105,0.3);  border-color: rgba(5,150,105,0.5); }
.bulk-btn.success:hover { background: rgba(5,150,105,0.45); }
.bulk-deselect {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 6px;
  color: rgba(255,255,255,0.7); cursor: pointer; transition: all 0.15s;
}
.bulk-deselect:hover { background: rgba(255,255,255,0.15); color: #fff; }

/* Bulk bar transition */
.bulk-slide-enter-active, .bulk-slide-leave-active { transition: all 0.2s ease; overflow: hidden; }
.bulk-slide-enter-from, .bulk-slide-leave-to { opacity: 0; max-height: 0; padding-top: 0; padding-bottom: 0; }
.bulk-slide-enter-to, .bulk-slide-leave-from { opacity: 1; max-height: 60px; }

/* ── Block indicators (in customer cell) ───────────────────────────────────────── */
.block-indicators { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.block-chip {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 600;
  padding: 2px 4px 2px 6px; border-radius: 4px;
  white-space: nowrap; max-width: 190px; overflow: hidden; text-overflow: ellipsis;
  cursor: default;
}
.block-platform { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #b91c1c); }
.block-location  { background: var(--warning-bg, #ffedd5); color: var(--warning-ink, #c2410c); }
.block-listing   { background: var(--warning-bg, #fef9c3); color: var(--warning-ink, #a16207); }
.block-program   { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #6d28d9); }
.block-chip-x {
  display: inline-flex; align-items: center; justify-content: center;
  width: 14px; height: 14px; border-radius: 3px; opacity: 0.55; cursor: pointer;
}
.block-chip-x:hover { opacity: 1; background: rgba(0,0,0,0.1); }

/* ── Block dialog ──────────────────────────────────────────────────────────────── */
.block-hint { font-size: 13px; color: var(--text-muted); margin-bottom: 14px; line-height: 1.5; }
.block-hint strong { color: var(--text); }

/* Quick platform-ban button */
.platform-ban-btn {
  width: 100%;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 11px 16px; border-radius: 10px; font-size: 14px; font-weight: 600;
  border: 2px dashed var(--danger-border, #fca5a5); background: var(--danger-bg); color: var(--danger-ink, #dc2626);
  cursor: pointer; transition: all 0.18s; position: relative;
  margin-bottom: 4px;
}
.platform-ban-btn:hover {
  background: var(--danger-bg, #fee2e2); border-color: #ef4444; border-style: solid;
}
.platform-ban-btn.active {
  background: var(--danger-bg, #fee2e2); border-color: #dc2626; border-style: solid;
  box-shadow: 0 0 0 3px rgba(220,38,38,0.12);
}
.platform-ban-active-dot {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  width: 8px; height: 8px; border-radius: 50%; background: #dc2626;
  box-shadow: 0 0 0 3px rgba(220,38,38,0.25);
  animation: pulse-red 1.4s ease-in-out infinite;
}
@keyframes pulse-red {
  0%, 100% { box-shadow: 0 0 0 3px rgba(220,38,38,0.25); }
  50%       { box-shadow: 0 0 0 6px rgba(220,38,38,0.08); }
}

/* "or target specific" divider */
.block-or-divider {
  display: flex; align-items: center; gap: 10px;
  margin: 14px 0 12px; color: var(--text-muted); font-size: 11px;
  font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
}
.block-or-divider::before,
.block-or-divider::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

.block-dropdowns { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.block-preview {
  padding: 12px 14px; background: var(--surface-hover, #f8fafc);
  border: 1px solid var(--border); border-radius: 8px;
}
.block-preview-header {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;
}
.block-preview-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--text-muted);
}
.block-preview-summary {
  font-size: 12px; font-weight: 600; color: var(--primary-ink);
}
.block-preview-summary.needs-scope { color: var(--warning-ink, #d97706); font-style: italic; font-weight: 500; }
.block-preview-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.auto-hint { color: var(--primary-ink); font-weight: 500; }

/* btn-danger */
.btn-danger {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 600;
  background: var(--error); color: #fff; border: none; cursor: pointer; transition: all 0.15s;
}
.btn-danger:hover { background: #b91c1c; }
.btn-danger:disabled { opacity: 0.45; cursor: not-allowed; }

/* ── Tag dialog extras ─────────────────────────────────────────────────────────── */
.form-hint { font-size: 11px; color: var(--text-muted); font-weight: 400; margin-left: 6px; }
.tag-suggestion-row { margin-top: 4px; }
.tag-suggestions { display: flex; flex-wrap: wrap; gap: 6px; }
.tag-suggestion-chip {
  padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 500;
  border: 1px solid var(--purple-border, #ddd6fe); background: var(--purple-bg, #f5f3ff); color: var(--purple-ink, #6d28d9);
  cursor: pointer; transition: all 0.12s;
}
.tag-suggestion-chip:hover  { background: var(--purple-bg, #ede9fe); }
.tag-suggestion-chip.active { background: #6d28d9; color: #fff; border-color: #6d28d9; }

/* ── Row actions ───────────────────────────────────────────────────────────────── */
.row-actions { display: flex; gap: 4px; }
.btn-icon { padding: 7px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: var(--danger-bg, #fee2e2); color: var(--error); }

.modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--border); }

@media (max-width: 900px) {
  .filter-ms-row { grid-template-columns: repeat(2, 1fr); }
  .filter-ranges { flex-direction: column; gap: 12px; }
}

/* ── Phone (≤600px) ────────────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  /* Bulk action bar: stack info over actions; buttons wrap into tappable rows */
  .bulk-bar { position: relative; flex-direction: column; align-items: stretch; gap: 8px; padding: 10px 14px; }
  .bulk-info { flex-wrap: wrap; padding-right: 30px; }
  .bulk-actions { flex-wrap: wrap; }
  .bulk-btn { flex: 1 1 auto; justify-content: center; min-height: 40px; }
  .bulk-deselect { position: absolute; top: 8px; right: 8px; }
  .bulk-slide-enter-to, .bulk-slide-leave-from { max-height: 260px; }

  /* Filter panel: single-column dropdowns, tighter padding */
  .filter-panel { padding: 14px 14px; }
  .filter-ms-row { grid-template-columns: 1fr; gap: 10px; }

  /* Range + date inputs fill the row; 16px font stops iOS auto-zoom */
  .range-group { width: 100%; }
  .range-inputs { width: 100%; }
  .range-input, .date-input { flex: 1; width: auto; min-width: 0; }
  .range-input, .filter-ranges select { font-size: 16px; height: 40px; }

  /* Loyalty editor: country select takes a full row, number inputs share the next
     (!important beats the template's inline flex values — mobile only) */
  .loyalty-edit-row { flex-wrap: wrap; }
  .loyalty-edit-row select { flex: 1 1 100% !important; }
  .loyalty-edit-row input { flex: 1 1 40% !important; min-width: 0; font-size: 16px; }

  /* Sheet footers: global style.css already stacks buttons full-width;
     just guarantee comfortable tap height + tighter spacing here */
  .modal-footer { gap: 8px; padding: 14px 16px; padding-bottom: calc(14px + env(safe-area-inset-bottom)); }
  .modal-footer .btn { min-height: 44px; }

  /* Slightly larger touch targets for row action icons */
  .row-actions .btn-icon { padding: 10px; }
}
</style>

<style scoped>
.customer-type-tags { display:flex; flex-wrap:wrap; gap:5px; margin-top:6px; }
.customer-type-tag { display:inline-flex; padding:3px 7px; border-radius:5px; font-size:11px; line-height:1.4; font-weight:600; }
.customer-type-booking { color:var(--info-ink); background:var(--info-bg); }
.customer-type-membership { color:var(--purple-ink); background:var(--purple-bg); }
.customer-type-event { color:var(--warning-ink); background:var(--warning-bg); }
</style>
