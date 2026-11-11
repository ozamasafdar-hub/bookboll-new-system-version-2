<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { RefreshCw, SlidersHorizontal, ChevronRight, ArrowUpRight } from 'lucide-vue-next'
import { apiFetch } from '../lib/api.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { paymentCards, paymentBreakdown, metricValue } from '../lib/paymentMetrics.js'
import { channelOptions, methodOptions, statusOptions, money, periodDates, filterError, queryFilters, listingsForVenues, keepAvailableListings, labelFor } from '../lib/paymentReport.js'
import MultiSelect from './MultiSelect.vue'
import TrendChart from './viz/TrendChart.vue'
import HBarChart from './viz/HBarChart.vue'
import ExternalBusinessTotals from './ExternalBusinessTotals.vue'
import '../pages/payments.css'

const defaults = () => ({ currency:[], status:[], method:[], channel:[], source:[], location_id:[], listing_id:[], business_id:[], from_date:'', to_date:'' })
const filters = reactive(defaults()), period = ref('all'), advanced = ref(false)
const data = ref(null), loading = ref(true), error = ref(''), chartCurrency = ref(''), trendMetric = ref('')
const options = computed(() => data.value?.filter_options || { currencies:[], venues:[], listings:[], businesses:[] })
const { currentUser } = useCurrentUser()
const platform = computed(() => data.value?.perspective ? data.value.perspective === 'platform' : ['super_admin','admin','country_admin','platform_staff'].includes(currentUser.value?.role))
const summary = computed(() => data.value?.summary || [])
const analytics = computed(() => data.value?.analytics || { timeline:[], channels:[], methods:[], venues:[] })
const cards = computed(() => paymentCards(platform.value)), breakdown = computed(() => paymentBreakdown(platform.value))
const invalid = computed(() => filterError(filters))
const usable = computed(() => !!data.value && !loading.value && !error.value && !invalid.value)
const listingOptions = computed(() => listingsForVenues(options.value.listings, filters.location_id).map(row => ({ ...row, label:`${row.label} · ${row.venue_name}` })))
const currencyOptions = computed(() => options.value.currencies.map(value => ({ value, label:value })))
const chartCurrencies = computed(() => summary.value.map(row => row.currency))
const sources = [{value:'venue',label:'Venue bookings'},{value:'subscription',label:'Memberships'},{value:'event',label:'Events'}]
const filterCount = computed(() => ['status','method','channel','source','location_id','listing_id','business_id'].reduce((n,k) => n + filters[k].length,0))
const metricOptions = computed(() => platform.value ? [
  ['bookboll_received','Bookboll received'],['platform_fees','Platform fees'],['owned_venue_received','Own venue revenue'],['total_sales','Total sales · GMV'],['provider_received','External businesses received'],['owned_pending','Own bookings to collect'],
] : [['provider_received','Your business received'],['total_sales','Total sales · GMV'],['provider_pending','Still to collect'],['platform_fees','Bookboll fees']])
const receivedKey = computed(() => platform.value ? 'bookboll_received' : 'provider_received')
const pendingKey = computed(() => platform.value ? 'owned_pending' : 'provider_pending')
const trendTitle = computed(() => metricOptions.value.find(([key]) => key === trendMetric.value)?.[1] || '')
const trendPoints = computed(() => analytics.value.timeline.map(row => ({label:row.key,value:metricValue(row.summary,chartCurrency.value,trendMetric.value)})))
const methodBars = computed(() => analytics.value.methods.map(row => ({label:labelFor(methodOptions,row.key),value:metricValue(row.summary,chartCurrency.value,receivedKey.value)})).filter(row => row.value).sort((a,b) => b.value-a.value))
const venues = computed(() => [...analytics.value.venues].filter(row => row.summary.some(s => s.currency === chartCurrency.value)).sort((a,b) => value(b,'total_sales')-value(a,'total_sales')))
const channels = computed(() => analytics.value.channels.filter(row => row.summary.some(s => s.currency === chartCurrency.value)))
function value(row,key) { return metricValue(row.summary,chartCurrency.value,key) }
function amount(row,key) { return money(value(row,key),chartCurrency.value) }
function amounts(key) { return !usable.value ? ['—'] : summary.value.length ? summary.value.map(row => money(row[key],row.currency)) : ['No recorded amount'] }
let timer, requestId = 0
async function load(refresh = false) {
  clearTimeout(timer)
  const request = ++requestId
  if (invalid.value) { loading.value=false; return }
  loading.value=true; error.value=''
  const result = await apiFetch('/admin/payments/analytics', {query:{...queryFilters(filters), ...(refresh === true ? {refresh:1} : {})}})
  if (request !== requestId) return
  loading.value=false
  if (!result.success || !result.data?.analytics) { error.value=result.message || 'Could not load payment analytics.'; return }
  data.value=result.data
  if (!chartCurrencies.value.includes(chartCurrency.value)) chartCurrency.value=chartCurrencies.value.includes('QAR') ? 'QAR' : chartCurrencies.value[0] || ''
  if (!metricOptions.value.some(([key]) => key === trendMetric.value)) trendMetric.value=receivedKey.value
}
function setPeriod() { if (period.value !== 'custom') [filters.from_date,filters.to_date]=periodDates(period.value) }
function reset() { period.value='all'; Object.assign(filters,defaults()) }
watch(filters, () => { ++requestId; loading.value=true; clearTimeout(timer); timer=setTimeout(load,250) }, {deep:true,flush:'sync'})
watch(() => filters.location_id, () => { const next=keepAvailableListings(filters.listing_id,listingOptions.value); if (next.length !== filters.listing_id.length) filters.listing_id=next },{deep:true})
onMounted(load)
onBeforeUnmount(() => { ++requestId; clearTimeout(timer) })
</script>

