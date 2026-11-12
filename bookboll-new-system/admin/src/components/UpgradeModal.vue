<script setup>
/**
 * UpgradeModal — the global "go Premium" flow, opened by requirePremium() from any
 * gated button (lib/premium.js holds the state). Three clean steps:
 *   1) gate      — "Premium feature" prompt for the feature they just hit
 *   2) plans     — Free vs annual Premium comparison
 *   3) checkout  — annual price, included features and payment summary
 * Subscribe opens a REAL Stripe Checkout: the server prices the plan, re-checks any
 * promo code and returns the URL we redirect to. The plan flips only once Stripe
 * confirms it — App.vue picks the `?plan_checkout=` return up and finishes it.
 */
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue'
import {
  upgradeModal, PREMIUM_FEATURES, PREMIUM_YEARLY_TOTAL, usePremium,
} from '../lib/premium.js'
import { validatePromo } from '../lib/promocodes.js'
import { Crown, Check, X, Sparkles, Loader, Tag, ArrowRight, Gift, ShieldCheck, CreditCard, Lock } from 'lucide-vue-next'

const { subscribePremium, upgrading, isPremium, ownerRow } = usePremium()
const open = computed(() => upgradeModal.value.open)
const highlight = computed(() => upgradeModal.value.feature)
const featureLabel = computed(() => PREMIUM_FEATURES.find(f => f.key === highlight.value)?.label || 'This')

// Core platform (free plan) highlights.
const FREE_FEATURES = [
  'Free branded booking website',
  'Venue marketplace listings',
  'Court, venue & activity bookings',
  'Online payments & payout wallet',
  'One calendar + team accounts',
]

const step = ref('gate')            // 'gate' | 'plans' | 'checkout'
const dialog = ref(null)
let returnFocus = null, previousOverflow = ''
function releaseDialog() {
  document.body.style.overflow = previousOverflow
  if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true })
  returnFocus = null
}
watch(open, async o => {
  if (o) {
    returnFocus = document.activeElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    step.value = 'gate'; clearPromo()
    await nextTick()
    dialog.value?.querySelector('.ug-x')?.focus()
  } else releaseDialog()
})
watch(step, async () => { await nextTick(); dialog.value?.querySelector('.ug-title, .ug-co-plan')?.focus({ preventScroll: true }) })
onBeforeUnmount(() => { if (open.value) releaseDialog() })
function dialogKeydown(event) {
  if (event.key !== 'Tab') return
  const targets = [...dialog.value.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href]')].filter(el => el.getClientRects().length)
  const first = targets[0], last = targets.at(-1)
  if (event.shiftKey && (document.activeElement === first || !targets.includes(document.activeElement))) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}

const billed = computed(() => PREMIUM_YEARLY_TOTAL)
const finalBilled = computed(() => Math.max(0, billed.value - (promo.value?.discount || 0)))

// Promo code (optional, super-admin provider-plan codes).
const promoInput = ref('')
const checking = ref(false)
const promo = ref(null)
const promoError = ref('')
async function applyPromo() {
  promoError.value = ''
  const raw = promoInput.value.trim()
  if (!raw) return
  checking.value = true
  const res = await validatePromo(raw, {
    applicableTo: 'provider_plan', amount: billed.value,
    ownerUserId: ownerRow.value?.id || null, customerEmail: ownerRow.value?.email || '',
  })
  checking.value = false
  if (res.ok) { promo.value = { code: res.code, discount: res.discount }; promoError.value = '' }
  else { promo.value = null; promoError.value = res.reason || 'Invalid code.' }
}
function clearPromo() { promo.value = null; promoInput.value = ''; promoError.value = '' }

async function doSubscribe() {
  // On success the browser leaves for Stripe, so there is nothing to reset here —
  // the modal is closed by completePlanCheckout() when we come back.
  await subscribePremium(promo.value)
}
function close() { clearPromo(); step.value = 'gate'; upgradeModal.value = { open: false, feature: '' } }
</script>

