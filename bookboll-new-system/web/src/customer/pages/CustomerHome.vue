<script setup>
import CommunityCard from '../components/CommunityCard.vue'
import { popularCommunities } from '../../lib/communityPresentation.js'
import { ref, computed, watch, onMounted } from 'vue'
import { selectedCity, selectedCountry, isCustomer, customer, loadCustomerLoyalty } from '../../lib/customerSession.js'
import { loadFacilities, loadPrograms } from '../../customer/data.js'
import { loadGames, gamesEnabled } from '../../customer/apiGames.js'
import { brandShows, isWhiteLabel, activeBrand, platformBrand, isBrandPreview } from '../../lib/brand.js'
import { useSeo, organizationLd, websiteLd, itemListLd, faqLd } from '../../lib/seo.js'
import { loadDirectory, slugifyCity, slugifySport } from '../../lib/cityPages.js'
import { membershipCategoryLabel } from '../../lib/membershipCategories.js'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import ServiceRow from '../components/ServiceRow.vue'
import VenueCategoryCard from '../components/VenueCategoryCard.vue'
import { catalogCategories, sampleListingPhotos } from '../../lib/browseCatalog.js'
import { categoryClickCounts, recordCategoryClick } from '../categoryPopularity.js'
import ProgramCard from '../components/ProgramCard.vue'
import GameCard from '../components/GameCard.vue'
import HeroSlideshow from '../components/HeroSlideshow.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { businessHomeUrl } from '../../lib/crossApp.js'
import BusinessHome from './BusinessHome.vue'
import { readableTags } from '../../lib/blogPresentation.js'
import { visibleBanners, defaultBanners } from '../../lib/heroBanners.js'
import { loadCommunities } from '../../customer/community.js'
import { avatarColor } from '../../lib/avatarColor.js'
import { Dumbbell, CalendarDays, Plus, ArrowRight, Sparkles, MapPin, Newspaper, HelpCircle, CalendarCheck, UsersRound, Lock, Building2 } from 'lucide-vue-next'

// Hero slideshow banners — provider's on a white-label site, else the marketplace's,
// filtered by audience (signed-in / signed-out / everyone) AND geo (country/city).
const heroBanners = computed(() => {
  const src = isWhiteLabel.value ? (activeBrand.value?.banners || []) : (platformBrand.value?.banners || [])
  return visibleBanners(src.length || isWhiteLabel.value ? src : defaultBanners({ platform: true }), { signedIn: isCustomer.value, countryId: selectedCountry.value?.id, cityId: selectedCity.value?.id })
})
// A system "loyalty" slide is shown FIRST for signed-in apex customers (rewards = platform-only).
const customerFirstName = computed(() => (customer.value?.full_name || '').split(' ')[0] || '')
const loyaltySlide = computed(() => (isCustomer.value && loyalty.value && !isWhiteLabel.value) ? { id: '__loyalty', kind: 'loyalty' } : null)
const slides = computed(() => loyaltySlide.value ? [loyaltySlide.value, ...heroBanners.value] : heroBanners.value)

const showAllFaqs = ref(false)
const facilities = ref([])
const programs = ref([])
const games = ref([])
const communities = ref([])
const loyalty = ref(null)
const loading = ref(true)
const venueCategories = computed(() => [
  { key: 'all', label: 'All Venues', count: facilities.value.length, photos: sampleListingPhotos(facilities.value), to: '/facilities' },
  ...catalogCategories(facilities.value, categoryClickCounts(facilities.value)).map(c => ({
    ...c, count: c.listings.length, photos: sampleListingPhotos(c.listings),
    to: { path: '/facilities', query: { category: c.category, ...(c.type ? { type: c.type } : {}) } },
  })),
])

