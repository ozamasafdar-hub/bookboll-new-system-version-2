/**
 * premium.js — Bookboll for Business PREMIUM plan (free vs premium providers).
 *
 * The plan lives on the provider's BUSINESS row, so every team member of a premium
 * business inherits it. Platform roles (super admin, country admin, platform staff)
 * are NEVER gated.
 *
 * Usage in a page:
 *   const { isPremium, requirePremium } = usePremium()
 *   <button @click="requirePremium('exports') && doExport()">
 * requirePremium() returns true when allowed; otherwise it opens the global Upgrade
 * modal (components/UpgradeModal.vue, mounted once in App.vue) and returns false.
 *
 * The subscription is REAL: subscribing opens Stripe Checkout ($588/year with no trial) and the plan only flips when Stripe confirms it. The gates here
 * explain the paywall; the backend enforces it, so a free provider that skips the UI
 * and posts straight to the API is refused with 402 all the same.
 */
import { ref, computed } from 'vue'
import { useCurrentUser } from './currentUser.js'
import { pushToast } from './toast.js'
import { fetchPlan, startPlanCheckout, confirmPlanCheckout, cancelPlan, resumePlan } from './plan.js'

// Premium is sold annually. Laravel must enforce the no-trial policy (readmeAlamgir.md).
export const PREMIUM_YEARLY_TOTAL = 588
export function billedAmount() { return PREMIUM_YEARLY_TOTAL }

export const PREMIUM_FEATURES = [
  { key: 'website_commission', label: 'Premium own-website fee rate', desc: 'Your market’s Premium booking fee applies during an active paid subscription. Check the rate in your listing’s payment settings. Marketplace rates stay the same.' },
  { key: 'blog',       label: 'Website blog',       desc: 'Publish posts on your own site — great for Google.' },
  { key: 'marketing',  label: 'Marketing emails',   desc: 'Email campaigns to your opted-in customers.' },
  { key: 'exports',    label: 'Data exports',       desc: 'Download bookings, customers, payments & more as CSV.' },
  { key: 'promocodes', label: 'Promo codes',        desc: 'Discount codes with targeting & usage limits.' },
  { key: 'banners',    label: 'Custom home banners', desc: 'Your own hero slides with buttons & images.' },
  { key: 'widgets',    label: 'Embed & widgets',    desc: 'Booking widgets and buttons for your existing website.' },
  { key: 'whitelabel', label: 'White-label website', desc: 'Remove the Bookboll badge from your site — only a small footer credit remains.' },
  { key: 'own_site_only', label: 'Off-marketplace listings', desc: 'Hide a listing or program from the Bookboll Marketplace and sell it only on your own website.' },
]

// Global upgrade-modal state (rendered once by components/UpgradeModal.vue).
export const upgradeModal = ref({ open: false, feature: '' })
export function openUpgrade(featureKey = '') {
  upgradeModal.value = { open: true, feature: featureKey }
}

/**
 * The plan as the SERVER sees it — the only thing the gates trust. Shared across
 * every page so one fetch serves the whole shell.
 */
export const planState = ref({
  plan: 'free', is_premium: false, is_platform: false,
  billing: null, status: null, premium_since: null,
  trial_ends_at: null, renews_at: null, cancel_at_period_end: false, has_subscription: false,
  domain: { domain: '', status: '', paid_at: null, fee: 119, pending: '' },
  loaded: false,
})

let inflight = null

/** Fetch the plan once; later callers share the same request. */
export async function loadPlan({ force = false } = {}) {
  if (!force && (planState.value.loaded || inflight)) return inflight || planState.value
  inflight = (async () => {
    const res = await fetchPlan()
    if (res.success && res.data) {
      planState.value = { ...planState.value, ...res.data, loaded: true }
    } else {
      // A failed read must not silently grant Premium — stay on the free side.
      planState.value = { ...planState.value, loaded: true }
    }
    inflight = null
    return planState.value
  })()
  return inflight
}

/** Called by every gated page's setup(); a no-op once the plan is in hand. */
export function ensurePlan() {
  if (!planState.value.loaded && !inflight) loadPlan()
}

