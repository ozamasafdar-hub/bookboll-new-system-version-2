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

function slotSelection(hold = async () => ({ ok: true })) {
  const calls = [], state = { selection: [] }
  const context = vm.createContext({
    ref: value => ({ value }), listingLoad: 1, date: { value: '2026-12-01' },
    l: { value: { id: 2 } }, loc: { value: { currency: 'QAR' } }, serverCurrency: { value: 'USD' },
    selectMode: { value: false }, bookingUnavailable: { value: false }, err: { value: '' }, holdExpiredMsg: { value: false },
    selected: { get value() { return state.selection } },
    fullQuote: { get value() { return { total: state.selection.reduce((n, s, i) => n + s.price * (i ? .8 : 1), 0) } } },
    setDay: (_day, slots) => { state.selection = slots }, syncHolds: hold, loadDay: () => {},
    trackStorefrontEvent: (event, item) => calls.push([event, JSON.parse(JSON.stringify(item))]),
  })
  const source = readFileSync(new URL('../src/customer/pages/ListingDetail.vue', import.meta.url), 'utf8')
  vm.runInContext(source.slice(source.indexOf('const selectionPending ='), source.indexOf('function clearDay(')), context)
  return { context, calls, state, slot: { available: true, start_time: '18:00', end_time: '19:00', price: 100 } }
}

test('slot holds report successful incremental prices, including discounted extensions; deselection and prefills do not', async () => {
  const env = slotSelection()
  await env.context.toggleSlot(env.slot)
  await env.context.addExtension({ slot: { start_time: '19:00', end_time: '20:00', price: 100 } })
  assert.deepEqual(env.calls.map(c => c[1].value), [100, 80])
  assert.ok(env.calls.every(c => c[1].id === 2 && c[1].currency === 'QAR'))
  await env.context.toggleSlot(env.slot)
  await env.context.toggleSlot(env.slot, false)
  assert.equal(env.calls.length, 2)
})

test('failed holds show the server reason and never report a cart event', async () => {
  const env = slotSelection(async () => ({ ok: false, reason: 'Sign in to save a hold.' }))
  await env.context.toggleSlot(env.slot)
  assert.equal(env.calls.length, 0)
  assert.equal(env.state.selection.length, 0)
  assert.equal(env.context.err.value, 'Sign in to save a hold.')
})

test('rapid clicks and stale holds after changing listings cannot duplicate or misattribute cart events', async () => {
  let finish
  const env = slotSelection(() => new Promise(resolve => { finish = resolve }))
  const pending = env.context.toggleSlot(env.slot)
  await env.context.toggleSlot(env.slot)
  assert.equal(env.state.selection.length, 1)
  env.context.listingLoad++
  env.context.l.value = { id: 9 }
  env.context.loc.value = { currency: 'PKR' }
  finish({ ok: true }); await pending
  assert.deepEqual(env.calls, [])
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
