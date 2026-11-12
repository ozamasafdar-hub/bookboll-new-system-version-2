/**
 * permissions.js — single source of truth for roles, capabilities & descriptions.
 *
 * ROLES are tiers the admin picks from a dropdown; each bakes in a sensible set of
 * capabilities. CAPABILITIES are individual actions (verbs) that map to dashboard
 * sections; they're shown in My Team's "Advanced — custom permissions" to fine-tune
 * a person beyond their role. Per-user overrides live on
 * admin_users.custom_permissions = { grant: [cap…], revoke: [cap…] }.
 *
 *   PLATFORM (Bookboll)              PROVIDER (a business, top → bottom)
 *   ──────────────────────────      ───────────────────────────────────────────
 *   super_admin    – everything     provider_admin       – Owner: full control
 *   country_admin  – assigned countries  facility_admin   – Manager: everything else
 *   platform_staff – ops/support    provider_supervisor  – Supervisor: ops + catalog
 *                                   staff                – Staff: ops + payments
 *                                   provider_viewer      – Viewer: read-only
 *
 * Each capability has a `side` (platform | provider | both) so the editor only
 * offers caps relevant to the role being edited.
 */

// ── Capability keys (verbs; one per section, view/manage split where useful) ───
export const CAP = {
  VIEW_CALENDAR:        'view_calendar',
  VIEW_BOOKINGS:        'view_bookings',
  MANAGE_BOOKINGS:      'manage_bookings',
  VIEW_SUBSCRIPTIONS:   'view_subscriptions',
  MANAGE_SUBSCRIPTIONS: 'manage_subscriptions',
  MANAGE_CUSTOMERS:     'manage_customers',
  ACCEPT_PAYMENTS:      'accept_payments',
  MANAGE_LISTINGS:      'manage_listings',      // Venues & Programs
  MANAGE_PROMOCODES:    'manage_promocodes',    // Promotions
  MANAGE_WEBSITE:       'manage_website',       // My Website (site + tracking)
  MANAGE_WEBSITE_BLOG:  'manage_website_blog',  // My Website → Blog posts
  VIEW_WALLET:          'view_wallet',          // My Wallets — see earnings/balances
  MANAGE_PAYOUTS:       'manage_payouts',       // My Wallets — edit payout details
  WITHDRAW_FUNDS:       'withdraw_funds',       // My Wallets — withdraw
  MANAGE_TEAM:          'manage_team',          // Team
  MODERATE_EVENTS:      'moderate_events',      // Event Moderation
  // ── Platform administration (Bookboll) — granular ──
  MANAGE_GEOGRAPHY:           'manage_geography',           // Countries & cities (enable/disable)
  MANAGE_LOYALTY:             'manage_loyalty',             // Loyalty points per country
  MANAGE_PLATFORM_WEBSITE:    'manage_platform_website',    // Edit the Bookboll marketplace site
  MANAGE_PLATFORM_BLOG:       'manage_platform_blog',       // Marketplace blog posts
  MANAGE_PROMOCODES_PLATFORM: 'manage_promocodes_platform', // Platform-fee promocodes
  MANAGE_PLATFORM_CATALOG:    'manage_platform_catalog',    // Venues & program listings on the platform
  APPROVE_LISTINGS:           'approve_listings',           // Approvals: venues & programs
  APPROVE_WITHDRAWALS:        'approve_withdrawals',        // Approvals: withdrawal requests (wallet verification)
  APPROVE_PAYOUTS:            'approve_payouts',            // Approvals: manual payout-method KYC setup
  PLATFORM_ADMIN:             'platform_admin',             // Audit Logs (+ super-admin umbrella)
  // Per-page export buttons (granted individually under "Export data").
  EXPORT_BOOKINGS:      'export_bookings',
  EXPORT_PAYMENTS:      'export_payments',
  EXPORT_CUSTOMERS:     'export_customers',
  EXPORT_SUBSCRIPTIONS: 'export_subscriptions',
  EXPORT_CATALOG:       'export_catalog',
}

const ALL = Object.values(CAP)
const ALL_EXPORTS = [CAP.EXPORT_BOOKINGS, CAP.EXPORT_PAYMENTS, CAP.EXPORT_CUSTOMERS, CAP.EXPORT_SUBSCRIPTIONS, CAP.EXPORT_CATALOG]

