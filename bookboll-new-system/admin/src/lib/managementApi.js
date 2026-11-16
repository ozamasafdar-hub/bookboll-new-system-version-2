import { apiFetch } from './api.js'

export async function managementRequest(path, options) {
  const result = await apiFetch(path, options)
  const detail = Object.values(result?.errors || {}).flat().find(value => typeof value === 'string')
  return { ok: !!result?.success, data: result?.data, message: detail || result?.message || 'The request could not be completed. Please try again.' }
}
