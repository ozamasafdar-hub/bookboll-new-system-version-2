const PAY_STATUS = { fully_paid: 'paid', deposit_paid: 'partial', payment_skipped: 'skipped', cancelled: 'cancelled' }
const terminalStatuses = new Set(['cancelled', 'canceled', 'completed', 'refunded'])

export function mapCustomerBooking(row) {
  const rawStatus = row.status || 'pending'
  const deposits = (row.payments || []).filter(p => p.type === 'deposit')
  const depositPaid = deposits.length
    ? deposits.some(p => p.status === 'paid')
    : row.payment_status === 'deposit_paid' && !row.refunded_at && !Number(row.refunded_amount)
  const booking_status = terminalStatuses.has(rawStatus) ? rawStatus
    : row.customer_status || (rawStatus === 'pending' && depositPaid ? 'confirmed' : rawStatus)
  const due = row.due_amount != null && Number.isFinite(Number(row.due_amount))
    ? Math.max(0, Number(row.due_amount)) : null
  let payment_status = PAY_STATUS[row.payment_status] || row.payment_status || 'pending'
  if (depositPaid && !terminalStatuses.has(rawStatus)) payment_status = due === 0 ? 'paid' : 'partial'
  else if (deposits.length && deposits.every(p => p.status === 'refunded')) payment_status = 'refunded'
  // An unpaid/failed deposit row must not be labelled paid because of an old flag.
  else if (payment_status === 'partial' && !depositPaid) payment_status = 'pending'

  return {
    id: row.id,
    bookingRef: row.booking_id || String(row.id),
    listingName: row.listing?.name || 'Court',
    locationName: row.venue?.name || '',
    currency: row.currency || '',
    booking_date: String(row.date || '').slice(0, 10),
    start_time: row.start_time,
    end_time: row.end_time,
    total: Number(row.total_price) || 0,
    booking_status,
    payment_status,
    due,
    statusLabel: booking_status,
    paymentMethod: row.payment_method || '',
    canCancel: booking_status === 'pending' && !depositPaid && payment_status === 'pending',
  }
}
