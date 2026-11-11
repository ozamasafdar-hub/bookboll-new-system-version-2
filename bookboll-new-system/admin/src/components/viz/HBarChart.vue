<script setup>
/**
 * HBarChart — top-N comparison as horizontal bars (one hue: magnitude, not
 * identity). Label left, 4px-rounded bar on a soft track, value at the end.
 */
import { computed } from 'vue'

const props = defineProps({
  rows: { type: Array, default: () => [] },   // [{ label, value, sub? }]
  color: { type: String, default: '#0d9488' },
  suffix: { type: String, default: '' },      // e.g. ' bookings'
  valueFormatter: { type: Function, default: null },
})
const max = computed(() => Math.max(1, ...props.rows.map(r => Number(r.value) || 0)))
</script>

<template>
  <div class="hb">
    <div v-if="!rows.length" class="hb-empty">No data for this period</div>
    <div v-for="(r, i) in rows" :key="i" class="hb-row" :title="`${r.label}: ${valueFormatter ? valueFormatter(r.value) : Number(r.value).toLocaleString() + suffix}`">
      <div class="hb-head">
        <span class="hb-lab">{{ r.label }}</span>
        <b class="hb-val">{{ valueFormatter ? valueFormatter(r.value) : Number(r.value).toLocaleString() }}<em v-if="r.sub"> · {{ r.sub }}</em></b>
      </div>
      <div class="hb-track">
        <div class="hb-bar" :style="{ width: (Number(r.value) / max * 100) + '%', background: color }"></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.hb { display: flex; flex-direction: column; gap: 10px; }
.hb-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 4px; }
.hb-lab { font-size: 12.5px; color: var(--text, #0f172a); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.hb-val { font-size: 12.5px; color: var(--text, #0f172a); font-variant-numeric: tabular-nums; flex-shrink: 0; }
.hb-val em { font-style: normal; font-weight: 500; color: var(--text-muted, #64748b); }
.hb-track { height: 10px; border-radius: 5px; background: var(--bg, #f1f5f9); overflow: hidden; }
.hb-bar { height: 100%; border-radius: 0 5px 5px 0; min-width: 2px; transition: width .25s; }
.hb-empty { padding: 34px 0; text-align: center; font-size: 13px; color: var(--text-muted, #64748b); }
</style>