/** Drop the cached plan — used when the acting account changes, and after sign-out. */
export function resetPlan() {
  inflight = null
  planState.value = { ...planState.value, plan: 'free', is_premium: false, is_platform: false, status: null, own_site_commission_discount_eligible: undefined, loaded: false }
}

export function usePremium() {
  const { currentUser, allUsers, currentOwnerUserId } = useCurrentUser()

  ensurePlan()

  // The business OWNER's row — who the plan and the promo redemption belong to.
  const ownerRow = computed(() => {
    const oid = currentOwnerUserId.value
    if (!oid) return null
    if (currentUser.value?.id === oid) return currentUser.value
    return (allUsers.value || []).find(u => u.id === oid) || null
  })

  const isPlatform = computed(() => !!planState.value.is_platform)
  const isPremium = computed(() => !!planState.value.is_premium)

  /** True when the feature may be used; otherwise opens the Upgrade modal and returns false. */
  function requirePremium(featureKey = '') {
    if (isPremium.value) return true
    openUpgrade(featureKey)
    return false
  }

  const upgrading = ref(false)
  /**
   * Start a REAL upgrade: the server prices the plan, re-checks the promo code and
   * hands back a Stripe Checkout URL. We hand the browser over — the plan flips when
   * Stripe says it did, never here.
   *
   * `promo` (optional) is a validated provider-plan code — `{ code, discount }` from
   * validatePromo({ applicableTo: 'provider_plan' }). Only its code string is sent;
   * the discount is recomputed server-side so it cannot be dictated by the browser.
   */
  async function subscribePremium(promo = null) {
    if (upgrading.value) return false
    upgrading.value = true
    const res = await startPlanCheckout({
      billing: 'yearly',
      promoCode: promo?.code?.code || null,
      returnPath: window.location.pathname + window.location.search,
    })
    if (!res.success || !res.data?.url) {
      upgrading.value = false
      pushToast(firstPlanError(res, 'Could not start the upgrade.'), 'error')
      return false
    }
    // Leaving the app — keep `upgrading` on so the button stays disabled until we go.
    window.location.assign(res.data.url)
    return true
  }

  return { isPremium, isPlatform, requirePremium, subscribePremium, upgrading, ownerRow, planState }
}

/**
 * Finish a Premium checkout the provider was just redirected back from.
 * Returns true when the plan is now active.
 */
export async function completePlanCheckout(sessionId) {
  if (!sessionId || sessionId === 'cancelled') {
    if (sessionId === 'cancelled') pushToast('Upgrade cancelled — nothing was charged.', 'info')
    return false
  }
  const res = await confirmPlanCheckout(sessionId)
  if (!res.success) {
    pushToast(firstPlanError(res, 'We could not confirm that payment yet.'), 'error')
    return false
  }
  planState.value = { ...planState.value, ...res.data, loaded: true }
  upgradeModal.value = { open: false, feature: '' }

  pushToast('Premium is on — everything is unlocked.', 'success')
  return true
}

/** Stop the plan at the end of the paid period (nothing is refunded or cut off early). */
export async function endPlan() {
  const res = await cancelPlan()
  if (!res.success) { pushToast(firstPlanError(res, 'Could not cancel the plan.'), 'error'); return false }
  planState.value = { ...planState.value, ...res.data, loaded: true }
  pushToast('Premium will end when your current period closes.', 'success')
  return true
}

/** Undo a pending cancellation. */
export async function keepPlan() {
  const res = await resumePlan()
  if (!res.success) { pushToast(firstPlanError(res, 'Could not resume the plan.'), 'error'); return false }
  planState.value = { ...planState.value, ...res.data, loaded: true }
  pushToast('Premium resumed — nothing changes.', 'success')
  return true
}

/** The clearest message a failed plan call gave us. */
export function firstPlanError(res, fallback) {
  const errors = res?.errors || {}
  const first = Object.values(errors).flat().find(v => typeof v === 'string')
  return first || res?.message || fallback
}
