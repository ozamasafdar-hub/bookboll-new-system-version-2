/**
 * premium.js — Bookboll for Business PREMIUM plan: the published pricing and
 * feature list rendered on business.bookboll.com/pricing.
 *
 * Entitlement (who IS premium, and the upgrade flow) is enforced in the admin
 * dashboard and on the server — the public pricing page only shows the offer.
 *
 * The admin app owns the real subscription checkout and reads the business plan
 * from Laravel. This public module contains display constants only. Booking fees are enforced by the shared Laravel market policy.
 */

// Premium is sold annually. Laravel must enforce the no-trial policy (readmeAlamgir.md).
export const PREMIUM_YEARLY_TOTAL = 588
export function billedAmount() { return PREMIUM_YEARLY_TOTAL }

export const PREMIUM_FEATURES = [
  { key: 'membership_website', label: 'Membership listings on your website', desc: 'Show academy contact listings on your branded website. Marketplace exposure is a separate monthly subscription per listing, on every plan.' },
  { key: 'blog',       label: 'Website blog',       desc: 'Publish posts on your own site — great for Google.' },
  { key: 'marketing',  label: 'Marketing emails',   desc: 'Email campaigns to your opted-in customers.' },
  { key: 'exports',    label: 'Data exports',       desc: 'Download bookings, customers, payments & more as CSV.' },
  { key: 'promocodes', label: 'Promo codes',        desc: 'Discount codes with targeting & usage limits.' },
  { key: 'banners',    label: 'Custom home banners', desc: 'Your own hero slides with buttons & images.' },
  { key: 'widgets',    label: 'Embed & widgets',    desc: 'Booking widgets and buttons for your existing website.' },
  { key: 'whitelabel', label: 'White-label website', desc: 'Remove the Bookboll badge from your site — only a small footer credit remains.' },
  { key: 'own_site_only', label: 'Off-marketplace listings', desc: 'Hide a venue listing from the Bookboll Marketplace and sell it only on your own website.' },
]

// Global upgrade-modal state (rendered once by components/UpgradeModal.vue).
