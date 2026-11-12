<script setup>
import { computed } from 'vue'
import { Store, Globe, Wallet, Check, ArrowRight, AlertCircle } from 'lucide-vue-next'
import PaymentExample from './PaymentExample.vue'
import { useToast } from '../lib/toast.js'

const props = defineProps({
  form: Object, payment: Object, example: Object, currency: String,
  channel: String, role: String, onlineAllowed: Boolean, bookbollOwned: Boolean,
  payoutStatus: String,
  showErrors: Boolean, paidPlan: Boolean,
})
const emit = defineEmits(['channel', 'placement', 'method', 'commission', 'upgrade', 'connect', 'retry', 'choose-venue'])
const isSite = computed(() => props.channel === 'site')
const visible = computed(() => isSite.value ? props.form.show_on_provider_site : props.form.show_on_marketplace)
const noMethod = computed(() => isSite.value
  ? !props.payment.site_deposit_enabled && !props.payment.site_online_enabled
  : !props.payment.deposit_enabled && !props.payment.online_payment_enabled && !props.payment.cash_on_arrival)
const statusUncertain = computed(() => !props.bookbollOwned && ['idle', 'loading', 'error'].includes(props.payoutStatus))
const money = (label, detail, amount) => ({ label, detail, amount })
const depositRows = computed(() => isSite.value ? [
  money('Customer pays online', 'Bookboll keeps this as its platform fee', props.example.siteDep),
  money('Customer pays on arrival', 'You collect and keep this amount', props.example.siteRest),
] : [
  money('Customer pays online', 'Bookboll keeps this as its platform fee', props.example.fee),
  money('Customer pays on arrival', props.bookbollOwned ? 'Collected by Bookboll' : 'You collect and keep this amount', props.example.share),
])
const onlineRows = computed(() => isSite.value ? [
  money('Your share', 'To your connected Stripe account', props.example.siteRest),
  money('Bookboll platform fee', 'Deducted from the online payment', props.example.siteDep),
] : props.bookbollOwned ? [money('Customer pays online', 'Collected by Bookboll', props.example.total)] : [
  money('Your share', 'To your connected Stripe account', props.example.share),
  money('Bookboll platform fee', 'Deducted from the online payment', props.example.fee),
])
const depositKey = computed(() => isSite.value ? 'site_deposit_enabled' : 'deposit_enabled')
const onlineKey = computed(() => isSite.value ? 'site_online_enabled' : 'online_payment_enabled')
const cashKey = computed(() => isSite.value ? 'site_cash_enabled' : 'cash_on_arrival')
const depositOn = computed(() => props.payment[depositKey.value])
const onlineOn = computed(() => props.payment[onlineKey.value])
const cashOn = computed(() => props.payment[cashKey.value])
const canChooseCash = computed(() => !isSite.value && props.role !== 'provider')
const methodError = computed(() => props.showErrors && noMethod.value)

// A disabled checkbox fires no click, so the reason it is disabled never reaches
// the user. The card itself listens and says it out loud.
const toast = useToast()
const onlineBlockedReason = computed(() => props.role === 'provider'
  ? 'Full payment online needs an active payout method. Use “Connect payouts” in this card to set one up.'
  : 'This provider has no active payout method yet, so full payment online cannot be enabled.')
const cashBlockedReason = 'Bookboll controls full payment on arrival for this listing. Contact Bookboll to change it.'
function methodClick(kind, e) {
  if (e.target.closest('button')) return   // the Connect payouts action
  const reason = kind === 'online'
    ? (props.onlineAllowed ? '' : onlineBlockedReason.value)
    : (canChooseCash.value ? '' : cashBlockedReason)
  if (!reason) return
  e.preventDefault()
  toast.info(reason)
}
</script>

