<script setup>
import { externalBusinessMetrics } from '../lib/paymentMetrics.js'
defineProps({ amounts: { type: Function, required: true } })
</script>

<template>
  <h3>External businesses received</h3>
  <strong v-for="amount in amounts('provider_received')" :key="amount">{{ amount }}</strong>
  <p>Paid revenue belonging to external businesses, including bookings added by their staff. Bookboll fees are excluded.</p>
  <dl><div v-for="[key,title,description] in externalBusinessMetrics" :key="key"><dt>{{ title }}<small>{{ description }}</small></dt><dd><span v-for="amount in amounts(key)" :key="amount">{{ amount }}</span></dd></div></dl>
</template>

<style scoped>
strong{display:block;font-size:23px;margin:8px 0;font-variant-numeric:tabular-nums}small{display:block;color:var(--muted);font-size:11px;line-height:1.5;margin-top:5px;font-weight:400}
</style>
