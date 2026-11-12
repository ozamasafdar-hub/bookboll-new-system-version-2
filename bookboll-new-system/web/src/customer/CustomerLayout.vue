<script setup>
import { ref, computed, onMounted, onUnmounted, watch, provide } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { selectedCity, selectedCountry, customer, isCustomer, customerSignOut, customerTheme, toggleCustomerTheme, locationPrompted, markLocationPrompted } from '../lib/customerSession.js'
import { activeBrand, isWhiteLabel, brandShows, brandPageEnabled, platformBrand, marketplaceUrl } from '../lib/brand.js'
import { useStorefrontTracking } from '../lib/tracking.js'
import { isEmbedded, isScopedEmbed } from '../lib/embed.js'
import { slugifyCity } from '../lib/cityPages.js'
import { unreadMessages, refreshUnread } from '../lib/social.js'
import LocationGate from './components/LocationGate.vue'
import AuthGate from './components/AuthGate.vue'
import { ensureSignedIn } from '../lib/authGate.js'
import CheckoutResumeCard from './components/CheckoutResumeCard.vue'
import AppDownloadCard from '../components/AppDownloadCard.vue'
import { appPromptContext, insideInstalledApp } from '../lib/mobileApps.js'
import BracketUpdatePrompt from './components/BracketUpdatePrompt.vue'
import ExploreHub from './components/ExploreHub.vue'
import NotificationBell from './components/NotificationBell.vue'
import MobileMenu from './components/MobileMenu.vue'
import { Menu } from 'lucide-vue-next'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import BrandMark from '../components/BrandMark.vue'
import { adminLoginUrl } from '../lib/crossApp.js'
import { MapPin, ChevronDown, User, LogOut, Shield, Home, Search, Building2, Dumbbell, UsersRound, Trophy, CalendarCheck, Moon, Sun, Settings, Newspaper, MessageCircle, Globe2, MapPinOff, CalendarDays, Info, Phone } from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()
const isVenueRoute = computed(() => /^\/(?:ar\/)?(facilities|venues|listings)(\/|$)/.test(route.path))
const showGate = ref(false)
const menuOpen = ref(false)
const mobileMenuOpen = ref(false)
watch(mobileMenuOpen, (open) => { if (open) menuOpen.value = false })
watch(() => route.fullPath, () => { menuOpen.value = false })

// FLOW pages: the visitor is mid-process (booking, joining a membership, hosting) — the
// page's own docked action bar owns the bottom edge, so the mobile bottom nav gets out
// of the way. Desktop side-nav is unaffected (CSS is phone-only).
const inFlow = computed(() => /^\/(listings|programs|games|tournaments)\/[^/]+/.test(route.path) || route.path === '/host-game')

// Scope the shared dark palette to the storefront while this shell is mounted.
// The theme preference persists across areas; cust-shell keeps it off the admin dashboard.
onMounted(() => document.documentElement.classList.add('cust-shell'))
onUnmounted(() => document.documentElement.classList.remove('cust-shell'))

// Storefront analytics/pixels. Runs ONLY here (the customer shell), so tags never load in the
// admin dashboard. Injects the ACTIVE brand's config: a provider's own tags on their site, the
// marketplace's tags on the apex — so a provider only ever measures their own storefront.
useStorefrontTracking()

// Embedded widget mode (Embed & Widgets snippets). `embedded` strips marketplace chrome
// (Explore hub, admin login); `scopedEmbed` (venues/programs widgets) also drops the global
// side-nav so the iframe stays locked to its one flow. Captured once at boot.
const embedded = isEmbedded()
const scopedEmbed = isScopedEmbed()
const appContext = computed(() => appPromptContext({ audience: 'customer', path: route.path, signedIn: isCustomer.value, whiteLabel: isWhiteLabel.value, embedded, accountId: customer.value?.id }))

const appDownloadCard = ref(null)
const appDownloadAvailable = ref(false)
onMounted(() => { appDownloadAvailable.value = !insideInstalledApp(window, navigator) })
provide('customerAppDownload', { available: computed(() => !!appContext.value && appDownloadAvailable.value), open: () => appDownloadCard.value?.open() })

// "We don't serve here" prompt (white-label only): show when the visitor's chosen city
// isn't one this provider serves, or the provider has no listed cities at all.
const brandRegions = computed(() => activeBrand.value?.regions || null)
const servedCityIds = computed(() => new Set((brandRegions.value?.cities || []).map(c => c.id)))
const servedCount = computed(() => (brandRegions.value?.cities || []).length)
const servesNowhere = computed(() => isWhiteLabel.value && !!brandRegions.value && servedCount.value === 0)
const unservedHere = computed(() => isWhiteLabel.value && !!brandRegions.value && !!selectedCity.value && !servedCityIds.value.has(selectedCity.value.id))
const showLocPrompt = computed(() => servesNowhere.value || unservedHere.value)
const brandLabel = computed(() => activeBrand.value?.name || 'This site')
const bookbollCityUrl = computed(() => marketplaceUrl(selectedCity.value ? `/venues/${slugifyCity(selectedCity.value.name)}` : '/'))

