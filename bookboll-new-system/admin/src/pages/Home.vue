<script setup>
/**
 * Home.vue — the admin dashboard landing page. A navigation + guidance hub (not
 * analytics — that's the separate Dashboard page). For providers it surfaces their
 * public website (copy link + customise) and a guided list of what to do next.
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useCurrentUser } from '../lib/currentUser.js'
import { canAccessPage } from '../lib/permissions.js'
import { brandPreviewUrl, brandSiteUrl, providerSlug } from '../lib/brand.js'
import { pushToast } from '../lib/toast.js'
import { loadOverview } from '../lib/overview.js'
import { fmt12, fmtRange12 } from '../lib/slots.js'
import FeatureSearch from '../components/FeatureSearch.vue'
import {
  LayoutDashboard, Calendar, Building2, Users, Wallet, Ticket, Globe as Globe2,
  CalendarDays, BookOpen, SquareCheck as CheckSquare, Settings, ScrollText, UsersRound,
  Repeat, Flag, Copy, ExternalLink, Palette, ArrowRight, Sparkles, Rocket,
  MapPin, Clock, ChevronDown, Dumbbell, Plus, LayoutGrid, Check,
} from 'lucide-vue-next'

const router = useRouter()
const { currentUser, role, isSuperAdmin, isProviderScoped, currentOwnerUserId, currentOverrides } = useCurrentUser()

const firstName = computed(() => (currentUser.value?.full_name || 'there').split(' ')[0])
const isProvider = computed(() => role.value === 'provider_admin' || role.value === 'facility_admin')

// The provider's own public storefront link — auto-derived from their web address /
// username / business name, so it's never empty (every provider has a `<slug>.bookboll.com`).
const siteSlug = computed(() => providerSlug(currentUser.value))
// A verified custom domain replaces the subdomain as the address customers use, so it
// is what we show and copy — same precedence as My Website, which would otherwise
// display a different address for the same site.
const customDomain = computed(() => currentUser.value?.brand_domain_status === 'active'
  ? (currentUser.value?.brand_custom_domain || '')
  : '')
const siteUrl = computed(() => customDomain.value
  ? `https://${customDomain.value}`
  : (siteSlug.value ? brandPreviewUrl(siteSlug.value) : ''))

async function copySite() {
  if (!siteUrl.value) { router.push('/brand'); return }
  try { await navigator.clipboard.writeText(siteUrl.value); pushToast('Website link copied', 'success') }
  catch { pushToast('Could not copy — open it from My Website.', 'error') }
}
// Published → the provider's real, shareable address; otherwise the preview by owner id,
// which still works before publishing and on hosts without wildcard DNS.
function openSite() {
  if (!currentUser.value?.id) return
  window.open(brandSiteUrl({
    slug: siteSlug.value,
    ownerId: currentUser.value.id,
    published: siteLive.value,
    customDomain: customDomain.value,
  }), '_blank', 'noopener')
}

// Guidance cards — filtered by what the current role can actually open.
const ALL_CARDS = [
  { to: '/listings', icon: Building2, title: 'Listings & programs', desc: 'Manage your locations, listings, prices and membership programs.' },
  { to: '/calendar', icon: Calendar, title: 'Calendar', desc: 'See every booking across your venues at a glance.' },
  { to: '/bookings', icon: BookOpen, title: 'Bookings', desc: 'Review, edit and manage incoming reservations.' },
  { to: '/customers', icon: Users, title: 'Customers', desc: 'Your customer CRM — profiles, history and loyalty.' },
  { to: '/memberships', icon: Repeat, title: 'Membership listings', desc: 'Showcase your academy and manage marketplace listing subscriptions.' },
  { to: '/wallet', icon: Wallet, title: 'My Wallets', desc: 'One wallet per country — track earnings and set up how you get paid.' },
  { to: '/promocodes', icon: Ticket, title: 'Promotions', desc: 'Create promo codes to bring in more bookings.' },
  { to: '/payments', icon: LayoutDashboard, title: 'Payments', desc: 'Collect and reconcile payments.' },
  { to: '/special-days', icon: CalendarDays, title: 'Special Days', desc: 'Set holidays and one-off hours or closures.' },
  { to: '/approvals', icon: CheckSquare, title: 'Approvals', desc: 'Review listings and programs awaiting approval.' },
  { to: '/moderation', icon: Flag, title: 'Event Moderation', desc: 'Keep player-hosted games safe and on-brand.' },
  { to: '/countries', icon: Globe2, title: 'Countries & Cities', desc: 'Manage where Bookboll operates.' },
  { to: '/team', icon: UsersRound, title: 'Team', desc: 'Add staff and set what they can do.' },
  { to: '/audit-logs', icon: ScrollText, title: 'Audit Logs', desc: 'See who changed what, and when.' },
  { to: '/settings', icon: Settings, title: 'Settings', desc: 'Platform, policies, commissions and more.' },
]
const cards = computed(() => ALL_CARDS.filter(c => canAccessPage(c.to, role.value, currentOverrides.value)))
const cardGroups = computed(() => [
  { title: 'Day to day', icon: Calendar, paths: ['/calendar', '/bookings', '/listings', '/customers', '/memberships', '/special-days'] },
  { title: 'Money & growth', icon: Wallet, paths: ['/payments', '/wallet', '/promocodes'] },
  { title: 'Business & administration', icon: Settings, paths: ['/team', '/approvals', '/moderation', '/countries', '/audit-logs', '/settings'] },
].map(group => ({ ...group, cards: group.paths.map(path => cards.value.find(card => card.to === path)).filter(Boolean) }))
  .filter(group => group.cards.length))

// ── Live setup guide (providers) — real completion states, not a static list ───
const route = useRoute()
const isWelcome = computed(() => route.query.welcome === '1')   // fresh from the signup wizard

// Checklist state + the venue cards come from one call; the API decides what
// this account may see (its own business, or a platform admin's jurisdiction).
const walletReady = ref(false)      // any active payout method on any wallet
const hasOwnerProgram = ref(false)  // programs can exist without a location

const hasLocation = computed(() => locations.value.length > 0)
const hasListing = computed(() => hasOwnerProgram.value || locations.value.some(l => (l.listings?.length || 0) + (l.programs?.length || 0) > 0))
const siteLive = computed(() => currentUser.value?.brand_enabled === true)

// Payout is deliberately LAST: let providers build their catalogue and website
// first (no upfront money-setup friction), then connect how they get paid when
// they're ready to go live. Online payments & memberships are still gated on it
// at the point of enabling — this is just the guidance order.
const checklist = computed(() => [
  { key: 'listing', to: '/listings', icon: Building2,
    label: 'Add your location & first listing',
    sub: !hasLocation.value ? 'Create your venue, then a court, space or membership program.'
       : !hasListing.value ? 'Location added — now add a court, space or program inside it.'
       : 'Your services are listed.',
    done: hasLocation.value && hasListing.value },
  { key: 'website', to: '/brand', icon: Palette,
    label: 'Customise & publish your website',
    sub: siteLive.value ? 'Your site is live.' : 'Logo & colours — your web address is already reserved.',
    done: siteLive.value },
  { key: 'wallet', to: '/wallet', icon: Wallet,
    label: 'Connect a payout method',
    sub: walletReady.value ? 'You can get paid.' : 'Last step — connect how you get paid to unlock online payments & memberships.',
    done: walletReady.value },
])
const doneCount = computed(() => checklist.value.filter(s => s.done).length)
const nextKey = computed(() => checklist.value.find(s => !s.done)?.key || '')
const allDone = computed(() => doneCount.value === checklist.value.length)

// ── Locations showcase (providers) ────────────────────────────────────────────
// Each of the provider's locations, with its weekly schedule + the listings/programs
// inside it revealed on click. Owner-scoped, so a provider only sees their own venues.
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const locations = ref([])
const locLoading = ref(false)
const openLocId = ref(null)

function fmtTime(t) {
  return fmt12(t)
}

// Weekly schedule for a location → all 7 days, each with its open periods (or Closed).
function scheduleFor(loc) {
  const byDay = {}
  for (const r of (loc.hours || [])) {
    if (!r.is_open) continue
    ;(byDay[r.day_of_week] ||= []).push(fmtRange12(r.open_time, r.close_time))
  }
  return DOW.map((day, i) => ({ day, open: !!byDay[i], periods: byDay[i] || [] }))
}

function toggleLoc(id) { openLocId.value = openLocId.value === id ? null : id }

async function loadLocations() {
  locLoading.value = true
  try {
    const { setup, locations: locs } = await loadOverview()
    locations.value = locs
    walletReady.value = !!setup.wallet_ready
    hasOwnerProgram.value = !!setup.has_program
  } catch { locations.value = [] }
  finally { locLoading.value = false }
}
onMounted(loadLocations)
</script>

<template>
  <div class="page home-page">
    <!-- Greeting -->
    <div class="hm-hero">
      <div>
        <h1>Welcome back, {{ firstName }} 👋</h1>
        <p>{{ isProvider ? 'Manage your venues, members and website — everything in one place.' : 'Your control center for the whole Bookboll platform.' }}</p>
      </div>
    </div>

    <FeatureSearch />

    <!-- Provider website tools -->
    <div v-if="isProvider" class="hm-site">
      <div class="hm-site-left">
        <div class="hm-site-ic"><Globe2 :size="20" /></div>
        <div class="hm-site-text">
          <div class="hm-site-title">Your booking website</div>
          <div v-if="siteUrl" class="hm-site-url">{{ siteUrl }}</div>
          <div v-else class="hm-site-url muted">Pick a web address to go live — set it up in My Website.</div>
        </div>
      </div>
      <div class="hm-site-actions">
        <button class="hm-btn" @click="copySite"><Copy :size="15" /> Copy link</button>
        <button v-if="siteUrl" class="hm-btn" @click="openSite"><ExternalLink :size="15" /> Open</button>
        <router-link to="/brand" class="hm-btn hm-btn-primary"><Palette :size="15" /> Customise</router-link>
      </div>
    </div>

    <!-- Setup guidance moved to the floating SetupCoach (bottom-right, one step
         at a time, dismissible) — mounted app-wide in App.vue. -->

    <!-- Navigation / guidance grid -->
    <section v-for="group in cardGroups" :key="group.title" class="hm-section" :aria-label="group.title">
      <h2 class="hm-section-head"><component :is="group.icon" :size="18" /> {{ group.title }}</h2>
      <div class="hm-grid">
        <router-link v-for="c in group.cards" :key="c.to" :to="c.to" class="hm-card">
          <div class="hm-card-ic"><component :is="c.icon" :size="20" /></div>
          <div class="hm-card-body">
            <div class="hm-card-title">{{ c.title }}</div>
            <div class="hm-card-desc">{{ c.desc }}</div>
          </div>
          <ArrowRight :size="16" class="hm-card-arrow" />
        </router-link>
      </div>
    </section>

    <!-- Locations (providers): each venue, its schedule & the services/listings inside -->
    <div v-if="isProvider" class="hm-section">
      <div class="hm-section-head">
        <MapPin :size="16" /> Your locations
        <router-link to="/listings" class="hm-loc-manage">Manage <ArrowRight :size="13" /></router-link>
      </div>

      <div v-if="locLoading" class="hm-loc-empty">Loading your locations…</div>

      <div v-else-if="!locations.length" class="hm-loc-empty">
        <Building2 :size="26" />
        <div>No locations yet.</div>
        <router-link to="/listings" class="hm-btn hm-btn-primary"><Plus :size="15" /> Add your first location</router-link>
      </div>

      <div v-else class="hm-loc-list">
        <div v-for="loc in locations" :key="loc.id" class="hm-loc" :class="{ open: openLocId === loc.id }">
          <!-- Clickable header -->
          <button type="button" class="hm-loc-head" @click="toggleLoc(loc.id)">
            <div class="hm-loc-thumb" :style="loc.photos && loc.photos[0] ? { backgroundImage: `url(${loc.photos[0]})` } : {}">
              <Building2 v-if="!(loc.photos && loc.photos[0])" :size="20" />
            </div>
            <div class="hm-loc-main">
              <div class="hm-loc-name">{{ loc.name }}</div>
              <div class="hm-loc-meta">
                <span v-if="loc.cityName || loc.address"><MapPin :size="12" /> {{ [loc.address, loc.cityName].filter(Boolean).join(', ') }}</span>
              </div>
              <div class="hm-loc-counts">
                <span class="hm-loc-chip"><LayoutGrid :size="12" /> {{ loc.listings.length }} listing{{ loc.listings.length === 1 ? '' : 's' }}</span>
                <span v-if="loc.programs.length" class="hm-loc-chip"><Dumbbell :size="12" /> {{ loc.programs.length }} program{{ loc.programs.length === 1 ? '' : 's' }}</span>
              </div>
            </div>
            <ChevronDown :size="18" class="hm-loc-chev" />
          </button>

          <!-- Expanded: schedule + services -->
          <div v-if="openLocId === loc.id" class="hm-loc-body">
            <div class="hm-loc-cols">
              <!-- Schedule -->
              <div class="hm-loc-block">
                <div class="hm-loc-block-h"><Clock :size="14" /> Weekly schedule</div>
                <div class="hm-sched">
                  <div v-for="d in scheduleFor(loc)" :key="d.day" class="hm-sched-row" :class="{ closed: !d.open }">
                    <span class="hm-sched-day">{{ d.day }}</span>
                    <span v-if="d.open" class="hm-sched-times">{{ d.periods.join(', ') }}</span>
                    <span v-else class="hm-sched-closed">Closed</span>
                  </div>
                </div>
              </div>

              <!-- Services / listings + programs -->
              <div class="hm-loc-block">
                <div class="hm-loc-block-h"><LayoutGrid :size="14" /> Services &amp; listings</div>
                <div v-if="loc.listings.length || loc.programs.length" class="hm-svc-list">
                  <router-link v-for="l in loc.listings" :key="'l'+l.id" to="/listings" class="hm-svc">
                    <span class="hm-svc-name">{{ l.name }}</span>
                    <span v-if="l.sport_category" class="hm-svc-tag">{{ l.sport_category }}</span>
                    <span class="hm-svc-status" :class="l.status === 'active' ? 'on' : 'off'">{{ l.status === 'active' ? 'Active' : (l.status || '—') }}</span>
                  </router-link>
                  <router-link v-for="p in loc.programs" :key="'p'+p.id" to="/listings" class="hm-svc">
                    <span class="hm-svc-name"><Dumbbell :size="12" /> {{ p.name }}</span>
                    <span class="hm-svc-tag prog">Program</span>
                    <span class="hm-svc-status" :class="p.status === 'active' ? 'on' : 'off'">{{ p.status === 'active' ? 'Active' : (p.status || '—') }}</span>
                  </router-link>
                </div>
                <div v-else class="hm-svc-empty">
                  No listings here yet.
                  <router-link to="/listings" class="hm-svc-add"><Plus :size="13" /> Add one</router-link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.hm { padding: 28px 32px 48px; max-width: 1200px; }
.hm-hero h1 { font-size: 26px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.hm-hero p { font-size: 15px; color: var(--text-muted, #64748b); margin: 6px 0 0; }

/* Provider website card */
.hm-site { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  margin-top: 22px; padding: 18px 20px; border: 1px solid var(--border); border-radius: 16px;
  background: linear-gradient(120deg, var(--primary-soft, #f0fdfa), var(--surface, #ffffff)); }
.hm-site-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.hm-site-ic { width: 44px; height: 44px; border-radius: 12px; background: var(--brand, #0f766e); color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hm-site-title { font-size: 14px; font-weight: 800; color: var(--text, #0f172a); }
.hm-site-url { font-size: 13.5px; color: var(--primary-ink, #0f766e); font-weight: 600; word-break: break-all; }
.hm-site-url.muted { color: var(--text-light, #94a3b8); font-weight: 500; }
.hm-site-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.hm-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text, #0f172a); font-size: 13.5px; font-weight: 700; cursor: pointer; text-decoration: none; }
.hm-btn:hover { border-color: var(--brand-accent, #14b8a6); }
.hm-btn-primary { background: var(--brand, #0f766e); color: #fff; border-color: transparent; }

/* Sections */
.hm-section { margin-top: 30px; }
.hm-section-head { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-bottom: 14px; }
.hm-section-head svg { color: var(--primary-ink, #0f766e); }

/* Setup guide (live checklist) */
.hm-guide-count { margin-left: auto; font-size: 12px; font-weight: 800; color: var(--primary-ink, #0f766e); text-transform: none; letter-spacing: 0; }
.hm-guide-bar { height: 6px; border-radius: 999px; background: var(--border, #e2e8f0); overflow: hidden; margin-bottom: 14px; }
.hm-guide-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2dd4bf, var(--brand, #0f766e)); transition: width .3s; }
.hm-steps { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
.hm-step { display: flex; align-items: center; gap: 12px; padding: 13px 15px; border: 1.5px solid var(--border); border-radius: 12px; background: var(--surface, #ffffff); text-decoration: none; transition: border-color .12s, transform .12s, box-shadow .12s; }
.hm-step:hover { border-color: var(--brand-accent, #14b8a6); transform: translateY(-1px); }
.hm-step.next { border-color: var(--brand, #0f766e); box-shadow: 0 8px 22px rgba(15,118,110,.1); }
.hm-step.done { background: var(--surface-hover, #fafcfb); }
.hm-step.done .hm-step-label b { color: var(--text-muted, #64748b); }
.hm-step-n { width: 26px; height: 26px; border-radius: 50%; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hm-step-n.ok { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #16a34a); }
.hm-step-label { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.hm-step-label b { font-size: 14px; font-weight: 700; color: var(--text, #0f172a); }
.hm-step-label small { font-size: 12px; color: var(--text-light, #94a3b8); line-height: 1.4; }
.hm-step-next { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #fff; background: var(--brand, #0f766e); border-radius: 999px; padding: 2px 8px; margin-inline-start: 6px; vertical-align: 2px; font-style: normal; }
.hm-step-arrow { color: var(--text-light, #cbd5e1); flex-shrink: 0; }
.hm-step:hover .hm-step-arrow { color: var(--primary-ink, #0f766e); }
.hm-step-share-actions { display: flex; gap: 6px; flex-shrink: 0; }

/* Guidance grid */
.hm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.hm-card { display: flex; align-items: flex-start; gap: 13px; padding: 16px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface, #ffffff); text-decoration: none; transition: border-color .12s, box-shadow .12s, transform .12s; }
.hm-card:hover { border-color: var(--brand-accent, #14b8a6); box-shadow: 0 8px 22px rgba(15,23,42,.07); transform: translateY(-2px); }
.hm-card-ic { width: 40px; height: 40px; border-radius: 11px; background: var(--bg, #f1f5f9); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hm-card-body { flex: 1; min-width: 0; }
.hm-card-title { font-size: 14.5px; font-weight: 800; color: var(--text, #0f172a); }
.hm-card-desc { font-size: 13px; color: var(--text-muted, #64748b); line-height: 1.45; margin-top: 3px; }
.hm-card-arrow { color: var(--text-light, #cbd5e1); flex-shrink: 0; margin-top: 9px; }
.hm-card:hover .hm-card-arrow { color: var(--primary-ink, #0f766e); }

/* Locations showcase */
.hm-loc-manage { margin-left: auto; display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: var(--primary-ink, #0f766e); text-decoration: none; text-transform: none; letter-spacing: 0; }
.hm-loc-manage:hover { text-decoration: underline; }
.hm-loc-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 32px 16px; border: 1px dashed var(--border); border-radius: 14px; color: var(--text-light, #94a3b8); font-size: 14px; text-align: center; }
.hm-loc-empty svg { color: var(--text-light, #cbd5e1); }
.hm-loc-list { display: flex; flex-direction: column; gap: 10px; }
.hm-loc { border: 1px solid var(--border); border-radius: 14px; background: var(--surface, #ffffff); overflow: hidden; transition: border-color .12s, box-shadow .12s; }
.hm-loc.open { border-color: var(--brand-accent, #14b8a6); box-shadow: 0 8px 22px rgba(15,23,42,.06); }
.hm-loc-head { width: 100%; display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: none; border: none; cursor: pointer; text-align: left; }
.hm-loc-head:hover { background: var(--surface-hover, #f8fafc); }
.hm-loc-thumb { width: 46px; height: 46px; border-radius: 11px; background: var(--bg, #f1f5f9); background-size: cover; background-position: center; color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hm-loc-main { flex: 1; min-width: 0; }
.hm-loc-name { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.hm-loc-meta { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.hm-loc-meta span { display: inline-flex; align-items: center; gap: 4px; }
.hm-loc-meta svg { color: var(--text-light, #94a3b8); }
.hm-loc-counts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
.hm-loc-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--text-secondary, #475569); background: var(--bg, #f1f5f9); border-radius: 999px; padding: 2px 9px; }
.hm-loc-chev { color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .18s; }
.hm-loc.open .hm-loc-chev { transform: rotate(180deg); }
.hm-loc-body { padding: 4px 16px 18px; border-top: 1px solid var(--border); }
.hm-loc-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 22px; margin-top: 14px; }
.hm-loc-block-h { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-bottom: 10px; }
.hm-loc-block-h svg { color: var(--primary-ink, #0f766e); }
/* Schedule */
.hm-sched { display: flex; flex-direction: column; gap: 2px; }
.hm-sched-row { display: flex; align-items: baseline; gap: 10px; font-size: 13px; padding: 4px 0; border-bottom: 1px dashed var(--border, #f1f5f9); }
.hm-sched-row.closed { color: var(--text-light, #94a3b8); }
.hm-sched-day { width: 42px; font-weight: 800; color: var(--text-secondary, #334155); flex-shrink: 0; }
.hm-sched-row.closed .hm-sched-day { color: var(--text-light, #94a3b8); }
.hm-sched-times { color: var(--text, #0f172a); font-weight: 600; }
.hm-sched-closed { color: var(--text-light, #cbd5e1); font-weight: 600; }
/* Services */
.hm-svc-list { display: flex; flex-direction: column; gap: 7px; }
.hm-svc { display: flex; align-items: center; gap: 9px; padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; text-decoration: none; background: var(--surface, #ffffff); }
.hm-svc:hover { border-color: var(--brand-accent, #14b8a6); background: var(--surface-hover, #f8fafc); }
.hm-svc-name { flex: 1; min-width: 0; display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hm-svc-name svg { color: var(--primary-ink, #0f766e); flex-shrink: 0; }
.hm-svc-tag { font-size: 10.5px; font-weight: 800; color: var(--text-secondary, #475569); background: var(--bg, #f1f5f9); border-radius: 999px; padding: 2px 8px; text-transform: capitalize; flex-shrink: 0; }
.hm-svc-tag.prog { color: var(--purple-ink, #7c3aed); background: var(--purple-bg, #f3e8ff); }
.hm-svc-status { font-size: 10.5px; font-weight: 800; flex-shrink: 0; }
.hm-svc-status.on { color: var(--positive-ink, #16a34a); }
.hm-svc-status.off { color: var(--text-light, #94a3b8); }
.hm-svc-empty { font-size: 13px; color: var(--text-light, #94a3b8); display: flex; align-items: center; gap: 8px; }
.hm-svc-add { display: inline-flex; align-items: center; gap: 4px; font-weight: 700; color: var(--primary-ink, #0f766e); text-decoration: none; }
.hm-svc-add:hover { text-decoration: underline; }

@media (max-width: 600px) {
  .hm { padding: 20px 16px 40px; }
  .hm-hero h1 { font-size: 22px; }
  .hm-grid, .hm-steps { grid-template-columns: 1fr; }
  .hm-loc-cols { grid-template-columns: 1fr; gap: 18px; }
  /* 360px: long site URLs must wrap instead of painting past the card edge */
  .hm-step-label small { word-break: break-all; }
  /* Share step: drop the Copy/Open actions to a second row under the label */
  .hm-step-share { flex-wrap: wrap; }
  .hm-step-share-actions { width: 100%; margin-inline-start: 38px; }
  /* Touch targets ≥44px on phone */
  .hm-btn { min-height: 44px; }
  /* Keep all text ≥11px on phone */
  .hm-step-next { font-size: 11px; }
  .hm-svc-tag, .hm-svc-status { font-size: 11px; }
  /* Service rows: give the name its own full first line so it stops truncating to a few chars; tag + status wrap onto a second line, both kept visible */
  .hm-svc { flex-wrap: wrap; }
  .hm-svc-name { flex: 1 1 100%; order: 1; white-space: normal; overflow: visible; text-overflow: clip; overflow-wrap: anywhere; }
  .hm-svc-tag { order: 2; }
  .hm-svc-status { order: 3; margin-inline-start: auto; }
  /* Website card actions fill the row cleanly instead of wrapping raggedly */
  .hm-site-actions { width: 100%; }
  .hm-site-actions .hm-btn { flex: 1 1 auto; justify-content: center; }
}
</style>
