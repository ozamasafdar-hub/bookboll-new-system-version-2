import { activeTrackingConfig, injectTracking, isMetaPix, isGa4, isStorefrontTrackingActive } from './tracking.js'
import { isBrandPreview } from './brand.js'

const events = { ViewContent: 'view_item', AddToCart: 'add_to_cart', Search: 'search', Contact: 'contact', FindLocation: 'find_location', CompleteRegistration: 'sign_up' }

// Explicit, bounded fields only. Never forward customer names, email, phone,
// free-text searches, checkout URLs or arbitrary application state to ad tools.
export function trackStorefrontEvent(event, item = {}) {
  try {
    if (typeof window === 'undefined' || !isStorefrontTrackingActive() || isBrandPreview() || !events[event]) return false
    const cfg = activeTrackingConfig()
    if (!isMetaPix(cfg.metaPixel) && !isGa4(cfg.ga4)) return false
    const parameters = {}
    if (['listing', 'membership', 'event', 'venue'].includes(item.kind)) parameters.content_category = item.kind
    if (item.kind === 'listing') {
      if (!/^[1-9]\d*$/.test(String(item.id || ''))) return false
      parameters.content_ids = [String(item.id)]
      parameters.content_type = 'product'
      const quantity = Number.isInteger(item.quantity) && item.quantity > 0 ? item.quantity : 1
      parameters.contents = [{ id: String(item.id), quantity }]
      parameters.num_items = quantity
    }
    // Only bookable listing IDs exist in the catalog. Informational memberships
    // and community events deliberately have no product IDs or purchase value.
    if (item.kind === 'listing' && typeof item.value === 'number' && Number.isFinite(item.value) && item.value >= 0 && /^[A-Z]{3}$/.test(item.currency || '')) {
      parameters.value = item.value
      parameters.currency = item.currency
    }
    if (['phone', 'email', 'whatsapp'].includes(item.method)) parameters.contact_method = item.method
    if (event === 'AddToCart' && (!parameters.contents || parameters.value === undefined)) return false
    injectTracking(cfg)
    const eventID = `bb_${event.toLowerCase()}_${window.crypto?.randomUUID?.() || `${Date.now()}_${Math.random().toString(36).slice(2)}`}`
    let sent = false
    if (isMetaPix(cfg.metaPixel) && typeof window.fbq === 'function') {
      window.fbq('trackSingle', cfg.metaPixel, event, parameters, { eventID }); sent = true
    }
    if (isGa4(cfg.ga4) && typeof window.gtag === 'function') {
      window.gtag('event', events[event], { send_to: cfg.ga4, ...parameters,
        ...(parameters.contents ? { items: parameters.contents.map(i => ({ item_id: i.id, quantity: i.quantity })) } : {}),
      }); sent = true
    }
    return sent
  } catch { return false }
}
