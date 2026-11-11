<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaymentAnalytics from '../components/PaymentAnalytics.vue'
import { loadAnalytics, loadAnalyticsAggregates } from '../lib/analytics.js'
import { bookingFrequency } from '../lib/bookingFrequency.js'
import { dateRangeError } from '../lib/finance.js'
import {
  DollarSign, BookOpen, Users, Building2, TrendingUp, ArrowUpRight, Clock,
  CircleCheck as CheckCircle2, TriangleAlert as AlertTriangle, Calendar,
  Wallet as WalletIcon, GraduationCap, Plus, Eye, ShieldCheck, CreditCard, MapPin,
  UserCheck, Repeat, Sparkles, Download, ChevronDown, ChevronUp
} from 'lucide-vue-next'
import FilterBar from '../components/FilterBar.vue'
import MultiSelect from '../components/MultiSelect.vue'
import TrendChart from '../components/viz/TrendChart.vue'
import DonutChart from '../components/viz/DonutChart.vue'
import HBarChart from '../components/viz/HBarChart.vue'
import ChartFilter from '../components/viz/ChartFilter.vue'
import { downloadCSV } from '../lib/useExport.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { runNotificationSweep } from '../customer/notificationSweep.js'
import { CAP, ROLE_INFO } from '../lib/permissions.js'
import { kindOptions, channelOptions, matchKind, matchChannel, kindLabel, channelLabel, bookingKind, bookingChannel, isProviderSlotBlock, isCommercialBooking } from '../lib/bookingMeta.js'
import { fmt12, fmtRange12 } from '../lib/slots.js'

const { role, isSuperAdmin, isCountryAdmin, currentOwnerUserId, currentCountryId,
  assignedCountryIds, assignedLocationIds, currentUser, can, scopeLocations } = useCurrentUser()

