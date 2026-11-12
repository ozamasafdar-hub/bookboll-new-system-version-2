// ── Promocodes: checkout validation + discount preview ────────────────────────
// Validation is SERVER-SIDE: POST /promocodes/apply runs the full rule set (status,
// date window, usage limits, per-customer limits, audience, venue/listing scope,
// min order) and returns the discount it would apply.
//
// This is a PREVIEW only. The booking request sends the promo CODE, and Laravel
// re-validates and applies the discount itself when creating the booking
// (BookingService::prepareCustomerCheckout), which is also where usage_count is
// incremented — so there is nothing to record from the client.
import { apiFetch } from './api.js'

/** Discount this code yields on `amount` (never more than the order). */
export function computeDiscount(code, amount) {
  const amt = Number(amount) || 0
  if (!code || amt <= 0) return 0
  let d = code.discount_type === 'percentage'
    ? amt * (Number(code.discount_value) || 0) / 100
    : (Number(code.discount_value) || 0)
  d = Math.min(d, amt)
  return Math.max(0, Math.round(d * 100) / 100)
}

/** Quick "is this code currently usable at all" (no per-customer/scope context). */
export function isCodeLive(code, now = new Date()) {
  if (!code || code.status !== 'active') return false
  const today = now.toISOString().slice(0, 10)
  if (code.valid_from && today < code.valid_from) return false
  if (code.valid_to && today > code.valid_to) return false
  if (code.usage_limit && Number(code.usage_count || 0) >= Number(code.usage_limit)) return false
  return true
}

export const AUDIENCE_LABEL = { all: 'Everyone', new: 'New customers', specific: 'Specific customers' }

/**
 * Validate a code for a checkout against Laravel.
 * ctx: { amount, listingId, locationId, paymentMethod }
 *   → { ok:true, code, discount } | { ok:false, reason }
 * The returned `code` keeps the `{ code: 'ABC' }` shape callers already read.
 */
export async function validatePromo(rawCode, ctx = {}) {
  const code = (rawCode || '').trim().toUpperCase()
  if (!code) return { ok: false, reason: 'Enter a promo code.' }

  const res = await apiFetch('/promocodes/apply', {
    method: 'POST',
    body: {
      promocode: code,
      order_amount: Number(ctx.amount) || 0,
      venue_id: ctx.locationId ?? undefined,
      listing_id: ctx.listingId ?? undefined,
      payment_method: ctx.paymentMethod ?? undefined,
      surface: ctx.surface || 'marketplace',
      quantity: ctx.bookingCount || 1,
      applicable_to: ctx.applicableTo || 'facility',
      slots: ctx.slots,
      add_ons: ctx.addOns,
    },
  })

  if (!res.success) return { ok: false, reason: res.message || 'Invalid promo code.' }

  const p = res.data?.promocode
  const discount = Number(p?.discount_amount) || 0
  if (discount <= 0) return { ok: false, reason: 'This code gives no discount on this order.' }

  return { ok: true, code: { code: p?.code || code, discount: p?.discount || '' }, discount, platformFunded: Number(p?.platform_funded_amount) || 0, platformFee: p?.platform_fee == null ? null : Number(p.platform_fee) }
}

/** @deprecated No-op. Laravel records the redemption and bumps usage_count itself
 *  when the booking is created (Promocode::incrementUsage). Kept so the callers that
 *  still reference it keep building; it used to write to Supabase and reached nothing. */
export async function recordRedemption() {
  /* server-side */
}
