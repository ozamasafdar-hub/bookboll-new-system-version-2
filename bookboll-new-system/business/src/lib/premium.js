/**
 * premium.js — Bookboll for Business PREMIUM plan: the published pricing and
 * feature list rendered on business.bookboll.com/pricing.
 *
 * Entitlement (who IS premium, and the upgrade flow) is enforced in the admin
 * dashboard and on the server — the public pricing page only shows the offer.
 *
 * The admin app owns the real subscription checkout and reads the business plan
 * from Laravel. This public module contains display constants only. The new
 * own-website commission policy still needs the backend work in readmeAlamgir.md.
 */

// Premium is sold annually. Laravel must enforce the no-trial policy (readmeAlamgir.md).
export const PREMIUM_YEARLY_TOTAL = 588
export function billedAmount() { return PREMIUM_YEARLY_TOTAL }

export const PREMIUM_FEATURES = [
  { key: 'website_commission', label: 'Premium own-website fee rate', desc: 'Your market’s Premium booking fee applies during an active paid subscription. Check the rate in your listing’s payment settings. Marketplace rates stay the same.' },
  { key: 'blog',       label: 'Website blog',       desc: 'Publish posts on your own site — great for Google.' },
  { key: 'marketing',  label: 'Marketing emails',   desc: 'Email campaigns to your opted-in customers.' },
  { key: 'exports',    label: 'Data exports',       desc: 'Download bookings, customers, payments & more as CSV.' },
  { key: 'promocodes', label: 'Promo codes',        desc: 'Discount codes with targeting & usage limits.' },
  { key: 'banners',    label: 'Custom home banners', desc: 'Your own hero slides with buttons & images.' },
  { key: 'widgets',    label: 'Embed & widgets',    desc: 'Booking widgets and buttons for your existing website.' },
  { key: 'whitelabel', label: 'White-label website', desc: 'Remove the Bookboll badge from your site — only a small footer credit remains.' },
  { key: 'own_site_only', label: 'Off-marketplace listings', desc: 'Hide a listing or program from the Bookboll Marketplace and sell it only on your own website.' },
]

// Global upgrade-modal state (rendered once by components/UpgradeModal.vue).