// First visit (apex only): auto-open the location modal once, AFTER the homepage has painted,
// so the visitor can confirm/correct the auto-picked city. It's a non-blocking overlay (content
// stays behind it) and dismissible; we persist that we've prompted so it never re-opens for
// returning users or SEO crawlers. The location chip still opens it on demand any time.
// ...except on the legal pages: /terms and /privacy-policy are opened directly
// from app-store listings and consent links, and the visitor came to READ them —
// a city picker over the text helps nobody (and app-store reviewers open the
// privacy policy cold). City selection is irrelevant to both pages anyway.
const LEGAL_PATHS = ['/terms', '/privacy-policy']
const onLegalPage = computed(() => LEGAL_PATHS.includes(route.path.replace(/^\/ar(?=\/|$)/, '')))
onMounted(() => router.isReady().then(() => {
  if (!isWhiteLabel.value && !locationPrompted.value && !onLegalPage.value) showGate.value = true
}))

// Unread DM badge — poll lightly while signed in on the apex marketplace (social = apex only).
let unreadTimer = null
function startUnread() {
  if (unreadTimer) return
  if (isCustomer.value && !isWhiteLabel.value) {
    refreshUnread(customer.value.id)
    unreadTimer = setInterval(() => refreshUnread(customer.value?.id), 30000)
  }
}
onMounted(startUnread)
watch(() => customer.value?.id, () => { unreadMessages.value = 0; startUnread() })
onUnmounted(() => { if (unreadTimer) clearInterval(unreadTimer) })

