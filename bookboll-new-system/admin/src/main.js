import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { createHead } from '@unhead/vue'
import './style.css'
import App from './App.vue'
import { dialogFocus } from './lib/dialogFocus.js'
import { installUnsavedGuards } from './lib/unsavedGuard.js'
import { initAuth, authUser, authLoading } from './lib/auth.js'
import { i18n } from './lib/i18n.js'
import { initAutoTranslate } from './lib/autoTranslate.js'
import { businessSignupUrl } from './lib/crossApp.js'

import Login from './pages/Login.vue'
import AuthCallback from './pages/AuthCallback.vue'
import Home from './pages/Home.vue'
import Dashboard from './pages/Dashboard.vue'
import Bookings from './pages/Bookings.vue'
import Calendar from './pages/Calendar.vue'
import Listings from './pages/Listings.vue'
import ClassesSubscriptions from './pages/ClassesSubscriptions.vue'
import Customers from './pages/Customers.vue'
import Payments from './pages/Payments.vue'
import Promocodes from './pages/Promocodes.vue'
import SpecialDays from './pages/SpecialDays.vue'
import Team from './pages/Team.vue'
import Countries from './pages/Countries.vue'
import Approvals from './pages/Approvals.vue'
import AuditLogs from './pages/AuditLogs.vue'
import Settings from './pages/Settings.vue'
import WalletPage from './pages/Wallet.vue'
import Subscriptions from './pages/Subscribers.vue'
import Moderation from './pages/Moderation.vue'
import Brand from './pages/Brand.vue'
import Banners from './pages/Banners.vue'
import BlogAdmin from './pages/Blog.vue'
import Tracking from './pages/Tracking.vue'
import Notifications from './pages/Notifications.vue'
import EmailSender from './pages/EmailSender.vue'
import WhatsAppPage from './pages/WhatsApp.vue'
import WebsiteBlog from './pages/WebsiteBlog.vue'
import WebsiteEmbed from './pages/WebsiteEmbed.vue'
import WebsiteDomain from './pages/WebsiteDomain.vue'
import CheckIn from './pages/CheckIn.vue'
import GroupNav from './pages/GroupNav.vue'

const admin = (path, component) => ({ path, component, meta: { area: 'admin' } })

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior() { return { top: 0 } },
  routes: [
    { path: '/login', component: Login, meta: { area: 'auth' } },
    { path: '/auth/callback', component: AuthCallback, meta: { area: 'auth', allowAuthed: true } },
    {
      path: '/signup',
      component: { render: () => null },
      beforeEnter: () => { window.location.replace(businessSignupUrl()) },
    },

    admin('/admin', Home),
    admin('/nav/:group', GroupNav),
    admin('/dashboard', Dashboard),
    admin('/bookings', Bookings),
    { path: '/checkin', redirect: '/memberships' },
    admin('/calendar', Calendar),
    admin('/listings', Listings),
    admin('/memberships', ClassesSubscriptions),
    admin('/customers', Customers),
    admin('/payments', Payments),
    admin('/promocodes', Promocodes),
    admin('/special-days', SpecialDays),
    admin('/team', Team),
    admin('/countries', Countries),
    admin('/approvals', Approvals),
    admin('/moderation', Moderation),
    admin('/brand', Brand),
    admin('/banners', Banners),
    admin('/blog-posts', BlogAdmin),
    admin('/website-blog', WebsiteBlog),
    admin('/website-embed', WebsiteEmbed),
    admin('/website-domain', WebsiteDomain),
    admin('/tracking', Tracking),
    admin('/notifications', Notifications),
    admin('/email-sender', EmailSender),
    admin('/whatsapp', WhatsAppPage),
    admin('/audit-logs', AuditLogs),
    admin('/settings', Settings),
    admin('/wallet', WalletPage),
    admin('/wallet/:purpose', WalletPage),
    { path: '/subscriptions', redirect: '/memberships' },

    { path: '/', redirect: '/admin' },
    { path: '/classes', redirect: '/memberships' },
    { path: '/classes-subscriptions', redirect: '/memberships' },
    { path: '/wallet/subscription', redirect: '/memberships' },
    { path: '/subscribers', redirect: '/subscriptions' },
    { path: '/venue-requests', redirect: '/approvals' },
    { path: '/loyalty', redirect: '/countries' },
    { path: '/locations', redirect: '/listings' },
    { path: '/addons', redirect: '/listings' },
    { path: '/resources', redirect: '/listings' },
    { path: '/providers', redirect: '/team' },
    { path: '/admin-users', redirect: '/team' },
    { path: '/my-team', redirect: '/team' },
    { path: '/payment-accounts', redirect: '/listings' },
  ],
})

router.beforeEach(async (to) => {
  if (authLoading.value) await initAuth()
  const area = to.meta?.area || 'admin'
  if (area === 'admin' && !authUser.value) return '/login'
  if (area === 'auth' && authUser.value && !to.meta?.allowAuthed) return '/admin'
})

installUnsavedGuards(router)

createApp(App).directive('dialog', dialogFocus).use(router).use(i18n).use(createHead()).mount('#app')
initAutoTranslate()

import './adminWorkspace.css'
