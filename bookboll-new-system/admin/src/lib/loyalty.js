/**
 * loyalty.js — Bookboll loyalty points: config, earn/redeem math, ledger updates.
 *
 * Defaults (apply to ALL countries/currencies unless a super admin edits the
 * per-country program in the dashboard → Countries page):
 *   • Earn:   1 point per 1 unit spent.
 *   • Redeem: 100 points = 1 unit off (redeem_rate 100).
 *
 * Redemption is ALWAYS in whole 1-currency-unit steps — redeem_value is fixed at 1
 * for every country/currency, so the only knob is "points per 1 unit" (redeem_rate).
 * Redemption is funded ENTIRELY by Bookboll's platform fee — the provider's payout
 * is never reduced. So a redemption is always capped at the order's platform fee.
 * Loyalty is a Bookboll-marketplace feature only (not on provider white-label sites);
 * callers gate that with isWhiteLabel.
 *
 * Backed by Laravel: read /marketplace/loyalty-config (public, per country),
 * list + save via /admin/geography/loyalty-programs (super admin only).
 * Points are EARNED server-side on booking confirmation; there is no client-side
 * ledger write, and redeem-at-checkout is not supported by the API yet.
 */
import { apiFetch } from './api.js'

export const DEFAULT_LOYALTY = {
  name: 'Bookboll Rewards',
  earn_rate: 1, redeem_rate: 100, redeem_value: 1,
  min_redeem: 100, max_redeem_pct: 100, expiry_months: 12, is_active: true,
}

// Activity bonuses (flat, on top of any spend-based points) for games/events.
export const JOIN_BONUS = 50    // confirmed into a game/event
export const HOST_BONUS = 100   // published a game/tournament

export function normalizeLoyalty(p) {
  if (!p) return { ...DEFAULT_LOYALTY }
  return {
    id: p.id ?? null,
    country_id: p.country_id ?? null,
    name: (p.name || '').trim() || DEFAULT_LOYALTY.name,
    earn_rate: Number(p.earn_rate ?? DEFAULT_LOYALTY.earn_rate),
    redeem_rate: Number(p.redeem_rate) > 0 ? Number(p.redeem_rate) : DEFAULT_LOYALTY.redeem_rate,
    // Redemption is standardised at 1 currency unit per step for every currency —
    // any legacy value (e.g. a stored "5") is coerced to 1, so redeem_rate always
    // reads as "points for 1 unit off".
    redeem_value: 1,
    min_redeem: Math.max(0, Number(p.min_redeem ?? DEFAULT_LOYALTY.min_redeem)),
    max_redeem_pct: Number(p.max_redeem_pct ?? DEFAULT_LOYALTY.max_redeem_pct),
    expiry_months: Math.max(0, Number(p.expiry_months ?? DEFAULT_LOYALTY.expiry_months)),
    is_active: p.is_active !== false,
  }
}

/** Effective loyalty config for a country — the per-country row overrides the default.
 *  Never throws: a backend blip falls back to the platform defaults. */
export async function loadLoyaltyConfig(countryId) {
  if (!countryId) return { ...DEFAULT_LOYALTY }
  try {
    const res = await apiFetch('/marketplace/loyalty-config', { query: { country_id: countryId } })
    return res.success ? normalizeLoyalty(res.data?.loyalty) : { ...DEFAULT_LOYALTY }
  } catch { return { ...DEFAULT_LOYALTY } }
}

/** Every saved per-country program, keyed by country_id — for the Countries page.
 *  Countries without a row simply aren't in the map and fall back to DEFAULT_LOYALTY. */
export async function loadLoyaltyPrograms() {
  const res = await apiFetch('/admin/geography/loyalty-programs')
  if (!res.success) return { error: res.message || 'Could not load loyalty programs.', byCountry: {} }
  const byCountry = {}
  for (const row of res.data?.loyalty_programs || []) {
    byCountry[row.country_id] = normalizeLoyalty(row)
  }
  // Tier ladders come back alongside, keyed by program_id.
  return { ok: true, byCountry, tiers: res.data?.loyalty_tiers || [], defaults: normalizeLoyalty(res.data?.defaults) }
}

