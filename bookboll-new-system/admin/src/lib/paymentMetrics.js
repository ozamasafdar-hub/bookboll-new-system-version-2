// Payments and Analytics deliberately use identical metric keys and definitions.
export const paymentCards = platform => platform ? [
  ['total_sales','Total sales · GMV','Paid + pending sales across all businesses. Excludes cancelled and refunded payments.'],
  ['bookboll_received','Bookboll received','Platform fees + Bookboll’s own-venue share after fees; each receipt counted once'],
  ['platform_fees','Platform fees','External + own-venue fees, memberships and events'],
  ['owned_venue_received','Own venues · revenue','Venue share after the internal platform fee'],
  ['owned_pending','Own bookings · to collect','Unpaid platform fee + venue share'],
] : [
  ['provider_received','Your business received','Your share of recorded paid transactions, including staff-added bookings'],
  ['provider_received_offline','Collected directly','Cash, card at venue, cheque and bank transfer; includes staff-added bookings'],
  ['provider_pending','Still to collect','Unpaid amounts belonging to your business'],
  ['platform_fees','Bookboll fees','Recorded fees retained by Bookboll'],
]

export const paymentBreakdown = platform => platform ? [
  ['external_marketplace_fees','External venue fees · marketplace'], ['external_site_fees','External venue fees · provider websites'],
  ['owned_marketplace_fees','Own venue platform fees · marketplace'], ['owned_site_fees','Own venue platform fees · own website'],
  ['other_channel_fees','Booking fees · channel not recorded'], ['membership_fees','Provider membership fees'],
  ['event_fees','Event host fees'], ['owned_online','Own venue share · online'], ['owned_onsite','Own venue share · on site'],
  ['owned_unallocated','Bookboll receipts · historical fee split missing'],
] : [['provider_received_online','Online payments · your share'], ['provider_received_offline','Direct collections · your share'], ['provider_received_other','Other / unrecorded payment method']]

export const externalBusinessMetrics = [
  ['provider_total_sales','Total external-business sales','Paid + pending value, including staff-added bookings and Bookboll fees.'],
  ['provider_manual','Collected from staff-added bookings','Already included in businesses received. No platform fee.'],
  ['provider_pending','Businesses still to collect','Unpaid provider share; external businesses manage collection.'],
  ['provider_sales','Sales from marketplace & websites','Customer booking value, including Bookboll fees and arrival balances. Excludes staff-added bookings.'],
]

export const currencyTotals = (summary, currency) => summary.find(row => row.currency === currency) || {}
export const metricValue = (summary, currency, key) => Number(currencyTotals(summary, currency)[key] || 0)
