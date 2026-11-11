<script setup>
import ExportDialog from '../components/ExportDialog.vue'
import AdminAppDownloadButton from '../components/AdminAppDownloadButton.vue'
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BookingActivitySummary from '../components/BookingActivitySummary.vue'
import BookingActivityCharts from '../components/BookingActivityCharts.vue'
import { periods, periodDates, purposes, dateError, statusLabel, activityChannelLabel, bookingPaymentLabel } from '../lib/bookingActivity.js'
import { money } from '../lib/paymentReport.js'
import { bookingRefLabel } from '../lib/bookingRef.js'
import {
  fetchBookingsDashboard, fetchBookingsForExport, cancelDashboardBooking, firstError,
} from '../lib/bookings.js'
import { pushToast } from '../lib/toast.js'
import { Search, Plus, Download, Eye, Pencil, X, TriangleAlert as AlertTriangle, SlidersHorizontal, ChevronUp, ChevronDown, ChevronsUpDown, ChevronLeft, ChevronRight, FileDown, ListFilter as Filter, RotateCcw, BookOpen, Loader } from 'lucide-vue-next'
import EmptyState from '../components/EmptyState.vue'
import { openCustomer } from '../lib/customerModal.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { downloadCSV } from '../lib/useExport.js'
import { usePremium } from '../lib/premium.js'
import { CAP } from '../lib/permissions.js'
import { kindOptions, channelOptions, matchKind, matchChannel, kindLabel, channelLabel, bookingCreatorLabel } from '../lib/bookingMeta.js'
import BookingDetailModal from '../components/BookingDetailModal.vue'
import NewBookingDrawer from '../components/NewBookingDrawer.vue'
import MultiSelect from '../components/MultiSelect.vue'
import FilterBar from '../components/FilterBar.vue'
import { idIn } from '../lib/geoFilter.js'
import PaginationBar from '../components/PaginationBar.vue'
import { fmt12, fmtRange12 } from '../lib/slots.js'
import { notifyBookingCancelled } from '../customer/notificationDispatch.js'

const { role, can, isSuperAdmin, isCountryAdmin, currentOwnerUserId, scopeLocations, scopeListings, assignedListingIds } = useCurrentUser()
const props=defineProps({analytics:{type:Boolean,default:false}})
const route=useRoute(), router=useRouter()
const activity=ref(null), loadError=ref(''), period=ref('all'), dateBasis=ref('booking')
const filterPurpose=ref([]), filterBusiness=ref([]), businessOptions=ref([])
const invalidDates=computed(()=>dateError(filterDateFrom.value,filterDateTo.value) || dateError(filterCreatedFrom.value,filterCreatedTo.value))
function setPeriod() { if(period.value !== 'custom') [filterDateFrom.value,filterDateTo.value]=periodDates(period.value) }
function openRecords() {
  const params=queryParams(), query={}
  for(const [key,value] of Object.entries(params)) if(value && !['page','per_page','with_activity','analytics_only'].includes(key)) query[key]=Array.isArray(value) ? value.join(',') : value
  router.push({path:'/bookings',query})
}
const canExport = computed(() => can(CAP.EXPORT_BOOKINGS))
const { isPremium, requirePremium } = usePremium()   // exports are a Premium feature for providers
const isPlatformAdmin = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')

// Locations the current user may see. Platform roles: null for super admin (no
// restriction), the jurisdiction's venues for country_admin / platform_staff.
// Providers: locations they own, narrowed to the user's assigned scope
// (Managers/Staff may be pinned to specific venues).
const ownedLocationIds = computed(() => {
  if (isPlatformAdmin.value) return allowedLocationIds.value
  const owned = locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value)
  return new Set(scopeLocations(owned).map(l => l.id))
})
// Scoped option lists for the filter dropdowns
// The API already applies business, jurisdiction and listing access, including ownerless businesses.
const scopedLocations = computed(() => locations.value.filter(v=>!filterBusiness.value.length || filterBusiness.value.includes(v.operator_type === 'bookboll' ? 'bookboll' : String(v.business_id))))
const scopedListings = computed(() => listings.value)
// Visible listing ids — used to scope bookings when the user is pinned to listings.
const scopedListingIdSet = computed(() => new Set(scopedListings.value.map(l => l.id)))

// ── Master data ───────────────────────────────────────────────────────────────
const bookings  = ref([])
const listings  = ref([])
const locations = ref([])
const countries = ref([])
const cities    = ref([])
const loading   = ref(true)
// Server-driven pagination: the page holds one page of rows plus the totals the
// server reports, instead of every row and a client-side slice.
const page      = ref(1)
const perPage   = ref(25)
const total     = ref(0)
const lastPage  = ref(1)
// Everything in scope ignoring filters — the export dialog offers both counts.
const scopeTotal = ref(0)
// Currencies present across the caller's WHOLE scope, not just this page — a
// per-page list would change as you page through.
const currencyOpts = ref([])
// Country / city ids present across the caller's whole scope (server-provided), so
// the geo dropdowns stay stable while paging instead of shrinking to one page.
const countryOpts  = ref([])
const cityOpts     = ref([])

// Platform jurisdiction (country_admin / platform_staff only see their countries' venues)
const { allowedLocationIds, scopeCountries, scopeCities } = useJurisdiction({ locations, cities })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))
// Geo options offered in the FilterBar derive from the bookings THIS viewer can
// see — a single-country provider gets just their country, not the whole platform
// list. Falls back to the jurisdiction lists while data loads.
const fbCountries = computed(() => {
  const used = new Set([...countryOpts.value,...locations.value.map(l=>l.country_id)].filter(Boolean))
  const rows = jCountries.value.filter(c => used.has(String(c.id)))
  return rows
})
const fbCities = computed(() => {
  const used = new Set(cityOpts.value)
  const rows = jCities.value.filter(c => used.has(String(c.id)))
  return rows.length ? rows : jCities.value
})

// ── Search & filters ──────────────────────────────────────────────────────────
const search          = ref('')
const showFilters     = ref(false)
const filterStatus    = ref([])
const filterPayment   = ref([])
const filterKind      = ref([])   // venue booking vs event/game seat (this table excludes membership blocks)
const filterChannel   = ref([])   // marketplace / provider site / admin manual
const filterListing   = ref([])
const filterVenue     = ref([])
const filterCountry   = ref([])
const filterCity      = ref([])
const filterDateFrom  = ref('')
const filterDateTo    = ref('')
const filterCreatedFrom = ref('')
const filterCreatedTo   = ref('')

