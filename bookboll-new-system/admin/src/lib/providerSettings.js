import { apiFetch } from './api.js'
import { normalizeBusinessPhone } from './businessPhone.js'

// Settings → Business Profile / My Account / Notifications.
//
// These controls were read-only and the save handler reported that updates were
// unavailable, because there was no endpoint behind them. There is now, and the
// rule that follows from how they got here is: never report success from a write
// response alone. The server echoes what it persisted, and these helpers compare
// it to what was sent — an API that accepts a field and drops it must be visible,
// not reported as saved.

export const EMPTY_SETTINGS = {
  business: null,
  account: { name: '', phone: '', email: '', email_editable: false },
  notifications: { preferences: {}, available: [], always_on: [], not_offered: [] },
  can_edit_business: false,
}

/** Validation errors keyed by field, so the dashboard can show them in place. */
function fieldErrors(res) {
  const errors = res?.errors
  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) return {}
  const out = {}
  for (const [field, messages] of Object.entries(errors)) {
    const text = Array.isArray(messages) ? messages.filter(m => typeof m === 'string').join(' ') : messages
    if (typeof text === 'string' && text.trim()) out[field] = text.trim()
  }
  return out
}

function failure(res, fallback) {
  return {
    ok: false,
    fields: fieldErrors(res),
    error: typeof res?.message === 'string' && res.message.trim() ? res.message : fallback,
  }
}

function normalizeSettings(data) {
  const notifications = data?.notifications || {}
  return {
    business: data?.business ? {
      name: String(data.business.name ?? ''),
      phone: String(data.business.phone ?? ''),
      description: String(data.business.description ?? ''),
      brand_name: String(data.business.brand_name ?? ''),
    } : null,
    account: {
      name: String(data?.account?.name ?? ''),
      phone: String(data?.account?.phone ?? ''),
      email: String(data?.account?.email ?? ''),
      // Absent means not editable: the cautious reading, since this field
      // controls where password resets are sent.
      email_editable: data?.account?.email_editable === true,
    },
    notifications: {
      preferences: notifications.preferences && typeof notifications.preferences === 'object' ? { ...notifications.preferences } : {},
      available: Array.isArray(notifications.available) ? notifications.available.filter(row => row && typeof row.key === 'string') : [],
      always_on: Array.isArray(notifications.always_on) ? notifications.always_on : [],
      not_offered: Array.isArray(notifications.not_offered) ? notifications.not_offered : [],
    },
    can_edit_business: data?.can_edit_business === true,
  }
}

export async function loadProviderSettings() {
  const res = await apiFetch('/business/settings')
  // An older API without the endpoint is not a failure to show as an error; the
  // controls stay read-only and say so, exactly as they did before.
  if ([404, 405].includes(res?.status)) return { ok: false, unsupported: true, settings: EMPTY_SETTINGS }
  if (!res?.success) return { ...failure(res, 'Could not load your settings.'), unsupported: false, settings: EMPTY_SETTINGS }
  return { ok: true, unsupported: false, settings: normalizeSettings(res.data || {}) }
}

/** Saved only if the server echoes back the same values. */
export async function saveBusinessProfile(form) {
  const body = {
    name: String(form?.name ?? '').trim(),
    phone: normalizeBusinessPhone(form?.phone),
    description: String(form?.description ?? '').trim(),
  }
  const res = await apiFetch('/business/settings/profile', { method: 'PUT', body })
  if (!res?.success) return failure(res, 'Could not save the business profile.')

  const saved = normalizeSettings({ business: res.data?.business }).business
  if (!saved || ['name', 'phone', 'description'].some(key => saved[key] !== body[key])) {
    return { ok: false, fields: {}, error: 'The business profile could not be confirmed as saved. Reload and check before relying on it.', business: saved }
  }
  return { ok: true, fields: {}, business: saved }
}

export async function saveAccountDetails(form) {
  const body = { name: String(form?.name ?? '').trim(), phone: String(form?.phone ?? '').trim() }
  const res = await apiFetch('/business/settings/account', { method: 'PUT', body })
  if (!res?.success) return failure(res, 'Could not save your account details.')

  const saved = normalizeSettings({ account: res.data?.account }).account
  if (saved.name !== body.name || saved.phone !== body.phone) {
    return { ok: false, fields: {}, error: 'Your account details could not be confirmed as saved. Reload and check before relying on them.', account: saved }
  }
  return { ok: true, fields: {}, account: saved }
}

/**
 * Notification switches.
 *
 * Only the switches the server offers are sent: a key it does not know is
 * refused outright, and sending one would fail the whole save for everything
 * else the person just changed.
 */
export async function saveNotificationPreferences(preferences, available) {
  const offered = new Set((available || []).map(row => row.key))
  const body = Object.fromEntries(Object.entries(preferences || {}).filter(([key]) => offered.has(key)).map(([key, value]) => [key, !!value]))
  if (!Object.keys(body).length) return { ok: false, fields: {}, error: 'There are no notification settings to save.' }

  const res = await apiFetch('/business/settings/notifications', { method: 'PUT', body: { preferences: body } })
  if (!res?.success) return failure(res, 'Could not save your notification preferences.')

  const saved = normalizeSettings({ notifications: res.data?.notifications }).notifications
  if (Object.entries(body).some(([key, value]) => saved.preferences[key] !== value)) {
    return { ok: false, fields: {}, error: 'Your notification preferences could not be confirmed as saved. Reload and check which messages you will receive.', notifications: saved }
  }
  return { ok: true, fields: {}, notifications: saved }
}
