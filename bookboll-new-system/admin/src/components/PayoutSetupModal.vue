<script setup>
/**
 * PayoutSetupModal — "set up a payout method" gate. Shown when a provider tries to enable
 * venue full-online payment (purpose 'bookings') or create a membership program (purpose
 * 'subscription') without an active payout method on the matching wallet. The primary action
 * takes them straight to the right wallet to set it up.
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { Wallet, ArrowRight, X, ShieldCheck } from 'lucide-vue-next'
import { WALLET_PURPOSE_LABELS } from '../lib/walletSetup.js'
import { confirmDiscardIfDirty } from '../lib/unsavedGuard.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  purpose: { type: String, default: 'bookings' },   // 'bookings' | 'subscription'
  countryId: { type: String, default: '' },
  // The method was SUBMITTED but is still awaiting Bookboll approval (pending_review) —
  // same gate, different copy: nothing to set up, just waiting on the review.
  pending: { type: Boolean, default: false },
})
// 'fallback' = "use the online-deposit option instead" (bookings only) — the
// parent form turns the deposit option on; no payout method required for it.
const emit = defineEmits(['close', 'fallback'])
const router = useRouter()

const isBookings = computed(() => props.purpose === 'bookings')
const walletLabel = computed(() => WALLET_PURPOSE_LABELS[props.purpose] || 'wallet')
const title = computed(() => props.pending
  ? 'Payout method under review'
  : isBookings.value ? 'Full payment online needs a payout method' : 'Set up your Membership wallet')
const body = computed(() => props.pending
  ? `Your ${walletLabel.value} wallet's payout method has been submitted and is awaiting Bookboll approval. ${isBookings.value ? 'Full online payment unlocks' : 'You can create membership programs'} as soon as it's approved.`
  : isBookings.value
    ? 'The customer pays the full amount online. Bookboll deducts its commission and credits your share to your Bookings wallet, so a payout method is required.'
    : 'You must set up a payout method for your Membership wallet to create membership programs.')
const note = computed(() => props.pending
  ? 'Approval usually doesn’t take long — you can check its status in your wallet anytime.'
  : isBookings.value
    ? 'You can also use deposit + on arrival without connected payouts. Bookboll keeps the online deposit as its fee; you collect the remaining amount. Commission also applies when payouts are connected. Your own-website fee follows the Free or paid Premium rate for your venue’s market.'
    : 'Choose Stripe Connect for automatic payouts, or the Bookboll Wallet to hold a balance and withdraw.')

async function goSetup() {
  // This gate usually pops over a half-filled listing/program form — going to
  // the wallet abandons it, so ask about unsaved changes before navigating.
  if (!(await confirmDiscardIfDirty())) return
  // Each wallet purpose is its own page: /wallet/subscription · /wallet/bookings
  const path = '/wallet/' + (props.purpose === 'bookings' ? 'bookings' : 'subscription')
  router.push({ path, query: props.countryId ? { country: props.countryId } : {} })
  emit('close')
}
</script>

<template>
  <div v-if="open" class="psm-backdrop" @click.self="emit('close')">
    <div class="psm-card" v-dialog="() => emit('close')">
      <button class="psm-x" @click="emit('close')" aria-label="Close"><X :size="18" /></button>
      <div class="psm-ic"><Wallet :size="26" /></div>
      <h3 class="psm-title">{{ title }}</h3>
      <p class="psm-body">{{ body }}</p>
      <div class="psm-note"><ShieldCheck :size="15" /><span>{{ note }}</span></div>
      <div class="psm-actions">
        <button class="psm-btn" @click="goSetup">{{ pending ? 'View wallet status' : 'Set up payout method' }} <ArrowRight :size="16" /></button>
        <button v-if="isBookings && !pending" class="psm-btn-ghost" @click="emit('fallback'); emit('close')">Review deposit + on arrival instead</button>
        <button v-else class="psm-btn-ghost" @click="emit('close')">Not now</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.psm-backdrop { position: fixed; inset: 0; z-index: 4200; background: rgba(15,23,42,.55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 20px; }
.psm-card { position: relative; width: 100%; max-width: 440px; background: var(--surface, #ffffff); border-radius: 18px; padding: 28px 26px; box-shadow: 0 24px 60px rgba(0,0,0,.4); text-align: center; }
.psm-x { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; border: none; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.psm-x:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }
.psm-ic { width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 14px; background: color-mix(in srgb, var(--primary, #0f766e) 12%, #fff); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; }
.psm-title { font-size: 19px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 8px; }
.psm-body { font-size: 14px; color: var(--text-muted, #64748b); line-height: 1.55; margin: 0 0 14px; }
.psm-note { display: flex; align-items: flex-start; gap: 8px; text-align: left; font-size: 12.5px; line-height: 1.5; color: var(--positive-ink, #166534); background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 10px; padding: 10px 12px; }
.psm-note svg { flex-shrink: 0; margin-top: 1px; }
/* Stacked: the ghost option carries a full sentence, so each button gets its own row */
.psm-actions { display: flex; flex-direction: column; gap: 9px; margin-top: 20px; }
.psm-btn-ghost { width: 100%; padding: 12px; border-radius: 11px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font-weight: 700; font-size: 13.5px; cursor: pointer; }
.psm-btn { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 12px; border-radius: 11px; border: none; background: var(--primary, #0f766e); color: #fff; font-weight: 800; font-size: 14px; cursor: pointer; }
.psm-btn:hover { filter: brightness(.95); }

/* Phone: modal becomes a bottom sheet, footer buttons stack full-width (global convention) */
@media (max-width: 600px) {
  .psm-backdrop { align-items: flex-end; padding: 0; }
  .psm-card { max-width: none; border-radius: 18px 18px 0 0; padding: 24px 18px calc(18px + env(safe-area-inset-bottom)); }
  .psm-btn, .psm-btn-ghost { min-height: 46px; }
}
</style>
