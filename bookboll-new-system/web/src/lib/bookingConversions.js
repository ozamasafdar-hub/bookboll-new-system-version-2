import { activeTrackingConfig, injectTracking, isMetaPix, isGa4 } from './tracking.js'
import { activeBrand, isWhiteLabel, isBrandPreview } from './brand.js'

const conversionMemory = new Set()
const conversionStoreKey = 'bb_booking_conversions_v1'

/** Only pass the backend's checkout_conversion / conversion payload here. */
export async function trackBookingConversion(event, receipt) {
  try {
    if (typeof window === 'undefined' || isBrandPreview()
      || !['InitiateCheckout', 'Purchase', 'Schedule'].includes(event) || !receipt
      || (event !== 'Schedule' && (typeof receipt.value !== 'number' || !Number.isFinite(receipt.value) || receipt.value <= 0 || !/^[A-Z]{3}$/.test(receipt.currency || '')))
      || !new RegExp(`^bb_${{ Purchase: 'purchase', InitiateCheckout: 'checkout', Schedule: 'schedule' }[event]}_[a-f0-9]{64}$`).test(receipt.event_id || '')
      || !Number.isInteger(receipt.num_items) || receipt.num_items < 1
      || !Array.isArray(receipt.content_ids) || !receipt.content_ids.length) return false

    // A provider checkout must never leak into the marketplace pixel on return.
    if (isWhiteLabel.value) {
      if (receipt.surface !== 'site' || !activeBrand.value?.businessId
        || Number(receipt.business_id) !== Number(activeBrand.value.businessId)) return false
    } else if (receipt.surface !== 'marketplace') return false

    const cfg = activeTrackingConfig()
    injectTracking(cfg)
    const parameters = {
      ...(event !== 'Schedule' ? { value: receipt.value, currency: receipt.currency } : {}),
      content_ids: receipt.content_ids.map(String), content_type: 'product', num_items: receipt.num_items,
      ...(Array.isArray(receipt.contents) ? { contents: receipt.contents } : {}),
    }
    const send = () => {
      let history = {}
      try { history = JSON.parse(window.localStorage.getItem(conversionStoreKey) || '{}') || {} } catch { /* memory fallback */ }
      let sent = false
      const once = (destination, deliver) => {
        const key = `${destination}:${receipt.event_id}`
        if (conversionMemory.has(key) || history[key]) return
        try {
          deliver()
          conversionMemory.add(key)
          history[key] = Date.now()
          sent = true
        } catch { /* a blocked tag must not interrupt payment */ }
      }
      if (isMetaPix(cfg.metaPixel) && typeof window.fbq === 'function') {
        once(`meta:${cfg.metaPixel}`, () => window.fbq('trackSingle', cfg.metaPixel, event, parameters, { eventID: receipt.event_id }))
      }
      if (isGa4(cfg.ga4) && typeof window.gtag === 'function') {
        once(`ga4:${cfg.ga4}`, () => window.gtag('event', { Purchase: 'purchase', InitiateCheckout: 'begin_checkout', Schedule: 'booking_confirmed' }[event], {
          send_to: cfg.ga4, ...(event !== 'Schedule' ? { value: receipt.value, currency: receipt.currency } : {}),
          ...(event === 'Purchase' ? { transaction_id: receipt.event_id } : {}),
          items: (receipt.contents || receipt.content_ids.map(id => ({ id, quantity: 1 }))).map(i => ({ item_id: String(i.id), quantity: i.quantity })),
        }))
      }
      try {
        // Bound storage and retain recent receipts across refreshes and return visits.
        window.localStorage.setItem(conversionStoreKey, JSON.stringify(Object.fromEntries(
          Object.entries(history).sort((a, b) => b[1] - a[1]).slice(0, 1000),
        )))
      } catch { /* private browsing / unavailable storage */ }
      return sent
    }
    // Serialize across tabs when supported, so two returns cannot both read an empty history.
    return window.navigator?.locks ? await window.navigator.locks.request(conversionStoreKey, send) : send()
  } catch { return false }
}