// Popular cities (apex only) for the global discoverability section + ItemList JSON-LD.
const popularCities = ref([])
// Latest blog posts for the home "From the blog" row — the marketplace's on apex,
// or the provider's own on their white-label site.
const blogPosts = ref([])
let loadId = 0
let loyaltyLoadId = 0
async function refreshLoyalty() {
  const current = ++loyaltyLoadId
  const countryId = selectedCountry.value?.id
  const customerId = customer.value?.id
  loyalty.value = null
  const result = isCustomer.value ? await loadCustomerLoyalty(countryId) : null
  if (current === loyaltyLoadId && countryId === selectedCountry.value?.id && customerId === customer.value?.id) loyalty.value = result
}
async function load() {
  if (isWhiteLabel.value) return
  const current = ++loadId
  loading.value = true
  const cityId = selectedCity.value?.id
  const [f, p, g, cm] = await Promise.all([
    loadFacilities(cityId), loadPrograms(cityId), loadGames(cityId),
    isWhiteLabel.value ? Promise.resolve([]) : loadCommunities({ cityId }),   // communities = apex marketplace only
  ])
  if (current !== loadId) return
  facilities.value = f; programs.value = p; games.value = g; communities.value = popularCommunities(cm)
  await refreshLoyalty()
  if (current === loadId) loading.value = false
}
onMounted(async () => {
  if (isWhiteLabel.value) return
  load()
  // Blog row: marketplace posts on apex, the provider's own on their white-label site.
  blogPosts.value = await loadPublishedPosts({ limit: 3 })
  if (!isWhiteLabel.value) {
    const dir = await loadDirectory()
    popularCities.value = dir.flatMap(g => g.cities.map(c => ({ ...c, country: g.country.name }))).sort((a, b) => b.count - a.count).slice(0, 12)
  }
})
watch([() => selectedCity.value?.id, () => selectedCountry.value?.id], load)

// Current-city sports for the "Browse by sport" chips (links to the city landing pages).
const citySlug = computed(() => selectedCity.value ? slugifyCity(selectedCity.value.name) : '')
const citySports = computed(() => {
  const t = {}
  for (const f of facilities.value) { if (!f.sport) continue; const sl = slugifySport(f.sport); if (!t[sl]) t[sl] = { name: f.sport, slug: sl } }
  return Object.values(t).slice(0, 10)
})
const cityMemberships = computed(() => {
  const t = {}
  for (const p of programs.value) { const k = p.category || 'academy'; if (!t[k]) t[k] = { key: k, label: membershipCategoryLabel(k) } }
  return Object.values(t)
})

// Marketplace FAQ — apex only (a provider's white-label site must not carry Bookboll
// marketplace answers; those pages are noindex anyway). Every answer is product-accurate:
// payment options vary per venue, the deposit is PART of the listed price (never a fee on
// top), free trials are once per business, rewards are a marketplace-only mechanic,
// hosted-event money goes to the HOST, and cancellation terms sit with the venue/host.
const faqs = computed(() => {
  if (isWhiteLabel.value) return []
  return [
    { q: 'What is Bookboll?',
      a: 'Bookboll is a sports booking marketplace. Book padel, football, tennis and other courts by the hour, discover gyms, classes and academies and contact them directly, and find local games and tournaments to play in — all with one free account.' },
    { q: 'How do I book a sports venue on Bookboll?',
      a: 'Pick a venue, choose a date and an available time slot, and confirm at checkout. Availability is live, your selected slots are held for you while you finish the booking, and the confirmation appears in your account instantly.' },
    { q: 'Do I pay online or at the venue?',
      a: 'Payment options vary per venue: some take the full amount online, while others take a deposit online with the rest paid on arrival at the venue. Whichever a venue offers, you see the options at checkout before you confirm.' },
    { q: 'Is the deposit an extra fee?',
      a: 'No. When a venue asks for a deposit, it is part of the total listed price — you pay that portion online to secure the slot and settle the remaining balance at the venue. It is never charged on top of the price.' },
    { q: 'Does Bookboll add booking fees for customers?',
      a: 'No. You pay the venue\'s listed price — Bookboll doesn\'t add service or booking fees on top at checkout.' },
    { q: 'How do I join an academy?', a: 'Open its membership listing and call the academy. Enrolment, trials, schedules and payment are arranged directly with the provider.' },
    { q: 'What are Bookboll Rewards points?',
      a: 'Visit My Rewards to see the program for your selected country, your confirmed points and the earning rules. Rates and ranks can differ by country. The rewards page also explains redemption availability; venue-booking redemption is not available yet.' },
    { q: 'Can I play even if I don\'t have a group?',
      a: 'Yes. Browse player-hosted games, hangouts and tournaments near you. Some events are instant join — your spot is confirmed the moment you join — while others need the host to approve your request first; each event page says which. You can also host your own game at any bookable venue.' },
    { q: 'How do I pay for a hosted game or tournament?',
      a: 'The price of a hosted event is paid to the host — usually on arrival, or online when the host has enabled online tickets. Bookboll connects you with the event, but the host runs it and collects the payment.' },
    { q: 'Who manages academy memberships?', a: 'The academy handles membership payments, attendance, changes and cancellations. Bookboll provides discovery and contact details.' },
    { q: 'Can I cancel or change a booking?',
      a: 'Cancellation and refund terms are agreed between you and the venue or host, so they aren\'t the same everywhere. Check the listing and our Terms page, and contact the venue directly if your plans change.' },
    { q: 'Do I need an account to book?',
      a: 'Yes — a free Bookboll account, created with just your email during checkout. It keeps your bookings, rewards points and games together in one place.' },
  ]
})

