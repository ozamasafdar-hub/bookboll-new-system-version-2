<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { selectedCity } from '../../lib/customerSession.js'
import { loadPrograms } from '../../customer/data.js'
import { audienceMatches } from '../../lib/membershipCategories.js'
import ProgramCard from '../components/ProgramCard.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { Search, Dumbbell, SlidersHorizontal } from 'lucide-vue-next'
import { useSeo, faqLd } from '../../lib/seo.js'
import { isWhiteLabel } from '../../lib/brand.js'

const all = ref([])
const loading = ref(true)
const q = ref('')
const aud = ref('all')   // audience filter: all | kids | adults | ladies | men

async function load() { loading.value = true; all.value = await loadPrograms(selectedCity.value?.id); loading.value = false }
onMounted(load)
watch(() => selectedCity.value?.id, load)

// Audience options — always offer Kids/Adults; add Women/Men only if present in the
// results (via the gym access audience OR a class/academy gender policy).
const AUD_LABELS = { all: 'All', kids: 'Kids', adults: 'Adults', ladies: 'Women only', men: 'Men only' }
const audChips = computed(() => {
  const keys = ['all', 'kids', 'adults']
  if (all.value.some(p => p.audience === 'ladies' || p.gender === 'women')) keys.push('ladies')
  if (all.value.some(p => p.audience === 'men' || p.gender === 'men')) keys.push('men')
  return keys.map(k => ({ key: k, label: AUD_LABELS[k] }))
})

// ── Filters (dashboard-style dropdown) — options come from what is ACTUALLY
// offered in the selected city, never the full static taxonomy.
const showFilters = ref(false)
const fCat = ref('all')
const fSub = ref('all')
const CAT_LABELS = { gym: 'Gym', class: 'Classes', academy: 'Academy' }
const availCategories = computed(() =>
  [...new Set(all.value.map(p => p.category).filter(Boolean))]
    .map(k => ({ key: k, label: CAT_LABELS[k] || (k.charAt(0).toUpperCase() + k.slice(1)) })))
const availTypes = computed(() => {
  const pool = fCat.value === 'all' ? all.value : all.value.filter(p => p.category === fCat.value)
  return [...new Set(pool.map(p => p.sport).filter(Boolean))].sort()
})
watch(fCat, () => { fSub.value = 'all' })
watch(availCategories, (cats) => { if (fCat.value !== 'all' && !cats.some(c => c.key === fCat.value)) fCat.value = 'all' })
const activeFilters = computed(() => (fCat.value !== 'all' ? 1 : 0) + (fSub.value !== 'all' ? 1 : 0) + (aud.value !== 'all' ? 1 : 0))
function clearFilters() { fCat.value = 'all'; fSub.value = 'all'; aud.value = 'all' }
// Tapping anywhere outside the search+filter cluster closes the floating panel.
function onDocClick(e) { if (showFilters.value && !e.target.closest('.fac-search-wrap')) showFilters.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

// Membership FAQ — apex marketplace only, mirrored as FAQPage JSON-LD below.
// Contact-listing guidance matches the public detail page.
const faqs = computed(() => {
  if (isWhiteLabel.value) return []
  const city = selectedCity.value?.name
  return [
    { q: 'How do I join an academy or membership?', a: 'Choose a listing and call the academy using its contact number. The provider will explain availability, enrolment and payment.' },
    { q: 'Does Bookboll collect my membership payment?', a: 'No. You arrange and pay for your academy membership directly with the provider. Bookboll helps you discover the listing.' },
    { q: 'Can I try a class first?', a: 'Ask the academy about trial sessions, schedules and prices. Each provider sets its own offers and policies.' },
    { q: 'Who handles changes, cancellations and attendance?', a: 'Contact the academy directly. It manages your membership, attendance and any changes or refunds.' },
    { q: `How do I find programmes${city ? ' in ' + city : ''} near me?`, a: 'Choose your city, then filter by sport, category and audience. Check the location and call the academy for details.' },
  ]
})

useSeo(() => {
  const city = selectedCity.value?.name
  return {
    title: city ? `Classes & memberships in ${city}` : 'Classes, academies & memberships',
    description: `Find sports classes, academies and gym memberships${city ? ' in ' + city : ''} and contact the academy directly through Bookboll.`,
    path: '/programs',
    jsonLd: [faqLd(faqs.value)].filter(Boolean),
  }
})

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  return all.value.filter(p => {
    if (!audienceMatches(aud.value, p.audience, p.gender)) return false
    if (fCat.value !== 'all' && p.category !== fCat.value) return false
    if (fSub.value !== 'all' && p.sport !== fSub.value) return false
    if (s && !(p.name.toLowerCase().includes(s) || (p.sport || '').toLowerCase().includes(s))) return false
    return true
  })
})
</script>

