<script setup>
import { financeRequest } from '../lib/financeApi.js'
import { ref, computed, watch } from 'vue'
import { ArrowRight, Building2, CalendarClock, Check, Info, Minus, TriangleAlert, X } from 'lucide-vue-next'
import { dateRangeError, validDate, money, count, previewReady, transferPreviewValid, newRequestKey } from '../lib/finance.js'
import './finance.css'

/*
 * Move a venue to an external business, optionally settling its legacy cash.
 *
 * Two decisions live in one dialog because they are one decision in practice:
 * who operates the venue from now on, and what the records say about the money
 * taken before that. The dialog's job is to make the SECOND one impossible to
 * make by accident — it is a reviewed assertion about the past, and the server
 * will not take it without a preview token and an explicit acknowledgement.
 *
 * Everything shown before "Review transfer" is derived from the form so the
 * operator can see what they are about to assert while they are still editing
 * it. Nothing here computes money: the figures all come from the server's
 * preview, which is the only thing allowed to say what the transfer is worth.
 */

const props = defineProps({
  venueId: [String, Number],
  venueName: String,
  // Who operates it today. Presentational only — the server decides what the
  // venue actually is — but a move needs a FROM as much as a TO.
  currentOwner: { type: String, default: '' },
})
const emit = defineEmits(['close', 'transferred'])

const options = ref(null)
const form = ref({ business_id: '', effective_from: '', history_mode: 'preserve', from_date: '', to_date: '' })
const preview = ref(null)
const agreed = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const key = ref(newRequestKey())

let generation = 0
// Any edit invalidates the reviewed figures. Deliberately sync: a stale token
// must never survive long enough to be committed against changed inputs.
watch(form, () => { generation++; preview.value = null; agreed.value = false; key.value = newRequestKey() }, { deep: true, flush: 'sync' })

/* ───────────────────────────── derived view ───────────────────────────── */

const isLegacy = computed(() => form.value.history_mode === 'legacy_deposit_fee')
const fromLabel = computed(() => props.currentOwner || 'Current operator')
const destination = computed(() => (options.value?.businesses || [])
  .find(b => String(b.id) === String(form.value.business_id)) || null)
const destinationName = computed(() => destination.value?.name || '')

