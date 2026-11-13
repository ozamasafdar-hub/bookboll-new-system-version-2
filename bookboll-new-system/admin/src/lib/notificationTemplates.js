/**
 * notificationTemplates.js — automated message templates (Email / SMS / WhatsApp)
 * for booking + membership lifecycle events, per audience (customer / provider team).
 *
 * Storage (Laravel):
 *  - Marketplace DEFAULTS: settings.key = 'notification_templates' (JSON).
 *  - Per-business OVERRIDES: businesses.notification_templates (JSON, null = inherit).
 *  Effective templates for a business = builtin ⟵ platform saved ⟵ business saved (deep merge).
 *
 * Templates use [square_bracket] tokens (see TEMPLATE_VARS); renderPreview() substitutes
 * sample values so admins see a realistic message while editing.
 */
import { apiFetch } from './api.js'

export const CHANNELS = [
  { key: 'email',    label: 'Email' },
  { key: 'sms',      label: 'SMS' },
  { key: 'whatsapp', label: 'WhatsApp' },
]
export const AUDIENCES = [
  { key: 'client',   label: 'To the customer' },
  { key: 'provider', label: 'To you & your team' },
]

export const TEMPLATE_VARS = [
  { group: 'Customer', vars: [
    { code: '[customer_name]',       label: 'Customer full name',   sample: 'Sara Ahmed' },
    { code: '[customer_first_name]', label: 'Customer first name',  sample: 'Sara' },
    { code: '[customer_email]',      label: 'Customer email',       sample: 'sara@example.com' },
    { code: '[customer_phone]',      label: 'Customer phone',       sample: '+971 50 123 4567' },
  ]},
  { group: 'Your business', vars: [
    { code: '[business_name]', label: 'Business name',   sample: 'Dubai Sports City' },
    { code: '[venue]',         label: 'Venue name',      sample: 'Main Courts' },
    { code: '[venue_address]', label: 'Venue address',   sample: 'Sports City Blvd 12, Dubai' },
    { code: '[venue_phone]',   label: 'Venue phone',     sample: '+971 4 555 0100' },
  ]},
  { group: 'Booking', vars: [
    { code: '[listing]',      label: 'Court / space booked', sample: 'Football Turf #12' },
    { code: '[sport]',        label: 'Sport / category',     sample: 'Football' },
    { code: '[date]',         label: 'Booking date',         sample: 'Sat, Jul 11 2026' },
    { code: '[start_time]',   label: 'Start time',           sample: '19:30' },
    { code: '[end_time]',     label: 'End time',             sample: '21:00' },
    { code: '[duration]',     label: 'Duration',             sample: '1h 30m' },
    { code: '[booking_code]', label: 'Booking reference',    sample: '#7AF320' },
    { code: '[players]',      label: 'Number of players',    sample: '10' },
  ]},
  { group: 'Money', vars: [
    { code: '[amount]',         label: 'Amount',              sample: '250.00' },
    { code: '[currency]',       label: 'Currency',            sample: 'AED' },
    { code: '[deposit_amount]', label: 'Deposit paid online', sample: '50.00' },
    { code: '[balance_due]',    label: 'Rest due on arrival', sample: '200.00' },
    { code: '[payment_method]', label: 'Payment method',      sample: 'Online' },
    { code: '[promo_code]',     label: 'Promo code used',     sample: 'SUMMER20' },
    { code: '[discount]',       label: 'Discount amount',     sample: '20.00' },
  ]},
  { group: 'Links', vars: [
    { code: '[booking_link]',    label: 'View booking link',      sample: 'bookboll.com/my-bookings' },
    { code: '[cancel_link]',     label: 'Cancel booking link',    sample: 'bookboll.com/my-bookings?cancel=7AF320' },
    { code: '[website_link]',    label: 'Your website link',      sample: 'dubaisports.bookboll.com' },
    { code: '[review_link]',     label: 'Leave-a-review link',    sample: 'bookboll.com/review/7AF320' },
  ]},
]
const VAR_SAMPLES = Object.fromEntries(TEMPLATE_VARS.flatMap(g => g.vars.map(v => [v.code, v.sample])))

