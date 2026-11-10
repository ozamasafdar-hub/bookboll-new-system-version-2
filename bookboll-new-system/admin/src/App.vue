<script setup>
import CookieConsent from './components/CookieConsent.vue'
import { ref, computed, onMounted, onUnmounted, nextTick, watch, provide } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { canAccessPage } from './lib/permissions.js'
import { User, Menu, X, ChevronRight, ChevronDown, LogOut, Moon, Sun } from 'lucide-vue-next'
import { NAV_GROUPS } from './lib/navGroups.js'
import { useCurrentUser } from './lib/currentUser.js'
import PublishNag from './components/PublishNag.vue'
import SetupCoach from './components/SetupCoach.vue'
import AppDownloadCard from './components/AppDownloadCard.vue'
import { appPromptContext, insideInstalledApp } from './lib/mobileApps.js'
import { adminTheme, toggleAdminTheme } from './lib/adminTheme.js'
import { authUser, signOut, needsAccountChoice } from './lib/auth.js'
import AccountPicker from './pages/AccountPicker.vue'
import ToastHost from './components/ToastHost.vue'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import AdminBell from './components/AdminBell.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import UpgradeModal from './components/UpgradeModal.vue'
import { ensurePlan, completePlanCheckout } from './lib/premium.js'
import InputDialog from './components/InputDialog.vue'
import CustomerDetailModal from './components/CustomerDetailModal.vue'
import BrandMark from './components/BrandMark.vue'
import { adminBrand, adminBrandVars, adminLogoSquareStyle, loadAdminBrand } from './lib/adminBrand.js'
import { useHead } from '@unhead/vue'

