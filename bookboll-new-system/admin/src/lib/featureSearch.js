import { canAccessPage, can, CAP, PLATFORM_ROLES } from './permissions.js'

// Search describes tasks as well as page names. These are navigation links only:
// destinations retain their own permissions and confirmation/save flows.
const entry = (id, title, description, group, to, keywords = '', extra = {}) => ({ id, title, description, group, to, keywords, ...extra })
export const FEATURE_DESTINATIONS = [
  entry('booking-payout', 'Set up booking payouts', 'Connect how you get paid for online venue bookings.', 'Booking wallet', '/wallet/bookings?setup=payout', 'online payments accept cards credit debit stripe bank account add payout method receive money get paid payment gateway', { providerOnly: true, capability: CAP.MANAGE_PAYOUTS }),
  entry('booking-wallet', 'Booking wallet', 'View venue earnings, balances and withdrawals.', 'Money', '/wallet/bookings', 'booking online payments payout balance cash out withdraw money bank stripe'),
  entry('payments', 'Payments', 'Review payments and reconcile transactions.', 'Money', '/payments', 'paid unpaid receipts invoices payment history cash card refund'),
  entry('branding', 'Website logo & colours', 'Change your website name, logo and brand colours.', 'My website', '/brand?tab=branding', 'design customize customise branding appearance color theme upload photo picture name tagline'),
  entry('sections', 'Show or hide website sections', 'Choose the content visitors see on your site.', 'My website', '/brand?tab=sections', 'design editor homepage layout visibility hide show listings memberships about contact', { providerOnly: true }),
  entry('content', 'About & contact pages', 'Edit your business story and contact details.', 'My website', '/brand?tab=content', 'website content text phone address email opening hours about us contact us', { providerOnly: true }),
  entry('site-settings', 'Website settings', 'Set languages and search engine information.', 'My website', '/brand?tab=settings', 'seo google search engine meta title description language translate translation'),
  entry('banners', 'Home banners', 'Create slides, choose buttons and target visitors.', 'My website', '/banners', 'hero slideshow banner promotion design carousel image announcement'),
  entry('domain', 'Custom domain', 'Connect your own website address.', 'My website', '/website-domain', 'url www dns web address domain name'),
  entry('embed', 'Embed booking on your website', 'Add a booking widget to an existing site.', 'My website', '/website-embed', 'wordpress iframe widget external website integration'),
  entry('website-blog', 'Website blog', 'Write news and updates for your customers.', 'My website', '/website-blog', 'article post news publish story', { providerOnly: true }),
  entry('tracking', 'Website tracking', 'Manage analytics and advertising integrations.', 'My website', '/tracking', 'google analytics pixel facebook meta ads gtag gtm'),
  entry('calendar', 'Calendar', 'See availability and manage your schedule.', 'Day to day', '/calendar', 'schedule slots time availability quick booking reservation'),
  entry('bookings', 'Bookings', 'Find, update and manage reservations.', 'Day to day', '/bookings', 'reservation cancel reschedule customer booking refund'),
  entry('listings', 'Locations & listings', 'Manage venues, courts, prices and opening hours.', 'Day to day', '/listings', 'add location venue court space resources price availability hours schedule online payment options'),
  entry('memberships', 'Membership listings', 'Advertise your academy and manage monthly listing payments.', 'Day to day', '/memberships', 'membership subscriptions gym academy classes create listing contact phone marketplace subscription pricing'),
  entry('customers', 'Customers', 'Find customer profiles, bookings and contact details.', 'Customers', '/customers', 'crm people clients history loyalty points'),
  entry('membership-members', 'Membership subscribers & attendance', 'Add offline members, manage cancellations and record attendance or payments received.', 'Customers', '/subscriptions', 'member membership subscriber enrol enrollment offline manual cancel cancellation pause renew attendance check in register gym academy'),
  entry('special-days', 'Special days & closures', 'Change hours for holidays and one-off dates.', 'Day to day', '/special-days', 'holiday close closed block dates opening hours'),
  entry('promos', 'Promo codes', 'Manage discounts and promotional codes.', 'Marketing', '/promocodes', 'coupon voucher promotion discount sale offer'),
  entry('email', 'Email sender', 'Configure the email address used for messages.', 'Marketing', '/email-sender', 'smtp email delivery sender notifications'),
  entry('whatsapp', 'WhatsApp', 'Set up your WhatsApp sender.', 'Marketing', '/whatsapp', 'whatsapp chat messages phone sender'),
  entry('notifications', 'Notification templates', 'Manage automated messages to customers.', 'Settings', '/notifications', 'email sms confirmation reminder message notification template'),
  entry('team', 'Team & users', 'Manage staff roles and access to your business.', 'Administration', '/team', 'invite employee staff permissions access owner add user business'),
  entry('settings', 'Settings', 'Manage account and business preferences.', 'Settings', '/settings', 'account business profile policies preferences'),
  entry('analytics', 'Business analytics', 'Explore bookings, revenue and customer activity.', 'Reports', '/dashboard', 'reports statistics revenue sales commission performance'),
  entry('countries', 'Countries & cities', 'Manage supported locations, commission and rewards.', 'Platform admin', '/countries', 'country city currency loyalty rewards points earn redeem rates commission'),
  entry('approvals', 'Approval queue', 'Review listings, withdrawals and payout methods.', 'Platform admin', '/approvals', 'approve pending review bank payout method verification'),
  entry('moderation', 'Event moderation', 'Review and manage player-hosted events.', 'Administration', '/moderation', 'games event moderation review report'),
  entry('audit', 'Audit logs', 'Review changes made by your team.', 'Platform admin', '/audit-logs', 'history activity log who changed'),
  entry('platform-blog', 'Marketplace blog', 'Manage articles on the Bookboll marketplace.', 'Platform admin', '/blog-posts', 'article news story blog post'),
]

