<script setup>
/**
 * CityPrograms.vue — membership local-SEO landing page for a PLACE (city OR country).
 *   /memberships/:place            → all memberships (gyms, classes, academies) in a place
 *   /memberships/:place/:segment   → segment is a membership category (gym/class/academy) OR a
 *                                    program type (yoga/football/…); the page detects which.
 * Keyword H1 + free-trial/offer framing + chips + ProgramCard grid + FAQ + breadcrumb + JSON-LD.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadPlacePrograms, priceRange, MEMBERSHIP_HEADING, MEMBERSHIP_NOUN } from '../../lib/programPages.js'
import { audienceMatches } from '../../lib/membershipCategories.js'
import { useSeo, itemListLd, breadcrumbLd, faqLd } from '../../lib/seo.js'
import { isWhiteLabel } from '../../lib/brand.js'
import ProgramCard from '../components/ProgramCard.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { ChevronRight, Dumbbell, Building2, Ticket } from 'lucide-vue-next'

const route = useRoute()
const data = ref({ place: null, mode: 'hub', segment: null, category: null, typeName: null, programs: [], categories: [], types: [], cities: [] })
const loading = ref(true)

async function load() {
  loading.value = true
  data.value = await loadPlacePrograms(route.params.place, route.params.segment || null)
  loading.value = false
}
onMounted(load)
watch(() => [route.params.place, route.params.segment], load)

const place = computed(() => data.value.place)
const isCountry = computed(() => place.value?.type === 'country')
const mode = computed(() => data.value.mode)
const programs = computed(() => data.value.programs)
const range = computed(() => place.value ? priceRange(programs.value, place.value.currencyCode) : null)
const cards = computed(() => programs.value.map(p => ({ ...p, cityName: place.value?.name })))
const placePath = computed(() => place.value ? `/memberships/${place.value.slug}` : '')

// Audience filter (kids / adults, + ladies/men if present in the results).
const aud = ref('all')
const AUD_LABELS = { all: 'All ages', kids: 'Kids', adults: 'Adults', ladies: 'Women only', men: 'Men only' }
const audChips = computed(() => {
  const keys = ['all', 'kids', 'adults']
  if (cards.value.some(p => p.audience === 'ladies' || p.gender === 'women')) keys.push('ladies')
  if (cards.value.some(p => p.audience === 'men' || p.gender === 'men')) keys.push('men')
  return keys.map(k => ({ key: k, label: AUD_LABELS[k] }))
})
const visibleCards = computed(() => cards.value.filter(p => audienceMatches(aud.value, p.audience, p.gender)))
watch(() => [route.params.place, route.params.segment], () => { aud.value = 'all' })

const subjectTitle = computed(() => {
  if (mode.value === 'type') return data.value.typeName
  if (mode.value === 'category') return data.value.category?.label
  return ''
})
const subjectNoun = computed(() => {
  if (mode.value === 'type') return `${(data.value.typeName || '').toLowerCase()} programs`
  if (mode.value === 'category') return MEMBERSHIP_NOUN[data.value.category?.key] || 'memberships'
  return 'memberships'
})
const heading = computed(() => {
  if (!place.value) return 'Not found'
  if (mode.value === 'type') return `${data.value.typeName} in ${place.value.name}`
  if (mode.value === 'category') return `${MEMBERSHIP_HEADING[data.value.category.key] || data.value.category.label} in ${place.value.name}`
  return `Memberships in ${place.value.name}`
})
const intro = computed(() => {
  if (!place.value) return ''
  const n = programs.value.length
  const price = range.value ? ` from ${range.value.currency} ${range.value.from.toLocaleString()}/month` : ''
  if (!n) return `We don't have any ${subjectNoun.value} listed in ${place.value.name} just yet — check back soon.`
  return `Browse ${n} ${subjectNoun.value} in ${place.value.name}${price} — contact providers directly for availability and enrolment. Compare listings and contact the provider.`
})

// Keyword FAQ — apex marketplace only (white-label sites must not carry Bookboll
// marketplace answers); mirrored as FAQPage JSON-LD. Money-accurate: intro offers vary
// per program, cancelling stops renewals, refunds for paid time follow the Terms.
const faqs = computed(() => {
  if (isWhiteLabel.value || !place.value) return []
  const w = subjectNoun.value, c = place.value.name
  const price = range.value ? `${range.value.currency} ${range.value.from.toLocaleString()}/month` : 'competitive rates'
  return [
    { q: `How do I join ${w} in ${c}?`, a: `Open a listing and call the academy using its contact number. The provider handles availability, enrolment and payments directly.` },
    { q: `How much do ${w} cost in ${c}?`, a: `Advertised prices vary by academy. Confirm the final price and schedule with the provider before enrolling.` },
    { q: `Does Bookboll manage my academy membership?`, a: `No. Bookboll helps you discover academies. Membership payments, renewals, cancellations and attendance are handled directly with the academy.` },
  ]
})

const crumbs = computed(() => {
  if (!place.value) return []
  const out = [{ name: 'Home', path: '/' }, { name: 'Memberships', path: '/memberships' }]
  if (place.value.type === 'city' && place.value.countrySlug && place.value.countryName)
    out.push({ name: place.value.countryName, path: `/memberships/${place.value.countrySlug}` })
  out.push({ name: place.value.name, path: placePath.value })
  if (subjectTitle.value) out.push({ name: subjectTitle.value, path: `${placePath.value}/${data.value.segment}` })
  return out
})

useSeo(() => {
  if (!place.value) return { path: route.path, noindex: true, title: 'Memberships' }
  const noindex = programs.value.length === 0
  const title = mode.value === 'category' ? `${heading.value} — contact an academy` : heading.value
  const ld = [
    { '@context': 'https://schema.org', '@type': 'CollectionPage', name: heading.value, description: intro.value },
    breadcrumbLd(crumbs.value),
    itemListLd(programs.value.map(p => ({ name: p.name, path: `/programs/${p.id}` })), heading.value),
    faqLd(faqs.value),
  ].filter(Boolean)
  return { title, description: intro.value, path: route.path, type: 'website', noindex, jsonLd: ld }
})
</script>

<template>
  <div class="cv">
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!place" class="cv-empty"><Dumbbell :size="34" /><p>We couldn't find that location.</p><router-link to="/memberships" class="cv-link">Browse all memberships</router-link></div>

    <template v-else>
      <nav class="cv-crumbs">
        <template v-for="(cr, i) in crumbs" :key="cr.path">
          <ChevronRight v-if="i" :size="13" />
          <router-link v-if="i < crumbs.length - 1" :to="cr.path">{{ cr.name }}</router-link>
          <span v-else>{{ cr.name }}</span>
        </template>
      </nav>

      <h1 class="cv-h1">{{ heading }}</h1>
      <p class="cv-intro">{{ intro }}</p>

      <!-- City chips (country places) -->
      <div v-if="isCountry && data.cities.length" class="cv-chips">
        <router-link v-for="c in data.cities" :key="c.slug" :to="`/memberships/${c.slug}`" class="cv-chip cv-chip-cat">
          {{ c.name }} <span class="cv-chip-n">{{ c.count }}</span>
        </router-link>
      </div>

      <!-- Membership category chips -->
      <div v-if="data.categories.length" class="cv-chips">
        <router-link :to="placePath" class="cv-chip" :class="{ on: mode === 'hub' }">All</router-link>
        <router-link v-for="c in data.categories" :key="c.slug" :to="`${placePath}/${c.slug}`" class="cv-chip cv-chip-cat" :class="{ on: data.segment === c.slug }">
          {{ c.label }} <span class="cv-chip-n">{{ c.count }}</span>
        </router-link>
      </div>

      <!-- Type chips -->
      <div v-if="data.types.length" class="cv-chips">
        <router-link v-for="t in data.types" :key="t.slug" :to="`${placePath}/${t.slug}`" class="cv-chip" :class="{ on: data.segment === t.slug }">
          {{ t.name }} <span class="cv-chip-n">{{ t.count }}</span>
        </router-link>
      </div>

      <!-- Audience filter (kids / adults, + ladies/men if present) -->
      <div v-if="cards.length && audChips.length > 1" class="cv-chips cv-aud-chips">
        <button v-for="c in audChips" :key="c.key" type="button" class="cv-chip" :class="{ on: aud === c.key }" @click="aud = c.key">{{ c.label }}</button>
      </div>

      <!-- Program grid -->
      <div v-if="visibleCards.length" class="cv-grid">
        <ProgramCard v-for="p in visibleCards" :key="p.id" :program="p" />
      </div>
      <div v-else-if="cards.length" class="cv-empty">
        <Dumbbell :size="34" /><p>No memberships match that filter.</p>
        <button type="button" class="cv-link cv-link-btn" @click="aud = 'all'">Clear filter</button>
      </div>
      <div v-else class="cv-empty">
        <Dumbbell :size="34" /><p>No {{ subjectNoun }} here yet.</p>
        <router-link :to="placePath" class="cv-link">See all memberships in {{ place.name }}</router-link>
      </div>

      <!-- Cross-links -->
      <div class="cv-cross">
        <router-link to="/venues" class="cv-cross-card"><Building2 :size="20" /><div><b>Venues to book</b><span>Courts, pitches & venues in {{ place.name }}</span></div><ChevronRight :size="16" /></router-link>
        <router-link to="/games" class="cv-cross-card"><Ticket :size="20" /><div><b>Games & tournaments</b><span>Join or host an event in {{ place.name }}</span></div><ChevronRight :size="16" /></router-link>
      </div>

      <RecruitBanner :city-name="place.name" category="membership" />

      <!-- FAQ (apex only — mirrors the FAQPage JSON-LD) -->
      <section v-if="faqs.length" class="cv-faq">
        <h2>Frequently asked questions</h2>
        <details v-for="(f, i) in faqs" :key="i" class="cv-faq-item">
          <summary>{{ f.q }}</summary>
          <p>{{ f.a }}</p>
        </details>
      </section>
    </template>
  </div>
</template>

<style scoped>
.cv { max-width: 1100px; margin: 0 auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cv-empty { text-align: center; color: var(--text-light, #94a3b8); padding: 44px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.cv-link { color: var(--brand-ink, var(--brand)); font-weight: 700; text-decoration: none; }
.cv-crumbs { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-light, #94a3b8); margin: 4px 0 14px; flex-wrap: wrap; }
.cv-crumbs a { color: var(--text-muted, #64748b); text-decoration: none; font-weight: 600; }
.cv-crumbs a:hover { color: var(--brand-ink, var(--brand)); }
.cv-crumbs span { color: var(--text, #0f172a); font-weight: 700; }
.cv-h1 { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); margin: 0 0 8px; }
.cv-intro { font-size: 15.5px; color: var(--text-secondary, #475569); line-height: 1.6; margin: 0 0 18px; max-width: 760px; }
.cv-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.cv-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #334155); font-weight: 700; font-size: 13.5px; text-decoration: none; }
.cv-chip:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cv-chip.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.cv-chip-cat { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border-color: var(--success-border, #99f6e4); color: var(--brand-ink, var(--brand)); }
.cv-chip-cat.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.cv-chip-n { font-size: 11px; opacity: .7; }
button.cv-chip { cursor: pointer; font-family: inherit; }
.cv-aud-chips { margin-top: -4px; }
.cv-link-btn { border: none; background: none; cursor: pointer; font-family: inherit; font-size: inherit; }
.cv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; margin-top: 6px; }
.cv-grid :deep(.pc) { width: 100%; box-sizing: border-box; }
.cv-cross { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 28px 0; }
.cv-cross-card { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px 18px; text-decoration: none; color: var(--text, #0f172a); }
.cv-cross-card:hover { border-color: var(--brand-accent); }
.cv-cross-card svg:first-child { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.cv-cross-card div { flex: 1; display: flex; flex-direction: column; }
.cv-cross-card b { font-size: 15px; font-weight: 800; }
.cv-cross-card span { font-size: 12.5px; color: var(--text-muted, #64748b); }
.cv-cross-card > svg:last-child { color: var(--text-light, #94a3b8); }
.cv-faq { margin: 8px 0 24px; max-width: 720px; }
.cv-faq h2 { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 12px; }
/* Quiet divider-list FAQ (same treatment as the homepage) */
.cv-faq-item { border-bottom: 1px solid var(--border, #e8edf3); }
.cv-faq-item:last-child { border-bottom: none; }
.cv-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 14px; list-style: none; padding: 13px 2px; font-size: 14px; font-weight: 600; color: var(--text-secondary, #334155); cursor: pointer; }
.cv-faq-item summary::-webkit-details-marker { display: none; }
.cv-faq-item summary::after { content: '+'; font-size: 17px; font-weight: 400; color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.cv-faq-item[open] summary { color: var(--text, #0f172a); }
.cv-faq-item[open] summary::after { transform: rotate(45deg); color: var(--brand-ink, var(--brand)); }
.cv-faq-item summary:hover { color: var(--brand-ink, var(--brand)); }
.cv-faq-item p { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.65; margin: 0; padding: 0 24px 14px 2px; }
@media (max-width: 640px) { .cv-cross { grid-template-columns: 1fr; } }

/* ---- Mobile polish pass 2 (calm, first-glance clarity) ---- */
@media (max-width: 760px) {
  /* Type scale */
  .cv-h1 { font-size: 21px; line-height: 1.2; margin: 0 0 6px; }
  .cv-faq h2 { font-size: 16.5px; font-weight: 800; margin: 0 0 10px; }

  /* Intro: tighter + clamped (3 lines keeps count + from-price visible) */
  .cv-intro { font-size: 14px; line-height: 1.5; margin: 0 0 12px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

  /* Breadcrumb: one calm scrollable line */
  .cv-crumbs { flex-wrap: nowrap; overflow-x: auto; white-space: nowrap; scrollbar-width: none; margin: 2px 0 10px; }
  .cv-crumbs::-webkit-scrollbar { display: none; }

  /* Filter chip rows: single horizontally-scrollable lines instead of a wall of wrapped pills */
  .cv-chips { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; margin-bottom: 8px; gap: 6px; padding-bottom: 2px; }
  .cv-chips::-webkit-scrollbar { display: none; }
  .cv-chip { flex-shrink: 0; padding: 9px 13px; font-size: 13px; min-height: 38px; }

  /* Comfortable tap target for the empty-state "Clear filter" text button */
  .cv-link-btn { padding: 10px 14px; min-height: 44px; }

  /* Tighter vertical rhythm */
  .cv-cross { margin: 18px 0; gap: 10px; }
  .cv-cross-card { padding: 12px 14px; gap: 12px; }
  .cv-empty { padding: 32px 16px; }
}
</style>