// Listing options cascade with the Venue picks (and stale picks are pruned) so a
// contradictory venue+listing pair can never silently return zero rows.
const cascadedListingOptions = computed(() => scopedListings.value
  .filter(l=>scopedLocations.value.some(v=>v.id===l.location_id))
  .filter(l => !filterVenue.value.length || filterVenue.value.includes(l.location_id))
  .map(l => ({ value: l.id, label: l.name })))
watch(filterVenue, () => {
  if (!locations.value.length) return // Keep deep-linked filters until lookup options arrive.
  if (!filterListing.value.length) return
  const ok = new Set(cascadedListingOptions.value.map(o => o.value))
  filterListing.value = filterListing.value.filter(id => ok.has(id))
})
watch(filterBusiness,()=>{
  if (!locations.value.length) return
  const allowed=new Set(scopedLocations.value.map(v=>v.id))
  filterVenue.value=filterVenue.value.filter(id=>allowed.has(id))
  filterListing.value=filterListing.value.filter(id=>listings.value.some(l=>l.id===id && allowed.has(l.location_id)))
})

// Currency + payment-method filters (currency only shown to multi-currency viewers)
const filterMethod   = ref([])
const filterCurrency = ref([])
// The server resolves currency from the venue (override → city → country) and puts
// it on the row, so the page no longer needs the venue/city/country lists to show it.
const currencyOf = (b) => b?.currency || ''
const currencyOptions = computed(() => currencyOpts.value)
// These are the values bookings.payment_method actually stores. The list inherited
// from the Supabase build used a different vocabulary ('online', 'cash', 'card'…),
// so every option in this filter matched zero rows — it looked like a working filter
// that simply never found anything.
const METHOD_OPTIONS = [
  { value: 'online_payment',               label: 'Online' },
  { value: 'deposit_remaining_on_arrival', label: 'Deposit, rest on arrival' },
  { value: 'cash_on_arrival',              label: 'Cash on arrival' },
  { value: 'paid_cash',                    label: 'Paid — cash' },
  { value: 'paid_credit_card',             label: 'Paid — card' },
  { value: 'paid_cheque',                  label: 'Paid — cheque' },
  { value: 'paid_wire_transfer',           label: 'Paid — wire transfer' },
  { value: 'payment_skipped',              label: 'Skipped' },
]

// ── Sorting ───────────────────────────────────────────────────────────────────
const sortCol = ref('created_at')
const sortDir = ref('desc')

function toggleSort(col) {
  if (sortCol.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortCol.value = col
    sortDir.value = 'asc'
  }
  page.value = 1
}

// ── Pagination ────────────────────────────────────────────────────────────────
// (page/perPage are wired up after `filtered` is defined below)

// ── Modals ────────────────────────────────────────────────────────────────────
const showNewBooking = ref(false)
const viewingId      = ref(null)
const editingId      = ref(null)

// ── Export modal ──────────────────────────────────────────────────────────────
const showExportModal = ref(false)

// ── Cancel flow ───────────────────────────────────────────────────────────────
const cancelTarget      = ref(null)
const showCancelChoice  = ref(false)
const showCancelConfirm = ref(false)
const cancellingAll     = ref(false)
const cancelling        = ref(false)

// ── Load ──────────────────────────────────────────────────────────────────────
// Filtering, sorting and paging happen on the SERVER. The page sends its filter
// state and receives one page of rows, already carrying the customer/listing/venue
// names, currency and geography it displays — so no lookup list is needed to render
// a row, which is what used to make the 15k customer payload necessary.
function queryParams() {
  return {
    with_activity:1,
    analytics_only:props.analytics ? 1 : undefined,
    purpose:filterPurpose.value,
    business_id:filterBusiness.value,
    date_basis:dateBasis.value,
    page: page.value,
    per_page: perPage.value,
    search: search.value.trim(),
    status: filterStatus.value,
    payment_status: filterPayment.value,
    payment_method: filterMethod.value,
    currency: filterCurrency.value,
    kind: filterKind.value,
    channel: filterChannel.value,
    listing_id: filterListing.value,
    venue_id: filterVenue.value,
    country_id: filterCountry.value,
    city_id: filterCity.value,
    date_from: filterDateFrom.value,
    date_to: filterDateTo.value,
    created_from: filterCreatedFrom.value,
    created_to: filterCreatedTo.value,
    sort: sortCol.value,
    sort_dir: sortDir.value,
  }
}

let loadSeq = 0
async function load() {
  clearTimeout(searchTimer)
  if(invalidDates.value) { ++loadSeq; loading.value=false; activity.value=null; bookings.value=[]; return }
  loading.value = true
  loadError.value=''
  // Filters change fast (typing, clicking chips); only the newest response may win,
  // or a slow earlier request can overwrite a newer one.
  const seq = ++loadSeq
  const res = await fetchBookingsDashboard(queryParams())
  if (seq !== loadSeq) return
  if (!res.success) {
    loadError.value=firstError(res,'Could not load bookings.')
    activity.value=null; bookings.value=[]; total.value=0
    loading.value = false
    return
  }
  const d = res.data || {}
  activity.value=d.activity
  businessOptions.value=d.business_options || []
  bookings.value  = d.bookings || []
  total.value     = d.pagination?.total ?? bookings.value.length
  lastPage.value  = d.pagination?.last_page ?? 1
  scopeTotal.value = d.scope_total ?? total.value
  listings.value  = d.listings || []
  locations.value = d.locations || []
  countries.value = d.countries || []
  cities.value    = d.cities || []
  currencyOpts.value = d.currency_options || []
  countryOpts.value  = d.country_options || []
  cityOpts.value     = d.city_options || []
  loading.value   = false
}

// Country/city lookup for a booking via its location
// The server resolves these from the booking's venue and puts them on the row.
function countryOfBooking(bk) { return bk?.country_id || null }
function cityOfBooking(bk)    { return bk?.city_id || null }