<template>
  <div v-if="open" class="ug-backdrop" @click.self="close">
    <div ref="dialog" class="ug-card" :class="'step-' + step" role="dialog" aria-modal="true" aria-label="Bookboll Premium" @keydown="dialogKeydown" @keydown.esc.stop.prevent="close">
      <button class="ug-x" @click="close" aria-label="Close"><X :size="18" /></button>

      <!-- ── STEP 1 · Premium feature gate ─────────────────────────────── -->
      <template v-if="step === 'gate'">
        <div class="ug-ic"><Crown :size="26" /></div>
        <h3 class="ug-title" tabindex="-1">{{ highlight ? 'Premium feature' : 'Grow with Premium' }}</h3>
        <p v-if="highlight" class="ug-sub"><b>{{ featureLabel }}</b> is part of Bookboll Premium — unlock it and the full business toolkit.</p>
        <p v-else class="ug-sub">Lower your own-website booking commission with a paid plan and get more tools for your business.</p>
        <ul class="ug-gate-feats">
          <li v-for="ft in PREMIUM_FEATURES.slice(0, 4)" :key="ft.key" :class="{ hot: ft.key === highlight }">
            <span class="ug-feat-ic"><Check :size="12" /></span>{{ ft.label }}
            <span v-if="ft.key === highlight" class="ug-feat-tag">this one</span>
          </li>
        </ul>
        <p class="ug-sub"><strong>US${{ PREMIUM_YEARLY_TOTAL }}/year.</strong> Billed annually, starting when you subscribe.</p>
        <div class="ug-actions ug-actions-col">
          <button class="ug-btn" @click="step = 'plans'"><Sparkles :size="15" /> Compare plans</button>
          <button class="ug-btn-ghost" @click="close">Maybe later</button>
        </div>
      </template>

      <!-- ── STEP 2 · Choose your plan ─────────────────────────────────── -->
      <template v-else-if="step === 'plans'">
        <h3 class="ug-title" tabindex="-1">Choose your plan</h3>
        <p class="ug-sub">Keep the core tools on Free, or subscribe to Premium for a year.</p>

        <p class="ug-fee-note">Marketplace membership listings have a separate monthly fee per listing on every plan. Default USD 20/month; country and city rates may differ.</p>
        <div class="ug-plans">
          <!-- Free -->
          <div class="ug-plan">
            <div class="ug-plan-name">Free</div>
            <div class="ug-plan-price"><b>$0</b><span>forever</span></div>
            <div class="ug-plan-tag current">Your current plan</div>
            <p class="ug-fee-note">Standard booking commission on your website and the marketplace.</p>
            <ul class="ug-plan-list">
              <li v-for="f in FREE_FEATURES.slice(0, 5)" :key="f"><Check :size="13" /> {{ f }}</li>
            </ul>
            <button class="ug-plan-btn ghost" @click="close">Stay on Free</button>
          </div>
          <!-- Premium -->
          <div class="ug-plan premium">
            <div class="ug-plan-ribbon"><Crown :size="12" /> Recommended</div>
            <div class="ug-plan-name">Premium</div>
            <div class="ug-plan-price"><b>${{ PREMIUM_YEARLY_TOTAL }}</b><span>/year</span></div>
            <div class="ug-plan-tag">Billed once a year</div>
            <ul class="ug-plan-list">
              <li class="everything">Everything in Free, plus:</li>
              <li v-for="ft in PREMIUM_FEATURES.slice(0, 6)" :key="ft.key"><Check :size="13" /> {{ ft.label }}</li>
            </ul>
            <p class="ug-fee-note">Website commission is halved during your paid subscription. Marketplace rates stay the same.</p>
            <button class="ug-plan-btn" @click="step = 'checkout'"><Sparkles :size="14" /> Choose Premium</button>
          </div>
        </div>
      </template>

      <!-- ── STEP 3 · Checkout ─────────────────────────────────────────── -->
      <template v-else>
        <button class="ug-back" @click="step = 'plans'"><ArrowRight :size="15" class="ug-back-ic" /> Back to plans</button>
        <div class="ug-co">
          <!-- left: plan + what's included -->
          <div class="ug-co-main">
            <div class="ug-co-head">
              <div class="ug-ic sm"><Sparkles :size="18" /></div>
              <div>
                <div class="ug-co-eyebrow">Selected plan</div>
                <h3 class="ug-co-plan" tabindex="-1">Premium</h3>
              </div>
              <div class="ug-co-price">
                <b>${{ PREMIUM_YEARLY_TOTAL }}</b><span>/year</span>
                <div class="ug-co-annual">billed annually</div>
              </div>
            </div>


            <p class="ug-fee-note">Your website has separate Free and paid Premium rates. Check your listing’s payment settings for the applicable rate. Marketplace rates stay the same. Connecting payouts does not remove commission.</p>
            <div class="ug-co-inc-h"><ShieldCheck :size="15" /> What's included</div>
            <div class="ug-co-grid">
              <div v-for="ft in PREMIUM_FEATURES" :key="ft.key" class="ug-co-inc" :class="{ hot: ft.key === highlight }">
                <span class="ug-feat-ic"><Check :size="12" /></span>{{ ft.label }}
              </div>
            </div>
          </div>

          <!-- right: order summary and annual total -->
          <aside class="ug-co-side">
            <div class="ug-os-title"><CreditCard :size="16" /> Order summary</div>
            <div class="ug-os-row"><span>Plan</span><b>Premium</b></div>
            <div class="ug-os-row"><span>Billing</span><b>Annual subscription</b></div>

            <!-- promo -->
            <div class="ug-promo">
              <div v-if="!promo" class="ug-promo-row">
                <span class="ug-promo-ic"><Tag :size="14" /></span>
                <input v-model="promoInput" class="ug-promo-input" aria-label="Promo code (optional)" placeholder="Promo code (optional)" @keydown.enter.prevent="applyPromo" />
                <button class="ug-promo-btn" :disabled="checking || !promoInput.trim()" @click="applyPromo">{{ checking ? '…' : 'Apply' }}</button>
              </div>
              <div v-else class="ug-promo-ok"><Check :size="13" /> <b>{{ promo.code.code }}</b> — ${{ promo.discount }} off <button class="ug-promo-x" @click="clearPromo">remove</button></div>
              <div v-if="promoError" class="ug-promo-err">{{ promoError }}</div>
            </div>

            <div class="ug-trial-card">
              <div class="ug-trial-row"><span>Annual plan</span><b>US${{ billed.toLocaleString() }}</b></div>
              <div v-if="promo" class="ug-trial-row"><span>Promo discount</span><b>−US${{ promo.discount }}</b></div>
              <div class="ug-trial-due"><span>Due at checkout</span><b>US${{ finalBilled.toLocaleString() }}</b></div>
              <p class="ug-trial-note">Renews annually at US${{ PREMIUM_YEARLY_TOTAL }} unless cancelled. No free trial. Booking commissions apply separately. Confirm the final amount at checkout.</p>
            </div>

            <button class="ug-btn ug-co-cta" :disabled="upgrading || isPremium" @click="doSubscribe">
              <Loader v-if="upgrading" :size="15" class="ug-spin" /><CreditCard v-else :size="15" />
              {{ isPremium ? 'Already Premium' : upgrading ? 'Opening checkout…' : 'Continue to annual checkout' }}
            </button>
            <div class="ug-fine"><Lock :size="11" /> Secure checkout by Stripe · Cancel renewal anytime</div>
          </aside>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ug-backdrop { position: fixed; inset: 0; z-index: 4300; background: rgba(15,23,42,.55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
.ug-card { position: relative; width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 28px 26px 22px; box-shadow: 0 24px 60px rgba(0,0,0,.4); text-align: center; }
.ug-card.step-gate { max-width: 440px; }
.ug-card.step-plans { max-width: 680px; }
.ug-card.step-checkout { max-width: 860px; text-align: start; }
.ug-x { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; border: none; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 2; }
.ug-x:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }
.ug-ic { width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 12px; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; }
.ug-ic.sm { width: 44px; height: 44px; margin: 0; border-radius: 12px; flex-shrink: 0; }
.ug-title { font-size: 21px; font-weight: 900; color: var(--text, #0f172a); margin: 0 0 6px; }
.ug-sub { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0 0 16px; }

.ug-fee-note { color: var(--text-secondary); background: var(--bg); border-radius: 9px; padding: 12px; font-size: 12px; line-height: 1.6; text-align: start; margin: 12px 0; }

/* Gate feature mini-list */
.ug-gate-feats { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 7px; text-align: start; }
.ug-gate-feats li { display: flex; align-items: center; gap: 9px; font-size: 13.5px; font-weight: 700; color: var(--text-secondary, #334155); padding: 9px 12px; border: 1px solid var(--border, #f1f5f9); border-radius: 10px; }
.ug-gate-feats li.hot { border-color: var(--primary-border, #99f6e4); background: var(--primary-soft, #f0fdfa); }
.ug-feat-ic { width: 19px; height: 19px; border-radius: 50%; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ug-feat-tag { margin-inline-start: auto; font-size: 10px; font-weight: 800; text-transform: uppercase; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #ccfbf1); border-radius: 999px; padding: 2px 8px; }
.ug-trial-chip { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 800; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 999px; padding: 7px 14px; margin: 0 auto 16px; }

/* Buttons */
.ug-actions { display: flex; gap: 10px; }
.ug-actions-col { flex-direction: column; }
.ug-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 13px; border-radius: 12px; border: none; background: var(--primary); color: #fff; font-weight: 800; font-size: 14.5px; cursor: pointer; width: 100%; }
.ug-btn:disabled { opacity: .6; cursor: default; }
.ug-btn:hover:not(:disabled) { filter: brightness(.97); }
.ug-btn-ghost { padding: 12px; border-radius: 12px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font-weight: 700; font-size: 14px; cursor: pointer; width: 100%; }

/* Billing toggle */
.ug-toggle { display: flex; gap: 4px; background: var(--bg, #f1f5f9); border-radius: 12px; padding: 4px; margin-bottom: 16px; }
.ug-toggle-center { max-width: 320px; margin: 0 auto 18px; }
.ug-tg { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; border: none; background: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 13.5px; padding: 9px 10px; border-radius: 9px; cursor: pointer; }
.ug-tg.on { background: var(--surface, #ffffff); color: var(--text, #0f172a); box-shadow: 0 1px 3px rgba(15,23,42,.12); }
.ug-save { font-size: 10px; font-weight: 800; text-transform: uppercase; color: var(--positive-ink, #15803d); background: var(--positive-bg, #dcfce7); padding: 2px 7px; border-radius: 999px; }

/* Plan cards */
.ug-plans { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; text-align: start; }
.ug-plan { position: relative; border: 1.5px solid var(--border, #e2e8f0); border-radius: 16px; padding: 18px 16px; display: flex; flex-direction: column; }
.ug-plan.premium { border-color: #0d9488; box-shadow: 0 8px 26px rgba(13,148,136,.14); }
.ug-plan-ribbon { position: absolute; top: -11px; left: 16px; display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #fff; background: var(--primary); padding: 3px 10px; border-radius: 999px; }
.ug-plan-name { font-size: 17px; font-weight: 900; color: var(--text, #0f172a); }
.ug-plan-price { display: flex; align-items: baseline; gap: 3px; margin: 4px 0 2px; }
.ug-plan-price b { font-size: 30px; font-weight: 900; color: var(--text, #0f172a); line-height: 1; }
.ug-plan-price span { font-size: 14px; font-weight: 700; color: var(--text-muted, #64748b); }
.ug-plan-tag { font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 12px; }
.ug-plan-tag.current { color: var(--primary-ink, #0f766e); font-weight: 700; }
.ug-plan-list { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 7px; flex: 1; }
.ug-plan-list li { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; color: var(--text-secondary, #334155); }
.ug-plan-list li svg { color: var(--primary-ink, #0d9488); flex-shrink: 0; margin-top: 1px; }
.ug-plan-list li.everything { font-weight: 800; color: var(--text, #0f172a); }
.ug-plan-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 12px; border-radius: 11px; border: none; background: var(--primary); color: #fff; font-weight: 800; font-size: 14px; cursor: pointer; }
.ug-plan-btn.ghost { background: none; border: 1.5px solid var(--border, #e2e8f0); color: var(--text-secondary, #475569); }

/* Checkout */
.ug-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 700; font-size: 13px; cursor: pointer; padding: 0 0 12px; }
.ug-back-ic { transform: rotate(180deg); }
.ug-co { display: grid; grid-template-columns: 1fr 320px; gap: 18px; align-items: start; }
.ug-co-head { display: flex; align-items: center; gap: 12px; padding-bottom: 14px; border-bottom: 1px solid var(--border, #e2e8f0); margin-bottom: 14px; }
.ug-co-eyebrow { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); }
.ug-co-plan { font-size: 19px; font-weight: 900; color: var(--text, #0f172a); }
.ug-co-price { margin-inline-start: auto; text-align: end; }
.ug-co-price b { font-size: 26px; font-weight: 900; color: var(--text, #0f172a); }
.ug-co-price span { font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); }
.ug-co-annual { font-size: 11px; font-weight: 700; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border-radius: 999px; padding: 2px 8px; display: inline-block; margin-top: 2px; }
.ug-co-inc-h { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 10px; }
.ug-co-inc-h svg { color: var(--primary-ink, #0d9488); }
.ug-co-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.ug-co-inc { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary, #334155); padding: 9px 11px; border: 1px solid var(--border, #f1f5f9); border-radius: 10px; }
.ug-co-inc.hot { border-color: var(--primary-border, #99f6e4); background: var(--primary-soft, #f0fdfa); }

.ug-co-side { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 16px; }
.ug-os-title { display: flex; align-items: center; gap: 7px; font-size: 14px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 12px; }
.ug-os-title svg { color: var(--primary-ink, #0d9488); }
.ug-os-row { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--text-muted, #64748b); padding: 5px 0; }
.ug-os-row b { color: var(--text, #0f172a); font-weight: 800; text-align: end; }
.ug-os-row small { font-weight: 600; color: var(--text-muted, #64748b); }

.ug-trial-card { background: var(--primary-soft); border: 1.5px solid var(--primary-border, #99f6e4); border-radius: 14px; padding: 13px; margin: 12px 0; }
.ug-trial-hd { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: var(--primary-ink, #0f766e); margin-bottom: 8px; }
.ug-trial-row { display: flex; align-items: baseline; justify-content: space-between; font-size: 13.5px; color: var(--text-secondary, #334155); padding: 3px 0; }
.ug-trial-row b { font-size: 16px; font-weight: 900; color: var(--text, #0f172a); }
.ug-trial-row b.teal { color: var(--primary-ink, #0d9488); }
.ug-trial-row b small { font-size: 12px; font-weight: 700; }
.ug-trial-free { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #ccfbf1); border-radius: 8px; padding: 6px 10px; margin: 6px 0; }
.ug-trial-note { font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.4; margin: 4px 0 8px; }
.ug-trial-due { display: flex; align-items: baseline; justify-content: space-between; border-top: 1px dashed var(--primary-border, #99f6e4); padding-top: 8px; font-size: 14px; font-weight: 800; color: var(--text, #0f172a); }
.ug-trial-due b { color: var(--primary-ink, #0d9488); font-size: 17px; font-weight: 900; }
.ug-co-cta { margin-top: 4px; }

/* Promo */
.ug-promo { margin: 10px 0; }
.ug-promo-row { display: flex; align-items: center; gap: 6px; border: 1px solid var(--border, #e2e8f0); border-radius: 11px; padding: 4px 4px 4px 10px; background: var(--surface, #ffffff); }
.ug-promo-ic { color: var(--text-light, #94a3b8); display: inline-flex; flex-shrink: 0; }
.ug-card input.ug-promo-input { flex: 1; border: none; outline: none; font-size: 13px; background: transparent; padding: 7px 2px; color: var(--text, #0f172a); min-width: 0; }
.ug-promo-btn { border: none; background: #0f172a; color: #fff; font-weight: 700; font-size: 12.5px; padding: 8px 13px; border-radius: 8px; cursor: pointer; flex-shrink: 0; }
.ug-promo-btn:disabled { opacity: .45; cursor: default; }
.ug-promo-ok { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 10px; padding: 8px 11px; }
.ug-promo-ok b { color: var(--text, #0f172a); }
.ug-promo-x { margin-inline-start: auto; border: none; background: transparent; color: var(--text-muted, #64748b); font-size: 12px; cursor: pointer; text-decoration: underline; }
.ug-promo-err { font-size: 12px; color: var(--danger-ink, #dc2626); margin-top: 6px; }

.ug-fine { display: flex; align-items: center; justify-content: center; gap: 5px; font-size: 11px; color: var(--text-light, #94a3b8); margin-top: 10px; }
.ug-spin { animation: ugspin 1s linear infinite; }
@keyframes ugspin { to { transform: rotate(360deg); } }

@media (max-width: 760px) {
  .ug-backdrop { align-items: flex-end; padding: 0; }
  .ug-card, .ug-card.step-gate, .ug-card.step-plans, .ug-card.step-checkout { max-width: none; border-radius: 18px 18px 0 0; padding: 24px 16px 16px; max-height: 94dvh; overflow-y: auto; }
  .ug-plans { grid-template-columns: 1fr; }
  .ug-co { grid-template-columns: 1fr; }
  .ug-co-grid { grid-template-columns: 1fr; }
  .ug-card input.ug-promo-input { font-size: 16px; }
}
</style>