const route = useRoute()
const paymentsPreview = import.meta.env.VITE_PAYMENTS_PREVIEW === 'true'
const router = useRouter()
const sidebarOpen = ref(false)
const menuButton = ref(null)
const closeMenuButton = ref(null)
watch(sidebarOpen, async open => {
  await nextTick()
  if (open) closeMenuButton.value?.focus()
  else menuButton.value?.focus()
})
function drawerKeydown(event) {
  if (event.key === 'Escape') { sidebarOpen.value = false; return }
  if (event.key !== 'Tab') return
  const targets = [...event.currentTarget.querySelectorAll('a[href], button:not([disabled])')]
  const first = targets[0], last = targets.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
let desktopMedia
function closeDrawerOnDesktop(event) { if (event.matches) sidebarOpen.value = false }
onMounted(() => {
  desktopMedia = window.matchMedia('(min-width: 901px)')
  desktopMedia.addEventListener('change', closeDrawerOnDesktop)
})
onUnmounted(() => desktopMedia?.removeEventListener('change', closeDrawerOnDesktop))
const countryFilter = ref('All Countries')

// Admin-dashboard branding (super-admin-configured logo + accent colour). Loaded
// once here and shared with the auth screens; applied scoped to the .app shell.
onMounted(() => { loadAdminBrand() })

// The provider's plan gates half the dashboard, so warm it as soon as there is a
// session — every requirePremium() check is synchronous and must not have to wait.
watch(authUser, (u) => { if (u) ensurePlan() }, { immediate: true })

/**
 * Coming back from Stripe. A Premium checkout can be started from any page, so the
 * return is handled here rather than in one page: confirm it with the server (the
 * webhook may not have landed yet), then take the marker out of the URL so a reload
 * does not re-run it.
 */
watch(() => route.query.plan_checkout, async (session) => {
  if (!session) return
  await completePlanCheckout(String(session))
  const query = { ...route.query }
  delete query.plan_checkout
  router.replace({ path: route.path, query })
}, { immediate: true })

// Shell: auth (login) or admin dashboard.
const area = computed(() => route.meta?.area || 'customer')

// Apply the dashboard palette only while its shell is active.
watch(area, (a) => {
  if (typeof document !== 'undefined') document.documentElement.classList.toggle('adm-shell', a === 'admin')
}, { immediate: true })

// Admin + auth areas are private — keep them out of search. On the public storefront this
// contributes nothing, so each customer page's own useSeo() fully controls the head. Public
// marketing pages in the bare 'auth' layout (meta.indexable, e.g. /for-business) are exempt —
// they manage their own SEO and SHOULD be indexed.
useHead(computed(() => (area.value === 'customer' || route.meta?.indexable) ? {} : {
  title: 'Bookboll Admin',
  meta: [{ name: 'robots', content: 'noindex, nofollow' }],
}))

const { currentUser, activeUserId, allUsers, switchableUsers, role, isSuperAdmin, isProviderScoped, currentOverrides, setActiveUser, loadUserContext } = useCurrentUser()
// Only load the admin user roster when an admin is actually signed in — the public
// customer storefront must not query admin_users.
watch(authUser, (u) => { if (u && !needsAccountChoice.value) loadUserContext() }, { immediate: true })

async function handleSignOut() {
  await signOut()
  router.replace('/login')
}

const roleLabel = {
  super_admin: 'Super Admin',
  country_admin: 'Country Admin',
  provider_admin: 'Provider Admin',
  facility_admin: 'Facility Admin',
  senior_employee: 'Senior Employee',
  junior_employee: 'Junior Employee',
  guard: 'Guard',
  staff: 'Staff',
}

function businessNameFor(u) {
  if (!u) return null
  if (u.role === 'provider_admin') return u.business_name || null
  if (u.owner_user_id) {
    const owner = allUsers.value.find(x => x.id === u.owner_user_id)
    return owner?.business_name || null
  }
  return null
}

const currentBusinessName = computed(() => businessNameFor(currentUser.value))
const appCardVisible = ref(false)
const appContext = computed(() => appPromptContext({ audience: 'admin', path: route.path, signedIn: !!authUser.value && !!currentUser.value && !needsAccountChoice.value, businessUser: isProviderScoped.value }))
const appDownloadCard = ref(null)
const appDownloadAvailable = ref(false)
onMounted(() => { appDownloadAvailable.value = !insideInstalledApp(window, navigator) })
provide('adminAppDownload', {
  available: computed(() => !!appContext.value && appDownloadAvailable.value),
  open: () => appDownloadCard.value?.open(),
})

// Sidebar groups and their directory pages share one navigation definition.
// Groups visible to the active role: Home always; single-link groups drop if their page
// isn't accessible; grouped ones keep only accessible pages and drop if empty.
const groups = computed(() =>
  NAV_GROUPS
    .map(g => g.to ? g : { ...g, items: g.items.filter(i => canAccessPage(i.path, role.value, currentOverrides.value)) })
    .filter(g => g.to ? (g.key === 'home' || canAccessPage(g.to, role.value, currentOverrides.value)) : (g.items && g.items.length))
)
// Flat list (for the breadcrumb title) + the group the current route belongs to.
const allItems = computed(() => groups.value.flatMap(g => g.items || (g.to ? [{ path: g.to, label: g.label }] : [])))
const activeGroupKey = computed(() => {
  // A group's landing page (/nav/<key>) highlights that group.
  if (route.path.startsWith('/nav/')) return route.path.slice(5)
  for (const g of groups.value) {
    // single-link groups match their path OR any sub-route (e.g. /wallet/subscription → My Wallets)
    if (g.to && (g.to === route.path || route.path.startsWith(g.to + '/'))) return g.key
    if (g.items && g.items.some(i => i.path === route.path || route.path.startsWith(i.path + '/'))) return g.key
  }
  return ''
})

const expandedGroup = ref('')
watch(activeGroupKey, key => { expandedGroup.value = key }, { immediate: true })
function toggleNavGroup(key) { expandedGroup.value = expandedGroup.value === key ? '' : key }

// Account menu (top-right of the topbar).
const accountOpen = ref(false)

// Block direct navigation to pages the active role can't access (and redirect if
// a role switch makes the current page off-limits). Waits until the user context
// has loaded so the real role — not the 'super_admin' default — is used.
watch([role, () => route.path, currentUser], () => {
  // MUST pass the per-user overrides (like the nav filter above does) — without
  // them a revoked page stays reachable by direct URL and a granted page
  // redirects away the moment it's opened.
  if (area.value === 'admin' && currentUser.value && !canAccessPage(route.path, role.value, currentOverrides.value)) router.replace('/admin')
}, { immediate: true })

const currentTitle = computed(() => allItems.value.find(i => i.path === route.path)?.label || 'Bookboll')

// Breadcrumb: Bookboll (→ home) › nav group (→ its landing page /nav/<key>) ›
// current page. Mirrors the sidebar hierarchy so the trail is the REAL path to
// the page, and every segment except the current one navigates.
const crumbs = computed(() => {
  const out = [{ label: 'Bookboll', to: '/admin' }]
  const g = groups.value.find(x => x.key === activeGroupKey.value)
  if (g && g.items) {
    if (route.path.startsWith('/nav/')) {
      out.push({ label: g.label, to: null })   // ON the group landing page
    } else {
      const item = g.items.find(i => i.path === route.path || route.path.startsWith(i.path + '/'))
      out.push({ label: g.label, to: `/nav/${g.key}` })
      out.push({ label: item?.label || currentTitle.value, to: null })
    }
  } else if (g) {
    out.push({ label: g.label, to: null })
  } else {
    out.push({ label: currentTitle.value, to: null })
  }
  return out
})

const initials = computed(() => {
  const n = currentUser.value?.full_name || 'SA'
  return n.split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase()
})
</script>

<template>
  <div v-if="paymentsPreview" class="payments-preview-banner" role="status">Payments preview · Database copy from 4 Nov 2026 · Read-only · No live payments</div>
  <!-- Auth pages (login / callback) render bare -->
  <router-view v-if="area === 'auth'" />

  <!-- This email manages several businesses → choose one before the dashboard loads -->
  <AccountPicker v-else-if="needsAccountChoice" />

  <!-- Admin dashboard shell -->
  <div v-else class="app" :style="adminBrandVars">
    <!-- Labeled desktop navigation; groups open by click and keyboard. -->
    <aside class="rail" aria-label="Dashboard navigation">
      <router-link to="/admin" class="rail-home">
        <span class="rail-brand" :style="adminLogoSquareStyle"><img v-if="adminBrand.logo" :src="adminBrand.logo" alt="" /><BrandMark v-else /></span>
        <span class="rail-wordmark">Bookboll<small>for Business</small></span>
      </router-link>
      <nav class="rail-nav" aria-label="Main navigation">
        <div v-for="g in groups" :key="g.key" class="rail-group">
          <router-link v-if="g.to" :to="g.to" class="rail-btn" :class="{ active: activeGroupKey === g.key }">
            <component :is="g.icon" :size="20" /><span class="rail-label">{{ g.label }}</span>
          </router-link>
          <template v-else>
            <button type="button" class="rail-btn" :class="{ active: activeGroupKey === g.key }"
              :aria-expanded="expandedGroup === g.key" :aria-controls="'nav-' + g.key" @click="toggleNavGroup(g.key)">
              <component :is="g.icon" :size="20" /><span class="rail-label">{{ g.label }}</span>
              <ChevronDown :size="15" class="rail-chevron" :class="{ expanded: expandedGroup === g.key }" />
            </button>
            <div v-if="expandedGroup === g.key" :id="'nav-' + g.key" class="rail-subnav">
              <router-link v-for="item in g.items" :key="item.path" :to="item.path" active-class="active">{{ item.label }}</router-link>
            </div>
          </template>
        </div>
      </nav>
      <div class="rail-business"><span class="rail-business-avatar">{{ initials }}</span><div><strong>{{ currentBusinessName || currentUser?.full_name || 'Your workspace' }}</strong><small>{{ roleLabel[role] || role }}</small></div></div>
    </aside>

    <!-- Mobile drawer: full grouped list -->
    <aside :class="['drawer', { open: sidebarOpen }]" :inert="!sidebarOpen" role="dialog" :aria-modal="sidebarOpen || undefined" aria-label="Dashboard navigation" @keydown="drawerKeydown">
      <div class="brand">
        <div class="brand-mark" :class="{ 'has-logo-img': adminBrand.logo }"
          :style="adminBrand.logo ? { backgroundImage: `url(${adminBrand.logo})`, backgroundSize: 'cover', backgroundPosition: 'center' } : adminLogoSquareStyle">
          <BrandMark v-if="!adminBrand.logo" />
        </div>
        <div class="brand-text"><div class="brand-name">Bookboll</div><div class="brand-sub">for Business</div></div>
        <button ref="closeMenuButton" class="close-btn" aria-label="Close navigation" @click="sidebarOpen=false"><X :size="18" /></button>
      </div>
      <nav class="drawer-nav">
        <template v-for="g in groups" :key="g.key">
          <router-link v-if="g.to" :to="g.to" class="nav-item" active-class="active" @click="sidebarOpen=false">
            <component :is="g.icon" :size="18" /><span>{{ g.label }}</span>
          </router-link>
          <div v-else class="nav-section">
            <router-link :to="`/nav/${g.key}`" class="nav-section-title nav-section-link" @click="sidebarOpen=false">{{ g.label }}</router-link>
            <router-link v-for="item in g.items" :key="item.path" :to="item.path" class="nav-item" active-class="active" @click="sidebarOpen=false">
              <component :is="item.icon" :size="18" /><span>{{ item.label }}</span>
            </router-link>
          </div>
        </template>
      </nav>
      <div class="drawer-foot">
        <div class="avatar" data-no-translate>{{ initials }}</div>
        <div style="flex:1; min-width:0;">
          <div class="user-name" data-no-translate>{{ currentUser?.full_name || 'Super Admin' }}</div>
          <div class="user-role">{{ roleLabel[role] || role }}</div>
        </div>
        <button class="signout-btn" title="Sign out" @click="handleSignOut"><LogOut :size="16" /></button>
      </div>
    </aside>

    <div class="main" :inert="sidebarOpen">
      <header class="topbar">
        <button ref="menuButton" class="menu-btn" aria-label="Open navigation" :aria-expanded="sidebarOpen" @click="sidebarOpen=true"><Menu :size="20" /></button>
        <div class="breadcrumb">
          <template v-for="(c, i) in crumbs" :key="i">
            <ChevronRight v-if="i" :size="14" />
            <router-link v-if="c.to && i < crumbs.length - 1" :to="c.to" class="crumb-link">{{ c.label }}</router-link>
            <span v-else-if="i < crumbs.length - 1" class="crumb-dim">{{ c.label }}</span>
            <span v-else class="crumb-cur">{{ c.label }}</span>
          </template>
        </div>
        <div class="top-actions">
          <LanguageSwitcher compact />
          <!-- Act-as switcher: ONLY identities the real signed-in account may use
               (own accounts; HQ can act as businesses — never above its own rank).
               Hidden entirely when there's nothing to switch to. -->
          <div v-if="switchableUsers.length > 1" class="role-switcher" title="Switch active account" data-no-translate>
            <User :size="14" />
            <select :value="activeUserId" @change="setActiveUser($event.target.value)">
              <option v-for="u in switchableUsers" :key="u.id" :value="u.id">
                {{ u.full_name }} — {{ roleLabel[u.role] || u.role }}{{ businessNameFor(u) ? ' · ' + businessNameFor(u) : '' }}
              </option>
            </select>
          </div>
          <button class="icon-btn theme-btn" :title="adminTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleAdminTheme">
            <Sun v-if="adminTheme === 'dark'" :size="18" />
            <Moon v-else :size="18" />
          </button>
          <AdminBell />

          <!-- Account (top-right) -->
          <div class="acct">
            <button class="acct-btn" :class="{ open: accountOpen }" @click="accountOpen = !accountOpen">
              <span class="acct-avatar" data-no-translate>{{ initials }}</span>
              <span class="acct-name" data-no-translate>{{ currentUser?.full_name || 'Super Admin' }}</span>
              <ChevronDown :size="15" class="acct-chev" :class="{ rot: accountOpen }" />
            </button>
            <div v-if="accountOpen" class="acct-menu">
              <div class="acct-head">
                <div class="acct-head-name" data-no-translate>{{ currentUser?.full_name || 'Super Admin' }}</div>
                <div class="acct-head-role">{{ roleLabel[role] || role }}<span v-if="currentBusinessName"> · {{ currentBusinessName }}</span></div>
              </div>
              <button class="acct-item" @click="accountOpen=false; handleSignOut()"><LogOut :size="16" /> Sign out</button>
            </div>
            <div v-if="accountOpen" class="acct-backdrop" @click="accountOpen=false"></div>
          </div>
        </div>
      </header>
      <main class="content">
        <PublishNag />
        <router-view />
        <AppDownloadCard ref="appDownloadCard" :visit-key="route.path" :account-id="currentUser?.id" audience="admin" :context="appContext" :blocked="sidebarOpen || accountOpen" @visibility-change="appCardVisible = $event" />
      </main>
      <!-- Floating setup guide (providers): one step at a time, bottom-right -->
      <SetupCoach v-show="!sidebarOpen && !appCardVisible" />
    </div>

    <div v-if="sidebarOpen" class="overlay" @click="sidebarOpen=false"></div>

    <!-- Global feedback hosts (mounted once) -->
    <ToastHost />
    <ConfirmDialog />
    <Teleport to="body"><div :style="adminBrandVars"><UpgradeModal /></div></Teleport>
    <InputDialog />
    <CustomerDetailModal />
  </div>
  <CookieConsent scope="admin" />
</template>

<style>
.payments-preview-banner { padding: 9px 20px; background: var(--warning-bg, #fff4d6); color: var(--warning-ink, #684a12); border-bottom: 1px solid var(--warning-border, #e9d29a); font-size: 12px; text-align: center; line-height: 1.4; position: relative; z-index: 100; }
</style>


<style scoped>
.app { display: flex; min-height: 100vh; background: var(--bg, #f8fafc); }

/* Desktop navigation layout; dashboardTheme.css supplies its appearance. */
.rail { width: 104px; flex-shrink: 0; background: var(--surface, #ffffff); border-right: 1px solid var(--border);
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  position: sticky; top: 0; height: 100vh; padding: 14px 0; z-index: 30; overflow-y: auto; }
.rail::-webkit-scrollbar { width: 0; }
.rail-brand { width: 46px; height: 46px; border-radius: 13px; background: linear-gradient(135deg, var(--brand-accent, #14b8a6), var(--brand, #0f766e)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; text-decoration: none; margin-bottom: 12px; flex-shrink: 0; overflow: hidden; }
.rail-nav { flex: 1; display: flex; flex-direction: column; gap: 12px; width: 100%; align-items: center; }
.rail-group { position: relative; width: 100%; display: flex; justify-content: center; }
/* Theme tokens keep navigation readable in either palette. */
.rail-btn { width: 92px; padding: 12px 4px; border-radius: 14px; border: none; background: none; color: var(--text-muted, #64748b); display: flex; flex-direction: column; align-items: center; gap: 8px; cursor: pointer; transition: background .12s, color .12s; text-decoration: none; }
.rail-btn:hover, .rail-btn.open { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); }
.rail-btn.active { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); }
.rail-btn.active::before { content: ''; position: absolute; left: 0; top: 8px; bottom: 8px; width: 3px; border-radius: 0 3px 3px 0; background: var(--brand, #0f766e); }
.rail-label { font-size: 12px; font-weight: 500; line-height: 1.2; text-align: center; }

/* ── Account menu (top-right) ─────────────────────────────────────────── */
.acct { position: relative; }
.acct-btn { display: inline-flex; align-items: center; gap: 8px; padding: 5px 8px 5px 5px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); cursor: pointer; max-width: 220px; }
.acct-btn:hover, .acct-btn.open { border-color: var(--brand-accent, #14b8a6); }
.acct-avatar { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; }
.acct-name { font-size: 13.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.acct-chev { color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.acct-chev.rot { transform: rotate(180deg); }
.acct-menu { position: absolute; inset-inline-end: 0; top: calc(100% + 8px); z-index: 60; min-width: 220px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 16px 40px rgba(15,23,42,.16); padding: 8px; }
.acct-head { padding: 6px 10px 10px; border-bottom: 1px solid var(--border); margin-bottom: 6px; }
.acct-head-name { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.acct-head-role { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 2px; }
.acct-item { display: flex; align-items: center; gap: 9px; width: 100%; text-align: left; padding: 9px 10px; border: none; background: none; border-radius: 9px; font-size: 14px; font-weight: 600; color: var(--text-secondary, #334155); cursor: pointer; }
.acct-item:hover { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #ef4444); }
.acct-backdrop { position: fixed; inset: 0; z-index: 55; }

/* ── Mobile drawer (full grouped list) ────────────────────────────────── */
.drawer { display: none; }
.brand { display: flex; align-items: center; gap: 12px; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.brand-mark { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, var(--brand-accent, #14b8a6), var(--brand, #0f766e)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; overflow: hidden; }
.brand-text { flex: 1; min-width: 0; }
.brand-name { color: var(--text, #0f172a); font-weight: 800; font-size: 16px; }
.brand-sub { font-size: 11px; color: var(--text-light, #94a3b8); }
.close-btn { color: var(--text-muted, #64748b); padding: 6px; }
.drawer-nav { flex: 1; padding: 12px 10px; overflow-y: auto; }
.nav-section { margin-bottom: 14px; }
.nav-section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light, #94a3b8); padding: 0 12px 6px; font-weight: 700; }
.nav-section-link { display: block; text-decoration: none; }
.nav-section-link:hover { color: var(--primary-ink); }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 9px; font-size: 14px; font-weight: 600; color: var(--text-secondary, #334155); margin-bottom: 2px; text-decoration: none; }
.nav-item:hover { background: var(--bg, #f1f5f9); }
.nav-item.active { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); }
.drawer-foot { display: flex; align-items: center; gap: 10px; padding: 12px; border-top: 1px solid var(--border); }
.avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; flex-shrink: 0; }
.user-name { color: var(--text, #0f172a); font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 11px; color: var(--text-light, #94a3b8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.signout-btn { flex-shrink: 0; background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); padding: 6px; border-radius: 8px; display: flex; align-items: center; }
.signout-btn:hover { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #ef4444); }

/* ── Main + topbar ────────────────────────────────────────────────────── */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar { background: var(--surface, #ffffff); border-bottom: 1px solid var(--border); padding: 14px 32px; display: flex; align-items: center; gap: 20px; position: sticky; top: 0; z-index: 20; }
.menu-btn { display: none; padding: 6px; }
.breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; }
.crumb-dim { color: var(--text-muted); font-weight: 400; }
.crumb-link { color: var(--text-muted); font-weight: 400; text-decoration: none; border-radius: 6px; }
.crumb-link:hover { color: var(--primary-ink); text-decoration: underline; }
.crumb-cur { color: var(--text); }
.top-actions { margin-inline-start: auto; display: flex; align-items: center; gap: 12px; }
.role-switcher { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-hover, #f8fafc); color: var(--text-muted); max-width: 320px; }
.role-switcher select { border: none; padding: 0; width: auto; font-size: 13px; color: var(--text); background: transparent; max-width: 280px; }
.role-switcher select:focus { box-shadow: none; }
.icon-btn { padding: 8px; border-radius: 8px; color: var(--text-muted); position: relative; transition: all 0.15s; }
.icon-btn:hover { background: var(--bg); color: var(--text); }
/* Day/night toggle: guaranteed ≥36px touch target on desktop AND the mobile topbar. */
.theme-btn { min-width: 36px; min-height: 36px; display: flex; align-items: center; justify-content: center; }
.dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; background: var(--error); border-radius: 50%; border: 2px solid #fff; }
/* min-width:0 lets the flex child shrink instead of forcing page-wide overflow; overflow-x:clip
   contains any stray-wide admin content without creating a scroll container (so sticky still works). */
.content { flex: 1; min-width: 0; overflow-x: clip; }
.overlay { display: none; }

@media (max-width: 900px) {
  .rail { display: none; }
  .drawer { display: flex; flex-direction: column; position: fixed; left: 0; top: 0; z-index: 40; width: 264px; height: 100vh; background: var(--surface, #ffffff); border-right: 1px solid var(--border); transform: translateX(-100%); transition: transform .2s; }
  .drawer.open { transform: translateX(0); }
  .menu-btn { display: flex; }
  .overlay { display: block; position: fixed; inset: 0; background: rgba(15,23,42,0.5); z-index: 30; }
  .topbar { padding: 14px 20px; }
  /* 44px-friendly touch targets on the phone drawer */
  .nav-item { padding: 12px; }
  .signout-btn, .close-btn { padding: 10px; }
}

@media (max-width: 600px) {
  .topbar { padding: 11px 14px; gap: 10px; }
  .breadcrumb { flex: 1; min-width: 0; font-size: 14px; }
  .breadcrumb .crumb-dim, .breadcrumb .crumb-link, .breadcrumb > svg { display: none; }
  .breadcrumb > span:last-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .top-actions { gap: 5px; }
  /* The role-switcher is a demo convenience — keep it usable but stop the long
     "Name — Role · Business" value from blowing the topbar past the viewport at
     360/344px (it was ~137px, pushing the avatar off-screen). Cap it hard. */
  .role-switcher { max-width: 26vw; padding: 6px 8px; gap: 4px; }
  .role-switcher > svg { display: none; }
  .role-switcher select { max-width: 24vw; font-size: 16px; }
  .icon-btn { padding: 6px; }
  /* The avatar alone is enough on a phone; the name AND chevron would crowd the
     row and (with the switcher) push the cluster past the screen edge. */
  .acct-name, .acct-chev { display: none; }
  .acct-btn { padding: 3px; max-width: none; }
}
</style>

<style>
@import './dashboardTheme.css';
@import './workspaceReview.css';
</style>
