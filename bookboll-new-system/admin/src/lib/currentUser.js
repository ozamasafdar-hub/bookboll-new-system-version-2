import { ref, computed } from 'vue'
import { can as roleCan } from './permissions.js'

const STORAGE_KEY = 'bb_active_user'

const STAFF_ROLE_PRIORITY = [
  'super_admin',
  'country_admin',
  'platform_staff',
  'admin',
  'senior_employee',
  'junior_employee',
  'guard',
]

export const ALLOWED_PORTAL_ROLES = new Set([
  ...STAFF_ROLE_PRIORITY,
  'provider_admin',
  'facility_admin',
  'provider_supervisor',
  'staff',
  'provider_viewer',
])

export const activeUserId = ref(localStorage.getItem(STORAGE_KEY) || '')
export const currentUser = ref(null)
export const allUsers = ref([])
// The REAL signed-in account's email (set by lib/auth.js from the Sanctum session).
export const realAuthEmail = ref('')

/** Pick the best Spatie role name for the admin shell. */
export function pickPrimaryRole(roles = []) {
  const list = (Array.isArray(roles) ? roles : []).map(String)
  // Spatie legacy `admin` = full platform owner (same powers as Supabase `super_admin`).
  // Without this, admin@bookboll.com gets an empty provider Wallet/Approvals view.
  if (list.includes('super_admin') || list.includes('admin')) return 'super_admin'
  for (const r of STAFF_ROLE_PRIORITY) {
    if (list.includes(r)) return r
  }
  for (const r of ['provider_admin', 'facility_admin', 'provider_supervisor', 'staff', 'provider_viewer']) {
    if (list.includes(r)) return r
  }
  return list[0] || 'provider_admin'
}

/**
 * Map a Laravel User + Spatie roles into the shape App.vue / permissions expect
 * (previously an admin_users row from Supabase).
 */
export function mapLaravelUserToAdmin(user, roles = [], scope = null) {
  if (!user) return null
  const role = pickPrimaryRole(roles)
  // The server resolves the owner a member stands under (through their business
  // membership when the user row carries none); the row alone is null for an
  // account assigned into an ownerless business, and every catalogue page
  // filters by this id.
  const ownerUserId = scope?.owner_user_id ?? user.owner_user_id
  // An owner carries the business on owned_business; a manager or staff member
  // reaches it through their membership (GET /me and login both load it).
  // Without the second read, invited staff saw no business name in the shell.
  const membership = user.active_business_membership || user.activeBusinessMembership || null
  const business = user.owned_business || user.ownedBusiness || membership?.business || null
  return {
    id: String(user.id),
    source: 'laravel',
    full_name: user.name || user.email || 'User',
    email: user.email || '',
    role,
    status: user.status || 'active',
    business_name: business?.name || null,
    business_status: business?.status || null,
    business_id: business?.id ? String(business.id) : null,
    username: business?.username || null,
    // The site's own state, carried on the business row GET /me already returns.
    // Without these the shell can only ever offer a PREVIEW link: anything deciding
    // whether to hand out the real `<slug>.bookboll.com` address reads brand_enabled,
    // and an undefined flag reads as "not published". PublishNag depends on the same
    // field, deliberately distinguishing false from absent.
    brand_enabled: typeof business?.brand_enabled === 'boolean' ? business.brand_enabled : undefined,
    brand_subdomain: business?.brand_subdomain || null,
    brand_custom_domain: business?.brand_custom_domain || null,
    brand_domain_status: business?.brand_domain_status || null,
    // Geography ids stay as Laravel sends them (numbers). Stringifying was a
    // Supabase-UUID habit, and it silently broke jurisdiction filtering: a Set of
    // string country ids never matches the numeric country_id on a city or venue,
    // so a country_admin / platform_staff saw nothing at all.
    country_id: user.country_id ?? null,
    owner_user_id: ownerUserId != null ? String(ownerUserId) : null,
    custom_permissions: null,
    assigned_country_ids: Array.isArray(user.assigned_country_ids)
      ? user.assigned_country_ids.map(Number).filter(Number.isFinite)
      : [],
    assigned_location_ids: [],
    assigned_listing_ids: [],
    assigned_program_ids: [],
  }
}

/** Apply a Laravel session user into currentUser / allUsers for the shell. */
export function applyLaravelUser(user, roles = [], scope = null) {
  const mapped = mapLaravelUserToAdmin(user, roles, scope)
  if (!mapped) {
    clearLaravelUser()
    return null
  }
  currentUser.value = mapped
  allUsers.value = [mapped]
  activeUserId.value = mapped.id
  realAuthEmail.value = (mapped.email || '').toLowerCase()
  try { localStorage.setItem(STORAGE_KEY, mapped.id) } catch { /* ignore */ }
  return mapped
}

