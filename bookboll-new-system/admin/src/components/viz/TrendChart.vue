<script setup>
/**
 * TrendChart — single-series SVG chart for the analytics dashboard.
 * mode 'line': 2px line + 10% area wash + end dot. mode 'bars': columns,
 * ≤24px wide, 4px rounded data-end, square baseline, 2px gaps.
 * Hover: crosshair snapped to the nearest x with a tooltip (value leads).
 * Chrome uses theme vars so dark mode renders correctly.
 */
import { ref, computed } from 'vue'

const props = defineProps({
  points: { type: Array, default: () => [] },   // [{ label, value }]
  mode:   { type: String, default: 'line' },    // 'line' | 'bars'
  color:  { type: String, default: '#0d9488' },
  prefix: { type: String, default: '' },        // value prefix, e.g. 'AED '
  valueFormatter: { type: Function, default: null },
  height: { type: Number, default: 170 },
})

const W = 600
const PAD = { l: 46, r: 14, t: 10, b: 20 }
const innerW = W - PAD.l - PAD.r
const innerH = computed(() => props.height - PAD.t - PAD.b)

const maxV = computed(() => Math.max(1, ...props.points.map(p => Number(p.value) || 0)))
function niceCeil(v) {
  const p = Math.pow(10, Math.floor(Math.log10(v)))
  for (const m of [1, 2, 2.5, 5, 10]) if (m * p >= v) return m * p
  return 10 * p
}
const yMax = computed(() => niceCeil(maxV.value))
const yTicks = computed(() => [0, 0.5, 1].map(f => yMax.value * f))
const fmt = (v) => props.valueFormatter ? props.valueFormatter(v) : props.prefix + Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: v < 10 && v % 1 ? 1 : 0 })
// Axis ticks stay compact and unprefixed (the tooltip carries the full value) —
// a long "AED 2,000" clips against the left edge.
const tickFmt = (v) => v >= 1000 ? `${+(v / 1000).toFixed(v % 1000 ? 1 : 0)}K` : String(Math.round(v))

const n = computed(() => props.points.length)
const xAt = (i) => n.value > 1 ? PAD.l + (innerW * i) / (n.value - 1) : PAD.l + innerW / 2
const yAt = (v) => PAD.t + innerH.value * (1 - (Number(v) || 0) / yMax.value)

const linePath = computed(() =>
  props.points.map((p, i) => `${i ? 'L' : 'M'}${xAt(i).toFixed(1)},${yAt(p.value).toFixed(1)}`).join(' '))
const areaPath = computed(() => {
  if (!n.value) return ''
  const base = PAD.t + innerH.value
  return `${linePath.value} L${xAt(n.value - 1).toFixed(1)},${base} L${xAt(0).toFixed(1)},${base} Z`
})

// bars geometry
const band = computed(() => n.value ? innerW / n.value : 0)
const barW = computed(() => Math.max(3, Math.min(24, band.value - 2)))

// x labels: first / middle / last only (never a label per point)
const xLabels = computed(() => {
  if (!n.value) return []
  const idx = n.value > 2 ? [0, Math.floor((n.value - 1) / 2), n.value - 1] : n.value === 2 ? [0, 1] : [0]
  return [...new Set(idx)].map(i => ({ i, text: props.points[i].label }))
})

// hover: nearest index from pointer x
const hoverI = ref(-1)
const svgEl = ref(null)
function onMove(e) {
  if (!n.value || !svgEl.value) return
  const r = svgEl.value.getBoundingClientRect()
  const px = ((e.clientX - r.left) / r.width) * W
  if (props.mode === 'bars') {
    hoverI.value = Math.max(0, Math.min(n.value - 1, Math.floor((px - PAD.l) / band.value)))
  } else {
    hoverI.value = Math.max(0, Math.min(n.value - 1, Math.round(((px - PAD.l) / innerW) * (n.value - 1))))
  }
}
function onLeave() { hoverI.value = -1 }
const hoverX = computed(() => hoverI.value < 0 ? 0 :
  (props.mode === 'bars' ? PAD.l + band.value * hoverI.value + band.value / 2 : xAt(hoverI.value)))
const tipStyle = computed(() => ({ left: `${(hoverX.value / W) * 100}%` }))
</script>

