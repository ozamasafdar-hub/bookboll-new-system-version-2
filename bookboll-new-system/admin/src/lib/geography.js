/**
 * Countries & Cities — Laravel geography API (World package + commission rates).
 */
import { apiFetch } from './api.js'

export async function fetchGeographyCountries(query = {}) {
  return apiFetch('/admin/geography/countries', { query })
}

export async function fetchGeographyCities(countryId, search = '') {
  return apiFetch('/admin/geography/cities', {
    query: {
      country_id: countryId,
      search: search || undefined,
    },
  })
}

export async function updateCountryStatus(id, status) {
  return apiFetch(`/admin/geography/countries/${id}/status`, {
    method: 'PATCH',
    body: { status },
  })
}

export async function updateCityStatus(id, status) {
  return apiFetch(`/admin/geography/cities/${id}/status`, {
    method: 'PATCH',
    body: { status },
  })
}

export async function bulkUpdateCityStatus(countryId, status, cityIds = null) {
  return apiFetch('/admin/geography/cities/bulk-status', {
    method: 'POST',
    body: {
      country_id: countryId,
      status,
      city_ids: cityIds,
    },
  })
}

export async function saveCommissionRate(countryId, listingType, ratePercent) {
  return apiFetch('/admin/geography/commission-rates', {
    method: 'PUT',
    body: {
      country_id: countryId,
      listing_type: listingType,
      rate_percent: ratePercent,
    },
  })
}

/**
 * Turn one service module on or off for a city.
 * Disabling stops NEW listings/programs/events being created there; anything
 * already published keeps working.
 */
export async function updateCityModule(id, module, enabled) {
  return apiFetch(`/admin/geography/cities/${id}/module`, {
    method: 'PATCH',
    body: { module, enabled },
  })
}

export function loadMarketSettings(scope, id = 0) {
  return apiFetch(`/admin/geography/settings/${scope}/${id}`)
}
export function saveMarketSettings(scope, id, values) {
  return apiFetch(`/admin/geography/settings/${scope}/${id}`, { method: 'PUT', body: { values } })
}
export function resetCountryRewards(countryId) {
  return apiFetch('/admin/geography/loyalty-programs', { method: 'PUT', body: { country_id: countryId, use_global: true } })
}
