import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { createHead } from '@unhead/vue'
import './style.css'
import App from './App.vue'
import { installUnsavedGuards } from './lib/unsavedGuard.js'
import { initAuth, authLoading, signOut } from './lib/auth.js'
import { i18n } from './lib/i18n.js'
import { initAutoTranslate } from './lib/autoTranslate.js'
import { loadAdminBrand } from './lib/adminBrand.js'

import ForBusiness from './pages/ForBusiness.vue'
import ForBusinessPricing from './pages/ForBusinessPricing.vue'
import BookingFees from './pages/BookingFees.vue'
import ForBusinessBlog from './pages/ForBusinessBlog.vue'
import ForBusinessBlogPost from './pages/ForBusinessBlogPost.vue'
import ProviderTerms from './pages/ProviderTerms.vue'
import Signup from './pages/Signup.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior() { return { top: 0 } },
  routes: [
    { path: '/', component: ForBusiness, meta: { indexable: true, allowAuthed: true } },
    { path: '/pricing', component: ForBusinessPricing, meta: { indexable: true, allowAuthed: true } },
    { path: '/booking-fees', component: BookingFees, meta: { indexable: true, allowAuthed: true } },
    { path: '/blog', component: ForBusinessBlog, meta: { indexable: true, allowAuthed: true } },
    { path: '/post/:slug', component: ForBusinessBlogPost, meta: { indexable: true, allowAuthed: true } },
    { path: '/terms', component: ProviderTerms, meta: { indexable: true, allowAuthed: true } },
    { path: '/signup', component: Signup },

    // Legacy path aliases (old bookboll.com/for-business/* bookmarks)
    { path: '/for-business', redirect: '/' },
    { path: '/for-business/pricing', redirect: '/pricing' },
    { path: '/for-business/blog', redirect: '/blog' },
    { path: '/for-business/blog/:slug', redirect: to => `/post/${to.params.slug}` },
    { path: '/blog/:slug', redirect: to => `/post/${to.params.slug}` },
    { path: '/for-business/terms', redirect: '/terms' },
  ],
})

router.beforeEach(async (to) => {
  if (authLoading.value) await initAuth()

  // /signup used to bounce anyone holding a token straight to the admin app.
  // That token is a signup artifact minted by POST /business/register — it is
  // NOT an admin dashboard session, because the admin app has its own login and
  // no token handoff (its /auth/callback just forwards to /login). So the bounce
  // always landed on the sign-in screen, and because the token outlives signing
  // out over there (Sanctum logout revokes only the token it was called with,
  // and localStorage is per-origin anyway) there was no way back to /signup.
  //
  // Signup is now always reachable. A visitor who already owns a business gets a
  // clear message from the API instead of a redirect they cannot escape.
  if (to.path === '/signup' && to.query.fresh !== undefined) {
    // Explicit reset, for anyone still carrying a token from an abandoned signup.
    signOut()
    return { path: '/signup', replace: true }
  }
})

installUnsavedGuards(router)
loadAdminBrand()
createApp(App).use(router).use(i18n).use(createHead()).mount('#app')
initAutoTranslate()
