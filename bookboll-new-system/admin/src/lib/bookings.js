/**
 * Bookings — Laravel admin API for the enhanced apps/admin Bookings page.
 * Payload shape matches the existing Bookings UI (location_id, booking_date, origin).
 */
import { apiFetch } from './api.js'

/**
 * The bookings table. Filtering, sorting and paging all happen on the SERVER —
 * the browser used to pull every booking plus every lookup list (~2 MB, most of it
 * the 15k customer list) and do the work itself, which stops scaling the moment
 * bookings run to thousands.
 *
 * `params` mirrors the page's filter state one-for-one; empty values are dropped so
 * the query string stays readable.
 */
export async function fetchBookingsDashboard(params = {}) {
  const query = {}
  for (const [k, v] of Object.entries(params)) {
    if (v === '' || v === null || v === undefined) continue
    if (Array.isArray(v)) { if (v.length) query[k] = v.join(',') ; continue }
    query[k] = v
  }
  return apiFetch('/admin/bookings/dashboard', { query })
}

/** Picker lists only (new-booking drawer, detail modal) — no bookings page. */
export async function fetchBookingLookups({ withCustomers = false } = {}) {
  return apiFetch('/admin/bookings/dashboard', {
    query: { lookups_only: 1, ...(withCustomers ? { with_customers: 1 } : {}) },
  })
}

/** Every row matching the current filters, for CSV export (not just this page). */
export async function fetchBookingsForExport(params = {}) {
  const rows = [], seen = new Set()
  let after = 0, through
  do {
    const res = await fetchBookingsDashboard({ ...params, with_activity:undefined, export:1, after_id:after, through_id:through, page:undefined, per_page:undefined })
    if (!res.success) return res
    through ??= res.data?.through_id
    for (const row of res.data?.bookings || []) if (!seen.has(row.id)) { seen.add(row.id); rows.push(row) }
    const next = res.data?.next_after_id
    if (next && Number(next) <= after) return {success:false,message:'Export could not advance. Try again.'}
    after = Number(next) || 0
  } while (after)
  return {success:true,data:{bookings:rows,truncated:false}}
}

export async function fetchBookingCrm(id) {
  if (!id) return { success: false, message: 'Missing booking', data: null }
  return apiFetch(`/admin/bookings/${id}/crm`)
}

export async function fetchBookingOccupancy(listingId, date) {
  return apiFetch('/admin/bookings/occupancy', {
    query: { listing_id: listingId, date },
  })
}

export async function createDashboardBooking(body) {
  return apiFetch('/admin/bookings/dashboard', { method: 'POST', body })
}

export async function updateDashboardBooking(id, body) {
  return apiFetch(`/admin/bookings/${id}/dashboard`, { method: 'PUT', body })
}

export async function cancelDashboardBooking(body) {
  return apiFetch('/admin/bookings/dashboard-cancel', { method: 'POST', body })
}

export async function collectDashboardPayment(bookingId, paymentId, body) {
  return apiFetch(`/admin/bookings/${bookingId}/payments/${paymentId}/collect`, {
    method: 'PUT',
    body,
  })
}

export async function generateDashboardPaymentLink(body) {
  return apiFetch('/admin/bookings/dashboard-payment-link', { method: 'POST', body })
}

export async function registerCustomer(body) {
  return apiFetch('/admin/customers/register', { method: 'POST', body })
}

export function firstError(res, fallback = 'Could not save booking.') {
  if (!res) return fallback
  const errors = res.errors || {}
  const first = Object.values(errors).flat().find(Boolean)
  if (first) return String(first)
  return res.message || fallback
}
