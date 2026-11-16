<script setup>
/**
 * Moderation.vue — Bookboll platform admins review user-hosted events (games &
 * tournaments) and take down indecent / spam ones.
 *
 *   • Flag    → internal "needs review" marker; the event stays live.
 *   • Remove  → hidden from the customer storefront + the host is notified
 *               (a derived notice appears in their bell, see notifications.js).
 *   • Restore → back to active / live.
 *
 * Events + Communities moderation state lives on Laravel; the storefront reads
 * moderation_status off the same rows, so a change here is live immediately.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { loadReferenceData } from '../lib/referenceData.js'
import { apiFetch } from '../lib/api.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { useConfirm } from '../lib/confirm.js'
import { useInputPrompt } from '../lib/inputPrompt.js'
import { useToast } from '../lib/toast.js'
import { webBaseUrl } from '../lib/appBase.js'
import WhatsappIcon from '../components/WhatsappIcon.vue'
import {
  Flag, Search, MapPin, CalendarDays, Users, Gift, Trophy, Eye, Trash2,
  RotateCcw, AlertTriangle, ShieldCheck, X, ChevronLeft, ChevronRight,
  ImageOff, ExternalLink, RefreshCw,
} from 'lucide-vue-next'
import FilterBar from '../components/FilterBar.vue'
import { matchesCityGeo } from '../lib/geoFilter.js'
import { fmt12, fmtRange12 } from '../lib/slots.js'
import {
  fetchEventModerationDashboard,
  flagEvent as apiFlagEvent,
  removeEvent as apiRemoveEvent,
  restoreEvent as apiRestoreEvent,
  firstError as modErr,
} from '../lib/moderationEvents.js'
import {
  fetchCommunityModerationDashboard,
  flagCommunity as apiFlagCommunity,
  removeCommunity as apiRemoveCommunity,
  restoreCommunity as apiRestoreCommunity,
  firstError as commErr,
} from '../lib/communityModeration.js'

const { currentUser, isSuperAdmin, isCountryAdmin, currentOwnerUserId, role } = useCurrentUser()
// Platform roles see events in their jurisdiction (super admin: everywhere);
// providers see only events held at their own venues.
const isPlatformAdmin = computed(() =>
  isSuperAdmin.value
  || isCountryAdmin.value
  || role.value === 'platform_staff'
  || role.value === 'admin'
)
const confirm = useConfirm()
const inputPrompt = useInputPrompt()
const toast = useToast()

const rows = ref([])
const cities = ref([])
const countries = ref([])
// Venue payloads carry city_id / country_id as strings, reference-data cities[] and
// countries[] carry integers — a strict === between them never matches. Same helper
// as Wallet.vue and Listings.vue.
const cid = v => (v === null || v === undefined || v === '') ? '' : String(v)
// All venues (id, city_id) — loaded for country_admin / platform_staff so the
// jurisdiction helper can map each venue to its country.
const allLocations = ref([])
const { allowedLocationIds, jurisdictionCountryIds, scopeCountries, scopeCities } = useJurisdiction({ locations: allLocations, cities })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))
const loading = ref(true)
const tab = ref('all')          // all | active | flagged | removed
const section = ref('events')   // events | communities (platform admins only)
const search = ref('')
const filterCountry = ref([])
const filterCity = ref([])
const commRows = ref([])        // communities (+ member/post counts), platform-admin only

const detail = ref(null)        // event open in the detail modal (a row ref)

// fullscreen image lightbox
const lbList = ref([])
const lbIdx = ref(0)
const lbOpen = ref(false)

// ── load ──────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true

  // reference-data is already scoped by the API: a provider gets only the venues
  // their business owns, a platform admin gets their jurisdiction.
  const ref = await loadReferenceData()
  cities.value = ref.cities
  countries.value = ref.countries
  allLocations.value = ref.venues

  let scopedLocationIds = null
  if (!isPlatformAdmin.value) {
    scopedLocationIds = ref.venues.map(l => String(l.id))
    if (!scopedLocationIds.length) {   // provider with no venues → nothing to moderate
      rows.value = []; loading.value = false; return
    }
  }

  const dash = await fetchEventModerationDashboard()

  let games = []
  const joinStats = dash.success ? (dash.data?.join_stats || {}) : {}
  if (!dash.success) {
    toast.error(modErr(dash, 'Could not load events.'))
  } else {
    games = dash.data?.games || []
  }

  if (scopedLocationIds) {
    const allow = new Set(scopedLocationIds.map(String))
    games = games.filter(x => x.location_id && allow.has(String(x.location_id)))
  }

  // Jurisdiction (country_admin / platform_staff): only events at venues in scope,
  // plus custom-location events whose city is inside the jurisdiction's countries.
  if (isPlatformAdmin.value && !isSuperAdmin.value) {
    const allowed = allowedLocationIds.value
    const cSet = jurisdictionCountryIds.value
    if (allowed) games = games.filter(x => x.location_id
      ? allowed.has(x.location_id)
      : cSet.has(cities.value.find(c => cid(c.id) === cid(x.city_id))?.country_id))
  }

  const locName = id => allLocations.value.find(l => String(l.id) === String(id))?.name || ''

  rows.value = games.map(x => {
    const stats = joinStats[x.id] || joinStats[String(x.id)] || {}
    return {
      ...x,
      moderation_status: x.moderation_status || 'active',
      locationName: locName(x.location_id),
      cityName: cities.value.find(c => cid(c.id) === cid(x.city_id))?.name || '',
      joinedCount: stats.accepted || 0,
      pendingCount: stats.pending || 0,
    }
  })

  // Communities (platform admins) — Laravel + owner names from Supabase customers.
  if (isPlatformAdmin.value) {
    try {
      const cDash = await fetchCommunityModerationDashboard()
      if (!cDash.success) {
        toast.error(commErr(cDash, 'Could not load communities.'))
        commRows.value = []
      } else {
        let comms = cDash.data?.communities || []
        const memberStats = cDash.data?.member_stats || {}
        if (!isSuperAdmin.value) {
          const cSet = jurisdictionCountryIds.value
          comms = comms.filter(x => !x.country_id || cSet.has(x.country_id))
        }
        commRows.value = comms.map(x => {
          const stats = memberStats[x.id] || memberStats[String(x.id)] || {}
          return {
            ...x,
            moderation_status: x.moderation_status || 'active',
            cityName: x.city_name || cities.value.find(c => cid(c.id) === cid(x.city_id))?.name || '',
            countryName: x.country_name || countries.value.find(k => cid(k.id) === cid(x.country_id || cities.value.find(c => cid(c.id) === cid(x.city_id))?.country_id))?.name || '',
            ownerName: x.owner_name || 'Player',
            memberCount: stats.accepted || 0,
          }
        })
        fillMissingCities(commRows.value)
      }
    } catch {
      commRows.value = []
    }
  }
  loading.value = false
}
onMounted(load)
onUnmounted(() => document.removeEventListener('keydown', onLbKey))

// ── derived ─────────────────────────────────────────────────────────────────
const counts = computed(() => {
  const src = section.value === 'communities' ? commRows.value : rows.value
  const c = { all: src.length, active: 0, flagged: 0, removed: 0 }
  src.forEach(r => { c[r.moderation_status] = (c[r.moderation_status] || 0) + 1 })
  return c
})

const commFiltered = computed(() => {
  const q = search.value.toLowerCase().trim()
  return commRows.value.filter(r => {
    if (tab.value !== 'all' && r.moderation_status !== tab.value) return false
    if ((filterCountry.value.length || filterCity.value.length) && !matchesCityGeo(r.city_id, cities.value, filterCountry.value, filterCity.value)) return false
    if (q) {
      const hay = `${r.name || ''} ${r.sport || ''} ${r.ownerName || ''} ${r.cityName} ${r.countryName || ''}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  })
})

const filtered = computed(() => {
  const q = search.value.toLowerCase().trim()
  return rows.value.filter(r => {
    if (tab.value !== 'all' && r.moderation_status !== tab.value) return false
    if ((filterCountry.value.length || filterCity.value.length) && !matchesCityGeo(r.city_id, cities.value, filterCountry.value, filterCity.value)) return false
    if (q) {
      const hay = `${r.title || ''} ${r.sport || ''} ${r.host_name || ''} ${r.locationName} ${r.custom_location_name || ''} ${r.cityName}`.toLowerCase()
      if (!hay.includes(q)) return false
    }
    return true
  })
})

// ── helpers ─────────────────────────────────────────────────────────────────
function imagesOf(g) {
  let v = g?.images
  if (!v) return []
  if (typeof v === 'string') { try { v = JSON.parse(v) } catch { return v ? [v] : [] } }
  return Array.isArray(v) ? v.filter(Boolean) : []
}
const coverOf = g => imagesOf(g)[0] || ''
const typeLabel = g => (g.game_type === 'tournament' ? 'Tournament' : 'Game')
// Reference data carries only cities that have a venue, so a community based in
// a city with none (a Lahore cricket club, say) resolves its country but not its
// city. Fetch those few by id from the public catalogue and patch the rows —
// once per id, in the background, never blocking the queue.
const cityById = new Map()
async function fillMissingCities(rows) {
  const ids = [...new Set(rows.filter(r => !r.cityName && r.city_id).map(r => String(r.city_id)))]
  await Promise.all(ids.map(async id => {
    if (!cityById.has(id)) {
      cityById.set(id, apiFetch(`/marketplace/cities/${encodeURIComponent(id)}`).then(res => (res.success ? res.data?.city || null : null)).catch(() => null))
    }
    const city = await cityById.get(id)
    if (!city) return
    for (const r of rows) {
      if (String(r.city_id) !== id) continue
      r.cityName = r.cityName || city.name || ''
      r.countryName = r.countryName || city.country?.name || ''
    }
  }))
}
const titleOf = g => g.title || g.sport || (g.game_type === 'tournament' ? 'Tournament' : 'Pickup game')
const firstName = g => (g.host_name || 'there').trim().split(' ')[0]

function whenLabel(g) {
  if (!g.game_date) return 'Flexible date'
  const d = new Date(g.game_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
  const t = g.start_time ? ` · ${g.end_time ? fmtRange12(g.start_time, g.end_time) : fmt12(g.start_time)}` : ''
  return d + t
}
function placeLabel(g) { return g.custom_location_name || g.locationName || g.cityName || 'Location not set' }
function spotsLabel(g) {
  const j = g.joinedCount || 0
  return Number(g.max_players) ? `${j}/${g.max_players} joined` : `${j} joined`
}
function fmtDate(d) { return d ? new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '' }
function timeAgo(d) {
  if (!d) return ''
  const s = Math.max(0, (Date.now() - new Date(d).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}
// Communities and public profiles live on the customer web app, not here: a
// relative href opened admin.bookboll.com/communities/7, which has no route
// and rendered blank.
const profileHref = id => (id ? `${webBaseUrl()}/u/${id}` : '')
// The public page resolves by uuid only (CustomerCommunityService::show), never
// the numeric id the moderation rows are keyed by.
const communityHref = c => (c?.uuid ? `${webBaseUrl()}/communities/${c.uuid}` : '')
function waLink(num, msg) {
  const digits = String(num || '').replace(/[^\d]/g, '')
  if (!digits) return ''
  return `https://wa.me/${digits}${msg ? '?text=' + encodeURIComponent(msg) : ''}`
}
function hostMsg(g) {
  const f = firstName(g)
  const t = titleOf(g)
  if (g.moderation_status === 'removed') {
    return `Hi ${f}, this is the Bookboll team about your event “${t}”.${g.moderation_reason ? ' It was taken down because: ' + g.moderation_reason + '.' : ''} Please reach out if you have any questions.`
  }
  return `Hi ${f}, this is the Bookboll team about your event “${t}”.`
}

// ── lightbox ────────────────────────────────────────────────────────────────
function openLightbox(list, idx) {
  if (!list || !list.length) return
  lbList.value = list; lbIdx.value = idx || 0; lbOpen.value = true
  document.addEventListener('keydown', onLbKey)
}
function closeLb() { lbOpen.value = false; document.removeEventListener('keydown', onLbKey) }
function lbPrev() { lbIdx.value = (lbIdx.value - 1 + lbList.value.length) % lbList.value.length }
function lbNext() { lbIdx.value = (lbIdx.value + 1) % lbList.value.length }
function onLbKey(e) {
  if (e.key === 'Escape') closeLb()
  else if (e.key === 'ArrowLeft') lbPrev()
  else if (e.key === 'ArrowRight') lbNext()
}

// ── detail modal ────────────────────────────────────────────────────────────
function openDetail(g) { detail.value = g }
function closeDetail() { detail.value = null }

// ── moderation actions (events → Laravel) ────────────────────────────────────
async function applyModeration(g, status, reason) {
  let res
  if (status === 'flagged') res = await apiFlagEvent(g.id, reason)
  else if (status === 'removed') res = await apiRemoveEvent(g.id, reason)
  else res = await apiRestoreEvent(g.id)

  if (!res.success) {
    toast.error(modErr(res, "Couldn't save — please try again."))
    return false
  }
  const updated = res.data?.game || {}
  Object.assign(g, {
    moderation_status: updated.moderation_status ?? status,
    moderation_reason: updated.moderation_reason ?? (reason ?? null),
    moderated_at: updated.moderated_at ?? new Date().toISOString(),
    moderated_by: updated.moderated_by ?? (currentUser.value?.id || currentUser.value?.full_name || 'admin'),
  })
  return true
}

async function flagEvent(g) {
  const reason = await inputPrompt({
    title: `Flag “${titleOf(g)}”`,
    message: 'Marks the event for review. It stays visible on the storefront until you remove it.',
    label: 'Reason (internal note for the team)',
    placeholder: 'e.g. Looks like spam — double-check before removing',
    multiline: true, required: true, confirmText: 'Flag for review',
  })
  if (reason === null) return
  if (await applyModeration(g, 'flagged', reason)) toast.info('Event flagged for review.')
}

async function removeEvent(g) {
  const reason = await inputPrompt({
    title: `Remove “${titleOf(g)}”`,
    message: 'This hides the event from the customer storefront and notifies the host with your reason.',
    label: 'Reason (shown to the host)',
    placeholder: 'e.g. Contains inappropriate content / not a genuine public event',
    multiline: true, required: true, confirmText: 'Remove & notify host',
  })
  if (reason === null) return
  if (await applyModeration(g, 'removed', reason)) toast.success('Event removed from the site — the host has been notified.')
}

async function restoreEvent(g) {
  if (!(await confirm({
    title: `Restore “${titleOf(g)}”?`,
    message: 'The event will be visible on the customer storefront again.',
    danger: false, confirmText: 'Restore event',
  }))) return
  if (await applyModeration(g, 'active', null)) toast.success('Event restored — it’s live again.')
}

// ── community moderation (Laravel) ──────────────────────────────────────────
async function applyCommunityMod(c, status, reason) {
  let res
  if (status === 'flagged') res = await apiFlagCommunity(c.id, reason)
  else if (status === 'removed') res = await apiRemoveCommunity(c.id, reason)
  else res = await apiRestoreCommunity(c.id)

  if (!res.success) {
    toast.error(commErr(res, "Couldn't save — please try again."))
    return false
  }
  const updated = res.data?.community || {}
  Object.assign(c, {
    moderation_status: updated.moderation_status ?? status,
    moderation_reason: updated.moderation_reason ?? (reason ?? null),
    moderated_at: updated.moderated_at ?? new Date().toISOString(),
    moderated_by: updated.moderated_by ?? (currentUser.value?.id || currentUser.value?.full_name || 'admin'),
  })
  return true
}
async function flagCommunity(c) {
  const reason = await inputPrompt({
    title: `Flag “${c.name}”`,
    message: 'Marks the community for review. It stays visible until you remove it.',
    label: 'Reason (internal note for the team)', placeholder: 'e.g. Reported by users — verify content',
    multiline: true, required: true, confirmText: 'Flag for review',
  })
  if (reason === null) return
  if (await applyCommunityMod(c, 'flagged', reason)) toast.info('Community flagged for review.')
}
async function removeCommunity(c) {
  const reason = await inputPrompt({
    title: `Remove “${c.name}”`,
    message: 'Hides the community (and its feed/meetups entry point) from the marketplace.',
    label: 'Reason', placeholder: 'e.g. Spam / inappropriate content',
    multiline: true, required: true, confirmText: 'Remove community',
  })
  if (reason === null) return
  if (await applyCommunityMod(c, 'removed', reason)) toast.success('Community removed from the site.')
}
async function restoreCommunity(c) {
  if (!(await confirm({ title: `Restore “${c.name}”?`, message: 'The community will be visible on the marketplace again.', danger: false, confirmText: 'Restore community' }))) return
  if (await applyCommunityMod(c, 'active', null)) toast.success('Community restored — it’s live again.')
}
</script>

<template>
  <div class="page moderation-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Event moderation</h1>
        <div class="page-subtitle">{{ isPlatformAdmin ? 'Review player-hosted events and take down inappropriate or spam events' : 'Review player-hosted events at your venues and take down anything inappropriate' }}</div>
      </div>
      <button class="mod-refresh" :disabled="loading" @click="load" aria-label="Refresh events" title="Refresh"><RefreshCw :size="17" :class="{ spin: loading }" /></button>
    </div>

    <!-- section toggle: user-hosted events vs communities (platform admins) -->
    <div v-if="isPlatformAdmin" class="mod-sections">
      <button class="mod-section" :class="{ on: section === 'events' }" @click="section = 'events'; tab = 'all'">Events</button>
      <button class="mod-section" :class="{ on: section === 'communities' }" @click="section = 'communities'; tab = 'all'">Communities<span v-if="commRows.length" class="mod-section-n">{{ commRows.length }}</span></button>
    </div>

    <!-- stat chips double as quick filters -->
    <div class="mod-stats" role="group" aria-label="Filter by moderation status">
      <button class="mod-stat" :aria-pressed="tab === 'all'" :class="{ on: tab === 'all' }" @click="tab = 'all'">
        <span class="mod-stat-n">{{ counts.all }}</span><span class="mod-stat-l">{{ section === 'communities' ? 'All communities' : 'All events' }}</span>
      </button>
      <button class="mod-stat active" :aria-pressed="tab === 'active'" :class="{ on: tab === 'active' }" @click="tab = 'active'">
        <span class="mod-stat-n">{{ counts.active }}</span><span class="mod-stat-l">Live</span>
      </button>
      <button class="mod-stat flagged" :aria-pressed="tab === 'flagged'" :class="{ on: tab === 'flagged' }" @click="tab = 'flagged'">
        <span class="mod-stat-n">{{ counts.flagged }}</span><span class="mod-stat-l">Flagged</span>
      </button>
      <button class="mod-stat removed" :aria-pressed="tab === 'removed'" :class="{ on: tab === 'removed' }" @click="tab = 'removed'">
        <span class="mod-stat-n">{{ counts.removed }}</span><span class="mod-stat-l">Removed</span>
      </button>
    </div>

    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="jCountries"
      :cities="jCities"
      search-placeholder="Search event, host, sport or venue…"
    />

    <div class="mod-results-heading"><h2>{{ section === 'communities' ? 'Communities' : 'Events' }}</h2><span aria-live="polite">{{ loading ? 'Loading…' : (section === 'communities' ? commFiltered.length : filtered.length) + ' results' }}</span><button v-if="search || filterCountry.length || filterCity.length || tab !== 'all'" type="button" @click="search = ''; filterCountry = []; filterCity = []; tab = 'all'">Clear filters</button></div>

    <!-- states -->
    <div v-if="loading" class="mod-msg">Loading events…</div>

    <!-- COMMUNITIES section -->
    <template v-else-if="section === 'communities'">
      <div v-if="!commFiltered.length" class="mod-empty">
        <Flag :size="32" />
        <div class="mod-empty-title">{{ tab === 'removed' ? 'Nothing removed' : tab === 'flagged' ? 'Nothing flagged' : 'No communities to review' }}</div>
        <div class="mod-empty-sub">Customer-created communities will appear here as they’re created.</div>
      </div>
      <div v-else class="mod-list">
        <div v-for="c in commFiltered" :key="c.id" class="mod-card card" :class="'is-' + c.moderation_status">
          <div class="mod-cover" :class="{ clickable: c.cover_image }" @click="c.cover_image && openLightbox([c.cover_image], 0)">
            <img v-if="c.cover_image" :src="c.cover_image" :alt="c.name" />
            <div v-else class="mod-cover-blank"><Users :size="22" /></div>
          </div>
          <div class="mod-body">
            <div class="mod-row1">
              <span class="mod-type">Community</span>
              <h3 class="mod-title">{{ c.name }}</h3>
              <span class="mod-status" :class="c.moderation_status">
                <ShieldCheck v-if="c.moderation_status === 'active'" :size="12" />
                <AlertTriangle v-else-if="c.moderation_status === 'flagged'" :size="12" />
                <Trash2 v-else :size="12" />
                {{ c.moderation_status === 'removed' ? 'Removed' : c.moderation_status === 'flagged' ? 'Flagged' : 'Live' }}
              </span>
            </div>
            <div class="mod-meta">
              <span v-if="c.sport"><Trophy :size="13" /> {{ c.sport }}</span>
              <span><Users :size="13" /> {{ c.memberCount }} member{{ c.memberCount === 1 ? '' : 's' }}</span>
              <span v-if="c.cityName || c.countryName" class="mod-dim"><MapPin :size="13" /> {{ [c.cityName, c.countryName].filter(Boolean).join(', ') }}</span>
              <span v-else class="mod-dim mod-warn">No location set</span>
              <span class="mod-dim">by {{ c.ownerName }}</span>
              <span class="mod-dim">{{ timeAgo(c.created_at) }}</span>
            </div>
            <p v-if="c.description" class="mod-desc">{{ c.description }}</p>
            <div v-if="c.moderation_status !== 'active' && c.moderation_reason" class="mod-flagbox" :class="c.moderation_status">
              <AlertTriangle :size="14" /><span>{{ c.moderation_reason }}</span>
            </div>
            <div class="mod-actions">
              <a v-if="c.uuid" :href="communityHref(c)" target="_blank" rel="noopener" class="mod-btn"><ExternalLink :size="14" /> View</a>
              <button v-if="c.moderation_status === 'active'" class="mod-btn warn" @click="flagCommunity(c)"><AlertTriangle :size="14" /> Flag</button>
              <button v-if="c.moderation_status !== 'removed'" class="mod-btn danger" @click="removeCommunity(c)"><Trash2 :size="14" /> Remove</button>
              <button v-else class="mod-btn ok" @click="restoreCommunity(c)"><RotateCcw :size="14" /> Restore</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <div v-else-if="!filtered.length" class="mod-empty">
      <Flag :size="32" />
      <div class="mod-empty-title">
        {{ tab === 'removed' ? 'Nothing removed' : tab === 'flagged' ? 'Nothing flagged' : 'No events to review' }}
      </div>
      <div class="mod-empty-sub">
        {{ search || filterCountry.length || filterCity.length
          ? 'No events match your search. Try clearing the filters.'
          : tab === 'flagged' ? 'Flag an event to keep an eye on it before deciding.'
          : tab === 'removed' ? 'Events you take down will be listed here so you can restore them.'
          : 'Player-hosted events will appear here as they’re created.' }}
      </div>
    </div>

    <!-- list -->
    <div v-else class="mod-list">
      <div v-for="g in filtered" :key="g.id" class="mod-card card" :class="'is-' + g.moderation_status">
        <div class="mod-cover" :class="{ clickable: imagesOf(g).length }" @click="imagesOf(g).length && openLightbox(imagesOf(g), 0)">
          <img v-if="coverOf(g)" :src="coverOf(g)" :alt="titleOf(g)" />
          <div v-else class="mod-cover-blank"><Trophy :size="22" /></div>
          <span v-if="imagesOf(g).length > 1" class="mod-cover-n">+{{ imagesOf(g).length - 1 }}</span>
        </div>

        <div class="mod-body">
          <div class="mod-row1">
            <span class="mod-type" :class="{ tourn: g.game_type === 'tournament' }">{{ typeLabel(g) }}</span>
            <h3 class="mod-title">{{ titleOf(g) }}</h3>
            <span class="mod-status" :class="g.moderation_status">
              <ShieldCheck v-if="g.moderation_status === 'active'" :size="12" />
              <AlertTriangle v-else-if="g.moderation_status === 'flagged'" :size="12" />
              <Trash2 v-else :size="12" />
              {{ g.moderation_status === 'removed' ? 'Removed' : g.moderation_status === 'flagged' ? 'Flagged' : 'Live' }}
            </span>
          </div>

          <div class="mod-meta">
            <span><CalendarDays :size="13" /> {{ whenLabel(g) }}</span>
            <span><MapPin :size="13" /> {{ placeLabel(g) }}</span>
            <span v-if="g.cityName" class="mod-dim">{{ g.cityName }}</span>
            <span><Users :size="13" /> {{ spotsLabel(g) }}</span>
            <span v-if="g.pendingCount" class="mod-dim">{{ g.pendingCount }} pending</span>
            <span v-if="Number(g.prize_pot) > 0"><Gift :size="13" /> {{ g.currency }} {{ Number(g.prize_pot).toLocaleString() }}</span>
          </div>

          <div class="mod-host">
            Hosted by
            <a v-if="g.host_customer_id" :href="profileHref(g.host_customer_id)" target="_blank" rel="noopener" class="mod-host-link">
              {{ g.host_name || 'A player' }} <ExternalLink :size="11" />
            </a>
            <b v-else>{{ g.host_name || 'A player' }}</b>
            <a v-if="g.host_whatsapp" :href="waLink(g.host_whatsapp, hostMsg(g))" target="_blank" rel="noopener" class="mod-wa" title="Message host on WhatsApp"><WhatsappIcon :size="14" /></a>
          </div>

          <p v-if="g.description" class="mod-desc">{{ g.description }}</p>

          <div v-if="g.moderation_status !== 'active' && g.moderation_reason" class="mod-flagbox" :class="g.moderation_status">
            <AlertTriangle :size="14" />
            <div>
              <b>{{ g.moderation_status === 'removed' ? 'Removed' : 'Flagged' }}</b>
              <span>{{ g.moderation_reason }}</span>
              <small v-if="g.moderated_at">{{ timeAgo(g.moderated_at) }} · by a Bookboll admin</small>
            </div>
          </div>

          <div class="mod-actions">
            <button class="mod-btn ghost" @click="openDetail(g)"><Eye :size="14" /> View details</button>
            <button v-if="g.moderation_status === 'active'" class="mod-btn warn" @click="flagEvent(g)"><Flag :size="14" /> Flag</button>
            <button v-if="g.moderation_status !== 'removed'" class="mod-btn danger" @click="removeEvent(g)"><Trash2 :size="14" /> Remove</button>
            <button v-if="g.moderation_status !== 'active'" class="mod-btn ok" @click="restoreEvent(g)"><RotateCcw :size="14" /> Restore</button>
          </div>
        </div>
      </div>
    </div>

    <!-- detail modal -->
    <Teleport to="body">
      <div v-if="detail" class="mod-overlay" @click.self="closeDetail">
        <div class="mod-modal" v-dialog="closeDetail">
          <button class="mod-modal-x" @click="closeDetail" aria-label="Close"><X :size="18" /></button>

          <div class="mod-modal-head">
            <span class="mod-type" :class="{ tourn: detail.game_type === 'tournament' }">{{ typeLabel(detail) }}</span>
            <span class="mod-status" :class="detail.moderation_status">
              {{ detail.moderation_status === 'removed' ? 'Removed' : detail.moderation_status === 'flagged' ? 'Flagged' : 'Live' }}
            </span>
          </div>
          <h2 class="mod-modal-title">{{ titleOf(detail) }}</h2>

          <!-- gallery -->
          <div v-if="imagesOf(detail).length" class="mod-gallery">
            <button v-for="(img, i) in imagesOf(detail)" :key="i" class="mod-thumb" @click="openLightbox(imagesOf(detail), i)">
              <img :src="img" :alt="`Photo ${i + 1}`" />
            </button>
          </div>
          <div v-else class="mod-noimg"><ImageOff :size="18" /> No photos uploaded</div>

          <dl class="mod-facts">
            <div><dt>When</dt><dd>{{ whenLabel(detail) }}</dd></div>
            <div><dt>Where</dt><dd>{{ placeLabel(detail) }}<template v-if="detail.custom_location_address"> — {{ detail.custom_location_address }}</template></dd></div>
            <div v-if="detail.cityName"><dt>City</dt><dd>{{ detail.cityName }}</dd></div>
            <div><dt>Players</dt><dd>{{ spotsLabel(detail) }}<span v-if="detail.pendingCount"> · {{ detail.pendingCount }} pending</span></dd></div>
            <div v-if="Number(detail.prize_pot) > 0"><dt>Prize</dt><dd>{{ detail.currency }} {{ Number(detail.prize_pot).toLocaleString() }}<span v-if="detail.prize_note"> — {{ detail.prize_note }}</span></dd></div>
            <div><dt>Host</dt><dd>
              <a v-if="detail.host_customer_id" :href="profileHref(detail.host_customer_id)" target="_blank" rel="noopener" class="mod-host-link">{{ detail.host_name || 'A player' }} <ExternalLink :size="11" /></a>
              <template v-else>{{ detail.host_name || 'A player' }}</template>
            </dd></div>
            <div v-if="detail.host_whatsapp"><dt>Contact</dt><dd>{{ detail.host_whatsapp }} <a :href="waLink(detail.host_whatsapp, hostMsg(detail))" target="_blank" rel="noopener" class="mod-wa inline"><WhatsappIcon :size="13" /> WhatsApp</a></dd></div>
            <div><dt>Created</dt><dd>{{ fmtDate(detail.created_at) }}</dd></div>
          </dl>

          <div v-if="detail.description" class="mod-modal-desc">
            <div class="mod-facts-h">Description</div>
            <p>{{ detail.description }}</p>
          </div>

          <div v-if="detail.moderation_status !== 'active' && detail.moderation_reason" class="mod-flagbox" :class="detail.moderation_status" style="margin-top:14px;">
            <AlertTriangle :size="14" />
            <div>
              <b>{{ detail.moderation_status === 'removed' ? 'Removed from the site' : 'Flagged for review' }}</b>
              <span>{{ detail.moderation_reason }}</span>
              <small v-if="detail.moderated_at">{{ timeAgo(detail.moderated_at) }} · by a Bookboll admin</small>
            </div>
          </div>

          <div class="mod-modal-actions">
            <button v-if="detail.moderation_status === 'active'" class="mod-btn warn" @click="flagEvent(detail)"><Flag :size="14" /> Flag for review</button>
            <button v-if="detail.moderation_status !== 'removed'" class="mod-btn danger" @click="removeEvent(detail)"><Trash2 :size="14" /> Remove & notify host</button>
            <button v-if="detail.moderation_status !== 'active'" class="mod-btn ok" @click="restoreEvent(detail)"><RotateCcw :size="14" /> Restore</button>
            <button class="mod-btn ghost" @click="closeDetail">Close</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- fullscreen image lightbox -->
    <Teleport to="body">
      <div v-if="lbOpen" class="mod-lb" @click.self="closeLb">
        <button class="mod-lb-x" @click="closeLb" aria-label="Close"><X :size="22" /></button>
        <button v-if="lbList.length > 1" class="mod-lb-nav left" @click.stop="lbPrev"><ChevronLeft :size="28" /></button>
        <img :src="lbList[lbIdx]" class="mod-lb-img" :alt="`Photo ${lbIdx + 1}`" />
        <button v-if="lbList.length > 1" class="mod-lb-nav right" @click.stop="lbNext"><ChevronRight :size="28" /></button>
        <div v-if="lbList.length > 1" class="mod-lb-count">{{ lbIdx + 1 }} / {{ lbList.length }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.mod-refresh { width: 38px; height: 38px; border: 1px solid var(--border); background: var(--surface, #ffffff); border-radius: 9px; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mod-refresh:hover:not(:disabled) { border-color: #14b8a6; color: var(--primary-ink, #0f766e); }
.spin { animation: modspin 1s linear infinite; }
@keyframes modspin { to { transform: rotate(360deg); } }

/* stat chips */
.mod-stats { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.mod-stat { display: flex; flex-direction: column; align-items: flex-start; min-width: 92px; padding: 10px 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface, #ffffff); cursor: pointer; transition: all .15s; }
.mod-stat:hover { border-color: var(--border-strong, #cbd5e1); }
.mod-stat.on { border-color: #0f766e; box-shadow: 0 0 0 1px #0f766e inset; }
.mod-stat-n { font-size: 22px; font-weight: 800; color: var(--text); line-height: 1; }
.mod-stat-l { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-top: 4px; }
.mod-stat.active .mod-stat-n { color: var(--positive-ink, #059669); }
.mod-stat.flagged .mod-stat-n { color: var(--warning-ink, #d97706); }
.mod-stat.removed .mod-stat-n { color: var(--danger-ink, #dc2626); }

/* toolbar */
.mod-toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; }
.mod-toolbar .search { flex: 1; max-width: 380px; }
.mod-city { font: inherit; font-size: 13.5px; padding: 9px 12px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); }
.mod-city:focus { outline: none; border-color: #14b8a6; }

.mod-msg { text-align: center; padding: 44px; color: var(--text-muted); }
.mod-sections { display: flex; gap: 8px; margin-bottom: 14px; }
.mod-section { display: inline-flex; align-items: center; gap: 7px; font: inherit; font-size: 13.5px; font-weight: 800; padding: 9px 18px; border-radius: 999px; border: 1.5px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); cursor: pointer; }
.mod-section.on { background: var(--text, #0f172a); color: var(--surface, #ffffff); border-color: var(--text, #0f172a); }
.mod-section-n { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 11px; background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #be123c); }
.mod-section.on .mod-section-n { background: rgba(255,255,255,.25); color: inherit; }

/* list */
.mod-list { display: flex; flex-direction: column; gap: 14px; }
.mod-card { display: flex; gap: 16px; padding: 16px; margin-bottom: 0; align-items: stretch; }
.mod-card.is-removed { opacity: .82; }
.mod-card.is-removed .mod-cover img { filter: grayscale(.5); }

.mod-cover { position: relative; width: 132px; flex-shrink: 0; border-radius: 12px; overflow: hidden; background: var(--bg, #f1f5f9); }
.mod-cover.clickable { cursor: zoom-in; }
.mod-cover img { width: 100%; height: 100%; object-fit: cover; display: block; min-height: 120px; }
.mod-cover-blank { width: 100%; height: 100%; min-height: 120px; display: flex; align-items: center; justify-content: center; color: var(--text-light, #94a3b8); }
.mod-cover-n { position: absolute; bottom: 6px; right: 6px; background: rgba(15,23,42,.78); color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }

.mod-body { flex: 1; min-width: 0; }
.mod-row1 { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.mod-type { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #ccfbf1); padding: 2px 9px; border-radius: 999px; }
.mod-type.tourn { color: var(--purple-ink, #7c3aed); background: var(--purple-bg, #ede9fe); }
.mod-title { font-size: 16px; font-weight: 700; color: var(--text); margin: 0; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mod-status { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; padding: 3px 9px; border-radius: 999px; }
.mod-status.active { color: var(--positive-ink, #166534); background: var(--positive-bg, #dcfce7); }
.mod-status.flagged { color: var(--warning-ink, #9a3412); background: var(--warning-bg, #ffedd5); }
.mod-status.removed { color: var(--danger-ink, #991b1b); background: var(--danger-bg, #fee2e2); }

.mod-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; font-size: 12.5px; color: var(--text); margin-top: 9px; }
.mod-meta span { display: inline-flex; align-items: center; gap: 5px; }
.mod-meta .mod-dim { color: var(--text-muted); }

.mod-host { font-size: 13px; color: var(--text-muted); margin-top: 9px; display: flex; align-items: center; gap: 7px; }
.mod-host-link { color: var(--primary-ink, #0f766e); font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; }
.mod-host-link:hover { text-decoration: underline; }
.mod-wa { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 7px; background: #25d366; color: #fff; }
.mod-wa.inline { width: auto; height: auto; gap: 5px; padding: 4px 9px; border-radius: 7px; font-size: 12.5px; font-weight: 700; text-decoration: none; }

.mod-desc { font-size: 13px; color: var(--text); line-height: 1.5; margin: 9px 0 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

.mod-flagbox { display: flex; gap: 9px; align-items: flex-start; margin-top: 11px; padding: 10px 12px; border-radius: 10px; font-size: 12.5px; }
.mod-flagbox.flagged { background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #9a3412); }
.mod-flagbox.removed { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #991b1b); }
.mod-flagbox b { display: block; font-weight: 800; }
.mod-flagbox span { display: block; margin-top: 1px; }
.mod-flagbox small { display: block; margin-top: 3px; opacity: .8; }

.mod-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.mod-btn { display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 13px; font-weight: 700; padding: 8px 13px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); cursor: pointer; transition: all .15s; }
.mod-btn:hover { background: var(--bg); }
.mod-btn.ghost { color: var(--text-muted); }
.mod-btn.warn { border-color: var(--warning-border, #fed7aa); color: var(--warning-ink, #c2410c); background: var(--warning-bg, #fff7ed); }
.mod-btn.warn:hover { background: var(--warning-bg, #ffedd5); }
.mod-btn.danger { border-color: var(--danger-border, #fecaca); color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); }
.mod-btn.danger:hover { background: var(--danger-bg, #fee2e2); }
.mod-btn.ok { border-color: var(--positive-border, #bbf7d0); color: var(--positive-ink, #15803d); background: var(--positive-bg, #f0fdf4); }
.mod-btn.ok:hover { background: var(--positive-bg, #dcfce7); }

/* detail modal */
.mod-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.55); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; z-index: 1000; overflow-y: auto; }
.mod-modal { position: relative; width: 100%; max-width: 640px; background: var(--surface, #ffffff); border-radius: 16px; padding: 24px; box-shadow: 0 24px 60px rgba(0,0,0,.25); }
.mod-modal-x { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mod-modal-x:hover { background: var(--border, #e2e8f0); }
.mod-modal-head { display: flex; align-items: center; gap: 9px; }
.mod-modal-title { font-size: 21px; font-weight: 800; color: var(--text); margin: 10px 0 16px; padding-right: 40px; }

.mod-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; margin-bottom: 18px; }
.mod-thumb { padding: 0; border: none; border-radius: 10px; overflow: hidden; cursor: zoom-in; aspect-ratio: 4/3; background: var(--bg, #f1f5f9); }
.mod-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mod-noimg { display: flex; align-items: center; gap: 7px; color: var(--text-muted); font-size: 13px; background: var(--bg); padding: 12px; border-radius: 10px; margin-bottom: 18px; }

.mod-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 18px; margin: 0; }
.mod-facts > div { min-width: 0; }
.mod-facts dt { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: 3px; }
.mod-facts dd { margin: 0; font-size: 13.5px; color: var(--text); }
.mod-facts-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: 5px; }
.mod-modal-desc { margin-top: 16px; }
.mod-modal-desc p { font-size: 13.5px; color: var(--text); line-height: 1.55; margin: 0; white-space: pre-wrap; }

.mod-modal-actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--border); }

/* lightbox */
.mod-lb { position: fixed; inset: 0; background: rgba(0,0,0,.92); display: flex; align-items: center; justify-content: center; z-index: 1100; }
.mod-lb-img { max-width: 90vw; max-height: 88vh; object-fit: contain; border-radius: 6px; }
.mod-lb-x { position: absolute; top: 18px; right: 20px; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,.12); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mod-lb-x:hover { background: rgba(255,255,255,.22); }
.mod-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 50px; height: 50px; border-radius: 50%; border: none; background: rgba(255,255,255,.12); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mod-lb-nav:hover { background: rgba(255,255,255,.22); }
.mod-lb-nav.left { left: 20px; }
.mod-lb-nav.right { right: 20px; }
.mod-lb-count { position: absolute; bottom: 22px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 13px; font-weight: 600; background: rgba(255,255,255,.14); padding: 5px 14px; border-radius: 999px; }

@media (max-width: 640px) {
  .mod-card { flex-direction: column; }
  .mod-cover { width: 100%; height: 160px; }
  .mod-facts { grid-template-columns: 1fr; }
}

/* narrow phones (360px-class) */
@media (max-width: 600px) {
  /* detail modal → bottom sheet */
  .mod-overlay { padding: 0; align-items: flex-end; }
  .mod-modal { max-width: none; border-radius: 16px 16px 0 0; padding: 18px 16px calc(16px + env(safe-area-inset-bottom)); max-height: 92dvh; overflow-y: auto; }

  /* 44px tap targets; sheet footer buttons stack full-width */
  .mod-btn { min-height: 44px; padding: 10px 14px; }
  .mod-modal-actions .mod-btn { flex: 1 1 100%; justify-content: center; }

  /* lightbox controls off the photo edges */
  .mod-lb-nav { width: 40px; height: 40px; }
  .mod-lb-nav.left { left: 8px; }
  .mod-lb-nav.right { right: 8px; }
  .mod-lb-x { top: 10px; right: 10px; }
  .mod-lb-img { max-width: 100vw; max-height: 82vh; }

  /* long unbroken strings (URLs, numbers, reasons) must wrap */
  .mod-flagbox span, .mod-desc, .mod-modal-desc p, .mod-facts dd { overflow-wrap: anywhere; }

  /* stat chips: even 2x2 grid down to 344px */
  .mod-stat { flex: 1 1 calc(50% - 5px); min-width: 0; }

  /* card header: title gets its own full-width top line; the type + status
     pills wrap onto a line below so both stay visible (no title truncation) */
  .mod-row1 { align-items: flex-start; }
  .mod-title { flex-basis: 100%; order: -1; white-space: normal; overflow: visible; text-overflow: clip; overflow-wrap: anywhere; }
  .mod-type, .mod-status { order: 1; }

  /* WhatsApp icon → 40px tap target (labelled .inline variant untouched) */
  .mod-wa { width: 40px; height: 40px; border-radius: 9px; }

  /* refresh button → 40px tap target */
  .mod-refresh { width: 40px; height: 40px; }
}

.moderation-page { max-width:1280px; margin-inline:auto; }
.moderation-page .page-header { display:grid; grid-template-columns:minmax(0,1fr) 44px; align-items:start; gap:16px; }
.moderation-page .page-subtitle { max-width:680px; line-height:1.6; }
.moderation-page .mod-refresh { width:44px; height:44px; flex:none; }
.moderation-page .mod-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:24px 0; }
.moderation-page .mod-stat { min-width:0; padding:18px; border-radius:10px; gap:6px; }
.moderation-page .mod-stat.on { border-color:var(--primary); box-shadow:0 0 0 1px var(--primary) inset; background:var(--primary-soft); }
.moderation-page .mod-stat-l { font-size:13px; }
.mod-results-heading { display:flex; align-items:center; flex-wrap:wrap; gap:12px; padding:20px 0 14px; border-bottom:1px solid var(--border); margin-bottom:16px; }
.mod-results-heading h2 { font-size:17px; color:var(--text); margin:0; }
.mod-results-heading span { color:var(--text-muted); font-size:13px; }
.mod-results-heading button { margin-inline-start:auto; min-height:44px; padding:8px 12px; border:1px solid var(--border); background:var(--surface); border-radius:8px; color:var(--primary-ink); }
.mod-empty { min-height:300px; padding:48px 24px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; text-align:center; border:1px solid var(--border); border-radius:12px; background:var(--surface); }
.mod-empty > svg { color:var(--primary-ink); width:40px; height:40px; }
.mod-empty-title { font-size:18px; font-weight:600; color:var(--text); }
.mod-empty-sub { color:var(--text-muted); font-size:14px; line-height:1.7; max-width:420px; }
@media(max-width:600px) { .moderation-page .mod-stats { grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; } .moderation-page .mod-stat { padding:16px; } .mod-empty { min-height:260px; padding:32px 20px; } }
.mod-warn { color: var(--warning-ink, #b45309); }
</style>
