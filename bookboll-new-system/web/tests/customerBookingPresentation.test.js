import test from 'node:test'
import assert from 'node:assert/strict'
import { mapCustomerBooking } from '../src/lib/customerBookingPresentation.js'

const booking = { id:1, status:'pending', payment_status:'deposit_paid', total_price:125, due_amount:100, currency:'QAR' }
test('a paid deposit confirms the reservation while keeping its arrival balance', () => {
  const b = mapCustomerBooking(booking)
  assert.equal(b.booking_status, 'confirmed')
  assert.equal(b.statusLabel, 'confirmed')
  assert.equal(b.payment_status, 'partial')
  assert.equal(b.due, 100)
  assert.equal(b.canCancel, false)
})
test('paid receipt evidence fixes a stale booking payment flag', () => {
  const b = mapCustomerBooking({...booking, payment_status:'pending', payments:[{type:'deposit',status:'paid'}]})
  assert.equal(b.booking_status, 'confirmed')
  assert.equal(b.payment_status, 'partial')
})
test('a pending deposit remains pending', () => {
  const b = mapCustomerBooking({...booking, payments:[{type:'deposit',status:'pending'}]})
  assert.equal(b.booking_status,'pending')
  assert.equal(b.payment_status,'pending')
})
test('cancelled and completed reservations retain their status', () => {
  for (const status of ['cancelled','canceled','completed','refunded']) {
    const b = mapCustomerBooking({...booking, status, customer_status:'confirmed'})
    assert.equal(b.booking_status,status)
    assert.equal(b.canCancel,false)
  }
})
test('refunded deposit evidence overrides an old paid flag', () => {
  const b = mapCustomerBooking({...booking, payments:[{type:'deposit',status:'refunded'}]})
  assert.equal(b.booking_status,'pending')
  assert.equal(b.payment_status,'refunded')
  assert.equal(b.canCancel,false)
})
test('once the balance is collected the booking reads paid in full', () => {
  const b = mapCustomerBooking({...booking, status:'confirmed', payment_status:'fully_paid', due_amount:0, payments:[{type:'deposit',status:'paid'}, {type:'remaining',status:'paid'}]})
  assert.equal(b.payment_status,'paid')
  assert.equal(b.due,0)
})
test('missing balances are not invented or replaced with the full booking price', () => {
  assert.equal(mapCustomerBooking({...booking,due_amount:undefined}).due,null)
  assert.equal(mapCustomerBooking({...booking,due_amount:-1}).due,0)
})
