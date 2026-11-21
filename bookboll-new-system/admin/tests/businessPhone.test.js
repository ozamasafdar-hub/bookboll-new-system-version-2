import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { normalizeBusinessPhone, businessPhoneError, businessContactLinks } from '../src/lib/businessPhone.js'

test('normalizes international display numbers without altering the recipient', () => {
  for (const input of ['+974 5550 0001', '00974 (5550) 0001', '+974-5550-0001']) {
    assert.equal(normalizeBusinessPhone(input), '+97455500001')
    assert.deepEqual(businessContactLinks(input), { phone: '+97455500001', call: 'tel:+97455500001', whatsapp: 'https://wa.me/97455500001' })
  }
  assert.equal(normalizeBusinessPhone('+39 06 1234 5678'), '+390612345678')
})
test('missing or malformed numbers never become contact links', () => {
  for (const input of ['', null, '55500001', '+974', '+974abc55500001', 'javascript:alert(1)', '+1234567890123456']) {
    assert.ok(businessPhoneError(input))
    assert.equal(businessContactLinks(input), null)
  }
  assert.equal(businessPhoneError('', false), '')
})
test('standalone signup and admin apps ship identical contact rules and inputs', () => {
  for (const path of ['lib/businessPhone.js', 'components/BusinessPhoneInput.vue']) {
    assert.equal(readFileSync(new URL('../src/'+path,import.meta.url),'utf8'), readFileSync(new URL('../../business/src/'+path,import.meta.url),'utf8'))
  }
})
