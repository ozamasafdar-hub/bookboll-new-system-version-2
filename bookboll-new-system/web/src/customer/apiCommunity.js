/**
 * Laravel-backed community APIs for apps/web.
 * Mirrors the shapes previously returned by Supabase community.js.
 */
import { apiFetch } from '../lib/api.js'
import { ref } from 'vue'
import { normalizedPermissions, permissionsConfirmed } from '../lib/communityAccess.js'

export const communitiesEnabled = ref(null)

function formatErr(res, fallback) {
  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

export async function probeCommunities() {
  if (communitiesEnabled.value === true) return true
  const res = await apiFetch('/marketplace/communities/probe')
  communitiesEnabled.value = !!res.success
  return communitiesEnabled.value
}

export async function loadCommunities({ cityId = null, sport = '', q = '' } = {}) {
  const res = await apiFetch('/marketplace/communities', {
    query: {
      ...(cityId ? { city_id: cityId } : {}),
      ...(sport ? { sport } : {}),
      ...(q ? { q } : {}),
    },
  })
  if (!res.success) return []
  return res.data?.communities || []
}

export async function loadCommunity(id, viewerId = null) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/communities/${encodeURIComponent(id)}`)
  if (!res.success || !res.data?.community) return null
  return res.data.community
}

export async function myCommunities(customerId) {
  if (!customerId) return []
  const res = await apiFetch('/customer/communities/mine')
  if (!res.success) return []
  return res.data?.communities || []
}

export async function loadMembers(communityId) {
  if (!communityId) return []
  const res = await apiFetch(`/marketplace/communities/${encodeURIComponent(communityId)}/members`)
  if (!res.success) return []
  return (res.data?.members || []).map((m) => ({
    ...m,
    community_id: communityId,
  }))
}

export async function createCommunity(ownerId, fields) {
  if (!ownerId) return { error: 'Not signed in' }
  const name = (fields.name || '').trim()
  if (!name) return { error: 'Give your community a name.' }
  const res = await apiFetch('/customer/communities', {
    method: 'POST',
    body: {
      name,
      description: (fields.description || '').trim(),
      sport: (fields.sport || '').trim(),
      sports: fields.sports || [],
      city_id: fields.city_id || null,
      country_id: fields.country_id || null,
      cover_image: fields.cover_image || '',
      avatar_image: fields.avatar_image || '',
      images: fields.images || [],
      is_private: !!fields.is_private,
      settings: normalizedPermissions(fields.settings),
    },
  })
  if (!res.success || !res.data?.community) {
    return { error: formatErr(res, 'Could not create the community.') }
  }
  const persisted = await loadCommunity(res.data.community.id)
  const community = persisted || res.data.community
  return { community, settingsWarning: permissionsConfirmed(persisted, normalizedPermissions(fields.settings)) ? '' : 'Your community was created, but its posting and chat permissions could not be confirmed. Review its settings before inviting members.' }
}

export async function updateCommunity(id, patch) {
  if (!id) return { error: 'Missing community.' }
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: patch,
  })
  if (!res.success) return { error: formatErr(res, 'Could not update.') }
  const persisted = await loadCommunity(id)
  const community = persisted || res.data?.community
  if (patch.settings && !permissionsConfirmed(persisted, normalizedPermissions(patch.settings))) return { error: 'We could not confirm all posting and chat permissions. Review the saved settings before inviting members or try again later.', community }
  return { ok: true, community }
}

// A narrow settings patch: moderators must never gain general community editing.
// The server must merge these keys and authorize this action independently.
export async function updateCommunityChatPermission(id, permission) {
  if (!id || !['members', 'moderators', 'admins'].includes(permission)) return { error: 'Choose who can send messages.' }
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: { settings: { chat_permission: permission, chat_locked: permission === 'admins' } },
  })
  if (!res.success) return { error: formatErr(res, 'Could not update chat permissions.') }
  const community = await loadCommunity(id)
  if (community?.settings?.chat_permission !== permission) {
    return { error: 'The new chat permission could not be confirmed. Try again or ask a community admin.', community }
  }
  return { ok: true, community }
}

export async function joinCommunity(community, customerId) {
  if (!customerId) return { error: 'Not signed in' }
  const id = community?.id || community
  if (!id) return { error: 'Missing community.' }
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(id)}/join`, { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not join.') }
  return { membership: res.data?.membership || { status: community?.is_private ? 'pending' : 'accepted' } }
}

export async function leaveCommunity(communityId, customerId) {
  if (!communityId) return { error: 'Missing community.' }
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(communityId)}/leave`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not leave.') }
  return { ok: true }
}

export async function respondToMember(memberId, accept) {
  const res = await apiFetch(`/customer/community-members/${encodeURIComponent(memberId)}/respond`, {
    method: 'POST',
    body: { accept: !!accept },
  })
  if (!res.success) return { error: formatErr(res, 'Could not update member.') }
  return { ok: true }
}

export async function removeMember(memberId) {
  const res = await apiFetch(`/customer/community-members/${encodeURIComponent(memberId)}`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not remove member.') }
  return { ok: true }
}

export async function setMemberRole(memberId, role) {
  if (!['admin', 'moderator', 'member'].includes(role)) return { error: 'Invalid role' }
  const res = await apiFetch(`/customer/community-members/${encodeURIComponent(memberId)}/role`, {
    method: 'PATCH',
    body: { role },
  })
  if (!res.success) return { error: formatErr(res, 'Could not update role.') }
  return { ok: true }
}

export function canManage(membership) {
  return !!membership && membership.status === 'accepted' && ['owner', 'admin'].includes(membership.role)
}

export function isMember(membership) {
  return !!membership && membership.status === 'accepted'
}

export async function loadCommunityFeed(communityId) {
  if (!communityId) return []
  const res = await apiFetch(`/marketplace/communities/${encodeURIComponent(communityId)}/posts`)
  if (!res.success) return []
  return res.data?.posts || []
}

export async function createCommunityPost(communityId, authorId, { body, photos }) {
  if (!authorId) return { error: 'Not signed in' }
  const clean = (body || '').trim()
  const pics = (photos || []).filter(Boolean)
  if (!clean && !pics.length) return { error: 'Write something or add a photo.' }
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(communityId)}/posts`, {
    method: 'POST',
    body: { body: clean, photos: pics },
  })
  if (!res.success) return { error: formatErr(res, 'Could not post.') }
  return { post: res.data?.post }
}