<template>
  <div class="discovery">
    <header class="discover-head"><div><h1><Dumbbell :size="29" /> Memberships{{ selectedCity?.name ? ' in ' + selectedCity.name : '' }}</h1><p>Build a routine you enjoy. Explore gym access, guided classes and academy programs.</p></div></header>
    <div class="discover-tools"><label class="discover-search"><Search :size="21" /><input v-model="q" type="search" aria-label="Search memberships" placeholder="Search by membership, activity or sport" /></label>
      <div v-if="availCategories.length" class="discover-filters" aria-label="Membership category"><button class="discover-filter" :class="{on:fCat==='all'}" :aria-pressed="fCat==='all'" @click="fCat='all'">All memberships</button><button v-for="category in availCategories" :key="category.key" class="discover-filter" :class="{on:fCat===category.key}" :aria-pressed="fCat===category.key" @click="fCat=category.key">{{ category.label }}</button></div>
      <div v-if="all.length" class="membership-filters"><label>Who’s it for?<select v-model="aud"><option v-for="option in audChips" :key="option.key" :value="option.key">{{ option.label }}</option></select></label><label v-if="availTypes.length">Activity<select v-model="fSub"><option value="all">All activities</option><option v-for="sport in availTypes" :key="sport">{{ sport }}</option></select></label><button v-if="activeFilters || q" class="discover-text-button" @click="q='';clearFilters()">Reset filters</button></div>
    </div>
    <div v-if="loading" class="discover-loading" role="status">Finding memberships…</div>
    <template v-else><div v-if="filtered.length" class="discover-result-head"><h2>{{ filtered.length }} memberships to explore</h2><p>Choose a plan that fits your routine</p></div><div v-if="filtered.length" class="cgrid"><ProgramCard v-for="p in filtered" :key="p.id" :program="{ ...p, cityName: selectedCity?.name }" /></div>
      <div v-else class="discover-empty"><Dumbbell :size="40" /><h2>{{ q || activeFilters ? 'No memberships match your search' : 'Memberships are on their way' }}</h2><p>{{ q || activeFilters ? 'Try another activity or clear the filters to explore all memberships.' : 'Gym, class and academy contact listings in ' + (selectedCity?.name || 'your city') + ' will appear here as businesses add them.' }}</p><button v-if="q || activeFilters" class="discover-action" @click="q='';clearFilters()">Clear search &amp; filters</button></div>
    </template>
    <!-- Provider recruitment — straight after the listings, BEFORE the SEO sections -->
    <RecruitBanner :city-name="selectedCity?.name" category="membership" />

    <!-- Membership FAQ (apex only — mirrors the FAQPage JSON-LD) -->
    <section v-if="faqs.length" class="prog-faq">
      <h2>Frequently asked questions</h2>
      <details v-for="(f, i) in faqs" :key="i" class="prog-faq-item">
        <summary>{{ f.q }}</summary>
        <p>{{ f.a }}</p>
      </details>
    </section>
  </div>
</template>