export function renderPreview(text) {
  return String(text || '').replace(/\[[a-z_]+\]/g, (m) => VAR_SAMPLES[m] ?? m)
}

export function smsSegments(text) {
  const len = renderPreview(text).length
  return len === 0 ? 0 : Math.ceil(len / 160)
}

const T = (subject, body, sms = '', whatsapp = '') => ({
  email:    { on: true,  subject, body },
  sms:      { on: false, body: sms },
  whatsapp: { on: false, body: whatsapp },
})

export const NOTIFICATION_EVENTS = [
  {
    key: 'booking_confirmation', label: 'Booking confirmation', icon: 'CalendarCheck',
    desc: 'Sent right after a booking is placed (online or by your front desk).',
    audiences: ['client', 'provider'],
  },
  {
    key: 'booking_reminder', label: 'Booking reminder', icon: 'Clock',
    desc: 'Reminds the customer before their session. Pick how long before below.',
    audiences: ['client', 'provider'], remind: true,
  },
  {
    key: 'booking_changed', label: 'Booking change', icon: 'CalendarClock',
    desc: 'Sent when the date, time or court of a booking is edited.',
    audiences: ['client', 'provider'],
  },
  {
    key: 'booking_cancelled', label: 'Booking cancellation', icon: 'CalendarX',
    desc: 'Sent when a booking is cancelled by either side.',
    audiences: ['client', 'provider'],
  },
  {
    key: 'payment_receipt', label: 'Payment receipt', icon: 'Receipt',
    desc: 'Sent when a payment is collected (online or marked paid at the venue).',
    audiences: ['client', 'provider'],
  },
  {
    key: 'review_request', label: 'Review request', icon: 'Star',
    desc: 'Asks the customer for feedback after their visit.',
    audiences: ['client'],
  },
]

