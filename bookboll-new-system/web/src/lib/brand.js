import { resolveStorefrontSort } from './storefrontSort.js'
/**
 * brand.js — provider white-label resolution + theming.
 *
 * A provider can run a white-labeled copy of the customer storefront on their own
 * subdomain (e.g. mybusiness.bookboll.com) that shows ONLY their own venues,
 * listings, membership programs and events, with their own logo, name and colors.
 *
 * How the brand is detected (in order):
 *   1. `?b=<slug>` query param — dev/testing + a fallback that works before
 *      wildcard DNS is configured (and as a shareable link anywhere).
 *   2. The first label of the hostname — `mybusiness.bookboll.com` → `mybusiness`
 *      (skips the apex, `www`, `app`, `admin`, and handles `*.localhost`).
 *
 * Resolution looks the slug up in `admin_users` and, on a hit, (a) scopes the
 * customer data layer to that owner and (b) applies the brand theme via CSS vars.
 * The public/apex site (no slug, or no match) is completely unchanged — every
 * default equals today's hardcoded Bookboll look.
 */
import { ref, computed } from 'vue'
import { websiteSectionEnabled } from './websiteSections.js'
import { apiFetch } from './api.js'
import { appBaseUrl } from './appBase.js'
import { setBrandScope, loadServedRegions } from '../customer/data.js'
import { normalizeBanners } from './heroBanners.js'
import { brandButtonText } from './brandColors.js'

// The apex the white-label subdomains live under (display + canonical links).
export const BRAND_APEX = 'bookboll.com'
// Platform defaults — the white-label theme falls back to these.
export const DEFAULT_PRIMARY = '#0f766e'
export const DEFAULT_ACCENT  = '#14b8a6'

const RESERVED_SUBS = new Set(['www', 'app', 'admin', 'api', 'business', 'staging', 'dev'])

/** Turn a free-text name into a valid subdomain label (a-z, 0-9, hyphens, ≤30). */
export function slugify(s) {
  return (s || '').toString().toLowerCase().trim()
    .replace(/['']/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 30)
    .replace(/-+$/g, '')
}

/**
 * The effective white-label web-address slug for a provider row — ALWAYS non-empty for
 * a real provider, so every provider automatically has a `<slug>.bookboll.com` address
 * without having to set one up manually. Order: an explicit brand subdomain → their
 * username → the slugified business / brand name → a stable id-based fallback.
 */
export function providerSlug(u) {
  if (!u) return ''
  const explicit = (u.brand_subdomain || u.username || '').toLowerCase()
  if (explicit) return explicit
  const derived = slugify(u.business_name || u.brand_name || '')
  if (derived && derived.length >= 3) return derived
  const id = String(u.id || '').replace(/-/g, '').slice(0, 8)
  return id ? `biz-${id}` : ''
}

// { ownerUserId, slug, name, tagline, logo, primary, accent } — null until resolved.
export const activeBrand = ref(null)
export const isWhiteLabel = computed(() => !!activeBrand.value)

// The PLATFORM brand — the main Bookboll marketplace (apex site, no white-label).
// Editable by the super admin in My Website; stored as Laravel settings keys.
export const platformBrand = ref({
  name: 'Bookboll', tagline: '', logo: '', primary: DEFAULT_PRIMARY, accent: DEFAULT_ACCENT,
  metaTitle: '', metaDescription: '',
  sortSpace: 'recommended', sortVenue: 'recommended',
  banners: [],
  tracking: { metaPixel: '', ga4: '', gtm: '', head: '' },   // marketplace (apex) analytics/pixels
})

// Load the marketplace branding from the API (best-effort → defaults on any error).
export async function loadPlatformBrand() {
  try {
    const res = await apiFetch('/marketplace/platform-brand')
    const m = res.success ? (res.data?.settings || {}) : {}
    platformBrand.value = {
      name:    m.brand_name    || 'Bookboll',
      tagline: m.brand_tagline || '',
      logo:    m.brand_logo_url || '',
      primary: m.brand_primary || DEFAULT_PRIMARY,
      accent:  m.brand_accent  || DEFAULT_ACCENT,
      metaTitle:       m.brand_meta_title || '',
      metaDescription: m.brand_meta_description || '',
      sortSpace: m.storefront_sort_space || 'recommended',
      sortVenue: m.storefront_sort_venue || 'recommended',
      banners: normalizeBanners(m.hero_banners),
      tracking: {
        metaPixel: m.tracking_meta_pixel || '',
        ga4:       m.tracking_ga4 || '',
        gtm:       m.tracking_gtm || '',
        head:      m.tracking_head || '',
      },
    }
  } catch { /* keep defaults */ }
  return platformBrand.value
}