onMounted(()=>{
  const arrays={status:filterStatus,payment_status:filterPayment,payment_method:filterMethod,currency:filterCurrency,kind:filterKind,channel:filterChannel,listing_id:filterListing,venue_id:filterVenue,country_id:filterCountry,city_id:filterCity,purpose:filterPurpose,business_id:filterBusiness}
  for(const [key,target] of Object.entries(arrays)) if(route.query[key]) target.value=String(route.query[key]).split(',').filter(Boolean)
  for(const [key,target] of Object.entries({search,date_from:filterDateFrom,date_to:filterDateTo,created_from:filterCreatedFrom,created_to:filterCreatedTo,date_basis:dateBasis})) if(route.query[key]) target.value=String(route.query[key])
  if(filterDateFrom.value || filterDateTo.value) period.value='custom'
  load()
})
onBeforeUnmount(()=>{ ++loadSeq; clearTimeout(searchTimer) })

// ── Lookups ───────────────────────────────────────────────────────────────────
// Names ride on the row itself now (the server joins them), so these take the
// booking rather than an id — no lookup list required to render a cell.
const customerName = (b) => b?.customer_name || 'Walk-in / customer not recorded'
const listingName  = (b) => b?.listing_name  || '—'
const locationName = (b) => b?.location_name || '—'

// With server-side filtering an empty table can mean "nothing matched" or "nothing
// exists"; only the filter state can tell them apart.
const isFiltering = computed(() => activeFilterCount.value > 0 || search.value.trim() !== '')

// ── Active filter count badge ─────────────────────────────────────────────────
const activeFilterCount = computed(() => {
  let n = 0
  if (filterStatus.value.length)  n++
  if (filterPayment.value.length) n++
  if (filterKind.value.length)    n++
  if (filterChannel.value.length) n++
  if (filterMethod.value.length)  n++
  if (filterCurrency.value.length) n++
  if (filterListing.value.length) n++
  if (filterVenue.value.length)   n++
  if (filterCountry.value.length) n++
  if (filterCity.value.length)    n++
  if (filterDateFrom.value)       n++
  if (filterDateTo.value)         n++
  if (filterCreatedFrom.value)    n++
  if (filterCreatedTo.value)      n++
  if (filterPurpose.value.length) n++
  if (filterBusiness.value.length) n++
  return n
})

function resetFilters() {
  filterStatus.value = []; filterPayment.value = []; filterKind.value = []; filterChannel.value = []
  filterMethod.value = []; filterCurrency.value = []
  filterListing.value = []; filterVenue.value = []
  filterCountry.value = []; filterCity.value = []
  filterDateFrom.value = filterDateTo.value = ''
  filterCreatedFrom.value = filterCreatedTo.value = ''
  search.value = ''
  filterPurpose.value=[]; filterBusiness.value=[]; period.value='all'; dateBasis.value='booking'
}

// ── Jurisdiction-scoped base (no user filters) ────────────────────────────────
// The set of bookings this user is allowed to see AT ALL. `filtered` narrows this
// further with the UI filters; "Export All" must export THIS, never raw bookings.
// Row scoping (provider ownership, jurisdiction, venue and listing pins) is applied
// by the server, so the page no longer re-filters what it was given.

const filtered = computed(() => bookings.value)
const paginated = filtered

// ── Pagination (server-driven) ────────────────────────────────────────────────
const totalPages = computed(() => Math.max(1, lastPage.value))
const visiblePages = computed(() => {
  const t = totalPages.value
  const c = page.value
  const out = []
  const from = Math.max(1, Math.min(c - 2, t - 4))
  const to = Math.min(t, from + 4)
  for (let i = from; i <= to; i++) out.push(i)
  return out
})
function goPage(p) {
  const next = Math.min(Math.max(1, p), totalPages.value)
  if (next === page.value) return
  page.value = next
  load()
}

// Any filter or sort change restarts at page 1 and refetches. Search is debounced
// so typing does not fire a request per keystroke.
let searchTimer = null
watch([
  search,filterPurpose,filterBusiness,dateBasis,
  filterStatus, filterPayment, filterKind, filterChannel, filterMethod, filterCurrency,
  filterListing, filterVenue, filterCountry, filterCity,
  filterDateFrom, filterDateTo, filterCreatedFrom, filterCreatedTo,
  sortCol, sortDir, perPage,
], () => { ++loadSeq; loading.value=true; page.value=1; clearTimeout(searchTimer); searchTimer=setTimeout(load,250) },{deep:true,flush:'sync'})
watch(currentOwnerUserId,()=>{ resetFilters(); load() })

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    + ' ' + fmt12(d)
}

// ── Status helpers ────────────────────────────────────────────────────────────
const statusBadge = (s) => ({
  confirmed: 'badge-info', completed: 'badge-success', cancelled: 'badge-neutral',
  pending: 'badge-warning', paid: 'badge-success', deposit_paid: 'badge-info',
  no_show: 'badge-error', skipped: 'badge-neutral', refunded: 'badge-error'
}[s] || 'badge-neutral')

// ── Edit shortcut ─────────────────────────────────────────────────────────────
function openEdit(e, booking) {
  e.stopPropagation()
  editingId.value = booking.id
}

// ── Cancel flow ───────────────────────────────────────────────────────────────
function promptCancel(e, booking) {
  e.stopPropagation()
  cancelTarget.value = booking
  if (booking.origin === 'class_program') {
    showCancelChoice.value = true
  } else {
    showCancelConfirm.value = true
  }
}

function chooseCancelThis()  { showCancelChoice.value = false; cancellingAll.value = false; doCancel() }
function chooseCancelAll()   { showCancelChoice.value = false; showCancelConfirm.value = true; cancellingAll.value = true }

function abortCancel() {
  showCancelChoice.value = showCancelConfirm.value = false
  cancelTarget.value = null; cancellingAll.value = false
}

