/**
 * analytics.js — the dataset behind the Analytics dashboard (/dashboard).
 *
 * One call returns the raw collections the page charts from, already scoped:
 * a provider gets their own business, a platform admin gets everything their
 * jurisdiction covers. The KPI and chart maths stay in the page.
 */
import { apiFetch } from './api.js'

const EMPTY = {
  bookings: [], customers: [], providers: [], listings: [], locations: [],
  programs: [], wallets: [], withdrawals: [], cities: [], countries: [],
  subscriptions: [], subPayments: [], games: [], gameJoins: [],
}

export async function loadAnalytics({programsOnly=false}={}) {
  const res = await apiFetch('/admin/dashboard/analytics',{query:programsOnly ? {programs_only:1} : {}})
  if (!res.success) return { ...EMPTY, error: res.message || 'Analytics could not be loaded.' }
  const d = res.data || {}
  return {
    bookings: d.bookings || [],
    customers: d.customers || [],
    providers: d.providers || [],
    listings: d.listings || [],
    locations: d.locations || [],
    programs: d.programs || [],
    wallets: d.wallets || [],
    withdrawals: d.withdrawals || [],
    cities: d.cities || [],
    countries: d.countries || [],
    subscriptions: d.subscriptions || [],
    // The API already filters these to paid rows.
    subPayments: d.sub_payments || [],
    games: d.games || [],
    gameJoins: d.game_joins || [],
  }
}

/**
 * Server-computed activity totals, with the coverage statement that says
 * whether they are complete.
 *
 * loadAnalytics() above returns raw rows the page adds up itself, which can
 * only ever describe the rows it was sent — the server caps that list, so a
 * busy venue's totals quietly became "the most recent N". This asks the
 * database for the answer instead.
 *
 * A response that answers a DIFFERENT question is rejected rather than
 * displayed: filters change while a request is in flight, and painting a
 * stale total over the current selection is worse than showing nothing.
 */
export async function loadAnalyticsAggregates(filters = {}) {
  const res = await apiFetch('/admin/dashboard/analytics/aggregates', { query: filters })

  if (!res.success) {
    return { ok: false, error: res.message || 'Totals could not be loaded.' }
  }

  const d = res.data || {}

  // An older backend that does not know this contract must not be read as
  // though it answered.
  if (d.aggregates_version !== 1) {
    return { ok: false, error: 'This server does not provide complete totals yet.' }
  }
  if (!echoMatches(d.filters, filters)) {
    return { ok: false, stale: true, error: 'Those totals were for a different selection.' }
  }

  return {
    ok: true,
    asOf: d.as_of || null,
    coverage: d.coverage || null,
    sales: Array.isArray(d.sales) ? d.sales : [],
    bookings: d.bookings || null,
    providerSlotBlocks: Number(d.provider_slot_blocks) || 0,
    series: Array.isArray(d.series) ? d.series : [],
  }
}

/** Did the server answer the question we asked? Only the keys we sent count. */
function echoMatches(echo, asked) {
  if (!echo || typeof echo !== 'object') return false
  return Object.keys(asked || {}).every(key => {
    const sent = asked[key]
    if (sent === undefined || sent === null || sent === '') return true
    return String(echo[key] ?? '') === String(sent)
  })
}
