export const MARKET_GROUPS = [
  { key: 'marketplace', label: 'Marketplace fees', short: 'Marketplace', help: 'Bookings made through Bookboll. These rates are the same on Free and Premium.', fields: [
    { key: 'facility', label: 'Venue bookings' }, { key: 'program', label: 'Memberships' }, { key: 'event', label: 'Event seats' },
  ] },
  { key: 'website', label: 'Provider website fees', short: 'Provider website', help: 'Venue bookings on a business’s own website. The same fee applies to deposits and full online payments.', fields: [
    { key: 'site_free', label: 'Free plan', automatic: 'Match marketplace rate' },
    { key: 'site_premium', label: 'Paid Premium plan', automatic: 'Half the marketplace rate' },
  ] },
  { key: 'services', label: 'Services businesses can add', short: 'Services', help: 'Control which new services can be created. Existing listings, programs and events stay available.', fields: [
    { key: 'module_listings', label: 'Booking listings', boolean: true }, { key: 'module_classes', label: 'Membership programs', boolean: true }, { key: 'module_matchmaking', label: 'Events & tournaments', boolean: true },
  ] },
]
export const MARKET_FIELDS = MARKET_GROUPS.flatMap(g => g.fields)
export function marketDraft(config) { return Object.fromEntries(MARKET_FIELDS.map(f => [f.key, config.overrides[f.key] ?? null])) }
export function overrideCount(settings) { return MARKET_FIELDS.filter(f => settings?.overrides?.[f.key] != null).length }
export function effectiveDraft(values, parent) { return Object.fromEntries(MARKET_FIELDS.map(f => [f.key, values[f.key] ?? parent[f.key]])) }
export function marketValue(effective, key) {
  if (key.startsWith('module_')) return effective[key] ? 'Enabled' : 'Disabled'
  const rate = effective[key] ?? Number(effective.facility) * (key === 'site_premium' ? .5 : 1)
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
    if (n !== null && (n === '' || !Number.isFinite(Number(n)) || Number(n) < 0 || Number(n) > 100)) return { key: f.key, message: `${f.label}: enter a percentage from 0 to 100.` }
  }
  return null
}