const BUILTIN_TEMPLATES = {
  booking_confirmation: {
    client: T(
      'Your booking at [business_name] is confirmed — [booking_code]',
      'Hi [customer_first_name],\n\nYour booking is confirmed!\n\n[listing] at [venue]\n[date], [start_time] – [end_time]\nReference: [booking_code]\n\nPaid online: [currency] [deposit_amount] · Due on arrival: [currency] [balance_due]\n\nView or manage your booking: [booking_link]\nNeed to cancel? [cancel_link]\n\nSee you on the court!\n[business_name]',
      'Booking confirmed: [listing] at [venue], [date] [start_time]-[end_time]. Ref [booking_code]. Due on arrival: [currency] [balance_due]. Manage: [booking_link]',
      '✅ Booking confirmed, [customer_first_name]!\n[listing] at [venue]\n[date], [start_time]–[end_time] · Ref [booking_code]\nDue on arrival: [currency] [balance_due] · Manage: [booking_link]'
    ),
    provider: T(
      'New booking — [listing], [date] [start_time] ([booking_code])',
      'New booking received.\n\nCustomer: [customer_name] ([customer_phone])\n[listing] at [venue]\n[date], [start_time] – [end_time] · [players] players\nReference: [booking_code]\n\nPaid online: [currency] [deposit_amount] · Collect on arrival: [currency] [balance_due]',
      'New booking: [listing], [date] [start_time]-[end_time]. [customer_name] ([customer_phone]). Ref [booking_code]. Collect on arrival: [currency] [balance_due].',
      '📅 New booking\n[customer_name] ([customer_phone])\n[listing] at [venue] — [date], [start_time]–[end_time] · [players] players\nRef [booking_code] · Collect on arrival: [currency] [balance_due]'
    ),
  },
  booking_reminder: {
    client: T(
      'Reminder: [listing] on [date] at [start_time]',
      'Hi [customer_first_name],\n\nA quick reminder about your upcoming session:\n\n[listing] at [venue]\n[date], [start_time] – [end_time]\nReference: [booking_code]\n\nAddress: [venue_address]\nRunning late or need to cancel? [cancel_link]\n\n[business_name]',
      'Reminder: [listing] at [venue] starts [date] [start_time]. Ref [booking_code]. Need to cancel? [cancel_link]',
      '⏰ Starts soon: [listing] at [venue]\n[date], [start_time]–[end_time] · Ref [booking_code]\nFind us: [venue_address]\nRunning late or need to cancel? [cancel_link]'
    ),
    provider: T(
      'Upcoming: [listing] [date] [start_time] — [customer_name]',
      'Upcoming booking reminder.\n\n[customer_name] ([customer_phone])\n[listing] at [venue]\n[date], [start_time] – [end_time]\nBalance to collect: [currency] [balance_due]',
      'Upcoming: [listing], [date] [start_time]-[end_time]. [customer_name] ([customer_phone]). Collect: [currency] [balance_due].',
      '⏰ Upcoming booking\n[customer_name] ([customer_phone])\n[listing] — [date], [start_time]–[end_time]\nBalance to collect: [currency] [balance_due]'
    ),
  },
  booking_changed: {
    client: T(
      'Your booking was updated — [booking_code]',
      'Hi [customer_first_name],\n\nYour booking details changed. Here are the new details:\n\n[listing] at [venue]\n[date], [start_time] – [end_time]\nReference: [booking_code]\n\nIf this doesn’t work for you: [cancel_link]\n\n[business_name]',
      'Booking [booking_code] updated: now [listing] at [venue], [date] [start_time]-[end_time]. Doesn\'t work? [cancel_link]',
      '🔄 Your booking was updated\nNow: [listing] at [venue]\n[date], [start_time]–[end_time] · Ref [booking_code]\nDoesn\'t work for you? [cancel_link]'
    ),
    provider: T(
      'Booking updated — [booking_code]',
      'Booking [booking_code] was updated.\n\nCustomer: [customer_name]\nNow: [listing], [date], [start_time] – [end_time]',
      'Booking [booking_code] updated: [customer_name], now [listing], [date] [start_time]-[end_time].',
      '🔄 Booking updated — [booking_code]\n[customer_name]\nNow: [listing], [date], [start_time]–[end_time]'
    ),
  },
  booking_cancelled: {
    client: T(
      'Your booking was cancelled — [booking_code]',
      'Hi [customer_first_name],\n\nYour booking [booking_code] ([listing] on [date] at [start_time]) has been cancelled.\n\nBook again anytime: [website_link]\n\n[business_name]',
      'Your booking [booking_code] ([listing], [date] [start_time]) is cancelled. Book again anytime: [website_link]',
      'Your booking was cancelled — [booking_code]\n[listing] on [date] at [start_time]\nBook again anytime: [website_link]\n[business_name]'
    ),
    provider: T(
      'Cancelled: [listing] [date] [start_time] — [customer_name]',
      'Booking [booking_code] was cancelled.\n\nCustomer: [customer_name]\nWas: [listing], [date], [start_time] – [end_time]\nThe slot is now free for new bookings.',
      'Cancelled: [listing], [date] [start_time]-[end_time] ([customer_name], ref [booking_code]). The slot is free again.',
      'Booking cancelled — [booking_code]\n[customer_name]\nWas: [listing], [date], [start_time]–[end_time]\nThe slot is now free for new bookings.'
    ),
  },
  payment_receipt: {
    client: T(
      'Receipt: [currency] [amount] — [business_name]',
      'Hi [customer_first_name],\n\nThanks! We received your payment.\n\nAmount: [currency] [amount]\nMethod: [payment_method]\nReference: [booking_code]\nDate: [date]\n\n[business_name]',
      'Payment received: [currency] [amount] ([payment_method]). Ref [booking_code]. Thank you! [business_name]',
      '🧾 Payment received — thank you!\n[currency] [amount] · [payment_method]\nRef [booking_code] · [date]\n[business_name]'
    ),
    provider: T(
      'Payment received: [currency] [amount] ([booking_code])',
      'Payment collected.\n\n[currency] [amount] · [payment_method]\nCustomer: [customer_name]\nReference: [booking_code]',
      'Payment collected: [currency] [amount] ([payment_method]) from [customer_name]. Ref [booking_code].',
      '💰 Payment collected\n[currency] [amount] · [payment_method]\n[customer_name] · Ref [booking_code]'
    ),
  },
  review_request: {
    client: T(
      'How was [listing] at [business_name]?',
      'Hi [customer_first_name],\n\nThanks for playing at [venue]! How did it go?\n\nLeave a quick review — it takes 30 seconds and helps other players:\n[review_link]\n\n[business_name]',
      'How was [listing] at [venue]? Leave a quick review (30 seconds): [review_link] [business_name]',
      '⭐ How was [listing] at [venue], [customer_first_name]?\nA quick review takes 30 seconds and helps other players:\n[review_link]\n[business_name]'
    ),
  },
}