<style scoped>
.ph h1 { font-size: 25px; font-weight: 900; color: var(--text, #0f172a); margin: 0; display: flex; align-items: center; gap: 10px; }
.ph h1 svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.ph p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 6px 0 18px; }
.csearch { display: flex; align-items: center; gap: 10px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; color: var(--text-light, #94a3b8); max-width: 480px; }
.csearch input { flex: 1; border: none; outline: none; font-size: 15px; color: var(--text, #0f172a); }
/* One clean field (dashboard style): search input with the filter icon INSIDE it
   (mirrors Facilities.vue's fac-* styles) */
.fac-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.fac-search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 480px; }
.fac-bar .csearch { margin-bottom: 0; max-width: none; width: 100%; padding: 6px 6px 6px 13px; border-radius: 10px; }
.fac-ficon { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 7px 8px; border-radius: 8px; }
.fac-ficon:hover { color: var(--brand-ink, var(--brand)); background: var(--bg, #f1f5f9); }
.fac-ficon.on { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); }
.fac-filter-n { background: var(--brand); color: #fff; font-size: 10.5px; font-weight: 800; min-width: 16px; height: 16px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
.fac-panel { position: absolute; top: calc(100% + 6px); inset-inline-start: 0; z-index: 50; width: min(480px, calc(100vw - 32px)); background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; box-shadow: 0 12px 32px rgba(0, 0, 0, .14); display: flex; flex-direction: column; gap: 12px; }
.fac-frow { display: flex; align-items: flex-start; gap: 12px; }
.fac-flabel { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); width: 70px; flex-shrink: 0; padding-top: 7px; }
.fac-chips { display: flex; flex-wrap: wrap; gap: 7px; flex: 1; min-width: 0; }
.fac-chips button { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #475569); font-weight: 700; font-size: 13px; padding: 6px 13px; border-radius: 999px; cursor: pointer; }
.fac-chips button:hover { border-color: var(--brand-accent); }
.fac-chips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.fac-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid var(--border, #eef2f6); }
.fac-clear { background: none; border: none; color: var(--danger-ink, #dc2626); font-weight: 700; font-size: 13px; cursor: pointer; padding: 2px; }
.fac-clear:disabled { color: #cbd5e1; cursor: default; }
.fac-clear:not(:disabled):hover { text-decoration: underline; }
.fac-done { background: var(--brand); color: #fff; border: none; border-radius: 999px; font-weight: 800; font-size: 13px; padding: 8px 18px; cursor: pointer; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.cgrid :deep(.pc) { width: auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 60px; color: var(--text-light, #94a3b8); }
/* FAQ — same look as the city landing pages' FAQ */
.prog-faq { margin: 44px 0 16px; max-width: 760px; }
.prog-faq h2 { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 12px; }
/* Quiet divider-list FAQ (same treatment as the homepage) */
.prog-faq-item { border-bottom: 1px solid var(--border, #e8edf3); }
.prog-faq-item:last-child { border-bottom: none; }
.prog-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 14px; list-style: none; padding: 13px 2px; font-size: 14px; font-weight: 600; color: var(--text-secondary, #334155); cursor: pointer; }
.prog-faq-item summary::-webkit-details-marker { display: none; }
.prog-faq-item summary::after { content: '+'; font-size: 17px; font-weight: 400; color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.prog-faq-item[open] summary { color: var(--text, #0f172a); }
.prog-faq-item[open] summary::after { transform: rotate(45deg); color: var(--brand-ink, var(--brand)); }
.prog-faq-item summary:hover { color: var(--brand-ink, var(--brand)); }
.prog-faq-item p { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.65; margin: 0; padding: 0 24px 14px 2px; }

/* ── Phone: match storefront mobile conventions — get browsing above the fold ── */
@media (max-width: 760px) {
  /* Tighter header chrome so the first cards sit higher.
     Subtitle restates the h1 ("Memberships in {city}") — hide it and move its
     12px rhythm onto the h1 so the search box keeps the same breathing room. */
  .ph h1 { font-size: 21px; margin-bottom: 12px; }
  .ph h1 svg { width: 20px; height: 20px; } /* icon tracks the smaller h1 cap height */
  .ph p { display: none; }

  /* Search: 16px input stops iOS zoom-on-focus; snugger box */
  .csearch { padding: 10px 14px; margin-bottom: 12px; }
  .csearch input { font-size: 16px; }

  /* Filter panel becomes a floating sheet above the bottom nav; pills grow to
     comfortable tap sizes; 16px input stops iOS zoom-on-focus */
  .fac-bar { margin-bottom: 12px; }
  .fac-panel { position: fixed; top: auto; inset-inline: 12px; bottom: 96px; width: auto; z-index: 120; }
  .fac-chips button { padding: 9px 14px; font-size: 13.5px; }

  /* One full-width card per row — same browse rhythm as the venues page.
     minmax(0,1fr) keeps wide card content from overflowing the track;
     ProgramCard's own ≤760px rules give the wide tile its 16/9 banner crop. */
  .cgrid { grid-template-columns: minmax(0, 1fr); gap: 12px; }

  /* Trim wasted padding on empty/loading states */
  .cmsg { padding: 36px 16px; }
  .cempty { padding: 40px 16px; }

  /* Light FAQ tightening (secondary content below the grid) */
  .prog-faq { margin: 32px 0 8px; }
  .prog-faq h2 { font-size: 17px; } /* section heading — one clear step below the 21px page h1 */
}
.membership-filters { display: flex; flex-wrap: wrap; gap: 14px 24px; margin-top: 18px; }.membership-filters label { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-secondary); }.membership-filters select { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; color: var(--text); font: inherit; }.cgrid { margin-bottom: 30px; grid-template-columns: repeat(auto-fill,minmax(270px,1fr)); }.prog-faq { max-width: 850px; margin-top: 40px; }
</style>
