/**
 * auth.js — Laravel Sanctum auth for the Bookboll admin dashboard.
 *
 * Replaces Supabase Auth. Session = Bearer token in localStorage (bb_admin_token)
 * + hydrated user via POST /login and GET /me.
 *
 * Public surface kept stable for Login / App / main.js:
 *   authUser, authLoading, authError, needsAccountChoice,
 *   initAuth, signIn, signOut, chooseAdminAccount, changeOwnPassword
 */
import { ref, computed } from 'vue'
import { apiFetch, getAdminToken, setAdminToken, clearAdminToken } from './api.js'
import { disconnectEcho } from './echo.js'
import { resetPlan } from './premium.js'
import {
  applyLaravelUser,
  clearLaravelUser,
  activeUserId,
  realAuthEmail,
  ALLOWED_PORTAL_ROLES,
} from './currentUser.js'

export const authUser     = ref(null)   // lightweight { id, email } when signed in
export const authLoading  = ref(true)
export const authError    = ref('')

// Multi-account picker was Supabase admin_users–specific. Laravel is one user row.
export const accountChoices = ref([])
export const needsAccountChoice = computed(() => false)

const PORTAL_REJECT_MSG = 'This portal is for Bookboll staff and business accounts.'

function normalizeRoles(roles) {
  if (!Array.isArray(roles)) return []
  return roles.map((r) => {
    if (typeof r === 'string') return r
    if (r && typeof r === 'object' && r.name) return String(r.name)
    return String(r)
  })
}

function rolesAllowed(roles) {
  return normalizeRoles(roles).some((r) => ALLOWED_PORTAL_ROLES.has(r))
}

function applySession(data) {
  const user = data?.user
  const roles = normalizeRoles(data?.roles)
  if (!user || !rolesAllowed(roles)) {
    clearSession()
    return false
  }
  // `scope` is the server's own answer for whose business this account acts
  // under; the user row's owner_user_id is null for a member assigned into an
  // ownerless business, and the pages filter their catalogue by it.
  applyLaravelUser(user, roles, data?.scope || null)
  authUser.value = {
    id: String(user.id),
    email: user.email || '',
  }
  realAuthEmail.value = (user.email || '').toLowerCase()
  return true
}

function clearSession() {
  try { disconnectEcho() } catch { /* ignore */ }
  clearAdminToken()
  clearLaravelUser()
  // The plan is per-account; the next sign-in must fetch its own, not inherit
  // whatever the last one was entitled to.
  resetPlan()
  authUser.value = null
  accountChoices.value = []
  activeUserId.value = ''
}

function isPreviewRender() {
  if (typeof window === 'undefined') return false
  try { return new URLSearchParams(window.location.search).get('preview') === '1' } catch { return false }
}

/** Boot: hydrate from stored Sanctum token via GET /me. */
export async function initAuth() {
  if (isPreviewRender()) { authLoading.value = false; return }

  const token = getAdminToken()
  if (!token) {
    clearSession()
    authLoading.value = false
    return
  }

  const res = await apiFetch('/me', { method: 'GET', token })
  if (!res.success || res.status === 401) {
    clearSession()
    authLoading.value = false
    return
  }

  if (!applySession(res.data)) {
    authError.value = PORTAL_REJECT_MSG
  }
  authLoading.value = false
}

export function chooseAdminAccount(_id) {
  // No-op: multi-business picker disabled under Sanctum (single user).
}

export async function signIn(email, password) {
  authError.value = ''
  const res = await apiFetch('/login', {
    method: 'POST',
    body: { email, password },
    token: '', // do not send a stale bearer on login
  })

  if (!res.success) {
    authError.value = res.message || 'Invalid email or password.'
    return false
  }

  const roles = normalizeRoles(res.data?.roles)
  if (!rolesAllowed(roles)) {
    authError.value = PORTAL_REJECT_MSG
    return false
  }

  const token = res.data?.token
  if (!token) {
    authError.value = 'Login succeeded but no token was returned.'
    return false
  }

  setAdminToken(token)
  if (!applySession(res.data)) {
    authError.value = PORTAL_REJECT_MSG
    return false
  }
  return true
}

/**
 * Step 1 of forgot-password: ask for a six-digit code by email.
 *
 * Always reports success when the request went through, because the API answers the
 * same way whether or not the address belongs to an admin — telling an anonymous
 * caller which addresses are privileged is exactly what a targeted phishing campaign
 * needs. The caller should say "if that address is an admin account…", not "sent".
 *
 * @returns {Promise<{ ok?: true, error?: string }>}
 */
export async function requestAdminPasswordReset(email) {
  const clean = (email || '').trim().toLowerCase()
  if (!clean) return { error: 'Enter your email address.' }

  const res = await apiFetch('/admin/forgot-password', {
    method: 'POST',
    body: { email: clean },
    token: '',   // signed out by definition; never send a stale bearer
  })

  if (!res.success) return { error: resetError(res, 'Could not send the code. Please try again.') }
  return { ok: true }
}

/**
 * Step 2: exchange the code for a new password.
 *
 * Resetting revokes every existing session server-side, this tab included, so the
 * caller must send the admin back to the sign-in form rather than assume they are on.
 *
 * @returns {Promise<{ ok?: true, error?: string }>}
 */
export async function resetAdminPassword({ email, otp, password, passwordConfirmation }) {
  const clean = (email || '').trim().toLowerCase()
  const code = (otp || '').trim()

  if (!clean) return { error: 'Enter your email address.' }
  if (!/^\d{6}$/.test(code)) return { error: 'Enter the 6-digit code from your email.' }
  if ((password || '').length < 8) return { error: 'Password must be at least 8 characters.' }
  if (password !== passwordConfirmation) return { error: 'Passwords do not match.' }

  const res = await apiFetch('/admin/reset-password', {
    method: 'POST',
    body: { email: clean, otp: code, password, password_confirmation: passwordConfirmation },
    token: '',
  })

  if (!res.success) return { error: resetError(res, 'Could not reset your password. Please try again.') }

  // The password changed, so whatever this tab was holding is dead anyway.
  clearSession()
  return { ok: true }
}

/** First field error if the API sent one, else its message. */
function resetError(res, fallback) {
  // Laravel's throttle middleware answers a bare "Too Many Attempts.", which tells an
  // admin nothing about what to do next.
  if (res?.status === 429) {
    return res?.message && res.message !== 'Too Many Attempts.'
      ? res.message
      : 'Too many attempts. Please wait a few minutes and try again.'
  }

  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

export async function signOut() {
  const token = getAdminToken()
  if (token) {
    try { await apiFetch('/logout', { method: 'POST', token }) } catch { /* ignore */ }
  }
  clearSession()
}

/** Signup moved to the business app + Laravel; keep stub for any leftover callers. */
export async function signUpProvider() {
  authError.value = 'Create your account on the Bookboll for Business site.'
  return false
}

/** Change password via Laravel Sanctum. Used by Settings → Security.
 *  Backend requires current_password; Settings currently only collects the new
 *  password — until that UI is updated, this will return a validation error. */
export async function changeOwnPassword(newPassword, currentPassword = '') {
  authError.value = ''
  const token = getAdminToken()
  if (!token) {
    authError.value = 'You are not signed in.'
    return false
  }
  if (!currentPassword) {
    authError.value = 'Enter your current password to change it.'
    return false
  }
  const res = await apiFetch('/change-password', {
    method: 'POST',
    token,
    body: {
      current_password: currentPassword,
      password: newPassword,
      password_confirmation: newPassword,
    },
  })
  if (!res.success) {
    authError.value = res.message || 'Could not update password.'
    return false
  }
  return true
}