// Set <meta name="description"> directly (storefront first paint). Per-page useSeo can
// still override on navigation.
function setMetaDescription(desc) {
  if (typeof document === 'undefined' || !desc) return
  let tag = document.querySelector('meta[name="description"]')
  if (!tag) { tag = document.createElement('meta'); tag.setAttribute('name', 'description'); document.head.appendChild(tag) }
  tag.setAttribute('content', desc)
}

// Point the browser-tab favicon at a brand's uploaded logo. Falls back to the
// bundled Bookboll logo (public/logo.svg) when the brand has no custom logo, so
// the tab always shows a real mark instead of the framework default.
export function setFavicon(url) {
  if (typeof document === 'undefined') return
  const href = url || '/logo.svg?v=3'   // version bump busts stale favicon caches
  let link = document.querySelector('link[rel="icon"]')
  if (!link) { link = document.createElement('link'); link.setAttribute('rel', 'icon'); document.head.appendChild(link) }
  // A raster upload (png/jpg) shouldn't advertise image/svg+xml.
  if (/\.svg(\?|$)/i.test(href)) link.setAttribute('type', 'image/svg+xml')
  else link.removeAttribute('type')
  link.setAttribute('href', href)
}

function isLocalHost(host) {
  return host === 'localhost' || host.endsWith('.localhost') || host === '[::1]' || /^\d+\.\d+\.\d+\.\d+$/.test(host)
}

/*
 * Preview hosts: a staging deployment that should behave like localhost.
 *
 * White-label sites resolve by subdomain, so on a testing domain such as
 * new.bookboll.com there is no way to see a provider's site at all — its first
 * label is read as a provider slug ("new"), no provider matches, and the `?b=`
 * convenience is refused because it is only honoured on localhost. Hosts listed
 * in VITE_PREVIEW_HOSTS (comma-separated, exact match) are treated as a preview
 * apex: `?b=<slug>` and `?bid=<owner>&preview=1` work there, the host itself is
 * never read as a slug, and "browse Bookboll" links stay on that origin.
 *
 * The real apex can never be listed — that is the origin the `?b=` restriction
 * exists to protect (a provider's pixels and custom <head> code must never run
 * on bookboll.com).
 */
const PREVIEW_HOSTS = new Set(
  String(import.meta.env.VITE_PREVIEW_HOSTS || '')
    .split(',')
    .map(h => h.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, ''))
    .filter(h => h && h !== BRAND_APEX && h !== 'www.' + BRAND_APEX),
)
export function isPreviewHost(host) {
  return PREVIEW_HOSTS.has(String(host || '').toLowerCase())
}
/** localhost, a dev tunnel, or a configured preview host — anywhere `?b=` is honoured. */
export function isPreviewEnvironment(host) {
  return isLocalHost(host) || isDevTunnel(host) || isPreviewHost(host)
}

// Public dev tunnels (Cloudflare quick tunnel / ngrok) used to preview the dev server on a
// phone. The whole tunnel host is a single apex — its first label is a random tunnel id, NOT a
// white-label subdomain — so we treat it like the apex marketplace (custom platform theme + ?b=
// convenience), never as a provider slug.
function isDevTunnel(host) {
  return /\.trycloudflare\.com$/.test(host) || /\.ngrok(-free)?\.(app|io)$/.test(host) || /\.ngrok\.app$/.test(host)
}

/** Dashboard "live preview" flag (?preview=1) — resolve the brand even if not yet
 *  enabled/published, so a provider can preview their site while setting it up. */
export function isBrandPreview() {
  if (typeof window === 'undefined') return false
  const params = new URLSearchParams(window.location.search)
  if (params.get('preview') === '1') return true
  // On a preview host a `?b=` / `?bid=` override is by definition a preview: the
  // provider's analytics and custom <head> code must not run on the staging origin.
  return isPreviewHost(window.location.hostname) && !!(params.get('b') || params.get('bid'))
}

/** Dashboard live-preview only: a provider's owner id passed as `?bid=<id>`, so the
 *  preview resolves the right provider even before they've chosen/saved a web address. */
export function detectBrandOwnerId() {
  if (typeof window === 'undefined') return ''
  return (new URLSearchParams(window.location.search).get('bid') || '').trim()
}

