import { periodDates } from './paymentReport.js'

export const periods = [['all','All time'],['today','Today'],['7','Last 7 days'],['30','Last 30 days'],['month','This month'],['last_month','Last month'],['custom','Custom dates']]
export { periodDates }
export const purposes = [{value:'booking',label:'Customer bookings (including staff-added)'},{value:'provider_slot_block',label:'Slot blocks · skipped payment'},{value:'needs_review',label:'Purpose needs review'}]
export const statusLabel = value => ({confirmed:'Confirmed / completed',pending:'Pending',cancelled:'Cancelled',completed:'Completed',no_show:'No show',paid:'Paid',fully_paid:'Paid',deposit_paid:'Deposit paid',skipped:'Skipped',payment_skipped:'Skipped',refunded:'Refunded'})[value] || value || 'Not recorded'
export const activityChannelLabel = value => ({admin:'Added by staff',site:'Provider website',marketplace:'Bookboll marketplace',unknown:'Source not recorded',auto:'Scheduled by system'})[value] || 'Source not recorded'
export function dateError(from,to) {
  return from && to && from > to ? 'End date must be on or after start date.' : ''
}
export function bookingPaymentLabel(booking) {
  // A recorded ledger summary is authoritative. Stored state is shown only when no ledger exists.
  return booking.recorded_payment_summary?.status || statusLabel(booking.payment_status)
}
export function activityCount(value) { return value == null ? '—' : Number(value).toLocaleString() }