// Platform-administration set (Bookboll) + shared operations set.
const PLATFORM_ADMIN_CAPS = [
  CAP.MANAGE_GEOGRAPHY, CAP.MANAGE_LOYALTY, CAP.MANAGE_PLATFORM_WEBSITE, CAP.MANAGE_PLATFORM_BLOG,
  CAP.MANAGE_PROMOCODES_PLATFORM, CAP.MANAGE_PROMOCODES, CAP.MANAGE_PLATFORM_CATALOG,
  CAP.APPROVE_LISTINGS, CAP.APPROVE_WITHDRAWALS, CAP.APPROVE_PAYOUTS,
]
const OPS_CAPS = [
  CAP.VIEW_CALENDAR, CAP.VIEW_BOOKINGS, CAP.MANAGE_BOOKINGS, CAP.VIEW_SUBSCRIPTIONS, CAP.MANAGE_SUBSCRIPTIONS,
  CAP.MANAGE_CUSTOMERS, CAP.ACCEPT_PAYMENTS,
]

// ── Provider role tiers (each builds on the one below) ────────────────────────
const VIEWER_CAPS = [CAP.VIEW_CALENDAR, CAP.VIEW_BOOKINGS, CAP.VIEW_SUBSCRIPTIONS]
const STAFF_CAPS = [
  ...VIEWER_CAPS, CAP.MANAGE_BOOKINGS, CAP.MANAGE_SUBSCRIPTIONS, CAP.MANAGE_CUSTOMERS, CAP.ACCEPT_PAYMENTS,
]
// Supervisor adds catalog + promotions + moderation. Exports / website / wallet
// are NOT base — owner/manager grant them per-person (matrix "special permission").
const SUPERVISOR_CAPS = [
  ...STAFF_CAPS, CAP.MANAGE_LISTINGS, CAP.MANAGE_PROMOCODES, CAP.MODERATE_EVENTS,
]
// Manager = the whole business except editing payout details (owner-grant only).
const MANAGER_CAPS = [
  ...SUPERVISOR_CAPS, ...ALL_EXPORTS,
  CAP.MANAGE_WEBSITE, CAP.MANAGE_WEBSITE_BLOG, CAP.MANAGE_TEAM, CAP.VIEW_WALLET, CAP.WITHDRAW_FUNDS,
]
const OWNER_CAPS = [...MANAGER_CAPS, CAP.MANAGE_PAYOUTS]

// Country Admin = super-equivalent BUT scoped to their countries, so they do NOT hold
// the GLOBAL Bookboll-HQ content caps (one marketplace site/blog/catalog for everyone)
// nor the provider-own-website caps (they have no personal white-label). Everything
// else (geography, loyalty, approvals, payouts, promocodes, team, ops, audit, exports)
// they get — enforced per-jurisdiction in the pages.
const COUNTRY_ADMIN_EXCLUDE = [
  CAP.MANAGE_PLATFORM_WEBSITE, CAP.MANAGE_PLATFORM_BLOG, CAP.MANAGE_PLATFORM_CATALOG,
  CAP.MANAGE_WEBSITE, CAP.MANAGE_WEBSITE_BLOG,
]
const COUNTRY_ADMIN_CAPS = ALL.filter(c => !COUNTRY_ADMIN_EXCLUDE.includes(c))

const ROLE_CAPS = {
  // ── Platform (Bookboll) ──
  super_admin:    ALL,
  // Spatie seed role used by admin@bookboll.com — same as super_admin in the shell.
  admin:          ALL,
  country_admin:  COUNTRY_ADMIN_CAPS,
  // Full platform powers (per the matrix), scoped to assigned venues/countries.
  // Owner-business website/blog/team/payouts and audit logs are NOT included;
  // event moderation + exports are granted per-person.
  platform_staff: [...PLATFORM_ADMIN_CAPS, ...OPS_CAPS],

  // ── Provider (business) ──
  provider_admin:      OWNER_CAPS,
  facility_admin:      MANAGER_CAPS,
  provider_supervisor: SUPERVISOR_CAPS,
  staff:               STAFF_CAPS,
  provider_viewer:     VIEWER_CAPS,

  // ── Legacy (pre-migration) — kept so existing rows keep working ──
  senior_employee: [CAP.VIEW_CALENDAR, CAP.VIEW_BOOKINGS, CAP.MANAGE_BOOKINGS, CAP.MANAGE_CUSTOMERS, CAP.ACCEPT_PAYMENTS],
  junior_employee: [CAP.VIEW_CALENDAR, CAP.VIEW_BOOKINGS, CAP.MANAGE_BOOKINGS],
  guard:           [CAP.VIEW_CALENDAR, CAP.VIEW_BOOKINGS],
}