export async function deleteCommunityPost(id) {
  const res = await apiFetch(`/customer/community-posts/${encodeURIComponent(id)}`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not delete post.') }
  return { ok: true }
}

export function groupKey(kind, id) {
  return `${kind}__${id}`
}

export async function loadGroupThread(key, limit = 200) {
  if (!key) return []
  const res = await apiFetch('/customer/messages/group', { query: { key, limit } })
  if (!res.success) return []
  const messages = res.data?.messages || []
  if (messages.length && typeof document !== 'undefined' && document.visibilityState === 'visible') {
    await apiFetch('/customer/messages/group/read', { method: 'POST', body: { key, through: messages.at(-1).id } })
  }
  return messages
}

export async function sendGroupMessage(key, senderId, body, mentions = []) {
  const clean = (body || '').trim()
  if (!senderId || !clean) return { error: 'Empty message' }
  const res = await apiFetch('/customer/messages/group', {
    method: 'POST',
    body: { key, body: clean, mentions },
  })
  if (!res.success) return { error: formatErr(res, 'Could not send.') }
  return { message: res.data?.message }
}

// Pending Laravel contract: see readmeAlamgir.md. Only remove the UI message
// after the server confirms deletion; never treat 404/403 as success.
export async function deleteGroupMessage(key, id) {
  if (!key || !id) return { error: 'Missing message.' }
  const res = await apiFetch('/customer/messages/group/' + encodeURIComponent(id), { method: 'DELETE', body: { key } })
  if (!res.success) return { error: formatErr(res, 'This message could not be removed. Please try again later.') }
  return { ok: true }
}

export async function markCommunityChatRead(communityId, customerId) {
  if (!communityId) return
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
  await apiFetch(`/customer/communities/${encodeURIComponent(communityId)}/chat/read`, { method: 'POST' })
}

export async function communityChatUnread(communityId, membership) {
  if (!isMember(membership) || !communityId) return 0
  const res = await apiFetch(`/customer/communities/${encodeURIComponent(communityId)}/chat/unread`)
  if (!res.success) return 0
  return res.data?.count || 0
}

export async function checkCommunityMembership(communityId, customerId) {
  if (!communityId || !customerId) return { membership: null, is_member: false }
  const res = await apiFetch('/customer/communities/membership-check', {
    query: { community_id: communityId, customer_id: customerId },
  })
  if (!res.success) return { membership: null, is_member: false }
  return {
    membership: res.data?.membership || null,
    is_member: !!res.data?.is_member,
  }
}

export const STREET_CRED_BADGES = {
  great_sport: { label: 'Great sport', emoji: '🤝' },
  clutch: { label: 'Clutch', emoji: '⚡' },
  hustle: { label: 'Hustler', emoji: '🔥' },
  wall: { label: 'The Wall', emoji: '🧱' },
  organizer_star: { label: 'Star organizer', emoji: '⭐' },
}

export async function giveStreetCred(gameId, giverId, receiverId, badge) {
  if (!gameId || !giverId || !receiverId || !STREET_CRED_BADGES[badge]) return { error: 'Invalid props.' }
  if (giverId === receiverId) return { error: "You can't give props to yourself 😄" }
  const res = await apiFetch('/customer/social/street-cred', {
    method: 'POST',
    body: { game_id: gameId, receiver_id: receiverId, badge },
  })
  if (!res.success) return { error: formatErr(res, 'Could not give props.') }
  return { ok: true }
}

export async function myPropsForGame(gameId, giverId) {
  if (!gameId || !giverId) return {}
  const res = await apiFetch('/customer/social/street-cred', { query: { game_id: gameId } })
  if (!res.success) return {}
  const raw = res.data?.props || {}
  const out = {}
  for (const [receiverId, badges] of Object.entries(raw)) {
    out[receiverId] = new Set(Array.isArray(badges) ? badges : [])
  }
  return out
}

export async function streetCredCounts(customerId) {
  if (!customerId) return []
  const res = await apiFetch('/customer/social/street-cred/counts', { query: { customer_id: customerId } })
  if (!res.success) return []
  return res.data?.streetCred || []
}

/** Everyone waiting to join any community I run — one call, not one per community. */
export async function loadPendingMembers() {
  const res = await apiFetch('/customer/communities/pending-members')
  if (!res.success) return []
  return res.data?.members || []
}
