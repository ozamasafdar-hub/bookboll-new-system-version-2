import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// Exercise the template model without a browser/network transport.
const source = readFileSync(new URL('../src/lib/notificationTemplates.js', import.meta.url), 'utf8')
  .replace("import { apiFetch } from './api.js'", 'const apiFetch = async () => ({success:false})')
const templates = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
test('saved subscription automation cannot reappear in the manual membership model', () => {
  const settings = templates.currentNotificationSettings({ ...templates.builtinSettings(), events: {
    booking_confirmation:{client:{email:{on:true,body:'Keep my custom booking message'}}},
    membership_payment:{client:{email:{on:true,body:'Charge card automatically'}}},
    trial_ending:{client:{email:{on:true}}},
    membership_winback:{client:{email:{on:true}}},
  }})
  assert.deepEqual(Object.keys(settings.events), ['booking_confirmation'])
  assert.equal(settings.events.booking_confirmation.client.email.body,'Keep my custom booking message')
  assert.ok(templates.NOTIFICATION_EVENTS.every(e => !e.key.startsWith('membership_') && e.key !== 'trial_ending'))
})
