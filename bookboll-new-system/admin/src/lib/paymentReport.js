export const paymentCategories = [
  ['provider_booking_fee', 'Provider booking fee'], ['owned_platform_fee', 'Platform fee · own venue'], ['provider_membership_fee', 'Provider membership fee'], ['event_fee', 'Event host fee'],
  ['owned_online', 'Own venue share · online'], ['owned_onsite', 'Own venue share · on site'], ['owned_unallocated', 'Bookboll receipt · fee split missing'], ['provider_online', 'Provider · online share'],
  ['provider_arrival', 'Provider · arrival payment'], ['provider_manual', 'Provider · manual booking'], ['cancelled_uncollected', 'Cancelled · no money received'], ['unclassified', 'Needs review'],
].map(([value, label]) => ({ value, label }))
export const channelOptions = [['marketplace', 'Bookboll marketplace'], ['site', 'Business website'], ['admin', 'Added by staff'], ['unknown', 'Not recorded']].map(([value, label]) => ({ value, label }))
export const methodOptions = [['online', 'Online payment'], ['cash', 'Cash'], ['card', 'Card at venue'], ['cheque', 'Cheque'], ['wire_transfer', 'Bank transfer']].map(([value, label]) => ({ value, label }))
// Wallet clearing/payout states are separate from the payment ledger.
export const statusOptions = [['paid', 'Paid'], ['pending', 'Pending'], ['refunded', 'Refunded'], ['cancelled', 'Cancelled']].map(([value, label]) => ({ value, label }))
export function listingsForVenues(listings, venueIds = []) {
  const selected = new Set(venueIds.map(String))
  return listings.filter(listing => !selected.size || selected.has(String(listing.venue_id)))
}
export function keepAvailableListings(selectedIds, listings) {
  const available = new Set(listings.map(listing => String(listing.value)))
  return selectedIds.filter(id => available.has(String(id)))
}
export const labelFor = (options, value) => options.find(x => x.value === (options === methodOptions && value === 'credit_card' ? 'card' : value))?.label || value || 'Not recorded'
export const money = (amount, currency) => {
  if (amount === null || amount === undefined || !Number.isFinite(Number(amount))) return '—'
  try { return new Intl.NumberFormat('en', { style: 'currency', currency, currencyDisplay: 'code' }).format(Number(amount)) }
  catch { return `${currency || 'Currency missing'} ${Number(amount).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}` }
}
const localDate = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
export function periodDates(period, now = new Date()) {
  if (period === 'all') return ['', '']
  if (period === 'last_month') return [localDate(new Date(now.getFullYear(), now.getMonth()-1, 1)), localDate(new Date(now.getFullYear(), now.getMonth(), 0))]
  const from = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period === 'month') from.setDate(1)
  if (period === '7') from.setDate(from.getDate()-6)
  if (period === '30') from.setDate(from.getDate()-29)
  return [localDate(from), localDate(now)]
}
export function filterError(f) {
  if (f.from_date && f.to_date && f.from_date > f.to_date) return 'End date must be on or after the start date.'
  if (f.amount_min !== '' && Number(f.amount_min) < 0) return 'Minimum amount cannot be negative.'
  if (f.amount_max !== '' && Number(f.amount_max) < 0) return 'Maximum amount cannot be negative.'
  if (f.amount_min !== '' && f.amount_max !== '' && Number(f.amount_min) > Number(f.amount_max)) return 'Maximum amount must be at least the minimum.'
  return ''
}
export function queryFilters(filters) {
  return Object.fromEntries(Object.entries(filters).map(([k,v]) => [k, Array.isArray(v) ? v.join(',') : v]).filter(([,v]) => v !== '' && v !== null && v !== undefined))
}
/** Legacy records remain inspectable but are never presented as verified earnings. */
export function legacyPaymentRows(data) {
  const find = (list,id) => (list || []).find(x => String(x.id) === String(id))
  const rows = data.payments?.data || (Array.isArray(data.payments) ? data.payments : [])
  return rows.map(p => {
    const b = find(data.bookings,p.booking_id)
    return { ...p, category:'unclassified', recipient_kind:'unknown', recipient_label:p.recipient || 'Not recorded',
      customer_name:find(data.customers,b?.customer_id)?.full_name || '—', venue_name:find(data.locations,b?.location_id)?.name || '—',
      listing_name:find(data.listings,b?.listing_id)?.name || '', booking_ref:b?.booking_id || (p.booking_id ? `#${p.booking_id}` : ''),
      channel:'unknown', row_kind:'allocation', can_collect:false, payment_date:(p.paid_at || p.created_at || '').slice(0,10),
      review_reason:'This server has not provided the recipient allocation and historical booking context yet.' }
  })
}
