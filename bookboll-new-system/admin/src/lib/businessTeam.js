import { normalizeBusinessTypes } from './businessTypes.js'
import { businessPhoneError } from './businessPhone.js'

// An owner belongs to their own team, even though their owner_user_id is null.
export function businessTeam(owner, users = []) {
  if (!owner) return []
  const id = owner.id == null ? null : String(owner.id)
  const actualOwner = id == null ? null : users.find(u => String(u.id) === id)
  const rows = id == null ? [] : [{ ...owner, ...actualOwner, role: 'provider_admin', isBusinessOwner: true }]
  const seen = new Set(id == null ? [] : [id])
  for (const user of users) {
    const belongs = user.business_id != null
      ? owner.business_id != null && String(user.business_id) === String(owner.business_id)
      : id != null && String(user.owner_user_id) === id
    if (user.id == null || !belongs || seen.has(String(user.id))) continue
    seen.add(String(user.id))
    rows.push({ ...user, isBusinessOwner: user.role === 'provider_admin' })
  }
  return orderBusinessTeam(rows)
}

export function orderBusinessTeam(users = []) {
  const rank = u => u.role === 'provider_admin' ? (u.is_primary_owner ? 0 : 1) : 2
  return [...users].sort((a,b) => rank(a)-rank(b) || (a.full_name || a.email || '').localeCompare(b.full_name || b.email || ''))
}

export function ownerAccessWarning(member, members = [], currentUserId) {
  if (!member) return ''
  if (String(member.id) === String(currentUserId)) return 'Ask another owner or superadmin to change your own access.'
  if (member.role === 'provider_admin' && member.status === 'active' && !members.some(u => String(u.id) !== String(member.id) && u.role === 'provider_admin' && u.status === 'active')) return 'Add or reactivate another owner before removing this owner’s access.'
  return ''
}

export function businessFormErrors(form) {
  const errors = {}
  if (!form.business_name?.trim()) errors.business_name = 'Enter the business name.'
  if (businessPhoneError(form.phone)) errors.phone = businessPhoneError(form.phone)
  if (!/^[a-z0-9][a-z0-9-]{2,29}$/.test(form.username || '')) errors.username = 'Use 3–30 lowercase letters, numbers or hyphens; start with a letter or number.'
  if (!normalizeBusinessTypes(form.business_types).length) errors.business_types = 'Choose at least one business type.'
  return errors
}
