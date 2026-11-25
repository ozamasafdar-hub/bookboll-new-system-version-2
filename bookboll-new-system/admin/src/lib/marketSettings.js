export const MARKET_GROUPS = [
  { key: 'marketplace', label: 'Booking transaction fees', short: 'Booking fees', help: 'Two booking channels, the same rates on Free and Premium. Set global defaults here; countries and cities can override either rate. Premium unlocks extra features only.', fields: [
    { key: 'facility', label: 'Bookboll marketplace' },
    { key: 'site', label: 'Venue’s own website' },
  ] },
  { key: 'events', label: 'Event fees', short: 'Events', help: 'Platform fee on event seats booked through Bookboll.', fields: [{ key: 'event', label: 'Event seats' }] },
  { key: 'memberships', label: 'Marketplace membership listings', short: 'Membership listings', help: 'Monthly subscription per listing in USD, on every business plan. Own-website membership listings require Premium. Existing subscriptions keep their agreed price until cancelled; new subscriptions use this rate.', fields: [{ key: 'membership_listing_monthly', label: 'Monthly listing fee', currency: true }] },
  { key: 'comeback', label: 'Come back to the same venue', short: 'Comeback offer', help: 'After a customer’s first completed marketplace booking at each venue, send a personal code by email and WhatsApp where they have opted in. Valid for 7 days, once, at that venue on the marketplace. The discount comes only from Bookboll’s fee and is capped at that fee; the venue’s share stays unchanged.', fields: [
    { key: 'comeback_enabled', label: 'Send comeback offers', boolean: true },
    { key: 'comeback_discount_pct', label: 'Booking discount' },
  ] },
  { key: 'services', label: 'Services businesses can add', short: 'Services', help: 'Control which new services can be created. Existing listings, programs and events stay available.', fields: [
    { key: 'module_listings', label: 'Booking listings', boolean: true }, { key: 'module_classes', label: 'Membership listings', boolean: true }, { key: 'module_matchmaking', label: 'Events & tournaments', boolean: true },
  ] },
]
export const MARKET_FIELDS = MARKET_GROUPS.flatMap(g => g.fields)
export function marketDraft(config) { return Object.fromEntries(MARKET_FIELDS.map(f => [f.key, config.overrides[f.key] ?? null])) }
export function overrideCount(settings) { return MARKET_FIELDS.filter(f => settings?.overrides?.[f.key] != null).length }
export function effectiveDraft(values, parent) { return Object.fromEntries(MARKET_FIELDS.map(f => [f.key, values[f.key] ?? parent[f.key]])) }
export function marketValue(effective, key) {
  if (key === 'membership_listing_monthly') return `USD ${Number(effective[key] ?? 20).toFixed(2)}`
  if (key.startsWith('module_') || key === 'comeback_enabled') return effective[key] ? 'Enabled' : 'Disabled'
  const rate = effective[key] ?? (key === 'site' ? 10 : 15)
  return `${Number(Number(rate).toFixed(4))}%`
}
export function marketChanges(initial, draft) {
  return MARKET_FIELDS.filter(f => {
    const a = initial[f.key], b = draft[f.key]
    if (a === b) return false
    if (a === null || b === null || a === '' || b === '') return a !== b
    return Number(a) !== Number(b)
  })
}
export function validateMarketDraft(values) {
  for (const f of MARKET_FIELDS.filter(f => !f.boolean)) {
    const n = values[f.key]
    if (f.currency) { if (n !== null && (n === '' || !Number.isFinite(Number(n)) || Number(n) < 1 || Number(n) > 100000)) return { key: f.key, message: 'Enter a monthly fee from USD 1 to USD 100,000.' }; continue }
    if (n !== null && (n === '' || !Number.isFinite(Number(n)) || Number(n) < 0 || Number(n) > 100)) return { key: f.key, message: `${f.label}: enter a percentage from 0 to 100.` }
  }
  return null
}

export const BOOKING_FEE_FIELDS = MARKET_GROUPS[0].fields
export function feeExample(value, venue = false) {
  const rate = Number(value)
  if (value === '' || value == null || !Number.isFinite(rate) || rate < 0 || rate > 100) return '—'
  return (venue ? 100 - rate : rate).toFixed(2)
}
export function feeSource(settings, key) {
  const source = settings?.sources?.[key] || 'global'
  return source === 'city' ? 'City override' : source === 'country' ? 'Country override' : 'Global default'
}