function onGateDone() { showGate.value = false; markLocationPrompted() }
function onGateDismiss() { showGate.value = false; markLocationPrompted() }
function initials(n) { return (n || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
function signOut() { customerSignOut(); menuOpen.value = false; router.push('/') }
</script>

<template>
  <div class="cust" :class="{ 'cust-embed': embedded, 'cust-embed-scoped': scopedEmbed, 'cust-inflow': inFlow, 'cust-marketplace': !isWhiteLabel, 'cust-provider': isWhiteLabel }">
    <a href="#main-content" class="cust-skip">Skip to content</a>
    <!-- Header -->
    <header class="cust-header">
      <div class="cust-header-inner">
        <button v-if="!scopedEmbed" type="button" class="cust-mobile-menu" aria-label="Open menu" :aria-expanded="mobileMenuOpen" aria-controls="mobile-menu" @click="mobileMenuOpen = true">
          <Menu :size="24" />
        </button>
        <div class="cust-logohead">
          <router-link to="/" class="cust-logo" :aria-label="isWhiteLabel ? activeBrand.name : (platformBrand.name || 'Bookboll')" data-no-translate>
            <span v-if="isWhiteLabel ? activeBrand.logo : platformBrand.logo" class="cust-logo-img" :style="{ backgroundImage: `url(${isWhiteLabel ? activeBrand.logo : platformBrand.logo})` }"></span>
            <span v-else-if="isWhiteLabel" class="cust-logo-mark">{{ initials(activeBrand.name) }}</span>
            <span v-else class="cust-logo-mark"><BrandMark /></span>
            <span class="cust-logo-name">{{ isWhiteLabel ? activeBrand.name : (platformBrand.name || 'Bookboll') }}</span>
          </router-link>
          <!-- Free white-label sites: attribution right in the header (premium
               white-label keeps only the footer credit) -->
          <a v-if="isWhiteLabel && !activeBrand.whitelabelBranding" class="cust-pow-head"
             :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
            Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b>
          </a>
        </div>

        <button class="cust-city" @click="showGate = true" :title="$t('header.changeLocation')" :aria-label="`${$t('header.changeLocation')}: ${selectedCity?.name || $t('header.setLocation')}`">
          <MapPin :size="15" />
          <span class="cust-city-name">{{ selectedCity?.name || $t('header.setLocation') }}</span>
          <ChevronDown :size="14" />
        </button>

        <div class="cust-header-right">
          <LanguageSwitcher class="cust-language" compact />
          <button class="cust-theme" @click="toggleCustomerTheme" :title="customerTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" :aria-label="customerTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'">
            <Sun v-if="customerTheme === 'dark'" :size="18" />
            <Moon v-else :size="18" />
          </button>
          <router-link v-if="isCustomer && !isWhiteLabel" to="/messages" class="cust-msgs" title="Messages" aria-label="Messages">
            <MessageCircle :size="20" />
            <span v-if="unreadMessages" class="cust-msgs-badge">{{ unreadMessages > 9 ? '9+' : unreadMessages }}</span>
          </router-link>
          <NotificationBell v-if="isCustomer && !isWhiteLabel && !mobileMenuOpen" @click="menuOpen = false" />
          <template v-if="isCustomer">
            <button type="button" class="cust-acct" aria-label="Account menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen" @keydown.esc="menuOpen = false">
              <div class="cust-avatar" :class="{ img: customer.avatar_url }" :style="customer.avatar_url ? { backgroundImage: `url(${customer.avatar_url})` } : {}" data-no-translate><span v-if="!customer.avatar_url">{{ initials(customer.full_name) }}</span></div>
              <span class="cust-acct-name" data-no-translate>{{ (customer.full_name || '').split(' ')[0] }}</span>
            </button>
            <div v-if="menuOpen" class="cust-menu" @mouseleave="menuOpen = false">
              <router-link v-if="!isWhiteLabel" :to="`/u/${customer.id}`" class="cust-menu-item" @click="menuOpen=false"><User :size="15" />{{ $t('account.myProfile') }}</router-link>
              <router-link v-if="!isWhiteLabel" to="/rewards" class="cust-menu-item" @click="menuOpen=false"><Trophy :size="15" />{{ $t('account.myRewards') }}</router-link>
              <router-link to="/my-bookings" class="cust-menu-item" @click="menuOpen=false"><CalendarCheck :size="15" />{{ $t('account.myBookings') }}</router-link>

              <router-link v-if="!isWhiteLabel" to="/my-games" class="cust-menu-item" @click="menuOpen=false"><CalendarDays :size="15" />{{ $t('account.myEvents') }}</router-link>
              <router-link v-if="!isWhiteLabel" to="/my-communities" class="cust-menu-item" @click="menuOpen=false"><UsersRound :size="15" />My Communities</router-link>
              <router-link to="/account" class="cust-menu-item" @click="menuOpen=false"><Settings :size="15" />{{ $t('account.settings') }}</router-link>
              <button class="cust-menu-item" @click="signOut"><LogOut :size="15" />{{ $t('account.signOut') }}</button>
            </div>
          </template>
          <button v-else type="button" class="cust-signin" @click="ensureSignedIn()"><User :size="16" />{{ $t('account.signIn') }}</button>
        </div>
      </div>


      <!-- White-label: traditional business top navigation (marketplace keeps its side nav) -->
      <nav v-if="isWhiteLabel && !scopedEmbed" class="cust-topnav" aria-label="Site">
        <router-link to="/" class="cust-topnav-link" exact-active-class="on">{{ $t('nav.home') }}</router-link>
        <router-link v-if="brandShows('venues')" to="/facilities" class="cust-topnav-link" :class="{ on: isVenueRoute }">Listings</router-link>
        <router-link v-if="brandShows('programs')" to="/programs" class="cust-topnav-link" active-class="on">{{ $t('nav.memberships') }}</router-link>
        <router-link v-if="brandShows('blog')" to="/blog" class="cust-topnav-link" active-class="on">Blog</router-link>
        <router-link v-if="brandPageEnabled('about')" to="/about" class="cust-topnav-link" active-class="on">About</router-link>
        <router-link v-if="brandPageEnabled('contact')" to="/contact" class="cust-topnav-link" active-class="on">Contact</router-link>
      </nav>

    <!-- Marketplace navigation (hidden in flow-scoped embeds so the widget can't wander) -->
    <nav v-if="!scopedEmbed && !isWhiteLabel" class="cust-sidenav" aria-label="Main navigation">
      <router-link to="/" class="cust-sidenav-link" exact-active-class="active"><Home :size="23" /><span>{{ $t('nav.home') }}</span></router-link>
      <router-link to="/search" class="cust-sidenav-link" active-class="active"><Search :size="23" /><span>Search</span></router-link>
      <router-link v-if="brandShows('venues')" to="/facilities" class="cust-sidenav-link" :class="{ active: isVenueRoute }"><Building2 :size="23" /><span>{{ $t('nav.venues') }}</span></router-link>
      <router-link v-if="isWhiteLabel && brandShows('venues')" to="/locations" class="cust-sidenav-link" active-class="active"><MapPin :size="23" /><span>Locations</span></router-link>
      <router-link v-if="brandShows('events')" to="/games" class="cust-sidenav-link" active-class="active"><CalendarDays :size="23" /><span>{{ $t('nav.events') }}</span></router-link>
      <router-link v-if="!isWhiteLabel" to="/communities" class="cust-sidenav-link" active-class="active"><UsersRound :size="23" /><span>Community</span></router-link>
      <router-link v-if="brandShows('programs')" to="/programs" class="cust-sidenav-link cust-nav-memberships" active-class="active"><Dumbbell :size="23" /><span>{{ $t('nav.memberships') }}</span></router-link>
      <router-link v-if="brandShows('blog')" to="/blog" class="cust-sidenav-link cust-nav-blog" active-class="active"><Newspaper :size="23" /><span>Blog</span></router-link>
      <router-link v-if="isWhiteLabel && brandPageEnabled('about')" to="/about" class="cust-sidenav-link" active-class="active"><Info :size="23" /><span>About</span></router-link>
      <router-link v-if="isWhiteLabel && brandPageEnabled('contact')" to="/contact" class="cust-sidenav-link" active-class="active"><Phone :size="23" /><span>Contact</span></router-link>
    </nav>
    <nav v-if="!scopedEmbed && isWhiteLabel" class="cust-provider-nav" aria-label="Main navigation">
      <router-link to="/" exact-active-class="active"><Home :size="21" /><span>Home</span></router-link>
      <router-link v-if="brandShows('venues')" to="/facilities" :class="{ active: isVenueRoute }"><Building2 :size="21" /><span>Listings</span></router-link>
      <router-link v-if="brandShows('programs')" to="/programs" active-class="active"><Dumbbell :size="21" /><span>Memberships</span></router-link>
      <router-link v-if="brandPageEnabled('contact')" to="/contact" active-class="active"><Phone :size="21" /><span>Contact</span></router-link>
    </nav>
    </header>

    <main class="cust-main" id="main-content" tabindex="-1">
      <!-- White-label: this provider doesn't serve the visitor's city → point to Bookboll. -->
      <div v-if="showLocPrompt" class="cns">
        <div class="cns-ico"><MapPinOff :size="22" /></div>
        <div class="cns-body">
          <div class="cns-title">
            <template v-if="servesNowhere">{{ brandLabel }} has no services listed yet</template>
            <template v-else>{{ brandLabel }} doesn’t have any services in {{ selectedCity?.name }}<template v-if="selectedCountry?.name">, {{ selectedCountry.name }}</template></template>
          </div>
          <div class="cns-sub">
            <template v-if="servesNowhere">Browse thousands of venues and classes on Bookboll instead.</template>
            <template v-else-if="servedCount">Pick a city {{ brandLabel }} operates in, or find services in {{ selectedCity?.name }} on Bookboll’s main site.</template>
            <template v-else>Find services in {{ selectedCity?.name }} on Bookboll’s main site.</template>
          </div>
        </div>
        <div class="cns-actions">
          <button v-if="!servesNowhere && servedCount" type="button" class="cns-btn-ghost" @click="showGate = true"><MapPin :size="15" /> Choose a city</button>
          <a class="cns-btn" :href="bookbollCityUrl"><Globe2 :size="15" /> {{ servesNowhere ? 'Browse Bookboll' : `Find ${selectedCity?.name || 'services'} on Bookboll` }}</a>
        </div>
      </div>

      <router-view />
      <AppDownloadCard ref="appDownloadCard" :visit-key="route.path" :account-id="customer?.id" audience="customer" :context="appContext" :blocked="showGate || mobileMenuOpen || menuOpen" />
    </main>

    <ExploreHub v-if="!embedded" />

    <footer class="cust-footer">
      <div class="cust-footer-inner">
        <span v-if="isWhiteLabel" class="cust-foot-brand">© {{ activeBrand.name }}
          <a class="cust-pow" :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
            <span v-if="platformBrand.logo" class="cust-pow-logo" :style="{ backgroundImage: `url(${platformBrand.logo})` }"></span>
            <span v-else class="cust-pow-mark"><BrandMark /></span>
            Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b>
          </a>
        </span>
        <span v-else>© {{ platformBrand.name || 'Bookboll' }}</span>
        <span v-if="!embedded" class="cust-foot-legal">
          <router-link to="/terms">Terms &amp; Conditions</router-link>
          <router-link to="/privacy-policy">Privacy Policy</router-link>
        </span>
        <a v-if="!embedded" :href="adminLoginUrl()" class="cust-admin-link"><Shield :size="13" /> {{ $t('footer.adminLogin') }}</a>
      </div>
    </footer>

    <!-- Free provider sites carry a visible, clickable Bookboll badge; premium
         white-label sites keep only the small footer credit above. -->
    <a v-if="isWhiteLabel && !activeBrand.whitelabelBranding && !embedded" class="cust-powbadge"
       :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
      <span v-if="platformBrand.logo" class="cust-pow-logo" :style="{ backgroundImage: `url(${platformBrand.logo})` }"></span>
      <span v-else class="cust-pow-mark">BB</span>
      <span>Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b></span>
    </a>

    <MobileMenu v-if="!scopedEmbed" :open="mobileMenuOpen" @close="mobileMenuOpen = false" @signin="ensureSignedIn()" @signout="signOut" />
    <LocationGate :open="showGate" @done="onGateDone" @dismiss="onGateDismiss" />
    <AuthGate />
    <CheckoutResumeCard />
    <!-- Tournament hosts: auto-popup when a finished game still needs its result -->
    <BracketUpdatePrompt />
  </div>
</template>

<style scoped>
.cust { min-height: 100vh; display: flex; flex-direction: column; background: var(--canvas, #f8fafc); }

.cust-header { position: sticky; top: 0; z-index: 100; background: var(--surface, #ffffff); border-bottom: 1px solid var(--border, #e2e8f0); box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.cust-header-inner { max-width: 1100px; margin: 0 auto; padding: 12px 18px; display: flex; align-items: center; gap: 14px; }
.cust-logohead { display: flex; flex-direction: column; min-width: 0; }
.cust-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
/* Header attribution — sits under the business name, aligned past the logo mark */
.cust-pow-head { font-size: 10.5px; font-weight: 600; color: var(--text-light, #94a3b8); text-decoration: none; padding-inline-start: 45px; margin-top: -3px; white-space: nowrap; }
.cust-pow-head b { color: var(--text-muted, #64748b); font-weight: 800; }
.cust-pow-head:hover, .cust-pow-head:hover b { color: var(--brand-ink, var(--brand)); }
.cust-logo-mark { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--brand-accent), var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.cust-logo-img { width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px; background-size: cover; background-position: center; background-color: var(--surface, #ffffff); box-shadow: 0 0 0 1px var(--border, #e2e8f0); }
.cust-logo-name { font-size: 19px; font-weight: 800; color: var(--text, #0f172a); }

.cust-city { display: flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; background: var(--canvas, #f8fafc); cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--text-secondary, #334155); }
.cust-city:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cust-city-name { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.cust-header-right { margin-inline-start: auto; position: relative; display: flex; align-items: center; gap: 10px; }
.cust-theme { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #334155); cursor: pointer; }
.cust-theme:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cust-msgs { position: relative; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #334155); cursor: pointer; text-decoration: none; }
.cust-msgs:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cust-msgs-badge { position: absolute; top: -3px; right: -3px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.cust-menu-badge { margin-left: auto; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
.cust-signin { display: flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 999px; background: #0f172a; color: #fff; text-decoration: none; font-size: 14px; font-weight: 700; border: none; cursor: pointer; font-family: inherit; }
.cust-signin:hover { background: #1e293b; }
.cust-acct { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px; }
.cust-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
.cust-avatar.img { background-size: cover; background-position: center; }
.cust-acct-name { font-size: 14px; font-weight: 700; color: var(--text, #0f172a); }
.cust-menu { position: absolute; top: 48px; inset-inline-end: 0; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,.14); overflow: hidden; min-width: 180px; }
.cust-menu-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 12px 16px; font-size: 14px; color: var(--text-secondary, #334155); text-decoration: none; background: none; border: none; cursor: pointer; text-align: left; }
.cust-menu-item:hover { background: var(--bg, #f1f5f9); }

/* White-label top navigation — traditional business navbar under the header row */
.cust-topnav { display: flex; justify-content: center; gap: 2px; padding: 0 16px; border-top: 1px solid var(--border, #f1f5f9); overflow-x: auto; scrollbar-width: none; }
.cust-topnav::-webkit-scrollbar { display: none; }
.cust-topnav-link { padding: 12px 16px; font-size: 14px; font-weight: 700; color: var(--text-secondary, #475569); text-decoration: none; white-space: nowrap; border-bottom: 2.5px solid transparent; transition: color .12s, border-color .12s; }
.cust-topnav-link:hover { color: var(--brand-ink, var(--brand)); }
.cust-topnav-link.on { color: var(--brand-ink, var(--brand)); border-bottom-color: var(--brand); }
@media (max-width: 767px) {
  .cust-topnav { justify-content: flex-start; padding: 0 6px; }
  .cust-topnav-link { padding: 11px 13px; font-size: 13.5px; }
}
/* The white-label hero bleeds to the viewport edges — clip any sub-pixel excess. */
.cust { overflow-x: clip; }

/* Floating side navigation card (anchored near the top-left). */
.cust-sidenav {
  position: fixed; inset-inline-start: 22px; top: 96px;
  z-index: 90; display: flex; flex-direction: column; gap: 6px; width: 120px;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #eef2f6); border-radius: 22px; padding: 13px;
  box-shadow: 0 20px 48px rgba(15,23,42,.13), 0 2px 6px rgba(15,23,42,.05);
}
.cust-sidenav-link {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px;
  width: 100%; box-sizing: border-box; padding: 17px 8px; border-radius: 16px;
  font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); text-decoration: none;
  text-align: center; line-height: 1.15; transition: background .14s, color .14s, transform .14s;
}
.cust-sidenav-link:hover { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); transform: translateY(-1px); }
.cust-sidenav-link.active { background: color-mix(in srgb, var(--brand) 12%, var(--surface, white)); color: var(--brand-ink, var(--brand)); }
.cust-sidenav-link svg { stroke-width: 2; }

.cust-main { flex: 1; max-width: 1100px; width: 100%; margin: 0 auto; padding: 18px; box-sizing: border-box; }

/* "We don't serve here" prompt */
.cns { display: flex; align-items: center; gap: 14px; background: var(--warning-bg, #fff7ed); border: 1px solid var(--warning-border, #fed7aa); border-radius: 16px; padding: 16px 18px; margin-bottom: 18px; flex-wrap: wrap; }
.cns-ico { width: 44px; height: 44px; border-radius: 12px; background: var(--warning-bg, #ffedd5); color: var(--warning-ink, #c2410c); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cns-body { flex: 1; min-width: 200px; }
.cns-title { font-size: 15.5px; font-weight: 800; color: #7c2d12; }
.cns-sub { font-size: 13px; color: var(--warning-ink, #9a3412); margin-top: 3px; line-height: 1.5; }
.cns-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cns-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 10px; background: #0f172a; color: #fff; font-size: 13.5px; font-weight: 800; text-decoration: none; white-space: nowrap; }
.cns-btn:hover { background: #1e293b; }
.cns-btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; border-radius: 10px; background: var(--surface, #ffffff); border: 1.5px solid var(--warning-border, #fed7aa); color: var(--warning-ink, #9a3412); font-size: 13.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.cns-btn-ghost:hover { border-color: var(--warning-border, #fdba74); }

/* Wide enough: reserve a left gutter so centered content clears the floating card. */
@media (min-width: 768px) {
  /* Symmetric gutters reserve room for the floating nav on the left AND keep the body
     content centred in the middle of the viewport (equal space both sides). */
  .cust { padding-inline: 164px; }
  /* Full-bleed the chrome bands across the whole viewport (escape the gutters). */
  .cust-header, .cust-footer { margin-inline: -164px; width: calc(100% + 328px); }
  /* Header content pinned to the corners; footer content stays centred with the body. */
  .cust-header-inner { max-width: none; padding-inline: 28px; }
}
/* Narrow screens: dock it to the bottom as a horizontal floating bar. */
@media (max-width: 767px) {
  .cust-sidenav {
    inset-inline-start: 50%; top: auto; bottom: 12px; transform: translateX(-50%);
    width: calc(100% - 16px); flex-direction: row; gap: 0; border-radius: 16px; padding: 6px;
    max-width: 440px;
  }
  .cust-sidenav-link { flex: 1 1 0; width: 0; min-width: 0; padding: 9px 1px; gap: 5px; font-size: 10px; }
  .cust-sidenav-link[href="/"] { flex-grow: 1; }
  .cust-sidenav-link[href="/communities"] { flex-grow: 1.4; }
  .cust-sidenav-link[href="/programs"] { flex-grow: 1.55; }
  .cust-sidenav-link span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cust-nav-blog { display: none; }   /* keep the floating bottom bar to the core tabs on mobile */

  /* Header: keep it to ONE tidy row that always fits. The logo flexes/truncates, the
     action cluster shrinks, and lower-priority text/controls collapse to icons. */
  .cust-header-inner { padding: 10px 12px; gap: 8px; }
  .cust-logohead { min-width: 0; flex-shrink: 1; }
  .cust-logo { min-width: 0; flex-shrink: 1; }
  .cust-pow-head { font-size: 9.5px; padding-inline-start: 0; margin-top: 0; }
  .cust-logo-name { font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cust-city { flex-shrink: 0; padding: 7px 9px; gap: 4px; }
  .cust-city-name { max-width: 64px; font-size: 12.5px; }
  .cust-header-right { gap: 4px; flex-shrink: 0; }
  .cust-theme, .cust-msgs { width: 36px; height: 36px; }
  .cust-acct-name { display: none; }            /* avatar is enough on mobile */
  .cust-signin { padding: 8px 12px; font-size: 13px; }

  /* Calm chrome: mute the two bordered icon rings this file owns so the city chip
     and avatar lead; icons stay tappable at 36px. */
  .cust-theme, .cust-msgs { border-color: transparent; background: transparent; }

  /* Tap targets: keep the visual circles small but extend the invisible hit area
     toward the 44px guideline (pure CSS, no markup change). */
  .cust-theme, .cust-msgs { position: relative; }
  .cust-theme::after, .cust-msgs::after { content: ''; position: absolute; inset: -6px; }

  /* Type-scale floor: unread badges up from 10px to the 11px minimum. */
  .cust-msgs-badge, .cust-menu-badge { font-size: 11px; min-width: 18px; height: 18px; }

  /* "We don't serve here" banner: slimmer card, helper copy clamped to 2 lines
     (title + both CTAs stay fully intact), CTAs stacked full-width so a long
     city name can never overflow the amber card. */
  .cns { padding: 14px; gap: 10px; }
  .cns-ico { width: 36px; height: 36px; }
  .cns-sub { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .cns-actions { width: 100%; flex-direction: column; align-items: stretch; gap: 8px; }
  .cns-btn, .cns-btn-ghost { white-space: normal; justify-content: center; text-align: center; }

  /* Clear the floating bottom nav bar so nothing hides behind it. */
  .cust-main { padding-bottom: 88px; }
  /* Flow pages (booking / membership signup): drop the bottom nav — the visitor
     is mid-process and the page's own action bar owns the bottom edge. */
  .cust-inflow .cust-sidenav { display: none; }
  /* Flow pages dock their action bar flush to the viewport bottom — reserve room so
     the last content (and any error text) never hides behind it. */
  .cust-inflow .cust-main { padding-bottom: calc(92px + env(safe-area-inset-bottom, 0px)); }
  /* The footer's last line must clear BOTH kinds of floating bottom chrome: the
     nav pill (browse pages, up to ~76px) and the docked action bars (flow pages,
     up to ~90px incl. safe area) — 116px covers the tallest with breathing room. */
  /* Extra specificity: the BASE .cust-footer-inner rule sits later in this file,
     so a same-specificity media rule loses on source order (the original 84px
     clearance silently never applied — the "footer hides behind the bars" bug). */
  .cust-footer .cust-footer-inner { padding-bottom: calc(116px + env(safe-area-inset-bottom, 0px)); }
}
/* Very small phones (360/344px): drop the brand wordmark, keep the logo mark, and
   tighten the header row so up to 5 right-cluster controls + the city chip always
   fit without horizontal overflow. The city label collapses to its pin+chevron chip
   (the chip's title attribute keeps it accessible and it still opens the gate). */
@media (max-width: 430px) {
  .cust-logo-name { display: none; }
  .cust-header-inner { padding: 10px 8px; gap: 6px; }
  .cust-header-right { gap: 2px; }
  .cust-theme, .cust-msgs { width: 32px; height: 32px; }
  .cust-avatar { width: 30px; height: 30px; }
  .cust-acct { padding: 2px; }
  .cust-city { flex-shrink: 1; min-width: 0; padding: 6px 8px; }
  .cust-city-name { max-width: 64px; }
}

.cust-footer { border-top: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); }
.cust-footer-inner { max-width: 1100px; margin: 0 auto; padding: 18px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--text-light, #94a3b8); }
.cust-admin-link { display: flex; align-items: center; gap: 5px; color: var(--text-muted, #64748b); text-decoration: none; font-weight: 600; }
.cust-foot-legal { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.cust-foot-legal a { color: var(--text-muted, #64748b); text-decoration: none; font-weight: 600; }
.cust-foot-legal a:hover { color: var(--brand-ink, var(--brand)); text-decoration: underline; }
.cust-admin-link:hover { color: var(--brand-ink, var(--brand)); }
.cust-foot-brand { display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; }
/* Footer credit — logo + clickable, present on every white-label site */
.cust-pow { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted, #64748b); text-decoration: none; font-size: 12.5px; }
.cust-pow:hover { color: var(--brand-ink, var(--brand)); }
.cust-pow b { color: var(--text-secondary, #334155); }
.cust-pow:hover b { color: var(--brand-ink, var(--brand)); }
.cust-pow-logo { width: 18px; height: 18px; border-radius: 5px; background-size: cover; background-position: center; flex-shrink: 0; }
.cust-pow-mark { width: 18px; height: 18px; border-radius: 5px; background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff; font-size: 9px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
/* Floating badge — free provider sites only */
.cust-powbadge { position: fixed; bottom: 14px; inset-inline-end: 14px; z-index: 60;
  display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px; border-radius: 999px;
  background: #0f172a; color: #cbd5e1; font-size: 12.5px; font-weight: 600; text-decoration: none;
  box-shadow: 0 6px 22px rgba(15,23,42,.28); }
.cust-powbadge b { color: #fff; }
.cust-powbadge:hover { background: #1e293b; }
@media (max-width: 767px) { .cust-powbadge { bottom: 10px; inset-inline-end: 10px; padding: 7px 11px; font-size: 12px; } }
.cust-powered { color: #cbd5e1; font-size: 12px; }

/* Embedded widget tweaks. Scoped embeds drop the side-nav, so reclaim its gutter and let the
   content use the full iframe width. Both embed modes slim the chrome. */
.cust-embed .cust-main { padding-top: 14px; }
@media (min-width: 768px) {
  .cust.cust-embed-scoped { padding-inline: 0; }
  .cust.cust-embed-scoped .cust-header,
  .cust.cust-embed-scoped .cust-footer { margin-inline: 0; width: 100%; }
}

/* Marketplace navigation and shared page width. */
.cust-skip { position: fixed; top: -80px; inset-inline-start: 16px; z-index: 200; padding: 12px 20px; background: var(--surface); color: var(--brand-ink, var(--brand)); border: 2px solid var(--brand); border-radius: 8px; }
.cust-skip:focus { top: 8px; }
.cust-marketplace .cust-header { box-shadow: none; }
.cust-marketplace { --customer-sticky-offset: 136px; }
.cust-main:focus { outline: none; }
.cust-marketplace .cust-main { max-width: 1200px; padding: 28px 24px 56px; }
.cust-marketplace .cust-header-inner { max-width: 1200px; padding: 16px 24px; }
.cust-marketplace .cust-logo-name { letter-spacing: -.4px; }
/* The city chip and the account button carry TEXT, so they read better unbordered
   next to the logo. The theme toggle is an icon button like Messages, the bell and
   the language switcher — all of which keep their ring — so it was flattened here
   by association and ended up the only bare icon in the row. The mobile rule above
   still mutes it, together with .cust-msgs, which is where flattening is deliberate. */
.cust-marketplace .cust-city { border: 0; background: transparent; border-inline-start: 1px solid var(--border); border-radius: 0; padding-inline: 18px 8px; margin-inline-start: 5px; }
.cust-marketplace .cust-signin { border-radius: 10px; padding: 11px 17px; font-weight: 600; }
.cust-marketplace .cust-acct { border: 0; background: transparent; font-family: inherit; }
.cust-marketplace .cust-sidenav-link:focus-visible { outline: 2px solid var(--brand); outline-offset: -2px; }
.cust-mobile-menu { display: none; }
@media (min-width: 768px) {
  .cust.cust-marketplace { padding-inline: 0; }
  .cust-marketplace .cust-header, .cust-marketplace .cust-footer { margin-inline: 0; width: 100%; }
  .cust-marketplace .cust-sidenav { position: static; flex-direction: row; max-width: 1200px; width: 100%; margin: 0 auto; padding: 0 24px; gap: 28px; border: 0; border-radius: 0; box-shadow: none; background: transparent; }
  .cust-marketplace .cust-sidenav-link { width: auto; flex-direction: row; justify-content: flex-start; padding: 14px 2px; gap: 8px; border-radius: 0; border-bottom: 3px solid transparent; font-size: 13px; font-weight: 600; white-space: nowrap; }
  .cust-marketplace .cust-sidenav-link svg { width: 18px; height: 18px; }
  .cust-marketplace .cust-sidenav-link:hover { transform: none; background: none; color: var(--brand-ink, var(--brand)); }
  .cust-marketplace .cust-sidenav-link.active { background: transparent; color: var(--brand-ink, var(--brand)); border-bottom-color: var(--brand); }
}
@media (max-width: 767px) {
  .cust-marketplace { --customer-sticky-offset: 80px; }
  .cust-marketplace .cust-header-inner { padding: 10px 12px; gap: 4px; }
  .cust-marketplace:not(.cust-embed-scoped) .cust-header-right > .cust-language,
  .cust-marketplace:not(.cust-embed-scoped) .cust-header-right > .cust-theme { display: none; }
  .cust-marketplace .cust-header-right { gap: 2px; }
  .cust-marketplace .cust-logo-name { display: none; }
  .cust-marketplace .cust-logohead { flex-shrink: 0; }
  .cust-marketplace .cust-logo { min-width: 44px; min-height: 44px; justify-content: center; }
  .cust-marketplace .cust-mobile-menu { display: grid; place-items: center; flex-shrink: 0; width: 44px; height: 44px; border: 0; border-radius: 10px; background: transparent; color: var(--text); cursor: pointer; }
  .cust-marketplace .cust-msgs, .cust-marketplace .cust-acct,
  .cust-marketplace .cust-header-right :deep(.nb-btn) { width: 44px; height: 44px; flex-shrink: 0; padding: 0; display: grid; place-items: center; border: 0; border-radius: 50%; background: transparent; color: var(--text-secondary); }
  .cust-marketplace .cust-msgs::after { content: none; }
  .cust-marketplace .cust-avatar { width: 32px; height: 32px; }
  .cust-marketplace .cust-mobile-menu:hover, .cust-marketplace .cust-acct:hover,
  .cust-marketplace .cust-msgs:hover, .cust-marketplace .cust-header-right :deep(.nb-btn:hover),
  .cust-marketplace .cust-header-right :deep(.nb-btn.on) { background: var(--bg); }
  .cust-marketplace .cust-mobile-menu:focus-visible, .cust-marketplace .cust-acct:focus-visible,
  .cust-marketplace .cust-msgs:focus-visible, .cust-marketplace .cust-header-right :deep(.nb-btn:focus-visible) { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: -2px; }
  .cust-marketplace .cust-msgs-badge, .cust-marketplace .cust-header-right :deep(.nb-badge) { top: 0; right: 0; }
  .cust-marketplace .cust-city-name { max-width: 90px; font-size: 13px; }
  .cust-marketplace .cust-city { min-height: 44px; min-width: 44px; flex-shrink: 1; }
  .cust-marketplace .cust-city svg { flex-shrink: 0; }
  .cust-marketplace .cust-nav-memberships { display: none; }
  .cust-marketplace .cust-main { padding: 20px 16px 94px; }
  .cust-marketplace.cust-inflow .cust-main { padding-bottom: calc(94px + env(safe-area-inset-bottom, 0px)); }
  .cust-marketplace .cust-city { padding-inline: 10px 0; margin-inline-start: 0; }
  .cust-marketplace .cust-signin { padding: 10px 12px; }
  .cust-marketplace .cust-sidenav { bottom: 0; inset-inline-start: 0; transform: none; max-width: none; width: 100%; border: 0; border-top: 1px solid var(--border); border-radius: 0; padding: 6px 10px calc(6px + env(safe-area-inset-bottom, 0px)); box-shadow: 0 -3px 14px #0f172a05; }
  .cust-marketplace .cust-sidenav-link { flex: 1 1 0; padding: 8px 0; gap: 4px; border-radius: 10px; font-weight: 600; font-size: 11px; }
  .cust-marketplace .cust-sidenav-link svg { width: 21px; height: 21px; }
  .cust-marketplace .cust-sidenav-link:hover { transform: none; }
  .cust-marketplace .cust-sidenav-link.active { background: color-mix(in srgb, var(--brand) 7%, var(--surface)); }
}
@media (max-width: 374px) {
  .cust-marketplace .cust-header-inner { padding-inline: 8px; }
  .cust-marketplace .cust-city-name { display: none; }
}
@media (min-width: 768px) and (max-width: 900px) {
  .cust-marketplace .cust-sidenav { gap: 16px; }
}

</style>

<style src="./marketplaceTheme.css"></style>
<style>
@import './providerTheme.css';
</style>
<style>
@media (min-width: 768px) {
  .cust .xh { margin-inline: -164px; width: calc(100% + 328px); }
  .cust.cust-marketplace .xh { margin-inline: 0; width: 100%; }
}
</style>
