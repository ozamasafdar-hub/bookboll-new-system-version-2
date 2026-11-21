/**
 * jurisdiction.js — shared jurisdiction scoping for platform roles.
 *
 * country_admin and platform_staff are platform-side roles that must only see/edit
 * data inside their assigned countries (platform_staff may additionally be pinned
 * to specific venues via assigned_location_ids). This composable mirrors the
 * canonical Dashboard.vue implementation (jurisdictionCountryIds /
 * platformLocationIds / providerInJurisdiction) so every page scopes identically.
 *
 * Provider-side roles are scoped by ownership (owner_user_id + scopeLocations),
 * NOT by jurisdiction — for them allowedLocationIds stays null and the helpers
 * always pass, exactly like Dashboard only applies its platform scope in the
 * platform view mode.
 */
import { ref, computed } from 'vue'
import { loadReferenceData } from './referenceData.js'
import { role, isSuperAdmin, isCountryAdmin, assignedCountryIds, assignedLocationIds, currentCountryId } from './currentUser.js'

/**
 * @param {object}  refs
 * @param {import('vue').Ref<Array>} refs.locations  — reactive locations list (id, city_id, owner_user_id). Required for allowedLocationIds.
 * @param {import('vue').Ref<Array>} [refs.cities]   — reactive cities list (id, country_id). Fetched internally when omitted.
 * @param {import('vue').Ref<Array>} [refs.providers]— reactive provider_admin users (id, country_id) for inJurisdictionOwner's country fallback.
 */
export function useJurisdiction({ locations, cities, providers } = {}) {
  const locList = locations || ref([])
  const provList = providers || ref([])
  // Cities only map location → country; pages that don't load them get an internal fetch.
  let cityList = cities
  if (!cityList) {
    const fetched = ref([])
    // reference-data is already scoped to the caller and carries every city they
    // can see, so pages that don't load cities themselves get them from there.
    loadReferenceData().then(({ cities: rows }) => { fetched.value = rows || [] })
    cityList = fetched
  }

  const isPlatformRole = computed(() =>
    isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')
  const isGlobalAdmin = computed(() => isSuperAdmin.value)

  function locCountry(locId) {
    const l = locList.value.find(x => x.id === locId)
    const c = l && cityList.value.find(ci => ci.id === l.city_id)
    return c?.country_id || null
  }

  // Countries the user administers. null = all (super admin); Set otherwise
  // (assigned_country_ids, falling back to the user's own country).
  const jurisdictionCountryIds = computed(() => {
    if (isSuperAdmin.value) return null
    const assigned = (assignedCountryIds.value || []).filter(Boolean)
    if (assigned.length) return new Set(assigned)
    return currentCountryId.value ? new Set([currentCountryId.value]) : new Set()
  })

  // Locations a platform user may see (null = all/super or a provider-side role).
  // Platform staff with assigned venues are pinned to those; otherwise the country
  // scope applies.
  const allowedLocationIds = computed(() => {
    if (isSuperAdmin.value || !isPlatformRole.value) return null
    if (role.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
      return new Set(locList.value.filter(l => assignedLocationIds.value.includes(Number(l.id))).map(l => l.id))
    }
    const set = jurisdictionCountryIds.value
    if (!set) return null
    return new Set(locList.value.filter(l => set.has(locCountry(l.id))).map(l => l.id))
  })

  /** Is this venue inside the user's jurisdiction? (true when unrestricted) */
  function inJurisdictionLoc(locationId) {
    const set = allowedLocationIds.value
    return set ? set.has(locationId) : true
  }

  /** Does this owner (provider) fall inside the user's jurisdiction — via one of
   *  their venues, or via the owner's own country? (true when unrestricted) */
  function inJurisdictionOwner(ownerId, providersOrUsers) {
    if (isSuperAdmin.value || !isPlatformRole.value) return true
    const locSet = allowedLocationIds.value
    if (locSet && locList.value.some(l => l.owner_user_id === ownerId && locSet.has(l.id))) return true
    const set = jurisdictionCountryIds.value
    const list = providersOrUsers ? (providersOrUsers.value ?? providersOrUsers) : provList.value
    const c = list.find(p => p.id === ownerId)?.country_id
    return set ? (c ? set.has(c) : false) : true
  }

  /** Narrow COUNTRY filter options to the jurisdiction. Platform roles only —
   *  super admin and provider-side users get the list untouched. Keeps a
   *  country_admin's filter dropdowns from advertising every other country. */
  function scopeCountries(list) {
    if (isSuperAdmin.value || !isPlatformRole.value) return list || []
    const set = jurisdictionCountryIds.value
    return set ? (list || []).filter(c => set.has(c.id)) : (list || [])
  }
  /** Same for CITY options (cities carry country_id). */
  function scopeCities(list) {
    if (isSuperAdmin.value || !isPlatformRole.value) return list || []
    const set = jurisdictionCountryIds.value
    return set ? (list || []).filter(ci => set.has(ci.country_id)) : (list || [])
  }

  return {
    isPlatformRole,
    isGlobalAdmin,
    jurisdictionCountryIds,
    allowedLocationIds,
    inJurisdictionLoc,
    inJurisdictionOwner,
    locCountry,
    scopeCountries,
    scopeCities,
  }
}