<template>
  <div class="payments-page payment-analytics">
    <div class="pa-heading"><div><h2>Payments &amp; revenue</h2><p>Same records and allocation rules as Payments. Staff-added bookings are included.</p></div><div class="pay-actions"><button class="btn" :disabled="loading" @click="load(true)"><RefreshCw :size="15" /> Refresh</button><router-link class="btn btn-secondary" to="/payments">Payment records <ArrowUpRight :size="15" /></router-link></div></div>
    <section class="pay-period" aria-label="Payment analytics filters">
      <label>Period<select v-model="period" aria-label="Payment analytics period" @change="setPeriod"><option value="all">All time</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="month">This month</option><option value="last_month">Last month</option><option value="custom">Custom dates</option></select></label>
      <template v-if="period === 'custom'"><label>From<input v-model="filters.from_date" type="date" aria-label="Payment analytics start date" /></label><label>To<input v-model="filters.to_date" type="date" :min="filters.from_date || undefined" aria-label="Payment analytics end date" /></label></template>
      <div class="pay-filter"><span>Currency</span><MultiSelect v-model="filters.currency" :options="currencyOptions" placeholder="All currencies" /></div>
      <button class="btn" :aria-expanded="advanced" aria-controls="analytics-payment-filters" @click="advanced=!advanced"><SlidersHorizontal :size="15" /> Filters {{ filterCount ? `(${filterCount})` : '' }}</button><button class="pay-link" @click="reset">Reset</button>
      <p>Paid: payment date. Unpaid: recorded date.<br />Every total follows these filters.</p>
    </section>
    <div v-if="advanced" id="analytics-payment-filters" class="pay-advanced pa-filters">
      <div class="pay-filter"><span>Status</span><MultiSelect v-model="filters.status" :options="statusOptions" placeholder="All statuses" /></div>
      <div class="pay-filter"><span>Method</span><MultiSelect v-model="filters.method" :options="methodOptions" placeholder="All methods" /></div>
      <div class="pay-filter"><span>Booking channel</span><MultiSelect v-model="filters.channel" :options="channelOptions" placeholder="All channels" /></div>
      <div v-if="platform" class="pay-filter"><span>Business</span><MultiSelect v-model="filters.business_id" :options="options.businesses" placeholder="All businesses" /></div>
      <div class="pay-filter"><span>Venue</span><MultiSelect v-model="filters.location_id" :options="options.venues" placeholder="All venues" /></div>
      <div class="pay-filter"><span>Listing</span><MultiSelect v-model="filters.listing_id" :options="listingOptions" placeholder="All listings" /></div>
      <div class="pay-filter"><span>Source</span><MultiSelect v-model="filters.source" :options="sources" placeholder="All sources" /></div>
    </div>
    <p v-if="invalid || error" class="pay-notice pay-error" role="alert">{{ invalid || error }} <button v-if="error" class="pay-link" @click="load">Try again</button></p>
    <p v-if="usable && data.review_count" class="pay-notice">{{ data.review_count.toLocaleString() }} unresolved allocations are excluded from totals and charts. <router-link to="/payments">Review in Payments</router-link></p>
    <p v-if="usable && data.fee_split_count" class="pay-notice">{{ data.fee_split_count.toLocaleString() }} own-venue allocations need a fee split. Confirmed receipts count once in Bookboll received; their fee and venue breakdowns remain incomplete.</p>
    <section class="pay-summary" :class="{'pay-summary-platform':platform}" :aria-busy="loading" aria-label="Payment analytics totals"><article v-for="[key,title,description] in cards" :key="key" class="pay-stat"><h2>{{ title }}</h2><strong v-for="text in amounts(key)" :key="text">{{ text }}</strong><p>{{ description }}</p></article></section>
    <p v-if="loading" class="pa-state" role="status">Loading payment analytics…</p>
    <template v-if="usable">
      <p v-if="!summary.length" class="pa-state">No classified payment records match these filters.</p>
      <template v-else>
        <div class="pa-chart-toolbar"><div><h3>Revenue breakdown</h3><p>Amounts use one currency at a time. Received amounts are paid only.</p></div><label>Chart &amp; table currency<select v-model="chartCurrency" aria-label="Payment chart currency"><option v-for="currency in chartCurrencies" :key="currency">{{ currency }}</option></select></label></div>
        <div class="pa-grid">
          <section class="pay-panel"><div class="pa-panel-head"><div><h3>{{ trendTitle }}</h3><p>{{ analytics.grain === 'month' ? 'Monthly' : 'Daily' }} · {{ chartCurrency }}</p></div><label class="pa-metric-label">Show<select v-model="trendMetric" aria-label="Payment trend metric"><option v-for="[key,title] in metricOptions" :key="key" :value="key">{{ title }}</option></select></label></div><TrendChart :points="trendPoints" color="#0d9488" :prefix="chartCurrency + ' '" :value-formatter="v => money(v,chartCurrency)" :height="210" /><p v-if="analytics.undated_allocations" class="pa-note">{{ analytics.undated_allocations }} undated allocations are included in totals but cannot appear on the timeline.</p></section>
          <section class="pay-panel"><h3>{{ platform ? 'Bookboll received' : 'Your business received' }} by method</h3><p>Only the recipient’s share; fees and venue revenue are not counted twice.</p><HBarChart :rows="methodBars" color="#4c8dff" :value-formatter="v => money(v,chartCurrency)" /></section>
        </div>
        <section class="pay-panel pa-table-panel"><h3>Where bookings came from</h3><p>Staff-added bookings have no platform fee. Event host fees remain separate charges.</p><div class="pay-table-wrap"><table class="pay-table"><thead><tr><th>Channel</th><th>Total sales</th><th>{{ platform ? 'Bookboll received' : 'Your business received' }}</th><th>{{ platform ? 'Platform fees' : 'Bookboll fees' }}</th><th>{{ platform ? 'External businesses received' : 'Still to collect' }}</th></tr></thead><tbody><tr v-for="row in channels" :key="row.key"><td>{{ labelFor(channelOptions,row.key) }}</td><td>{{ amount(row,'total_sales') }}</td><td>{{ amount(row,receivedKey) }}</td><td>{{ amount(row,'platform_fees') }}</td><td>{{ amount(row,platform ? 'provider_received' : 'provider_pending') }}</td></tr></tbody></table></div></section>
        <div class="pay-breakdown">
          <details class="pay-panel pay-collapsible"><summary class="pay-panel-heading"><h2>{{ platform ? 'Where Bookboll’s money came from' : 'Where your money came from' }}</h2><span>Paid only <ChevronRight :size="16" /></span></summary><dl><div v-for="[key,title] in breakdown" :key="key"><dt>{{ title }}</dt><dd><span v-for="text in amounts(key)" :key="text">{{ text }}</span></dd></div></dl><p v-if="platform">Own-venue revenue is after the platform fee, before rent, landlord revenue share and other operating costs.</p></details>
          <details v-if="platform" class="pay-panel pay-collapsible"><summary class="pay-panel-heading"><h2>External businesses</h2><ChevronRight :size="16" /></summary><ExternalBusinessTotals :amounts="amounts" /></details>
          <section v-else class="pay-panel"><h3>Sales &amp; staff bookings</h3><dl><div><dt>Total sales · paid + pending</dt><dd><span v-for="text in amounts('total_sales')" :key="text">{{ text }}</span></dd></div><div><dt>Collected from staff-added bookings<small>Included in your business received · no Bookboll fee</small></dt><dd><span v-for="text in amounts('provider_manual')" :key="text">{{ text }}</span></dd></div></dl></section>
        </div>
        <section class="pay-panel pa-table-panel"><h3>Venue performance</h3><p>{{ chartCurrency }} · Sorted by total sales. Pending external-business payments are managed by each business.</p><div class="pay-table-wrap"><table class="pay-table"><thead><tr><th>Venue</th><th>Total sales</th><th>{{ platform ? 'Bookboll received' : 'Your business received' }}</th><th>{{ platform ? 'Platform fees' : 'Bookboll fees' }}</th><th v-if="platform">Own venue revenue</th><th>{{ platform ? 'Own bookings to collect' : 'Still to collect' }}</th><th v-if="platform">External businesses received</th></tr></thead><tbody><tr v-for="row in venues" :key="row.key"><td>{{ row.label }}</td><td>{{ amount(row,'total_sales') }}</td><td>{{ amount(row,receivedKey) }}</td><td>{{ amount(row,'platform_fees') }}</td><td v-if="platform">{{ amount(row,'owned_venue_received') }}</td><td>{{ amount(row,pendingKey) }}</td><td v-if="platform">{{ amount(row,'provider_received') }}</td></tr></tbody></table></div></section>
      </template>
    </template>
  </div>