// Which dashboard layout to render
const viewMode = computed(() => {
  if (isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff') return 'platform'
  if (['provider_admin', 'facility_admin', 'provider_supervisor'].includes(role.value)) return 'business'
  return 'operations' // staff / viewer / legacy senior·junior·guard
})
const isViewOnly = computed(() => role.value === 'guard' || role.value === 'provider_viewer')
const route = useRoute(), router = useRouter()
const analyticsTab = computed(() => route.query.tab === 'payments' && can(CAP.ACCEPT_PAYMENTS) ? 'payments' : 'activity')
function setAnalyticsTab(tab) { router.replace({query:{...route.query,tab:tab === 'payments' ? 'payments' : undefined}}) }
let activityLoaded = false

const roleInfo = computed(() => ROLE_INFO[role.value] || { label: role.value, tagline: '' })
const firstName = computed(() => (currentUser.value?.full_name || '').split(' ')[0] || 'there')
const now = new Date()
const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`

// ── Data ──────────────────────────────────────────────────────────────────────
const bookings   = ref([])
const customers  = ref([])
const providers  = ref([])
const listings   = ref([])
const locations  = ref([])
const programs   = ref([])
const cities     = ref([])
const countries  = ref([])
const wallets    = ref([])
const withdrawals = ref([])
const allSubscriptions = ref([])   // every class_subscription (scoped per view below)
const subPayments = ref([])        // paid membership billing rows (booking_payments, source_type=subscription)
const games = ref([])              // hosted events (platform events analytics)
const gameJoins = ref([])          // seat requests per event
const loading    = ref(true)
const loadError  = ref('')

const ownedLocationIds = computed(() => {
  if (viewMode.value === 'platform') return null // no restriction
  const owned = locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value)
  return new Set(scopeLocations(owned).map(l => l.id))   // narrow to assigned venues
})

// ── Jurisdiction (platform roles see only their countries / assigned venues) ──
function locCountry(locId) {
  const l = locations.value.find(x => x.id === locId)
  const c = l && cities.value.find(ci => ci.id === l.city_id)
  return c?.country_id || null
}
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null
  const assigned = (assignedCountryIds.value || []).filter(Boolean)
  if (assigned.length) return new Set(assigned)
  return currentCountryId.value ? new Set([currentCountryId.value]) : new Set()
})
// Locations a platform user may see (null = all/super). Platform staff with assigned
// venues are pinned to those; otherwise the country scope applies.
const platformLocationIds = computed(() => {
  if (isSuperAdmin.value) return null
  if (role.value === 'platform_staff' && (assignedLocationIds.value || []).length) return new Set(assignedLocationIds.value)
  const set = jurisdictionCountryIds.value
  if (!set) return null
  return new Set(locations.value.filter(l => set.has(locCountry(l.id))).map(l => l.id))
})
function providerInJurisdiction(ownerId) {
  if (isSuperAdmin.value) return true
  const locSet = platformLocationIds.value
  if (locSet && locations.value.some(l => l.owner_user_id === ownerId && locSet.has(l.id))) return true
  const set = jurisdictionCountryIds.value
  const c = providers.value.find(p => p.id === ownerId)?.country_id
  return set ? (c ? set.has(c) : false) : true
}
const platformListings = computed(() => {
  const set = platformLocationIds.value
  return set ? listings.value.filter(l => set.has(l.location_id)) : listings.value
})
const platformPrograms = computed(() => {
  const set = platformLocationIds.value
  if (!set) return programs.value
  return programs.value.filter(p => (p.location_id && set.has(p.location_id)) || providerInJurisdiction(p.owner_user_id))
})
const platformProviders = computed(() => isSuperAdmin.value ? providers.value : providers.value.filter(p => providerInJurisdiction(p.id)))

// Bookings in scope: platform → jurisdiction locations; provider/employee → their venues.
const scopedBookings = computed(() => {
  if (viewMode.value === 'platform') {
    const set = platformLocationIds.value
    return set ? bookings.value.filter(b => set.has(b.location_id)) : bookings.value
  }
  const ids = ownedLocationIds.value
  return bookings.value.filter(b => ids && ids.has(b.location_id))
})

/* Totals the SERVER computed, with its coverage statement. The raw rows below
   still drive the charts and tables; these drive the figures that must be
   complete, because a capped list cannot produce a true total. */
const serverTotals = ref(null)

/* Whether the figures above describe everything, said by the server rather
   than guessed from how many rows arrived. The old test was
   `bookings.length >= 5000`, which is the cap talking, not the data: a venue
   with exactly 5,000 bookings looked truncated and one with 4,999 looked
   complete even when the server had stopped early. */
const coverageIsComplete = computed(() => serverTotals.value?.coverage?.status === 'complete')

async function load() {
  activityLoaded = true
  loading.value = true

  // Asked for alongside the rows, not instead of them.
  loadAnalyticsAggregates({}).then(a => { serverTotals.value = a?.ok ? a : null })

  const d = await loadAnalytics()
  loadError.value = d.error || ''
  bookings.value    = d.bookings
  customers.value   = d.customers
  providers.value   = d.providers
  listings.value    = d.listings
  locations.value   = d.locations
  programs.value    = d.programs
  wallets.value     = d.wallets
  withdrawals.value = d.withdrawals
  cities.value      = d.cities
  countries.value   = d.countries
  allSubscriptions.value = d.subscriptions
  subPayments.value = d.subPayments
  games.value       = d.games
  gameJoins.value   = d.gameJoins

  loading.value = false

  // Fire the scheduled-notification sweep for this provider (booking reminders,
  // trial-ending, renewal reminders, post-visit review requests). Idempotent + async;
  // never blocks the dashboard. (No server cron in this build — this is the trigger.)
  if (viewMode.value === 'business' && currentOwnerUserId.value) {
    runNotificationSweep(currentOwnerUserId.value)
  }
}
onMounted(() => { if (analyticsTab.value === 'activity') load() })
watch(analyticsTab, tab => { if (tab === 'activity' && !activityLoaded) load() })
watch(currentOwnerUserId, () => { activityLoaded = false; if (analyticsTab.value === 'activity') load() })

// ── Money helper ────────────────────────────────────────────────────────────
function money(n) { return `${Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 })}` }

// ── Multi-currency ────────────────────────────────────────────────────────────
// A booking's money is in its VENUE's currency (location → country currency_code,
// or a per-location override). Summing AED + QAR + EGP into one "AED" total is
// wrong; every money KPI groups by currency instead.
const locationCurrency = computed(() => {
  const cityCountry = new Map(cities.value.map(ci => [ci.id, ci.country_id]))
  // No last-resort code: a venue with no resolvable country groups under '' and
  // prints its amounts bare, rather than labelling them a currency nobody charged.
  const ccy = new Map(countries.value.map(co => [co.id, co.currency_code || '']))
  const map = new Map()
  for (const l of locations.value) {
    map.set(l.id, l.currency_override || ccy.get(cityCountry.get(l.city_id)) || '')
  }
  return map
})
function bookingCurrency(b) { return b?.currency || locationCurrency.value.get(b?.location_id) || '' }

// Sum a numeric field grouped by currency → [{ currency, amount }] desc by amount.
function sumByCurrency(list, valueFn) {
  const acc = {}
  for (const b of list) {
    const cur = bookingCurrency(b)
    acc[cur] = (acc[cur] || 0) + Number(valueFn(b) || 0)
  }
  return Object.entries(acc)
    .filter(([, amt]) => amt !== 0)
    .map(([currency, amount]) => ({ currency, amount }))
    .sort((a, b) => b.amount - a.amount)
}
// Turn grouped totals into a headline string + optional "+ QAR 2,000 · +EGP 500" sub.
function moneyMulti(groups) {
  if (!groups.length) return { value: '—', sub: '' }
  const value = `${groups[0].currency} ${money(groups[0].amount)}`
  const sub = groups.slice(1).map(g => `+ ${g.currency} ${money(g.amount)}`).join(' · ')
  return { value, sub }
}

// ── Analytics filters (multi-select) + export ────────────────────────────────
// Every KPI and list below reads filteredBookings, so the filters reshape the
// whole dashboard, not just one card.
const fSearch    = ref('')   // free text: venue name / booking id
const fCountries = ref([])   // multi: country ids (FilterBar built-in)
const fCities    = ref([])   // multi: city ids (FilterBar built-in)
const fLocations = ref([])   // multi: location ids
const fStatuses  = ref([])   // multi: booking_status values
const fPayments  = ref([])   // multi: payment_status values
const fMethods   = ref([])   // multi: payment method (the methods donut charts this)
const fPrograms  = ref([])   // multi: membership program (memberships section)
const fSports    = ref([])   // multi: event sport (events section, platform)
const fKinds     = ref([])   // multi: booking TYPE (venue / event seat / membership session)
const fChannels  = ref([])   // multi: CHANNEL it was booked through (marketplace / provider site / manual)
const fDateFrom  = ref('')   // booking_date >= (custom range)
const fDateTo    = ref('')   // booking_date <=
const fRange     = ref('all')

const RANGE_OPTIONS = [
  { value: 'all',   label: 'All time' },
  { value: 'today', label: 'Today' },
  { value: '7d',    label: 'Last 7 days' },
  { value: '30d',   label: 'Last 30 days' },
  { value: 'month', label: 'This month' },
  { value: '90d',   label: 'Last 90 days' },
  { value: 'custom', label: 'Custom dates' },
]
const activityDateError = computed(() => fRange.value === 'custom' ? dateRangeError(fDateFrom.value, fDateTo.value) : '')
const STATUS_OPTIONS = ['confirmed', 'completed', 'pending', 'cancelled', 'no_show']
  .map(v => ({ value: v, label: v.replace('_', ' ') }))
// The Revenue chart hard-excludes cancelled — don't offer an option that can
// only ever produce an empty chart.
const REVENUE_STATUS_OPTIONS = STATUS_OPTIONS.filter(o => o.value !== 'cancelled')
const METHOD_OPTIONS = [
  { value: 'online', label: 'Online' }, { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' }, { value: 'cheque', label: 'Cheque' },
  { value: 'wire_transfer', label: 'Wire transfer' }, { value: 'payment_skipped', label: 'Skipped' },
]
const PAYMENT_OPTIONS = [
  { value: 'paid', label: 'Paid' }, { value: 'deposit_paid', label: 'Deposit paid' },
  { value: 'pending', label: 'Payment pending' }, { value: 'skipped', label: 'Skipped' },
  { value: 'refunded', label: 'Refunded' },
]
// Two orthogonal booking dimensions (lib/bookingMeta.js) replacing the old mixed
// "origin" list. Options are derived from the kinds/channels actually present in
// this viewer's data — a provider with no hosted games never sees an "Event"
// option that can't match anything.
const KIND_OPTIONS = computed(() => {
  const present = new Set(scopedBookings.value.map(b => bookingKind(b)))
  const values = ['venue', 'event', 'membership'].filter(v => present.has(v))
  return kindOptions(values.length > 1 ? values : ['venue', 'event', 'membership'])
})
const CHANNEL_OPTIONS = computed(() => {
  const present = new Set(scopedBookings.value.map(b => bookingChannel(b)))
  const values = ['marketplace', 'provider_site', 'manual'].filter(v => present.has(v))
  return channelOptions(values.length > 1 ? values : ['marketplace', 'provider_site', 'manual'])
})

// Venue options honour the viewer's scope (jurisdiction / owned venues).
const scopedLocationRows = computed(() => {
  if (viewMode.value === 'platform') {
    const set = platformLocationIds.value
    return set ? locations.value.filter(l => set.has(l.id)) : locations.value
  }
  const ids = ownedLocationIds.value
  return locations.value.filter(l => ids && ids.has(l.id))
})
const programOptions = computed(() =>
  viewPrograms.value.map(pr => ({ value: pr.id, label: pr.name || 'Program' }))
    .sort((a, b) => a.label.localeCompare(b.label)))
const locationOptions = computed(() =>
  scopedLocationRows.value.map(l => ({ value: l.id, label: l.name || 'Unnamed venue' }))
    .sort((a, b) => a.label.localeCompare(b.label)))

// Country/City options for the FilterBar: only geos where the viewer actually
// has venues in scope (jurisdiction for platform roles, owned for providers).
const jCountries = computed(() => {
  const ids = new Set(scopedLocationRows.value.map(l => locCountry(l.id)).filter(Boolean))
  return countries.value.filter(c => ids.has(c.id))
})
const jCities = computed(() => {
  const ids = new Set(scopedLocationRows.value.map(l => l.city_id).filter(Boolean))
  return cities.value.filter(c => ids.has(c.id))
})
const locCity = (locId) => locations.value.find(l => l.id === locId)?.city_id || null

function rangeStartISO() {
  const d = new Date()
  if (fRange.value === 'today') return today
  if (fRange.value === '7d')  { d.setDate(d.getDate() - 6) }
  else if (fRange.value === '30d') { d.setDate(d.getDate() - 29) }
  else if (fRange.value === '90d') { d.setDate(d.getDate() - 89) }
  else if (fRange.value === 'month') { d.setDate(1) }
  else return null
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const extraFilterCount = computed(() =>
  (fLocations.value.length ? 1 : 0) + (fStatuses.value.length ? 1 : 0) +
  (fPayments.value.length ? 1 : 0) + (fKinds.value.length ? 1 : 0) + (fChannels.value.length ? 1 : 0) + (fMethods.value.length ? 1 : 0) +
  (fPrograms.value.length ? 1 : 0) + (fSports.value.length ? 1 : 0) +
  (fRange.value !== 'all' || fDateFrom.value || fDateTo.value ? 1 : 0))
const filtersActive = computed(() =>
  extraFilterCount.value > 0 || fCountries.value.length > 0 || fCities.value.length > 0 || !!fSearch.value.trim())
function clearFilters() {
  fSearch.value = ''; fCountries.value = []; fCities.value = []
  fLocations.value = []; fStatuses.value = []; fPayments.value = []; fKinds.value = []; fChannels.value = []; fMethods.value = []
  fPrograms.value = []; fSports.value = []
  fDateFrom.value = ''; fDateTo.value = ''; fRange.value = 'all'
}

// Explicit handlers keep the period selector and both sets of date inputs in
// sync without watchers resetting a date while the other field is being edited.
function setActivityPeriod() {
  if (fRange.value !== 'custom') { fDateFrom.value=''; fDateTo.value=''; return }
  const start=new Date(`${today}T00:00:00`);start.setDate(start.getDate()-29)
  if (!fDateFrom.value) fDateFrom.value=ymd(start)
  if (!fDateTo.value) fDateTo.value=today
}
function useCustomDates() { fRange.value='custom' }

// Page filters WITHOUT the membership default-exclusion — charts whose own Type
// override selects 'Membership session' read this, so the page-level exclusion
// can't make that option permanently empty.
const filteredBookingsAll = computed(() => {
  if (activityDateError.value) return []
  let list = scopedBookings.value
  const q = fSearch.value.trim().toLowerCase()
  if (q) list = list.filter(b => locationName(b.location_id).toLowerCase().includes(q) || String(b.id || '').toLowerCase().includes(q))
  if (fCountries.value.length) { const s = new Set(fCountries.value); list = list.filter(b => s.has(locCountry(b.location_id))) }
  if (fCities.value.length)    { const s = new Set(fCities.value);    list = list.filter(b => s.has(locCity(b.location_id))) }
  if (fLocations.value.length) { const s = new Set(fLocations.value); list = list.filter(b => s.has(b.location_id)) }
  if (fStatuses.value.length)  { const s = new Set(fStatuses.value);  list = list.filter(b => s.has(b.booking_status)) }
  if (fPayments.value.length)  { const s = new Set(fPayments.value);  list = list.filter(b => s.has(b.payment_status || 'pending')) }
  if (fKinds.value.length)     list = list.filter(b => matchKind(b, fKinds.value))
  if (fChannels.value.length)  list = list.filter(b => matchChannel(b, fChannels.value))
  if (fMethods.value.length)   list = list.filter(b => fMethods.value.includes(b.payment_method))
  const from = rangeStartISO()
  if (from) list = list.filter(b => String(b.booking_date || '').slice(0,10) >= from && String(b.booking_date || '').slice(0,10) <= today)
  if (fDateFrom.value) list = list.filter(b => String(b.booking_date || '').slice(0,10) >= fDateFrom.value)
  if (fDateTo.value)   list = list.filter(b => String(b.booking_date || '').slice(0,10) <= fDateTo.value)
  return list
})

// Membership schedule blocks are informational placeholders (total 0, payment
// skipped) — they'd inflate booking counts and the Skipped payment slice, so
// analytics exclude them unless the page Type filter explicitly selects them.
const filteredBookings = computed(() =>
  fKinds.value.includes('membership')
    ? filteredBookingsAll.value
    : filteredBookingsAll.value.filter(b => bookingKind(b) !== 'membership'))

const canExport = computed(() => can(CAP.EXPORT_BOOKINGS))
function locationName(id) { return locations.value.find(l => l.id === id)?.name || '' }
function exportBookings() {
  if (!filteredBookings.value.length) return
  downloadCSV(filteredBookings.value, [
    { label: 'Date',           key: 'booking_date' },
    { label: 'Start',          key: 'start_time' },
    { label: 'End',            key: 'end_time' },
    { label: 'Venue',          fn: b => locationName(b.location_id) },
    { label: 'Players',        key: 'players' },
    { label: 'Total',          key: 'total' },
    { label: 'Commission',     key: 'commission_amount' },
    { label: 'Booking status', key: 'booking_status' },
    { label: 'Payment status', fn: b => b.payment_status || 'pending' },
    { label: 'Payment method', key: 'payment_method' },
    { label: 'Type',           fn: b => kindLabel(b) },
    { label: 'Booked via',     fn: b => channelLabel(b) },
  ], 'dashboard_bookings')
}

// "View all" inline expansion for the lists (headers keep their page links too).
const showAllPending = ref(false)

// ── Charts ────────────────────────────────────────────────────────────────────
// Every chart reads filteredBookings (or this owner's subscriptions), so the
// FilterBar reshapes the graphs exactly like the KPIs. Colors are a fixed,
// validated categorical set (see components/viz) — hue follows the entity.
const VIZ = { teal: '#0d9488', blue: '#2a78d6', green: '#008300', magenta: '#e87ba4', yellow: '#eda100', aqua: '#1baf7a', orange: '#eb6834', red: '#e34948', gray: '#94a3b8' }
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

// ── Per-chart filter overrides ────────────────────────────────────────────────
// Each chart card carries its own filter popover (ChartFilter). Overrides apply
// ON TOP of the page filters and narrow that one chart only.
const chartFilters = ref({ revenue: {}, revsrc: {}, bookings: {}, status: {}, methods: {}, top: {}, newcust: {}, weekday: {}, hours: {}, members: {}, memstate: {}, memrev: {}, events: {}, fill: {} })
const cf = (id) => chartFilters.value[id] || {}
function rangeStartFor(r) {
  const d = new Date()
  if (r === 'today') return today
  if (r === '7d') d.setDate(d.getDate() - 6)
  else if (r === '30d') d.setDate(d.getDate() - 29)
  else if (r === '90d') d.setDate(d.getDate() - 89)
  else if (r === 'month') d.setDate(1)
  else return null
  return ymd(d)
}
// The chart's booking set: the page's filteredBookings further narrowed by the
// chart's own overrides.
function chartData(id) {
  const f = cf(id)
  // A chart explicitly asking for membership rows starts from the unstripped set.
  let list = f.kinds?.includes('membership') ? filteredBookingsAll.value : filteredBookings.value
  if (['revenue','revsrc','bookings','status','methods','top','newcust'].includes(id)) list = list.filter(b => !isProviderSlotBlock(b))
  if (f.locations?.length) { const s = new Set(f.locations); list = list.filter(b => s.has(b.location_id)) }
  if (f.statuses?.length) { const s = new Set(f.statuses); list = list.filter(b => s.has(b.booking_status)) }
  if (f.payments?.length) { const s = new Set(f.payments); list = list.filter(b => s.has(b.payment_status || 'pending')) }
  if (f.kinds?.length) list = list.filter(b => matchKind(b, f.kinds))
  if (f.channels?.length) list = list.filter(b => matchChannel(b, f.channels))
  if (f.range) { const from = rangeStartFor(f.range); if (from) list = list.filter(b => (b.booking_date || '') >= from) }
  return list
}

// Time buckets honour the active date filters; default = last 30 days.
// Daily up to ~5 weeks, weekly up to ~6 months, monthly beyond.
function computeBuckets(start, end) {
  const s = new Date(start + 'T00:00:00'), e = new Date(end + 'T00:00:00')
  const span = Math.round((e - s) / 864e5) + 1
  const unit = span <= 35 ? 'day' : span <= 190 ? 'week' : 'month'
  const buckets = []
  const cur = new Date(s)
  while (cur <= e && buckets.length < 400) {
    let toD = new Date(cur)
    if (unit === 'week') toD.setDate(toD.getDate() + 6)
    else if (unit === 'month') toD = new Date(cur.getFullYear(), cur.getMonth() + 1, 0)
    if (toD > e) toD = new Date(e)
    buckets.push({
      from: ymd(cur), to: ymd(toD),
      label: unit === 'month'
        ? cur.toLocaleDateString('en-US', { month: 'short', year: '2-digit' })
        : cur.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    })
    if (unit === 'day') cur.setDate(cur.getDate() + 1)
    else if (unit === 'week') cur.setDate(cur.getDate() + 7)
    else cur.setMonth(cur.getMonth() + 1, 1)
  }
  return buckets
}
const chartBuckets = computed(() => {
  if (activityDateError.value) return []
  const end = fDateTo.value || today
  let start = fDateFrom.value || rangeStartISO()
  if (!start) { const d = new Date(end + 'T00:00:00'); d.setDate(d.getDate() - 29); start = ymd(d) }
  if (start > end) start = end
  return computeBuckets(start, end)
})
// A chart with its own Period override gets its own buckets.
function bucketsFor(id) {
  if (activityDateError.value) return []
  const r = cf(id).range
  if (!r) return chartBuckets.value
  const start = [rangeStartFor(r), fDateFrom.value, rangeStartISO()].filter(Boolean).sort().at(-1) || today
  const end = fDateTo.value && fDateTo.value < today ? fDateTo.value : today
  return start > end ? [] : computeBuckets(start, end)
}
function bucketize(list, dateFn, valFn = () => 1, bks = chartBuckets.value) {
  const out = bks.map(b => ({ label: b.label, value: 0 }))
  for (const item of list) {
    const d = String(dateFn(item) || '').slice(0, 10)
    if (!d) continue
    for (let i = 0; i < bks.length; i++) {
      if (d >= bks[i].from && d <= bks[i].to) { out[i].value += Number(valFn(item)) || 0; break }
    }
  }
  return out
}
function rangeLabelFor(id) {
  const b = bucketsFor(id)
  return b.length ? `${b[0].label} – ${b[b.length - 1].label}` : ''
}

// ── Memberships (BOTH views) ─────────────────────────────────────────────────
// Programs in this viewer's scope: a provider sees their own; platform admins
// their jurisdiction (platformPrograms). Subscriptions + billing follow.
const viewPrograms = computed(() =>
  viewMode.value === 'business'
    ? programs.value.filter(pr => pr.owner_user_id === currentOwnerUserId.value)
    : platformPrograms.value)
const subscriptions = computed(() => {
  const ids = new Set(viewPrograms.value.map(pr => pr.id))
  return allSubscriptions.value.filter(x => ids.has(x.program_id))
})

// Paid membership billing rows in scope, with the page's geo/venue/date/program
// filters applied (each charge is stored as a fee row + an earning row — summing
// both yields the GROSS amount collected).
const membershipRevenueRows = computed(() => {
  const progById = new Map(viewPrograms.value.map(pr => [pr.id, pr]))
  const subById = new Map(subscriptions.value.map(x => [x.id, x]))
  let rows = []
  for (const pmt of subPayments.value) {
    const sub = subById.get(pmt.subscription_id)
    if (!sub) continue                               // out of this viewer's scope
    const prog = progById.get(sub.program_id)
    rows.push({
      date: (pmt.paid_at || pmt.created_at || '').slice(0, 10),
      amount: Number(pmt.amount) || 0,
      currency: pmt.currency || '',
      location_id: prog?.location_id || null,
      program_id: sub.program_id,
    })
  }
  if (fPrograms.value.length)  { const sel = new Set(fPrograms.value);  rows = rows.filter(r => sel.has(r.program_id)) }
  if (fLocations.value.length) { const sel = new Set(fLocations.value); rows = rows.filter(r => sel.has(r.location_id)) }
  if (fCities.value.length)    { const sel = new Set(fCities.value);    rows = rows.filter(r => sel.has(locCity(r.location_id))) }
  if (fCountries.value.length) { const sel = new Set(fCountries.value); rows = rows.filter(r => sel.has(locCountry(r.location_id))) }
  const memFrom = rangeStartISO()
  if (memFrom) rows = rows.filter(r => r.date >= memFrom && r.date <= today)
  if (fDateFrom.value) rows = rows.filter(r => r.date >= fDateFrom.value)
  if (fDateTo.value)   rows = rows.filter(r => r.date <= fDateTo.value)
  return rows
})
// The share of membership billing that joins the COMBINED revenue numbers —
// gated by the page Type filter (Type=venue must exclude membership money).
const membershipRevenueInView = computed(() =>
  (!fKinds.value.length || fKinds.value.includes('membership')) ? membershipRevenueRows.value : [])
// Membership rows for one chart, honouring that chart's own venue/period override.
function memChartRows(id, { kindGated = false } = {}) {
  const f = cf(id)
  let rows = kindGated ? membershipRevenueInView.value : membershipRevenueRows.value
  if (kindGated && f.kinds?.length && !f.kinds.includes('membership')) return []
  if (f.locations?.length) { const sel = new Set(f.locations); rows = rows.filter(r => sel.has(r.location_id)) }
  if (f.range) { const from = rangeStartFor(f.range); if (from) rows = rows.filter(r => r.date >= from) }
  return rows
}

// Revenue is per-currency (never mixed) — chips switch currency when several exist.
// Currencies come from BOTH sources: booking totals and membership billing.
const chartCurrencies = computed(() => {
  const set = new Set(sumByCurrency(chartData('revenue'), x => x.total).map(g => g.currency))
  for (const r of membershipRevenueInView.value) if (r.amount) set.add(r.currency)
  return [...set]
})
const chartCurrency = ref('')   // replaced by the first real currency the data has
watch(chartCurrencies, (list) => { if (list.length && !list.includes(chartCurrency.value)) chartCurrency.value = list[0] }, { immediate: true })

// TRUE multi-source revenue: venue + event booking totals PLUS membership billing.
const revenueTrend = computed(() => {
  const bkts = bucketsFor('revenue')
  const base = bucketize(
    chartData('revenue').filter(b => isCommercialBooking(b) && bookingCurrency(b) === chartCurrency.value),
    b => b.booking_date, b => b.total, bkts)
  const mem = bucketize(
    memChartRows('revenue', { kindGated: true }).filter(r => r.currency === chartCurrency.value),
    r => r.date, r => r.amount, bkts)
  return base.map((pt, i) => ({ label: pt.label, value: pt.value + (mem[i]?.value || 0) }))
})
// Where the money comes from — the source split of the revenue in view.
const sourceSlices = computed(() => {
  let venue = 0, event = 0
  for (const b of chartData('revsrc')) {
    if (!isCommercialBooking(b) || bookingCurrency(b) !== chartCurrency.value) continue
    if (bookingKind(b) === 'event') event += Number(b.total) || 0
    else venue += Number(b.total) || 0
  }
  const mem = memChartRows('revsrc', { kindGated: true })
    .filter(r => r.currency === chartCurrency.value).reduce((a, r) => a + r.amount, 0)
  return [
    { label: 'Venue bookings', value: Math.round(venue), color: VIZ.teal },
    { label: 'Events / games', value: Math.round(event), color: VIZ.blue },
    { label: 'Memberships', value: Math.round(mem), color: VIZ.magenta },
  ].filter(x => x.value > 0)
})
// Membership billing over time (memberships section — not Type-gated).
const memRevenueTrend = computed(() => bucketize(
  memChartRows('memrev').filter(r => r.currency === memRevCurrency.value),
  r => r.date, r => r.amount, bucketsFor('memrev')))
const memRevCurrencies = computed(() => {
  const acc = new Map()
  for (const r of membershipRevenueRows.value) acc.set(r.currency, (acc.get(r.currency) || 0) + r.amount)
  return [...acc.entries()].sort((a, b) => b[1] - a[1]).map(([c]) => c)
})
const memRevCurrency = ref('')   // replaced by the first real currency the data has
watch(memRevCurrencies, (list) => { if (list.length && !list.includes(memRevCurrency.value)) memRevCurrency.value = list[0] }, { immediate: true })
const bookingsTrend = computed(() => bucketize(chartData('bookings'), b => b.booking_date, () => 1, bucketsFor('bookings')))
// New customers = first booking (within the current scope+filters) per customer.
const newCustomersTrend = computed(() => {
  const first = new Map()
  for (const b of chartData('newcust')) {
    if (!b.customer_id || !isCommercialBooking(b)) continue
    const d = (b.created_at || '').slice(0, 10) || b.booking_date
    if (!first.has(b.customer_id) || d < first.get(b.customer_id)) first.set(b.customer_id, d)
  }
  return bucketize([...first.values()], d => d, () => 1, bucketsFor('newcust'))
})

const STATUS_VIZ = { confirmed: VIZ.blue, completed: VIZ.green, pending: VIZ.yellow, cancelled: VIZ.red, no_show: VIZ.orange }
const statusSlices = computed(() => {
  const acc = {}
  for (const b of chartData('status')) { const k = b.booking_status || 'pending'; acc[k] = (acc[k] || 0) + 1 }
  return Object.entries(acc)
    .map(([k, v]) => ({ label: k.replace('_', ' '), value: v, color: STATUS_VIZ[k] || VIZ.gray }))
    .sort((a, b) => b.value - a.value)
})
const METHOD_VIZ = { online: VIZ.blue, cash: VIZ.green, card: VIZ.magenta, cheque: VIZ.yellow, wire_transfer: VIZ.aqua, payment_skipped: VIZ.gray }
const METHOD_NICE = { online: 'Online', cash: 'Pay on arrival', card: 'Card', cheque: 'Cheque', wire_transfer: 'Wire transfer', payment_skipped: 'Skipped' }
const methodSlices = computed(() => {
  const acc = {}
  for (const b of chartData('methods')) { const k = b.payment_method || 'cash'; acc[k] = (acc[k] || 0) + 1 }
  return Object.entries(acc)
    .map(([k, v]) => ({ label: METHOD_NICE[k] || k.replace('_', ' '), value: v, color: METHOD_VIZ[k] || VIZ.gray }))
    .sort((a, b) => b.value - a.value)
})
const weekdayBars = computed(() => {
  const out = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(n => ({ label: n, value: 0 }))
  for (const b of chartData('weekday')) {
    if (!b.booking_date) continue
    out[new Date(b.booking_date + 'T00:00:00').getDay()].value++
  }
  return out
})
const hourBars = computed(() => {
  const acc = Array.from({ length: 24 }, () => 0)
  let any = false
  for (const b of chartData('hours')) {
    const h = parseInt(String(b.start_time).split(':')[0], 10)
    if (!isNaN(h) && h >= 0 && h < 24) { acc[h]++; any = true }
  }
  if (!any) return []
  let lo = acc.findIndex(v => v > 0), hi = 23
  while (hi > 0 && !acc[hi]) hi--
  lo = Math.max(0, lo - 1); hi = Math.min(23, hi + 1)
  return acc.slice(lo, hi + 1).map((v, i) => ({ label: fmt12(`${String(lo + i).padStart(2, '0')}:00`).replace(':00 ', ''), value: v }))
})
const vizListingName = (id) => listings.value.find(l => l.id === id)?.name || 'Unknown listing'
const topListings = computed(() => {
  const acc = new Map()
  for (const b of chartData('top')) {
    if (!b.listing_id) continue
    const e = acc.get(b.listing_id) || { count: 0, rev: 0, cur: bookingCurrency(b) }
    e.count++
    if (isCommercialBooking(b)) e.rev += Number(b.total) || 0
    acc.set(b.listing_id, e)
  }
  return [...acc.entries()].sort((a, b) => b[1].count - a[1].count).slice(0, 6)
    .map(([id, e]) => ({ label: vizListingName(id), value: e.count, sub: `${e.cur} ${money(e.rev)}` }))
})


// Membership charts (same time buckets as everything else). They FOLLOW the page
// filters via the subscription's program venue + the page date range.
const pageScopedSubscriptions = computed(() => {
  let list = subscriptions.value
  const progLoc = new Map(viewPrograms.value.map(pr => [pr.id, pr.location_id]))
  if (fPrograms.value.length)  { const sel = new Set(fPrograms.value);  list = list.filter(x => sel.has(x.program_id)) }
  if (fLocations.value.length) { const sel = new Set(fLocations.value); list = list.filter(x => sel.has(progLoc.get(x.program_id))) }
  if (fCities.value.length)    { const sel = new Set(fCities.value);    list = list.filter(x => sel.has(locCity(progLoc.get(x.program_id)))) }
  if (fCountries.value.length) { const sel = new Set(fCountries.value); list = list.filter(x => sel.has(locCountry(progLoc.get(x.program_id)))) }
  const from = rangeStartISO()
  if (from) list = list.filter(x => (x.started_at || '').slice(0, 10) >= from)
  if (fDateFrom.value) list = list.filter(x => (x.started_at || '').slice(0, 10) >= fDateFrom.value)
  if (fDateTo.value)   list = list.filter(x => (x.started_at || '').slice(0, 10) <= fDateTo.value)
  return list
})
function memberData(id) {
  const r = cf(id).range
  if (!r) return pageScopedSubscriptions.value
  const from = rangeStartFor(r)
  return from ? pageScopedSubscriptions.value.filter(s => (s.started_at || '') >= from) : pageScopedSubscriptions.value
}
const membersTrend = computed(() => bucketize(memberData('members'), s => s.started_at, () => 1, bucketsFor('members')))
const memberSlices = computed(() => {
  const list = memberData('memstate')
  const count = (fn) => list.filter(fn).length
  return [
    { label: 'Paying members', value: count(s => s.status === 'active' && s.trial_status === 'converted'), color: VIZ.green },
    { label: 'In trial', value: count(s => s.status === 'active' && s.trial_status === 'in_trial'), color: VIZ.yellow },
    { label: 'Paused', value: count(s => s.status === 'paused'), color: VIZ.gray },
    { label: 'Cancelled', value: count(s => s.status === 'cancelled'), color: VIZ.red },
  ].filter(s => s.value > 0)
})

// ── Events analytics (platform view) ─────────────────────────────────────────
// Marketplace events (hosted games/tournaments): jurisdiction-scoped, honouring
// the page geo/date filters plus the Sport filter.
const eventSports = computed(() => {
  const set = new Set(games.value.map(g => (g.sport || '').toLowerCase()).filter(Boolean))
  return [...set].sort().map(x => ({ value: x, label: x.charAt(0).toUpperCase() + x.slice(1) }))
})
const scopedGames = computed(() => {
  let list = games.value
  const jset = jurisdictionCountryIds.value
  if (jset) list = list.filter(g => g.country_id ? jset.has(g.country_id) : false)
  if (fCountries.value.length) { const sel = new Set(fCountries.value); list = list.filter(g => sel.has(g.country_id)) }
  if (fCities.value.length)    { const sel = new Set(fCities.value);    list = list.filter(g => sel.has(g.city_id)) }
  if (fSports.value.length)    { const sel = new Set(fSports.value);    list = list.filter(g => sel.has((g.sport || '').toLowerCase())) }
  const evFrom = rangeStartISO()
  const evDate = (g) => g.game_date || (g.created_at || '').slice(0, 10)
  if (evFrom) list = list.filter(g => evDate(g) >= evFrom)
  if (fDateFrom.value) list = list.filter(g => evDate(g) >= fDateFrom.value)
  if (fDateTo.value)   list = list.filter(g => evDate(g) <= fDateTo.value)
  return list
})
function eventsData(id) {
  const r = cf(id).range
  if (!r) return scopedGames.value
  const from = rangeStartFor(r)
  const evDate = (g) => g.game_date || (g.created_at || '').slice(0, 10)
  return from ? scopedGames.value.filter(g => evDate(g) >= from) : scopedGames.value
}
const acceptedJoinsByGame = computed(() => {
  const acc = new Map()
  for (const j of gameJoins.value) if (j.status === 'accepted') acc.set(j.game_id, (acc.get(j.game_id) || 0) + 1)
  return acc
})
const eventsTrend = computed(() => bucketize(
  eventsData('events'), g => g.game_date || (g.created_at || '').slice(0, 10), () => 1, bucketsFor('events')))
const EVENT_STATUS_VIZ = { open: VIZ.blue, full: VIZ.green, completed: VIZ.teal, cancelled: VIZ.red }
const eventStatusSlices = computed(() => {
  const acc = {}
  for (const g of eventsData('events')) { const k = g.status || 'open'; acc[k] = (acc[k] || 0) + 1 }
  return Object.entries(acc).map(([k, v]) => ({ label: k, value: v, color: EVENT_STATUS_VIZ[k] || VIZ.gray }))
    .sort((a, b) => b.value - a.value)
})
// Seat fill rate per sport: accepted seats vs capacity across that sport's events.
const fillBars = computed(() => {
  const acc = new Map()
  for (const g of eventsData('fill')) {
    if (g.status === 'cancelled') continue
    const sport = (g.sport || 'other').toLowerCase()
    const e = acc.get(sport) || { seats: 0, cap: 0, n: 0 }
    e.seats += acceptedJoinsByGame.value.get(g.id) || 0
    e.cap += Number(g.max_players) || 0
    e.n++
    acc.set(sport, e)
  }
  return [...acc.entries()]
    .map(([sp, e]) => ({ label: sp.charAt(0).toUpperCase() + sp.slice(1), value: e.cap ? Math.round(100 * e.seats / e.cap) : 0, sub: `${e.n} event${e.n === 1 ? '' : 's'}` }))
    .sort((a, b) => b.value - a.value).slice(0, 6)
})
const hasEvents = computed(() => viewMode.value === 'platform' && scopedGames.value.length > 0)

// ── Platform KPIs ─────────────────────────────────────────────────────────────
// Merge per-currency booking totals with membership billing totals.
function mergeCurrencyGroups(groups, rows) {
  const acc = new Map(groups.map(g => [g.currency, g.amount]))
  for (const r of rows) acc.set(r.currency, (acc.get(r.currency) || 0) + r.amount)
  return [...acc.entries()].filter(([, a]) => a !== 0)
    .map(([currency, amount]) => ({ currency, amount })).sort((a, b) => b.amount - a.amount)
}

const platformKpis = computed(() => {
  const list = filteredBookings.value
  const commercial = list.filter(isCommercialBooking)
  const revenue = moneyMulti(mergeCurrencyGroups(sumByCurrency(commercial, x => x.total), membershipRevenueInView.value))
  // Prefer the server's totals. They are computed across every authorised
  // record; the loaded list is whatever fitted in the response.
  const server = serverTotals.value?.bookings
  const frequency = server
    ? {
        bookings: server.eligible,
        customers: server.customers,
        repeatCustomers: server.repeat_customers,
        average: server.per_customer,
        repeatRate: server.repeat_pct,
      }
    : bookingFrequency(list)
  const average = loading.value ? 'Loading…' : loadError.value ? 'Unavailable' : frequency.average == null ? '—' : `${frequency.average.toLocaleString(undefined,{maximumFractionDigits:2})}×`
  return [
    { label: 'Booking & membership value', value: revenue.value, sub: revenue.sub, note:'Gross sales value, not Bookboll earnings', icon: DollarSign, color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'Customer bookings', value: loading.value ? 'Loading…' : loadError.value ? 'Unavailable' : frequency.bookings, note:'Confirmed or completed venue bookings with an identified customer', icon: BookOpen, color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'Booking customers', value: loading.value ? 'Loading…' : loadError.value ? 'Unavailable' : frequency.customers, note:'Distinct customers in those confirmed venue bookings', icon: Users, color: 'var(--warning-ink)', bg: 'var(--warning-bg)' },
    { label: 'Bookings per customer', value:average, sub: !loading.value && !loadError.value && frequency.repeatRate != null ? `${frequency.repeatRate.toLocaleString(undefined,{maximumFractionDigits:1})}% booked more than once` : '', note:loadError.value ? 'Analytics could not be loaded. Please retry.' : `${coverageIsComplete.value ? '' : 'Loaded history · '}Confirmed venue bookings ÷ booking customers in this period`, icon: Repeat, color: 'var(--positive-ink)', bg: 'var(--positive-bg)' },
  ]
})
const pendingApprovals = computed(() =>
  platformListings.value.filter(x => x.approval_status === 'pending').length +
  platformPrograms.value.filter(x => x.approval_status === 'pending').length
)
const approvedProviders = computed(() => platformProviders.value.filter(x => x.business_status === 'approved').length)
// Manual payout-method submissions awaiting review, in jurisdiction (distinct businesses).
const pendingPayoutApprovals = computed(() => {
  const owners = new Set(
    wallets.value
      .filter(w => w.setup_status === 'pending_review' && providerInJurisdiction(w.owner_user_id))
      .map(w => w.owner_user_id)
  )
  return owners.size
})

// ── Business KPIs (provider / facility admin) ─────────────────────────────────
const myWallet = computed(() => wallets.value.find(w => w.owner_user_id === currentOwnerUserId.value))
const businessKpis = computed(() => {
  const revenue = moneyMulti(mergeCurrencyGroups(sumByCurrency(filteredBookings.value.filter(isCommercialBooking), x => x.total), membershipRevenueInView.value))
  const myListings = listings.value.filter(l => ownedLocationIds.value?.has(l.location_id))
  const myPrograms = programs.value.filter(p => p.owner_user_id === currentOwnerUserId.value)
  return [
    { label: 'Booked sales value', value: revenue.value, sub: revenue.sub, icon: DollarSign, color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'My Bookings',       value: filteredBookings.value.length,  icon: BookOpen,   color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'Active Listings',   value: myListings.filter(l => l.status === 'active').length, icon: MapPin, color: 'var(--purple-ink)', bg: 'var(--purple-bg)' },
    { label: 'Active programs', value: myPrograms.filter(p => p.status === 'active').length, icon: GraduationCap, color: 'var(--positive-ink)', bg: 'var(--positive-bg)' },
  ]
})

// ── Membership KPIs (provider / facility admin) ───────────────────────────────
// Field semantics copied verbatim from Subscribers.vue's subTotals: a member is
// "active/paid" when status==='active' && trial_status==='converted'; "in trial"
// when status==='active' && trial_status==='in_trial'; MRR = Σ monthly_amount of
// paid members. Currency mirrors Subscribers' subSummaryCurrency fallback (a
// program's currency field, else the wallet/AED default).
const hasMemberships = computed(() => viewPrograms.value.length > 0)
const membershipCurrency = computed(() => {
  const anyProg = programs.value.find(p => p.owner_user_id === currentOwnerUserId.value)
  return anyProg?.currency || myWallet.value?.currency || ''
})
const membershipKpis = computed(() => {
  const list = subscriptions.value
  const active = list.filter(s => s.status === 'active')
  const paid = active.filter(s => s.trial_status === 'converted')
  const inTrial = active.filter(s => s.trial_status === 'in_trial')
  const mrr = paid.reduce((a, s) => a + Number(s.monthly_amount || 0), 0)
  // Net new this month = members started this calendar month − members cancelled
  // this calendar month. started_at is a date string; cancelled uses
  // cancelled_during_trial_at (trial cancels) with a fallback for post-trial cancels.
  const now = new Date()
  const ym = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const inThisMonth = (v) => typeof v === 'string' && v.slice(0, 7) === ym
  const startedThisMonth = list.filter(s => inThisMonth(s.started_at)).length
  const cancelledThisMonth = list.filter(s =>
    s.status === 'cancelled' && inThisMonth(s.cancelled_during_trial_at || s.updated_at || s.started_at)
  ).length
  const netNew = startedThisMonth - cancelledThisMonth
  const cur = membershipCurrency.value
  return [
    { label: 'Active members',       value: paid.length,                    icon: UserCheck,  color: 'var(--positive-ink)', bg: 'var(--positive-bg)' },
    { label: 'MRR',                  value: `${cur} ${money(mrr)}`,          icon: Repeat,     color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'In trial',             value: inTrial.length,                  icon: Clock,      color: 'var(--warning-ink)', bg: 'var(--warning-bg)' },
    { label: 'Net new (this month)', value: (netNew > 0 ? '+' : '') + netNew, icon: Sparkles,   color: 'var(--purple-ink)', bg: 'var(--purple-bg)' },
  ]
})

// ── Operations KPIs (employees / guard) ───────────────────────────────────────
const todaysBookings = computed(() => filteredBookings.value.filter(b => b.booking_date === today))
const upcomingToday = computed(() => {
  const now = new Date().toTimeString().slice(0, 5)
  return todaysBookings.value.filter(b => (b.start_time || '') >= now && b.booking_status !== 'cancelled')
})
const pendingPaymentBookings = computed(() =>
  filteredBookings.value.filter(b => {
    if (b.booking_status === 'cancelled' || isProviderSlotBlock(b)) return false
    const ps = b.payment_status
    return !ps || ps === 'pending' || ps === 'deposit_paid'
  })
)
const opsKpis = computed(() => {
  const base = [
    { label: "Today's Bookings", value: todaysBookings.value.length, icon: Calendar,  color: 'var(--info-ink)', bg: 'var(--info-bg)' },
    { label: 'Upcoming Today',   value: upcomingToday.value.length,  icon: Clock,     color: 'var(--warning-ink)', bg: 'var(--warning-bg)' },
  ]
  if (can(CAP.ACCEPT_PAYMENTS)) {
    base.push({ label: 'Awaiting Payment', value: pendingPaymentBookings.value.length, icon: CreditCard, color: 'var(--danger-ink)', bg: 'var(--danger-bg)' })
  }
  base.push({ label: 'Total Bookings', value: filteredBookings.value.length, icon: BookOpen, color: 'var(--positive-ink)', bg: 'var(--positive-bg)' })
  return base
})

// ── Shared ──────────────────────────────────────────────────────────────────
const statusBadge = (s) => ({
  confirmed: 'badge-info', completed: 'badge-success', cancelled: 'badge-neutral',
  pending: 'badge-warning', paid: 'badge-success', deposit_paid: 'badge-info', no_show: 'badge-error',
}[s] || 'badge-neutral')
function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : '—' }
</script>

<template>
  <div class="page">
    <!-- Header with role-aware greeting -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Business analytics</h1>
        <div class="page-subtitle">
          <span class="role-pill" :style="{ background: roleInfo.bg, color: roleInfo.color }">
            <ShieldCheck :size="12" />{{ roleInfo.label }}
          </span>
          Bookings, revenue and customer activity for the selected period.
        </div>
      </div>
      <router-link v-if="can(CAP.MANAGE_BOOKINGS)" to="/bookings" class="btn btn-primary"><Plus :size="16" />New Booking</router-link>
      <router-link v-else to="/calendar" class="btn btn-secondary"><Calendar :size="16" />View Calendar</router-link>
    </div>

    <nav class="analytics-tabs" aria-label="Analytics views"><button :aria-pressed="analyticsTab === 'activity'" :class="{active:analyticsTab === 'activity'}" @click="setAnalyticsTab('activity')">Bookings &amp; activity</button><button v-if="can(CAP.ACCEPT_PAYMENTS)" :aria-pressed="analyticsTab === 'payments'" :class="{active:analyticsTab === 'payments'}" @click="setAnalyticsTab('payments')">Payments &amp; revenue</button></nav>
    <PaymentAnalytics v-if="analyticsTab === 'payments'" :key="currentOwnerUserId || 'platform'" />
    <template v-else>
    <p v-if="can(CAP.ACCEPT_PAYMENTS)" class="activity-money-note">This view shows booking activity and booked sales value. For money received, platform fees and pending collections, <button @click="setAnalyticsTab('payments')">open Payments &amp; revenue</button>.</p>
    <!-- ── Analytics filters: same pattern as Bookings/Payments — a search bar
         with a filter ICON that OPENS the panel (bottom sheet on phones).
         Filters reshape EVERY number and list below (KPIs included). -->
    <FilterBar
      v-model:search="fSearch"
      v-model:country="fCountries"
      v-model:city="fCities"
      :countries="jCountries"
      :cities="jCities"
      :extra-count="extraFilterCount"
      search-placeholder="Search venue or booking ID…"
      @clear="clearFilters"
    >
      <div class="dash-filter-grid">
        <div class="dash-ff">
          <label>Venue</label>
          <MultiSelect v-model="fLocations" placeholder="All venues" :options="locationOptions" />
        </div>
        <div class="dash-ff">
          <label>Booking Status</label>
          <MultiSelect v-model="fStatuses" placeholder="All statuses" :options="STATUS_OPTIONS" />
        </div>
        <div class="dash-ff">
          <label>Payment Status</label>
          <MultiSelect v-model="fPayments" placeholder="All payment statuses" :options="PAYMENT_OPTIONS" />
        </div>
        <div class="dash-ff">
          <label>Payment Method</label>
          <MultiSelect v-model="fMethods" placeholder="All methods" :options="METHOD_OPTIONS" />
        </div>
        <div class="dash-ff">
          <label>Type</label>
          <MultiSelect v-model="fKinds" placeholder="All types" :options="KIND_OPTIONS" />
        </div>
        <div class="dash-ff">
          <label>Booked via</label>
          <MultiSelect v-model="fChannels" placeholder="All channels" :options="CHANNEL_OPTIONS" />
        </div>
        <div v-if="programOptions.length > 1" class="dash-ff">
          <label>Program</label>
          <MultiSelect v-model="fPrograms" placeholder="All programs" :options="programOptions" />
        </div>
        <div v-if="viewMode === 'platform' && eventSports.length > 1" class="dash-ff">
          <label>Event sport</label>
          <MultiSelect v-model="fSports" placeholder="All sports" :options="eventSports" />
        </div>
        <div class="dash-ff">
          <label>Quick Range</label>
          <select v-model="fRange" @change="setActivityPeriod">
            <option v-for="r in RANGE_OPTIONS" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </div>
        <div class="dash-ff">
          <label>Date From</label>
          <input type="date" v-model="fDateFrom" aria-label="Filter start date" :max="fDateTo || undefined" @input="useCustomDates" />
        </div>
        <div class="dash-ff">
          <label>Date To</label>
          <input type="date" v-model="fDateTo" aria-label="Filter end date" :min="fDateFrom || undefined" @input="useCustomDates" />
        </div>
      </div>
      <template #actions>
        <label class="analytics-period">Period<select v-model="fRange" aria-label="Analytics period" @change="setActivityPeriod"><option v-for="r in RANGE_OPTIONS" :key="r.value" :value="r.value">{{ r.label }}</option></select></label>
        <button v-if="canExport" type="button" class="btn btn-secondary" :disabled="!!activityDateError || !filteredBookings.length" @click="exportBookings">
          <Download :size="15" /> Export CSV
        </button>
      </template>
    </FilterBar>
    <div v-if="fRange === 'custom'" class="analytics-custom-dates" aria-label="Custom analytics dates">
      <label>From<input v-model="fDateFrom" type="date" aria-label="Analytics start date" :max="fDateTo || undefined" :aria-invalid="!!activityDateError" /></label>
      <label>To<input v-model="fDateTo" type="date" aria-label="Analytics end date" :min="fDateFrom || undefined" :aria-invalid="!!activityDateError" /></label>
      <span>Includes both dates · applies to this activity view and CSV export.</span>
    </div>
    <p v-if="activityDateError" class="fin-error" role="alert">{{ activityDateError }}</p>
    <div v-if="filtersActive && !activityDateError" class="dt-note">Showing {{ filteredBookings.length.toLocaleString() }} of {{ scopedBookings.length.toLocaleString() }} bookings</div>
    <p v-if="!coverageIsComplete" class="activity-coverage"><strong>Partial history</strong> Figures use {{ bookings.length.toLocaleString() }} loaded bookings. Older records may be missing.</p>
    <div v-if="loadError" class="analytics-load-error" role="alert">
      <span>{{ loadError }}</span>
      <button type="button" class="btn btn-secondary" :disabled="loading" @click="load">Retry</button>
    </div>

    <template v-if="!activityDateError">
    <!-- ════════════════ PLATFORM (super / country admin) ════════════════ -->
    <template v-if="viewMode === 'platform'">
      <div class="grid grid-4">
        <div v-for="kpi in platformKpis" :key="kpi.label" class="card kpi">
          <div class="kpi-top"><div class="kpi-label">{{ kpi.label }}</div>
            <div class="kpi-icon" :style="{ background: kpi.bg, color: kpi.color }"><component :is="kpi.icon" :size="18" /></div>
          </div>
          <div class="kpi-value">{{ kpi.value }}</div>
          <div v-if="kpi.sub" class="kpi-sub" :class="{ 'kpi-commission-currencies': kpi.note }" :title="kpi.sub">{{ kpi.sub }}</div>
          <div v-if="kpi.note" class="kpi-note" :class="{ 'kpi-note-warning': kpi.incomplete }">{{ kpi.note }}</div>
        </div>
      </div>

      <!-- ── Charts (all driven by the filters above) ── -->
      <div class="viz-section-head"><span class="viz-section-title">Bookings &amp; sales value</span><span class="viz-section-sub">Gross booking and membership value; excludes slot blocks and cancelled bookings</span></div>
      <div class="viz-grid">
        <div class="card viz-card">
          <div class="viz-head">
            <div><div class="viz-title">Sales value</div><div class="viz-sub">{{ rangeLabelFor('revenue') }} · all sources · excludes cancelled</div></div>
            <div class="viz-tools">
              <div v-if="chartCurrencies.length > 1" class="viz-chips">
                <button v-for="c in chartCurrencies" :key="c" type="button" :class="{ on: chartCurrency === c }" @click="chartCurrency = c">{{ c }}</button>
              </div>
              <ChartFilter v-model="chartFilters.revenue" :venue-options="locationOptions" :status-options="REVENUE_STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" />
            </div>
          </div>
          <TrendChart :points="revenueTrend" mode="line" :prefix="chartCurrency + ' '" :color="'#0d9488'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Sales value by source</div><div class="viz-sub">Venues vs events vs memberships · {{ chartCurrency }}</div></div><ChartFilter v-model="chartFilters.revsrc" :dims="['range', 'venue', 'kind', 'channel']" :venue-options="locationOptions" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="sourceSlices" center-label="Sales value" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Bookings</div><div class="viz-sub">{{ rangeLabelFor('bookings') }}</div></div><ChartFilter v-model="chartFilters.bookings" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="bookingsTrend" mode="bars" :color="'#2a78d6'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Booking status</div><div class="viz-sub">Share of bookings in view</div></div><ChartFilter v-model="chartFilters.status" :dims="['range', 'venue', 'payment', 'kind', 'channel']" :venue-options="locationOptions" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="statusSlices" center-label="Bookings" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Payment methods</div><div class="viz-sub">How customers chose to pay</div></div><ChartFilter v-model="chartFilters.methods" :dims="['range', 'venue', 'status', 'kind', 'channel']" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="methodSlices" center-label="Bookings" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Top listings</div><div class="viz-sub">By bookings · sales value alongside</div></div><ChartFilter v-model="chartFilters.top" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <HBarChart :rows="topListings" suffix=" bookings" :color="'#0d9488'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">New customers</div><div class="viz-sub">First booking in this view</div></div><ChartFilter v-model="chartFilters.newcust" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="newCustomersTrend" mode="line" :color="'#1baf7a'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Busiest days</div><div class="viz-sub">Bookings by weekday</div></div><ChartFilter v-model="chartFilters.weekday" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="weekdayBars" mode="bars" :color="'#2a78d6'" :height="150" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Popular hours</div><div class="viz-sub">Bookings by start time</div></div><ChartFilter v-model="chartFilters.hours" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="hourBars" mode="bars" :color="'#2a78d6'" :height="150" />
        </div>
      </div>

      <!-- ── MEMBERSHIPS — sign-ups, member states, billing collected ── -->
      <template v-if="hasMemberships">
        <div class="viz-section-head"><span class="viz-section-title">Memberships</span><span class="viz-section-sub">Program, venue, geo &amp; date filters apply</span></div>
        <div class="viz-grid">
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">New members</div><div class="viz-sub">{{ rangeLabelFor('members') }} · membership sign-ups</div></div><ChartFilter v-model="chartFilters.members" :dims="['range']" /></div>
            <TrendChart :points="membersTrend" mode="bars" :color="'#1baf7a'" />
          </div>
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">Members</div><div class="viz-sub">Everyone who ever joined, by state</div></div><ChartFilter v-model="chartFilters.memstate" :dims="['range']" /></div>
            <DonutChart :slices="memberSlices" center-label="Members" />
          </div>
          <div class="card viz-card">
            <div class="viz-head">
              <div><div class="viz-title">Membership revenue</div><div class="viz-sub">{{ rangeLabelFor('memrev') }} · billing collected</div></div>
              <div class="viz-tools">
                <div v-if="memRevCurrencies.length > 1" class="viz-chips">
                  <button v-for="c in memRevCurrencies" :key="c" type="button" :class="{ on: memRevCurrency === c }" @click="memRevCurrency = c">{{ c }}</button>
                </div>
                <ChartFilter v-model="chartFilters.memrev" :dims="['range', 'venue']" :venue-options="locationOptions" />
              </div>
            </div>
            <TrendChart :points="memRevenueTrend" mode="bars" :prefix="memRevCurrency + ' '" :color="'#e87ba4'" />
          </div>
        </div>
      </template>

      <!-- ── EVENTS — marketplace games & tournaments ── -->
      <template v-if="hasEvents">
        <div class="viz-section-head"><span class="viz-section-title">Events</span><span class="viz-section-sub">Sport, geo &amp; date filters apply</span></div>
        <div class="viz-grid">
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">Events hosted</div><div class="viz-sub">{{ rangeLabelFor('events') }} · games &amp; tournaments</div></div><ChartFilter v-model="chartFilters.events" :dims="['range']" /></div>
            <TrendChart :points="eventsTrend" mode="bars" :color="'#eb6834'" />
          </div>
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">Seat fill rate</div><div class="viz-sub">Accepted players vs capacity, by sport</div></div><ChartFilter v-model="chartFilters.fill" :dims="['range']" /></div>
            <HBarChart :rows="fillBars" suffix="%" :color="'#eb6834'" />
          </div>
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">Event status</div><div class="viz-sub">Open · full · completed · cancelled</div></div></div>
            <DonutChart :slices="eventStatusSlices" center-label="Events" />
          </div>
        </div>
      </template>

      <div class="card" style="margin-top:20px">
        <div class="card-header"><div class="card-title">Approval Queue</div><router-link to="/approvals" class="link">Review</router-link></div>
        <div class="list">
          <div class="list-item"><div class="list-icon warn"><AlertTriangle :size="16" /></div>
            <div class="list-main"><div class="list-title">{{ pendingApprovals }} items pending approval</div>
              <div class="list-sub">Listings & programs awaiting review</div></div></div>
          <div class="list-item"><div class="list-icon ok"><CheckCircle2 :size="16" /></div>
            <div class="list-main"><div class="list-title">{{ approvedProviders }} approved providers</div>
              <div class="list-sub">Currently active on platform</div></div></div>
          <router-link v-if="pendingPayoutApprovals" to="/wallet" class="list-item" style="text-decoration:none;color:inherit">
            <div class="list-icon warn"><WalletIcon :size="16" /></div>
            <div class="list-main"><div class="list-title">{{ pendingPayoutApprovals }} payout method{{ pendingPayoutApprovals === 1 ? '' : 's' }} to verify</div>
              <div class="list-sub">Manual (Bookboll Wallet) KYC awaiting approval</div></div>
            <ArrowUpRight :size="15" style="color:var(--text-muted)" />
          </router-link>
        </div>
      </div>
    </template>

    <!-- ════════════════ BUSINESS (provider / facility admin) ════════════════ -->
    <template v-else-if="viewMode === 'business'">
      <div class="grid grid-4">
        <div v-for="kpi in businessKpis" :key="kpi.label" class="card kpi">
          <div class="kpi-top"><div class="kpi-label">{{ kpi.label }}</div>
            <div class="kpi-icon" :style="{ background: kpi.bg, color: kpi.color }"><component :is="kpi.icon" :size="18" /></div>
          </div>
          <div class="kpi-value">{{ kpi.value }}</div>
          <div v-if="kpi.sub" class="kpi-sub" :title="kpi.sub">{{ kpi.sub }}</div>
        </div>
      </div>

      <!-- Membership KPIs — only when this provider runs ≥1 membership program -->
      <div v-if="hasMemberships" class="kpi-section">
        <div class="kpi-section-head">
          <GraduationCap :size="15" /><span>Memberships</span>
          <router-link to="/subscriptions" class="link">View subscribers</router-link>
        </div>
        <div class="grid grid-4">
          <div v-for="kpi in membershipKpis" :key="kpi.label" class="card kpi">
            <div class="kpi-top"><div class="kpi-label">{{ kpi.label }}</div>
              <div class="kpi-icon" :style="{ background: kpi.bg, color: kpi.color }"><component :is="kpi.icon" :size="18" /></div>
            </div>
            <div class="kpi-value">{{ kpi.value }}</div>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="quick-row">
        <router-link to="/bookings" class="quick-card"><div class="qc-icon" style="background: var(--info-bg);color: var(--info-ink)"><BookOpen :size="18"/></div>Bookings</router-link>
        <router-link to="/calendar" class="quick-card"><div class="qc-icon" style="background: var(--warning-bg);color: var(--warning-ink)"><Calendar :size="18"/></div>Calendar</router-link>
        <router-link v-if="can(CAP.MANAGE_LISTINGS)" to="/listings" class="quick-card"><div class="qc-icon" style="background: var(--purple-bg);color: var(--purple-ink)"><MapPin :size="18"/></div>Listings</router-link>
        <router-link v-if="can(CAP.MANAGE_LISTINGS)" to="/memberships" class="quick-card"><div class="qc-icon" style="background: var(--info-bg);color: var(--info-ink)"><GraduationCap :size="18"/></div>Memberships</router-link>
        <router-link v-if="can(CAP.VIEW_WALLET)" to="/wallet" class="quick-card"><div class="qc-icon" style="background: var(--positive-bg);color: var(--positive-ink)"><WalletIcon :size="18"/></div>Earnings &amp; Payouts</router-link>
      </div>

      <!-- ── Charts (all driven by the filters above) ── -->
      <div class="viz-section-head"><span class="viz-section-title">Bookings &amp; sales value</span><span class="viz-section-sub">Booked value before fees; payment collections are in Payments &amp; revenue</span></div>
      <div class="viz-grid">
        <div class="card viz-card">
          <div class="viz-head">
            <div><div class="viz-title">Booked sales value</div><div class="viz-sub">{{ rangeLabelFor('revenue') }} · all sources · excludes cancelled</div></div>
            <div class="viz-tools">
              <div v-if="chartCurrencies.length > 1" class="viz-chips">
                <button v-for="c in chartCurrencies" :key="c" type="button" :class="{ on: chartCurrency === c }" @click="chartCurrency = c">{{ c }}</button>
              </div>
              <ChartFilter v-model="chartFilters.revenue" :venue-options="locationOptions" :status-options="REVENUE_STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" />
            </div>
          </div>
          <TrendChart :points="revenueTrend" mode="line" :prefix="chartCurrency + ' '" :color="'#0d9488'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Booked value by source</div><div class="viz-sub">Venues vs events vs memberships · {{ chartCurrency }}</div></div><ChartFilter v-model="chartFilters.revsrc" :dims="['range', 'venue', 'kind', 'channel']" :venue-options="locationOptions" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="sourceSlices" center-label="Booked value" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Bookings</div><div class="viz-sub">{{ rangeLabelFor('bookings') }}</div></div><ChartFilter v-model="chartFilters.bookings" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="bookingsTrend" mode="bars" :color="'#2a78d6'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Booking status</div><div class="viz-sub">Share of bookings in view</div></div><ChartFilter v-model="chartFilters.status" :dims="['range', 'venue', 'payment', 'kind', 'channel']" :venue-options="locationOptions" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="statusSlices" center-label="Bookings" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Payment methods</div><div class="viz-sub">How customers chose to pay</div></div><ChartFilter v-model="chartFilters.methods" :dims="['range', 'venue', 'status', 'kind', 'channel']" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <DonutChart :slices="methodSlices" center-label="Bookings" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Top listings</div><div class="viz-sub">By bookings · revenue alongside</div></div><ChartFilter v-model="chartFilters.top" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <HBarChart :rows="topListings" suffix=" bookings" :color="'#0d9488'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">New customers</div><div class="viz-sub">First booking in this view</div></div><ChartFilter v-model="chartFilters.newcust" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="newCustomersTrend" mode="line" :color="'#1baf7a'" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Busiest days</div><div class="viz-sub">Bookings by weekday</div></div><ChartFilter v-model="chartFilters.weekday" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="weekdayBars" mode="bars" :color="'#2a78d6'" :height="150" />
        </div>
        <div class="card viz-card">
          <div class="viz-head"><div><div class="viz-title">Popular hours</div><div class="viz-sub">Bookings by start time</div></div><ChartFilter v-model="chartFilters.hours" :venue-options="locationOptions" :status-options="STATUS_OPTIONS" :payment-options="PAYMENT_OPTIONS" :kind-options="KIND_OPTIONS" :channel-options="CHANNEL_OPTIONS" /></div>
          <TrendChart :points="hourBars" mode="bars" :color="'#2a78d6'" :height="150" />
        </div>

      </div>

      <!-- ── MEMBERSHIPS — sign-ups, member states, billing collected ── -->
      <template v-if="hasMemberships">
        <div class="viz-section-head"><span class="viz-section-title">Memberships</span><span class="viz-section-sub">Program, venue, geo &amp; date filters apply</span></div>
        <div class="viz-grid">
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">New members</div><div class="viz-sub">{{ rangeLabelFor('members') }} · membership sign-ups</div></div><ChartFilter v-model="chartFilters.members" :dims="['range']" /></div>
            <TrendChart :points="membersTrend" mode="bars" :color="'#1baf7a'" />
          </div>
          <div class="card viz-card">
            <div class="viz-head"><div><div class="viz-title">Members</div><div class="viz-sub">Everyone who ever joined, by state</div></div><ChartFilter v-model="chartFilters.memstate" :dims="['range']" /></div>
            <DonutChart :slices="memberSlices" center-label="Members" />
          </div>
          <div class="card viz-card">
            <div class="viz-head">
              <div><div class="viz-title">Membership revenue</div><div class="viz-sub">{{ rangeLabelFor('memrev') }} · billing collected</div></div>
              <div class="viz-tools">
                <div v-if="memRevCurrencies.length > 1" class="viz-chips">
                  <button v-for="c in memRevCurrencies" :key="c" type="button" :class="{ on: memRevCurrency === c }" @click="memRevCurrency = c">{{ c }}</button>
                </div>
                <ChartFilter v-model="chartFilters.memrev" :dims="['range', 'venue']" :venue-options="locationOptions" />
              </div>
            </div>
            <TrendChart :points="memRevenueTrend" mode="bars" :prefix="memRevCurrency + ' '" :color="'#e87ba4'" />
          </div>
        </div>
      </template>
    </template>

    <!-- ════════════════ OPERATIONS (senior / junior / guard) ════════════════ -->
    <template v-else>
      <!-- View-only banner for viewers / guards -->
      <div v-if="isViewOnly" class="ops-banner">
        <Eye :size="15" /><span>You have <strong>view-only</strong> access. You can browse bookings but cannot make changes.</span>
      </div>

      <div class="grid grid-4">
        <div v-for="kpi in opsKpis" :key="kpi.label" class="card kpi">
          <div class="kpi-top"><div class="kpi-label">{{ kpi.label }}</div>
            <div class="kpi-icon" :style="{ background: kpi.bg, color: kpi.color }"><component :is="kpi.icon" :size="18" /></div>
          </div>
          <div class="kpi-value">{{ kpi.value }}</div>
          <div v-if="kpi.sub" class="kpi-sub" :title="kpi.sub">{{ kpi.sub }}</div>
        </div>
      </div>

      <div class="card" style="margin-top:20px">
        <div class="card-header">
          <div class="card-title">Today's Schedule</div>
          <router-link to="/calendar" class="link">Open calendar</router-link>
        </div>
        <div class="list">
          <div v-if="loading" class="empty">Loading…</div>
          <div v-else-if="!todaysBookings.length" class="empty">No bookings scheduled today</div>
          <div v-for="b in todaysBookings" :key="b.id" class="list-item">
            <div class="list-icon"><Clock :size="16" /></div>
            <div class="list-main"><div class="list-title">{{ fmtRange12(b.start_time, b.end_time) }}</div>
              <div class="list-sub">{{ b.players }} players · {{ b.booking_status }}</div></div>
            <div class="list-right">
              <span class="badge" :class="statusBadge(b.payment_status || 'pending')">{{ b.payment_status || 'unpaid' }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Senior employees get a quick collect-payments prompt -->
      <div v-if="can(CAP.ACCEPT_PAYMENTS) && pendingPaymentBookings.length" class="card" style="margin-top:20px">
        <div class="card-header">
          <div class="card-title">Awaiting Payment ({{ pendingPaymentBookings.length }})</div>
          <router-link to="/payments" class="link">Go to payments</router-link>
        </div>
        <div class="list">
          <div v-for="b in (showAllPending ? pendingPaymentBookings : pendingPaymentBookings.slice(0,5))" :key="b.id" class="list-item">
            <div class="list-icon warn"><CreditCard :size="16" /></div>
            <div class="list-main"><div class="list-title">{{ fmtDate(b.booking_date) }} · {{ fmt12(b.start_time) }}</div>
              <div class="list-sub">{{ b.payment_status || 'unpaid' }}</div></div>
            <div class="list-right"><div class="list-amount">{{ bookingCurrency(b) }} {{ money(b.total) }}</div></div>
          </div>
          <button v-if="pendingPaymentBookings.length > 5" type="button" class="dt-viewall" @click="showAllPending = !showAllPending">
            <component :is="showAllPending ? ChevronUp : ChevronDown" :size="15" />
            {{ showAllPending ? 'Show fewer' : `View all ${pendingPaymentBookings.length}` }}
          </button>
        </div>
      </div>
    </template>
    </template>
    </template>
  </div>
</template>

<style scoped>
.analytics-tabs{display:flex;gap:8px;border-bottom:1px solid var(--border);margin:4px 0 24px}.analytics-tabs button{padding:14px 16px;background:none;border:0;border-bottom:2px solid transparent;color:var(--muted);font:inherit;font-size:14px;font-weight:600;cursor:pointer}.analytics-tabs button.active{border-color:var(--primary);color:var(--primary-ink,var(--primary))}.analytics-tabs button:focus-visible,.activity-money-note button:focus-visible{outline:2px solid var(--primary);outline-offset:3px}.activity-money-note{font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:18px}.activity-money-note button{font:inherit;color:var(--primary-ink,var(--primary));background:none;border:0;padding:0;text-decoration:underline;cursor:pointer}
.role-pill { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:2px 8px; border-radius:99px; margin-right:8px; vertical-align:middle; }

.card-header { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.card-title { font-size: 15px; font-weight: 600; }
.link { font-size: 13px; color: var(--primary-ink); font-weight: 500; }
.list { padding: 8px; }
.list-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; transition: background 0.1s; }
.list-item:hover { background: var(--bg); }
.list-icon { width: 32px; height: 32px; border-radius: 8px; background: var(--info-bg, #dbeafe); color: var(--info-ink, #1e40af); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.list-icon.warn { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.list-icon.ok { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #065f46); }
.list-main { flex: 1; min-width: 0; }
.list-title { font-size: 14px; font-weight: 500; }
.list-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.list-right { text-align: right; }
.list-amount { font-size: 14px; font-weight: 600; margin-bottom: 4px; }

/* Membership KPI section */
.kpi-section { margin-top: 24px; }
.kpi-section-head { display: flex; align-items: center; gap: 7px; margin-bottom: 12px; font-size: 13px; font-weight: 600; color: var(--text); }
.kpi-section-head svg { color: var(--info-ink, #0891b2); }
.kpi-section-head .link { margin-left: auto; font-weight: 500; }

/* Quick actions */
.quick-row { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
.quick-card {
  flex: 1; min-width: 130px; display: flex; flex-direction: column; gap: 10px;
  padding: 16px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  font-size: 13.5px; font-weight: 600; color: var(--text); transition: all .15s;
}
.quick-card:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); transform: translateY(-2px); }
.qc-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }

/* Ops banner */
.ops-banner {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px; margin-bottom: 18px;
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border); border-radius: 10px;
  font-size: 13px; color: var(--text-muted);
}
.ops-banner svg { color: var(--text-muted, #64748b); }

/* ── Charts grid ── */
.viz-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 12px; }
.viz-section-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 10px; margin-top: 26px; }
.viz-section-title { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.viz-section-sub { font-size: 12px; color: var(--text-light, #94a3b8); }
.viz-card { min-width: 0; padding: 16px 18px 14px; }
.viz-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.viz-title { font-size: 14.5px; font-weight: 700; color: var(--text); }
.viz-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.viz-tools { display: inline-flex; align-items: center; gap: 8px; }
.viz-chips { display: inline-flex; gap: 4px; background: var(--bg, #f1f5f9); border-radius: 8px; padding: 3px; }
.viz-chips button { padding: 4px 10px; border: none; border-radius: 6px; background: none; font-size: 12px; font-weight: 700; color: var(--text-muted); cursor: pointer; }
.viz-chips button.on { background: var(--surface, #ffffff); color: var(--primary-ink); box-shadow: var(--shadow-sm); }
@media (max-width: 860px) { .viz-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 600px) { .viz-head { flex-wrap: wrap; }.viz-head > div:first-child { min-width: 0; }.viz-tools { flex-wrap: wrap; } }

/* Analytics toolbar */
.analytics-custom-dates { display:flex; flex-wrap:wrap; gap:12px; align-items:end; margin:12px 0; padding:14px; border:1px solid var(--border); border-radius:10px; background:var(--surface); }
.analytics-custom-dates label { display:flex; flex-direction:column; gap:6px; min-width:0; font-size:12px; font-weight:600; }
.analytics-custom-dates input { min-width:0; width:180px; max-width:100%; min-height:40px; }
.analytics-custom-dates > span { font-size:12px; color:var(--text-muted); padding-bottom:10px; }
.activity-coverage { margin:14px 0; font-size:12px; line-height:1.6; color:var(--text-muted); }
.activity-coverage strong { color:var(--warning-ink); margin-right:6px; }
@media(max-width:600px) { .analytics-custom-dates label { flex:1 1 130px; }.analytics-custom-dates input { width:100%; min-height:44px; font-size:16px; }.analytics-custom-dates > span { padding-bottom:0; } }
/* Filter panel fields (inside the FilterBar dropdown / phone bottom sheet).
   minmax(0,1fr) columns + min-width:0 everywhere: date inputs have a large
   intrinsic min-content width that otherwise BLOWS the grid tracks past the
   panel/sheet width on phones (ragged, overflowing fields). */
.dash-filter-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.dash-ff { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.dash-ff label { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .05em; }
.dash-ff select, .dash-ff input {
  width: 100%; min-width: 0; box-sizing: border-box; height: 38px; padding: 0 10px;
  border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff);
  font-size: 13.5px; color: var(--text); appearance: none; -webkit-appearance: none;
}

/* Phone: KPI stat tiles read fine 2-up (1-up made the page a very long scroll) */
@media (max-width: 700px) {
  .grid-4 { grid-template-columns: repeat(2, 1fr) !important; }
  .kpi-value { font-size: 20px; }
  .kpi-sub { font-size: 11px; }
}
/* Inside the ≤600px bottom sheet: one field per row, full width. */
@media (max-width: 600px) {
  .dash-filter-grid { grid-template-columns: 1fr; }
  .dash-ff select, .dash-ff input { height: 44px; font-size: 16px; }
}
.dt-export { margin-left: auto; }
.dt-export:disabled { opacity: .5; cursor: default; }
.dt-note { margin-top: 8px; font-size: 12.5px; font-weight: 600; color: var(--primary-ink); }
.dt-count { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-left: 6px; }
.kpi-sub { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kpi-commission-currencies { white-space: normal; overflow-wrap: anywhere; }
.kpi-note { margin-top: 8px; font-size: 11.5px; line-height: 1.5; color: var(--text-muted); }
.kpi-note-warning { color: var(--warning-ink); }
.analytics-load-error { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-block: 16px; padding: 14px; border: 1px solid var(--border); border-radius: 12px; color: var(--warning-ink); background: var(--warning-bg); }
.dt-viewall { width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 11px; margin-top: 4px; border: none; border-top: 1px solid var(--border); background: none; color: var(--primary-ink); font-size: 13.5px; font-weight: 700; cursor: pointer; min-height: 44px; }
.dt-viewall:hover { background: var(--bg); }

/* Phone (≤600px): tighten list rows / card headers, full-width primary action */
@media (max-width: 600px) {
  .list-item { gap: 10px; padding: 10px 8px; }
  .list-amount { font-size: 13px; }
  .list-right .badge { font-size: 10.5px; }
  .card-header { padding: 14px 14px; }
  .card-title { font-size: 14px; }
  .page-header > .btn { width: 100%; justify-content: center; }
  /* Toolbar: 2-up filter grid, full-width export, 16px select (no iOS zoom) */
  .dt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .dt-lead { grid-column: 1 / -1; }
  .dt-ms { max-width: none; min-width: 0; }
  .dt-range { width: 100%; font-size: 16px; }
  .dt-export { grid-column: 1 / -1; margin-left: 0; justify-content: center; min-height: 44px; }
  .dt-reset { justify-self: start; }
}
</style>
