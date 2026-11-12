import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { createHead } from '@unhead/vue'
import './style.css'
import './customer/discovery.css'
import './customer/communityJourney.css'
import App from './App.vue'
import { installUnsavedGuards } from './lib/unsavedGuard.js'
import { resolveBrand, isWhiteLabel, brandShows, moduleForPath, brandPageEnabled, brandSectionEnabled, activeBrand } from './lib/brand.js'
import { isScopedEmbed, embedAllows, embedHome } from './lib/embed.js'
import { ensureDefaultLocation, ensureBrandLocation } from './lib/customerSession.js'
import { i18n } from './lib/i18n.js'
import { initAutoTranslate } from './lib/autoTranslate.js'
import { resumeGoogleLogin } from './lib/googleAuth.js'
import { adminDashboardUrl, businessHomeUrl, businessSignupUrl } from './lib/crossApp.js'

import CustomerHome from './customer/pages/CustomerHome.vue'
import Facilities from './customer/pages/Facilities.vue'
import FacilityDetail from './customer/pages/FacilityDetail.vue'
import Locations from './customer/pages/Locations.vue'
import ListingDetail from './customer/pages/ListingDetail.vue'
import Games from './customer/pages/Games.vue'
import GameDetail from './customer/pages/GameDetail.vue'
import HostGame from './customer/pages/HostGame.vue'
import TournamentDetail from './customer/pages/TournamentDetail.vue'
import ManageRequests from './customer/pages/ManageRequests.vue'
import Communities from './customer/pages/Communities.vue'
import CommunityDetail from './customer/pages/CommunityDetail.vue'
import CreateCommunity from './customer/pages/CreateCommunity.vue'
import MyCommunities from './customer/pages/MyCommunities.vue'
import CustomerPrograms from './customer/pages/CustomerPrograms.vue'
import ProgramDetail from './customer/pages/ProgramDetail.vue'
import Rewards from './customer/pages/Rewards.vue'
import Account from './customer/pages/Account.vue'
import MyGames from './customer/pages/MyGames.vue'
import MyBookings from './customer/pages/MyBookings.vue'
import ThankYou from './customer/pages/ThankYou.vue'
import PaymentCancelled from './customer/pages/PaymentCancelled.vue'
import MyMemberships from './customer/pages/MyMemberships.vue'
import Messages from './customer/pages/Messages.vue'
import PublicProfile from './customer/pages/PublicProfile.vue'
import BlogIndex from './customer/pages/Blog.vue'
import BlogPost from './customer/pages/BlogPost.vue'
import CityVenues from './customer/pages/CityVenues.vue'
import CityDirectory from './customer/pages/CityDirectory.vue'
import CityPrograms from './customer/pages/CityPrograms.vue'
import MembershipDirectory from './customer/pages/MembershipDirectory.vue'
import Unsubscribe from './customer/pages/Unsubscribe.vue'
import Terms from './customer/pages/Terms.vue'
import Privacy from './customer/pages/Privacy.vue'
import AboutSite from './customer/pages/AboutSite.vue'
import ContactSite from './customer/pages/ContactSite.vue'
import PayBooking from './customer/pages/PayBooking.vue'
import MarketplaceSearch from './customer/pages/Search.vue'

const customer = (path, component) => ({ path, component, alias: path === '/' ? '/ar' : `/ar${path}`, meta: { area: 'customer' } })