<template>
  <div class="tc" @pointerleave="onLeave">
    <div v-if="!points.length || maxV <= 0 && points.every(p => !p.value)" class="tc-empty">No data for this period</div>
    <template v-else>
      <svg ref="svgEl" :viewBox="`0 0 ${W} ${height}`" class="tc-svg" @pointermove="onMove">
        <!-- gridlines + y ticks -->
        <g v-for="t in yTicks" :key="t">
          <line :x1="PAD.l" :x2="W - PAD.r" :y1="yAt(t)" :y2="yAt(t)" class="tc-grid" />
          <text :x="PAD.l - 8" :y="yAt(t) + 3.5" class="tc-tick" text-anchor="end">{{ tickFmt(t) }}</text>
        </g>

        <!-- marks -->
        <template v-if="mode === 'bars'">
          <rect v-for="(p, i) in points" :key="i"
            :x="PAD.l + band * i + (band - barW) / 2"
            :y="Math.min(yAt(p.value), PAD.t + innerH - 1)"
            :width="barW"
            :height="Math.max(1, PAD.t + innerH - yAt(p.value))"
            :rx="Math.min(4, barW / 2)"
            :fill="color" :opacity="hoverI === i ? 1 : 0.88" />
          <!-- square off the rounded BOTTOM corners (data-end only is rounded) -->
          <rect v-for="(p, i) in points" :key="'b' + i"
            v-show="PAD.t + innerH - yAt(p.value) > 4"
            :x="PAD.l + band * i + (band - barW) / 2"
            :y="PAD.t + innerH - 4" :width="barW" height="4"
            :fill="color" :opacity="hoverI === i ? 1 : 0.88" />
        </template>
        <template v-else>
          <path :d="areaPath" :fill="color" opacity="0.1" />
          <path :d="linePath" :stroke="color" stroke-width="2" fill="none" stroke-linejoin="round" stroke-linecap="round" />
          <circle v-if="n" :cx="xAt(n - 1)" :cy="yAt(points[n - 1].value)" r="4" :fill="color" class="tc-dot" />
        </template>

        <!-- baseline -->
        <line :x1="PAD.l" :x2="W - PAD.r" :y1="PAD.t + innerH" :y2="PAD.t + innerH" class="tc-axis" />

        <!-- x labels -->
        <text v-for="l in xLabels" :key="l.i" :x="xAt(l.i)" :y="height - 5" class="tc-tick"
          :text-anchor="l.i === 0 ? 'start' : l.i === n - 1 ? 'end' : 'middle'">{{ l.text }}</text>

        <!-- crosshair -->
        <line v-if="hoverI >= 0" :x1="hoverX" :x2="hoverX" :y1="PAD.t" :y2="PAD.t + innerH" class="tc-cross" />
        <circle v-if="hoverI >= 0 && mode === 'line'" :cx="hoverX" :cy="yAt(points[hoverI].value)" r="4.5" :fill="color" class="tc-dot" />
      </svg>
      <div v-if="hoverI >= 0" class="tc-tip" :style="tipStyle">
        <b>{{ fmt(points[hoverI].value) }}</b><span>{{ points[hoverI].label }}</span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.tc { position: relative; }
.tc-svg { display: block; width: 100%; height: auto; touch-action: none; }
.tc-grid { stroke: var(--border, #e2e8f0); stroke-width: 1; opacity: .55; }
.tc-axis { stroke: var(--border, #e2e8f0); stroke-width: 1; }
.tc-cross { stroke: var(--text-light, #94a3b8); stroke-width: 1; }
.tc-tick { font-size: 10.5px; fill: var(--text-light, #94a3b8); font-variant-numeric: tabular-nums; }
.tc-dot { stroke: var(--surface, #ffffff); stroke-width: 2; }
.tc-empty { padding: 34px 0; text-align: center; font-size: 13px; color: var(--text-muted, #64748b); }
.tc-tip {
  position: absolute; top: 0; transform: translateX(-50%);
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 8px; padding: 5px 9px; pointer-events: none; white-space: nowrap;
  box-shadow: 0 4px 14px rgba(15, 23, 42, .12); display: flex; align-items: baseline; gap: 6px;
}
.tc-tip b { font-size: 13px; color: var(--text, #0f172a); }
.tc-tip span { font-size: 11px; color: var(--text-muted, #64748b); }
</style>
