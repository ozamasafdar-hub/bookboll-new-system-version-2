<script setup>
import { computed, ref, watch, onBeforeUnmount, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search, X, MapPin, Building2, UsersRound, Dumbbell, CalendarDays, UserRound, ArrowRight, ChevronRight, Lock, Newspaper } from 'lucide-vue-next'
import { selectedCity, selectedCountry } from '../../lib/customerSession.js'
import { brandShows } from '../../lib/brand.js'
import { apiFetch } from '../../lib/api.js'
import { useSeo } from '../../lib/seo.js'
import { trackStorefrontEvent } from '../../lib/storefrontEvents.js'
import { emptySearchCatalog, fetchSearchCatalog, readSearchQuery, searchCatalog } from '../../lib/marketplaceSearch.js'
import { PEOPLE_PAGE_SIZE, fetchPeople, peopleSummary } from '../../lib/peopleSearch.js'
import FacilityCard from '../components/FacilityCard.vue'
import ProgramCard from '../components/ProgramCard.vue'
import GameCard from '../components/GameCard.vue'

const route = useRoute()
const router = useRouter()
const selection = computed(() => readSearchQuery(route.query))
const draft = ref(selection.value.q)
const input = ref(null)
const typeTrack = ref(null)
const catalog = ref(emptySearchCatalog())
const failed = ref([])
const loading = ref(true)
const limit = ref(24)
let requestId = 0
let searchTimer

// People are searched on the server: the catalogue tabs filter a loaded page,
// but there is no "all users" payload to filter and there must never be one.
const people = ref([])
const peopleResult = ref(null)
const peopleLoading = ref(false)
const peopleMore = ref(null)
let peopleId = 0

const sections = computed(() => [
  { key: 'listings', label: 'Listings', icon: Building2, to: '/facilities', hint: 'Courts, pitches and spaces', visible: brandShows('venues') },
  { key: 'communities', label: 'Communities', icon: UsersRound, to: '/communities', hint: 'Clubs and people to play with', visible: true },
  { key: 'memberships', label: 'Memberships', icon: Dumbbell, to: '/programs', hint: 'Gyms, classes and academies', visible: brandShows('programs') },
  { key: 'events', label: 'Events', icon: CalendarDays, to: '/games', hint: 'Games, meetups and tournaments', visible: brandShows('events') },
].filter(section => section.visible))
const enabledKeys = computed(() => sections.value.map(section => section.key))
const activeType = computed(() => ['all', 'people', ...enabledKeys.value].includes(selection.value.type) ? selection.value.type : 'all')
const ready = computed(() => selection.value.q.length >= 2)
const matches = computed(() => Object.fromEntries(sections.value.map(section => [section.key, searchCatalog(catalog.value[section.key], selection.value.q, section.key)])))
const count = computed(() => sections.value.reduce((total, section) => total + matches.value[section.key].length, 0))
const shownCount = computed(() => activeType.value === 'all' ? allCount.value : (matches.value[activeType.value]?.length || 0))
const visibleSections = computed(() => sections.value.filter(section => (activeType.value === 'all' || activeType.value === section.key) && matches.value[section.key].length))
const relevantFailures = computed(() => sections.value.filter(section => failed.value.includes(section.key) && (activeType.value === 'all' || activeType.value === section.key)))
const peopleCount = computed(() => peopleResult.value?.coverage?.status === 'complete' ? peopleResult.value.coverage.total : null)
const peopleSummaryText = computed(() => peopleSummary(peopleResult.value, people.value.length))
// What the badges and the All summary can honestly add for people: the exact
// total when the server counted everything, otherwise what has been fetched.
const peopleSearched = computed(() => !!(peopleResult.value?.ok && peopleResult.value.searched))
const peopleTally = computed(() => peopleSearched.value ? (peopleCount.value ?? people.value.length) : 0)
const peopleBadge = computed(() => {
  if (!peopleSearched.value) return null
  const { status, total } = peopleResult.value.coverage
  if (status === 'complete' && total !== null) return String(total)
  if (status === 'at_least' && total !== null) return `${total}+`
  return people.value.length ? `${people.value.length}+` : '0'
})
// The All tab is every section PLUS people, so the number it quotes is the sum.
const allCount = computed(() => count.value + peopleTally.value)
const PEOPLE_PREVIEW = 4
const peoplePreview = computed(() => people.value.slice(0, PEOPLE_PREVIEW))
const peopleHasMore = computed(() => people.value.length > PEOPLE_PREVIEW || !!peopleMore.value)
const peopleFailed = computed(() => !!(peopleResult.value && !peopleResult.value.ok && !peopleResult.value.unsupported))
const suggestions = computed(() => [...new Set(catalog.value.listings.map(listing => listing.sport).filter(Boolean))].slice(0, 5))

