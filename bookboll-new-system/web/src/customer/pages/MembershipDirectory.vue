<script setup>
/** MembershipDirectory.vue — /memberships: directory of every country → city → gym/class/academy. */
import { ref, computed, onMounted } from 'vue'
import { loadDirectory } from '../../lib/cityPages.js'
import { useSeo, itemListLd } from '../../lib/seo.js'
import { Dumbbell } from 'lucide-vue-next'

const groups = ref([])
const loading = ref(true)
onMounted(async () => {
  const dir = await loadDirectory()
  // Only countries/cities that actually have memberships.
  groups.value = dir
    .map(g => ({ country: g.country, cities: g.cities.filter(c => c.programCount > 0) }))
    .filter(g => g.cities.length)
  loading.value = false
})

const allCities = computed(() => groups.value.flatMap(g => g.cities.map(c => ({ name: c.name, path: `/memberships/${c.slug}` }))))

useSeo(() => ({
  title: 'Gyms, classes & academies by city',
  description: 'Browse gym memberships, fitness classes and sports academies by city — contact providers directly for availability and enrolment — across every city on Bookboll.',
  path: '/memberships',
  jsonLd: [
    { '@context': 'https://schema.org', '@type': 'CollectionPage', name: 'Memberships by city' },
    itemListLd(allCities.value, 'Cities on Bookboll'),
  ].filter(Boolean),
}))
</script>

<template>
  <div class="dir">
    <header class="dir-hero">
      <h1>Gyms, classes &amp; academies by city</h1>
      <p>Find academies and contact providers directly<span class="dir-cta-hint"> — pick your city to get started.</span></p>
    </header>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!groups.length" class="cmsg">No memberships listed yet.</div>

    <div v-else class="dir-groups">
      <section v-for="g in groups" :key="g.country.slug" class="dir-country">
        <h2><router-link :to="`/memberships/${g.country.slug}`">{{ g.country.name }}</router-link></h2>
        <div class="dir-cities">
          <div v-for="c in g.cities" :key="c.slug" class="dir-city">
            <router-link :to="`/memberships/${c.slug}`" class="dir-city-name"><Dumbbell :size="14" /> {{ c.name }} <span class="dir-count">{{ c.programCount }}</span></router-link>
            <div class="dir-sports">
              <router-link v-for="m in c.memberships" :key="m.slug" :to="`/memberships/${c.slug}/${m.slug}`" class="dir-sport">{{ m.label }}<span class="dir-in"> in {{ c.name }}</span></router-link>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.dir { max-width: 1100px; margin: 0 auto; }
.dir-hero { padding: 26px 0 18px; }
.dir-hero h1 { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.dir-hero p { font-size: 15px; color: var(--text-muted, #64748b); margin: 8px 0 0; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.dir-groups { display: flex; flex-direction: column; gap: 26px; }
.dir-country h2 { font-size: 18px; font-weight: 900; color: var(--text, #0f172a); margin: 0 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--border, #eef2f6); }
.dir-country h2 a { color: inherit; text-decoration: none; }
.dir-country h2 a:hover { color: var(--brand-ink, var(--brand)); }
.dir-cities { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.dir-city { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.dir-city-name { display: flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 800; color: var(--text, #0f172a); text-decoration: none; }
.dir-city-name:hover { color: var(--brand-ink, var(--brand)); }
.dir-count { font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); background: var(--bg, #f1f5f9); padding: 1px 7px; border-radius: 999px; margin-left: auto; }
.dir-sports { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 9px; }
.dir-sport { font-size: 12.5px; color: var(--text-muted, #64748b); text-decoration: none; }
.dir-sport:hover { color: var(--brand-ink, var(--brand)); text-decoration: underline; }

@media (max-width: 760px) {
  /* Tighter hero so the first tappable city link is reachable sooner */
  .dir-hero { padding: 16px 0 10px; }
  .dir-hero h1 { font-size: 22px; line-height: 1.2; }
  .dir-hero p { font-size: 13.5px; margin-top: 6px; }
  /* Drop the instructional filler — the tappable city list below makes it obvious */
  .dir-cta-hint { display: none; }
  /* One full-width column — the fixed 260px minmax minimum can't overflow */
  .dir-cities { grid-template-columns: 1fr; gap: 10px; }
  /* Primary tap target ≥ 44px */
  .dir-city-name { min-height: 44px; }
  /* Mute the repeated decorative dumbbell so the title + count lead */
  .dir-city-name svg { color: var(--text-light, #94a3b8); }
  /* Finger-safe sub-sport links */
  .dir-sports { gap: 8px 12px; margin-top: 8px; }
  .dir-sport { font-size: 13px; padding: 4px 0; }
  /* Card title already names the city — collapse links to Gym / Class / Academy */
  .dir-in { display: none; }
  /* Tighten section rhythm on phone */
  .dir-groups { gap: 18px; }
  /* Weight 900 stays reserved for the page h1 */
  .dir-country h2 { font-size: 16px; font-weight: 800; margin-bottom: 9px; }
}
</style>