// Ordered role lists for the role dropdown.
export const PLATFORM_ROLES = ['super_admin', 'country_admin', 'platform_staff']
export const PROVIDER_ROLES = ['provider_admin', 'facility_admin', 'provider_supervisor', 'staff', 'provider_viewer']

/**
 * Does `role` (with optional per-user `overrides`) have capability `cap`?
 * super_admin always passes (overrides can't lock the owner out).
 */
export function can(cap, role, overrides = null) {
  if (role === 'super_admin' || role === 'admin') return true
  const base = ROLE_CAPS[role]
  if (!Array.isArray(base)) return false
  const revoke = overrides?.revoke || []
  if (revoke.includes(cap)) return false
  if (base.includes(cap)) return true
  const grant = overrides?.grant || []
  return grant.includes(cap)
}

/** The role's BASE capability list (no per-user overrides). */
export function roleBaseCaps(role) {
  if (role === 'super_admin' || role === 'admin') return [...ALL]
  const base = ROLE_CAPS[role]
  return Array.isArray(base) ? [...base] : []
}

/** The effective capability list for a role + overrides (handy for the UI). */
export function effectiveCaps(role, overrides = null) {
  if (role === 'super_admin' || role === 'admin') return [...ALL]
  const base = ROLE_CAPS[role]
  if (!Array.isArray(base)) return []
  const revoke = new Set(overrides?.revoke || [])
  const set = new Set(base.filter(c => !revoke.has(c)))
  for (const g of (overrides?.grant || [])) if (!revoke.has(g)) set.add(g)
  return ALL.filter(c => set.has(c))   // stable order
}

// ── Page access ───────────────────────────────────────────────────────────────
// Each gated route maps to the capability(ies) of its section. An array = "any of".
// `null` = any signed-in role. Unlisted pages are allowed.
export const PAGE_CAP = {
  '/':              null,
  '/dashboard':     null,
  '/calendar':      CAP.VIEW_CALENDAR,
  '/bookings':      [CAP.VIEW_BOOKINGS, CAP.MANAGE_BOOKINGS],
  '/checkin':       [CAP.VIEW_SUBSCRIPTIONS, CAP.MANAGE_SUBSCRIPTIONS],
  '/customers':     CAP.MANAGE_CUSTOMERS,
  '/subscribers':   [CAP.VIEW_SUBSCRIPTIONS, CAP.MANAGE_SUBSCRIPTIONS],
  '/subscriptions': [CAP.VIEW_SUBSCRIPTIONS, CAP.MANAGE_SUBSCRIPTIONS],
  '/listings':      [CAP.MANAGE_LISTINGS, CAP.MANAGE_PLATFORM_CATALOG],
  '/memberships':   [CAP.MANAGE_LISTINGS, CAP.MANAGE_PLATFORM_CATALOG],
  '/classes':       [CAP.MANAGE_LISTINGS, CAP.MANAGE_PLATFORM_CATALOG],
  '/special-days':  CAP.MANAGE_LISTINGS,
  '/payments':      CAP.ACCEPT_PAYMENTS,
  '/promocodes':    [CAP.MANAGE_PROMOCODES, CAP.MANAGE_PROMOCODES_PLATFORM],
  '/brand':         [CAP.MANAGE_WEBSITE, CAP.MANAGE_PLATFORM_WEBSITE],
  '/banners':       CAP.MANAGE_WEBSITE,
  '/website-embed': CAP.MANAGE_WEBSITE,
  '/website-domain': CAP.MANAGE_WEBSITE,
  '/tracking':      CAP.MANAGE_WEBSITE,
  '/notifications': CAP.MANAGE_WEBSITE,   // automated message templates (manager+)
  '/email-sender':  CAP.MANAGE_WEBSITE,   // own SMTP sending identity (manager+)
  '/whatsapp':      CAP.MANAGE_WEBSITE,   // provider's own WhatsApp sender (super admin edits Bookboll's) — like Email sender
  '/website-blog':  CAP.MANAGE_WEBSITE_BLOG,
  '/payment-setup': [CAP.VIEW_WALLET, CAP.MANAGE_PAYOUTS],
  '/wallet':        [CAP.VIEW_WALLET, CAP.MANAGE_PAYOUTS, CAP.WITHDRAW_FUNDS],
  '/team':          CAP.MANAGE_TEAM,
  '/moderation':    CAP.MODERATE_EVENTS,
  '/approvals':     [CAP.APPROVE_LISTINGS, CAP.APPROVE_WITHDRAWALS],
  '/countries':     CAP.MANAGE_GEOGRAPHY,
  '/loyalty':       CAP.MANAGE_LOYALTY,
  '/blog-posts':    CAP.MANAGE_PLATFORM_BLOG,
  '/audit-logs':    CAP.PLATFORM_ADMIN,
  '/venue-requests':CAP.APPROVE_LISTINGS,
  '/settings':      null,
}