watch(activeType, async () => {
  await nextTick()
  const track = typeTrack.value
  const active = track?.querySelector('[aria-pressed="true"]')
  if (!active) return
  const bounds = track.getBoundingClientRect(), item = active.getBoundingClientRect()
  if (item.left < bounds.left || item.right > bounds.right) track.scrollBy({ left: item.left - bounds.left - (bounds.width - item.width) / 2, behavior: 'auto' })
}, { immediate: true })

async function load() {
  const id = ++requestId
  loading.value = true
  catalog.value = emptySearchCatalog()
  failed.value = []
  const result = await fetchSearchCatalog(apiFetch, { city: selectedCity.value, country: selectedCountry.value, enabled: enabledKeys.value })
  if (id !== requestId) return
  catalog.value = result.catalog
  failed.value = result.failed
  loading.value = false
}
async function loadPeople(cursor = null) {
  const id = ++peopleId
  peopleLoading.value = true
  const result = await fetchPeople(apiFetch, { q: selection.value.q, cursor, limit: PEOPLE_PAGE_SIZE })
  if (id !== peopleId) return
  peopleResult.value = result
  people.value = cursor ? [...people.value, ...result.users] : result.users
  peopleMore.value = result.nextCursor
  peopleLoading.value = false
}

// People are a separate request, so it runs once per query — never per city
// or catalogue change — and for every tab: the All view lists people at the
// bottom and counts them, so they cannot wait for the People tab to be opened.
watch(() => selection.value.q, (q) => {
  peopleId++
  people.value = []
  peopleMore.value = null
  peopleResult.value = null
  if (q.length >= 2) loadPeople()
}, { immediate: true })

watch([() => selectedCity.value?.id, () => selectedCity.value?.name, () => selectedCountry.value?.iso_code, () => enabledKeys.value.join(',')], load, { immediate: true })

function commit(type = activeType.value, replace = false) {
  clearTimeout(searchTimer)
  const q = draft.value.trim().slice(0, 120)
  if (!replace && q.length >= 2 && type !== 'people') trackStorefrontEvent('Search')
  if (q === selection.value.q && type === selection.value.type) return
  router[replace ? 'replace' : 'push']({ path: route.path, query: { ...(q ? { q } : {}), ...(type !== 'all' ? { type } : {}) } })
}
function clearSearch() { draft.value = ''; commit(); input.value?.focus() }
function trySearch(term) { draft.value = term; commit('all'); input.value?.focus() }
watch(draft, () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => commit(activeType.value, true), 250) })
watch(() => route.query, () => { clearTimeout(searchTimer); draft.value = selection.value.q; limit.value = 24 })
onBeforeUnmount(() => { clearTimeout(searchTimer); requestId++; peopleId++ })

useSeo({ title: 'Search Bookboll', description: 'Find listings, communities, memberships and events on Bookboll.', path: '/search', noindex: true })
</script>