function goBusiness(path = '/') {
  window.location.replace(businessHomeUrl(path))
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.path === from.path && to.hash === from.hash) return false
    return { top: 0 }
  },
  routes: [
    customer('/', CustomerHome),
    customer('/search', MarketplaceSearch),
    customer('/facilities', Facilities),
    customer('/facilities/:id/:slug?', FacilityDetail),
    customer('/locations', Locations),
    customer('/venues', CityDirectory),
    customer('/venues/:city', CityVenues),
    customer('/venues/:city/:sport', CityVenues),
    customer('/memberships', MembershipDirectory),
    customer('/memberships/:place', CityPrograms),
    customer('/memberships/:place/:segment', CityPrograms),
    customer('/listings/:id', ListingDetail),
    customer('/games', Games),
    customer('/games/:id', GameDetail),
    customer('/games/:id/requests', ManageRequests),
    customer('/tournaments/:id', TournamentDetail),
    customer('/tournaments/:id/requests', ManageRequests),
    customer('/communities', Communities),
    customer('/communities/new', CreateCommunity),
    customer('/communities/:id', CommunityDetail),
    customer('/my-communities', MyCommunities),
    customer('/host-game', HostGame),
    customer('/programs', CustomerPrograms),
    customer('/programs/:id', ProgramDetail),
    customer('/blog', BlogIndex),
    customer('/post/:slug', BlogPost),
    // Posts used to live under /blog/<slug>; the live site serves them at /post/<slug>.
    // Keep the old shape as a redirect so already-shared/indexed links don't 404.
    { path: '/blog/:slug', redirect: (to) => `/post/${to.params.slug}` },
    { path: '/ar/blog/:slug', redirect: (to) => `/ar/post/${to.params.slug}` },
    customer('/rewards', Rewards),
    customer('/account', Account),
    customer('/my-games', MyGames),
    customer('/my-bookings', MyBookings),
    customer('/thank-you', ThankYou),
    customer('/payment/cancel', PaymentCancelled),
    { path: '/my-memberships', redirect: '/programs' },
    customer('/messages', Messages),
    customer('/messages/:id', Messages),
    customer('/u/:id', PublicProfile),
    customer('/unsubscribe', Unsubscribe),
    customer('/terms', Terms),
    // /privacy-policy is the URL registered in the Google Play & App Store
    // listings — the path must not change or store review breaks.
    customer('/privacy-policy', Privacy),
    // Legacy legal paths from the previous frontend; keep them resolving so
    // indexed links and older app builds don't land on a blank page.
    { path: '/privacy', redirect: '/privacy-policy' },
    { path: '/ar/privacy', redirect: '/ar/privacy-policy' },
    { path: '/terms-conditions', redirect: '/terms' },
    { path: '/ar/terms-conditions', redirect: '/ar/terms' },
    customer('/about', AboutSite),
    customer('/contact', ContactSite),
    { path: '/pass', redirect: '/my-memberships' },
    customer('/pay/:id', PayBooking),

    // B2B surface moved to business.bookboll.com
    {
      path: '/signup',
      component: { render: () => null },
      beforeEnter: () => { window.location.replace(businessSignupUrl()) },
    },
    {
      path: '/for-business',
      component: { render: () => null },
      beforeEnter: () => { goBusiness('/') },
    },
    {
      path: '/for-business/pricing',
      component: { render: () => null },
      beforeEnter: () => { goBusiness('/pricing') },
    },
    {
      path: '/for-business/blog',
      component: { render: () => null },
      beforeEnter: () => { goBusiness('/blog') },
    },
    {
      path: '/for-business/blog/:slug',
      component: { render: () => null },
      beforeEnter: (to) => { goBusiness(`/blog/${to.params.slug}`) },
    },
    {
      path: '/for-business/terms',
      component: { render: () => null },
      beforeEnter: () => { goBusiness('/terms') },
    },

    // Legacy admin paths → admin domain
    {
      path: '/login',
      component: { render: () => null },
      beforeEnter: () => { window.location.replace(adminDashboardUrl('/login')) },
    },
    {
      path: '/admin/:pathMatch(.*)*',
      component: { render: () => null },
      beforeEnter: (to) => {
        const rest = to.params.pathMatch
        const suffix = Array.isArray(rest) ? rest.join('/') : (rest || '')
        window.location.replace(adminDashboardUrl(suffix ? `/admin/${suffix}` : '/admin'))
      },
    },

    // Unknown paths used to match nothing, which rendered an EMPTY <router-view>
    // under the header — a blank 200 page rather than a visible miss. Send them
    // home like the previous frontend did, so a mistyped or retired link
    // (including one in an app-store listing) always lands somewhere real.
    { path: '/ar/:pathMatch(.*)*', redirect: '/ar' },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

// Keep a provider preview scoped when customers follow links or refresh a detail page.
const providerPreviewQuery = Object.fromEntries(
  [...new URLSearchParams(window.location.search)].filter(([key, value]) => ['b', 'bid', 'preview'].includes(key) && value),
)
router.beforeEach(async (to) => {
  const area = to.meta?.area || 'customer'
  const barePath = to.path === '/ar' ? '/' : to.path.startsWith('/ar/') ? to.path.slice(3) : to.path

  if (area === 'customer' && ['/about', '/contact'].includes(barePath)) {
    if (!isWhiteLabel.value || !brandPageEnabled(barePath.slice(1))) return '/'
  }
  if (area === 'customer' && isScopedEmbed() && !embedAllows(barePath)) return embedHome()
  if (area === 'customer' && isWhiteLabel.value) {
    if (barePath === '/locations' && !brandSectionEnabled('locations')) return '/'
    if (Object.entries(providerPreviewQuery).some(([key, value]) => to.query[key] !== value)) {
      return { path: to.path, query: { ...to.query, ...providerPreviewQuery }, hash: to.hash }
    }
    const blocked = ['/search', '/rewards', '/u/', '/messages', '/my-games', '/communities', '/my-communities']
    if (blocked.some(p => barePath === p || barePath.startsWith(p))) return '/'
    const mod = moduleForPath(barePath)
    if (mod && !brandShows(mod) && !isScopedEmbed()) return '/'
  }
})

installUnsavedGuards(router)

Promise.allSettled([resolveBrand()])
  .then(() => (!isWhiteLabel.value ? ensureDefaultLocation() : ensureBrandLocation(activeBrand.value?.regions)))
  .catch(() => {})
  .finally(() => {
    const app = createApp(App)
    app.use(router).use(i18n).use(createHead()).mount('#app')
    initAutoTranslate()
    // Only does anything when a Google sign-in had to fall back to a full redirect
    // because the browser blocked the popup; see lib/googleAuth.js.
    resumeGoogleLogin().catch(() => {})
  })