</template>

<style scoped>
.payment-analytics .pay-stat h2{font-size:12px}.payment-analytics .pay-panel-heading h2{font-size:14px}
.payment-analytics{display:grid;gap:20px}.pa-heading,.pa-chart-toolbar,.pa-panel-head{display:flex;justify-content:space-between;gap:20px;align-items:center}.payment-analytics h2{font-size:18px;margin:0}.payment-analytics h3{font-size:15px;margin:0 0 6px}.payment-analytics p{color:var(--muted);font-size:12px;line-height:1.6;margin:6px 0 14px}.pa-heading .pay-actions{flex-shrink:0}.payment-analytics .pay-period,.payment-analytics .pay-summary,.payment-analytics .pay-breakdown,.payment-analytics .pay-notice{margin:0}.pa-chart-toolbar label,.pa-metric-label{display:grid;gap:5px;font-size:11px;font-weight:600}.pa-chart-toolbar select,.pa-metric-label select{padding:8px;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:7px;font:inherit;max-width:240px}.pa-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);gap:18px}.pa-grid .pay-panel{min-width:0}.pa-filters{border:1px solid var(--border);border-radius:10px;background:var(--surface)}.pa-state{padding:24px;text-align:center}.pa-table-panel .pay-table{min-width:680px}.pa-table-panel td:not(:first-child){white-space:nowrap;font-variant-numeric:tabular-nums}.pa-table-panel .pay-table td:nth-child(4){min-width:130px}small{display:block;color:var(--muted);margin-top:5px;font-size:11px}.pa-note{font-size:11px!important}@media(max-width:1000px){.pa-grid{grid-template-columns:1fr}.pa-heading{align-items:flex-start}}@media(max-width:600px){.pa-heading,.pa-chart-toolbar,.pa-panel-head{flex-direction:column;align-items:stretch}.pa-heading .pay-actions{flex-wrap:wrap}.pa-metric-label select{max-width:100%}}
</style>