<template>
  <div class="market-search">
    <header class="search-heading">
      <div><h1>Search Bookboll</h1><p>Find a place to play, your next event or a community to join.</p></div>
      <span v-if="selectedCity?.name" class="search-location"><MapPin :size="15" /> In {{ selectedCity.name }}</span>
    </header>

    <form class="search-form" role="search" @submit.prevent="commit()">
      <Search :size="21" aria-hidden="true" />
      <label for="marketplace-query" class="search-sr">Search Bookboll</label>
      <input id="marketplace-query" ref="input" v-model="draft" type="search" maxlength="120" placeholder="Search names, activities or places" autocomplete="off" enterkeyhint="search" aria-controls="search-results" />
      <button v-if="draft" class="search-clear" type="button" aria-label="Clear search" @click="clearSearch"><X :size="18" /></button>
      <button class="search-submit" type="submit">Search <ArrowRight :size="17" /></button>
    </form>

    <div ref="typeTrack" class="search-types" role="group" aria-label="Search categories">
      <button :aria-pressed="activeType === 'all'" @click="commit('all')">All<span v-if="ready && !loading && !peopleLoading">{{ allCount }}</span></button>
      <button v-for="section in sections" :key="section.key" :aria-pressed="activeType === section.key" @click="commit(section.key)"><component :is="section.icon" :size="16" />{{ section.label }}<span v-if="ready && !loading && !failed.includes(section.key)">{{ matches[section.key].length }}</span></button>
      <button :aria-pressed="activeType === 'people'" @click="commit('people')"><UserRound :size="16" />People<span v-if="peopleBadge !== null">{{ peopleBadge }}</span></button>
    </div>

    <div id="search-results" :aria-busy="activeType === 'people' ? peopleLoading : (ready && (loading || (activeType === 'all' && peopleLoading)))">
      <template v-if="activeType === 'people'">
        <p v-if="peopleLoading && !people.length" class="search-loading" role="status">Searching people…</p>

        <!-- A server without the endpoint is not an error the customer caused. -->
        <section v-else-if="peopleResult && peopleResult.unsupported" class="search-empty">
          <UserRound :size="30" /><h2>Find people on Bookboll</h2><p>Search by name is coming soon. You can already visit profiles through communities and events.</p>
          <router-link to="/communities">Explore communities <ArrowRight :size="16" /></router-link>
        </section>

        <!-- A search that could not run is not an empty result. -->
        <div v-else-if="peopleResult && !peopleResult.ok" class="search-error" role="status">
          <p>We couldn’t search people just now. Please try again.</p><button @click="loadPeople()">Retry</button>
        </div>

        <p v-else-if="!ready" class="search-minimum" role="status">Enter at least 2 characters to search for people.</p>

        <template v-else-if="peopleResult">
          <p class="search-summary" role="status">{{ peopleSummaryText }} for <strong>“{{ selection.q }}”</strong></p>

          <section v-if="!people.length" class="search-empty">
            <UserRound :size="30" /><h2>No people match “{{ selection.q }}”</h2>
            <p>Try another spelling, or a first name on its own. People who turned off being found by name won’t appear here.</p>
            <button @click="commit('all')">Search all categories <ArrowRight :size="16" /></button>
          </section>

          <section v-else class="search-section" aria-labelledby="results-people">
            <div class="search-section-heading"><h2 id="results-people">People <span>{{ people.length }}</span></h2></div>
            <div class="search-grid search-grid-people">
              <router-link v-for="person in people" :key="person.id" class="search-person" :to="person.url">
                <div class="search-person-photo"><img v-if="person.avatar" :src="person.avatar" alt="" loading="lazy" /><UserRound v-else :size="24" /></div>
                <div class="search-person-body">
                  <h3>{{ person.name }}<Lock v-if="person.isPrivate" :size="12" aria-label="Private profile" /></h3>
                  <p>{{ person.hostedCount }} hosted · {{ person.joinedCount }} joined</p>
                </div>
                <ChevronRight :size="18" />
              </router-link>
            </div>
            <button v-if="peopleMore" class="search-more" :disabled="peopleLoading" @click="loadPeople(peopleMore)">{{ peopleLoading ? 'Loading…' : 'Show more people' }}</button>
          </section>
        </template>
      </template>

      <template v-else-if="!ready">
        <p v-if="selection.q" class="search-minimum" role="status">Enter at least 2 characters to search.</p>
        <div v-if="suggestions.length" class="search-suggestions"><span>Try an activity</span><button v-for="term in suggestions" :key="term" @click="trySearch(term)">{{ term }} <ArrowRight :size="14" /></button></div>
        <section class="search-explore"><h2>Explore Bookboll</h2><div class="search-explore-grid"><router-link v-for="section in sections" :key="section.key" :to="section.to"><component :is="section.icon" :size="23" /><span><strong>{{ section.label }}</strong><small>{{ section.hint }}</small></span><ChevronRight :size="18" /></router-link><router-link v-if="brandShows('blog')" to="/blog"><Newspaper :size="23" /><span><strong>Blog</strong><small>Ideas and guides for your next game</small></span><ChevronRight :size="18" /></router-link></div></section>
      </template>

      <p v-else-if="loading" class="search-loading" role="status">Searching Bookboll…</p>

      <template v-else>
        <p class="search-summary" role="status">{{ shownCount }} {{ shownCount === 1 ? 'result' : 'results' }} for <strong>“{{ selection.q }}”</strong><span v-if="relevantFailures.length"> · Some results are unavailable</span></p>
        <div v-if="relevantFailures.length" class="search-error" role="status"><p>We couldn’t load {{ relevantFailures.map(section => section.label.toLowerCase()).join(', ') }}. Please try again.</p><button @click="load">Retry</button></div>
        <section v-if="!shownCount && !relevantFailures.length && !(activeType === 'all' && (peopleLoading || peopleFailed))" class="search-empty"><Search :size="30" /><h2>No matches{{ activeType !== 'all' ? ' in ' + activeType : '' }}</h2><p>Try a different name or activity{{ selectedCity?.name ? ', or choose another city in the header' : '' }}.</p><button v-if="activeType !== 'all'" @click="commit('all')">Search all categories <ArrowRight :size="16" /></button><button v-else @click="clearSearch">Clear search <X :size="16" /></button></section>

        <section v-for="section in visibleSections" :key="section.key" class="search-section" :aria-labelledby="`results-${section.key}`">
          <div class="search-section-heading"><h2 :id="`results-${section.key}`">{{ section.label }} <span>{{ matches[section.key].length }}</span></h2><button v-if="activeType === 'all' && matches[section.key].length > 4" @click="commit(section.key)">See all {{ matches[section.key].length }} <ArrowRight :size="16" /></button></div>
          <div class="search-grid" :class="'search-grid-' + section.key">
            <template v-for="result in matches[section.key].slice(0, activeType === 'all' ? 4 : limit)" :key="result.id">
              <FacilityCard v-if="section.key === 'listings'" :facility="{ ...result, currency: result.currency || selectedCountry?.currency_code || '' }" :return-to="route.fullPath" />
              <ProgramCard v-else-if="section.key === 'memberships'" :program="result" />
              <GameCard v-else-if="section.key === 'events'" :game="result" />
              <router-link v-else class="search-community" :to="`/communities/${result.id}`">
                <div class="search-community-photo"><img v-if="result.avatar_image || result.cover_image" :src="result.avatar_image || result.cover_image" alt="" loading="lazy" /><UsersRound v-else :size="26" /></div>
                <div class="search-community-body"><span class="search-community-sport">{{ result.sport || 'Community' }}<Lock v-if="result.is_private" :size="12" aria-label="Private community" /></span><h3>{{ result.name }}</h3><p v-if="result.description">{{ result.description }}</p><span class="search-community-members">{{ result.memberCount || 0 }} {{ result.memberCount === 1 ? 'member' : 'members' }} <ChevronRight :size="16" /></span></div>
              </router-link>
            </template>
          </div>
          <button v-if="activeType !== 'all' && matches[section.key].length > limit" class="search-more" @click="limit += 24">Show more {{ section.label.toLowerCase() }}</button>
        </section>

        <!-- People close the All view: a name search that matched nobody's
             venue or club still finds the person. The full, paged list lives
             on the People tab. -->
        <template v-if="activeType === 'all'">
          <p v-if="peopleLoading && !people.length" class="search-loading search-loading-people" role="status">Searching people…</p>
          <div v-else-if="peopleFailed" class="search-error" role="status"><p>We couldn’t search people just now.</p><button @click="loadPeople()">Retry</button></div>
          <section v-else-if="people.length" class="search-section" aria-labelledby="results-people-all">
            <div class="search-section-heading"><h2 id="results-people-all">People <span>{{ peopleBadge }}</span></h2><button v-if="peopleHasMore" @click="commit('people')">See all{{ peopleCount !== null ? ' ' + peopleCount : '' }} <ArrowRight :size="16" /></button></div>
            <div class="search-grid search-grid-people">
              <router-link v-for="person in peoplePreview" :key="person.id" class="search-person" :to="person.url">
                <div class="search-person-photo"><img v-if="person.avatar" :src="person.avatar" alt="" loading="lazy" /><UserRound v-else :size="24" /></div>
                <div class="search-person-body">
                  <h3>{{ person.name }}<Lock v-if="person.isPrivate" :size="12" aria-label="Private profile" /></h3>
                  <p>{{ person.hostedCount }} hosted · {{ person.joinedCount }} joined</p>
                </div>
                <ChevronRight :size="18" />
              </router-link>
            </div>
          </section>
        </template>
      </template>
    </div>
  </div>
