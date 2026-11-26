import { toRaw } from 'vue'
import { apiFetch } from '../lib/api.js'

// Keep click totals with the exact catalog response. Changing city or brand must
// never reuse a different catalog's popularity, and no per-browser score is used.
const metadata = new WeakMap()
const recentClicks = new Map()
const COOLDOWN_MS = 60_000
export const categoryKey = ({ category = 'sports', type = '' } = {}) => `${category}:${type}`

export function attachCategoryPopularity(listings, scope, data = {}) {
  const clicks = Object.create(null)
  const matchesScope = row => ['city_id', 'country_id', 'country_iso', 'brand_slug'].every(key =>
    row[key] == null || scope[key] != null && String(row[key]).toLowerCase() === String(scope[key]).toLowerCase())
  const scopeValid = !data.category_popularity_scope || matchesScope(data.category_popularity_scope)
  for (const row of Array.isArray(data.category_popularity) ? data.category_popularity : []) {
    if (!scopeValid || !row || !matchesScope(row) || typeof row.category !== 'string' || typeof row.type !== 'string') continue
    const count = Number(row.click_count)
    if (Number.isFinite(count) && count >= 0) clicks[categoryKey(row)] = count
  }
  const query = Object.fromEntries(['city_id', 'city', 'country_id', 'country_iso', 'brand_slug'].filter(key => scope[key] != null).map(key => [key, scope[key]]))
  metadata.set(toRaw(listings), { clicks, query, enabled: data.category_click_tracking_enabled === true })
  return listings
}

export function categoryClickCounts(listings) { return metadata.get(toRaw(listings))?.clicks || {} }

// Only an explicit user selection counts. Never track renders, query restoration,
// All Venues, active-category re-clicks or dashboard previews. Enable this endpoint
// only after the server advertises support in its catalog response.
export async function recordCategoryClick(listings, category, source, { preview = false } = {}) {
  const meta = metadata.get(toRaw(listings))
  if (preview || !meta?.enabled || !category || category.key === 'all' || !['home', 'catalog'].includes(source)) return false
  const key = categoryKey(category)
  if (!listings.some(item => categoryKey({ category: item.category || 'sports', type: item.sport || '' }) === key)) return false
  const dedupeKey = JSON.stringify([meta.query, key])
  const now = Date.now()
  if (recentClicks.has(dedupeKey) && now - recentClicks.get(dedupeKey) < COOLDOWN_MS) return false
  for (const [id, time] of recentClicks) if (now - time >= COOLDOWN_MS) recentClicks.delete(id)
  recentClicks.set(dedupeKey, now)
  try {
    const result = await apiFetch('/marketplace/category-clicks', { method: 'POST', body: { ...meta.query, category: category.category, type: category.type || '', source } })
    if ([404, 405].includes(result.status)) meta.enabled = false
    return result.success === true
  } catch { return false } // Analytics must never prevent browsing.
}
