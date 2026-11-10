<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Search, Download, SlidersHorizontal, RefreshCw, ArrowUpDown, ChevronRight, X, ExternalLink } from 'lucide-vue-next'
import { fetchPaymentsDashboard } from '../lib/payments.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
import { usePremium } from '../lib/premium.js'
import { downloadCSV } from '../lib/useExport.js'
import { pushToast } from '../lib/toast.js'
import { stripeInvoiceUrl } from '../lib/stripeInvoice.js'
import { dialogFocus as vDialogFocus } from '../lib/dialogFocus.js'
import { paymentCategories, channelOptions, methodOptions, statusOptions, labelFor, money, periodDates, filterError, queryFilters, legacyPaymentRows } from '../lib/paymentReport.js'
import MultiSelect from '../components/MultiSelect.vue'
import BookingDetailModal from '../components/BookingDetailModal.vue'
import './payments.css'

const { currentUser, can } = useCurrentUser()
const { requirePremium } = usePremium()
const perspective = ref('')
const platform = computed(() => perspective.value ? perspective.value === 'platform' : ['super_admin', 'admin', 'country_admin', 'platform_staff'].includes(currentUser.value?.role))
const defaults = () => ({ search:'', currency:[], status:[], method:[], category:[], channel:[], source:[], location_id:[], business_id:[], from_date:'', to_date:'', amount_min:'', amount_max:'', scope:'all' })
const filters = reactive(defaults())
const period = ref('all'), advanced = ref(false), page = ref(1), perPage = ref(25), total = ref(0), sort = ref('date'), sortDir = ref('desc')
const rows = ref([]), summary = ref([]), options = ref({ currencies:[], venues:[], businesses:[] }), ready = ref(null), reviewCount = ref(0)
const loading = ref(false), error = ref(''), exporting = ref(false), selected = ref(null), bookingId = ref(null), collectId = ref(null)
const invalid = computed(() => filterError(filters))
const lastPage = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const currencies = computed(() => (options.value.currencies || []).map(value => ({ value, label:value })))
const sourceOptions = [{ value:'venue', label:'Venue bookings' }, { value:'event', label:'Events' }, { value:'subscription', label:'Memberships' }]
let timer, requestId = 0
const query = () => ({ ...queryFilters(filters), report_version:2, sort:sort.value, sort_dir:sortDir.value })
async function load() {
  clearTimeout(timer)
  const id = ++requestId
  if (invalid.value) { loading.value = false; return }
  loading.value = true; error.value = ''
  try {
    const result = await fetchPaymentsDashboard({ ...query(), page:page.value, records_per_page:perPage.value })
    if (id !== requestId) return
    if (!result.success) throw new Error(result.message || 'Could not load payments.')
    const data = result.data || {}
    ready.value = data.report_version === 2
    perspective.value = data.perspective || ''
    rows.value = ready.value ? data.payments.data : legacyPaymentRows(data)
    total.value = Number(data.payments?.total ?? rows.value.length)
    summary.value = ready.value ? data.summary || [] : []
    reviewCount.value = ready.value ? data.review_count || 0 : 0
    options.value = { currencies:[], venues:[], businesses:[], ...data.filter_options }
  } catch (e) {
    if (id !== requestId) return
    error.value = e.message || 'Could not load payments. Please try again.'
    rows.value = []; summary.value = []; total.value = 0
  } finally { if (id === requestId) loading.value = false }
}
watch(filters, () => { ++requestId; clearTimeout(timer); page.value = 1; loading.value = true; timer = setTimeout(load, 250) }, { deep:true, flush:'sync' })
function setPeriod() { if (period.value !== 'custom') [filters.from_date, filters.to_date] = periodDates(period.value) }
function reset() { period.value = 'all'; Object.assign(filters, defaults()) }
function goTo(next) { page.value = next; load() }
function changeSort(key) { sortDir.value = sort.value === key && sortDir.value === 'desc' ? 'asc' : 'desc'; sort.value = key; page.value = 1; load() }
function amounts(key) {
  if (!ready.value || error.value || invalid.value || loading.value) return ['—']
  return summary.value.length ? summary.value.map(s => money(s[key], s.currency)) : ['No recorded amount']
}
const cards = computed(() => platform.value ? [
  ['bookboll_received','Bookboll received','Platform fees + all Bookboll venue receipts'],
  ['platform_fees','Platform fees','External + own-venue fees, memberships and events'],
  ['owned_venue_received','Own venues · revenue','Venue share after the internal platform fee'],
  ['owned_pending','Own bookings · to collect','Unpaid platform fee + venue share'],
] : [
  ['provider_received','Your business received','Your share of recorded paid transactions'],
  ['provider_arrival','Arrival payments received','Collected at your venues'],
  ['provider_pending','Still to collect','Unpaid amounts belonging to your business'],
  ['platform_fees','Bookboll fees','Recorded fees retained by Bookboll'],
])
const breakdown = computed(() => platform.value ? [
  ['external_marketplace_fees','External venue fees · marketplace'], ['external_site_fees','External venue fees · provider websites'],
  ['owned_marketplace_fees','Own venue platform fees · marketplace'], ['owned_site_fees','Own venue platform fees · own website'],
  ['other_channel_fees','Booking fees · channel not recorded'], ['membership_fees','Provider membership fees'],
  ['event_fees','Event host fees'],
  ['owned_online','Own venue share · online'], ['owned_onsite','Own venue share · on site'],
  ['owned_unallocated','Own venue receipts · split needs review'],
] : [['provider_online','Your online share'], ['provider_arrival','Your arrival collections'], ['provider_manual','Your staff-added bookings']])
const categoryLabel = p => labelFor(paymentCategories, p.category)
const hasFee = p => ['provider_booking_fee','owned_platform_fee','provider_membership_fee','event_fee'].includes(p.category)
const canCollect = p => p.can_collect && can(CAP.ACCEPT_PAYMENTS) && p.recipient_kind === (platform.value ? 'bookboll' : 'provider')
function viewBooking(p, collect = false) { selected.value = null; collectId.value = collect ? String(p.source_payment_id || p.id) : null; bookingId.value = String(p.booking_id) }
async function exportRows() {
  if (!can(CAP.EXPORT_PAYMENTS) || (!platform.value && !requirePremium('exports'))) return
  exporting.value = true
  const snapshot = query(), collected = [], ids = new Set()
  try {
    let expected
    for (let n = 1; ; n++) {
      const response = await fetchPaymentsDashboard({ ...snapshot, page:n, records_per_page:250 })
      if (!response.success || response.data?.report_version !== 2) throw new Error('The updated payment report is required to export allocations.')
      const data = response.data.payments
      if (expected !== undefined && expected !== data.total) throw new Error('Payments changed during export. Please try again.')
      expected = data.total
      for (const row of data.data) {
        if (ids.has(row.id)) throw new Error('Payments changed during export. Please try again.')
        ids.add(row.id); collected.push(row)
      }
      if (collected.length >= expected) break
      if (!data.data.length) throw new Error('The export was incomplete. Please try again.')
    }
    if (!collected.length) { pushToast('No matching payments to export.', 'info'); return }
    // Spreadsheet formulas must not execute when customer-supplied names are opened in Excel.
    const safe = value => /^[=+\-@\t\r]/.test(String(value ?? '')) ? "'" + value : value
    const columns = [['id','Allocation record'],['source_payment_id','Original payment record'],['allocation_id','Saved internal allocation'],['payment_id','Payment'],['booking_ref','Booking'],['payment_date','Payment date'],['customer_name','Customer'],['venue_name','Venue'],['recipient_label','Recipient'],['category','Allocation'],['channel','Booking channel'],['method','Method'],['currency','Currency'],['amount','Amount'],['status','Status'],['fee_rate','Recorded fee %'],['review_reason','Review note']]
    downloadCSV(collected, columns.map(([key,label]) => ({ label, fn:r => safe(r[key]) })), 'payment_allocations')
  } catch (e) { pushToast(e.message, 'error') }
  finally { exporting.value = false }
}
onMounted(load)
onBeforeUnmount(() => { ++requestId; clearTimeout(timer) })
</script>

