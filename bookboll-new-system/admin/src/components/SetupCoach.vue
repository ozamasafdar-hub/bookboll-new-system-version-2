<script setup>
/**
 * SetupCoach — the provider setup guide as a floating bottom-right coach.
 * Shows ONE step at a time (venue+listing → publish site → connect payouts)
 * with real completion detection, a single CTA, Skip, and "Don't show again".
 * Minimises to a small progress pill; disappears forever once dismissed or
 * after the all-done celebration. Providers only.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadOverview, setupChangedAt } from '../lib/overview.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { brandPreviewUrl, brandSiteUrl, providerSlug } from '../lib/brand.js'
import { pushToast } from '../lib/toast.js'
import { askConfirm } from '../lib/confirm.js'
import { Rocket, Building2, LayoutGrid, GraduationCap, Palette, Check, X, Copy, ExternalLink, PartyPopper, ChevronLeft, ChevronRight } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const { currentUser, role, currentOwnerUserId } = useCurrentUser()
const isProvider = computed(() => role.value === 'provider_admin' || role.value === 'facility_admin')

// ── persisted user choice: '' (open) · 'min' (pill) · 'off' (never again) ──
const KEY = computed(() => `bb_setupcoach_${currentOwnerUserId.value || 'anon'}`)
const mode = ref('')
function readMode() { try { mode.value = localStorage.getItem(KEY.value) || '' } catch { mode.value = '' } }
function setMode(m) { mode.value = m; try { localStorage.setItem(KEY.value, m) } catch { /* ignore */ } }
watch(KEY, readMode, { immediate: true })

// ── live completion state (cheap, owner-scoped; refreshed on page change) ──
const loaded = ref(false)
const hasLocation = ref(false)
const hasListing = ref(false)
const siteLive = computed(() => currentUser.value?.brand_enabled === true)

async function refresh() {
  if (!isProvider.value || mode.value === 'off') return
  try {
    // has_listing already folds in programmes, which can exist before any venue.
    const { setup } = await loadOverview()
    hasLocation.value = !!setup.has_location
    hasListing.value = !!setup.has_listing
    loaded.value = true
  } catch { /* keep previous state */ }
}
onMounted(refresh)
watch(() => route.fullPath, () => { if (visible.value) refresh() })
watch(currentOwnerUserId, refresh)
// A venue or listing is saved in a modal on the page you are already on — no
// navigation happens, so without this the coach never notices and keeps asking
// for the step the provider has just completed.
watch(setupChangedAt, () => { viewIdx.value = -1; refresh() })

// ── steps (ONE line of text each — the coach stays light). Payouts are NOT a
// step: they're optional, and the listing/membership flows already surface the
// payout nudge exactly where it matters. Each step keeps a follow-up action for
// after it's done (add another / edit), so revisiting a step stays useful. ──
const steps = computed(() => [
  { key: 'venue',   icon: Building2,  title: 'Add your venue',          sub: 'Your place — name, map pin & hours.',         cta: 'Add your venue', to: '/listings?add=location', doneCta: 'Add another venue', done: hasLocation.value },
  { key: 'listing', icon: LayoutGrid, title: 'Add your first listing',  sub: 'A court, space or membership program in it.', cta: 'Add a listing',  to: '',                       doneCta: 'Add another listing', done: hasListing.value },
  { key: 'website', icon: Palette,    title: 'Publish your website',    sub: 'Logo & colours — then go live.',              cta: 'Customise site', to: '/brand',                 doneCta: 'Edit your website', done: siteLive.value },
])
const doneCount = computed(() => steps.value.filter(s => s.done).length)
const allDone = computed(() => doneCount.value === steps.value.length)

// Which step the card shows: the first incomplete one by default; the chevrons
// walk through ALL steps (completed ones show a Done state + follow-up action).
const viewIdx = ref(-1)   // -1 = automatic (first incomplete step)
const firstIncomplete = computed(() => steps.value.findIndex(s => !s.done))
const currentIdx = computed(() => {
  if (viewIdx.value >= 0) return viewIdx.value % steps.value.length
  return firstIncomplete.value === -1 ? 0 : firstIncomplete.value
})
// allDone with no manual browsing → celebration view (current = null)
const current = computed(() => (allDone.value && viewIdx.value < 0) ? null : steps.value[currentIdx.value])
function stepNav(dir) { viewIdx.value = ((currentIdx.value + dir) % steps.value.length + steps.value.length) % steps.value.length }
const currentNumber = computed(() => currentIdx.value + 1)

// "Don't show again" asks once — short, honest pitch before it's gone for good.
async function dismissForever() {
  const ok = await askConfirm({
    title: 'Hide the setup guide?', danger: false,
    message: 'These 3 steps are how your business goes live — listed, published, and able to get paid online. Once hidden, the guide won\'t come back.',
    confirmText: 'Hide it', cancelText: 'Keep guiding me',
  })
  if (ok) setMode('off')
}

