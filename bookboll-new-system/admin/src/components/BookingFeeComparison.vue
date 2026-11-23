<script setup>
import { computed } from 'vue'
const props = defineProps({ data: Object, loading: Boolean, error: String, modelValue: [String, Number] })
const emit = defineEmits(['retry', 'update:modelValue'])
const selected = computed(() => props.data?.venues?.find(v => String(v.id) === String(props.modelValue)) || props.data?.venues?.[0] || props.data?.base)
const percent = key => selected.value?.rates?.[key] == null ? '—' : `${Number(selected.value.rates[key])}%`
const source = key => ({global:'Global default',country:'Country rate',city:'City rate'}[selected.value?.sources?.[key]] || '')
const venueShare = key => selected.value?.rates?.[key] == null ? '—' : (100 - Number(selected.value.rates[key])).toFixed(2)
</script>
<template>
  <section class="fee-compare" aria-label="Booking fee comparison">
    <header><h4>Booking fees</h4><span>Separate from your annual plan</span></header>
    <p v-if="loading" role="status">Loading current rates…</p>
    <div v-else-if="error" role="alert"><p>{{ error }}</p><button type="button" class="btn btn-secondary btn-sm" @click="emit('retry')">Retry rates</button></div>
    <template v-else-if="selected">
      <label v-if="data.venues?.length" class="fee-venue">Rates for your venue
        <select :value="selected.id" @change="emit('update:modelValue', $event.target.value)"><option v-for="v in data.venues" :key="v.id" :value="v.id">{{ v.name }}{{ v.location ? ' · ' + v.location : '' }}</option></select>
      </label>
      <p v-else>{{ selected.name }}. Your venue’s city can override these rates.</p>
      <table><thead><tr><th>Booking source</th><th>Free</th><th>Premium</th></tr></thead><tbody>
        <tr><th>Your own website</th><td><strong>{{ percent('site_free') }}</strong><small>{{ source('site_free') }}</small></td><td class="fee-premium"><strong>{{ percent('site_premium') }}</strong><small>{{ source('site_premium') }}</small></td></tr>
        <tr><th>Bookboll marketplace</th><td colspan="2"><strong>{{ percent('facility') }}</strong> on both plans<small>{{ source('facility') }} · listing-specific rates or fixed deposits may differ</small></td></tr>
      </tbody></table>
      <details><summary>Example: a website booking priced at 100</summary><p>Free: you keep <b>{{ venueShare('site_free') }}</b>. Premium: you keep <b>{{ venueShare('site_premium') }}</b>. The rest is Bookboll’s fee, included in the booking price. Before discounts and payment processing fees.</p></details>
      <p class="fee-help">The website rate applies to deposit and full online bookings. Premium rates require eligible Premium; staff-added bookings have no platform fee.</p>
    </template>
  </section>
</template>
<style scoped>
.fee-compare{text-align:left;border:1px solid var(--border);border-radius:12px;padding:18px;margin:18px 0;background:var(--bg);color:var(--text)}.fee-compare header{display:flex;justify-content:space-between;gap:12px;align-items:baseline}.fee-compare h4{font-size:15px;margin:0}.fee-compare header span,.fee-compare p,.fee-compare small{font-size:11px;color:var(--text-muted);line-height:1.6}.fee-venue{display:block;margin:14px 0 8px;font-size:12px;font-weight:600}.fee-venue select{display:block;width:100%;margin-top:6px;font-size:12px}.fee-compare table{width:100%;border-collapse:collapse;margin:12px 0;font-size:12px;font-variant-numeric:tabular-nums}.fee-compare th,.fee-compare td{padding:12px 6px;border-bottom:1px solid var(--border);text-transform:none;text-align:center}.fee-compare th:first-child{text-align:left;font-weight:500;max-width:130px}.fee-compare thead th{color:var(--text-muted);font-size:11px}.fee-compare strong{font-size:21px;font-weight:700}.fee-compare small{display:block;margin-top:4px}.fee-premium strong{color:var(--positive-ink)}.fee-compare summary{cursor:pointer;font-size:12px;color:var(--primary-ink);padding:4px 0}.fee-help{margin:10px 0 0}.fee-compare details p{margin-bottom:0}@media(max-width:600px){.fee-compare{padding:14px}.fee-compare header{display:block}.fee-compare header span{display:block;margin-top:4px}.fee-venue select{font-size:16px}.fee-compare th,.fee-compare td{padding:10px 4px}}
</style>