/** Detect the brand slug from the URL, or '' if this is the plain platform site. */
export function detectBrandSlug() {
  if (typeof window === 'undefined') return ''
  const host = window.location.hostname || ''
  const params = new URLSearchParams(window.location.search)
  const q = (params.get('b') || '').trim().toLowerCase()
  // `?b=<slug>` is a DEV/localhost-only convenience. On any real host it is IGNORED, so a
  // provider's scoped storefront — and its analytics/pixels + custom <head> code — can never be
  // force-loaded on the apex origin via https://bookboll.com/?b=<slug> (which would leak
  // marketplace traffic into a provider's tracking and expose the apex origin to a provider's
  // scripts). In production, white-label sites resolve by SUBDOMAIN only (needs wildcard DNS).
  if (q && isPreviewEnvironment(host)) return q
  // IP address labels are not provider subdomains. Keep explicit local ?b= testing above.
  if (host === '[::1]' || /^\d+\.\d+\.\d+\.\d+$/.test(host)) return ''
  // A dev tunnel or a configured preview host is the apex itself — its first label is a
  // tunnel id or a staging name such as "new", never a provider slug.
  if (isDevTunnel(host) || isPreviewHost(host)) return ''

  const parts = host.split('.')
  // apex length: localhost is 1 label, bookboll.com is 2.
  const baseLen = isLocalHost(host) ? 1 : 2
  if (parts.length > baseLen) {
    const sub = (parts[0] || '').toLowerCase()
    if (sub && !RESERVED_SUBS.has(sub)) return sub
  }
  return ''
}

/**
 * Resolve a brand through Laravel — one request returning identity, theme, modules,
 * sort defaults, banners, tracking, languages and the About/Contact pages.
 *
 * `allowDisabled` (the dashboard's "live preview") resolves a brand that isn't
 * enabled or published yet, so a provider can preview their site while setting it up.
 * Without it, only an ENABLED brand resolves — white-labelling stays opt-in.
 */
async function fetchBrand({ slug = '', ownerId = '', allowDisabled = false } = {}) {
  const query = { preview: allowDisabled ? 1 : undefined }
  if (ownerId) query.owner_id = ownerId
  else if (slug) query.slug = slug
  const res = await apiFetch('/marketplace/brand', { query, token: '' })
  if (!res.success || !res.data?.brand) return null
  return res.data.brand
}

export function brandSectionEnabled(key) {
  return websiteSectionEnabled(activeBrand.value?.pages, key)
}

/** Is a simple content page live on the active white-label site? */
export function brandPageEnabled(key) {
  const pg = activeBrand.value?.pages
  if (!pg) return false
  if (key === 'about') return !!String(pg.about?.text || '').trim()
  if (key === 'contact') return ['phone', 'whatsapp', 'email', 'hours', ...(brandSectionEnabled('locations') ? ['address', 'maps_url'] : [])]
    .some(k => String(pg.contact?.[k] || '').trim())
  return false
}

/** Configured initial order. Customers can override each view independently. */
export function storefrontSortKey(view) {
  return resolveStorefrontSort(view, activeBrand.value ? (activeBrand.value.sort || {}) : null, { venue: platformBrand.value.sortVenue, space: platformBrand.value.sortSpace })
}

/** Is a storefront module visible? Always true on the plain platform site; on a
 *  white-label site it follows the provider's choices. `mod` = venues|events|programs. */
export function brandShows(mod) {
  const b = activeBrand.value
  if (!b) return true
  return b.modules ? b.modules[mod] !== false : true
}

// Map a customer route path to the storefront module that gates it (or null = always on).
export function moduleForPath(path) {
  const p = path || ''
  if (p.startsWith('/facilities') || p.startsWith('/listings/')) return 'venues'
  if (p.startsWith('/games') || p.startsWith('/tournaments') || p.startsWith('/host-game')) return 'events'
  if (p.startsWith('/programs')) return 'programs'
  if (p.startsWith('/blog') || p.startsWith('/post')) return 'blog'
  return null
}

/** Absolute URL to the MAIN Bookboll marketplace (apex), from anywhere — used by the
 *  white-label "we don't serve here, browse Bookboll" prompts. In dev/preview the brand is
 *  scoped via `?b=`, so the same origin without it IS the apex; on a real subdomain
 *  (`sub.bookboll.com`) we strip the brand label to reach the root domain. */
export function marketplaceUrl(path = '') {
  if (typeof window === 'undefined') return path || '/'
  const { hostname, protocol } = window.location
  // A preview host IS the marketplace: the same origin without the brand override.
  // It must not be rewritten to the production apex just because it is a
  // subdomain of it — that would walk a tester off the testing site.
  if (isPreviewHost(hostname)) return `${appBaseUrl()}${path || '/'}`
  // Strip the brand label ONLY on a real white-label subdomain of the apex —
  // any other host (dev, Pages preview under a subpath) is the marketplace itself.
  if (hostname.endsWith('.' + BRAND_APEX)) {
    return `${protocol}//${BRAND_APEX}${path || '/'}`
  }
  return `${appBaseUrl()}${path || '/'}`
}

/** Apply a brand's colors as CSS variables on <html>. Soft tints are derived in CSS
 *  via color-mix, so we only set the two base colors here. */
export function applyBrandTheme(primary, accent) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.style.setProperty('--brand', primary || DEFAULT_PRIMARY)
  root.style.setProperty('--brand-accent', accent || DEFAULT_ACCENT)
  root.style.setProperty('--brand-on', brandButtonText(primary || DEFAULT_PRIMARY))
  root.classList.add('cust-branded')
}

