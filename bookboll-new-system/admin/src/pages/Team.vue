<script setup>
import BusinessSetup from '../components/BusinessSetup.vue'
import BusinessInvite from '../components/BusinessInvite.vue'
import { brandPreviewUrl } from '../lib/brand.js'
import BusinessAssignment from '../components/BusinessAssignment.vue'
import AccountPicker from '../components/AccountPicker.vue'
import { financeRequest } from '../lib/financeApi.js'
import { newRequestKey } from '../lib/finance.js'
import { businessTeam, businessFormErrors } from '../lib/businessTeam.js'
import { ref, onMounted, computed, watch } from 'vue'
import { apiFetch } from '../lib/api.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { Plus, Search, Trash2, Pencil as Edit2, X, Shield, UserCog, User, MapPin, Building2, AtSign, Users, UsersRound, ChevronDown, Info, Crown, CircleArrowDown } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import FilterBar from '../components/FilterBar.vue'
import { idIn } from '../lib/geoFilter.js'
import PermissionScopeEditor from '../components/PermissionScopeEditor.vue'
import RoleSelect from '../components/RoleSelect.vue'
import { usePagination } from '../lib/usePagination.js'
import { ROLE_INFO, roleLabel as roleLabelFn, PLATFORM_ROLES, PROVIDER_ROLES } from '../lib/permissions.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import MultiSelect from '../components/MultiSelect.vue'
import { BUSINESS_TYPES, normalizeBusinessTypes, businessTypeLabel } from '../lib/businessTypes.js'

function unwrapPage(block) {
  if (!block) return []
  if (Array.isArray(block)) return block
  if (Array.isArray(block.data)) return block.data
  return []
}

function apiErr(res, fallback = 'Request failed.') {
  if (!res) return fallback
  const errors = res.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      if (Array.isArray(v) && v[0]) return String(v[0])
      if (typeof v === 'string' && v) return v
    }
  }
  return res.message || fallback
}

// Roles a super admin can assign (platform + provider) and a provider can assign
// to their own team (everything below Owner).
const ASSIGNABLE_ADMIN_ROLES = [...PLATFORM_ROLES, ...PROVIDER_ROLES]
const ASSIGNABLE_TEAM_ROLES = PROVIDER_ROLES.filter(r => r !== 'provider_admin')

const { loadUserContext, isSuperAdmin, isCountryAdmin, role: actorRole, currentUser, currentOwnerUserId, currentUsername, assignedCountryIds, assignedLocationIds } = useCurrentUser()

// Platform-side roles (Bookboll) all get the management view; provider roles get
// the "My Team" view. Country Admin / Platform Staff are platform-side.
const isPlatformSide = computed(() =>
  isSuperAdmin.value
  || isCountryAdmin.value
  || actorRole.value === 'platform_staff'
  || actorRole.value === 'admin'
)

// Roles the CURRENT user may assign — you can never grant a role at or above your
// own authority. Super → all; Country Admin → Platform Staff + provider; Platform
// Staff & providers → provider roles only.
const assignableRoles = computed(() => {
  if (isSuperAdmin.value) return [...PLATFORM_ROLES, ...PROVIDER_ROLES]
  if (isCountryAdmin.value) return ['platform_staff', ...PROVIDER_ROLES]
  return [...ASSIGNABLE_TEAM_ROLES]   // platform_staff + provider admins → provider roles only
})

// ── Jurisdiction (which businesses a platform user may manage) ────────────────
// A business's countries are derived from its locations (+ optional anchor on the
// owner row), the same way wallets are auto-created per location country.
function businessCountryIds(ownerId) {
  const set = new Set()
  for (const l of locations.value) {
    if (String(l.owner_user_id) !== String(ownerId)) continue
    const city = cities.value.find(c => c.id === l.city_id)
    if (city?.country_id) set.add(city.country_id)
  }
  const owner = allUsers.value.find(u => String(u.id) === String(ownerId))
  if (owner?.country_id) set.add(owner.country_id)
  const biz = businesses.value.find(b => String(b.owner_user_id) === String(ownerId))
  if (biz?.country_id) set.add(biz.country_id)
  return set
}
// The current platform user's country jurisdiction (null = global/unrestricted).
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null
  const ids = assignedCountryIds.value
  if (isCountryAdmin.value) {
    // Pre-migration fallback: a Country Admin with no assigned list uses their country_id.
    if (ids.length) return new Set(ids)
    return currentUser.value?.country_id ? new Set([currentUser.value.country_id]) : new Set()
  }
  if (actorRole.value === 'platform_staff') return ids.length ? new Set(ids) : null  // empty = inherited-global
  return null
})
const jurisdictionLocationSet = computed(() => new Set(assignedLocationIds.value))
// May the current platform user manage the business owned by `ownerId`?
function businessInJurisdiction(ownerId) {
  if (isSuperAdmin.value) return true
  // Platform Staff pinned to specific venues → only businesses that own one of them.
  if (actorRole.value === 'platform_staff' && jurisdictionLocationSet.value.size) {
    return locations.value.some(l => l.owner_user_id === ownerId && jurisdictionLocationSet.value.has(l.id))
  }
  const j = jurisdictionCountryIds.value
  if (!j) return true                 // unrestricted (super, or global platform staff)
  for (const c of businessCountryIds(ownerId)) if (j.has(c)) return true
  return false
}

// ── Shared ────────────────────────────────────────────────────────────────────
const locations       = ref([])
const listings        = ref([])   // venue scope deferred — kept empty for now
const programs        = ref([])
const countries       = ref([])
const cities          = ref([])
const businesses      = ref([])   // Laravel /admin/businesses
const SCOPED_ROLES = ['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'platform_staff']
const isScopedRole = (r) => SCOPED_ROLES.includes(r)
const commissionRates = ref({})
const teamView = ref('businesses')
const businessReview = ref(false)
const assignmentBusiness = ref(null)
const businessApiReady = ref(false)
const businessCapabilityLoading = ref(false)
let businessRequestKey = newRequestKey()
async function assignmentSaved() { assignmentBusiness.value=null; pushToast('Account moved to business','success'); await load(); await loadUserContext() }

/*
 * Optional owner on the Add business form.
 *
 * Creating a business still refuses an owner — BusinessIdentityService::store
 * accepts `owner_user_id` only as null, because attaching an account is a move
 * that has to be audited: it takes the person OUT of the platform team and can
 * be blocked (last super admin, already in a business, inactive). So the owner
 * chosen here is applied straight after creation through the SAME reviewed path
 * the "Assign platform account" button uses — preview, blockers, commit — rather
 * than a second, unaudited way in.
 */
const businessOwnerCandidates = ref([])
const businessOwnerRoles = ref([])
const businessOwnerLoading = ref(false)
const businessOwnerNotice = ref('')
const businessOwnerAck = ref(false)
const businessOwner = computed(() =>
  businessOwnerCandidates.value.find(c => String(c.id) === String(adminForm.value.assign_owner_user_id)) || null)
/**
 * Attach an existing account to a just-created business: preview, then commit.
 *
 * Nothing here is new policy — it is the same two calls BusinessAssignment.vue
 * makes. Blockers are reported verbatim rather than summarised, because they
 * name the specific thing to fix ("This is the last active super admin").
 *
 * @returns {Promise<{ok: boolean, message: string}>}
 */
async function assignOwnerToBusiness(businessId, userId, role) {
  const preview = await financeRequest(`/admin/businesses/${businessId}/assignment-preview`, {
    method: 'POST',
    body: { user_id: userId, role, scope: 'all', location_ids: [] },
  })
  if (!preview.ok || !preview.data?.preview_token) {
    return { ok: false, message: preview.message || 'the assignment could not be reviewed.' }
  }
  // The token is only valid for the exact move it was minted for; check the
  // server agreed to the move we asked for before committing it.
  if (String(preview.data.user_id) !== String(userId)
    || String(preview.data.business_id) !== String(businessId)
    || preview.data.role !== role) {
    return { ok: false, message: 'the reviewed assignment did not match the request.' }
  }
  const blockers = preview.data.blockers || []
  if (blockers.length) return { ok: false, message: blockers.join(' ') }

  const commit = await financeRequest(`/admin/businesses/${businessId}/assignments`, {
    method: 'POST',
    body: { preview_token: preview.data.preview_token, idempotency_key: newRequestKey() },
  })
  if (!commit.ok || !commit.data?.membership?.id) {
    return { ok: false, message: commit.message || 'the assignment was not confirmed.' }
  }
  return { ok: true, message: '' }
}

async function loadBusinessOwnerCandidates(search = '') {
  businessOwnerLoading.value = true
  businessOwnerNotice.value = ''
  const result = await financeRequest('/admin/businesses/assignment-candidates', { query: search ? { search } : {} })
  businessOwnerLoading.value = false
  if (!result.ok) {
    businessOwnerCandidates.value = []
    businessOwnerRoles.value = []
    // Not fatal: the business can still be created and staffed afterwards.
    businessOwnerNotice.value = 'Existing accounts could not be loaded, so an owner cannot be chosen here. Create the business and use “Assign platform account”.'
    return
  }
  businessOwnerCandidates.value = result.data?.candidates || []
  businessOwnerRoles.value = result.data?.roles || []
  if (!businessOwnerRoles.value.includes(adminForm.value.assign_owner_role)) {
    adminForm.value.assign_owner_role = businessOwnerRoles.value[0] || 'provider_admin'
  }
}
const newBusiness = computed(() => !editing.value && adminForm.value.role === 'provider_admin')
const businessErrors = computed(() => businessFormErrors(adminForm.value))
const showBusinessSetup = ref(false)
const inviteBusiness = ref(null)
const inviteRoles = computed(() => {
  const inBusiness = adminForm.value.owner_user_id || editing.value?.business_id || editing.value?.role === 'provider_admin'
  return assignableRoles.value.filter(r => inBusiness ? PROVIDER_ROLES.includes(r) : PLATFORM_ROLES.includes(r))
})
function openBusinessNew() { showBusinessSetup.value = true }
function createdBusiness(b) { teamView.value = 'businesses'; search.value = b.username; load() }
function assignNewBusiness(b) { assignmentBusiness.value = { ...b, business_id: b.id, id: b.owner_user_id } }
async function reviewBusiness() {
  triedSave.value = true
  if (Object.keys(businessErrors.value).length) { formError.value = 'Complete the highlighted fields.'; return }
  // An owner is optional, but a chosen one must be a real candidate: the list is
  // what the server says this admin may move, so a stale id is refused here
  // rather than after the business has already been created.
  if (adminForm.value.assign_owner_user_id && !businessOwner.value) {
    formError.value = 'That account is no longer available to assign. Choose another, or leave the owner empty.'
    return
  }
  if (adminForm.value.assign_owner_user_id && !businessOwnerRoles.value.includes(adminForm.value.assign_owner_role)) {
    formError.value = 'Choose a business role for the owner.'
    return
  }
  businessOwnerAck.value = false
  formError.value = ''
  businessReview.value = true
  businessCapabilityLoading.value=true
  const result=await financeRequest('/admin/businesses/capabilities')
  businessCapabilityLoading.value=false
  businessApiReady.value=!!(result.ok && result.data?.create_without_owner)
  if(!businessApiReady.value) formError.value='Business creation without an owner requires the updated business backend. Your draft is ready; no account has been created.'
}

const showModal = ref(false)
const editing   = ref(null)
const saving    = ref(false)
const search    = ref('')
const filterCountry = ref([])
const filterCity    = ref([])
const filterBizTypes = ref([])
const bizTypeOptions = BUSINESS_TYPES.map(t => ({ value: t.key, label: t.label }))
const filterPlan = ref('')
const triedSave = ref(false)
// Team members (admin_users) carry country_id / city_id directly.
function memberGeoMatch(u) {
  if (!idIn(u?.city_id, filterCity.value)) return false
  if (!idIn(u?.country_id, filterCountry.value)) return false
  return true
}
async function requestCloseTeamModal() { if (saving.value || businessCapabilityLoading.value) return; if (await confirmDiscardIfDirty()) showModal.value = false }

// ── Admin Users (super admin view) ────────────────────────────────────────────
const allUsers   = ref([])
const formError  = ref('')
const adminForm  = ref(defaultAdminForm())

function defaultAdminForm() {
  return {
    business_name: '', business_types: [], full_name: '', email: '', role: 'staff', country_id: '', location_id: '',
    owner_user_id: '', status: 'active', username: '', phone: '', city_id: '',
    // Add business only: an EXISTING platform account to make this business's
    // owner. Empty = create the business unclaimed, as before.
    assign_owner_user_id: '', assign_owner_role: 'provider_admin',
    commission_rate_facility: null, commission_rate_program: null,
    business_status: 'approved', description: '', license_number: '',
    custom_permissions: {}, assigned_location_ids: [], assigned_listing_ids: [], assigned_program_ids: [],
  }
}

const isProviderScopedRole = (r) => ['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard'].includes(r)
const businessOwners = computed(() => allUsers.value.filter(u => u.role === 'provider_admin' && businessInJurisdiction(u.id)))

const filteredUsers = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return allUsers.value
  return allUsers.value.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q) ||
    (u.username || '').toLowerCase().includes(q) ||
    roleLabel(u.role).toLowerCase().includes(q)
  )
})
const { page: auPage, perPage: auPerPage, totalPages: auTotalPages, paginated: paginatedUsers, visiblePages: auVisiblePages, goTo: auGoTo } = usePagination(filteredUsers)