// Hide on the pages the current CTA points to? No — the coach is a map, keep it.
// But never render for non-providers, before state loads, or when dismissed.
const visible = computed(() => isProvider.value && mode.value !== 'off' && loaded.value)

// The listing step asks WHICH kind first (inline chooser), then deep-links
// straight into that form via /listings?add=… — no hunting on the page.
const choosingType = ref(false)
function go() {
  if (!current.value) return
  if (current.value.key === 'listing') { choosingType.value = true; return }
  router.push(current.value.to)
}
function pickType(kind) {
  choosingType.value = false
  if (kind === 'program') router.push({ path: '/memberships', query: { new: '1' } })
  else router.push(`/listings?add=${kind}`)
}
watch(current, () => { choosingType.value = false })

// ── all-done celebration: share link, then Done dismisses forever ──
// A verified custom domain is the address customers use, so it wins over the subdomain.
const customDomain = computed(() => currentUser.value?.brand_domain_status === 'active'
  ? (currentUser.value?.brand_custom_domain || '')
  : '')
const siteUrl = computed(() => {
  if (customDomain.value) return `https://${customDomain.value}`
  const s = providerSlug(currentUser.value)
  return s ? brandPreviewUrl(s) : ''
})
async function copySite() {
  try { await navigator.clipboard.writeText(siteUrl.value); pushToast('Website link copied', 'success') } catch { /* ignore */ }
}
// Published → the provider's real, shareable address; otherwise the preview by owner id,
// which still works before publishing and on hosts without wildcard DNS.
function openSite() {
  if (!currentUser.value?.id) return
  window.open(brandSiteUrl({
    slug: providerSlug(currentUser.value),
    ownerId: currentUser.value.id,
    published: siteLive.value,
    customDomain: customDomain.value,
  }), '_blank', 'noopener')
}
</script>

<template>
  <div v-if="visible" class="sc" :class="{ 'sc-min': mode === 'min' }">
    <!-- Minimised: a small progress pill -->
    <button v-if="mode === 'min'" type="button" class="sc-pill" @click="setMode('')" title="Setup guide">
      <Rocket :size="15" />
      <span>{{ doneCount }}/{{ steps.length }}</span>
    </button>

    <!-- Expanded card -->
    <div v-else class="sc-card">
      <div class="sc-head">
        <span class="sc-head-t"><Rocket :size="14" /> Setup</span>
        <span class="sc-dots">
          <i v-for="s in steps" :key="s.key" :class="{ on: s.done, cur: current && s.key === current.key }"></i>
        </span>
        <button type="button" class="sc-x" title="Minimise" @click="setMode('min')"><X :size="15" /></button>
      </div>

      <!-- One step at a time -->
      <template v-if="current">
        <div class="sc-body">
          <span class="sc-ic" :class="{ 'sc-ic-done': current.done }">
            <Check v-if="current.done" :size="20" />
            <component v-else :is="current.icon" :size="20" />
          </span>
          <div class="sc-txt">
            <div class="sc-step">
              Step {{ currentNumber }} of {{ steps.length }}
              <span v-if="current.done" class="sc-done-chip"><Check :size="10" /> Done</span>
            </div>
            <div class="sc-title">{{ current.title }}</div>
            <div class="sc-sub">{{ current.sub }}</div>
          </div>
        </div>
        <!-- Listing step: pick the kind, land directly in that form -->
        <div v-if="choosingType" class="sc-choose">
          <button type="button" class="sc-choice" @click="pickType('listing')">
            <LayoutGrid :size="17" />
            <span><b>Booking Listing</b><small>Booked by the hour — court, studio, room</small></span>
          </button>
          <button type="button" class="sc-choice" @click="pickType('program')">
            <GraduationCap :size="17" />
            <span><b>Membership Listing</b><small>A recurring class or membership</small></span>
          </button>
        </div>
        <button v-else type="button" class="sc-cta" :class="{ 'sc-cta-ghost': current.done }" @click="go">
          {{ current.done ? current.doneCta : current.cta }}
        </button>
        <div class="sc-foot">
          <span class="sc-nav">
            <button type="button" class="sc-nav-btn" title="Previous step" @click="stepNav(-1)"><ChevronLeft :size="15" /></button>
            <button type="button" class="sc-nav-btn" title="Next step" @click="stepNav(1)"><ChevronRight :size="15" /></button>
          </span>
          <button type="button" class="sc-link sc-mute" @click="dismissForever">Don't show again</button>
        </div>
      </template>

      <!-- All done → one-time celebration, then gone for good -->
      <template v-else>
        <div class="sc-body">
          <span class="sc-ic sc-ic-done"><PartyPopper :size="20" /></span>
          <div class="sc-txt">
            <div class="sc-title">You're all set!</div>
            <div class="sc-sub">Share your website and take bookings.</div>
          </div>
        </div>
        <div class="sc-share">
          <button type="button" class="sc-cta sc-cta-ghost" @click="copySite"><Copy :size="14" /> Copy link</button>
          <button type="button" class="sc-cta sc-cta-ghost" @click="openSite"><ExternalLink :size="14" /> Open</button>
        </div>
        <button type="button" class="sc-cta" @click="setMode('off')"><Check :size="15" /> Done</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* Keep setup help below modal backdrops (50), including owner invitations. */
