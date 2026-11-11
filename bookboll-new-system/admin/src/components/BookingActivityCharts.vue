<script setup>
import { computed } from 'vue'
import TrendChart from './viz/TrendChart.vue'
import HBarChart from './viz/HBarChart.vue'
import { activityChannelLabel, statusLabel } from '../lib/bookingActivity.js'
import { money } from '../lib/paymentReport.js'
const props=defineProps({data:Object,locations:Array,dateBasis:String})
const emit=defineEmits(['venue','records'])
const channels=computed(()=>props.data.channels.map(r=>({...r,label:activityChannelLabel(r.label)})))
const statuses=computed(()=>props.data.statuses.map(r=>({...r,label:r.label === 'confirmed' ? 'Confirmed' : statusLabel(r.label)})))
const venueName=id=>props.locations.find(v=>String(v.id)===String(id))?.name || 'Venue not recorded'
</script>
<template>
  <div class="bk-charts">
    <p class="bk-scope">All {{ data.matched.toLocaleString() }} matching records are included. {{ data.slot_blocks.toLocaleString() }} slot blocks and {{ data.cancelled.toLocaleString() }} cancellations stay visible in activity, and are excluded from customer booking totals.</p>
    <div class="bk-chart-grid">
      <section class="card"><h2>Customer bookings over time</h2><p>{{ data.grain === 'month' ? 'Monthly' : 'Daily' }} · {{ dateBasis === 'created' ? 'Date booked' : 'Reservation date' }}</p><TrendChart :points="data.series" color="#0d9488" :height="210" /><small v-if="data.undated">{{ data.undated }} undated bookings are included in totals only.</small></section>
      <section class="card"><h2>Where bookings came from</h2><p>Every matching record · original creation channel</p><HBarChart :rows="channels" color="#4c8dff" /></section>
      <section class="card"><h2>Customers coming back</h2><p>Bookings per customer in the selected period</p><HBarChart :rows="data.frequency" color="#0d9488" /><small>{{ data.identified_bookings.toLocaleString() }} bookings ÷ {{ data.customers.toLocaleString() }} identified customers. {{ data.walk_in_bookings.toLocaleString() }} walk-in / unidentified bookings are excluded from this ratio.</small></section>
      <section class="card"><h2>Booking status</h2><p>Every matching record · includes slot blocks</p><HBarChart :rows="statuses" color="#e5aa42" /></section>
      <section class="card"><h2>Popular booking times</h2><p>Customer bookings · local reservation start time</p><TrendChart :points="data.hours" mode="bars" color="#4c8dff" :height="190" /></section>
      <section class="card"><h2>Booking value</h2><p>Saved booking price, once per booking. This is not money received.</p><div v-if="data.sales.length" class="bk-sales"><strong v-for="sale in data.sales" :key="sale.currency">{{ money(sale.amount,sale.currency) }}</strong></div><p v-else>No customer bookings in this view.</p><router-link to="/dashboard?tab=payments">View receipts and allocations in payment analytics</router-link></section>
    </div>
    <section class="card bk-venue-table"><div class="bk-section-head"><div><h2>Venue activity</h2><p>Customer bookings · select a venue to narrow the report</p></div><button class="btn btn-secondary" @click="emit('records')">View matching bookings</button></div><div class="table-wrap"><table><thead><tr><th>Venue</th><th>Customer bookings</th><th>Booking customers</th></tr></thead><tbody><tr v-for="venue in data.venues" :key="venue.id"><td><button class="bk-text-button" @click="emit('venue',venue.id)">{{ venueName(venue.id) }}</button></td><td>{{ venue.bookings.toLocaleString() }}</td><td>{{ venue.customers.toLocaleString() }}</td></tr><tr v-if="!data.venues.length"><td colspan="3">No customer bookings match these filters.</td></tr></tbody></table></div></section>
  </div>
</template>
<style scoped>
.bk-chart-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:18px}.bk-charts .card{padding:22px;min-width:0}.bk-charts h2{font-size:16px;margin:0 0 6px}.bk-charts p,.bk-charts small{font-size:12px;color:var(--muted);line-height:1.6}.bk-charts p{margin:0 0 18px}.bk-charts small{display:block;margin-top:14px}.bk-scope{padding:0 2px}.bk-sales{display:flex;flex-direction:column;gap:14px;margin:26px 0}.bk-sales strong{font-size:27px}.bk-charts a,.bk-text-button{font-size:12px;color:var(--primary);background:none;border:0;cursor:pointer;text-align:left}.bk-venue-table{margin-top:18px}.bk-section-head{display:flex;justify-content:space-between;gap:16px;align-items:start}.bk-venue-table td:not(:first-child){font-variant-numeric:tabular-nums}@media(max-width:900px){.bk-chart-grid{grid-template-columns:1fr}.bk-section-head{flex-direction:column}}
</style>