const normalize = value => String(value || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim()
const stopWords = new Set('how do i can my the a an to for of and or up set change edit add manage please want need'.split(' '))
const stem = word => word.length > 4 && word.endsWith('s') ? word.slice(0, -1) : word
const words = text => normalize(text).split(' ').filter(Boolean).map(stem)

// One mistyped/omitted character, or two transposed adjacent letters.
function nearWord(a, b) {
  if (a.length < 5 || Math.abs(a.length - b.length) > 1) return false
  if (a.length === b.length) {
    const differences = [...a].map((c, i) => c !== b[i] ? i : -1).filter(i => i >= 0)
    return differences.length === 1 || (differences.length === 2 && differences[1] === differences[0] + 1 && a[differences[0]] === b[differences[1]] && a[differences[1]] === b[differences[0]])
  }
  const [short, long] = a.length < b.length ? [a, b] : [b, a]
  let i = 0
  while (i < short.length && short[i] === long[i]) i++
  return short.slice(i) === long.slice(i + 1)
}

export function searchFeatures(query, { role, overrides = null, providerScoped = false } = {}) {
  const available = FEATURE_DESTINATIONS.filter(item =>
    (!item.providerOnly || (providerScoped && !PLATFORM_ROLES.includes(role) && role !== 'admin'))
    && canAccessPage(item.to.split('?')[0], role, overrides)
    && (!item.capability || can(item.capability, role, overrides)))
  const terms = words(query).filter(term => !stopWords.has(term))
  if (!normalize(query)) return available.filter(item => ['booking-payout', 'branding', 'calendar', 'listings', 'banners', 'team'].includes(item.id)).slice(0, 6)
  if (!terms.length) return []
  const vocabulary = new Set(available.flatMap(item => words(`${item.title} ${item.description} ${item.group} ${item.keywords}`)))
  return available.map(item => {
    const titleWords = words(item.title), descriptionWords = words(item.description), allWords = words(`${item.title} ${item.description} ${item.group} ${item.keywords}`)
    let score = normalize(item.title) === normalize(query) ? 100 : 0
    for (const term of terms) {
      if (titleWords.includes(term)) score += 12
      else if (descriptionWords.includes(term)) score += 9
      else if (allWords.includes(term)) score += 7
      else if (allWords.some(word => word.startsWith(term))) score += 4
      else if (!vocabulary.has(term) && allWords.some(word => nearWord(term, word))) score += 2
      else return { ...item, score: 0 }
    }
    return { ...item, score }
  }).filter(item => item.score > 0).sort((a, b) => b.score - a.score).slice(0, 8)
}