/** Create or update the program for one country. Returns { ok, program } | { error }. */
export async function saveLoyaltyProgram(countryId, form, tiers = null) {
  const res = await apiFetch('/admin/geography/loyalty-programs', {
    method: 'PUT',
    body: {
      country_id: countryId,
      name: (form.name || '').trim(),
      earn_rate: Number(form.earn_rate) || 0,
      redeem_rate: Math.max(1, Math.floor(Number(form.redeem_rate) || 1)),
      min_redeem: Math.max(0, Math.floor(Number(form.min_redeem) || 0)),
      max_redeem_pct: Math.min(100, Math.max(0, Math.floor(Number(form.max_redeem_pct) || 0))),
      expiry_months: Math.max(0, Math.floor(Number(form.expiry_months) || 0)),
      is_active: form.is_active !== false,
      // Omitted entirely when the caller passes no ladder, so a save that isn't
      // about tiers leaves them untouched.
      ...(Array.isArray(tiers) ? { tiers: tiers.filter(t => (t.name || '').trim()).map((t, i) => ({
        name: (t.name || '').trim(),
        threshold: Math.max(0, Number(t.threshold) || 0),
        multiplier: Math.max(0, Number(t.multiplier ?? 1)),
        color: t.color || '#92400e',
        bg_color: t.bg_color || '#fef3c7',
        sort_order: i,
      })) } : {}),
    },
  })
  if (!res.success) {
    const first = res.errors && Object.values(res.errors)[0]
    return { error: (Array.isArray(first) ? first[0] : first) || res.message || 'Could not save the loyalty program.' }
  }
  return {
    ok: true,
    program: normalizeLoyalty(res.data?.loyalty_program),
    tiers: res.data?.loyalty_tiers || null,
  }
}

/** Whole points earned for spending `amount`. */
export function earnedPoints(amount, cfg = DEFAULT_LOYALTY) {
  const c = normalizeLoyalty(cfg)
  return Math.max(0, Math.floor((Number(amount) || 0) * c.earn_rate))
}

/** Currency value of `points` (whole redeem-units only). */
export function pointsValue(points, cfg = DEFAULT_LOYALTY) {
  const c = normalizeLoyalty(cfg)
  return Math.floor((Number(points) || 0) / c.redeem_rate) * c.redeem_value
}

/**
 * The maximum a customer can redeem on this order.
 * @returns {{ points, amount }} amount in currency (whole redeem-units), points to spend.
 */
export function maxRedeemable({ points, platformFee, orderTotal, cfg = DEFAULT_LOYALTY }) {
  const c = normalizeLoyalty(cfg)
  if (!c.is_active) return { points: 0, amount: 0 }
  if ((Number(points) || 0) < c.min_redeem) return { points: 0, amount: 0 }
  let cap = pointsValue(points, c)                                  // value of their balance
  cap = Math.min(cap, Math.max(0, Number(platformFee) || 0))        // ← never more than the platform fee
  if (c.max_redeem_pct > 0) cap = Math.min(cap, (Number(orderTotal) || 0) * c.max_redeem_pct / 100)
  const units = Math.floor(cap / c.redeem_value)
  const amount = +(units * c.redeem_value).toFixed(2)
  const pts = units * c.redeem_rate
  if (pts < c.min_redeem || amount <= 0) return { points: 0, amount: 0 }
  return { points: pts, amount }
}

// ── Ledger writes ────────────────────────────────────────────────────────────
// These wrote to Supabase (customer_loyalty / loyalty_ledger) keyed by a Supabase
// customer UUID; under Laravel those ids don't match and the tables aren't the
// source of truth, so they are no-ops.
//
// Earning is server-side: BookingService stamps bookings.loyalty_points_earned
// from the country/global rate and tier multiplier and credits the ledger once on confirmation.
// Redeeming at checkout has no API parameter yet.

/** @deprecated No-op. Laravel awards points server-side on booking confirmation. */
export async function awardLoyalty() {
  return 0
}

/** @deprecated No-op. The API has no redeem-at-checkout support yet. */
export async function redeemLoyalty() {
  return 0
}

/** A customer's earning history (newest first). Served by /customer/loyalty. */
export async function loadLoyaltyLedger(_customerId, { limit = 40 } = {}) {
  try {
    const res = await apiFetch('/customer/loyalty')
    if (!res.success) return []
    return (Array.isArray(res.data?.ledger) ? res.data.ledger : []).slice(0, limit)
  } catch { return [] }
}