for (const k of ['booking_reminder', 'booking_changed']) {
  if (BUILTIN_TEMPLATES[k]?.provider) BUILTIN_TEMPLATES[k].provider.email.on = false
}

export function builtinSettings() {
  const events = {}
  for (const ev of NOTIFICATION_EVENTS) {
    events[ev.key] = {}
    for (const aud of ev.audiences) {
      const t = BUILTIN_TEMPLATES[ev.key]?.[aud] || T('', '')
      events[ev.key][aud] = JSON.parse(JSON.stringify(t))
    }
  }
  return {
    remind_before_days: 1,
    events,
  }
}

function deepMerge(base, patch) {
  if (patch === null || patch === undefined) return base
  if (typeof base !== 'object' || base === null || Array.isArray(base)) return patch
  const out = { ...base }
  for (const [k, v] of Object.entries(patch)) {
    out[k] = (typeof v === 'object' && v !== null && !Array.isArray(v)) ? deepMerge(base[k], v) : v
  }
  return out
}

// Never revive obsolete auto-billing templates from saved platform/business settings.
export function currentNotificationSettings(settings) {
  const allowed = new Set(NOTIFICATION_EVENTS.map(event => event.key))
  return { remind_before_days: settings.remind_before_days ?? 1,
    events: Object.fromEntries(Object.entries(settings.events || {}).filter(([key]) => allowed.has(key))) }
}

/** Marketplace defaults (Laravel settings), builtin-merged. */
export async function loadPlatformNotificationSettings() {
  const res = await apiFetch('/admin/notification-templates')
  if (!res.success) return builtinSettings()
  return currentNotificationSettings(deepMerge(builtinSettings(), res.data?.templates || null))
}

/**
 * Effective settings for a business: builtin ⟵ platform ⟵ business row.
 * ownerId kept for call-site compatibility (resolved server-side from auth).
 */
export async function loadProviderNotificationSettings(_ownerId) {
  const res = await apiFetch('/business/notification-templates')
  if (!res.success) {
    const platform = await loadPlatformNotificationSettings()
    return { settings: platform, providerSaved: null, error: res.message }
  }
  const platformMerged = deepMerge(builtinSettings(), res.data?.platform || null)
  const saved = res.data?.templates || null
  return { settings: currentNotificationSettings(deepMerge(platformMerged, saved)), providerSaved: saved }
}

/** Save marketplace defaults (super admin / platform staff). */
export async function savePlatformNotificationSettings(settings) {
  const res = await apiFetch('/admin/notification-templates', {
    method: 'PUT',
    body: { templates: settings },
  })
  return { error: res.success ? null : { message: res.message || 'Could not save.' } }
}

/** Save a business's templates (auth user's business). */
export async function saveProviderNotificationSettings(_ownerId, settings) {
  const res = await apiFetch('/business/notification-templates', {
    method: 'PUT',
    body: { templates: settings },
  })
  return { error: res.success ? null : { message: res.message || 'Could not save.' } }
}