async function doCancel() {
  if (!cancelTarget.value) return
  cancelling.value = true
  const b = cancelTarget.value
  const res = cancellingAll.value && b.program_id
    ? await cancelDashboardBooking({ booking_id: b.id, program_id: b.program_id, booking_date: b.booking_date, cancel_all: true })
    : await cancelDashboardBooking({ booking_id: b.id })
  if (!res.success) {
    cancelling.value = false
    pushToast(firstError(res, 'Could not cancel booking.'), 'error')
    return
  }
  if (!cancellingAll.value && b.customer_id) {
    try {
      const loc = locations.value.find(l => l.id === b.location_id)
      notifyBookingCancelled({
        ownerId: loc?.owner_user_id || null,
        customer: { id: b.customer_id, name: customerName(b), email: b.customer_email || '' },
        booking: {
          listing: listingName(b), venue: locationName(b), business_name: locationName(b),
          date: b.booking_date, start_time: fmt12(b.start_time), end_time: fmt12(b.end_time),
          code: b.booking_id || String(b.id || ''), currency: currencyOf(b),
        },
      })
    } catch { /* never let a notification break the cancel */ }
  }
  cancelling.value = false; showCancelConfirm.value = false
  cancelTarget.value = null; cancellingAll.value = false
  load()
}

// ── Export ────────────────────────────────────────────────────────────────────
// Export covers EVERY matching row, not just the page on screen — so it fetches
// them first rather than exporting the 25 rows the table happens to hold.
const exporting = ref(false)
async function exportCSV(useFilters) {
  if (!canExport.value) return
  if (!requirePremium('exports')) { showExportModal.value = false; return }
  if (exporting.value) return
  exporting.value = true
  // "All bookings" = the caller's whole scope, so send no filters.
  const params = useFilters ? queryParams() : {}
  const res = await fetchBookingsForExport(params)
  exporting.value = false
  if (!res.success) { pushToast(res.message || 'Could not prepare the export.', 'error'); return }
  const rows = res.data?.bookings || []
  downloadCSV(rows, [
    { label: 'ID',             key: 'id' },
    { label: 'Booking reference', fn:bookingRefLabel },
    { label: 'Record purpose', key:'record_purpose' },
    { label: 'Record Date',    fn: r => r.created_at?.slice(0,10) },
    { label: 'Booking Date',   key: 'booking_date' },
    { label: 'Customer',       fn: r => customerName(r) },
    { label: 'Listing',        fn: r => listingName(r) },
    { label: 'Venue',          fn: r => locationName(r) },
    { label: 'Start Time',     key: 'start_time' },
    { label: 'End Time',       key: 'end_time' },
    { label: 'Players',        key: 'players' },
    { label: 'Total',          key: 'total' },
    { label: 'Currency',       fn: b => currencyOf(b) },
    { label: 'Booking Status', key: 'booking_status' },
    { label: 'Payment Status', key: 'payment_status' },
    { label: 'Payment Method', key: 'payment_method' },
    { label: 'Type',           fn: b => kindLabel(b) },
    { label: 'Booked via',     fn: b => activityChannelLabel(b.report_channel) },
    { label: 'Created by',     fn:bookingCreatorLabel },
    { label: 'Recorded receipts',fn:b=>b.recorded_payment_summary?.paid ?? '' },
    { label: 'Notes',          key: 'notes' },
  ], 'bookings')
  showExportModal.value = false
}

</script>

