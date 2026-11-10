/**
 * brand.js — white-label naming helpers for the B2B site.
 *
 * The public business site never RESOLVES a provider brand (that is the
 * storefront's job, apps/web). Signup only needs to show the provider the web
 * address they are claiming, so this keeps the address builders and the theme
 * defaults; resolution, theming and the storefront module gates live in
 * apps/web/src/lib/brand.js.
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
import { appBaseUrl } from './appBase.js'

// The apex the white-label subdomains live under (display + canonical links).
export const BRAND_APEX = 'bookboll.com'
// Platform defaults — the white-label theme falls back to these.
export const DEFAULT_PRIMARY = '#0f766e'
export const DEFAULT_ACCENT  = '#14b8a6'

export const activeBrand = ref(null)
export const isWhiteLabel = computed(() => !!activeBrand.value)

// The PLATFORM brand — the main Bookboll marketplace (apex site, no white-label).
// Editable by the super admin in My Website; stored as platform_settings keys.
export const platformBrand = ref({
  name: 'Bookboll', tagline: '', logo: '', primary: DEFAULT_PRIMARY, accent: DEFAULT_ACCENT,
  metaTitle: '', metaDescription: '',
  sortSpace: 'recommended', sortVenue: 'recommended',
  banners: [],
  tracking: { metaPixel: '', ga4: '', gtm: '', head: '' },   // marketplace (apex) analytics/pixels
})

// Load the marketplace branding from platform_settings (best-effort → defaults on any error).
function isLocalHost(host) {
  return host === 'localhost' || host.endsWith('.localhost') || host === '127.0.0.1' || /^\d+\.\d+\.\d+\.\d+$/.test(host)
}

// Public dev tunnels (Cloudflare quick tunnel / ngrok) used to preview the dev server on a
// phone. The whole tunnel host is a single apex — its first label is a random tunnel id, NOT a
// white-label subdomain — so we treat it like the apex marketplace (custom platform theme + ?b=
// convenience), never as a provider slug.
export function brandHost(slug) { return `${(slug || '').toLowerCase()}.${BRAND_APEX}` }

/** A link that actually works in the CURRENT environment: the real subdomain in
 *  production, or the `?b=` fallback in dev / before wildcard DNS exists. Pass
 *  `{ preview: true }` for the dashboard live-preview (resolves the brand even if it
 *  isn't enabled/published yet, via the `preview=1` flag). */
export function brandPreviewUrl(slug, { preview = false } = {}) {
  const s = (slug || '').toLowerCase()
  // Dev / local: load the public web app with ?b=. appBaseUrl() prefers VITE_WEB_URL
  // so this works from the admin app (port 5174 → 5173) as well.
  const previewHosts = String(import.meta.env.VITE_PREVIEW_HOSTS || '').split(',').map(h => h.trim().toLowerCase())
  let previewEnvironment = false
  try { previewEnvironment = previewHosts.includes(new URL(appBaseUrl()).hostname) } catch { /* no configured storefront */ }
  if (previewEnvironment || (typeof window !== 'undefined' && isLocalHost(window.location.hostname))) {
    return `${appBaseUrl()}/?b=${encodeURIComponent(s)}${preview ? '&preview=1' : ''}`
  }
  return `https://${brandHost(s)}${preview ? '?preview=1' : ''}`
}

/** Dashboard live-preview URL that resolves a provider by OWNER ID on the public web app. */
