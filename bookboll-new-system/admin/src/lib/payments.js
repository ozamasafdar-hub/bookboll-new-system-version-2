/**
 * Payments — Laravel admin API for the Payments page listing.
 * Backend: GET /api/admin/payments/dashboard (Laravel paginator)
 */
import { apiFetch } from './api.js'

export async function fetchPaymentsDashboard(query = {}) {
  return apiFetch('/admin/payments/dashboard', { query })
}

export const fetchPaymentDetails = (id, rowId) => apiFetch(`/admin/payments/${id}/details`, { query:{ row_id:rowId } })
export const previewPaymentRefund = id => apiFetch(`/admin/payments/${id}/refund-preview`)
export const requestPaymentRefund = (id, body) => apiFetch(`/admin/payments/${id}/refund`, { method:'POST', body })
export const refreshPaymentRefund = id => apiFetch(`/admin/payments/${id}/refund-refresh`, { method:'POST' })