/** Can `role` (+ overrides) open the page at `path`? */
export function canAccessPage(path, role, overrides = null) {
  let cap = PAGE_CAP[path]
  // Sub-routes (e.g. /wallet/subscription) inherit their base section's cap so they can't
  // slip past the gate just because the exact path isn't mapped.
  if (cap === undefined && path && path.indexOf('/', 1) > 0) cap = PAGE_CAP['/' + path.split('/')[1]]
  if (cap === undefined || cap === null) return true
  if (Array.isArray(cap)) return cap.some(c => can(c, role, overrides))
  return can(cap, role, overrides)
}

// ── Human-facing capability metadata (My Team "Advanced" permission editor) ───
// label = verb phrase; group = editor section; side = which role sides see it.
export const CAP_INFO = {
  [CAP.VIEW_CALENDAR]:        { label: 'View calendar',        group: 'Operations', side: 'both' },
  [CAP.VIEW_BOOKINGS]:        { label: 'View bookings',        group: 'Operations', side: 'both' },
  [CAP.MANAGE_BOOKINGS]:      { label: 'Manage bookings',      group: 'Operations', side: 'both' },
  [CAP.VIEW_SUBSCRIPTIONS]:   { label: 'View membership subscribers',   group: 'Operations', side: 'both' },
  [CAP.MANAGE_SUBSCRIPTIONS]: { label: 'Manage members & attendance', group: 'Operations', side: 'both' },
  [CAP.MANAGE_CUSTOMERS]:     { label: 'Manage customers',     group: 'Operations', side: 'both' },
  [CAP.ACCEPT_PAYMENTS]:      { label: 'Accept payments',      group: 'Operations', side: 'both' },
  [CAP.MANAGE_LISTINGS]:      { label: 'Manage venues & programs', group: 'Catalog',   side: 'provider' },
  [CAP.MANAGE_PROMOCODES]:    { label: 'Manage promocodes (provider-fee)', group: 'Marketing', side: 'both' },
  [CAP.MANAGE_WEBSITE]:       { label: 'Manage website',       group: 'My Website', side: 'provider' },
  [CAP.MANAGE_WEBSITE_BLOG]:  { label: 'Manage blog posts',    group: 'My Website', side: 'provider' },
  [CAP.VIEW_WALLET]:          { label: 'View wallet & earnings', group: 'My Wallets', side: 'provider' },
  [CAP.MANAGE_PAYOUTS]:       { label: 'Edit payout details',  group: 'My Wallets', side: 'provider' },
  [CAP.WITHDRAW_FUNDS]:       { label: 'Withdraw funds',       group: 'My Wallets', side: 'provider' },
  [CAP.MANAGE_TEAM]:          { label: 'Invite & manage team', group: 'Administration', side: 'provider' },
  [CAP.MODERATE_EVENTS]:      { label: 'Moderate events',      group: 'Administration', side: 'both' },
  // Platform administration (Bookboll) — only offered for platform-side roles.
  [CAP.MANAGE_GEOGRAPHY]:           { label: 'Manage countries & cities',  group: 'Platform admin', side: 'platform' },
  [CAP.MANAGE_LOYALTY]:             { label: 'Manage loyalty points',      group: 'Platform admin', side: 'platform' },
  [CAP.MANAGE_PLATFORM_WEBSITE]:    { label: 'Edit platform website',      group: 'Platform admin', side: 'platform' },
  [CAP.MANAGE_PLATFORM_BLOG]:       { label: 'Manage platform blog posts', group: 'Platform admin', side: 'platform' },
  [CAP.MANAGE_PROMOCODES_PLATFORM]: { label: 'Manage platform-fee promocodes', group: 'Platform admin', side: 'platform' },
  [CAP.MANAGE_PLATFORM_CATALOG]:    { label: 'Manage platform listings',   group: 'Platform admin', side: 'platform' },
  [CAP.APPROVE_LISTINGS]:           { label: 'Approve venues & programs',   group: 'Platform admin', side: 'platform' },
  [CAP.APPROVE_WITHDRAWALS]:        { label: 'Approve withdrawal requests', group: 'Platform admin', side: 'platform' },
  [CAP.APPROVE_PAYOUTS]:            { label: 'Approve payout-method setup', group: 'Platform admin', side: 'platform' },
  [CAP.PLATFORM_ADMIN]:             { label: 'View audit logs',            group: 'Platform admin', side: 'platform' },
  [CAP.EXPORT_BOOKINGS]:      { label: 'Export bookings',          group: 'Export data', side: 'both' },
  [CAP.EXPORT_PAYMENTS]:      { label: 'Export payments',          group: 'Export data', side: 'both' },
  [CAP.EXPORT_CUSTOMERS]:     { label: 'Export customers',         group: 'Export data', side: 'both' },
  [CAP.EXPORT_SUBSCRIPTIONS]: { label: 'Export subscriptions',     group: 'Export data', side: 'both' },
  [CAP.EXPORT_CATALOG]:       { label: 'Export venues & programs', group: 'Export data', side: 'provider' },
}

