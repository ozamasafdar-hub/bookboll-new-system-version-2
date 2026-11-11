<script setup>
import { computed } from 'vue'
import { activityCount } from '../lib/bookingActivity.js'
const props=defineProps({data:Object,busy:Boolean})
const s=computed(()=>props.busy ? null : props.data)
</script>
<template>
  <section class="bk-summary" aria-label="Booking activity totals" :aria-busy="busy">
    <article><span>Matching records</span><strong>{{ activityCount(s?.matched) }}</strong><small>All statuses · includes slot blocks</small></article>
    <article><span>Customer bookings</span><strong>{{ activityCount(s?.active) }}</strong><small>Pending, confirmed &amp; completed · excludes refunds &amp; blocks</small></article>
    <article><span>Booking customers</span><strong>{{ activityCount(s?.customers) }}</strong><small>Distinct customers in these bookings</small></article>
    <article><span>Bookings per customer</span><strong>{{ s?.per_customer == null ? '—' : `${s.per_customer.toFixed(2)}×` }}</strong><small>{{ s?.repeat_pct == null ? 'No identified booking customers' : `${s.repeat_pct}% booked more than once in this period` }}</small></article>
  </section>
</template>
<style scoped>
.bk-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid var(--border);border-radius:12px;background:var(--surface);overflow:hidden;margin:20px 0}.bk-summary article{padding:22px;border-right:1px solid var(--border);display:flex;flex-direction:column;gap:14px}.bk-summary article:last-child{border:0}.bk-summary span,.bk-summary small{color:var(--muted);font-size:12px;line-height:1.5}.bk-summary strong{font-size:28px;letter-spacing:-.6px;font-variant-numeric:tabular-nums}.bk-summary small{font-size:11px;max-width:230px}@media(max-width:900px){.bk-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.bk-summary article{border-bottom:1px solid var(--border)}}@media(max-width:500px){.bk-summary article{padding:15px}.bk-summary strong{font-size:24px}}
</style>
