/**
 * Thin axios wrapper for the public Laravel Bookboll API (apps/web).
 * Expects { success, message, data, errors } JSON bodies. Never throws —
 * callers treat success:false as empty/null.
 */
import axios from 'axios'
import { endExpiredSession, isSessionExpired } from './sessionExpiry.js'
import { holdToken } from './holdSession.js'

export const CUSTOMER_TOKEN_KEY = 'bb_customer_token'

export const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')

const client = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
    from: 'frontend-web',
  },
})

export function getCustomerToken() {
  try { return localStorage.getItem(CUSTOMER_TOKEN_KEY) || '' } catch { return '' }
}

export function setCustomerToken(token) {
  try {
    if (token) localStorage.setItem(CUSTOMER_TOKEN_KEY, token)
    else localStorage.removeItem(CUSTOMER_TOKEN_KEY)
  } catch { /* ignore */ }
}

export function clearCustomerToken() {
  setCustomerToken('')
}

/**
 * @param {string} path
 * @param {{ method?: string, body?: object|null, token?: string|null, query?: Record<string, any> }} [opts]
 * @returns {Promise<{ success: boolean, message?: string, data?: any, errors?: any, code?: number, status: number }>}
 */
export async function apiFetch(path, { method = 'GET', body = null, token = null, query = null } = {}) {
  const headers = { 'X-Bookboll-Hold-Token': holdToken() }
  const bearer = token !== null && token !== undefined ? token : getCustomerToken()
  if (bearer) headers.Authorization = `Bearer ${bearer}`
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  if (!isForm && body && typeof body === 'object') headers['Content-Type'] = 'application/json'

  try {
    const res = await client.request({
      url: path.startsWith('/') ? path : `/${path}`,
      method,
      data: body ?? undefined,
      params: query || undefined,
      headers,
      validateStatus: () => true,
    })

    const json = res.data && typeof res.data === 'object' ? res.data : null
    const ok = res.status >= 200 && res.status < 300

    // The session was revoked while this tab was open. Callers still get their
    // normal {success:false} answer — the reload is already on its way, and a
    // half-finished screen must not throw on top of it.
    if (isSessionExpired(res.status, bearer)) {
      endExpiredSession()
    }

    if (json && typeof json.success !== 'boolean') {
      const hasErrors = json.errors && typeof json.errors === 'object'
      return {
        success: ok,
        message: json.message || (ok ? 'OK' : 'Request failed'),
        data: json.data ?? json,
        errors: hasErrors ? json.errors : {},
        status: res.status,
      }
    }

    return {
      success: Boolean(json?.success),
      message: json?.message || (ok ? 'OK' : 'Request failed'),
      data: json?.data,
      errors: json?.errors || {},
      code: json?.code,
      status: res.status,
    }
  } catch {
    return {
      success: false,
      message: 'Could not reach the server. Check that the API is running.',
      errors: {},
      status: 0,
    }
  }
}

export function apiBaseUrl() {
  return API_BASE
}
