/**
 * Thin axios wrapper for the Laravel Bookboll API.
 * Expects { success, message, data, errors } JSON bodies.
 */
import axios from 'axios'
import { endExpiredSession, isSessionExpired } from './sessionExpiry.js'
import { supportSession } from './businessSupport.js'

export const ADMIN_TOKEN_KEY = 'bb_admin_token'

const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')

const client = axios.create({
  baseURL: API_BASE,
  headers: {
    Accept: 'application/json',
    from: 'admin-web',
  },
})

export function getAdminToken() {
  const support = supportSession()
  if (support) return support.token || 'invalid-support-session'
  try { return localStorage.getItem(ADMIN_TOKEN_KEY) || '' } catch { return '' }
}

export function setAdminToken(token) {
  try {
    if (token) localStorage.setItem(ADMIN_TOKEN_KEY, token)
    else localStorage.removeItem(ADMIN_TOKEN_KEY)
  } catch { /* ignore */ }
}

export function clearAdminToken() {
  setAdminToken('')
}

/**
 * @param {string} path - Absolute path under the API base, e.g. `/login`
 * @param {{ method?: string, body?: object|null, token?: string|null, timeout?: number, query?: Record<string, string|number|undefined|null> }} [opts]
 * @returns {Promise<{ success: boolean, message?: string, data?: any, errors?: any, code?: number, status: number }>}
 */
export async function apiFetch(path, { method = 'GET', body = null, token = null, query = null, timeout = 0 } = {}) {
  const headers = {}
  const bearer = token !== null && token !== undefined ? token : getAdminToken()
  if (bearer) headers.Authorization = `Bearer ${bearer}`

  try {
    const res = await client.request({
      url: path.startsWith('/') ? path : `/${path}`,
      method,
      timeout,
      data: body ?? undefined,
      params: query || undefined,
      headers,
      validateStatus: () => true,
    })

    const json = res.data && typeof res.data === 'object' ? res.data : null
    const ok = res.status >= 200 && res.status < 300

    // The session was revoked while this tab was open. Every caller still gets
    // its normal {success:false} answer — the redirect is already on its way,
    // and a half-finished screen must not throw on top of it.
    if (isSessionExpired(res.status, bearer) && bearer === getAdminToken()) {
      endExpiredSession()
    }

    if (json && typeof json.success !== 'boolean') {
      const hasErrors = json.errors && typeof json.errors === 'object'
      return {
        success: ok,
        message: json.message || (ok ? 'OK' : 'Request failed'),
        data: json.data ?? null,
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