<template>
  <div class="listing-payments">
    <div class="lp-channels" role="group" aria-label="Booking channel">
      <button type="button" :aria-pressed="!isSite" :class="{ active: !isSite }" @click="emit('channel', 'marketplace')">
        <Store :size="19" /><span><strong>Bookboll marketplace</strong><small>{{ form.show_on_marketplace ? 'Shown on Bookboll' : 'Not shown on Bookboll' }}</small></span>
      </button>
      <button type="button" :aria-pressed="isSite" :class="{ active: isSite }" @click="emit('channel', 'site')">
        <Globe :size="19" /><span><strong>Your own website</strong><small>{{ !form.show_on_provider_site ? 'Not shown on your site' : 'Shown on your site' }}</small></span>
      </button>
    </div>

    <section class="lp-panel" :aria-label="isSite ? 'Your website payment settings' : 'Marketplace payment settings'">
      <label class="lp-placement"><span><strong>{{ isSite ? 'Show on your website' : 'Show on Bookboll marketplace' }}</strong><small>{{ isSite ? 'Bookings made through your own business website.' : 'Reach customers browsing Bookboll. New listings need approval.' }}</small></span>
        <input type="checkbox" :checked="visible" @change="emit('placement', channel, $event.target.checked)" />
      </label>
      <p v-if="!visible" class="lp-muted">This channel is off. Turn it on to offer this listing here.</p>
      <p v-else-if="isSite && bookbollOwned" class="lp-muted">This venue is owned by Bookboll. Its website bookings use the payment methods configured under Bookboll marketplace.</p>
      <template v-else>
        <div v-if="statusUncertain" class="lp-status lp-error" role="status"><AlertCircle :size="18" /><span>{{ payoutStatus === 'idle' ? 'Choose a venue to load the available payment methods.' : payoutStatus === 'loading' ? 'Checking your payout connection…' : 'We couldn’t check your payouts. Retry before choosing or saving payment options.' }}</span><button v-if="payoutStatus === 'idle'" type="button" @click="emit('choose-venue')">Choose venue</button><button v-else-if="payoutStatus === 'error'" type="button" @click="emit('retry')">Retry</button></div>
        <p class="lp-fee-rule" v-if="!isSite && !bookbollOwned"><strong>Bookboll platform fee: {{ example.feePct }}%.</strong> With a deposit, the deposit is this fee. With full online payment, the fee is deducted before your share reaches your wallet.</p>
        <aside v-else-if="isSite && !statusUncertain" class="lp-commission" aria-label="Your website commission">
          <div><strong>Your website commission: {{ example.sitePct }}%</strong><p>{{ paidPlan ? 'Paid Premium' : 'Free' }} rate for this venue’s market. This applies with or without connected payouts. Marketplace bookings keep their separate rate.</p></div>
          <div v-if="!paidPlan && role === 'provider' && example.premiumSitePct < example.sitePct" class="lp-commission-upgrade"><span>Pay {{ example.premiumSitePct }}% with paid Premium.</span><button type="button" @click="emit('upgrade')">Upgrade to Premium <ArrowRight :size="15" /></button></div>
        </aside>

        <details v-if="role !== 'provider' && !isSite && !bookbollOwned" class="lp-admin"><summary>Platform fee override</summary><label>Fee percentage<input type="number" min="0" max="100" step="0.5" :value="form.commission_rate" :placeholder="`Default ${example.feePct}%`" @input="emit('commission', $event.target.value)" /></label><p class="lp-muted">Leave blank to use the country default.</p></details>
        <fieldset class="lp-methods" :disabled="statusUncertain">
          <legend>How can customers pay?</legend>
          <p class="lp-muted">Enable one or more methods. Customers choose at checkout.</p>
          <article class="lp-method" :class="{ selected: depositOn, invalid: methodError }">
            <label class="lp-method-choice"><input type="checkbox" :checked="depositOn" :class="{ 'input-invalid': methodError }" @change="emit('method', depositKey, $event.target.checked)" /><span><strong>Deposit + on arrival</strong><small>Bookboll keeps the online deposit fee. You collect the remaining balance on arrival.</small></span></label>
            <div v-if="depositOn" class="lp-method-details">
              <p v-if="isSite" class="lp-explanation">The online deposit covers the {{ example.sitePct }}% Bookboll commission and is included in the booking price. The rate follows your plan at the time of booking. No payout connection is needed for this method.</p>
              <p v-else class="lp-explanation">The {{ example.feePct }}% deposit is included in the booking price. It is Bookboll’s fee, not money owed to your business. Collect only the remaining {{ +(100 - example.feePct).toFixed(2) }}% when the customer arrives.</p>
              <PaymentExample :currency="currency" :total="example.total" :rows="depositRows" />
            </div>
          </article>

          <article class="lp-method" :class="{ selected: onlineOn, invalid: methodError, blocked: !onlineAllowed }" @click="methodClick('online', $event)">
            <label class="lp-method-choice" :title="onlineAllowed ? null : onlineBlockedReason"><input type="checkbox" :checked="onlineOn" :disabled="!onlineAllowed" @change="emit('method', onlineKey, $event.target.checked)" /><span><strong>Full payment online</strong><small>{{ onlineAllowed ? 'Customer pays the whole booking price now. Nothing to collect on arrival.' : 'Connect a payout method to receive your online payments.' }}</small></span><span v-if="!onlineAllowed" class="lp-tag">Needs payouts</span></label>
            <!-- Payout state belongs to this method: it is the only one that needs it. -->
            <div v-if="!bookbollOwned && !statusUncertain" class="lp-status lp-method-payout">
              <Wallet :size="18" /><span><strong>{{ onlineAllowed ? 'Payouts connected' : 'No active payout method' }}</strong><small>{{ onlineAllowed ? 'Your booking share goes to your connected Stripe account.' : 'Deposit + on arrival works without one. Full online payment needs connected payouts.' }}</small></span>
              <button v-if="!onlineAllowed && role === 'provider'" type="button" @click="emit('connect', channel)">Connect payouts <ArrowRight :size="14" /></button>
            </div>
            <div v-if="onlineOn && onlineAllowed" class="lp-method-details"><PaymentExample :currency="currency" :total="example.total" :rows="onlineRows" /></div>
          </article>

          <article v-if="!isSite && (canChooseCash || cashOn)" class="lp-method" :class="{ selected: cashOn, invalid: methodError, blocked: !canChooseCash }" @click="methodClick('cash', $event)">
            <label class="lp-method-choice" :title="canChooseCash ? null : cashBlockedReason"><input type="checkbox" :checked="cashOn" :disabled="!canChooseCash" @change="emit('method', cashKey, $event.target.checked)" /><span><strong>Full payment on arrival</strong><small>{{ canChooseCash ? 'No online payment. You collect the whole amount in person.' : 'Enabled by Bookboll for this listing.' }}</small></span></label>
            <div v-if="cashOn" class="lp-method-details"><p class="lp-explanation">No money is collected if the customer does not show up.</p><PaymentExample :currency="currency" :total="example.total" :rows="[money('You collect on arrival', 'The full booking price', example.total)]" /></div>
          </article>
          <p v-if="methodError" class="lp-error-text" role="alert">Enable at least one payment method for this channel.</p>
        </fieldset>
        <p class="lp-footnote">{{ example.isReal ? 'Examples use your base booking price.' : 'Examples use a sample booking price of ' + currency + ' 100.' }} The split uses the actual booking total at checkout.</p>
      </template>
    </section>
  </div>