<template>
  <div class="page payments-page">
    <header class="page-header">
      <div><h1>Payments</h1><p>{{ platform ? 'Bookboll earnings, venue collections and provider sales.' : 'Your collections, outstanding balances and Bookboll fees.' }}</p></div>
      <div class="pay-actions"><button class="btn" :disabled="loading" @click="load"><RefreshCw :size="15" /> Refresh</button><button v-if="can(CAP.EXPORT_PAYMENTS)" class="btn" :disabled="exporting || !ready || loading || !!invalid" @click="exportRows"><Download :size="15" /> {{ exporting ? 'Exporting…' : 'Export CSV' }}</button></div>
    </header>

    <section class="pay-period" aria-label="Payment reporting period">
      <label>Period<select v-model="period" @change="setPeriod"><option value="all">All time</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="month">This month</option><option value="custom">Custom dates</option></select></label>
      <template v-if="period === 'custom'"><label>From<input v-model="filters.from_date" type="date" /></label><label>To<input v-model="filters.to_date" type="date" :min="filters.from_date || undefined" /></label></template>
      <div class="pay-filter"><span>Currency</span><MultiSelect v-model="filters.currency" :options="currencies" placeholder="All currencies" /></div>
      <p>Totals follow all selected filters.<br />Paid: payment date. Unpaid: recorded date.</p>
    </section>
    <p v-if="invalid" class="pay-notice pay-error" role="alert">{{ invalid }}</p>
    <p v-if="error" class="pay-notice pay-error" role="alert">{{ error }} <button class="pay-link" @click="load">Try again</button></p>
    <p v-if="ready === false && !error" class="pay-notice">Payment records are available. Earnings totals will appear after the updated payment report is deployed; the existing totals do not reliably separate fees from provider money.</p>
    <p v-if="ready && reviewCount && !loading" class="pay-notice">{{ reviewCount.toLocaleString() }} matching records need review. Confirmed own-venue receipts count once in Bookboll received; their unknown fee split is shown separately. Other unclassified payments are excluded. <button class="pay-link" @click="filters.scope = 'review'">Review records</button></p>

    <section class="pay-summary" aria-label="Payment totals" :aria-busy="loading">
      <article v-for="[key,title,description] in cards" :key="key" class="pay-stat"><h2>{{ title }}</h2><strong v-for="amount in amounts(key)" :key="amount">{{ amount }}</strong><p>{{ description }}</p></article>
    </section>

    <div class="pay-breakdown">
      <section class="pay-panel"><div class="pay-panel-heading"><h2>{{ platform ? 'Where Bookboll’s money came from' : 'Where your money came from' }}</h2><span>Paid only</span></div>
        <dl><div v-for="[key,title] in breakdown" :key="key"><dt>{{ title }}</dt><dd><span v-for="amount in amounts(key)" :key="amount">{{ amount }}</span></dd></div></dl>
      </section>
      <section class="pay-panel pay-context">
        <template v-if="platform"><span class="pay-eyebrow">External businesses</span><h2>Sales sent to providers</h2><strong v-for="amount in amounts('provider_sales')" :key="amount">{{ amount }}</strong><p>Recorded booking value from the marketplace and provider websites, including their arrival balances.</p><hr /><h3>Only Bookboll’s share is your earnings</h3><p>Provider deposits are platform fees. For full online payments, only the recorded Bookboll fee belongs to the platform. Provider arrival payments stay with the provider.</p><p>Own venues are separate business units: customer bookings allocate the recorded platform fee first, then the rest to venue revenue. Manual staff bookings stay entirely with the venue. Rent and landlord revenue share are separate costs.</p><hr /><h3>Own bookings awaiting payment</h3><dl><div><dt>Platform fee to collect</dt><dd><span v-for="amount in amounts('owned_platform_pending')" :key="amount">{{ amount }}</span></dd></div><div><dt>Venue share to collect</dt><dd><span v-for="amount in amounts('owned_venue_pending')" :key="amount">{{ amount }}</span></dd></div><div><dt>Pending · split needs review</dt><dd><span v-for="amount in amounts('owned_unallocated_pending')" :key="amount">{{ amount }}</span></dd></div></dl></template>
        <template v-else><span class="pay-eyebrow">How payments are split</span><h2>Your money, clearly separated</h2><h3>Deposit + arrival</h3><p>The online deposit is Bookboll’s fee. Collect the remaining balance from your customer at the venue.</p><hr /><h3>Full online payment</h3><p>Your share and Bookboll’s fee are separate records. Your online share is payment income; bank payout timing is shown in your connected payment account.</p><hr /><h3>Bookings added by your staff</h3><p>Recorded payments belong to your business. Skip payment creates no payment record and contributes no earnings here.</p></template>
      </section>
    </div>

    <section class="pay-ledger" aria-labelledby="ledger-heading" :aria-busy="loading">
      <div class="pay-ledger-heading"><div><h2 id="ledger-heading">Payment records</h2><p>{{ ready ? 'One amount, one recipient. Split receipts are counted through their allocations once.' : 'Existing records · allocation verification pending' }}</p></div><span>{{ total.toLocaleString() }} records</span></div>
      <div class="pay-tools"><div class="pay-search"><Search :size="17" /><input v-model="filters.search" type="search" maxlength="150" aria-label="Search payments" placeholder="Search payment, booking, customer or venue…" /></div><button class="btn" :aria-expanded="advanced" aria-controls="payment-filters" @click="advanced = !advanced"><SlidersHorizontal :size="16" /> Filters</button><button class="pay-link" @click="reset">Reset</button></div>
      <div v-if="advanced" id="payment-filters" class="pay-advanced">
        <div class="pay-filter"><span>Status</span><MultiSelect v-model="filters.status" :options="statusOptions" placeholder="All statuses" /></div><div class="pay-filter"><span>Payment method</span><MultiSelect v-model="filters.method" :options="methodOptions" placeholder="All methods" /></div>
        <template v-if="ready"><div class="pay-filter"><span>Allocation</span><MultiSelect v-model="filters.category" :options="paymentCategories" placeholder="All allocations" /></div><div class="pay-filter"><span>Booking channel</span><MultiSelect v-model="filters.channel" :options="channelOptions" placeholder="All channels" /></div><div class="pay-filter"><span>Source</span><MultiSelect v-model="filters.source" :options="sourceOptions" placeholder="All sources" /></div><div class="pay-filter"><span>Venue</span><MultiSelect v-model="filters.location_id" :options="options.venues" placeholder="All venues" /></div><div v-if="platform" class="pay-filter"><span>Business</span><MultiSelect v-model="filters.business_id" :options="options.businesses" placeholder="All businesses" /></div></template>
        <label>Minimum amount<input v-model="filters.amount_min" type="number" min="0" step="0.01" placeholder="No minimum" /></label><label>Maximum amount<input v-model="filters.amount_max" type="number" min="0" step="0.01" placeholder="No maximum" /></label>
      </div>
      <div v-if="ready" class="pay-scopes" role="group" aria-label="Filter recipient"><button :class="{active:filters.scope === 'all'}" :aria-pressed="filters.scope === 'all'" @click="filters.scope = 'all'">All records</button><button :class="{active:filters.scope === (platform ? 'bookboll' : 'provider')}" :aria-pressed="filters.scope === (platform ? 'bookboll' : 'provider')" @click="filters.scope = platform ? 'bookboll' : 'provider'">{{ platform ? 'Bookboll’s share' : 'Your share' }}</button><button :class="{active:filters.scope === 'review'}" :aria-pressed="filters.scope === 'review'" @click="filters.scope = 'review'">Needs review</button></div>
      <div class="pay-table-wrap"><table class="pay-table"><thead><tr><th :aria-sort="sort === 'date' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"><button @click="changeSort('date')">Payment / date <ArrowUpDown :size="13" /></button></th><th>Customer / venue</th><th>Booking channel</th><th>Allocation / recipient</th><th>Method</th><th class="pay-number" :aria-sort="sort === 'amount' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"><button @click="changeSort('amount')">Amount <ArrowUpDown :size="13" /></button></th><th>Status</th><th><span class="pay-sr">Details</span></th></tr></thead>
        <tbody><tr v-if="loading || invalid || error || !rows.length"><td colspan="8" class="pay-empty">{{ invalid || (loading ? 'Loading payments…' : error ? 'Payments could not be loaded.' : 'No payments match these filters.') }}</td></tr>
          <tr v-for="p in (loading || invalid || error ? [] : rows)" :key="p.id"><td><button class="pay-link" @click="selected = p">{{ p.payment_id || '#' + p.id }}</button><small>{{ p.payment_date || 'Date not recorded' }}</small><small v-if="p.booking_ref">{{ p.booking_ref }}</small></td><td><b>{{ p.customer_name }}</b><small>{{ p.venue_name }}</small><small v-if="p.listing_name">{{ p.listing_name }}</small></td><td>{{ labelFor(channelOptions,p.channel) }}</td><td><b>{{ categoryLabel(p) }}</b><small>{{ p.recipient_label }}</small><small v-if="hasFee(p) && p.fee_rate != null">{{ p.fee_rate }}% recorded rate</small></td><td>{{ labelFor(methodOptions,p.method) }}</td><td class="pay-number"><b>{{ money(p.amount,p.currency) }}</b></td><td><span class="pay-status" :class="'pay-status-' + p.status">{{ labelFor(statusOptions,p.status) }}</span><button v-if="canCollect(p)" class="pay-link pay-collect" @click="viewBooking(p,true)">Record payment</button></td><td><button class="pay-icon" :aria-label="'Details for payment ' + (p.payment_id || p.id)" @click="selected = p"><ChevronRight :size="18" /></button></td></tr>
        </tbody></table></div>
      <footer class="pay-pagination"><label>Rows<select v-model.number="perPage" @change="goTo(1)"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label><div><button class="btn" :disabled="loading || page <= 1" @click="goTo(page-1)">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="btn" :disabled="loading || page >= lastPage" @click="goTo(page+1)">Next</button></div></footer>
    </section>

    <Teleport to="body"><div v-if="selected" class="pay-overlay" @click.self="selected = null"><section v-dialog-focus="() => selected = null" class="pay-detail"><header><div><span class="pay-eyebrow">Payment details</span><h2>{{ selected.payment_id || '#' + selected.id }}</h2></div><button class="pay-icon" aria-label="Close payment details" @click="selected = null"><X :size="20" /></button></header><strong class="pay-detail-amount">{{ money(selected.amount, selected.currency) }}</strong><span class="pay-status" :class="'pay-status-' + selected.status">{{ labelFor(statusOptions,selected.status) }}</span><p v-if="selected.review_reason" class="pay-notice">{{ selected.review_reason }}</p><dl><div><dt>Recipient</dt><dd>{{ selected.recipient_label }}</dd></div><div><dt>Allocation</dt><dd>{{ categoryLabel(selected) }}</dd></div><div><dt>Channel</dt><dd>{{ labelFor(channelOptions,selected.channel) }}</dd></div><div><dt>Customer</dt><dd>{{ selected.customer_name }}</dd></div><div><dt>Venue</dt><dd>{{ selected.venue_name }}</dd></div><div><dt>Method</dt><dd>{{ labelFor(methodOptions,selected.method) }}</dd></div><div><dt>Payment date</dt><dd>{{ selected.payment_date || 'Not recorded' }}</dd></div><div v-if="hasFee(selected)"><dt>Recorded rate</dt><dd>{{ selected.fee_rate == null ? 'Not recorded' : selected.fee_rate + '%' }}</dd></div><div v-if="selected.receipt_amount != null"><dt>Original receipt</dt><dd>{{ money(selected.receipt_amount, selected.currency) }}</dd></div><div v-if="selected.source_receipt_ids?.length"><dt>Source receipt IDs</dt><dd>{{ selected.source_receipt_ids.join(', ') }}</dd></div></dl><p v-if="selected.notes">{{ selected.notes }}</p><p v-if="selected.source_payment_id && selected.status === 'pending'" class="pay-detail-note">Record payment opens the original booking receipt. Its platform and venue allocations become paid together.</p><p class="pay-detail-note">Amounts follow the recorded transaction. Current commission settings are never used to recalculate this report.</p><div class="pay-actions"><button v-if="selected.booking_id" class="btn" @click="viewBooking(selected)">View booking</button><a v-if="stripeInvoiceUrl(selected)" class="btn" :href="stripeInvoiceUrl(selected)" target="_blank" rel="noopener noreferrer"><ExternalLink :size="15" /> Stripe receipt</a><button v-if="canCollect(selected)" class="btn primary" @click="viewBooking(selected,true)">Record payment</button></div></section></div></Teleport>
    <BookingDetailModal v-if="bookingId" :booking-id="bookingId" :collect-payment-id="collectId" @close="bookingId = null; collectId = null; load()" />
  </div>
</template>
