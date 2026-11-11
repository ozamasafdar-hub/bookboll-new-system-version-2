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
export const previewCheckoutCollection = (id, rowId) => apiFetch(`/admin/payments/${id}/collection-preview`, { query:{ row_id:rowId } })
export const collectCheckoutPayment = (id, body) => apiFetch(`/admin/payments/${id}/collect-checkout`, { method:'POST', body })
export const previewPaymentReview = (id, body) => apiFetch(`/admin/payments/${id}/review-preview`, { method:'POST', body })
export const savePaymentReview = (id, body) => apiFetch(`/admin/payments/${id}/review`, { method:'POST', body })