useSeo(() => {
  const b = isWhiteLabel.value ? activeBrand.value : platformBrand.value
  const brand = b?.name || 'Bookboll'
  const city = selectedCity.value?.name
  // A provider/marketplace custom SEO title/description (My Website) wins when set.
  const metaTitle = b?.metaTitle
  const metaDescription = b?.metaDescription
  return {
    exactTitle: metaTitle || undefined,
    title: metaTitle ? undefined : (city ? `Book sports venues, classes & events in ${city}` : null),
    description: metaDescription || `${brand} — reserve sports courts and pitches by the hour, join games and tournaments, and discover academies and memberships${city ? ' in ' + city : ''}.`,
    path: '/',
    jsonLd: [
      organizationLd(), websiteLd(),
      itemListLd(popularCities.value.map(c => ({ name: `Sports venues in ${c.name}`, path: `/venues/${c.slug}` })), 'Popular cities'),
      faqLd(faqs.value),
    ].filter(Boolean),
  }
})
watch(() => customer.value?.id, refreshLoyalty)
</script>

<template>
  <!-- A provider's white-label site gets the business-website layout, not the
       marketplace browse rows (see BusinessHome.vue). -->
  <BusinessHome v-if="isWhiteLabel" />
  <div v-else class="home">
    <h1 class="home-page-title">Book venues, join events and discover communities{{ selectedCity?.name ? ` in ${selectedCity.name}` : ' near you' }}</h1>
    <!-- Customizable hero slideshow (super admin → marketplace; provider → their site).
         Filtered by audience (signed-in / signed-out / everyone) + geo (country/city).
         A system "loyalty" slide is prepended FIRST for signed-in apex customers
         (rewards are a platform mechanic, not shown on white-label sites). When there
         are no slides at all, the default welcome hero below shows instead. -->
    <HeroSlideshow v-if="slides.length" :banners="slides" :city="selectedCity?.name"
      :loyalty="loyalty" :customer-name="customerFirstName" />

    <section v-else class="welcome" aria-label="Welcome to Bookboll">
      <div><h2>More ways to play{{ selectedCity?.name ? ` in ${selectedCity.name}` : ' near you' }}.</h2><p>Book courts, join games and find your next class.</p></div>
      <router-link v-if="!isCustomer" to="/account" class="welcome-cta"><Sparkles :size="16" /> Sign in to earn rewards</router-link>
    </section>

    <div v-if="loading" class="home-loading">Loading what's near you…</div>

    <template v-else>
      <!-- Facilities -->
      <ServiceRow v-if="brandShows('venues')" title="Book a venue" tone="venues" :icon="CalendarCheck" subtitle="A space for every way you play." show-subtitle viewAll="/facilities" class="home-categories" :class="{ 'home-categories-sparse': facilities.length < 5 }">
        <VenueCategoryCard v-for="category in (facilities.length ? venueCategories : [])" :key="category.key" :category="category" @click="recordCategoryClick(facilities, category, 'home', { preview: isBrandPreview() })" />
        <div v-if="facilities.length < 5" class="venue-recruit" :class="{ 'venue-recruit-empty': !facilities.length }">
          <span class="venue-recruit-icon"><Building2 :size="28" /></span>
          <div class="venue-recruit-copy"><h3>{{ facilities.length ? 'Your venue belongs here' : `Be the first venue in ${selectedCity?.name || 'your city'}` }}</h3><p>{{ facilities.length ? 'Own a court, studio or space? Help more people discover and book it.' : 'No venues listed here yet. Bring your court, studio or space to Bookboll and start welcoming local players.' }}</p></div>
          <a :href="businessHomeUrl('/')" class="venue-recruit-cta">Add your venue <ArrowRight :size="16" /></a>
        </div>
      </ServiceRow>

      <!-- Games -->
      <ServiceRow v-if="brandShows('events')" title="Events" tone="events" :icon="CalendarDays" subtitle="Join a game. Meet someone new." show-subtitle viewAll="/games">
        <template v-if="gamesEnabled !== false && games.length">
          <GameCard v-for="g in games.slice(0, 10)" :key="g.id" :game="g" />
          <router-link to="/host-game" class="host-card">
            <div class="host-plus"><Plus :size="26" /></div>
            <div class="host-title">Host &amp; Earn</div>
            <div class="host-sub">Pick a venue, set price per person, invite players</div>
          </router-link>
        </template>
        <div v-else class="feature-empty feature-events">
          <span class="feature-empty-icon"><CalendarDays :size="28" /></span>
          <div><h3>{{ gamesEnabled === false ? 'Events are coming soon' : 'Host & earn' }}</h3><p>{{ gamesEnabled === false ? 'Find games, meetups and tournaments here as they become available.' : 'Pick a venue, set your price per player, and bring people together.' }}</p><span v-if="gamesEnabled !== false" class="feature-empty-note">No upcoming events in {{ selectedCity?.name || 'your city' }} yet. Host the next one.</span></div>
          <router-link v-if="gamesEnabled !== false" to="/host-game" class="host-earn-cta">Host &amp; earn <ArrowRight :size="16" /></router-link>
        </div>
      </ServiceRow>

      <!-- Communities (apex marketplace only) -->
      <ServiceRow title="Communities" tone="communities" :icon="UsersRound" subtitle="Find your people. Most popular communities first." show-subtitle viewAll="/communities">
        <CommunityCard v-for="cm in communities.slice(0, 10)" :key="cm.id" :community="cm" class="home-community" />
        <router-link v-if="communities.length" to="/communities/new" class="host-card community-create"><div class="host-plus"><UsersRound :size="26" /></div><div class="host-title">Start a community</div><div class="host-sub">Your club, your people. Share updates and organize your next meetup.</div><span class="comm-cta">Create your group <Plus :size="15" /></span></router-link>
        <div v-if="!communities.length" class="feature-empty feature-communities"><span class="feature-empty-icon"><UsersRound :size="28" /></span><div><h3>Find your people</h3><p>No communities in {{ selectedCity?.name || 'your city' }} yet. Start a group for your next game.</p></div><router-link to="/communities/new">Start a community <Plus :size="16" /></router-link></div>
      </ServiceRow>

      <!-- Programs -->
      <ServiceRow v-if="brandShows('programs')" title="Memberships" tone="memberships" :icon="Dumbbell" subtitle="Gyms, classes and academies. Find your routine." show-subtitle viewAll="/programs">
        <ProgramCard v-for="p in programs.slice(0, 10)" :key="p.id" :program="{ ...p, cityName: selectedCity?.name }" />
        <div v-if="!programs.length" class="feature-empty feature-memberships"><span class="feature-empty-icon"><Dumbbell :size="28" /></span><div><h3>No memberships in {{ selectedCity?.name || 'your city' }} yet</h3><p>Run a gym, class or academy? Reach new members with Bookboll for businesses.</p></div><a :href="businessHomeUrl('/')" class="feature-business-cta">Add your business <ArrowRight :size="16" /></a></div>
      </ServiceRow>

      <RecruitBanner :city-name="selectedCity?.name" category="business" />

      <!-- From the blog — latest posts (marketplace's on apex, provider's on their site) + View all.
           Shown BELOW the "For businesses" banner. -->
      <section v-if="blogPosts.length" class="bloghome">
        <div class="bh-head">
          <div>
            <h2 class="bh-title"><Newspaper :size="20" class="bh-title-ico" /> Good to know</h2>
            <p class="bh-sub">Ideas and guides for your next game</p>
          </div>
          <router-link to="/blog" class="bh-viewall">View all <ArrowRight :size="15" /></router-link>
        </div>
        <div class="bh-grid">
          <router-link v-for="(p, index) in blogPosts" :key="p.id" :to="`/post/${p.slug}`" class="bh-card" :class="{ 'bh-featured': index === 0 }">
            <div class="bh-cover" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
              <span v-if="!p.cover_image" class="bh-cover-ph"><Newspaper :size="24" /></span>
            </div>
            <div class="bh-body">
              <div v-if="readableTags(p.tags).length" class="bh-tags"><span v-for="t in readableTags(p.tags).slice(0, 1)" :key="t" class="bh-tag">{{ t }}</span></div>
              <h3 class="bh-card-title">{{ p.title }}</h3>
              <p v-if="p.excerpt && index === 0" class="bh-excerpt">{{ p.excerpt }}</p>
              <div class="bh-foot">
                <span class="bh-meta" v-if="p.published_at">{{ fmtPostDate(p.published_at) }}</span>
                <span class="bh-read">Read <ArrowRight :size="14" /></span>
              </div>
            </div>
          </router-link>
        </div>
      </section>

      <!-- Marketplace FAQ (apex only — mirrors the FAQPage JSON-LD above).
           Sits directly under the blog row per design. -->
      <section v-if="faqs.length" class="home-faq">
        <h2><HelpCircle :size="17" /> Frequently asked questions</h2>
        <details v-for="(f, i) in (showAllFaqs ? faqs : faqs.slice(0, 4))" :key="i" class="home-faq-item">
          <summary>{{ f.q }}</summary>
          <p>{{ f.a }}</p>
        </details>
        <button class="faq-more" :aria-expanded="showAllFaqs" @click="showAllFaqs = !showAllFaqs">{{ showAllFaqs ? 'Show fewer questions' : 'See all questions' }}</button>
      </section>

      <!-- Explore the current city: venues + memberships in one cohesive panel.
           The heading carries the city, so chip labels stay short (clean UI); the
           keyword-rich "{sport} in {city}" anchors live in the sitewide footer hub. -->
      <section v-if="!isWhiteLabel && (citySports.length || cityMemberships.length)" class="explore">
        <h2 class="explore-h"><MapPin :size="17" /> Explore {{ selectedCity?.name }}</h2>
        <div class="explore-rows">
          <div v-if="citySports.length" class="explore-row">
            <span class="explore-label">Venues</span>
            <div class="explore-chips">
              <router-link v-for="s in citySports" :key="s.slug" :to="`/venues/${citySlug}/${s.slug}`" class="chip" :aria-label="`${s.name} in ${selectedCity?.name}`">{{ s.name }}</router-link>
              <router-link :to="`/venues/${citySlug}`" class="chip chip-all">All venues <ArrowRight :size="13" /></router-link>
            </div>
          </div>
          <div v-if="cityMemberships.length" class="explore-row">
            <span class="explore-label">Memberships</span>
            <div class="explore-chips">
              <router-link v-for="m in cityMemberships" :key="m.key" :to="`/memberships/${citySlug}/${m.key}`" class="chip" :aria-label="`${m.label} in ${selectedCity?.name}`">{{ m.label }}</router-link>
              <router-link :to="`/memberships/${citySlug}`" class="chip chip-all">All memberships <ArrowRight :size="13" /></router-link>
            </div>
          </div>
        </div>
      </section>

      <!-- Popular cities across the platform (global discoverability) -->
      <section v-if="!isWhiteLabel && popularCities.length" class="cities">
        <div class="cities-head">
          <h2>Browse by city</h2>
          <router-link to="/venues" class="cities-all">All cities <ArrowRight :size="14" /></router-link>
        </div>
        <div class="cities-grid">
          <router-link v-for="c in popularCities" :key="c.slug" :to="`/venues/${c.slug}`" class="city">
            <span class="city-main">
              <span class="city-name">{{ c.name }}</span>
              <span class="city-country">{{ c.country }}</span>
            </span>
            <span v-if="c.count" class="city-count">{{ c.count }}</span>
          </router-link>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.home { display: flex; flex-direction: column; gap: 32px; padding-top: 8px; }