/** Resolve the active brand from the URL once at app start. Best-effort: any failure
 *  leaves the plain platform site intact. */
export async function resolveBrand() {
  // Apply the editable marketplace (platform) brand — logo, name, colors, meta.
  // Used for the apex AND as the fallback for any host/slug that ISN'T a real
  // provider white-label (a Vercel/Netlify preview host, a staging domain, an
  // unknown subdomain): those must still show the branded marketplace, never the
  // hardcoded defaults with the super admin's customization missing.
  async function applyPlatformBrand() {
    await loadPlatformBrand()
    applyBrandTheme(platformBrand.value.primary, platformBrand.value.accent)
    const ptitle = platformBrand.value.metaTitle || (platformBrand.value.name !== 'Bookboll' ? platformBrand.value.name : '')
    if (ptitle) document.title = ptitle
    setMetaDescription(platformBrand.value.metaDescription || platformBrand.value.tagline)
    setFavicon(platformBrand.value.logo)   // super-admin's uploaded marketplace logo
  }
  try {
    const slug = detectBrandSlug()
    const preview = isBrandPreview()
    // Dashboard live preview can target a provider by owner id (`?bid=`) so it resolves
    // even before they've chosen/saved a web address. Preview-only (needs `preview=1`).
    const bid = preview ? detectBrandOwnerId() : ''
    if (!slug && !bid) {
      // Apex = the main Bookboll marketplace. Apply the (editable) platform brand.
      await applyPlatformBrand()
      return null
    }
    const row = await fetchBrand({ slug, ownerId: bid, allowDisabled: preview })
    // Slug/bid present but no provider matched (e.g. a preview/staging host whose
    // first label isn't a real white-label): fall back to the marketplace brand so
    // the super admin's logo/colors still apply, instead of the unthemed default.
    if (!row) { await applyPlatformBrand(); return null }
    const brandSlug = row.slug || (slug || '').toLowerCase()
    const brand = {
      ownerUserId: row.ownerUserId,
      businessId: row.businessId,
      slug: brandSlug,
      name: row.name || 'Bookboll',
      tagline: row.tagline || '',
      logo: row.logo || '',
      primary: row.primary || DEFAULT_PRIMARY,
      accent: row.accent || DEFAULT_ACCENT,
      metaTitle: row.metaTitle || '',
      metaDescription: row.metaDescription || '',
      modules: row.modules || { venues: false, events: false, programs: false, blog: false },
      sort: row.sort || { space: 'recommended', venue: 'recommended' },
      banners: normalizeBanners(row.banners),
      regions: await loadServedRegions(brandSlug),
      tracking: row.tracking || { metaPixel: '', ga4: '', gtm: '', head: '' },
      languages: Array.isArray(row.languages) ? row.languages : [],
      pages: row.pages || { home: { hero_image: '' }, about: { text: '' }, contact: {} },
      whitelabelBranding: !!row.whitelabelBranding,
    }
    activeBrand.value = brand
    setBrandScope(brand.ownerUserId)
    applyBrandTheme(brand.primary, brand.accent)
    document.title = brand.metaTitle || brand.name || 'Bookboll'
    setMetaDescription(brand.metaDescription || brand.tagline)
    setFavicon(brand.logo)   // provider's own logo on their white-label site
    return brand
  } catch {
    return null   // never block the storefront on a branding error
  }
}

/** Canonical shareable address for a slug (what a provider gives their customers). */
export function brandHost(slug) { return `${(slug || '').toLowerCase()}.${BRAND_APEX}` }

/** A link that actually works in the CURRENT environment: the real subdomain in
 *  production, or the `?b=` fallback in dev / before wildcard DNS exists. Pass
 *  `{ preview: true }` for the dashboard live-preview (resolves the brand even if it
 *  isn't enabled/published yet, via the `preview=1` flag). */
export function brandPreviewUrl(slug, { preview = false } = {}) {
  const s = (slug || '').toLowerCase()
  // Dev / local: load the public web app with ?b=. appBaseUrl() prefers VITE_WEB_URL
  // so this works from the admin app (port 5174 → 5173) as well.
  if (typeof window !== 'undefined' && isPreviewEnvironment(window.location.hostname)) {
    return `${appBaseUrl()}/?b=${encodeURIComponent(s)}${preview ? '&preview=1' : ''}`
  }
  return `https://${brandHost(s)}${preview ? '?preview=1' : ''}`
}

/** Dashboard live-preview URL that resolves a provider by OWNER ID on the public web app. */
export function brandPreviewById(ownerId) {
  return `${appBaseUrl()}/?bid=${encodeURIComponent(ownerId || '')}&preview=1`
}