/** Capabilities offered when editing `role` — filtered by the role's side. */
export function editableCapsForRole(role) {
  const side = ROLE_INFO[role]?.side || 'provider'
  return ALL.filter(c => {
    const s = CAP_INFO[c]?.side || 'both'
    return s === 'both' || s === side
  })
}

// ── Capability dependencies ───────────────────────────────────────────────────
// A cap REQUIRES the caps listed (you can't manage bookings without viewing them,
// can't edit payouts/withdraw without seeing the wallet, can't export what you
// can't access). The editor cascades on these so a contradictory tick is impossible.
export const CAP_DEPS = {
  [CAP.MANAGE_BOOKINGS]:      [CAP.VIEW_BOOKINGS],
  [CAP.ACCEPT_PAYMENTS]:      [CAP.VIEW_BOOKINGS],
  [CAP.MANAGE_SUBSCRIPTIONS]: [CAP.VIEW_SUBSCRIPTIONS],
  [CAP.MANAGE_PAYOUTS]:       [CAP.VIEW_WALLET],
  [CAP.WITHDRAW_FUNDS]:       [CAP.VIEW_WALLET],
  [CAP.EXPORT_BOOKINGS]:      [CAP.VIEW_BOOKINGS],
  [CAP.EXPORT_SUBSCRIPTIONS]: [CAP.VIEW_SUBSCRIPTIONS],
  [CAP.EXPORT_PAYMENTS]:      [CAP.ACCEPT_PAYMENTS],
  [CAP.EXPORT_CUSTOMERS]:     [CAP.MANAGE_CUSTOMERS],
  [CAP.EXPORT_CATALOG]:       [CAP.MANAGE_LISTINGS],
}
// reverse index: cap → caps that depend on it
const CAP_DEPENDENTS = {}
for (const [c, reqs] of Object.entries(CAP_DEPS)) for (const r of reqs) (CAP_DEPENDENTS[r] ||= []).push(c)