.sc { position: fixed; right: 18px; bottom: 18px; z-index: 45; }

/* pill */
.sc-pill {
  display: inline-flex; align-items: center; gap: 7px; padding: 11px 16px;
  background: var(--primary, #0f766e); color: #fff; border: none; border-radius: 999px;
  font-size: 13.5px; font-weight: 800; cursor: pointer;
  box-shadow: 0 0 0 4px rgba(13, 148, 136, .15), 0 8px 24px rgba(15, 23, 42, .3);
}
.sc-pill:hover { filter: brightness(1.06); }

/* card — a strong teal edge + accent bar so it stands out on light AND dark
   pages (drop shadows disappear on a black background). */
.sc-card {
  width: 344px; background: var(--surface, #ffffff);
  border: 1.5px solid rgba(13, 148, 136, .55);
  border-radius: 18px; padding: 16px; overflow: hidden; position: relative;
  box-shadow: 0 0 0 4px rgba(13, 148, 136, .12), 0 18px 48px rgba(15, 23, 42, .25);
}
.sc-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, #0d9488, #22c55e);
}
.sc-head { display: flex; align-items: center; gap: 8px; margin: 2px 0 14px; }
.sc-head-t { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--primary-ink, #0f766e); }
.sc-dots { display: inline-flex; align-items: center; gap: 4px; margin-left: 2px; flex: 1; }
.sc-dots i { width: 7px; height: 7px; border-radius: 50%; background: var(--border, #e2e8f0); }
.sc-dots i.on { background: #22c55e; }
.sc-dots i.cur { background: var(--primary, #0f766e); transform: scale(1.25); }
.sc-x { background: none; border: none; color: var(--text-light, #94a3b8); cursor: pointer; padding: 3px; border-radius: 6px; display: inline-flex; }
.sc-x:hover { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); }

.sc-body { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
.sc-ic { width: 46px; height: 46px; border-radius: 13px; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sc-ic-done { background: var(--positive-bg, #f0fdf4); color: var(--positive-ink, #16a34a); }
.sc-txt { min-width: 0; }
.sc-step { display: flex; align-items: center; gap: 7px; font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; color: var(--primary-ink, #0f766e); }
.sc-done-chip { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); color: var(--positive-ink, #15803d); font-size: 10px; letter-spacing: .02em; }
.sc-title { font-size: 16px; font-weight: 800; color: var(--text, #0f172a); line-height: 1.3; margin: 3px 0; }
.sc-sub { font-size: 13px; color: var(--text-muted, #64748b); line-height: 1.5; }

.sc-cta {
  width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  padding: 12px; border: none; border-radius: 11px; background: var(--primary, #0f766e);
  color: #fff; font-size: 14.5px; font-weight: 800; cursor: pointer;
}
.sc-cta:hover { filter: brightness(1.06); }
.sc-cta-ghost { background: var(--surface, #ffffff); color: var(--primary-ink, #0f766e); border: 1.5px solid var(--primary, #0f766e); }
/* listing-kind chooser */
.sc-choose { display: flex; flex-direction: column; gap: 8px; }
.sc-choice {
  display: flex; align-items: center; gap: 10px; text-align: left; width: 100%;
  padding: 10px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 11px;
  background: var(--surface, #ffffff); color: var(--primary-ink, #0f766e); cursor: pointer; transition: all .12s;
}
.sc-choice:hover { border-color: var(--primary, #0f766e); background: var(--primary-soft, #f0fdfa); }
.sc-choice span { display: flex; flex-direction: column; min-width: 0; }
.sc-choice b { font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); }
.sc-choice small { font-size: 11.5px; color: var(--text-muted, #64748b); }
.sc-share { display: flex; gap: 8px; margin-bottom: 8px; }
.sc-share .sc-cta { padding: 8px; font-size: 12.5px; }

.sc-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }
.sc-nav { display: inline-flex; gap: 6px; }
.sc-nav-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
  border: 1px solid var(--border, #e2e8f0); border-radius: 8px; background: var(--surface, #ffffff);
  color: var(--text-muted, #64748b); cursor: pointer;
}
.sc-nav-btn:hover { border-color: var(--primary, #0f766e); color: var(--primary-ink, #0f766e); }
.sc-link { background: none; border: none; font-size: 12px; font-weight: 700; color: var(--primary-ink, #0f766e); cursor: pointer; padding: 2px 0; }
.sc-link:hover { text-decoration: underline; }
.sc-mute { color: var(--text-light, #94a3b8); font-weight: 600; }

@media (max-width: 600px) {
  .sc { right: 12px; bottom: 76px; }   /* clear the mobile bottom areas */
  .sc-card { width: min(300px, calc(100vw - 24px)); }
}
</style>
