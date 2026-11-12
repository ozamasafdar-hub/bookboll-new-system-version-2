<script setup>
import { computed } from 'vue'
import { MapPin, Dumbbell, ChevronRight, Users } from 'lucide-vue-next'
import { userCoords } from '../../lib/customerSession.js'
import { haversineKm, formatDistance } from '../../lib/distance.js'
import { MEMBER_COUNT_MIN } from '../../customer/data.js'
import { audienceLabel, categoryIsGym, effectiveGenderPolicy, genderPolicyLabel } from '../../lib/membershipCategories.js'
const props = defineProps({ program: { type: Object, required: true } })
// Social proof: only show the "N members" pill once a program has real traction, so a
// brand-new program never advertises "1 member". Below the threshold → hidden.
const memberCount = computed(() => Number(props.program.memberCount) || 0)
const showMembers = computed(() => false)
const img = computed(() => props.program.image || '')
const color = computed(() => props.program.color || '#7c3aed')
const isGym = computed(() => categoryIsGym(props.program.category))
// Gender policy tag (mixed/men/women). Gyms always show it — "Mixed" included, since
// that's the tag gym browsers look for; class/academy only when men-/women-only (less
// clutter — their age chip already occupies the corner). Falls back to the gym access
// audience (ladies/men) for rows saved before the gender_policy migration.
const gender = computed(() => props.program.gender || effectiveGenderPolicy(props.program))
const genderChip = computed(() => (isGym.value || gender.value !== 'mixed') ? genderPolicyLabel(gender.value) : '')
// Hide the no-signal default "Everyone"; show Kids/Adults/Both. Gym access values
// (ladies/men) are suppressed — the gender chip now carries that meaning.
const audience = computed(() => !isGym.value && props.program.audience && props.program.audience !== 'everyone' ? audienceLabel(props.program.audience) : '')
// "X km away" — only when the visitor has shared their location AND the venue is pinned.
const distance = computed(() => {
  const u = userCoords.value, p = props.program
  if (!u || p.lat == null || p.lng == null) return ''
  return formatDistance(haversineKm(u.lat, u.lng, p.lat, p.lng))
})
</script>

<template>
  <router-link :to="`/programs/${program.id}`" class="pc">
    <div class="pc-img" :style="img ? { backgroundImage: `url(${img})` } : { background: color }">
      <div v-if="!img" class="pc-img-fallback"><Dumbbell :size="34" /></div>
      <span v-if="program.offerBadge" class="pc-trial">{{ program.offerBadge }}</span>
      <!-- Top-right chip stack: age (class/academy) + gender policy (mixed/men/women). -->
      <div class="pc-chips">
        <span v-if="audience" class="pc-aud" :class="'aud-' + program.audience">{{ audience }}</span>
        <span v-if="genderChip" class="pc-aud pc-gen" :class="'gp-' + gender">{{ genderChip }}</span>
      </div>
      <!-- Proximity pill (only when the visitor has shared their location). -->
      <span v-if="distance" class="pc-dist"><MapPin :size="11" /> {{ distance }}</span>
    </div>
    <div class="pc-body">
      <div class="pc-name">{{ program.name }}</div><small class="pc-sport">Contact the provider to join</small>
      <div class="pc-foot">
        <span v-if="Number(program.price)>0" class="pc-price">{{ program.currency }} {{ Number(program.price || 0).toLocaleString() }}<span class="pc-per">/{{ program.interval || 'month' }}</span></span>
        <span v-if="!Number(program.price)" class="pc-price">Contact for pricing</span>
        <span class="pc-foot-r"><span v-if="showMembers" class="pc-members"><Users :size="11" /> {{ memberCount.toLocaleString() }} members</span><span v-else-if="program.sport" class="pc-sport">{{ program.sport }}</span><ChevronRight :size="16" class="pc-arrow" /></span>
      </div>
    </div>
  </router-link>
</template>

<style scoped>
.pc { display: block; width: 260px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; text-decoration: none; transition: transform .12s, box-shadow .12s; box-shadow: 0 1px 2px rgba(15,23,42,.05), 0 5px 16px rgba(15,23,42,.07); }
.pc:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15,23,42,.12); }
/* width:100% pins the cover to the card edge: with an auto inline size, the mobile
   aspect-ratio + max-height pair below lets some engines transfer the height cap back
   to the width (cover renders narrower than the card = uncovered strip on the right).
   A definite width means aspect-ratio only ever derives the HEIGHT. */