.home > * { margin: 0; min-width: 0; }
.home-page-title { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.feature-empty-note { display: block; margin-top: 8px; color: var(--text-muted); font-size: 12px; }
.feature-events { background: color-mix(in srgb, var(--brand) 4%, var(--surface)); }
.feature-events > .host-earn-cta { background: var(--brand); color: white; padding: 12px 18px; border-radius: 10px; font-weight: 650; }
.community-create { background: var(--surface); }

.welcome { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 23px 28px; border-radius: 16px; background: var(--brand); color: white; }
.welcome h2 { font-size: 24px; line-height: 1.3; font-weight: 700; letter-spacing: -.5px; margin: 0 0 6px; color: white; }
.welcome p { font-size: 14px; color: #fff; opacity: .9; }
.welcome-cta { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 16px; min-height: 44px; border-radius: 10px; background: var(--surface, white); color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 650; white-space: nowrap; }
.feature-empty { display: flex; align-items: center; gap: 22px; flex: 1 0 100%; width: 100%; padding: 25px 28px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
.feature-empty-icon { display: grid; place-items: center; width: 58px; height: 58px; border-radius: 16px; flex-shrink: 0; color: var(--row-ink, var(--brand-ink, var(--brand))); background: var(--row-bg, color-mix(in srgb, var(--brand) 7%, var(--surface))); }
.feature-empty > div { flex: 1; min-width: 0; }
.feature-empty h3 { font-size: 16px; font-weight: 650; margin: 0 0 6px; }
.feature-empty p { font-size: 13px; line-height: 1.6; color: var(--text-muted); max-width: 530px; }
.feature-empty > a { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; min-height: 44px; white-space: nowrap; color: var(--brand-ink, var(--brand)); }
.feature-memberships .feature-business-cta { padding: 10px 16px; border-radius: 9px; background: #0b6655; color: #fff; justify-content: center; white-space: normal; }
.feature-memberships .feature-business-cta:hover { background: #087360; }
.feature-memberships .feature-business-cta:focus-visible { outline: 2px solid var(--text); outline-offset: 3px; }
.home-loading { text-align: center; padding: 50px 20px; color: var(--text-muted); }
.host-card { width: 230px; display: flex; flex-direction: column; justify-content: center; gap: 9px; padding: 22px; background: color-mix(in srgb, var(--brand) 5%, var(--surface)); border: 1px solid var(--border); border-radius: 16px; text-decoration: none; }
.host-plus { color: var(--brand-ink, var(--brand)); margin-bottom: 10px; }
.host-title { font-size: 17px; font-weight: 700; color: var(--text); }
.host-sub { font-size: 13px; color: var(--text-muted); line-height: 1.6; }
.comm-card { display: flex; flex-direction: column; width: 280px; padding: 22px; border: 1px solid var(--border); background: var(--surface); border-radius: 14px; text-decoration: none; }
.comm-card:hover { border-color: var(--brand); }
.comm-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; }
.comm-av { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 50%; background-size: cover; background-position: center; color: white; font-size: 22px; font-weight: 700; }
.comm-lock { display: flex; align-items: center; gap: 4px; font-size: 11px; color: var(--text-muted); }
.comm-name { font-size: 16px; font-weight: 650; line-height: 1.4; margin-bottom: 10px; }
.comm-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted); }
.comm-sport { color: var(--brand-ink, var(--brand)); }
.comm-count { display: flex; align-items: center; gap: 4px; }
.comm-cta { display: flex; align-items: center; gap: 7px; margin-top: auto; padding-top: 20px; font-size: 12px; color: var(--brand-ink, var(--brand)); font-weight: 600; }
.bh-head, .cities-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.bh-title, .cities-head h2, .explore-h, .home-faq h2 { font-size: 23px; font-weight: 700; letter-spacing: -.4px; line-height: 1.3; margin: 0; }
.bh-title-ico, .home-faq h2 > svg, .explore-h > svg { display: none; }
.bh-sub { color: var(--text-muted); font-size: 14px; margin-top: 6px; }
.bh-viewall, .cities-all { display: flex; align-items: center; gap: 7px; color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 600; white-space: nowrap; min-height: 44px; }
.bh-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
.bh-card { display: flex; flex-direction: column; text-decoration: none; min-width: 0; }
.bh-cover { aspect-ratio: 16 / 9; border-radius: 14px; background: var(--border); background-size: cover; background-position: center; display: grid; place-items: center; }
.bh-cover-ph { color: var(--text-muted); }
.bh-body { padding: 16px 0 0; display: flex; flex-direction: column; flex: 1; }
.bh-tags { display: flex; gap: 8px; margin-bottom: 8px; }
.bh-tag { font-size: 11px; color: var(--brand-ink, var(--brand)); font-weight: 600; }
.bh-card-title { font-size: 18px; font-weight: 650; line-height: 1.4; margin: 0 0 8px; }
.bh-card:hover .bh-card-title { color: var(--brand-ink, var(--brand)); }
.bh-excerpt { font-size: 13px; line-height: 1.65; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: 0 0 16px; }
.bh-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: auto; }
.bh-meta { font-size: 11px; color: var(--text-muted); }
.bh-read { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--brand-ink, var(--brand)); }
.home-faq { max-width: 780px; width: 100%; }
.home-faq h2 { margin-bottom: 12px; }
.home-faq-item { border-bottom: 1px solid var(--border); }
.home-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 17px 0; list-style: none; cursor: pointer; font-size: 14px; font-weight: 550; }
.home-faq-item summary::-webkit-details-marker { display: none; }
.home-faq-item summary::after { content: '+'; color: var(--text-muted); font-size: 20px; }
.home-faq-item[open] summary::after { content: '−'; }
.home-faq-item p { font-size: 14px; line-height: 1.7; color: var(--text-muted); padding: 0 20px 18px 0; max-width: 720px; }
.faq-more { color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 600; padding: 16px 0 0; min-height: 44px; }
.explore-rows { display: flex; flex-direction: column; gap: 18px; margin-top: 20px; }
.explore-row { display: flex; flex-direction: column; gap: 10px; }
.explore-label { font-size: 13px; font-weight: 600; color: var(--text-muted); }
.explore-chips, .cities-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.chip, .city { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; min-height: 44px; border: 1px solid var(--border); border-radius: 9px; text-decoration: none; font-size: 13px; }
.chip:hover, .city:hover { color: var(--brand-ink, var(--brand)); border-color: var(--brand); }
.chip-all { color: var(--brand-ink, var(--brand)); border-color: transparent; }
.city-main { display: inline-flex; align-items: baseline; gap: 8px; }
.city-name { font-weight: 600; }
.city-country, .city-count { font-size: 12px; color: var(--text-muted); }
.home a:focus-visible, .home button:focus-visible, .home summary:focus-visible { outline: 2px solid var(--brand); outline-offset: 4px; }
.home :deep(.sr-title-ico) { display: block; }
.home-community { width: 300px; }
@media(max-width:760px) { .home-community { width: min(290px, 82vw); } }
.home :deep(.sr-title) { font-size: 23px; font-weight: 700; letter-spacing: -.4px; }
.home :deep(.sr-subtitle) { font-size: 14px; margin-top: 6px; }
.home :deep(.sr-head) { margin-bottom: 20px; }
@media (max-width: 760px) {
  .home { gap: 26px; padding-top: 0; }
  .welcome { padding: 20px; flex-wrap: wrap; gap: 15px; }
  .welcome h2 { font-size: 21px; }
  .welcome p { font-size: 13px; line-height: 1.6; }
  .welcome-cta { font-size: 12px; padding: 10px 13px; }
  .feature-empty { display: grid; grid-template-columns: 42px minmax(0, 1fr); padding: 20px; gap: 14px; align-items: start; }
  .feature-empty-icon { width: 42px; height: 42px; border-radius: 12px; }
  .feature-empty-icon svg { width: 23px; height: 23px; }
  .feature-empty h3 { font-size: 14px; line-height: 1.5; }
  .feature-empty p { font-size: 12px; }
  .feature-empty > a { grid-column: 2; margin-inline-start: 0; justify-self: start; }
  .feature-events > .host-earn-cta { flex-basis: auto; padding: 11px 16px; }
  .feature-empty-note { font-size: 11px; line-height: 1.6; }
  .home :deep(.sr-title), .bh-title, .cities-head h2, .explore-h, .home-faq h2 { font-size: 20px; letter-spacing: -.4px; }
  .home :deep(.sr-subtitle), .bh-sub { font-size: 12px; line-height: 1.5; }
  .home :deep(.sr-head) { margin-bottom: 13px; }
  .home :deep(.sr-viewall) { font-size: 12px; padding-top: 3px; }
  .home :deep(.sr-track) { margin-inline-end: -16px; padding-inline-end: 16px; }
  .comm-card { width: 255px; padding: 18px; }
  .bh-head { align-items: flex-start; gap: 12px; margin-bottom: 16px; }
  .bh-card-title { font-size: 16px; }
  .home-faq-item summary { font-size: 13px; line-height: 1.5; }
  .home-faq-item p { font-size: 13px; }
}
/* Recruitment fills the unused category row when local supply is small. */
.venue-recruit { flex:1 0 300px; min-width:0; display:flex; flex-direction:column; align-items:flex-start; justify-content:center; padding:24px; gap:14px; border:1px solid var(--border); border-radius:16px; background:var(--surface); }
.venue-recruit-icon { display:grid; place-items:center; width:48px; height:48px; border-radius:12px; background:color-mix(in srgb, #0b6655 10%, var(--surface)); color:var(--success-ink,#0b6655); flex-shrink:0; }
.venue-recruit h3 { margin:0 0 8px; font-size:20px; line-height:1.3; color:var(--text); }.venue-recruit p { margin:0; font-size:14px; line-height:1.6; max-width:62ch; color:var(--text-muted); }
.venue-recruit-cta { display:inline-flex; align-items:center; justify-content:center; gap:12px; min-height:44px; padding:10px 18px; border-radius:8px; background:#0b6655; color:#fff; font-size:14px; font-weight:650; text-decoration:none; }.venue-recruit-cta:hover { background:#087360; }.venue-recruit-cta:focus-visible { outline:3px solid var(--brand-ink); outline-offset:4px; }
.venue-recruit-empty { flex:1 0 100%; flex-direction:row; align-items:center; gap:22px; padding:28px; }.venue-recruit-empty .venue-recruit-copy { flex:1; }.venue-recruit-empty .venue-recruit-cta { flex-shrink:0; }
@media(max-width:760px) { .home-categories-sparse :deep(.sr-track) { flex-wrap:wrap; margin-inline-end:0; padding-inline-end:2px; }.venue-recruit { flex-basis:100%; padding:20px; }.venue-recruit-empty { display:grid; grid-template-columns:44px minmax(0,1fr); gap:14px; }.venue-recruit h3 { font-size:18px; }.venue-recruit-empty .venue-recruit-cta { grid-column:2; justify-self:start; }.venue-recruit-icon { width:44px; height:44px; } }

/* One lead story and two short reads keep the home guide section scannable. */
.bloghome{border-top:1px solid var(--border);padding-top:28px;margin-top:32px}.bh-head{margin-bottom:20px}.bh-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:0 30px}.bh-card{display:grid;grid-template-columns:132px 1fr;gap:18px;align-items:start;padding:20px 0;border:0;border-bottom:1px solid var(--border);border-radius:0;box-shadow:none;background:transparent;overflow:hidden}.bh-card:hover{transform:none;box-shadow:none}.bh-featured{grid-row:span 2;grid-template-columns:1fr;padding:0;border-bottom:0;gap:14px}.bh-cover{height:100px;aspect-ratio:auto;border-radius:10px}.bh-featured .bh-cover{height:210px}.bh-body{padding:0;min-width:0}.bh-card-title{font-size:17px;line-height:1.4;margin:5px 0 0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.bh-featured .bh-card-title{font-size:21px;line-height:1.3}.bh-excerpt{font-size:14px;line-height:1.55;-webkit-line-clamp:2;max-width:56ch;margin:10px 0 0}.bh-tags{margin:0 0 5px}.bh-tag{font-size:11px;background:var(--brand-tint);color:var(--brand-ink);border-radius:4px;padding:3px 7px}.bh-foot{margin-top:14px;gap:10px}.bh-read{font-size:12px}.bh-meta{font-size:12px}.bh-card:focus-visible{outline:3px solid var(--brand-ink);outline-offset:5px}
/* Section action buttons follow their section's colour (set on ServiceRow's tone):
   venues blue, events amber, communities green, memberships violet. */
.venue-recruit-cta, .feature-events > .host-earn-cta, .feature-memberships .feature-business-cta, .feature-communities > a { display:inline-flex; align-items:center; justify-content:center; gap:10px; min-height:44px; padding:10px 18px; border-radius:9px; background:var(--row-cta, var(--brand)); color:var(--row-cta-text, #fff); font-size:14px; font-weight:650; text-decoration:none; white-space:nowrap; }
.venue-recruit-cta:hover, .feature-events > .host-earn-cta:hover, .feature-memberships .feature-business-cta:hover, .feature-communities > a:hover { background:var(--row-cta-hover, var(--row-cta, var(--brand))); }
.venue-recruit-cta:focus-visible, .feature-events > .host-earn-cta:focus-visible, .feature-memberships .feature-business-cta:focus-visible, .feature-communities > a:focus-visible { outline:2px solid var(--row-cta, var(--text)); outline-offset:3px; }
.venue-recruit-icon { color:var(--row-ink, var(--success-ink)); background:color-mix(in srgb, var(--row-ink, #0b6655) 12%, var(--surface)); }
.host-card { background:color-mix(in srgb, var(--row-ink, var(--brand)) 5%, var(--surface)); border-color:color-mix(in srgb, var(--row-ink, var(--brand)) 18%, var(--border)); }
.host-card:hover { border-color:var(--row-ink, var(--brand)); }
.host-plus, .comm-cta { color:var(--row-ink, var(--brand-ink, var(--brand))); }
@media(max-width:760px){.bh-grid{grid-template-columns:1fr;gap:0}.bh-featured{grid-row:auto;padding-bottom:22px;border-bottom:1px solid var(--border)}.bh-featured .bh-cover{height:200px}.bh-card:not(.bh-featured){grid-template-columns:100px 1fr;gap:14px}.bh-card:not(.bh-featured) .bh-cover{height:90px}.bh-card-title{font-size:16px}.bh-featured .bh-card-title{font-size:20px}.bh-foot{flex-wrap:wrap}.bh-sub{font-size:13px}}

</style>
