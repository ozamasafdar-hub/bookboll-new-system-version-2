import { test } from 'node:test'
import assert from 'node:assert/strict'
import { bookingChannel, channelLabel, bookingCreatorLabel } from '../src/lib/bookingMeta.js'

test('recorded staff creation overrides the legacy web label and customer identity', () => {
  const b={origin:'bookboll_web',created_by:7,creation_context:{channel:'admin',actor_name:'Front Desk',actor_kind:'staff'}}
  assert.equal(bookingChannel(b),'manual')
  assert.equal(channelLabel(b),'Admin created (manual)')
  assert.equal(bookingCreatorLabel(b),'Front Desk')
})
test('customer receipts collected by staff do not become manual bookings', () => {
  assert.equal(bookingChannel({origin:'bookboll_web',payment_method:'paid_cash',creation_context:{channel:'marketplace',actor_kind:'customer'}}),'marketplace')
})
test('missing creator and channel are displayed as unknown rather than inferred', () => {
  assert.equal(bookingChannel({}),'unknown')
  assert.equal(bookingCreatorLabel({customer_name:'Customer'}),'Creator not recorded')
  assert.equal(bookingChannel({origin:'bookboll_web',creation_context:{channel:'unknown'}}),'unknown')
})
