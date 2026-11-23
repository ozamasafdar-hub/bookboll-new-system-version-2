/**
 * Thin axios wrapper for the Laravel Bookboll API.
 * Expects { success, message, data, errors } JSON bodies.
 */
import axios from 'axios'
import { endExpiredSession, isSessionExpired } from './sessionExpiry.js'

const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')

const client = axios.create({
  baseURL: API_BASE,
  headers: { Accept: 'application/json' },
})

// Same key businessAuth.js writes on register/login. Read here rather than
// importing it: businessAuth imports apiFetch, so importing back would be a cycle.
const TOKEN_KEY = 'bb_api_token'
function storedToken() {
  try { return localStorage.getItem(TOKEN_KEY) || '' } catch { return '' }
}

/**
 * @param {string} path - Absolute path under the API base, e.g. `/business/register`
 * @param {{ method?: string, body?: object|null, token?: string|null, query?: Record<string, string|number|undefined|null>, timeout?: number }} [opts]
 * @returns {Promise<{ success: boolean, message?: string, data?: any, errors?: any, code?: number, status: number }>}
 */
export async function apiFetch(path, { method = 'GET', body = null, token = null, query = null, timeout = 0 } = {}) {
  const headers = {}
  // Fall back to the stored token, as the admin and web apps do. Without this,
  // only calls that passed a token explicitly were authenticated — which is why
  // the signup logo upload (POST /uploads/image) came back 401 even though
  // registration had just issued and saved a token.
  // An explicit `token: ''` still opts out, for deliberately anonymous calls.
  const bearer = token !== null && token !== undefined ? token : storedToken()
  if (bearer) headers.Authorization = `Bearer ${bearer}`

  try {
    const res = await client.request({
      url: path.startsWith('/') ? path : `/${path}`,
      method,
      data: body ?? undefined,
      params: query || undefined,
      timeout,
      headers,
      validateStatus: () => true, // normalize success/error ourselves
    })

    const json = res.data && typeof res.data === 'object' ? res.data : null
    const ok = res.status >= 200 && res.status < 300

    // The session was revoked while this tab was open. Callers still get their
    // normal {success:false} answer — the reload is already on its way.
    if (isSessionExpired(res.status, bearer)) {
      endExpiredSession()
    }

    // Laravel ValidationException default shape: { message, errors }
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