export function clearLaravelUser() {
  currentUser.value = null
  allUsers.value = []
  activeUserId.value = ''
  realAuthEmail.value = ''
  try { localStorage.removeItem(STORAGE_KEY) } catch { /* ignore */ }
}

// May the real signed-in account act as `row`?
export function canActAs(row) {
  if (!row) return false
  const email = realAuthEmail.value
  if (!email) return false
  const own = allUsers.value.filter(u => (u.email || '').toLowerCase() === email)
  if ((row.email || '').toLowerCase() === email) return true
  if (own.some(u => u.role === 'super_admin' || u.role === 'admin')) return true
  if (own.some(u => u.role === 'country_admin' || u.role === 'platform_staff')) return row.role !== 'super_admin'
  return false
}

export const switchableUsers = computed(() => allUsers.value.filter(u => canActAs(u)))

/**
 * Hydrate shell context after auth. Sanctum path already sets currentUser via
 * applyLaravelUser — do not reload from Supabase.
 */
export async function loadUserContext() {
  if (currentUser.value?.source === 'laravel') return
}

export function setActiveUser(id) {
  const row = allUsers.value.find(x => x.id === id) || null
  if (!canActAs(row)) return
  activeUserId.value = id
  currentUser.value = row
  localStorage.setItem(STORAGE_KEY, id || '')
}

export const role = computed(() => currentUser.value?.role || 'super_admin')
export const isSuperAdmin = computed(() => role.value === 'super_admin')
export const isCountryAdmin = computed(() => role.value === 'country_admin')
export const isProviderScoped = computed(() =>
  ['provider_admin', 'facility_admin', 'provider_supervisor', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard', 'staff'].includes(role.value)
)
export const currentOwnerUserId = computed(() => {
  const u = currentUser.value
  if (!u) return null
  if (u.role === 'provider_admin') return u.id
  return u.owner_user_id || null
})
export const currentCountryId = computed(() => currentUser.value?.country_id || null)
export const allBusinessOwners = computed(() =>
  allUsers.value.filter(u => u.role === 'provider_admin')
)

export const assignedCountryIds  = computed(() => currentUser.value?.assigned_country_ids || [])
export const assignedLocationIds = computed(() => currentUser.value?.assigned_location_ids || [])
export const assignedListingIds  = computed(() => currentUser.value?.assigned_listing_ids || [])
export const assignedProgramIds  = computed(() => currentUser.value?.assigned_program_ids || [])
export const hasScopeRestriction = computed(() =>
  (assignedLocationIds.value.length + assignedListingIds.value.length + assignedProgramIds.value.length) > 0)

function scopeNarrows() {
  return !isSuperAdmin.value && !isCountryAdmin.value && role.value !== 'provider_admin'
}
export function scopeLocations(locs) {
  if (!scopeNarrows()) return locs
  const ids = assignedLocationIds.value
  if (!ids.length) return locs
  const set = new Set(ids)
  return locs.filter(l => set.has(l.id))
}
export function scopeListings(items) {
  if (!scopeNarrows()) return items
  const ids = assignedListingIds.value
  if (!ids.length) return items
  const set = new Set(ids)
  return items.filter(i => set.has(i.id))
}
export function scopePrograms(items) {
  if (!scopeNarrows()) return items
  const ids = assignedProgramIds.value
  if (!ids.length) return items
  const set = new Set(ids)
  return items.filter(i => set.has(i.id))
}

export const currentOverrides = computed(() => currentUser.value?.custom_permissions || null)

export function can(cap) {
  return roleCan(cap, role.value, currentOverrides.value)
}
export const currentUsername = computed(() => {
  const u = currentUser.value
  if (!u) return null
  if (u.role === 'provider_admin') return u.username || null
  if (u.owner_user_id) {
    const owner = allUsers.value.find(x => x.id === u.owner_user_id)
    return owner?.username || null
  }
  return u.username || null
})

export function useCurrentUser() {
  return {
    currentUser,
    activeUserId,
    allUsers,
    switchableUsers,
    allBusinessOwners,
    role,
    isSuperAdmin,
    isCountryAdmin,
    isProviderScoped,
    currentOwnerUserId,
    currentCountryId,
    currentUsername,
    can,
    currentOverrides,
    assignedCountryIds,
    assignedLocationIds,
    assignedListingIds,
    assignedProgramIds,
    hasScopeRestriction,
    scopeLocations,
    scopeListings,
    scopePrograms,
    setActiveUser,
    loadUserContext,
  }
}