<template>
  <ExportDialog :open="showExportModal" title="Export bookings" noun="bookings" :filtered-count="total" :all-count="scopeTotal" :busy="exporting" @close="showExportModal = false" @export="exportCSV" />
  <div :class="['booking-workspace',{'page':!analytics}]">
    <div v-if="!analytics" class="page-header">
      <div>
        <h1 class="page-title">Bookings</h1>
        <div class="page-subtitle">Reservations, customers and who created each booking.</div>
      </div>
      <div class="bk-head-actions">
        <AdminAppDownloadButton />
        <button v-if="canExport" class="btn btn-secondary" :disabled="loading || !!invalidDates || !!loadError" @click="showExportModal = true"><Download :size="16" />Export<span v-if="!isPremium" class="prem-lock">Premium</span></button>
        <button v-if="can(CAP.MANAGE_BOOKINGS)" class="btn btn-primary" @click="showNewBooking = true"><Plus :size="16" />New Booking</button>
      </div>
    </div>

    <section class="bk-period" aria-label="Booking report filters">
      <label>Period<select v-model="period" aria-label="Booking period" @change="setPeriod"><option v-for="[value,label] in periods" :key="value" :value="value">{{ label }}</option></select></label>
      <label>Date basis<select v-model="dateBasis" aria-label="Booking date basis"><option value="booking">Reservation date</option><option value="created">Date booked</option></select></label>
      <template v-if="period === 'custom'"><label>From<input type="date" v-model="filterDateFrom" aria-label="Booking date from" /></label><label>To<input type="date" v-model="filterDateTo" :min="filterDateFrom || undefined" aria-label="Booking date to" /></label></template>
      <div class="bk-period-actions"><button class="btn btn-secondary" :disabled="loading" @click="load"><RotateCcw :size="14" /> Refresh</button><button v-if="analytics" class="btn btn-secondary" @click="openRecords">View matching bookings</button></div>
      <p>Every total follows your filters.<br />One record per reserved slot.</p>
    </section>
    <BookingActivitySummary :data="activity" :busy="loading || !!loadError || !!invalidDates" />
    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="fbCountries"
      :cities="fbCities"
      :extra-count="activeFilterCount - (filterCountry.length ? 1 : 0) - (filterCity.length ? 1 : 0)"
      search-placeholder="Search booking, customer, staff, venue or listing…"
      @clear="resetFilters"
    >
        <div class="bk-filter-grid">
          <div class="bk-filter-field"><label>Record type</label><MultiSelect v-model="filterPurpose" :options="purposes" placeholder="Bookings and slot blocks" /></div>
          <div v-if="isPlatformAdmin" class="bk-filter-field"><label>Business</label><MultiSelect v-model="filterBusiness" :options="businessOptions" placeholder="All businesses" /></div>
          <div class="bk-filter-field">
            <label>Booking Status</label>
            <MultiSelect
              v-model="filterStatus"
              placeholder="All statuses"
              :options="[
                { value: 'confirmed',    label: 'Confirmed / completed' },
                { value: 'cancelled',    label: 'Cancelled' },
                { value: 'no_show',      label: 'No Show' },
                { value: 'pending',      label: 'Pending' },
              ]"
            />
          </div>
          <div class="bk-filter-field">
            <label>Payment Status</label>
            <MultiSelect
              v-model="filterPayment"
              placeholder="All payment statuses"
              :options="[
                { value: 'skipped',      label: 'Skipped' },
                { value: 'pending',      label: 'Pending' },
                { value: 'paid',         label: 'Paid' },
                { value: 'deposit_paid', label: 'Deposit Paid' },
                { value: 'refunded',     label: 'Refunded' },
              ]"
            />
          </div>
          <div class="bk-filter-field">
            <label>Type</label>
            <!-- This table shows venue bookings + event seats only (membership
                 calendar blocks are excluded on load), so no membership option. -->
            <MultiSelect
              v-model="filterKind"
              placeholder="All types"
              :options="kindOptions(['venue', 'event'])"
            />
          </div>
          <div class="bk-filter-field">
            <label>Booked via</label>
            <MultiSelect
              v-model="filterChannel"
              placeholder="All channels"
              :options="channelOptions(['marketplace', 'provider_site', 'manual','unknown'])"
            />
          </div>
          <div class="bk-filter-field">
            <label>Payment Method</label>
            <MultiSelect v-model="filterMethod" placeholder="All methods" :options="METHOD_OPTIONS" />
          </div>
          <div v-if="currencyOptions.length > 1" class="bk-filter-field">
            <label>Currency</label>
            <MultiSelect v-model="filterCurrency" placeholder="All currencies" :options="currencyOptions" />
          </div>
          <div class="bk-filter-field">
            <label>Venue</label>
            <MultiSelect
              v-model="filterVenue"
              placeholder="All venues"
              :options="scopedLocations.map(l => ({ value: l.id, label: l.name }))"
            />
          </div>
          <div class="bk-filter-field">
            <label>Listing</label>
            <!-- Cascades with Venue: only listings of the picked venues are offered. -->
            <MultiSelect
              v-model="filterListing"
              placeholder="All listings"
              :options="cascadedListingOptions"
            />
          </div>
          <div class="bk-filter-field">
            <label>Record Date From</label>
            <input type="date" v-model="filterCreatedFrom" aria-label="Record date from" />
          </div>
          <div class="bk-filter-field">
            <label>Record Date To</label>
            <input type="date" v-model="filterCreatedTo" aria-label="Record date to" />
          </div>
        </div>
    </FilterBar>
    <div class="bk-quick" aria-label="Quick booking filters">
      <button :class="{on:!filterPurpose.length && !filterChannel.length && !filterPayment.length}" @click="filterPurpose=[]; filterChannel=[]; filterPayment=[]">All records</button>
      <button :class="{on:filterPurpose.includes('booking')}" @click="filterPurpose=['booking']">Customer bookings</button>
      <button :class="{on:filterChannel.includes('manual')}" @click="filterChannel=['manual']">Added by staff</button>
      <button :class="{on:filterPurpose.includes('provider_slot_block')}" @click="filterPurpose=['provider_slot_block']">Slot blocks</button>
      <button :class="{on:filterPayment.includes('pending')}" @click="filterPayment=['pending','deposit_paid']">Payment outstanding</button>
    </div>

    <p v-if="invalidDates || loadError" class="bk-error" role="alert">{{ invalidDates || loadError }} <button v-if="loadError" class="btn" @click="load">Try again</button></p>
    <template v-if="analytics"><p v-if="loading" class="bk-report-state" role="status">Loading booking activity…</p><BookingActivityCharts v-else-if="activity && !invalidDates && !loadError" :data="activity" :locations="locations" :date-basis="dateBasis" @venue="filterVenue=[$event]" @records="openRecords" /></template>

    <div v-else-if="!invalidDates && !loadError" class="card bk-records" style="padding: 0;">
      <div class="bk-records-head"><div><h2>Booking records</h2><p>{{ loading ? 'Loading…' : `${total.toLocaleString()} matching records` }} · Membership schedule blocks are in Calendar.</p></div><router-link to="/dashboard">Activity analytics</router-link></div>

      <!-- ── Table ──────────────────────────────────────────────────────────── -->
      <div class="table-wrap">
        <table class="bk-table">
          <colgroup><col style="width:17%" /><col style="width:23%" /><col style="width:15%" /><col style="width:17%" /><col style="width:15%" /><col style="width:13%" /></colgroup>
          <thead>
            <tr>
              <th><button class="bk-sort" @click="toggleSort('created_at')" :aria-label="`Sort by date booked, ${sortCol==='created_at' ? sortDir : 'not sorted'}`">Booking / date booked <ChevronsUpDown :size="12" /></button></th>
              <th><button class="bk-sort" @click="toggleSort('customer')">Customer <ChevronsUpDown :size="12" /></button><button class="bk-sort secondary" @click="toggleSort('listing')">Court / listing <ChevronsUpDown :size="12" /></button></th>
              <th><button class="bk-sort" @click="toggleSort('booking_date')">Reservation <ChevronsUpDown :size="12" /></button></th>
              <th>Channel / created by</th>
              <th><button class="bk-sort" @click="toggleSort('total')">Booking value <ChevronsUpDown :size="12" /></button><button class="bk-sort secondary" @click="toggleSort('payment_status')">Payment state <ChevronsUpDown :size="12" /></button></th>
              <th><button class="bk-sort" @click="toggleSort('booking_status')">Status <ChevronsUpDown :size="12" /></button></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="6" class="empty"><Loader :size="18" class="spin" /> Loading bookings…</td></tr>
            <tr v-else-if="!paginated.length"><td colspan="6" style="padding:0">
              <EmptyState :icon="BookOpen"
                :title="isFiltering ? 'No matching bookings' : 'No bookings yet'"
                :message="isFiltering ? 'No bookings match your search or filters. Try adjusting them.' : 'Bookings will appear here once they are created.'"
                :action-label="can(CAP.MANAGE_BOOKINGS) ? 'New Booking' : ''" @action="showNewBooking = true" />
            </td></tr>
            <tr v-for="b in (loading ? [] : paginated)" :key="b.id" class="row-clickable" @click="viewingId=b.id">
              <td><button class="bk-ref" @click.stop="viewingId=b.id">{{ bookingRefLabel(b) }}</button><small class="cell-sub">{{ fmtDateTime(b.created_at) }}</small><small v-if="b.record_purpose === 'provider_slot_block'" class="cell-sub bk-block">Slot block · no sale</small><small v-else-if="b.origin === 'customer_game'" class="cell-sub">Event reservation</small></td>
              <td>
                <span v-if="b.origin === 'class_program'" class="program-block-label">{{ b.notes || 'Program Block' }}</span>
                <button v-else-if="b.customer_id" class="bk-cust-link" @click.stop="openCustomer(b.customer_id)" title="View customer">{{ customerName(b) }}</button>
                <span v-else>{{ customerName(b) }}</span>
                <div class="bk-listing">{{ listingName(b) }}</div>
                <div class="cell-sub">{{ locationName(b) }}</div>
              </td>
              <td>
                <div class="bk-reservation-date">{{ b.booking_date }}</div>
                <div class="cell-sub">{{ fmtRange12(b.start_time, b.end_time) }}</div>
              </td>
              <td>
                <span class="bk-channel">{{ activityChannelLabel(b.report_channel) }}</span>
                <small class="cell-sub">Created by {{ bookingCreatorLabel(b) }}</small>
                <small v-if="b.report_channel === 'admin'" class="cell-sub">No platform fee</small>
              </td>
              <td>
                <div class="bk-amount">{{ b.record_purpose === 'provider_slot_block' ? '—' : money(b.total,currencyOf(b)) }}</div>
                <span class="badge" :class="statusBadge(b.payment_status)">{{ statusLabel(b.payment_status) }}</span>
                <small v-if="b.recorded_payment_summary?.paid != null && b.record_purpose !== 'provider_slot_block'" class="cell-sub">{{ money(b.recorded_payment_summary.paid,b.recorded_payment_summary.currency) }} received</small>
                <small v-else-if="b.recorded_payment_summary?.status === 'Needs review'" class="cell-sub">Receipt allocation needs review</small>
              </td>
              <td>
                <span class="badge" :class="statusBadge(b.booking_status)">{{ b.booking_status === 'confirmed' ? 'Confirmed' : statusLabel(b.booking_status) }}</span>
                <div class="row-actions">
                  <button class="btn-icon" title="View"  @click.stop="viewingId=b.id"><Eye :size="15" /></button>
                  <button v-if="can(CAP.MANAGE_BOOKINGS)" class="btn-icon" title="Edit"  @click.stop="openEdit($event, b)"><Pencil :size="15" /></button>
                  <button
                    v-if="can(CAP.MANAGE_BOOKINGS)"
                    class="btn-icon danger"
                    title="Cancel booking"
                    :disabled="b.booking_status === 'cancelled'"
                    @click.stop="promptCancel($event, b)"
                  ><X :size="15" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Pagination bar ─────────────────────────────────────────────────── -->
      <PaginationBar
        :page="page" :perPage="perPage" :totalPages="totalPages"
        :total="total" :visiblePages="visiblePages"
        @update:perPage="perPage = $event"
        @goTo="goPage"
      />
    </div>

    <!-- ── Drawers & modals ───────────────────────────────────────────────── -->
    <NewBookingDrawer
      :open="showNewBooking"
      @close="showNewBooking = false"
      @saved="(ids) => { showNewBooking = false; load(); viewingId = String(Array.isArray(ids) ? ids[0] : ids) }"
    />

    <BookingDetailModal
      :bookingId="viewingId"
      @close="viewingId = null; load()"
      @viewBooking="viewingId = $event"
    />

    <BookingDetailModal
      v-if="editingId"
      :bookingId="editingId"
      :startInEdit="true"
      @close="editingId = null; load()"
      @viewBooking="editingId = $event"
    />

    <!-- Export modal -->
    <Teleport to="body">

    </Teleport>

    <!-- Cancel step 1: this session or all future? -->
    <Teleport to="body">
      <div v-if="showCancelChoice" class="bk-modal-backdrop" @click.self="abortCancel">
        <div class="bk-modal" v-dialog="abortCancel">
          <div class="bk-modal-icon warn"><AlertTriangle :size="22" /></div>
          <div class="bk-modal-title">Cancel Booking</div>
          <div class="bk-modal-body">
            This booking is part of a membership program. Would you like to cancel only this session, or cancel all future bookings for this program from today onward?
          </div>
          <div class="bk-modal-actions">
            <button class="bk-btn-ghost" @click="abortCancel">Keep</button>
            <button class="bk-btn-secondary" @click="chooseCancelThis">This session only</button>
            <button class="bk-btn-danger" @click="chooseCancelAll">All future bookings</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Cancel step 2: are you sure? -->
    <Teleport to="body">
      <div v-if="showCancelConfirm" class="bk-modal-backdrop" @click.self="abortCancel">
        <div class="bk-modal" v-dialog="abortCancel">
          <div class="bk-modal-icon danger"><AlertTriangle :size="22" /></div>
          <div class="bk-modal-title">Are you sure?</div>
          <div v-if="cancellingAll" class="bk-modal-body">
            All upcoming bookings for this program from <strong>{{ cancelTarget?.booking_date }}</strong> onward will be marked as <strong>cancelled</strong>.
            <br /><br />
            You can re-block slots for future dates from the <strong>Membership Programs</strong> page.
          </div>
          <div v-else class="bk-modal-body">
            This booking for <strong>{{ listingName(cancelTarget) }}</strong> on <strong>{{ cancelTarget?.booking_date }}</strong> will be marked as <strong>cancelled</strong>.
          </div>
          <div class="bk-modal-actions">
            <button class="bk-btn-ghost" @click="abortCancel">Go back</button>
            <button class="bk-btn-danger" :disabled="cancelling" @click="doCancel">
              {{ cancelling ? 'Cancelling…' : cancellingAll ? 'Yes, cancel all future bookings' : 'Yes, cancel booking' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.bk-quick{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 18px}.bk-quick button{border:1px solid var(--border);background:var(--surface);color:var(--muted);padding:8px 12px;border-radius:7px;font-size:12px;cursor:pointer}.bk-quick button.on{border-color:var(--primary);color:var(--primary);background:color-mix(in srgb,var(--primary) 8%,var(--surface))}
.bk-period{display:flex;align-items:end;flex-wrap:wrap;gap:14px;padding:20px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
.bk-period label{display:grid;gap:7px;font-size:12px;font-weight:600}.bk-period select,.bk-period input{min-height:38px;padding:8px 12px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:7px;font:inherit}.bk-period p{margin:0 0 2px auto;text-align:right;font-size:11px;line-height:1.6;color:var(--muted)}.bk-period-actions{display:flex;gap:10px}.bk-error{padding:16px;background:var(--surface);border:1px solid #d65b62;border-radius:8px}.bk-report-state{padding:35px;text-align:center;color:var(--muted)}.bk-records-head{display:flex;align-items:center;justify-content:space-between;padding:20px;border-bottom:1px solid var(--border)}.bk-records-head h2{margin:0 0 5px;font-size:16px}.bk-records-head p{margin:0;font-size:12px;color:var(--muted)}.bk-records-head a,.bk-ref{font-size:12px;color:var(--primary);background:none;border:0;cursor:pointer;text-align:left;padding:0}.bk-ref{font-weight:650;text-decoration:underline;text-underline-offset:3px}.bk-records small.cell-sub{display:block;max-width:190px;line-height:1.5;margin-top:5px}.bk-channel{font-size:12px;font-weight:600}.bk-amount{white-space:nowrap;font-weight:650;font-variant-numeric:tabular-nums}.bk-block{color:var(--muted)}.booking-workspace :focus-visible{outline:2px solid var(--primary);outline-offset:3px}.bk-records .row-clickable:hover{background:color-mix(in srgb,var(--primary) 5%,var(--surface))}.bk-records .badge{font-size:11px;white-space:nowrap}.bk-records .table-wrap table{min-width:940px}
@media(max-width:800px){.bk-period p{text-align:left;margin-left:0}.bk-period{padding:16px}.bk-period-actions{flex-wrap:wrap}.bk-records-head{gap:14px;align-items:start}.bk-records-head a{flex-shrink:0}}
/* ── Page header actions ──────────────────────────────────────────────────── */
.bk-head-actions { display: flex; gap: 10px; }

/* ── Toolbar ──────────────────────────────────────────────────────────────── */
.bk-toolbar {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 18px; border-bottom: 1px solid var(--border, #f1f5f9);
}
.bk-search {
  flex: 1; display: flex; align-items: center; gap: 8px;
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  padding: 7px 12px; max-width: 360px;
}
.bk-search input { border: none; background: transparent; font-size: 13.5px; color: var(--text, #0f172a); outline: none; width: 100%; }
.bk-search input::placeholder { color: var(--text-light, #94a3b8); }
.bk-search svg  { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.bk-toolbar-right { display: flex; align-items: center; gap: 6px; margin-left: auto; }
.bk-filter-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 13px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface, #ffffff); color: var(--text-secondary, #475569); font-size: 13px; font-weight: 500; cursor: pointer;
  transition: all .15s;
}
.bk-filter-btn:hover, .bk-filter-btn.active { background: var(--bg, #f1f5f9); border-color: var(--border-strong, #cbd5e1); color: var(--text, #0f172a); }
.bk-filter-count {
  background: #0f172a; color: #fff;
  font-size: 11px; font-weight: 700; border-radius: 999px;
  min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; padding: 0 5px;
}
.bk-reset-btn {
  padding: 7px 8px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface, #ffffff); color: var(--text-light, #94a3b8); cursor: pointer; display: flex; align-items: center;
  transition: all .15s;
}
.bk-reset-btn:hover { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #dc2626); border-color: var(--danger-border, #fca5a5); }

/* ── Filter panel ─────────────────────────────────────────────────────────── */
.bk-filter-panel {
  padding: 16px 18px 12px; border-bottom: 1px solid var(--border, #f1f5f9);
  background: var(--bg); animation: filterSlide .15s ease;
}
@keyframes filterSlide { from { opacity: 0; transform: translateY(-6px); } }
.bk-filter-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px;
}
.bk-filter-field { display: flex; flex-direction: column; gap: 4px; }
.bk-filter-field label { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .05em; }
.bk-filter-field select,
.bk-filter-field input {
  padding: 7px 10px; border: 1px solid var(--border, #e2e8f0); border-radius: 7px;
  font-size: 12.5px; color: var(--text, #0f172a); background: var(--surface, #ffffff); appearance: none;
  transition: border-color .15s;
}
.bk-filter-field select:focus, .bk-filter-field input:focus { outline: none; border-color: #3b82f6; }
.bk-filter-footer {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 12px;
}
.bk-filter-count-label { font-size: 12px; color: var(--text-light, #94a3b8); }
.bk-reset-link {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; color: var(--text-muted, #64748b); background: none; border: none; cursor: pointer;
}
.bk-reset-link:hover { color: var(--danger-ink, #dc2626); }

/* ── Table ────────────────────────────────────────────────────────────────── */
.bk-table{width:100%;min-width:940px;table-layout:fixed}.bk-table th,.bk-table td{padding:16px 14px;vertical-align:top;white-space:normal;overflow-wrap:anywhere}.bk-table th{font-size:11px}.bk-sort{display:flex;align-items:center;gap:5px;background:none;border:0;padding:0;color:inherit;font:inherit;font-weight:600;text-align:left;cursor:pointer}.bk-sort.secondary{margin-top:6px;font-weight:400}.bk-sort:hover{color:var(--text)}.bk-sort:focus-visible{outline:2px solid var(--primary);outline-offset:3px}.bk-table .bk-ref{font-size:11px;color:var(--primary-ink);overflow-wrap:anywhere}.bk-table .cell-sub{display:block;font-size:11px;line-height:1.5;margin-top:5px}.bk-listing{font-size:12px;margin-top:8px;line-height:1.5}.bk-reservation-date{white-space:nowrap;font-weight:600;font-size:12px}.bk-table .bk-amount{font-size:13px;white-space:nowrap;margin-bottom:8px}.bk-table .bk-cust-link{font-size:12px;font-weight:600}.bk-table .row-actions{margin-top:10px}.bk-table .badge{max-width:100%;font-size:10px}.bk-table .bk-channel{font-size:12px}
.th-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.th-sort:hover { background: var(--surface-hover, #f8fafc); }
.sort-icon { display: inline-flex; align-items: center; margin-left: 4px; color: var(--text-light, #94a3b8); vertical-align: middle; }
.cell-sub { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.record-date { font-size: 12.5px; color: var(--text-muted, #64748b); white-space: nowrap; }

/* ── Pagination ───────────────────────────────────────────────────────────── */
.bk-pagination {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 12px 18px; border-top: 1px solid var(--border, #f1f5f9);
}
.bk-per-page {
  display: flex; align-items: center; gap: 7px;
  font-size: 13px; color: var(--text-muted, #64748b);
}
.bk-per-page select {
  padding: 5px 26px 5px 9px; border: 1px solid var(--border, #e2e8f0); border-radius: 7px;
  font-size: 13px; color: var(--text, #0f172a); background: var(--surface, #ffffff); appearance: none; cursor: pointer;
}
.bk-page-info { font-size: 13px; color: var(--text-muted, #64748b); white-space: nowrap; }
.bk-page-nav  { display: flex; align-items: center; gap: 2px; margin-left: auto; }
.bk-page-btn  {
  min-width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border, #e2e8f0); border-radius: 7px; background: var(--surface, #ffffff);
  font-size: 13px; color: var(--text-secondary, #475569); cursor: pointer; transition: all .15s; padding: 0 6px;
}
.bk-page-btn:hover:not(:disabled):not(.active) { background: var(--bg, #f1f5f9); }
.bk-page-btn.active { background: #0f172a; color: #fff; border-color: #0f172a; font-weight: 600; }
.bk-page-btn:disabled { opacity: .35; cursor: not-allowed; }
.bk-page-ellipsis { font-size: 13px; color: var(--text-light, #94a3b8); padding: 0 4px; }

/* ── Export modal extras ──────────────────────────────────────────────────── */
.export-modal { max-width: 380px; }
.export-options { display: flex; flex-direction: column; gap: 8px; width: 100%; margin-top: 4px; }
.export-option-btn {
  padding: 12px 16px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px;
  background: var(--surface, #ffffff); cursor: pointer; text-align: left; transition: all .15s;
}
.export-option-btn:hover { border-color: #3b82f6; background: var(--info-bg, #eff6ff); }
.export-opt-title { font-size: 13.5px; font-weight: 600; color: var(--text, #0f172a); }
.export-opt-sub   { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 2px; }

/* ── Cancel / confirm modals ──────────────────────────────────────────────── */
.bk-modal-backdrop {
  position: fixed; inset: 0; background: rgba(15,23,42,.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 9999; padding: 20px;
}
.bk-modal {
  background: var(--surface, #ffffff); border-radius: 14px; padding: 28px 28px 22px;
  max-width: 420px; width: 100%;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  animation: bkModalIn .18s cubic-bezier(.22,1,.36,1);
}
@keyframes bkModalIn { from { opacity:0; transform: scale(.95) translateY(8px); } }
.bk-modal-icon {
  width: 48px; height: 48px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.bk-modal-icon.warn   { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #d97706); }
.bk-modal-icon.danger { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #dc2626); }
.bk-modal-title  { font-size: 17px; font-weight: 700; color: var(--text, #0f172a); text-align: center; }
.bk-modal-body   { font-size: 13.5px; color: var(--text-secondary, #475569); text-align: center; line-height: 1.6; }
.bk-modal-body strong { color: var(--text, #0f172a); }
.bk-modal-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 8px; width: 100%; }
.bk-btn-ghost {
  padding: 9px 18px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface-hover, #f8fafc); color: var(--text-muted, #64748b); font-size: 13.5px; font-weight: 500; cursor: pointer;
  transition: background .15s;
}
.bk-btn-ghost:hover { background: var(--bg, #f1f5f9); }
.bk-btn-secondary {
  padding: 9px 18px; border-radius: 8px; border: 1px solid #3b82f6;
  background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); font-size: 13.5px; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.bk-btn-secondary:hover { background: var(--info-bg, #dbeafe); }
.bk-btn-danger {
  padding: 9px 18px; border-radius: 8px; border: none;
  background: #dc2626; color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.bk-btn-danger:hover:not(:disabled) { background: #b91c1c; }
.bk-btn-danger:disabled { opacity: .6; cursor: not-allowed; }

/* ── Misc ─────────────────────────────────────────────────────────────────── */
.mono { font-family: 'SF Mono', Monaco, monospace; font-size: 13px; color: var(--text-muted); }
.bk-cust-link { background: none; border: none; padding: 0; font: inherit; font-weight: 500; color: var(--primary-ink, #0f766e); cursor: pointer; text-align: left; }
.bk-cust-link:hover { text-decoration: underline; }
.row-clickable { cursor: pointer; }
.row-clickable:hover td { background: var(--surface-hover, #f8fafc); }
.row-actions { display: flex; gap: 4px; }
.btn-icon { padding: 6px; border-radius: 6px; color: var(--text-muted); transition: all 0.15s; background: none; border: none; cursor: pointer; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: var(--danger-bg, #fee2e2); color: var(--error); }
.btn-icon:disabled { opacity: .3; cursor: not-allowed; }
.badge-program { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); font-weight: 600; }
.badge-game { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #6d28d9); font-weight: 600; }
.badge-site { background: var(--primary-soft, #ccfbf1); color: var(--primary-ink, #0f766e); font-weight: 600; }
.program-block-label { font-weight: 600; color: var(--warning-ink, #92400e); font-size: 13px; }
.muted-text { color: var(--text-muted); font-size: 13px; }

/* ── Phone (≤600px) ───────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  /* Filter fields: 16px font kills iOS auto-zoom, 44px min tap target */
  .bk-filter-field select,
  .bk-filter-field input {
    font-size: 16px;
    padding: 10px 12px;
    min-height: 44px;
  }

  /* Header action cluster can wrap instead of overflowing on narrow phones */
  .bk-head-actions { flex-wrap: wrap; width: 100%; }
  .bk-head-actions .btn { flex: 1 1 auto; justify-content: center; }

  /* Teleported modals become bottom sheets */
  .bk-modal-backdrop { padding: 0; align-items: flex-end; }
  .bk-modal {
    max-width: 100%;
    width: 100%;
    border-radius: 16px 16px 0 0;
    padding: 22px 18px 18px;
    max-height: 92vh;
    overflow-y: auto;
  }
  .export-modal { max-width: 100%; }
  .bk-modal-actions { flex-direction: column-reverse; flex-wrap: nowrap; }
  .bk-modal-actions > button { width: 100%; min-height: 44px; }

  /* 44px tap targets for modal footer + export option buttons */
  .bk-btn-ghost, .bk-btn-secondary, .bk-btn-danger {
    min-height: 44px;
    padding: 11px 18px;
  }
  .export-option-btn { min-height: 44px; }
}
</style>