</template>

<style scoped>
.market-search { min-width: 0; }
.search-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin: 6px 0 26px; }
.search-heading h1 { font-size: 34px; font-weight: 700; letter-spacing: -.8px; line-height: 1.2; margin: 0 0 10px; }
.search-heading p { font-size: 14px; line-height: 1.6; color: var(--text-muted); }
.search-location { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); font-size: 13px; padding-top: 10px; white-space: nowrap; }
.search-form { display: flex; align-items: center; gap: 12px; padding: 8px 8px 8px 18px; border: 1px solid var(--border-strong, var(--border)); border-radius: 14px; background: var(--surface); max-width: 820px; }
.search-form:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 12%, transparent); }
.search-form > svg { color: var(--text-muted); flex-shrink: 0; }
.search-form input { width: 100%; min-width: 0; flex: 1; background: transparent; color: var(--text); border: 0; padding: 9px 0; font: inherit; font-size: 16px; outline: none; }
.search-form input::-webkit-search-cancel-button { display: none; }
.search-submit, .search-clear { display: flex; align-items: center; justify-content: center; flex-shrink: 0; min-height: 44px; border-radius: 9px; }
.search-submit { background: var(--brand); color: white; font-size: 14px; font-weight: 600; padding: 10px 18px; gap: 10px; }
.search-clear { width: 44px; color: var(--text-muted); background: transparent; }
.search-types { display: flex; align-items: center; gap: 8px; overflow-x: auto; scrollbar-width: thin; padding: 5px 2px 12px; margin: 17px -2px 24px; border-bottom: 1px solid var(--border); }
.search-types button { display: flex; align-items: center; justify-content: center; gap: 7px; flex-shrink: 0; padding: 11px 14px; min-height: 44px; color: var(--text-muted); background: transparent; border: 1px solid transparent; border-radius: 10px; font-size: 13px; font-weight: 600; white-space: nowrap; }
.search-types button[aria-pressed="true"] { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 8%, var(--surface)); color: var(--brand-ink, var(--brand)); }
.search-types button:hover { background: var(--surface); }
.search-types span { font-size: 11px; font-weight: 600; background: var(--bg); padding: 2px 6px; border-radius: 5px; }
.search-types small { font-size: 10px; font-weight: 500; }
.search-grid-people { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
.search-person { display: flex; align-items: center; gap: 13px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 13px; background: var(--surface); min-height: 44px; }
.search-person:hover { border-color: var(--brand); }
.search-person-photo { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 46px; height: 46px; border-radius: 50%; overflow: hidden; background: var(--bg); color: var(--text-muted); }
.search-person-photo img { width: 100%; height: 100%; object-fit: cover; }
.search-person-body { min-width: 0; flex: 1; }
.search-person-body h3 { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; margin: 0 0 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.search-person-body p { font-size: 12px; color: var(--text-muted); }
.search-person > svg:last-child { flex-shrink: 0; color: var(--text-muted); }
.search-suggestions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 0 0 34px; }
.search-suggestions > span { font-size: 13px; color: var(--text-muted); margin-inline-end: 4px; }
.search-suggestions button { display: flex; align-items: center; gap: 7px; padding: 10px 14px; min-height: 44px; border: 1px solid var(--border); border-radius: 22px; background: var(--surface); font-size: 13px; color: var(--text); }
.search-suggestions svg { color: var(--brand-ink, var(--brand)); }
.search-explore { max-width: 820px; }
.search-explore h2 { font-size: 21px; font-weight: 650; margin: 0 0 12px; }
.search-explore-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 0 36px; }
.search-explore a { display: flex; align-items: center; gap: 14px; min-height: 92px; padding: 18px 2px; border-bottom: 1px solid var(--border); }
.search-explore a > svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.search-explore a > svg:last-child { margin-inline-start: auto; color: var(--text-muted); }
.search-explore a span { min-width: 0; }
.search-explore strong { display: block; font-size: 15px; font-weight: 600; }
.search-explore small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 5px; line-height: 1.5; }
.search-summary { font-size: 14px; color: var(--text-muted); margin: 0 0 28px; overflow-wrap: anywhere; }
.search-summary strong { color: var(--text); font-weight: 600; }
.search-section { margin-bottom: 34px; }
.search-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
.search-section-heading h2 { font-size: 23px; font-weight: 650; letter-spacing: -.4px; display: flex; align-items: center; gap: 10px; }
.search-section-heading h2 span { font-size: 12px; background: var(--bg); color: var(--text-muted); padding: 4px 7px; border-radius: 6px; letter-spacing: 0; }
.search-section-heading button, .search-empty a, .search-empty button { display: inline-flex; align-items: center; gap: 8px; color: var(--brand-ink, var(--brand)); background: transparent; font-size: 13px; font-weight: 600; min-height: 44px; }
.search-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 18px; }
.search-grid :deep(.fc), .search-grid :deep(.pc), .search-grid :deep(.gc) { width: 100%; min-width: 0; }
.search-grid-listings :deep(.fc), .search-grid-memberships :deep(.pc) { display: grid; grid-template-columns: 145px minmax(0,1fr); box-shadow: none; }
.search-grid-listings :deep(.fc-img), .search-grid-memberships :deep(.pc-img) { width: 100%; height: 100%; min-height: 140px; max-height: none; aspect-ratio: auto; }
.search-grid-listings :deep(.fc-body), .search-grid-memberships :deep(.pc-body) { padding: 16px; min-width: 0; }
.search-grid-listings :deep(.fc-price), .search-grid-memberships :deep(.pc-foot) { flex-wrap: wrap; }
.search-grid-memberships :deep(.pc-name) { white-space: normal; }
.search-community { display: flex; gap: 15px; padding: 18px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); min-width: 0; }
.search-community-photo { flex-shrink: 0; width: 60px; height: 60px; border-radius: 12px; background: color-mix(in srgb, var(--brand) 8%, var(--surface)); color: var(--brand-ink, var(--brand)); overflow: hidden; display: grid; place-items: center; }
.search-community-photo img { width: 100%; height: 100%; object-fit: cover; }
.search-community-body { flex: 1; min-width: 0; }
.search-community-sport { display: flex; align-items: center; gap: 7px; font-size: 11px; color: var(--text-muted); }
.search-community h3 { font-size: 15px; font-weight: 650; line-height: 1.4; margin: 5px 0 6px; }
.search-community p { font-size: 12px; line-height: 1.6; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.search-community-members { display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--text-muted); margin-top: 14px; }
.search-community-members svg { color: var(--brand-ink, var(--brand)); }
.search-empty { max-width: 460px; padding: 34px 0 46px; }
.search-empty > svg { color: var(--text-muted); margin-bottom: 16px; }
.search-empty h2 { font-size: 22px; font-weight: 650; margin: 0 0 10px; }
.search-empty p { color: var(--text-muted); font-size: 14px; line-height: 1.7; margin-bottom: 12px; }
.search-loading { padding: 38px 0; color: var(--text-muted); }
.search-loading-people { padding: 6px 0 30px; font-size: 13px; }
.search-minimum { color: var(--text-muted); margin-bottom: 20px; font-size: 13px; }
.search-error { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 18px; margin-bottom: 24px; border: 1px solid var(--warning-border, #fde68a); background: var(--warning-bg, #fffbeb); color: var(--warning-ink, #92400e); border-radius: 12px; font-size: 13px; }
.search-error button { color: inherit; text-decoration: underline; font-weight: 600; min-height: 44px; padding-inline: 10px; }
.search-more { display: block; min-height: 44px; padding: 12px 20px; margin: 22px auto 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); font-size: 13px; font-weight: 600; }
.market-search button:focus-visible, .market-search a:focus-visible { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: 3px; }
.search-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
@media(min-width:768px) and (max-width:1000px) { .search-grid { grid-template-columns: 1fr; } }
@media(max-width:767px) {
  .search-heading { flex-direction: column; gap: 12px; margin-bottom: 20px; }
  .search-heading h1 { font-size: 27px; letter-spacing: -.5px; }
  .search-heading p { font-size: 13px; max-width: 34ch; }
  .search-location { padding-top: 0; font-size: 12px; }
  .search-form { padding: 5px 5px 5px 12px; gap: 6px; }
  .search-form > svg { width: 18px; }
  .search-form input { font-size: 16px; }
  .search-submit { width: 44px; padding: 10px; font-size: 0; gap: 0; }
  .search-clear { width: 36px; }
  .search-types { margin-top: 13px; margin-bottom: 20px; gap: 4px; scrollbar-width: none; }
  .search-types::-webkit-scrollbar { display: none; }
  .search-types button { padding: 10px 12px; font-size: 12px; }
  .search-suggestions { gap: 8px; margin-bottom: 28px; }
  .search-suggestions > span { width: 100%; }
  .search-explore-grid { grid-template-columns: 1fr; }
  .search-explore a { min-height: 82px; }
  .search-grid { grid-template-columns: 1fr; gap: 14px; }
  .search-section-heading h2 { font-size: 20px; }
  .search-summary { margin-bottom: 20px; font-size: 13px; }
  .search-grid-listings :deep(.fc) { display: grid; grid-template-columns: clamp(132px, 36%, 220px) minmax(0,1fr); }
  .search-grid-listings :deep(.fc-img) { height: 100%; min-height: 135px; max-height: none; aspect-ratio: auto; }
  .search-grid-listings :deep(.fc-body) { padding: 12px; }
  .search-grid-listings :deep(.fc-title) { font-size: 14px; }
  .search-grid-listings :deep(.fc-price) { flex-wrap: wrap; }
  .search-grid-memberships :deep(.pc) { grid-template-columns: 105px minmax(0,1fr); }
  .search-grid-memberships :deep(.pc-body) { padding: 12px; }
  .search-community { padding: 15px; gap: 12px; }
  .search-community-photo { width: 54px; height: 54px; }
}
</style>
