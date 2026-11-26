// Mirrored in all three portals. A browser choice belongs to this origin and storefront.
import { ref } from 'vue'

/**
 * Whether optional tracking waits for a cookie choice.
 *
 * OFF: the platform runs Google Ads and Meta campaigns and needs attribution
 * on every visit; its markets do not require a consent banner, and the banner
 * was hiding most visitors from analytics. Tags load immediately, Google
 * Consent Mode defaults to granted, nothing is ever purged, and the banner
 * renders nothing. The gated behaviour below is kept intact (and tested) so
 * this is a one-line flip if a market ever needs it.
 */
export const CONSENT_REQUIRED = false

export const CONSENT_VERSION = 1
export const CONSENT_MAX_AGE = 180 * 24 * 60 * 60 * 1000
export const cookieConsent = ref(null)
export const consentReady = ref(false)
let scope = 'site', storage, listening = false, trackingStarted = false
export const consentKey = (site = scope) => `bb_cookie_consent_v${CONSENT_VERSION}:${encodeURIComponent(site)}`

export function parseConsent(raw, now = Date.now()) {
  try {
    const value = JSON.parse(raw)
    if (value?.version !== CONSENT_VERSION || typeof value.analytics !== 'boolean' || typeof value.marketing !== 'boolean' || !Number.isFinite(value.savedAt) || value.savedAt > now || now - value.savedAt >= CONSENT_MAX_AGE) return null
    return { version: CONSENT_VERSION, necessary: true, analytics: value.analytics, marketing: value.marketing, savedAt: value.savedAt }
  } catch { return null }
}
export function trackingAllowed(category, choice = cookieConsent.value, required = CONSENT_REQUIRED) { return !required || choice?.[category] === true }
export function restrictTracking(config = {}, choice = cookieConsent.value, required = CONSENT_REQUIRED) {
  const analytics = trackingAllowed('analytics', choice, required), marketing = trackingAllowed('marketing', choice, required)
  return { ga4: analytics ? config.ga4 : '', metaPixel: marketing ? config.metaPixel : '',
    // Containers and arbitrary snippets may include either category; require both.
    gtm: analytics && marketing ? config.gtm : '', head: analytics && marketing ? config.head : '' }
}
export function consentRevoked(before, after, required = CONSENT_REQUIRED) {
  return ['analytics', 'marketing'].some(category => trackingAllowed(category, before, required) && !trackingAllowed(category, after, required))
}
export function markTrackingStarted() { trackingStarted = true }

export function removableTrackingCookie(name, choice, required = CONSENT_REQUIRED) {
  return (!trackingAllowed('analytics', choice, required) && /^(_ga(?:_|$)|_gid$|_gat(?:_|$))/.test(name)) ||
    (!trackingAllowed('marketing', choice, required) && /^(_fbp$|_fbc$|_gcl_)/.test(name))
}
function clearDeniedCookies(choice) {
  if (typeof document === 'undefined') return
  // Known, accessible GA/Meta cookies only. Auth, checkout and payment state remain intact.
  const host = window.location.hostname
  const labels = host.split('.')
  const domains = ['', host, ...labels.slice(0, -1).map((_, i) => '.' + labels.slice(i).join('.'))]
  const parts = window.location.pathname.split('/').filter(Boolean)
  const paths = ['/', ...parts.map((_, i) => '/' + parts.slice(0, i + 1).join('/'))]
  for (const pair of document.cookie.split(';')) {
    const name = pair.trim().split('=')[0]
    if (!removableTrackingCookie(name, choice)) continue
    for (const domain of domains) for (const path of paths) {
      document.cookie = `${name}=; Max-Age=0; path=${path}${domain ? `; domain=${domain}` : ''}; SameSite=Lax`
    }
  }
}
function updateChoice(next) {
  const reload = trackingStarted && consentRevoked(cookieConsent.value, next)
  cookieConsent.value = next
  clearDeniedCookies(next)
  // Removing a script element cannot undo timers/listeners or code it already ran.
  // Persist first, then restart under the new gates. No unsaved form is auto-submitted.
  if (reload && typeof window !== 'undefined') window.location.reload()
}
export function initCookieConsent(site = 'site') {
  if (consentReady.value && site === scope) return
  scope = site
  try { storage = window.localStorage } catch { storage = undefined }
  let next = null
  try { next = parseConsent(storage?.getItem(consentKey())) } catch { /* default deny */ }
  updateChoice(next)
  consentReady.value = true
  if (!listening && typeof window !== 'undefined') {
    listening = true
    window.addEventListener('storage', event => {
      if (event.key === consentKey() || event.key === null) updateChoice(parseConsent(event.newValue))
    })
    window.addEventListener('focus', () => {
      if (cookieConsent.value && Date.now() - cookieConsent.value.savedAt >= CONSENT_MAX_AGE) updateChoice(null)
    })
  }
}
export function saveCookieConsent({ analytics = false, marketing = false } = {}) {
  const value = { version: CONSENT_VERSION, necessary: true, analytics: analytics === true, marketing: marketing === true, savedAt: Date.now() }
  let persisted = true
  try { if (!storage) throw new Error('Storage unavailable'); storage.setItem(consentKey(), JSON.stringify(value)) } catch { persisted = false; try { storage?.removeItem(consentKey()) } catch { /* storage is unavailable */ } }
  updateChoice(value)
  return persisted
}