</template>

<style scoped>
.listing-payments { min-width: 0; }
.lp-muted { color: var(--text-muted); font-size: 13px; line-height: 1.6; margin: 6px 0 14px; }
.lp-channels { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; margin: 20px 0 16px; }
.lp-channels button { display: flex; gap: 10px; align-items: center; text-align: left; border: 1px solid var(--border); border-radius: 10px; padding: 14px; color: var(--text-secondary); background: var(--surface); }
.lp-channels button.active { background: var(--primary-soft); border-color: var(--primary); color: var(--primary-ink); box-shadow: inset 0 -2px var(--primary); }
.lp-channels svg { flex-shrink: 0; }
.lp-channels strong { font-size: 13px; display: block; line-height: 1.5; }
.lp-channels small { display: block; font-size: 11.5px; margin-top: 3px; line-height: 1.4; color: var(--text-muted); }
.lp-panel { border: 1px solid var(--border); border-radius: 12px; padding: 22px; background: var(--surface); }
.lp-placement { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding-bottom: 18px; border-bottom: 1px solid var(--border); cursor: pointer; }
.lp-placement strong { font-size: 15px; display: block; }
.lp-placement small { display: block; color: var(--text-muted); font-size: 12px; line-height: 1.6; margin-top: 5px; }
.listing-payments input[type=checkbox] { accent-color: var(--primary); width: 18px; height: 18px; flex: 0 0 18px; margin: 3px 0; cursor: pointer; }
.lp-status { display: flex; align-items: flex-start; gap: 10px; padding: 16px 0 4px; font-size: 13px; line-height: 1.5; }
.lp-status > svg { flex-shrink: 0; color: var(--text-muted); margin-top: 2px; }
.lp-status > span { flex: 1; }
.lp-status strong { display: block; font-size: 13px; font-weight: 600; }
.lp-status small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 3px; max-width: 54ch; }
.lp-status button { color: var(--primary-ink); background: var(--primary-soft); display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 600; font-size: 12px; padding: 10px 12px; border-radius: 8px; min-height: 42px; }
.lp-fee-rule { color: var(--text-secondary); background: var(--bg); font-size: 13px; line-height: 1.7; padding: 14px; border-radius: 8px; margin: 14px 0 22px; }
.lp-fee-rule strong { color: var(--text); }
.lp-commission { margin: 18px 0 24px; padding: 18px; border: 1px solid var(--warning-border); border-inline-start: 4px solid var(--warning-ink); background: var(--warning-bg); border-radius: 10px; }
.lp-commission strong { color: var(--warning-ink); font-size: 16px; }
.lp-commission p { margin: 8px 0 0; color: var(--text-secondary); font-size: 13px; line-height: 1.7; max-width: 70ch; }
.lp-commission-upgrade { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 18px; padding-top: 14px; margin-top: 14px; border-top: 1px solid var(--warning-border); font-size: 13px; color: var(--text); }
.lp-commission-upgrade button { display: inline-flex; align-items: center; gap: 8px; background: var(--text); color: var(--surface); border-radius: 8px; padding: 11px 14px; font-weight: 650; min-height: 44px; }
.lp-methods { border: 0; min-width: 0; padding: 0; margin: 22px 0 0; }
.lp-methods legend { font-size: 16px; font-weight: 650; margin-bottom: 4px; }
.lp-method { border: 1px solid var(--border); border-radius: 10px; margin-top: 10px; overflow: hidden; }
.lp-method.selected { border-color: var(--primary-border); }
.lp-method-choice { display: flex; align-items: flex-start; gap: 12px; padding: 16px; cursor: pointer; }
.lp-method-choice > span:not(.lp-tag) { flex: 1; min-width: 0; }
.lp-method-choice strong { display: block; font-size: 14px; color: var(--text); line-height: 1.5; }
.lp-method-choice small { display: block; color: var(--text-muted); font-size: 12px; line-height: 1.6; margin-top: 4px; max-width: 65ch; }
.lp-method.selected .lp-method-choice { background: var(--primary-soft); }
.lp-method-details { padding: 0 16px 16px; }
.lp-method-payout { padding: 13px 16px; border-top: 1px solid var(--border); align-items: center; gap: 12px; }
.lp-method.selected .lp-method-payout { border-top-color: var(--primary-border); }
.lp-explanation { color: var(--text-secondary); font-size: 13px; line-height: 1.7; margin: 14px 0 0; max-width: 70ch; }
.lp-tag { font-size: 11px; color: var(--text-muted); background: var(--bg); padding: 4px 7px; border-radius: 5px; white-space: nowrap; }
.lp-method.blocked .lp-method-choice { cursor: help; }
.lp-footnote { font-size: 11.5px; color: var(--text-muted); line-height: 1.6; margin-top: 16px; }
.lp-admin { padding: 12px 0; font-size: 13px; }.lp-admin summary { cursor: pointer; }.lp-admin label { display: block; margin-top: 10px; }.lp-admin input { max-width: 150px; margin-left: 10px; }
.lp-error-text, .lp-error { color: var(--danger-ink); font-size: 12px; line-height: 1.6; margin-top: 10px; }.invalid { border-color: var(--danger-border); }
.listing-payments :is(button,input):focus-visible { outline: 2px solid var(--primary-ink); outline-offset: 3px; }
.listing-payments input:disabled { cursor: not-allowed; opacity: .55; }
@media(max-width:760px) {
  .lp-panel { padding: 16px; }.lp-channels { gap: 8px; }.lp-channels button { align-items: flex-start; padding: 12px 10px; gap: 6px; }.lp-channels svg { display: none; }
  .lp-status { flex-wrap: wrap; }.lp-status button { margin-left: 28px; }.lp-status > span { flex-basis: calc(100% - 30px); }
  .lp-method-choice { padding: 14px 12px; gap: 9px; flex-wrap: wrap; }.lp-method-details { padding: 0 12px 14px; }.lp-tag { margin-left: 27px; }
  .lp-method-choice > span:not(.lp-tag) { flex-basis: calc(100% - 27px); }
  .lp-placement { align-items: flex-start; }
}
</style>