function openAdminNew() {
  businessRequestKey=newRequestKey()
  businessApiReady.value=false
  businessReview.value = false
  editing.value = null
  adminForm.value = { ...defaultAdminForm(), role: assignableRoles.value.find(r => PLATFORM_ROLES.includes(r)) || 'staff' }
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openAdminEdit(u) {
  businessReview.value = false
  editing.value = u
  formError.value = ''
  adminForm.value = {
    business_name: u.business_name || '', business_types: normalizeBusinessTypes(u.business_types),
    full_name: u.full_name || '', email: u.email || '', role: u.role || 'staff',
    country_id: u.country_id || '', location_id: u.location_id || '',
    owner_user_id: u.owner_user_id || '', status: u.status || 'active',
    username: u.username || '', phone: u.phone || '', city_id: u.city_id || '',
    commission_rate_facility: u.commission_rate_facility ?? null,
    commission_rate_program:  u.commission_rate_program  ?? null,
    business_status: u.business_status || 'pending',
    description: u.description || '', license_number: u.license_number || '',
    custom_permissions: u.custom_permissions || {},
    assigned_location_ids: [...(u.assigned_location_ids || [])],
    assigned_listing_ids:  [...(u.assigned_listing_ids  || [])],
    assigned_program_ids:  [...(u.assigned_program_ids  || [])],
  }
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
// Persist via Laravel /admin/users (Sanctum).
async function persistAdminUser(payload) {
  const body = {
    name: payload.full_name || payload.name,
    email: payload.email,
    role: payload.role,
    status: payload.status || 'active',
    phone: payload.phone || null,
    country_id: payload.country_id || null,
    assigned_country_ids: payload.assigned_country_ids || null,
    owner_user_id: payload.owner_user_id || null,
    // Which venues this person may manage. Scoped roles only: for an owner or a
    // platform role the picker is hidden, and sending [] would strip their access.
    ...(isScopedRole(payload.role)
      ? { assigned_location_ids: payload.assigned_location_ids || [] }
      : {}),
    business_name: payload.business_name || payload.full_name || null,
    username: payload.username || null,
    business_types: payload.business_types || null,
  }
  if (payload.password) body.password = payload.password

  const res = editing.value
    ? await apiFetch(`/admin/users/${editing.value.id}`, { method: 'PUT', body })
    : await apiFetch('/admin/users', { method: 'POST', body })

  if (!res.success) return { message: apiErr(res, 'Could not save user.') }
  return null
}

async function saveAdmin() {
  formError.value = ''
  triedSave.value = true
  if (!assignableRoles.value.includes(adminForm.value.role)) {
    formError.value = "You don't have permission to assign that role."
    return
  }
  if (saving.value) return
  if (editing.value && (
    String(adminForm.value.owner_user_id || '') !== String(editing.value.owner_user_id || '') ||
    (adminForm.value.role === 'provider_admin') !== (editing.value.role === 'provider_admin') ||
    ((editing.value.business_id || editing.value.owner_user_id) && PLATFORM_ROLES.includes(adminForm.value.role))
  )) {
    formError.value='Business ownership and account moves require a reviewed assignment. Open the destination business and choose Assign platform account.'
    return
  }
  if (newBusiness.value && Object.keys(businessErrors.value).length) {
    formError.value = 'Complete the required business details.'
    businessReview.value = false
    return
  }
  if (newBusiness.value) {
    if(!businessReview.value || !businessApiReady.value) { formError.value='Review the business and connect the updated business backend before creating it.'; return }
    saving.value=true
    const result=await financeRequest('/admin/businesses', {method:'POST',body:{business_name:adminForm.value.business_name.trim(),username:adminForm.value.username.trim(),business_types:normalizeBusinessTypes(adminForm.value.business_types),country_id:adminForm.value.country_id || null,status:adminForm.value.status,owner_user_id:null,idempotency_key:businessRequestKey}})
    saving.value=false
    if(!result.ok || !result.data?.business?.id) {formError.value=result.message || 'The server did not confirm the business. Your draft is still here.';return}
    const createdId = result.data.business.id
    // No owner chosen: unchanged behaviour.
    if (!adminForm.value.assign_owner_user_id) {
      clearDirty();showModal.value=false;pushToast('Business created. Assign an owner whenever you’re ready.','success');await load();return
    }
    /*
     * Owner chosen: attach through the reviewed path. The business now EXISTS,
     * so every failure below says so — the admin must never be told the whole
     * thing failed and create a duplicate.
     */
    const owner = businessOwner.value
    saving.value = true
    const assigned = await assignOwnerToBusiness(createdId, adminForm.value.assign_owner_user_id, adminForm.value.assign_owner_role)
    saving.value = false
    clearDirty();showModal.value=false
    if (assigned.ok) {
      pushToast(`Business created and ${owner?.name || 'the account'} assigned as ${roleLabel(adminForm.value.assign_owner_role)}.`,'success')
    } else {
      // Deliberately a long-lived error toast: the business is real and the
      // admin has to know the owner step still needs doing.
      pushToast(`Business created, but the owner was not assigned — ${assigned.message} Open the business and use “Assign platform account”.`,'error')
    }
    await load(); await loadUserContext(); return
  }
  if (!adminForm.value.full_name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminForm.value.email.trim())) {
    formError.value = 'Enter a name and a valid email address.'
    return
  }
  const isOwner    = adminForm.value.role === 'provider_admin'
  const isInternal = ['super_admin', 'country_admin', 'platform_staff', 'admin'].includes(adminForm.value.role)
  if (isOwner && !editing.value && !adminForm.value.username.trim()) {
    formError.value = 'Company Login Username is required.'
    return
  }
  const resolvedOwnerId = isOwner ? null : isInternal ? null : (adminForm.value.owner_user_id || currentOwnerUserId.value || null)
  let resolvedUsername = null
  if (isOwner) {
    resolvedUsername = editing.value ? undefined : adminForm.value.username.trim().toLowerCase().replace(/\s+/g, '')
  } else {
    resolvedUsername = editing.value ? undefined : null
  }
  let resolvedCountryId = adminForm.value.country_id || null
  if (isProviderScopedRole(adminForm.value.role) && resolvedOwnerId) {
    const owner = allUsers.value.find(u => String(u.id) === String(resolvedOwnerId))
    if (owner?.country_id) resolvedCountryId = owner.country_id
  }
  if (isOwner && !editing.value && !resolvedCountryId) {
    const j = jurisdictionCountryIds.value
    if (j && j.size) resolvedCountryId = [...j][0]
  }

  const payload = {
    full_name: adminForm.value.full_name.trim(),
    email: adminForm.value.email.trim().toLowerCase(),
    role: adminForm.value.role,
    country_id: resolvedCountryId,
    owner_user_id: resolvedOwnerId,
    status: adminForm.value.status,
    phone: isOwner ? adminForm.value.phone : (adminForm.value.phone || null),
    business_name: isOwner ? (adminForm.value.business_name || adminForm.value.full_name).trim() : null,
    business_types: isOwner ? normalizeBusinessTypes(adminForm.value.business_types) : null,
    // The venues ticked in the Access Scope picker. saveAdmin() builds this object
    // field by field, so anything not named here never reaches the request — which
    // is why a selection of two venues still submitted an empty list.
    assigned_location_ids: [...(adminForm.value.assigned_location_ids || [])],
  }
  if (resolvedUsername !== undefined) payload.username = resolvedUsername
  if (adminForm.value.role === 'platform_staff' && !editing.value) {
    const j = jurisdictionCountryIds.value
    payload.assigned_country_ids = j ? [...j] : []
  }
  if (adminForm.value.role === 'country_admin') {
    payload.assigned_country_ids = resolvedCountryId ? [Number(resolvedCountryId)] : (adminForm.value.assigned_country_ids || [])
  }
  saving.value = true
  const error = await persistAdminUser(payload)
  saving.value = false
  if (error) { formError.value = error.message; pushToast('Could not save user.', 'error'); return }
  clearDirty()
  logAudit({ action: editing.value ? AUDIT.UPDATED : AUDIT.CREATED, entity_type: 'admin_user', entity_id: editing.value?.id || payload.email, details: `${editing.value ? 'Updated' : 'Created'} ${roleLabelFn(adminForm.value.role)} ${payload.full_name || payload.email || ''}`.trim() })
  pushToast(editing.value ? 'User updated' : isOwner ? 'Business created' : 'User created', 'success')
  showModal.value = false
  await load()
  await loadUserContext()
}
async function removeAdmin(user) {
  if (!user || !assignableRoles.value.includes(user.role)) {
    pushToast("You don't have the authority to delete this user.", 'error')
    return
  }
  if (!(await askConfirm({
    title: 'Delete user?',
    message: 'This user will be removed. If they own a business, related data may be removed too. This cannot be undone.',
    confirmText: 'Delete',
  }))) return
  const res = await apiFetch(`/admin/users/${user.id}`, { method: 'DELETE' })
  if (!res.success) { pushToast(apiErr(res, 'Could not delete user.'), 'error'); return }
  pushToast('User deleted', 'success')
  load()
}

// ── My Team (provider view) ───────────────────────────────────────────────────
const team        = ref([])
const filterRole  = ref('')
const filterLoc   = ref('')
const teamForm    = ref(defaultTeamForm())

// Provider staff tiers (everything below Owner) — drives the overview count cards.
const TEAM_ROLES = ASSIGNABLE_TEAM_ROLES.map(v => ({
  value: v, label: ROLE_INFO[v].label, desc: ROLE_INFO[v].tagline, color: ROLE_INFO[v].color, bg: ROLE_INFO[v].bg,
}))

function defaultTeamForm() {
  return {
    full_name: '', email: '', role: 'staff', location_id: '', phone: '', status: 'active',
    custom_permissions: {}, assigned_location_ids: [], assigned_listing_ids: [], assigned_program_ids: [],
  }
}

// Scope options for the permission editor, limited to the relevant business.
// Super admin: the owner picked in the form. Provider: their own business.
const editorOwnerId = computed(() =>
  isSuperAdmin.value ? (adminForm.value.owner_user_id || null) : currentOwnerUserId.value)
// Venues on offer in the scope picker, for the company currently selected.
//
// Two things this has to get right, both of which produced an empty list:
//   • "@bookboll" is the PLATFORM, and its option value is '' — Bookboll's own
//     venues are the ones no business owns (owner_user_id NULL). Returning []
//     for the empty owner hid all 15 of them.
//   • The <select> yields a STRING while the API sends a number, so a strict
//     === between them never matched even for a real business.
const editorLocations = computed(() => {
  if (!isSuperAdmin.value) return locations.value

  const owner = editorOwnerId.value
  if (!owner) return locations.value.filter(l => !l.owner_user_id)

  return locations.value.filter(l => String(l.owner_user_id ?? '') === String(owner))
})
const editorLocIdSet = computed(() => new Set(editorLocations.value.map(l => l.id)))
const editorListings = computed(() => listings.value.filter(l => editorLocIdSet.value.has(l.location_id)))
const editorPrograms = computed(() => programs.value.filter(p => editorLocIdSet.value.has(p.location_id)))

const filteredTeam = computed(() => {
  let list = team.value
  if (filterRole.value) list = list.filter(u => u.role === filterRole.value)
  // "Location" = who has access to this venue: scoped members via their
  // assigned_location_ids (the Access Scope picker), legacy members via the old
  // single location_id, and members with NO scope at all (= whole business).
  if (filterLoc.value)  list = list.filter(u => {
    const ids = u.assigned_location_ids || []
    if (ids.length) return ids.includes(filterLoc.value)
    if (u.location_id) return u.location_id === filterLoc.value
    return true   // unscoped = access to every location, this one included
  })
  if (filterCountry.value.length || filterCity.value.length) list = list.filter(memberGeoMatch)
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q)
  )
  return list
})
const { page: teamPage, perPage: teamPerPage, totalPages: teamTotalPages, paginated: paginatedTeam, visiblePages: teamVisiblePages, goTo: teamGoTo } = usePagination(filteredTeam)