/** Transitive prerequisites of `cap` (caps that must also be ON). */
export function prereqsOf(cap, acc = new Set()) {
  for (const p of (CAP_DEPS[cap] || [])) if (!acc.has(p)) { acc.add(p); prereqsOf(p, acc) }
  return acc
}
/** Transitive dependents of `cap` (caps that must turn OFF if `cap` is OFF). */
export function dependentsOf(cap, acc = new Set()) {
  for (const d of (CAP_DEPENDENTS[cap] || [])) if (!acc.has(d)) { acc.add(d); dependentsOf(d, acc) }
  return acc
}

// ── Human-facing role metadata (role dropdown + throughout the UI) ────────────
export const ROLE_INFO = {
  super_admin: {
    label: 'Super Admin', side: 'platform',
    tagline: 'Full platform control',
    color: '#dc2626', bg: '#fef2f2',
    can:    ['Everything across every country, provider and setting'],
    cannot: [],
  },
  country_admin: {
    label: 'Country Admin', side: 'platform',
    tagline: 'Runs their assigned countries',
    color: '#d97706', bg: '#fffbeb',
    can:    ['All platform tools for the providers & venues in their assigned countries'],
    cannot: ['Manage countries they are not assigned to'],
  },
  platform_staff: {
    label: 'Platform Staff', side: 'platform',
    tagline: 'Bookboll operations & platform admin',
    color: '#0d9488', bg: '#f0fdfa',
    can:    ['Countries, loyalty, platform site & blog, promocodes, listings & approvals (in scope)', 'Bookings, customers, subscriptions & payments'],
    cannot: ['Edit payout details, withdraw funds, manage teams or view audit logs'],
  },
  provider_admin: {
    label: 'Owner', side: 'provider',
    tagline: 'Full control of the business',
    color: '#2563eb', bg: '#eff6ff',
    can:    ['Everything — bookings, venues & programs, payments, promotions, website', 'Connect the business’s Stripe account & manage its team'],
    cannot: ['See or touch other businesses'],
  },
  facility_admin: {
    label: 'Manager', side: 'provider',
    tagline: 'Runs the business day-to-day',
    color: '#0891b2', bg: '#ecfeff',
    can:    ['Everything for assigned venues — bookings, customers, payments, venues & programs, promotions', 'View payment setup & moderate events'],
    cannot: ['Edit payout details, withdraw funds, manage the team or the website'],
  },
  provider_supervisor: {
    label: 'Supervisor', side: 'provider',
    tagline: 'Senior operations + catalog',
    color: '#7c3aed', bg: '#f5f3ff',
    can:    ['Manage bookings, subscriptions, customers & accept payments', 'Manage venues & programs and promotions'],
    cannot: ['Wallet, team, website or event moderation'],
  },
  staff: {
    label: 'Staff', side: 'provider',
    tagline: 'Front-desk operations',
    color: '#0284c7', bg: '#f0f9ff',
    can:    ['Manage bookings & subscriptions', 'Accept payments & manage customers'],
    cannot: ['Venues & programs, promotions, wallet, team or website'],
  },
  provider_viewer: {
    label: 'Viewer', side: 'provider',
    tagline: 'Read-only',
    color: '#64748b', bg: '#f8fafc',
    can:    ['View the calendar, bookings & subscriptions'],
    cannot: ['Make any changes'],
  },
  // Legacy display (pre-migration users)
  senior_employee: { label: 'Senior Employee (legacy)', side: 'provider', tagline: 'Operations + payments', color: '#059669', bg: '#ecfdf5', can: ['Bookings, customers & payments'], cannot: ['Catalog, website, payouts or team'] },
  junior_employee: { label: 'Junior Employee (legacy)', side: 'provider', tagline: 'Bookings only', color: '#7c3aed', bg: '#f5f3ff', can: ['Calendar & bookings'], cannot: ['Payments, customers, catalog or export'] },
  guard:           { label: 'Guard (legacy)', side: 'provider', tagline: 'View-only', color: '#64748b', bg: '#f8fafc', can: ['View calendar & bookings'], cannot: ['Create, edit or accept anything'] },
}

export function roleLabel(role) {
  return ROLE_INFO[role]?.label || role
}
