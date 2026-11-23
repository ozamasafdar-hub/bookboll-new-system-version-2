<script setup>
defineProps({ row: {type:Object,required:true} })
const columns=['facility','site_free','site_premium']
const labels={global:'Global default',country:'Country rate',city:'City rate'}
</script>
<template>
  <td v-for="key in columns" :key="key" :class="{custom:row.sources[key]!=='global'}">
    <strong>{{ row.rates[key] == null ? '—' : Number(row.rates[key]) + '%' }}</strong>
    <small>{{ labels[row.sources[key]] }}</small>
  </td>
</template>
<style scoped>
td{padding:19px 12px;vertical-align:middle;border-bottom:1px solid #dfe8e5;text-align:right;font-variant-numeric:tabular-nums}strong{display:block;font-size:21px;line-height:1.3;font-weight:700}small{display:block;font-size:11px;color:#526762;margin-top:5px}.custom strong{color:#075e54}.custom small{font-weight:650;color:#075e54}@media(max-width:600px){td{padding:17px 5px}strong{font-size:18px}small{font-size:10px}}
</style>
