/**
 * tracking.js — third-party analytics/pixel injection for the customer storefront.
 *
 * SCOPING (the whole point): a provider's tags are injected ONLY when THEIR brand is the
 * active storefront (their subdomain / ?b= link), and the platform's tags ONLY on the apex
 * Bookboll marketplace. So a provider measures traffic to THEIR site alone — never the
 * Bookboll marketplace, and never another provider's site. Injection lives inside the
 * customer shell (useStorefrontTracking is called from CustomerLayout, which App renders
 * only for area==='customer'), so tags NEVER load in the admin dashboard or auth pages.
 *
 * Supported via validated IDs: Google Analytics 4 (gtag.js), Google Tag Manager, Meta
 * (Facebook) Pixel — plus an optional raw custom <head> snippet (advanced; the site owner's
 * own storefront only).
 */
import { watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { activeBrand, isWhiteLabel, platformBrand, isBrandPreview } from './brand.js'

// ── Validators — only well-formed IDs are ever turned into snippets ────────────
export const isGa4     = (id) => /^G-[A-Z0-9]{4,}$/i.test((id || '').trim())
export const isGtm     = (id) => /^GTM-[A-Z0-9]{4,}$/i.test((id || '').trim())
export const isMetaPix = (id) => /^\d{6,20}$/.test((id || '').trim())

const MARK = 'data-bb-trk'   // marks every node we inject (for de-dupe + cleanup)
let enabledConfig = {}
let lastSig = ''             // signature of the currently-injected config

/** Normalize a tracking config off a brand-like object ({ tracking: {...} }). */
export function trackingFrom(brandLike) {
  const t = (brandLike && brandLike.tracking) || {}
  return {
    metaPixel: (t.metaPixel || '').trim(),
    ga4:       (t.ga4 || '').trim(),
    gtm:       (t.gtm || '').trim(),
    head:      (t.head || ''),   // raw code preserved verbatim (whitespace may matter)
  }
}

/** Does a config carry anything worth injecting? */
export function hasAnyTracking(cfg) {
  return !!(cfg && (isMetaPix(cfg.metaPixel) || isGa4(cfg.ga4) || isGtm(cfg.gtm) || cfg.head))
}

function sigOf(cfg) { return [cfg.metaPixel, cfg.ga4, cfg.gtm, cfg.head].join('|') }

function el(tag, attrs, text) {
  const n = document.createElement(tag)
  n.setAttribute(MARK, '')
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === true) n.setAttribute(k, '')
    else if (v != null && v !== false) n.setAttribute(k, v)
  }
  if (text != null) n.textContent = text
  return n
}

/** Remove every tag we've injected. */
export function clearTracking() {
  if (typeof document === 'undefined') return
  document.querySelectorAll(`[${MARK}]`).forEach((n) => n.remove())
  lastSig = ''
  enabledConfig = {}
}

/**
 * Inject the tags for a config. Idempotent: identical config → no-op; a changed config
 * clears the old tags first. Only well-formed IDs are injected.
 */
export function injectTracking(cfg) {
  if (typeof document === 'undefined' || !cfg) return
  const sig = sigOf(cfg)
  if (sig === lastSig) return
  clearTracking()
  lastSig = sig
  enabledConfig = cfg
  if (!hasAnyTracking(cfg)) return
  const head = document.head
  const body = document.body

  // Google Tag Manager
  if (isGtm(cfg.gtm)) {
    const id = cfg.gtm.trim()
    head.appendChild(el('script', {}, `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${id}');`))
    const ns = el('noscript')
    ns.innerHTML = `<iframe src="https://www.googletagmanager.com/ns.html?id=${id}" height="0" width="0" style="display:none;visibility:hidden"></iframe>`
    if (body) body.insertBefore(ns, body.firstChild)
  }

  // Google Analytics 4 (gtag.js)
  if (isGa4(cfg.ga4)) {
    const id = cfg.ga4.trim()
    head.appendChild(el('script', { async: true, src: `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}` }))
    head.appendChild(el('script', {}, `window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','${id}');`))
  }

  // Meta (Facebook) Pixel
  if (isMetaPix(cfg.metaPixel)) {
    const id = cfg.metaPixel.trim()
    head.appendChild(el('script', {}, `!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','${id}');fbq('track','PageView');`))
    const ns = el('noscript')
    ns.innerHTML = `<img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=${id}&ev=PageView&noscript=1" alt="" />`
    head.appendChild(ns)
  }

  // Advanced: raw custom <head> code (owner's OWN storefront only — same scoping as above).
  // Parsed via <template> then re-created so <script> tags actually execute.
  if (cfg.head) {
    try {
      const tpl = document.createElement('template')
      tpl.innerHTML = cfg.head
      Array.from(tpl.content.childNodes).forEach((node) => {
        if (node.nodeType !== 1) return                 // skip text/comment nodes
        if (node.tagName === 'SCRIPT') {
          const s = el('script')
          for (const a of node.attributes) s.setAttribute(a.name, a.value)
          s.textContent = node.textContent
          head.appendChild(s)
        } else {
          node.setAttribute(MARK, '')
          head.appendChild(node)
        }
      })
    } catch { /* never break the storefront on a bad snippet */ }
  }
}

/** Fire a page-view on SPA navigation for GA4 + Meta (GTM users handle this via a GTM trigger). */
export function trackPageView(path) {
  if (typeof window === 'undefined') return
  try { if (isGa4(enabledConfig.ga4) && typeof window.gtag === 'function') window.gtag('event', 'page_view', { page_path: path }) } catch { /* ignore */ }
  try { if (isMetaPix(enabledConfig.metaPixel) && typeof window.fbq === 'function') window.fbq('track', 'PageView') } catch { /* ignore */ }
}

/** The config that applies RIGHT NOW: the provider's on their own site, else the platform's. */
export function activeTrackingConfig() {
  return isWhiteLabel.value ? trackingFrom(activeBrand.value) : trackingFrom(platformBrand.value)
}

/**
 * Storefront-only tracking. Call from CustomerLayout's <script setup>. Injects the active
 * config on mount + whenever the resolved brand/platform config changes, and fires a
 * page-view on each in-app navigation. Because it's only mounted inside the customer shell,
 * it can never run on the admin dashboard or auth pages.
 */
export function useStorefrontTracking() {
  const route = useRoute()
  // Never inject in the dashboard "live preview" (?preview=1): preview is for checking layout,
  // not measuring traffic — so it must not pollute the provider's analytics, and (belt-and-braces
  // with detectBrandSlug) it keeps a provider's custom scripts off the apex origin during preview.
  const apply = () => { if (isBrandPreview()) { clearTracking(); return } injectTracking(activeTrackingConfig()) }

  onMounted(apply)
  // Watch BOTH refs so tags re-apply on either transition (apex platform config vs a resolved
  // provider brand). The idempotent signature check in injectTracking makes redundant fires no-ops.
  watch([activeBrand, platformBrand], apply, { deep: true })

  // Vue watchers are lazy: the first callback is a real navigation, not the initial load.
  // The snippets send the initial page-view; every subsequent route must also be counted.
  watch(() => route.path, (p) => { if (!isBrandPreview()) trackPageView(p) })

  onBeforeUnmount(clearTracking)
}