const countByRole = computed(() => {
  const counts = {}
  for (const r of TEAM_ROLES) counts[r.value] = team.value.filter(u => u.role === r.value).length
  return counts
})

function openTeamNew() {
  editing.value = null
  teamForm.value = defaultTeamForm()
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openTeamEdit(u) {
  editing.value = u
  teamForm.value = {
    full_name: u.full_name || '', email: u.email || '', role: u.role || 'staff',
    location_id: u.location_id || '', phone: u.phone || '', status: u.status || 'active',
    custom_permissions: u.custom_permissions || {},
    assigned_location_ids: [...(u.assigned_location_ids || [])],
    assigned_listing_ids:  [...(u.assigned_listing_ids  || [])],
    assigned_program_ids:  [...(u.assigned_program_ids  || [])],
  }
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
async function saveTeam() {
  triedSave.value = true
  if (!teamForm.value.full_name || !teamForm.value.email || !teamForm.value.role) {
    pushToast('Please fill in the name, email and role.', 'error'); return
  }
  saving.value = true
  const body = {
    name: teamForm.value.full_name.trim(),
    email: teamForm.value.email.trim().toLowerCase(),
    role: teamForm.value.role,
    phone: teamForm.value.phone || null,
    status: teamForm.value.status,
    // Venue pins, for the roles that are meant to be scoped. Omitted for the rest
    // so an unscoped role is never silently narrowed to nothing.
    ...(isScopedRole(teamForm.value.role)
      ? { assigned_location_ids: teamForm.value.assigned_location_ids || [] }
      : {}),
  }
  const res = editing.value
    ? await apiFetch(`/business/team/${editing.value.id}`, { method: 'PUT', body })
    : await apiFetch('/business/team', { method: 'POST', body })
  saving.value = false
  if (!res.success) { pushToast(apiErr(res, 'Could not save team member.'), 'error'); return }
  clearDirty()
  logAudit({ action: editing.value ? AUDIT.UPDATED : AUDIT.CREATED, entity_type: 'team_member', entity_id: editing.value?.id || body.email, details: `${editing.value ? 'Updated' : 'Added'} ${roleLabelFn(teamForm.value.role)} ${body.name || body.email || ''}`.trim() })
  pushToast(editing.value ? 'Team member updated' : 'Team member added', 'success')
  showModal.value = false
  load()
}
async function removeTeam(u) {
  if (!(await askConfirm({
    title: 'Remove team member?',
    message: `${u.full_name || u.email} will be removed from your team and will lose access.`,
    confirmText: 'Remove',
  }))) return
  const res = await apiFetch(`/business/team/${u.id}`, { method: 'DELETE' })
  if (!res.success) { pushToast(apiErr(res, 'Could not remove team member.'), 'error'); return }
  pushToast('Team member removed', 'success')
  load()
}

// ── Load (Laravel Sanctum) ────────────────────────────────────────────────────
async function load() {
  if (isPlatformSide.value) {
    const [platformRes, staffRes, bizRes, countriesRes] = await Promise.all([
      apiFetch('/admin/users', { query: { scope: 'platform', records_per_page: 200 } }),
      apiFetch('/admin/users', { query: { scope: 'provider_staff', records_per_page: 500 } }),
      apiFetch('/admin/businesses', { query: { records_per_page: 200 } }),
      apiFetch('/admin/locations/countries'),
    ])
    const platformUsers = unwrapPage(platformRes.data?.users)
    const staffUsers = unwrapPage(staffRes.data?.users)
    // Include provider owners from businesses so groups/edit still resolve.
    const ownersFromBiz = unwrapPage(bizRes.data?.businesses).map(b => ({
      id: b.owner_user_id,
      full_name: b.owner?.full_name || b.owner?.name || b.full_name || b.business_name || b.name,
      email: b.owner?.email || b.email,
      role: 'provider_admin',
      status: b.owner?.status || b.owner_status || 'active',
      country_id: b.country_id,
      username: b.username,
      business_name: b.business_name || b.name,
      business_types: b.business_types || [],
      business_status: b.business_status || b.status,
      business_id: b.id,
      plan: b.plan || 'free',
      owner_user_id: null,
      last_login: b.owner?.last_login || b.owner?.last_login_at || b.last_login || null,
    })).filter(o => o.id)

    const byId = new Map()
    for (const u of [...platformUsers, ...staffUsers, ...ownersFromBiz]) {
      const existing = byId.get(String(u.id))
      byId.set(String(u.id), existing ? { ...u, ...existing } : u)
    }
    allUsers.value = [...byId.values()]
    businesses.value = unwrapPage(bizRes.data?.businesses)
    countries.value = countriesRes.data?.countries || []
    cities.value = []
    // The venue catalogue rides along with /admin/users. Without it the scope
    // picker rendered an empty list, so every assignment looked like "no venues"
    // and none could be made.
    locations.value = (platformRes.data?.venues || staffRes.data?.venues || [])
      .map(v => ({ ...v, owner_user_id: v.owner_user_id ?? null }))
    listings.value = []
    programs.value = []
    commissionRates.value = {}
  } else {
    const ownerId = currentOwnerUserId.value
    if (!ownerId) return
    const teamRes = await apiFetch('/business/team')
    team.value = teamRes.data?.users || []
    // The owner's venues, for the scope picker.
    locations.value = (teamRes.data?.venues || [])
      .map(v => ({ ...v, owner_user_id: v.owner_user_id ?? ownerId }))
    listings.value = []
    programs.value = []
  }
}
onMounted(load)
watch(() => currentUser.value?.id, () => load())

// ── Grouped view helpers (super admin) ────────────────────────────────────────

const platformTeam = computed(() =>
  allUsers.value.filter(u => {
    const isPlatformMember = ['super_admin', 'country_admin', 'platform_staff', 'admin'].includes(u.role) ||
      (isProviderScopedRole(u.role) && !u.owner_user_id)
    if (!isPlatformMember || u.business_id || u.owner_user_id) return false
    if (isSuperAdmin.value) return true
    return String(u.id) === String(currentUser.value?.id) || assignableRoles.value.includes(u.role)
  })
)

const providerGroups = computed(() => {
  return businesses.value
    .filter(b => b.owner_user_id ? businessInJurisdiction(b.owner_user_id) : isSuperAdmin.value || (
      !(actorRole.value === 'platform_staff' && jurisdictionLocationSet.value.size) &&
      (!jurisdictionCountryIds.value || [...jurisdictionCountryIds.value].some(id => String(id) === String(b.country_id)))
    ))
    .map(b => {
      const ownerRow = allUsers.value.find(u => String(u.id) === String(b.owner_user_id))
      const provider = {
        id: b.owner_user_id,
        role: 'provider_admin',
        full_name: ownerRow?.full_name || b.full_name || b.business_name || b.name,
        email: ownerRow?.email || b.email,
        username: b.username,
        business_name: b.business_name || b.name,
        business_types: b.business_types || [],
        business_status: b.business_status || b.status,
        business_id: b.id,
        country_id: b.country_id ?? ownerRow?.country_id,
        plan: b.plan || 'free',
        premium_since: b.premium_since || null,
        status: ownerRow?.status || 'active',
        last_login: ownerRow?.last_login || ownerRow?.last_login_at,
      }
      return {
        provider,
        staff: businessTeam(provider, allUsers.value),
      }
    })
})

// Expand state for business groups — all collapsed by default
const expandedBusinesses = ref(new Set())

function toggleBusiness(id) {
  const s = new Set(expandedBusinesses.value)
  s.has(id) ? s.delete(id) : s.add(id)
  expandedBusinesses.value = s
}

// Auto-expand businesses whose staff match the current search
watch(search, (q) => {
  if (!q.trim()) return
  const lower = q.trim().toLowerCase()
  const s = new Set(expandedBusinesses.value)
  for (const g of providerGroups.value) {
    const staffMatch = g.staff.some(u =>
      (u.full_name || '').toLowerCase().includes(lower) ||
      (u.email || '').toLowerCase().includes(lower) ||
      roleLabel(u.role).toLowerCase().includes(lower)
    )
    if (staffMatch) s.add(g.provider.business_id)
  }
  expandedBusinesses.value = s
})

const filteredPlatformTeam = computed(() => {
  const q = search.value.trim().toLowerCase()
  return platformTeam.value.filter(u =>
    memberGeoMatch(u) && (!q ||
      (u.full_name || '').toLowerCase().includes(q) ||
      (u.email || '').toLowerCase().includes(q) ||
      roleLabel(u.role).toLowerCase().includes(q))
  )
})

const { page: platformPage, perPage: platformPerPage, totalPages: platformTotalPages, paginated: paginatedPlatform, visiblePages: platformVisiblePages, goTo: platformGoTo } = usePagination(filteredPlatformTeam)

const filteredProviderGroups = computed(() => {
  const q = search.value.trim().toLowerCase()
  return providerGroups.value.filter(g => {
    if (filterCountry.value.length || filterCity.value.length) {
      if (!memberGeoMatch(g.provider) && !g.staff.some(memberGeoMatch)) return false
    }
    // Business type (multi): provider matches if they have ANY selected type.
    if (filterBizTypes.value.length) {
      const types = normalizeBusinessTypes(g.provider.business_types)
      if (!filterBizTypes.value.some(t => types.includes(t))) return false
    }
    // Plan filter (Premium / Free).
    if (filterPlan.value && (filterPlan.value === 'premium') !== isPremiumPlan(g.provider)) return false
    if (!q) return true
    const provMatch =
      (g.provider.full_name || '').toLowerCase().includes(q) ||
      (g.provider.email || '').toLowerCase().includes(q) ||
      (g.provider.username || '').toLowerCase().includes(q)
    const staffMatch = g.staff.some(u =>
      (u.full_name || '').toLowerCase().includes(q) ||
      (u.email || '').toLowerCase().includes(q) ||
      roleLabel(u.role).toLowerCase().includes(q)
    )
    return provMatch || staffMatch
  })
})

// Open invite modal pre-filled with a company assignment
function openAddStaffFor(providerId) {
  editing.value = null
  adminForm.value = { ...defaultAdminForm(), role: 'facility_admin', owner_user_id: providerId }
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}

// When a super admin picks a country for a NEW provider account,
// auto-fill both commission rates from that country's defaults.
// (Does nothing when editing an existing record — stored values take precedence.)
watch(
  () => adminForm.value.country_id,
  (countryId) => {
    if (adminForm.value.role !== 'provider_admin' || editing.value || !countryId) return
    const defaults = commissionRates.value[countryId]
    if (defaults) {
      adminForm.value.commission_rate_facility = defaults.facility
      adminForm.value.commission_rate_program  = defaults.program
    }
  }
)

// ── Helpers ───────────────────────────────────────────────────────────────────
const roleLabel  = (r) => roleLabelFn(r)
const roleBadge  = (r) => ({ super_admin: 'badge-error', country_admin: 'badge-warning', provider_admin: 'badge-info', facility_admin: 'badge-info', senior_employee: 'badge-warning', junior_employee: 'badge-neutral', guard: 'badge-neutral' }[r] || 'badge-neutral')
const roleInfo   = (r) => TEAM_ROLES.find(x => x.value === r) || { label: r, color: '#64748b', bg: '#f8fafc' }
const locName    = (id) => locations.value.find(l => l.id === id)?.name || '—'
// Human summary of a user's location scope (assigned_location_ids; empty = all).
function scopeSummary(u) {
  const ids = u?.assigned_location_ids || []
  if (!ids.length) return u?.location_id ? locName(u.location_id) : 'All locations'
  if (ids.length === 1) return locName(ids[0])
  return `${ids.length} locations`
}
const countryName = (id) => countries.value.find(c => c.id === id)?.name
const ownerTag   = (id) => { const o = allUsers.value.find(u => u.id === id); return o?.username ? `@${o.username}` : o?.full_name || '—' }

// What this person can actually reach, in the order the server resolves it
// (App\Support\AccessScope). Venue pins come FIRST: they are the narrowest and
// most specific answer, and reading past them reported "Global" for someone
// restricted to a single venue.
const GLOBAL_ROLES = ['super_admin', 'admin']
function scopeText(u) {
  if (u.role === 'provider_admin') return u.username ? `@${u.username}` : '—'

  const pins = u.assigned_location_ids || []
  if (pins.length === 1) return locName(pins[0])
  if (pins.length > 1) return `${pins.length} venues`

  if (u.location_id) return locName(u.location_id)
  if (u.owner_user_id) return ownerTag(u.owner_user_id)
  if (u.country_id) return countryName(u.country_id)
  if (GLOBAL_ROLES.includes(u.role)) return 'Global'

  // No pins, no business, no country and not a global role — the server gives
  // this person nothing. Saying "Global" hid exactly the accounts that need
  // fixing, so say what is true.
  return 'No access'
}

const statusBadge = (s) => ({ approved: 'badge-success', active: 'badge-success', pending: 'badge-warning', rejected: 'badge-error', suspended: 'badge-error' }[s] || 'badge-neutral')

// Premium is a paid Stripe subscription, so there is no admin switch that grants it —
// handing it out here would leave a provider entitled with nothing billed. The
// buttons stay hidden; the chip below reports what Stripe says.
const isPremiumPlan = (p) => p?.plan === 'premium'
const planBusyId = ref('')
async function setProviderPlan(_provider, _plan) {
  pushToast('Premium is a paid subscription — the provider starts it from their own dashboard.', 'info')
}
const timeAgo = (ts) => {
  if (!ts) return 'Never'
  const diff = Date.now() - new Date(ts).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function avatarInitials(name) { return (name || '?').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
const avatarColors = ['#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777']
function avatarColor(id) {
  if (id == null || id === '') return avatarColors[0]
  // Laravel numeric ids + legacy UUID strings both work.
  const n = typeof id === 'number'
    ? id
    : parseInt(String(id).replace(/-/g, '').slice(0, 8), 16) || Number(id) || 0
  return avatarColors[Math.abs(n) % avatarColors.length]
}
</script>

<template>
  <div class="page admin-workspace team-workspace">

    <!-- ── PLATFORM VIEW (Super / Country Admin / Platform Staff) ──────── -->
    <template v-if="isPlatformSide">
      <div class="page-header">
        <div>
          <h1 class="page-title">Team &amp; Users</h1>
          <div class="page-subtitle">{{ isSuperAdmin ? 'Platform admins and all provider businesses with their staff.' : 'Bookboll staff and the provider businesses in your jurisdiction.' }}</div>
        </div>
        <div class="workspace-actions"><button class="btn btn-secondary" @click="openAdminNew"><Plus :size="16" />Invite user</button><button v-if="assignableRoles.includes('provider_admin')" class="btn btn-primary" @click="openBusinessNew"><Building2 :size="16" />Add business</button></div>
      </div>

      <div class="workspace-switcher" aria-label="Team directory">
        <button :aria-pressed="teamView === 'businesses'" @click="teamView = 'businesses'"><Building2 :size="22" /><span><strong>Provider businesses <b>{{ providerGroups.length }}</b></strong><small>Owners, teams and business accounts</small></span></button>
        <button :aria-pressed="teamView === 'platform'" @click="teamView = 'platform'"><Shield :size="22" /><span><strong>Platform team <b>{{ platformTeam.length }}</b></strong><small>Bookboll access and responsibilities</small></span></button>
      </div>
      <!-- Search -->
      <FilterBar
        v-model:search="search"
        v-model:country="filterCountry"
        v-model:city="filterCity"
        :countries="countries"
        :cities="cities"
        :extra-count="(filterBizTypes.length ? 1 : 0) + (filterPlan ? 1 : 0)"
        search-placeholder="Search by name, email or @username…"
        @clear="filterBizTypes = []; filterPlan = ''"
      >
        <div class="lf-selects">
          <div class="lf-field"><label>Business type</label>
            <MultiSelect v-model="filterBizTypes" :options="bizTypeOptions" placeholder="All types" />
          </div>
          <div class="lf-field"><label>Plan</label>
            <select v-model="filterPlan" class="lf-select">
              <option value="">All plans</option>
              <option value="premium">Premium</option>
              <option value="free">Free</option>
            </select>
          </div>
        </div>
      </FilterBar>


      <!-- ── Platform Team ───────────────────────────────────────────── -->
      <div v-if="teamView === 'platform'" class="section-block">
        <div class="section-label"><Shield :size="13" />Platform Team <span class="section-count">{{ filteredPlatformTeam.length }}</span></div>
        <div class="card" style="padding:0;overflow:hidden;">
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th><th>Last Login</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="u in paginatedPlatform" :key="u.id">
                  <td>
                    <div class="cell-main">
                      <div class="avatar-sm" :style="`background:${avatarColor(u.id)}`">{{ avatarInitials(u.full_name) }}</div>
                      <div style="font-weight:500;">{{ u.full_name }}</div>
                    </div>
                  </td>
                  <td style="color:var(--text-muted);">{{ u.email }}</td>
                  <td><span class="badge" :class="roleBadge(u.role)"><Shield :size="11" />{{ roleLabel(u.role) }}</span></td>
                  <td style="color:var(--text-muted);">{{ scopeText(u) }}</td>
                  <td><span class="badge" :class="u.status==='active'?'badge-success':'badge-neutral'">{{ u.status }}</span></td>
                  <td style="color:var(--text-muted);">{{ timeAgo(u.last_login) }}</td>
                  <td><div class="row-actions"><button class="btn-icon" @click="openAdminEdit(u)"><Edit2 :size="15" /></button><button class="btn-icon danger" @click="removeAdmin(u)"><Trash2 :size="15" /></button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <PaginationBar v-if="teamView === 'platform' && filteredPlatformTeam.length" :page="platformPage" :per-page="platformPerPage" :total="filteredPlatformTeam.length" :total-pages="platformTotalPages" :visible-pages="platformVisiblePages" @goTo="platformGoTo" @update:perPage="platformPerPage = $event" />
      <div v-if="teamView === 'platform' && !filteredPlatformTeam.length" class="workspace-empty">No team members match your search.</div>
      <!-- ── Provider Businesses ─────────────────────────────────────── -->
      <div v-if="teamView === 'businesses'" class="section-block">
        <div class="section-label"><Building2 :size="13" />Provider Businesses <span class="section-count">{{ filteredProviderGroups.length }}</span></div>

        <div v-if="!filteredProviderGroups.length" class="card" style="text-align:center;padding:32px;color:var(--text-muted);">
          No providers found.
        </div>

        <div v-for="g in filteredProviderGroups" :key="g.provider.business_id" class="biz-group">

          <!-- Business header row -->
          <div class="biz-header" role="button" tabindex="0" :aria-expanded="expandedBusinesses.has(g.provider.business_id)" @click="toggleBusiness(g.provider.business_id)" @keydown.enter.self.prevent="toggleBusiness(g.provider.business_id)" @keydown.space.self.prevent="toggleBusiness(g.provider.business_id)">
            <div class="biz-icon"><Building2 :size="17" /></div>
            <div class="biz-name-block">
              <div class="biz-name">{{ g.provider.business_name || g.provider.full_name }}</div>
              <div v-if="g.provider.username" class="biz-username">@{{ g.provider.username }}</div>
            </div>
            <div class="biz-owner-email">{{ g.provider.id ? g.provider.email : 'Owner not assigned' }}</div>
            <div class="biz-badges">
              <span class="biz-plan-chip" :class="{ premium: isPremiumPlan(g.provider) }" :title="isPremiumPlan(g.provider) ? 'On the Premium plan' : 'On the Free plan'">
                <Crown v-if="isPremiumPlan(g.provider)" :size="11" />{{ isPremiumPlan(g.provider) ? 'Premium' : 'Free' }}
              </span>
              <span v-for="t in normalizeBusinessTypes(g.provider.business_types)" :key="t" class="biz-type-chip">{{ businessTypeLabel(t) }}</span>
              <span class="badge" :class="statusBadge(g.provider.business_status)">{{ g.provider.business_status || 'pending' }}</span>
              <span class="biz-staff-count"><UsersRound :size="12" />{{ g.staff.length }} team member{{ g.staff.length === 1 ? '' : 's' }}</span>
              <span v-if="g.provider.country_id" class="biz-country-pill">{{ countryName(g.provider.country_id) }}</span>
            </div>
            <div class="biz-actions" @click.stop>
              <a class="btn btn-sm btn-secondary" :href="brandPreviewUrl(g.provider.username, { preview: true })" target="_blank" rel="noopener">View website</a>
              <button v-if="!g.provider.id" class="btn btn-sm btn-secondary" @click="inviteBusiness=g.provider">Invite owner</button>
              <!-- The owner gap is read in the header ("Owner not assigned"), so
                   the fix for it belongs here rather than below a staff table the
                   admin has to expand first. -->
              <button v-if="!g.provider.id" class="btn btn-sm btn-primary biz-assign-btn" @click="assignmentBusiness=g.provider">
                <UsersRound :size="13" />Assign platform account
              </button>
              <button v-if="false && isSuperAdmin && !isPremiumPlan(g.provider)" class="btn-icon plan-up" :disabled="planBusyId === g.provider.id" @click="setProviderPlan(g.provider, 'premium')" title="Upgrade to Premium"><Crown :size="15" /></button>
              <button v-else-if="false && isSuperAdmin" class="btn-icon plan-down" :disabled="planBusyId === g.provider.id" @click="setProviderPlan(g.provider, 'free')" title="Move to Free"><CircleArrowDown :size="15" /></button>
              <button v-if="g.provider.id" class="btn-icon" @click="openAdminEdit(g.provider)" title="Edit business"><Edit2 :size="15" /></button>
              <button v-if="g.provider.id" class="btn-icon danger" @click="removeAdmin(g.provider)" title="Delete business"><Trash2 :size="15" /></button>
            </div>
            <div class="biz-chevron" :class="{ open: expandedBusinesses.has(g.provider.business_id) }"><ChevronDown :size="16" /></div>
          </div>

          <!-- Staff list (expanded) -->
          <div v-if="expandedBusinesses.has(g.provider.business_id)" class="staff-section">
            <div class="table-wrap"><table class="staff-table">
              <thead>
                <tr>
                  <th>Staff Member</th><th>Role</th><th>Location</th><th>Status</th><th>Last Login</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in g.staff" :key="m.id">
                  <td>
                    <div class="cell-main">
                      <div class="avatar-sm sm" :style="`background:${avatarColor(m.id)}`">{{ avatarInitials(m.full_name) }}</div>
                      <div>
                        <div style="font-weight:500;font-size:13px;">{{ m.full_name || '—' }}</div>
                        <div style="font-size:12px;color:var(--text-muted);">{{ m.email }}</div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge" :class="roleBadge(m.role)">{{ m.isBusinessOwner ? 'Business owner' : roleLabel(m.role) }}</span></td>
                  <td style="font-size:13px;color:var(--text-muted);">{{ scopeSummary(m) }}</td>
                  <td><span class="badge" :class="m.status==='active'?'badge-success':'badge-neutral'">{{ m.status }}</span></td>
                  <td style="font-size:12px;color:var(--text-muted);">{{ timeAgo(m.last_login) }}</td>
                  <td><div class="row-actions"><button class="btn-icon" @click="openAdminEdit(m)"><Edit2 :size="15" /></button><button v-if="!m.isBusinessOwner" class="btn-icon danger" @click="removeAdmin(m)" aria-label="Remove team member"><Trash2 :size="15" /></button></div></td>
                </tr>
                <tr v-if="!g.staff.length">
                  <td colspan="6" class="empty-staff">No team members yet. Invite an owner or staff member, or assign an existing platform account.</td>
                </tr>
              </tbody>
            </table></div>
            <div class="staff-add-row">
              <button v-if="g.provider.id" class="btn btn-sm btn-secondary" @click="assignmentBusiness=g.provider"><UsersRound :size="13"/>Assign platform account</button>
              <button class="btn btn-sm btn-secondary" @click="inviteBusiness=g.provider">
                <Plus :size="13" />Add staff member
              </button>
            </div>
          </div>

        </div>
      </div>

      <BusinessSetup v-if="showBusinessSetup" :countries="countries.filter(c => !jurisdictionCountryIds || jurisdictionCountryIds.has(c.id) || jurisdictionCountryIds.has(String(c.id)))" @close="showBusinessSetup=false" @created="createdBusiness" @invite="inviteBusiness=$event" @assign="assignNewBusiness" />
      <BusinessInvite v-if="inviteBusiness" :business="inviteBusiness" @close="inviteBusiness=null" @saved="load" />
      <BusinessAssignment v-if="assignmentBusiness" :business="assignmentBusiness" @close="assignmentBusiness=null" @assigned="assignmentSaved"/>
      <!-- Admin modal -->
      <div v-if="showModal" class="modal-backdrop" @click.self="requestCloseTeamModal">
        <div class="modal" v-dialog="requestCloseTeamModal" @input="markDirty" @change="markDirty">
          <div class="modal-header">
            <div class="modal-title">{{ newBusiness ? (businessReview ? 'Review new business' : 'Add business') : editing ? 'Edit user' : 'Invite user' }}</div>
            <button class="btn-icon" @click="requestCloseTeamModal" aria-label="Close"><X :size="18" /></button>
          </div>
          <div class="modal-body">
            <div v-if="newBusiness && businessReview" class="business-review">
              <Building2 :size="28" /><h3>{{ adminForm.business_name }}</h3><p>@{{ adminForm.username }}</p>
              <dl>
                <dt>Owner</dt>
                <dd v-if="businessOwner">{{ businessOwner.name }}<br /><span class="biz-review-sub">{{ businessOwner.email }} · {{ roleLabel(adminForm.assign_owner_role) }}</span></dd>
                <dd v-else>Assign later</dd>
                <dt>Business type</dt><dd>{{ adminForm.business_types.map(businessTypeLabel).join(', ') }}</dd>
                <dt>Country</dt><dd>{{ countryName(adminForm.country_id) || 'Not specified' }}</dd>
              </dl>
              <p v-if="!businessOwner" class="workspace-note">This creates the business without a login or owner account. Assign an existing platform account as owner or staff afterwards.</p>
              <template v-else>
                <p class="workspace-note">The business is created first, then this account is moved into it. The move is reviewed by the server and can be refused — if it is, the business still exists and you can assign someone from its card.</p>
                <label class="biz-owner-ack">
                  <input type="checkbox" v-model="businessOwnerAck" />
                  <span>I confirm {{ businessOwner.name }} should leave the platform team and become {{ roleLabel(adminForm.assign_owner_role) }} of this business.</span>
                </label>
              </template>
            </div>
            <template v-else>
            <p v-if="newBusiness" class="workspace-note">Create the business now. You can assign an owner and other roles from the platform team later.</p>
            <div v-if="!newBusiness" class="tm-section-title">Profile</div>
            <div v-if="!newBusiness" class="grid grid-2">
              <div class="form-group"><label class="form-label" for="team-name">{{ adminForm.role === 'provider_admin' ? 'Owner’s full name' : 'Full name' }} *</label><input id="team-name" v-model="adminForm.full_name" :aria-invalid="triedSave && !adminForm.full_name.trim()" autocomplete="name" /><small v-if="triedSave && !adminForm.full_name.trim()" class="form-error">Enter a full name.</small></div>
              <div class="form-group"><label class="form-label" for="team-email">Email *</label><input id="team-email" v-model="adminForm.email" type="email" :aria-invalid="triedSave && !!businessErrors.email" autocomplete="email" /><small v-if="triedSave && businessErrors.email" class="form-error">{{ businessErrors.email }}</small></div>
            </div>
            <div v-if="!newBusiness" class="tm-section-title">Role &amp; access</div>
            <div v-if="!newBusiness" class="form-group"><label class="form-label">Role</label>
              <RoleSelect v-model="adminForm.role" :roles="inviteRoles" />
            </div>
            <div v-if="adminForm.role === 'provider_admin'" class="inline-business">
              <div class="inline-business-header"><Building2 :size="14" />Business details</div>
              <div class="form-group"><label for="team-business" class="form-label">Business name *</label><input id="team-business" v-model="adminForm.business_name" :aria-invalid="triedSave && !!businessErrors.business_name" /><small v-if="triedSave && businessErrors.business_name" class="form-error">{{ businessErrors.business_name }}</small></div>
              <fieldset class="business-type-picker"><legend>Business type *</legend><label v-for="t in BUSINESS_TYPES" :key="t.key"><input type="checkbox" v-model="adminForm.business_types" :value="t.key" />{{ t.label }}</label><small v-if="triedSave && businessErrors.business_types" class="form-error">{{ businessErrors.business_types }}</small></fieldset>
              <div class="form-group">
                <label class="form-label">Company Login Username <span class="required">*</span></label>
                <div class="username-input-wrap">
                  <span class="username-prefix"><AtSign :size="14" /></span>
                  <input aria-label="Company login username" v-model="adminForm.username" @input="adminForm.username = adminForm.username.toLowerCase().replace(/\s/g, '')" :readonly="!!editing" :class="{ 'input-locked': !!editing, 'input-invalid': triedSave && !editing && !adminForm.username.trim() }" placeholder="e.g. dsc" style="padding-left:34px;" />
                </div>
                <div v-if="editing" class="field-hint">Username is locked after creation.</div>
              </div>
              <small v-if="triedSave && newBusiness && businessErrors.username" class="form-error">{{ businessErrors.username }}</small>
              <div class="form-group"><label for="business-country" class="form-label">Country (optional)</label><select id="business-country" v-model="adminForm.country_id"><option value="">Select country</option><option v-for="c in countries.filter(c => !jurisdictionCountryIds || jurisdictionCountryIds.has(c.id) || jurisdictionCountryIds.has(String(c.id)))" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
              <div class="biz-country-note">
                <Info :size="13" />
                <span>This business can operate in any country. Its countries &amp; currencies are set automatically from the locations it adds — a wallet is created per country.</span>
              </div>
              <!-- Optional owner: an EXISTING platform account. Left empty, the
                   business is created unclaimed exactly as before. -->
              <div v-if="newBusiness" class="biz-owner">
                <div class="inline-business-header"><UsersRound :size="14" />Owner (optional)</div>
                <p v-if="businessOwnerNotice" class="field-hint biz-owner-notice">{{ businessOwnerNotice }}</p>
                <template v-else>
                  <p class="field-hint biz-owner-lead">Pick a colleague from the platform team, or leave this empty and assign someone later.</p>
                  <AccountPicker
                    v-model="adminForm.assign_owner_user_id"
                    :candidates="businessOwnerCandidates"
                    :loading="businessOwnerLoading"
                    empty-text="No eligible accounts — everyone on the platform team is already in a business."
                    @search="loadBusinessOwnerCandidates"
                  />
                  <div v-if="adminForm.assign_owner_user_id" class="form-group biz-owner-role">
                    <label class="form-label" for="biz-owner-role">Business role *</label>
                    <select id="biz-owner-role" v-model="adminForm.assign_owner_role">
                      <option v-for="r in businessOwnerRoles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                    </select>
                  </div>
                  <p v-if="adminForm.assign_owner_user_id" class="field-hint biz-owner-warn">
                    <Info :size="13" />
                    <span>This moves the account into the business: it leaves the platform team and its existing sessions are refreshed. You’ll confirm on the next step.</span>
                  </p>
                </template>
              </div>
              <p class="field-hint">Commission is configured in Countries &amp; Cities. This account starts on the Free plan.</p>
            </div>
            <div v-else-if="isProviderScopedRole(adminForm.role)" class="grid grid-2 tm-company-row">
              <div class="form-group">
                <label class="form-label" for="team-company">Company</label>
                <select id="team-company" v-model="adminForm.owner_user_id">
                  <option value="">@bookboll</option>
                  <option v-for="b in businessOwners" :key="b.id" :value="b.id">@{{ b.username || b.full_name }}</option>
                </select>
                <div class="field-hint">The business this person works for.</div>
              </div>
              <div class="form-group">
                <label class="form-label" for="team-status">Status</label>
                <select id="team-status" v-model="adminForm.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                <div class="field-hint">Inactive users can’t sign in.</div>
              </div>
            </div>
            <!--
              Country field — only super_admin and country_admin need to select a country explicitly.
              Provider-scoped roles (facility_admin, senior_employee, junior_employee, guard, staff)
              inherit country_id from their assigned provider at save time — no manual selection needed.
              Provider admins set their country in the Company details section above.
            -->
            <div class="grid grid-2" v-if="['super_admin','country_admin'].includes(adminForm.role)">
              <div class="form-group"><label class="form-label">Country</label>
                <select v-model="adminForm.country_id"><option value="">—</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option></select>
              </div>
              <div class="form-group"><label class="form-label">Status</label>
                <select v-model="adminForm.status"><option>active</option><option>inactive</option></select>
              </div>
            </div>
            <!-- Status for provider_admin (provider-scoped roles show it next to Company) -->
            <div class="form-group" v-else-if="!isProviderScopedRole(adminForm.role)">
              <label class="form-label">Status</label>
              <select v-model="adminForm.status"><option>active</option><option>inactive</option></select>
            </div>
            <!-- Per-user permissions + location/listing/program scope -->
            <div v-if="isScopedRole(adminForm.role)" class="perm-editor-wrap">
              <PermissionScopeEditor
                :role="adminForm.role"
                v-model:permissions="adminForm.custom_permissions"
                v-model:locationIds="adminForm.assigned_location_ids"
                v-model:listingIds="adminForm.assigned_listing_ids"
                v-model:programIds="adminForm.assigned_program_ids"
                :available-locations="editorLocations"
                :available-listings="editorListings"
                :available-programs="editorPrograms"
              />
            </div>
            </template>
            <div v-if="formError" class="form-error" role="alert">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="requestCloseTeamModal">Cancel</button>
            <button v-if="newBusiness && businessReview" class="btn btn-secondary" :disabled="saving" @click="businessReview = false">Back to details</button>
            <button v-if="newBusiness && !businessReview" class="btn btn-primary" @click="reviewBusiness">Review business</button>
            <button v-else class="btn btn-primary" :disabled="saving || (newBusiness && (!businessApiReady || businessCapabilityLoading || (!!adminForm.assign_owner_user_id && !businessOwnerAck)))" @click="saveAdmin">{{ saving ? 'Saving…' : newBusiness ? (businessOwner ? 'Create business & assign owner' : 'Create business') : 'Save changes' }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ── PROVIDER VIEW ─────────────────────────────────────────────── -->
    <template v-else>
      <div class="page-header">
        <div>
          <h1 class="page-title">My Team</h1>
          <div class="page-subtitle">Choose who can manage bookings, customers and your business.</div>
        </div>
        <button class="btn btn-primary" @click="openTeamNew"><Plus :size="15" />Invite Member</button>
      </div>

      <!-- Role overview cards -->
      <div class="role-cards">
        <div v-for="r in TEAM_ROLES" :key="r.value" class="role-card" :style="`border-top:3px solid ${r.color}`">
          <div class="role-card-top">
            <div class="role-card-icon">
              <Shield v-if="r.value==='facility_admin'" :size="18" />
              <UserCog v-else-if="r.value==='senior_employee'" :size="18" />
              <User v-else :size="18" />
            </div>
            <div class="role-card-count">{{ countByRole[r.value] }}</div>
          </div>
          <div class="role-card-title">{{ r.label }}</div>
          <div class="role-card-desc">{{ r.desc }}</div>
        </div>
      </div>

      <!-- Toolbar -->
      <FilterBar
        v-model:search="search"
        v-model:country="filterCountry"
        v-model:city="filterCity"
        :countries="countries"
        :cities="cities"
        :extra-count="(filterRole ? 1 : 0) + (filterLoc ? 1 : 0)"
        search-placeholder="Search by name or email..."
        @clear="filterRole = ''; filterLoc = ''"
      >
        <div class="lf-selects">
          <div class="lf-field"><label>Role</label>
            <select class="filter-select" v-model="filterRole">
              <option value="">All roles</option>
              <option v-for="r in TEAM_ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </div>
          <div class="lf-field"><label>Location</label>
            <select class="filter-select" v-model="filterLoc">
              <option value="">All locations</option>
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </div>
        </div>
      </FilterBar>

      <!-- Empty state -->
      <div v-if="!team.length" class="team-empty">
        <Users :size="36" style="opacity:0.25" />
        <div class="team-empty-title">No team members yet</div>
        <div class="team-empty-sub">Invite managers, supervisors or staff and choose what each person can access.</div>
        <button class="btn btn-primary" @click="openTeamNew"><Plus :size="14" />Invite your first member</button>
      </div>

      <template v-else>
        <div v-if="filteredTeam.length" class="team-grid">
          <div v-for="u in paginatedTeam" :key="u.id" class="team-card">
            <div class="team-card-header">
              <div class="team-avatar" :style="`background:${avatarColor(u.id)}`">{{ avatarInitials(u.full_name) }}</div>
              <div class="team-card-info">
                <div class="team-name">{{ u.full_name || '—' }}</div>
                <div class="team-email">{{ u.email }}</div>
              </div>
              <div class="team-card-menu">
                <button class="btn-icon" @click="openTeamEdit(u)" title="Edit"><Edit2 :size="15" /></button>
                <button class="btn-icon danger" @click="removeTeam(u)" title="Remove"><Trash2 :size="15" /></button>
              </div>
            </div>
            <div class="team-card-body">
              <span class="role-pill" :style="`background:${roleInfo(u.role).bg}; color:${roleInfo(u.role).color}; border-color:${roleInfo(u.role).color}22`">
                <Shield v-if="u.role==='facility_admin'" :size="11" />
                <UserCog v-else-if="u.role==='senior_employee'" :size="11" />
                <User v-else :size="11" />
                {{ roleInfo(u.role).label }}
              </span>
              <span v-if="u.status !== 'active'" class="status-pill status-inactive">Inactive</span>
            </div>
            <div class="team-card-footer">
              <div class="team-meta"><MapPin :size="12" /><span>{{ scopeSummary(u) }}</span></div>
              <div v-if="u.phone" class="team-meta"><span>{{ u.phone }}</span></div>
            </div>
          </div>
        </div>
        <div v-else class="card empty" style="text-align:center;padding:32px;">No team members match your filters.</div>
        <PaginationBar v-if="filteredTeam.length > 0" :page="teamPage" :perPage="teamPerPage" :totalPages="teamTotalPages" :total="filteredTeam.length" :visiblePages="teamVisiblePages" @update:perPage="teamPerPage=$event" @goTo="teamGoTo" />
      </template>

      <!-- Provider modal -->
      <div v-if="showModal" class="modal-backdrop" @click.self="requestCloseTeamModal">
        <div class="modal" v-dialog="requestCloseTeamModal" style="max-width:520px;" @input="markDirty" @change="markDirty">
          <div class="modal-header">
            <div class="modal-title">{{ editing ? 'Edit Member' : 'Invite Team Member' }}</div>
            <button class="btn-icon" @click="requestCloseTeamModal" aria-label="Close"><X :size="18" /></button>
          </div>
          <div class="modal-body">
            <div class="form-group" style="margin-bottom:20px;">
              <label class="form-label">Role</label>
              <RoleSelect v-model="teamForm.role" :roles="ASSIGNABLE_TEAM_ROLES" />
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Full name <span class="required">*</span></label><input aria-label="Full name" v-model="teamForm.full_name" :class="{ 'input-invalid': triedSave && !teamForm.full_name }" placeholder="Jane Smith" /></div>
              <div class="form-group"><label class="form-label">Email <span class="required">*</span></label><input aria-label="Email" v-model="teamForm.email" type="email" :class="{ 'input-invalid': triedSave && !teamForm.email }" placeholder="jane@example.com" /></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Phone</label><input aria-label="Phone" v-model="teamForm.phone" placeholder="+971 50 123 4567" /></div>
              <div class="form-group"><label class="form-label">Status</label>
                <select aria-label="Team member status" v-model="teamForm.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
              </div>
            </div>
            <!-- Per-user permissions + location/listing/program scope -->
            <PermissionScopeEditor
              :role="teamForm.role"
              v-model:permissions="teamForm.custom_permissions"
              v-model:locationIds="teamForm.assigned_location_ids"
              v-model:listingIds="teamForm.assigned_listing_ids"
              v-model:programIds="teamForm.assigned_program_ids"
              :available-locations="editorLocations"
              :available-listings="editorListings"
              :available-programs="editorPrograms"
            />
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="requestCloseTeamModal">Cancel</button>
            <button class="btn btn-primary" :disabled="!teamForm.full_name || !teamForm.email || saving" @click="saveTeam">{{ editing ? 'Save changes' : 'Invite member' }}</button>
          </div>
        </div>
      </div>
    </template>

  </div>
</template>

<style scoped>
.biz-assign-btn { white-space: nowrap; margin-right: 4px; }
@media (max-width: 760px) { .biz-assign-btn { font-size: 12px; padding: 6px 10px; } }
.biz-owner { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--border); }
.biz-owner-notice { color: var(--warning-ink, #92400e); }
.biz-owner-lead { margin: 0 0 10px; }
.biz-owner-role { margin-top: 12px; }
.biz-owner-warn { display: flex; align-items: flex-start; gap: 6px; margin-top: 6px; line-height: 1.5; }
.biz-owner-ack { display: flex; align-items: flex-start; gap: 8px; margin-top: 14px; font-size: 13px; line-height: 1.5; text-align: left; }
.biz-owner-ack input { margin-top: 2px; flex-shrink: 0; }
.biz-review-sub { color: var(--text-muted); font-size: 12.5px; }
/* Role explanation panel in the create/edit account form */
.role-explain { margin-top: 10px; padding: 10px 12px; border-left: 3px solid var(--border); background: var(--surface-hover, #f8fafc); border-radius: 0 8px 8px 0; }
.role-explain-head { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.role-explain-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.role-explain-list li { font-size: 12.5px; line-height: 1.4; }
.re-can { color: var(--positive-ink, #15803d); }
.re-cant { color: var(--danger-ink, #b91c1c); }

.cell-main { display: flex; align-items: center; gap: 10px; }
.avatar-sm { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #0f766e, #14b8a6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 12px; }
.mini-avatar { width: 34px; height: 34px; border-radius: 8px; background: var(--primary-soft, #ccfbf1); color: var(--primary-ink); display: flex; align-items: center; justify-content: center; }
.row-actions { display: flex; gap: 4px; }
.btn-icon { padding: 7px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: var(--danger-bg, #fee2e2); color: var(--error); }
.inline-business { margin: 12px 0; padding: 14px; border: 1px dashed var(--border); border-radius: 10px; background: var(--surface-hover, #f8fafc); }
.biz-country-note { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--text-muted); background: var(--info-bg, #f0f9ff); border: 1px solid var(--info-border, #bae6fd); border-radius: 8px; padding: 9px 11px; margin-bottom: 10px; }
.biz-country-note svg { flex-shrink: 0; margin-top: 1px; color: var(--info-ink, #0284c7); }
.inline-business-header { font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.username-input-wrap { position: relative; }
.username-prefix { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; align-items: center; pointer-events: none; }
.input-locked { background: var(--bg, #f1f5f9); color: var(--text-muted); cursor: not-allowed; }
.field-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.tm-section-title { display: flex; align-items: center; gap: 10px; margin: 4px 0 12px; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
.tm-section-title::after { content: ""; flex: 1; height: 1px; background: var(--border); }
.form-group + .tm-section-title, .grid + .tm-section-title { margin-top: 8px; }
.tm-company-row { align-items: start; }
.perm-editor-wrap { margin-top: 4px; }
.required { color: var(--danger-ink, #dc2626); }
.form-error { margin-top: 12px; padding: 10px 14px; background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); border-radius: 8px; color: var(--danger-ink, #991b1b); font-size: 13px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.filter-select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); font-size: 13px; color: var(--text); cursor: pointer; }
.role-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
.role-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
.role-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.role-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.role-card-count { font-size: 28px; font-weight: 800; line-height: 1; }
.role-card-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.role-card-desc { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
.team-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 64px 24px; text-align: center; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; }
.team-empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.team-empty-sub { font-size: 13px; color: var(--text-muted); max-width: 380px; }
.team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.team-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 12px; transition: box-shadow 0.15s; }
.team-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.team-card-header { display: flex; align-items: center; gap: 12px; }
.team-avatar { width: 40px; height: 40px; border-radius: 50%; color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.team-card-info { flex: 1; min-width: 0; }
.team-name { font-size: 14px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-email { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-card-menu { display: flex; gap: 4px; flex-shrink: 0; }
.team-card-body { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.role-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid; }
.status-pill { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.status-inactive { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #991b1b); border: 1px solid var(--danger-border, #fecaca); }
.team-card-footer { display: flex; flex-direction: column; gap: 4px; padding-top: 8px; border-top: 1px solid var(--border); }
.team-meta { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); }
.role-selector { display: flex; flex-direction: column; gap: 8px; }
.role-option { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s; }
.role-option:hover { border-color: var(--primary); }
.role-option.selected { border-color: var(--primary); background: var(--info-bg, #f0f9ff); }
.role-option-icon { width: 34px; height: 34px; border-radius: 8px; background: var(--bg, #f1f5f9); color: var(--text-muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; }
.role-option-label { font-size: 13.5px; font-weight: 700; color: var(--text); }
.role-option-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.perm-summary { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
.perm-summary-title { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
.perm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.perm-item { font-size: 12.5px; padding-left: 18px; position: relative; }
.perm-item::before { content: ''; position: absolute; left: 0; top: 3px; width: 10px; height: 10px; border-radius: 50%; }
.perm-item.yes { color: var(--positive-ink, #065f46); }
.perm-item.yes::before { background: #059669; }
.perm-item.no { color: var(--text-light, #94a3b8); }
.perm-item.no::before { background: #cbd5e1; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--border); }

/* ── Grouped super-admin layout ─────────────────────────────── */
.section-block { margin-bottom: 28px; }
.section-label {
  display: flex; align-items: center; gap: 7px;
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
  color: var(--text-muted); margin-bottom: 10px;
}
.section-count {
  background: var(--border); color: var(--text-muted); border-radius: 999px;
  padding: 1px 7px; font-size: 11px; font-weight: 600;
}

/* Business group card */
.biz-group {
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  margin-bottom: 10px; overflow: hidden; transition: box-shadow 0.15s;
}
.biz-group:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }

.biz-header {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px; cursor: pointer; user-select: none;
}
.biz-header:hover { background: var(--surface-hover, #fafafa); }

.biz-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--primary-soft, #ccfbf1); color: var(--primary-ink);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.biz-name-block { min-width: 160px; }
.biz-name { font-weight: 700; font-size: 14px; color: var(--text); }
.biz-username { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.biz-owner-email { font-size: 13px; color: var(--text-muted); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.biz-badges { display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }
.biz-type-chip { display: inline-flex; align-items: center; font-size: 10.5px; font-weight: 700; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 999px; padding: 2px 8px; white-space: nowrap; }
.biz-plan-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; letter-spacing: .02em; color: var(--text-muted, #64748b); background: var(--bg, #f1f5f9); border: 1px solid var(--border, #e2e8f0); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
.biz-plan-chip.premium { color: var(--warning-ink, #b45309); background: var(--warning-bg, #fef3c7); border-color: var(--warning-border, #fde68a); }
.btn-icon.plan-up { color: var(--warning-ink, #d97706); }
.btn-icon.plan-up:hover { background: var(--warning-bg, #fef3c7); }
.btn-icon.plan-down { color: var(--text-light, #94a3b8); }
.btn-icon.plan-down:hover { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }
.biz-staff-count {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; color: var(--text-muted); background: var(--bg);
  border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px;
}
.biz-country-pill {
  font-size: 11px; color: var(--info-ink, #0369a1); background: var(--info-bg, #e0f2fe);
  border: 1px solid var(--info-border, #bae6fd); border-radius: 999px; padding: 2px 8px; font-weight: 600;
}

.biz-actions { display: flex; gap: 4px; flex-shrink: 0; }
.biz-chevron { color: var(--text-muted); transition: transform 0.2s; flex-shrink: 0; display: flex; }
.biz-chevron.open { transform: rotate(180deg); }

/* Staff sub-table */
.staff-section { border-top: 1px solid var(--border); background: var(--surface-hover, #fafafa); }
.staff-table { width: 100%; border-collapse: collapse; }
.staff-table th {
  padding: 9px 16px 9px 20px; font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
  background: var(--bg, #f1f5f9); border-bottom: 1px solid var(--border); text-align: left;
}
.staff-table td { padding: 11px 16px 11px 20px; border-bottom: 1px solid var(--border); font-size: 13px; }
.staff-table tbody tr:last-child td { border-bottom: none; }
.staff-table tbody tr:hover td { background: var(--surface-hover, #f8fafc); }
.avatar-sm.sm { width: 28px; height: 28px; font-size: 10px; }
.empty-staff { text-align: center; color: var(--text-muted); font-size: 13px; padding: 20px; }

.staff-add-row {
  padding: 10px 20px 14px;
  border-top: 1px dashed var(--border);
  background: var(--surface-hover, #f8fafc);
}
.btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 7px; display: inline-flex; align-items: center; gap: 5px; }

/* ── Phone (≤600px) ─────────────────────────────────────────── */
@media (max-width: 600px) {
  /* Business header row: wrap instead of clipping actions/chevron off-screen */
  .biz-header { flex-wrap: wrap; padding: 12px 14px; row-gap: 8px; }
  .biz-name-block { min-width: 0; flex: 1; order: 1; }
  .biz-actions { order: 2; margin-left: auto; }
  .biz-chevron { order: 3; }
  .biz-name { white-space: normal; overflow-wrap: anywhere; }
  .biz-owner-email { flex-basis: 100%; order: 5; }
  .biz-badges { flex-basis: 100%; order: 6; }

  /* Expanded staff table: scroll inside its own container (table-wrap convention) */
  .staff-section { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .staff-table { min-width: 620px; }

  /* Match global mobile input convention (16px prevents iOS zoom) */
  .filter-select { font-size: 16px; padding: 10px; }

  /* Align bottom-sheet footer spacing with global sheet rules */
  .modal-footer { padding: 14px 18px; gap: 8px; }
}
@media (max-width: 1200px) { .role-cards { grid-template-columns: repeat(2,minmax(0,1fr)); } }
.role-card-icon { background: var(--primary-soft); color: var(--primary-ink); }
.role-card-count { color: var(--text); }
@media (max-width: 380px) { .role-cards { grid-template-columns: minmax(0,1fr); } }
</style>
