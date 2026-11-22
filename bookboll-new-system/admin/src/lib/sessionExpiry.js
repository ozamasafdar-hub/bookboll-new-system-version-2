/**
 * What happens the moment the server stops recognising this session.
 *
 * Revoking a token from the admin panel only invalidates it SERVER side. The
 * browser carried on holding the stale token and the screen full of data it
 * fetched before, so every action after that quietly failed — a save that did
 * nothing, a list that never refreshed — and the operator had no idea they had
 * been signed out. Boot-time auth (initAuth) only ever covered the first load.
 *
 * So: the first 401 against an authenticated request ends the session here.
 *
 * Deliberately free of imports from auth.js / currentUser.js. Those modules
 * import apiFetch, and apiFetch has to call into this one, so importing them
 * back would close a cycle. Clearing the keys directly is also the more
 * dependable thing to do: the redirect below is a full document load, which
 * discards every in-memory store whether or not it had a reset function.
 */

import { supportSession, returnToSuperadmin } from './businessSupport.js'

/** Keys that belong to the signed-in account and must not outlive it. */
const SESSION_KEYS = [
  'bb_admin_token',       // api.js — the Sanctum bearer
  'bb_active_user',       // currentUser.js — cached identity and role
  'bb_customer_identity', // customerSession.js — the customer the staff was acting as
]

/** Where an expired session lands. */
const LOGIN_PATH = '/login'

// A revoked token usually 401s several in-flight requests in the same tick.
// Without this latch each one would clear storage and queue its own redirect.
let handled = false

/** Test seam: a fresh page gets a fresh latch. */
export function resetSessionExpiryLatch() {
  handled = false
}

export function isSessionExpiryHandled() {
  return handled
}

/**
 * Should this response end the session?
 *
 * Only when the request actually CARRIED a token. A 401 on an anonymous call
 * means "sign in to do that", which is a normal answer and must not boot
 * anyone out — and a wrong password is a 400 from this API, never a 401, so
 * the login screen cannot put itself into a loop.
 */
export function isSessionExpired(status, sentToken) {
  return status === 401 && Boolean(sentToken)
}

/**
 * Drop everything tied to the account and return to the login screen.
 *
 * `location.replace` rather than a router push: it leaves no history entry to
 * go "back" into a dead session, and the reload guarantees no component is
 * still holding data the revoked account could see.
 */
export function endExpiredSession() {
  if (handled) return
  handled = true

  if (supportSession()) { returnToSuperadmin('expired'); return }

  for (const key of SESSION_KEYS) {
    try { localStorage.removeItem(key) } catch { /* storage can be unavailable */ }
  }

  if (typeof window === 'undefined') return

  // Already on the login screen: clearing was the whole job, and reloading it
  // would throw away a half-typed email for no reason.
  if (window.location.pathname === LOGIN_PATH) {
    handled = false
    return
  }

  window.location.replace(LOGIN_PATH)
}
