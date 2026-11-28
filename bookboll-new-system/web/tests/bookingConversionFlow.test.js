import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const verified = { value: 15, currency: 'QAR', event_id: `bb_purchase_${'a'.repeat(64)}`, num_items: 2 }
const cleanImports = source => source.replace(/^import .*$/gm, '').replace(/^export \{.*$/gm, '').replace(/export /g, '')

async function thankYou(query, responses) {
  let mount, calls = [], loaded
  const script = readFileSync(new URL('../src/customer/pages/ThankYou.vue', import.meta.url), 'utf8').match(/<script setup>([\s\S]*?)<\/script>/)[1]
  const context = vm.createContext({
    ref: value => ({ value }), computed: getter => ({ get value() { return getter() } }),
    onMounted: fn => { mount = fn }, useRoute: () => ({ query }), useRouter: () => ({}), useSeo: () => {},
    selectedCountry: { value: { currency_code: 'PKR' } }, isWhiteLabel: { value: false },
    endCheckoutSession: async () => {},
    loadBookingsByIds: async ids => { loaded = ids; return [] },
    apiFetch: async path => { calls.push(['verify', path]); return responses.shift() || { success: false } },
    trackBookingConversion: async (event, data) => { calls.push([event, data]) },
    setTimeout: fn => { fn() },
  })
  vm.runInContext(cleanImports(script), context)
  await mount()
  return { calls, loaded }
}

test('the thank-you page waits for verification, then sends the receipt once and uses server booking ids', async () => {
  const result = await thankYou({ checkout_session: 'cs_test_paid', booking_ids: '999', value: '100000', currency: 'USD' }, [
    { success: true, data: { status: 'processing' } },
    { success: true, data: { status: 'completed', booking_ids: [1, 2], conversion: verified } },
  ])
  assert.deepEqual(result.calls.map(c => c[0]), ['verify', 'verify', 'Purchase'])
  assert.equal(result.calls[2][1], verified)
  assert.deepEqual(result.loaded, [1, 2])
})

test('forged return URLs, failed verification and cancelled payments never send a Purchase', async () => {
  for (const [query, response] of [
    [{ booking_ids: '1,2', value: '15' }, []],
    [{ checkout_session: '../fake' }, []],
    [{ checkout_session: 'cs_test_other_user' }, [{ success: false, status: 404 }]],
    [{ checkout_session: 'cs_test_failed' }, [{ success: true, data: { status: 'cancelled' } }]],
    [{ checkout_session: 'cs_test_waiting' }, Array(4).fill({ success: true, data: { status: 'processing' } })],
  ]) {
    const result = await thankYou(query, response)
    assert.equal(result.calls.some(c => c[0] === 'Purchase'), false)
    assert.ok(result.calls.length <= 4)
  }
})

async function checkout(response, method = 'deposit') {
  const calls = []
  const window = { location: {} }
  const source = readFileSync(new URL('../src/customer/apiBooking.js', import.meta.url), 'utf8')
  const context = vm.createContext({
    window, setTimeout: fn => fn(),
    apiFetch: async () => response,
    trackBookingConversion: async (event, data) => { calls.push([event, data]); return true },
    createdBookingCurrency: () => 'QAR',
  })
  vm.runInContext(cleanImports(source) + `
    ensureLaravelCustomer = async () => ({})
    bookingPayload = ({payment}) => ({payment_method: PAY_MAP[payment.method]})
    releaseHolds = async () => {}
    this.start = createCustomerBookingsMulti
  `, context)
  const result = await context.start({ payment: { method }, location: {}, listing: {} })
  return { calls, result, href: window.location.href }
}

test('deposit and full-online checkout pass the backend amount before Stripe navigation', async () => {
  for (const method of ['deposit', 'online']) {
    const conversion = { ...verified, value: method === 'deposit' ? 15 : 100 }
    const result = await checkout({ success: true, data: { url: 'https://checkout.stripe.com/test', checkout_conversion: conversion } }, method)
    assert.deepEqual(result.calls, [['InitiateCheckout', conversion]])
    assert.equal(result.href, 'https://checkout.stripe.com/test')
    assert.equal(result.result.redirect, true)
  }
})

test('invalid, free and cash bookings do not emit online checkout events', async () => {
  for (const [response, method] of [
    [{ success: false, message: 'Slot is unavailable' }, 'deposit'],
    [{ success: true, data: { free_booking: true, bookings: [{ id: 1 }] } }, 'deposit'],
    [{ success: true, data: { bookings: [{ id: 1 }] } }, 'cash'],
  ]) {
    const result = await checkout(response, method)
    assert.deepEqual(result.calls, [])
    assert.equal(result.href, undefined)
  }
})
