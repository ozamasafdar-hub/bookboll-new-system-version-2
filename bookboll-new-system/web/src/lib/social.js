// social.js — marketplace social layer: profile strength and direct messages.
// Apex marketplace only.
//
// Follows and posts live in customer/apiPublicProfile.js — the profile page reads and
// writes them through Laravel, which is also where their access control is enforced.
import { ref } from 'vue'
import { apiFetch } from './api.js'

// ── Profile strength (drives the "complete your profile" nudge) ────────────────
// A simple 0–100 score from the things hosts look at when accepting a join request.
export function profileStrength(c) {
  if (!c) return { score: 0, missing: [], level: 'weak' }
  const checks = [
    { key: 'avatar', ok: !!(c.avatar_url || c.avatar), label: 'Add a profile photo' },
    { key: 'name',   ok: !!(c.full_name && c.full_name.trim() && !/@/.test(c.full_name)), label: 'Add your real name' },
    { key: 'bio',    ok: !!(c.bio && c.bio.trim().length >= 20), label: 'Write a short bio (20+ chars)' },
    { key: 'photos', ok: Array.isArray(c.photos) && c.photos.length > 0, label: 'Add a few photos' },
  ]
  const done = checks.filter(c => c.ok).length
  const score = Math.round((done / checks.length) * 100)
  return {
    score,
    missing: checks.filter(c => !c.ok).map(c => c.label),
    level: score >= 75 ? 'strong' : score >= 50 ? 'ok' : 'weak',
  }
}

// ── Direct messages ─────────────────────────────────────────────────────────────
export function convoKey(a, b) { return [a, b].sort().join('__') }

/**
 * The whole inbox: one row per peer AND one per community group chat, newest
 * first. The server merges both so the page makes a single request.
 */
export async function loadInbox() {
  const res = await apiFetch('/customer/messages')
  if (!res.success) return []
  return res.data?.conversations || []
}

/** @deprecated direct-only view of the inbox; prefer loadInbox(). */
export async function loadConversations(meId) {
  if (!meId) return []
  return (await loadInbox()).filter(c => !c.group)
}

/** A direct thread with `otherId`, plus that peer, in one request. */
export async function loadThreadWithPeer(otherId) {
  if (!otherId) return { messages: [], peer: null }
  const res = await apiFetch('/customer/messages/thread', { query: { peer_id: otherId } })
  if (!res.success) return { messages: [], peer: null }
  return { messages: res.data?.messages || [], peer: res.data?.peer || null }
}

export async function loadThread(meId, otherId) {
  return (await loadThreadWithPeer(otherId)).messages
}

export async function sendMessage(meId, otherId, body) {
  const clean = (body || '').trim()
  if (!otherId || !clean) return { error: 'Empty message' }
  const res = await apiFetch('/customer/messages', {
    method: 'POST',
    body: { recipient_id: otherId, body: clean },
  })
  if (!res.success) return { error: res.message || 'Could not send that message.' }
  return { message: res.data?.message }
}

export async function markThreadRead(meId, otherId) {
  if (!otherId) return
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
  await apiFetch('/customer/messages/read', { method: 'POST', body: { peer_id: otherId } })
}

// Global unread badge (polled by the layout while signed in).
export const unreadMessages = ref(0)
export async function refreshUnread(meId) {
  if (!meId) { unreadMessages.value = 0; return }
  try {
    const res = await apiFetch('/customer/messages/unread-count')
    unreadMessages.value = res.success ? (Number(res.data?.count) || 0) : 0
  } catch { unreadMessages.value = 0 }
}