.pc-img { width: 100%; height: 150px; background-size: cover; background-position: center; position: relative; }
.pc-img-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.pc-trial { position: absolute; top: 10px; left: 10px; font-size: 11px; font-weight: 800; color: var(--success-ink, #065f46); background: var(--surface, #ffffff); border-radius: 999px; padding: 4px 10px; }
.pc-chips { position: absolute; top: 10px; right: 10px; display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
.pc-aud { font-size: 10.5px; font-weight: 800; color: #fff; border-radius: 999px; padding: 4px 9px; background: rgba(15,23,42,.72); backdrop-filter: blur(4px); }
.pc-aud.aud-kids { background: rgba(217,119,6,.92); }
.pc-aud.aud-adults { background: rgba(37,99,235,.92); }
.pc-aud.aud-both { background: rgba(124,58,237,.92); }
.pc-aud.aud-ladies { background: rgba(219,39,119,.92); }
.pc-aud.aud-men { background: rgba(13,148,136,.92); }
/* Gender policy chip — men blue-ish, women rose-ish, mixed neutral scrim. */
.pc-aud.gp-men { background: rgba(37,99,235,.92); }
.pc-aud.gp-women { background: rgba(219,39,119,.92); }
.pc-aud.gp-mixed { background: rgba(15,23,42,.72); }
.pc-dist { position: absolute; right: 10px; bottom: 10px; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: #fff; background: rgba(15,23,42,.72); backdrop-filter: blur(4px); border-radius: 999px; padding: 4px 9px; }
.pc-dist svg { flex-shrink: 0; }
.pc-body { padding: 13px 14px 15px; }
.pc-name { font-size: 14.5px; font-weight: 800; color: var(--text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pc-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 10px; }
.pc-price { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.pc-per { font-size: 12px; font-weight: 600; color: var(--text-light, #94a3b8); }
.pc-sport { font-size: 12px; color: var(--text-light, #94a3b8); }
/* Social-proof pill — subtle, sits where the sport tag would (right of the price). */
.pc-members { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; color: var(--success-ink, #0f766e); background: var(--success-bg, #f0fdfa); border-radius: 999px; padding: 3px 9px; white-space: nowrap; }
.pc-members svg { flex-shrink: 0; }
.pc-foot-r { display: flex; align-items: center; gap: 7px; min-width: 0; }
.pc-arrow { color: var(--text-muted, #64748b); flex-shrink: 0; }
.pc:hover .pc-arrow { color: var(--brand-ink, var(--brand)); }

/* Phone: the browse grids (CustomerPrograms .cgrid / CityPrograms .cv-grid) are
   1-col full-width tiles — mirror FacilityCard's mobile treatment so membership
   cards read like venue cards: 16/9 banner crop capped at 160px + a slightly
   roomier body with a bumped title. Rail cards (home, 260px) stay compact since
   16/9 of 260px is ~146px, inside the same cap. Mobile-only — desktop unchanged. */
@media (max-width: 760px) {
  .pc { border-radius: 14px; }
  /* Intentional 16/9 cover: rail cards (~200-260px) render true 16/9; full-width
     tiles (~308px at 344px viewport) cap at 160px — a deliberate banner crop,
     same as FacilityCard. (.pc-img width:100% keeps the crop spanning the card.) */
  .pc-img { height: auto; aspect-ratio: 16 / 9; min-height: 96px; max-height: 160px; }
  .pc-body { padding: 11px 13px 12px; }
  .pc-name { margin: 0; font-size: 15.5px; }
  .pc-foot { margin-top: 8px; gap: 6px; }
  /* Keep overlay pills legible on the shorter image at phone DPI. */
  .pc-aud { font-size: 11px; padding: 3px 8px; }
  .pc-trial { padding: 3px 8px; }
  .pc-dist { padding: 3px 8px; }
  /* Calm chrome: neutral scrim for the AGE pills on phones (text label carries
     the meaning); the white offer pill stays the sole highlight. Desktop keeps
     the colored variants. Gender-policy chips (.gp-*) deliberately KEEP their
     blue/rose on phones — they're the "who can join" tag users scan for. */
  .pc-aud.aud-kids, .pc-aud.aud-adults, .pc-aud.aud-both, .pc-aud.aud-ladies, .pc-aud.aud-men { background: rgba(15,23,42,.72); }
}
</style>
