import test from 'node:test'
import { normalizeBusinessPhone } from '../src/lib/businessPhone.js'
import assert from 'node:assert/strict'

/* Settings → Business Profile / My Account / Notifications were switched off
 * because an earlier pass found the save handler reporting that updates were
 * unavailable and the notification switches changing local state only. The
 * rule that follows: never report success from a write response alone. The
 * server echoes what it persisted and these helpers compare it, so an API that
 * accepts a field and drops it is visible rather than reported as saved. */

import { readFileSync } from 'node:fs'
import { parse as parseJs } from '@babel/parser'

/** Load the adapter with apiFetch replaced, so no network or env is needed. */
function withApi(apiFetch) {
  let code = readFileSync(new URL('../src/lib/providerSettings.js', import.meta.url), 'utf8')
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.end)
    } else if (node.type === 'ExportNamedDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.start + 'export '.length, node.end) + code.slice(node.end)
    }
  }
  return new Function('apiFetch', 'normalizeBusinessPhone', `${code}; return { EMPTY_SETTINGS, loadProviderSettings, saveBusinessProfile, saveAccountDetails, saveNotificationPreferences }`)(apiFetch, normalizeBusinessPhone)
}

test('a write response alone cannot confirm a saved business profile', async () => {
  const sent = []
  // An API that accepts the request and drops the description.
  const { saveBusinessProfile } = withApi(async (path, opts) => {
    sent.push({ path, body: opts.body })
    return { success: true, status: 200, data: { business: { name: opts.body.name, phone: opts.body.phone, description: '' } } }
  })

  const result = await saveBusinessProfile({ name: 'North Club', phone: '+971 50 000 0000', description: 'Six padel courts.' })
  assert.equal(result.ok, false)
  assert.match(result.error, /could not be confirmed/)
  assert.equal(sent[0].path, '/business/settings/profile')
})

test('a profile that comes back exactly as sent is a success', async () => {
  const { saveBusinessProfile } = withApi(async (_path, opts) => ({
    success: true, status: 200, data: { business: { ...opts.body, brand_name: 'North Courts Online' } },
  }))

  const result = await saveBusinessProfile({ name: ' North Club ', phone: '', description: 'Courts.' })
  assert.equal(result.ok, true)
  assert.equal(result.business.name, 'North Club') // trimmed before sending
  assert.equal(result.business.brand_name, 'North Courts Online')
})

test('server validation is shown beside the field, not as one vague failure', async () => {
  const { saveBusinessProfile } = withApi(async () => ({
    success: false, status: 422, message: 'The given data was invalid.',
    errors: { name: ['The name field is required.'], phone: ['Too long.'] },
  }))

  const result = await saveBusinessProfile({ name: '' })
  assert.equal(result.ok, false)
  assert.deepEqual(result.fields, { name: 'The name field is required.', phone: 'Too long.' })
  assert.equal(result.error, 'The given data was invalid.')
})

test('a refused email change is reported against the email field', async () => {
  const { saveAccountDetails } = withApi(async () => ({
    success: false, status: 422, message: 'Email changes need to be verified by support.',
    errors: { email: ['Contact support to change the address on your account.'] },
  }))

  const result = await saveAccountDetails({ name: 'Alamgir Khan' })
  assert.equal(result.ok, false)
  assert.match(result.fields.email, /Contact support/)
})

test('account details are confirmed against what came back', async () => {
  const { saveAccountDetails } = withApi(async (_path, opts) => ({
    success: true, status: 200, data: { account: { name: opts.body.name, phone: '', email: 'me@example.com', email_editable: false } },
  }))

  const dropped = await saveAccountDetails({ name: 'Alamgir Khan', phone: '+971 55 111 2222' })
  assert.equal(dropped.ok, false)
  assert.match(dropped.error, /could not be confirmed/)

  const kept = await saveAccountDetails({ name: 'Alamgir Khan', phone: '' })
  assert.equal(kept.ok, true)
  assert.equal(kept.account.email_editable, false)
})

test('only switches the server offers are sent, so one stray key cannot fail the save', async () => {
  let body = null
  const { saveNotificationPreferences } = withApi(async (_path, opts) => {
    body = opts.body
    return { success: true, status: 200, data: { notifications: { preferences: opts.body.preferences, available: [] } } }
  })

  const available = [{ key: 'booking_new' }, { key: 'payment_received' }]
  const result = await saveNotificationPreferences({ booking_new: false, payment_received: true, wallet_low: false }, available)

  assert.deepEqual(body.preferences, { booking_new: false, payment_received: true })
  assert.equal(result.ok, true)
})

test('a notification switch the server did not persist is not reported as saved', async () => {
  const { saveNotificationPreferences } = withApi(async () => ({
    success: true, status: 200, data: { notifications: { preferences: { booking_new: true }, available: [] } },
  }))

  const result = await saveNotificationPreferences({ booking_new: false }, [{ key: 'booking_new' }])
  assert.equal(result.ok, false)
  assert.match(result.error, /could not be confirmed/)
})

test('an older server without the endpoints keeps the read-only behaviour', async () => {
  for (const status of [404, 405]) {
    const { loadProviderSettings } = withApi(async () => ({ success: false, status }))
    const result = await loadProviderSettings()
    assert.equal(result.ok, false)
    assert.equal(result.unsupported, true)
    assert.equal(result.settings.can_edit_business, false)
  }

  // A real failure is not the same thing and must not silence the controls.
  const { loadProviderSettings } = withApi(async () => ({ success: false, status: 500, message: 'Server error' }))
  const failed = await loadProviderSettings()
  assert.equal(failed.unsupported, false)
  assert.equal(failed.error, 'Server error')
})

test('settings are read with explicit shapes, and a missing email flag means not editable', async () => {
  const { loadProviderSettings } = withApi(async () => ({
    success: true, status: 200,
    data: {
      business: { name: 'North Club', phone: null, description: null, brand_name: 'Online' },
      account: { name: 'Owner', phone: '+971', email: 'me@example.com' },
      notifications: {
        preferences: { booking_new: true },
        available: [{ key: 'booking_new', label: 'New booking received' }, { label: 'no key' }],
        always_on: [{ kind: 'approval', reason: 'Decisions about your own listings.' }],
        not_offered: [{ key: 'wallet_low', reason: 'No low-balance alert exists.' }],
      },
      can_edit_business: true,
    },
  }))

  const { settings } = await loadProviderSettings()
  assert.equal(settings.business.phone, '')
  assert.equal(settings.account.email_editable, false)
  assert.deepEqual(settings.notifications.available.map(r => r.key), ['booking_new'])
  assert.equal(settings.notifications.always_on.length, 1)
  assert.equal(settings.notifications.not_offered[0].key, 'wallet_low')
  assert.equal(settings.can_edit_business, true)
})

test('a business profile the server declines to let staff edit is a plain refusal', async () => {
  const { saveBusinessProfile } = withApi(async () => ({
    success: false, status: 403, message: 'Only the business owner can change the business profile.',
  }))

  const result = await saveBusinessProfile({ name: 'Taken Over' })
  assert.equal(result.ok, false)
  assert.match(result.error, /Only the business owner/)
  assert.deepEqual(result.fields, {})
})