function fmtDate(value) {
  if (!validDate(value)) return '—'
  return new Date(`${value}T00:00:00Z`)
    .toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

/**
 * The date problem, said while it can still be fixed.
 *
 * Silent until both dates exist: nagging about an empty field the operator has
 * not reached yet reads as a failure they caused. The server checks all of this
 * again — this is the earlier, friendlier copy of the same rule.
 */
const dateIssue = computed(() => {
  if (!isLegacy.value) return ''
  if (!validDate(form.value.from_date) || !validDate(form.value.to_date)) return ''

  const range = dateRangeError(form.value.from_date, form.value.to_date)
  if (range) return range

  if (validDate(form.value.effective_from) && form.value.to_date >= form.value.effective_from) {
    return `History has to end before ${fmtDate(form.value.effective_from)} — a booking cannot belong to both periods.`
  }

  return ''
})

const historyDays = computed(() => {
  if (dateIssue.value || !validDate(form.value.from_date) || !validDate(form.value.to_date)) return 0

  return Math.round(
    (Date.parse(`${form.value.to_date}T00:00:00Z`) - Date.parse(`${form.value.from_date}T00:00:00Z`)) / 86400000
  ) + 1
})

/**
 * What confirming would do, in plain sentences.
 *
 * The "no" lines matter as much as the "yes" ones: the single most expensive
 * misreading of this screen is believing it pays the provider. It does not —
 * it writes down that the provider already took the cash themselves.
 */
const effects = computed(() => {
  const to = destinationName.value || 'the new business'
  const rows = [
    { tone: 'go', text: `${to} becomes the operator of ${props.venueName || 'this venue'}.` },
    { tone: 'go', text: `New bookings belong to ${to}; Bookboll keeps the applicable platform fee.` },
  ]

  if (isLegacy.value && historyDays.value) {
    const span = `${fmtDate(form.value.from_date)} – ${fmtDate(form.value.to_date)}`
    rows.push(
      { tone: 'go', text: `Paid deposits on bookings played ${span} are re-labelled as Bookboll's fee.` },
      { tone: 'go', text: `Arrival balances in that period are closed as cash ${to} already collected.` },
    )
  } else {
    rows.push({ tone: 'go', text: 'Every existing fee, receipt and payment stays exactly as recorded.' })
  }

  rows.push(
    { tone: 'no', text: 'No money is moved. No wallet is credited and no payout is created.' },
    { tone: 'warn', text: 'Landlord agreements on this venue stay in force — rent is neither cancelled nor paid.' },
  )

  return rows
})

/** Server-counted, never computed here. Preserve mode has nothing to count. */
const reviewStats = computed(() => {
  const p = preview.value
  if (!p) return []

  const landlord = { label: 'Landlord agreements still in force', value: count(p.landlord_obligation_count), tone: p.landlord_obligation_count > 0 ? 'warn' : '' }
  if (form.value.history_mode === 'preserve') return [landlord]

  return [
    { label: 'Deposits re-labelled as Bookboll fees', value: count(p.eligible_count), tone: 'go' },
    { label: 'Arrival balances closed as provider cash', value: count(p.pending_arrival_count), tone: 'info' },
    { label: 'Excluded — needs review', value: count(p.excluded_count), tone: p.excluded_count > 0 ? 'warn' : '' },
    landlord,
  ]
})

const expiresLabel = computed(() => {
  const at = Date.parse(preview.value?.expires_at)

  return Number.isFinite(at) ? new Date(at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—'
})

/* ───────────────────────────── server calls ───────────────────────────── */

async function load() {
  const result = await financeRequest(`/admin/venues/${props.venueId}/transfer-options`)
  if (result.ok) options.value = result.data
  else notice.value = result.message
}

async function review() {
  error.value = ''
  if (!form.value.business_id || !validDate(form.value.effective_from)) {
    error.value = 'Choose the destination business and transfer date.'

    return
  }
  if (form.value.history_mode === 'legacy_deposit_fee') {
    error.value = dateRangeError(form.value.from_date, form.value.to_date)
    if (!error.value && form.value.to_date >= form.value.effective_from) error.value = 'Legacy history must end before the transfer date.'
  }
  if (isLegacy.value && options.value?.reconciliation_version !== 2) error.value = 'This server needs the updated payment reconciliation before legacy history can be changed.'
  if (error.value || busy.value || !options.value?.can_transfer) return

  busy.value = true
  const current = ++generation
  const result = await financeRequest(`/admin/venues/${props.venueId}/transfer-preview`, {
    method: 'POST',
    body: {
      ...form.value,
      from_date: form.value.history_mode === 'preserve' ? null : form.value.from_date,
      to_date: form.value.history_mode === 'preserve' ? null : form.value.to_date,
    },
  })
  busy.value = false
  if (current !== generation) return

  if (!result.ok || !transferPreviewValid(result.data, form.value)) {
    error.value = result.message || 'The server did not return a complete transfer preview.'

    return
  }
  preview.value = result.data
  agreed.value = false
}

async function commit() {
  if (busy.value || !agreed.value || !previewReady(preview.value)) {
    error.value = 'Review the transfer again if its preview has expired.'

    return
  }
  busy.value = true
  error.value = ''
  const result = await financeRequest(`/admin/venues/${props.venueId}/transfers`, {
    method: 'POST',
    body: {
      preview_token: preview.value.preview_token,
      idempotency_key: key.value,
      acknowledge_legacy_cash: form.value.history_mode === 'legacy_deposit_fee',
    },
  })
  busy.value = false

  if (!result.ok || !result.data?.transfer?.id) {
    error.value = result.message || 'The transfer was not confirmed. Refresh its preview before continuing.'

    return
  }
  emit('transferred', result.data.venue)
}

load()
</script>

<template>
  <div class="modal-backdrop" style="z-index:3300" @click.self="!busy && emit('close')">
    <div
      class="modal fin-dialog vt-dialog" v-dialog="() => !busy && emit('close')"
      role="dialog" aria-modal="true" aria-labelledby="transfer-title" @input.stop @change.stop
    >
      <div class="modal-header">
        <div>
          <div class="fin-eyebrow">Venue ownership</div>
          <h2 id="transfer-title" class="modal-title">Move to an external business</h2>
        </div>
        <button class="btn-icon" :disabled="busy" aria-label="Close transfer" @click="emit('close')"><X :size="18" /></button>
      </div>

      <div class="modal-body">
        <!-- The move itself, before any of its settings: who hands over to whom. -->
        <div class="vt-move">
          <div class="vt-party">
            <span class="vt-party-role">Operated today by</span>
            <strong><Building2 :size="14" aria-hidden="true" />{{ fromLabel }}</strong>
          </div>
          <ArrowRight class="vt-move-arrow" :size="18" aria-hidden="true" />
          <div class="vt-party" :class="{ 'vt-party-set': destinationName }">
            <span class="vt-party-role">Moves to</span>
            <strong>
              <Building2 :size="14" aria-hidden="true" />
              {{ destinationName || 'Choose a business' }}
            </strong>
          </div>
        </div>
        <p class="fin-help vt-venue">{{ venueName }}</p>

        <p v-if="notice" class="fin-notice">{{ notice }}</p>

        <!-- ── Step 1: what the transfer should be ─────────────────────── -->
        <fieldset v-show="!preview" :disabled="busy" class="vt-fieldset">
          <div class="fin-form-grid">
            <label class="fin-field fin-wide">
              Destination business *
              <select v-model="form.business_id">
                <option value="">Choose a business</option>
                <option v-for="item in options?.businesses || []" :key="item.id" :value="item.id">
                  {{ item.name }}{{ item.owner_user_id ? '' : ' · owner not assigned' }}
                </option>
              </select>
            </label>
            <label class="fin-field fin-wide">
              Transfer effective from *
              <input v-model="form.effective_from" type="date" />
              <small class="fin-help">
                Recorded as the handover date and used as the cut-off below. The venue itself
                moves as soon as you confirm, not on this date.
              </small>
            </label>
          </div>

          <h3 class="vt-h3">What happens to previous payments?</h3>
          <label class="fin-choice">
            <input v-model="form.history_mode" type="radio" value="preserve" />
            <span>
              <strong>Keep history unchanged</strong>
              <small>Only future activity uses the new business. Existing fees, receipts and landlord obligations stay as recorded.</small>
            </span>
          </label>
          <label class="fin-choice">
            <input v-model="form.history_mode" type="radio" value="legacy_deposit_fee" />
            <span>
              <strong>Reconcile legacy provider bookings</strong>
              <small>Paid online deposits become Bookboll fees. Reviewed arrival balances become provider cash. Full online payments are never converted to cash; manual bookings remain entirely provider revenue.</small>
            </span>
          </label>

          <div v-if="isLegacy" class="vt-history">
            <div class="fin-form-grid">
              <label class="fin-field">History from *<input v-model="form.from_date" type="date" /></label>
              <label class="fin-field">History to *<input v-model="form.to_date" type="date" /></label>
            </div>
            <p class="fin-help vt-history-note">
              These match the day a booking was <strong>played</strong> — not the day it was paid for.
            </p>
          </div>

          <!-- The two periods side by side. The whole rule this dialog enforces
               is that they cannot touch, which is far easier to see than read. -->
          <div class="vt-timeline" :class="{ 'vt-timeline-bad': dateIssue }">
            <div v-if="isLegacy" class="vt-seg vt-seg-past">
              <span class="vt-seg-role"><CalendarClock :size="13" aria-hidden="true" />Reconciled history</span>
              <strong>{{ fmtDate(form.from_date) }} → {{ fmtDate(form.to_date) }}</strong>
              <small v-if="historyDays">{{ historyDays }} day{{ historyDays === 1 ? '' : 's' }} of bookings</small>
            </div>
            <div v-else class="vt-seg vt-seg-past vt-seg-quiet">
              <span class="vt-seg-role"><CalendarClock :size="13" aria-hidden="true" />Everything before</span>
              <strong>Stays as recorded</strong>
              <small>No past payment is touched</small>
            </div>

            <span class="vt-seg-join" aria-hidden="true"></span>

            <div class="vt-seg vt-seg-future">
              <span class="vt-seg-role"><Building2 :size="13" aria-hidden="true" />{{ destinationName || 'New business' }} operates</span>
              <strong>From {{ fmtDate(form.effective_from) }}</strong>
              <small>All new bookings and fees</small>
            </div>
          </div>
          <p v-if="dateIssue" class="fin-error vt-inline-error">{{ dateIssue }}</p>

          <!-- What confirming would actually do. -->
          <div class="vt-effects">
            <div class="fin-eyebrow">What this will do</div>
            <ul>
              <li v-for="row in effects" :key="row.text" :class="`vt-fx-${row.tone}`">
                <Check v-if="row.tone === 'go'" :size="15" aria-hidden="true" />
                <Minus v-else-if="row.tone === 'no'" :size="15" aria-hidden="true" />
                <TriangleAlert v-else :size="15" aria-hidden="true" />
                <span>{{ row.text }}</span>
              </li>
            </ul>
          </div>

          <p v-if="isLegacy" class="fin-notice vt-legal">
            <Info :size="15" aria-hidden="true" />
            <span>This confirms historical cash collection. It does not send money to the provider.
              Skipped-payment slot blocks are excluded. Cancelled, refunded, disputed or ambiguous
              records must be excluded or resolved during review.</span>
          </p>
        </fieldset>

        <!-- ── Step 2: the server's figures ────────────────────────────── -->
        <section v-if="preview" class="vt-review">
          <div class="vt-review-head">
            <div>
              <div class="fin-eyebrow">Review before applying</div>
              <h3 class="vt-h3">{{ preview.destination_name }}</h3>
            </div>
            <span class="vt-expiry">Expires {{ expiresLabel }}</span>
          </div>

          <dl class="fin-review-list vt-terms">
            <dt>Handover date</dt><dd>{{ fmtDate(form.effective_from) }}</dd>
            <dt>Historical payments</dt>
            <dd>{{ isLegacy ? `${fmtDate(form.from_date)} – ${fmtDate(form.to_date)} · reconciled` : 'Kept unchanged' }}</dd>
          </dl>

          <div class="vt-stats">
            <div v-for="stat in reviewStats" :key="stat.label" class="vt-stat" :class="stat.tone ? `vt-stat-${stat.tone}` : ''">
              <strong>{{ stat.value }}</strong>
              <span>{{ stat.label }}</span>
            </div>
          </div>

          <div v-for="total in preview.totals" :key="total.currency" class="fin-panel vt-money">
            <div class="vt-money-head"><strong>{{ total.currency }}</strong></div>
            <div class="fin-row"><span>Paid deposits → Bookboll fee</span><strong>{{ money(total.deposit_to_fee, total.currency) }}</strong></div>
            <div class="fin-row"><span>Arrival cash → collected directly by provider</span><strong>{{ money(total.provider_cash, total.currency) }}</strong></div>
            <div class="fin-row"><span>Recorded platform fee amount</span><strong>{{ money(total.earnings_delta, total.currency) }}</strong></div>
            <div v-if="total.provider_already_paid" class="fin-row"><span>Already collected → provider revenue</span><strong>{{ money(total.provider_already_paid, total.currency) }}</strong></div><p class="fin-help vt-money-note">Deposits are existing receipts, not new earnings. This records the provider’s own cash collection. Bookboll owes no payout for these arrival balances.</p>
          </div>

          <details v-if="preview.payment_changes?.length" class="fin-panel">
            <summary>Review individual payment changes (first {{ preview.payment_changes.length }})</summary>
            <div class="table-wrap"><table><thead><tr><th>Payment</th><th>Recorded payment</th><th>Amount</th><th>After reconciliation</th></tr></thead>
              <tbody><tr v-for="change in preview.payment_changes" :key="change.payment_id">
                <td>#{{ change.payment_id }}<small>Booking #{{ change.booking_id }}</small></td>
                <td>{{ change.type }} · {{ change.method }} · {{ change.status }}</td>
                <td>{{ money(change.amount, change.currency) }}</td>
                <td>{{ change.action === 'deposit_to_fee' ? 'Bookboll platform fee' : change.action === 'provider_direct_cash' ? 'Provider collected cash' : 'Provider revenue (already paid)' }}</td>
              </tr></tbody>
            </table></div>
          </details>
          <p v-for="warning in preview.warnings || []" :key="warning" class="fin-notice">{{ warning }}</p>
          <p v-for="blocker in preview.blockers || []" :key="blocker" class="fin-error">{{ blocker }}</p>

          <p class="fin-help">
            Existing landlord liabilities are preserved. Original payments remain in the audit
            history with linked correction entries.
          </p>

          <label class="fin-checkbox vt-confirm">
            <input v-model="agreed" type="checkbox" :disabled="busy" />
            <span>{{ isLegacy
              ? 'I confirm the reviewed deposits were Bookboll fees and the reviewed arrival balances were collected by this provider as cash.'
              : 'I confirm this venue should move to the selected business, keeping historical payments unchanged.' }}</span>
          </label>
        </section>

        <p v-if="error" class="fin-error" role="alert">{{ error }}</p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button>
        <button v-if="preview" class="btn btn-secondary" :disabled="busy" @click="preview = null; agreed = false">Back to details</button>
        <button class="btn btn-secondary" :disabled="busy || !options?.can_transfer" @click="review">
          {{ busy ? 'Working…' : preview ? 'Refresh review' : 'Review transfer' }}
        </button>
        <button v-if="preview" class="btn btn-primary" :disabled="busy || !agreed || !previewReady(preview)" @click="commit">
          Confirm transfer<ArrowRight :size="14" />
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Every custom property below is defined by dashboardTheme.css in BOTH
 * palettes, and a test walks this block to keep it that way. A token the theme
 * does not define falls through to whatever literal follows it, which is how a
 * panel ends up light-coloured in dark mode with light text on top of it. */
.vt-dialog { width: min(760px, calc(100vw - 24px)); max-width: 760px; }
.vt-fieldset { border: 0; padding: 0; margin: 0; }
.vt-h3 { font-size: 15px; margin: 0 0 4px; color: var(--text); }

/* ── the move ─────────────────────────────────────────────────────────── */
.vt-move { display: flex; align-items: stretch; gap: 12px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.vt-party { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.vt-party-role { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); }
.vt-party strong { display: flex; align-items: center; gap: 7px; font-size: 14px; color: var(--text-muted); overflow-wrap: anywhere; }
.vt-party-set strong { color: var(--primary-ink); }
.vt-move-arrow { flex-shrink: 0; align-self: center; color: var(--text-muted); }
.vt-venue { margin: 8px 0 20px; }

/* ── the two periods ──────────────────────────────────────────────────── */
.vt-history { margin-top: 14px; }
.vt-history-note { margin-top: 10px; }
.vt-timeline { display: flex; align-items: stretch; gap: 0; margin-top: 16px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.vt-seg { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; padding: 13px 15px; }
.vt-seg-role { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.vt-seg strong { font-size: 14px; color: var(--text); overflow-wrap: anywhere; }
.vt-seg small { font-size: 12px; color: var(--text-muted); }
.vt-seg-past { background: var(--warning-bg); }
.vt-seg-past .vt-seg-role { color: var(--warning-ink); }
/* Preserve mode asserts nothing about the past, so it must not look active. */
.vt-seg-quiet { background: var(--surface); }
.vt-seg-quiet .vt-seg-role { color: var(--text-muted); }
.vt-seg-future { background: var(--primary-soft); }
.vt-seg-future .vt-seg-role { color: var(--primary-ink); }
/* The join is the cut-off: the one thing the two periods may never cross. */
.vt-seg-join { flex: 0 0 3px; background: var(--border-strong); }
.vt-timeline-bad { border-color: var(--danger-border); }
.vt-timeline-bad .vt-seg-past { background: var(--danger-bg); }
.vt-timeline-bad .vt-seg-past .vt-seg-role { color: var(--danger-ink); }
.vt-timeline-bad .vt-seg-join { background: var(--danger-ink); }
.vt-inline-error { margin-top: 10px; }

/* ── what this will do ────────────────────────────────────────────────── */
.vt-effects { margin-top: 22px; padding: 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.vt-effects ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.vt-effects li { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; line-height: 1.55; color: var(--text); }
.vt-effects svg { flex-shrink: 0; margin-top: 2px; }
.vt-fx-go svg { color: var(--positive-ink); }
.vt-fx-no { color: var(--text-muted); }
.vt-fx-no svg { color: var(--text-muted); }
.vt-fx-warn svg { color: var(--warning-ink); }
.vt-legal { display: flex; align-items: flex-start; gap: 10px; margin-top: 16px; }
.vt-legal svg { flex-shrink: 0; margin-top: 2px; }

/* ── review ───────────────────────────────────────────────────────────── */
.vt-review { margin: 8px 0 0; }
.vt-review-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 16px; }
.vt-expiry { flex-shrink: 0; font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.vt-terms { padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.vt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 12px; }
.vt-stat { display: flex; flex-direction: column; gap: 3px; padding: 13px 14px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface); }
.vt-stat strong { font-size: 22px; font-variant-numeric: tabular-nums; color: var(--text); line-height: 1.1; }
.vt-stat span { font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.vt-stat-go { background: var(--positive-bg); border-color: var(--positive-border); }
.vt-stat-go strong { color: var(--positive-ink); }
.vt-stat-info { background: var(--info-bg); border-color: var(--info-border); }
.vt-stat-info strong { color: var(--info-ink); }
.vt-stat-warn { background: var(--warning-bg); border-color: var(--warning-border); }
.vt-stat-warn strong { color: var(--warning-ink); }
.vt-money { margin-top: 12px; padding: 16px 18px; }
.vt-money-head { font-size: 13px; letter-spacing: .04em; color: var(--text-muted); }
.vt-money-note { margin-top: 12px; }
.vt-confirm { padding: 14px 16px; border: 1px solid var(--primary-border); border-radius: 12px; background: var(--primary-soft); }

@media (max-width: 600px) {
  .vt-move { flex-direction: column; gap: 10px; }
  .vt-move-arrow { align-self: flex-start; transform: rotate(90deg); }
  .vt-timeline { flex-direction: column; }
  .vt-seg-join { flex: 0 0 3px; width: 100%; }
}
</style>
