// Per-tab support identity. The original superadmin bearer is never replaced.
export const SUPPORT_KEY = 'bb_business_support'
export function supportSession() {
  if (typeof sessionStorage === 'undefined') return null
  try {
    const raw = sessionStorage.getItem(SUPPORT_KEY)
    if (!raw) return null
    const value = JSON.parse(raw)
    return value?.token && value?.session_id && value?.expires_at ? value : { invalid: true }
  } catch { return { invalid: true } }
}
export function saveSupportSession(data) {
  if (!data?.token || !data.support_session?.session_id) throw new Error('The support session could not be started.')
  sessionStorage.setItem(SUPPORT_KEY, JSON.stringify({ ...data.support_session, token: data.token }))
}
export function clearSupportSession() {
  try { sessionStorage.removeItem(SUPPORT_KEY) } catch { /* expiry still enforced by server */ }
}
export function returnToSuperadmin(reason = 'ended') {
  clearSupportSession()
  // Discard every business-scoped in-memory store through a full reload.
  if (typeof window !== 'undefined') window.location.replace('/team?support=' + encodeURIComponent(reason))
}
