<script setup>
import ExportDialog from '../components/ExportDialog.vue'
/**
 * Subscribers.vue — the subscribers table for class/subscription programs.
 * Split out of the old "Classes & Subscriptions" page so programs and subscribers
 * each live on their own page under the "Classes & Subscription Programs" nav group.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadReferenceData } from '../lib/referenceData.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { pushToast } from '../lib/toast.js'
import { askConfirm } from '../lib/confirm.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { rowState } from '../lib/approvalState.js'
import { offerModeFor, discountedPrice } from '../lib/billingPlans.js'
import {
  firstError,
  fetchSubscriptionsDashboard,
  createSubscription,
  chargeSubscription,
  retryChargeSubscription,
  cancelSubscription,
  bulkCancelSubscriptions,
  pauseSubscription,
  resumeSubscription,
  markSubscriptionPastDue,
  retentionMonthOff as retentionMonthOffApi,
  winbackSubscription,
  fetchSubscriptionPayments,
} from '../lib/subscriptions.js'
import { notifyMembershipStarted, notifyMembershipPayment, notifyMembershipCancelled, notifyMembershipPaymentFailed, notifyMembershipWinback } from '../customer/notificationDispatch.js'
import { usePremium } from '../lib/premium.js'
import { usePagination } from '../lib/usePagination.js'
import PaginationBar from '../components/PaginationBar.vue'
import FilterBar from '../components/FilterBar.vue'
import { matchesLocationGeo } from '../lib/geoFilter.js'
import { CAP } from '../lib/permissions.js'
import { downloadCSV } from '../lib/useExport.js'
import FormAnswers from '../components/FormAnswers.vue'
import { asFields } from '../lib/customForms.js'
import { Plus, Search, X, Check, Users, DollarSign, Repeat, ShieldCheck, ChevronUp, ChevronDown as ChevronDownIcon, ChevronsUpDown, SlidersHorizontal, Download, FileText, Pause, Play, AlertTriangle, CheckCircle, RotateCcw, HeartHandshake } from 'lucide-vue-next'

const { isSuperAdmin, isCountryAdmin, currentOwnerUserId, can, role, assignedLocationIds, scopePrograms } = useCurrentUser()
const canManageSubs = computed(() => can(CAP.MANAGE_SUBSCRIPTIONS))
const canExport = computed(() => can(CAP.EXPORT_SUBSCRIPTIONS))
const { isPremium, requirePremium } = usePremium()   // exports are a Premium feature for providers
// Shared CSV column config, reused by the toolbar "Export" and the bulk "Export selected".
function subExportColumns() {
  return [
    { label: 'Customer', key: 'customer_name' },
    { label: 'Email', key: 'customer_email' },
    { label: 'Program', fn: s => programById(s.program_id)?.name || '' },
    { label: 'Status', fn: s => subExportStatus(s) },
    { label: 'Billing', fn: s => intervalLabel(s.billing_interval) },
    { label: 'Amount', fn: s => Number(s.monthly_amount || 0) },
    { label: 'Started', key: 'started_at' },
    { label: 'Next billing', fn: s => s.next_billing_at || '' },
  ]
}
function subExportStatus(s) {
  if (s.trial_status === 'in_trial') return 'In Trial'
  if (s.status === 'cancelled') return 'Cancelled'
  if (s.status === 'paused') return 'Paused'
  if (s.status === 'past_due') return 'Past due'
  return 'Active'
}
const showExportModal = ref(false)
function exportSubs(useFilters) {
  if (!canExport.value) return
  if (!requirePremium('exports')) { showExportModal.value = false; return }
  downloadCSV(useFilters ? filteredSortedSubs.value : scopedSubscriptions.value, subExportColumns(), 'subscriptions')
  showExportModal.value = false
}

// When embedded (inside the Customers page's "Members" tab) the page title/padding are
// dropped — the host page provides the header.
defineProps({ embedded: { type: Boolean, default: false } })

const subscriptions = ref([])
const programs = ref([])
const locations = ref([])
const cities = ref([])
const countries = ref([])
// Venue payloads carry city_id / country_id as strings, reference-data cities[] and
// countries[] carry integers — a strict === between them never matches. Same helper
// as Wallet.vue and Listings.vue.
const cid = v => (v === null || v === undefined || v === '') ? '' : String(v)
const providers = ref([])
const sessions = ref([])

// Platform jurisdiction (country_admin / platform_staff only see their countries' programs)
const { allowedLocationIds, inJurisdictionLoc, inJurisdictionOwner, scopeCountries, scopeCities } = useJurisdiction({ locations, cities, providers })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))

async function loadAll() {
  const [dash, ref] = await Promise.all([
    fetchSubscriptionsDashboard(),
    loadReferenceData(),
  ])
  if (!dash.success) {
    pushToast(firstError(dash, 'Could not load subscriptions.'), 'error')
    return
  }
  subscriptions.value = dash.data?.subscriptions || []
  programs.value = dash.data?.programs || []
  sessions.value = dash.data?.sessions || []
  providers.value = dash.data?.providers || []
  locations.value = ref.venues
  cities.value = ref.cities
  countries.value = ref.countries
}

const route = useRoute()
const router = useRouter()
// Deep-link from a subscription payment on the Payments page: /subscriptions?sub=<id>
// opens that member's detail drawer directly.
function openSubFromQuery() {
  const sid = route.query.sub
  if (!sid) return
  const match = subscriptions.value.find(s => String(s.id) === String(sid))
  if (match) openSubDetail(match)
  // Clear the query so a manual close + refresh doesn't re-open it.
  router.replace({ query: { ...route.query, sub: undefined } })
}
onMounted(async () => {
  await loadAll()
  openSubFromQuery()
})

// ── Shared helpers ──
const programById = (id) => programs.value.find(p => p.id === id)
// A comped (free) member: $0, no charge scheduled. The is_comp column is added by a later
// migration; pre-migration rows simply never have it, so this is safely false.
const isComp = (s) => !!s?.is_comp
// Best-available cancel timestamp for churn math. There's no dedicated cancelled_at column,
// so we fall back through the signals we do have (see concerns): an explicit cancelled_at if
// a later migration adds one, then the trial-cancel timestamp, then updated_at/created_at.
function cancelTimestamp(s) {
  if (!s) return null
  const raw = s.cancelled_at || s.cancelled_during_trial_at || s.updated_at || s.created_at
  if (!raw) return null
  const t = new Date(raw).getTime()
  return Number.isNaN(t) ? null : t
}
// Custom sign-up answers (from the marketplace membership checkout).
const answersFor = ref(null)
function hasAnswers(s) {
  return !!(s.form_answers && Object.keys(s.form_answers).length && asFields(programById(s.program_id)?.signup_form).length)
}
const locationName = (id) => locations.value.find(l => l.id === id)?.name || '—'
const providerName = (id) => { const p = providers.value.find(x => x.id === id); return p ? (p.business_name || p.full_name) : '—' }
const sessionsForSub = (id) => {
  const sub = subscriptions.value.find(s => s.id === id)
  if (sub && sub.session_count != null) return Number(sub.session_count) || 0
  return sessions.value.filter(s => s.subscription_id === id).length
}
function currencyForLocation(locationId) {
  const loc = locations.value.find(l => l.id === locationId)
  // Unknown stays unknown: callers print the amount bare rather than labelling
  // money in a currency nobody was charged.
  if (!loc) return ''
  if (loc.currency_override) return loc.currency_override
  const city = cities.value.find(c => cid(c.id) === cid(loc.city_id))
  if (!city) return ''
  return countries.value.find(c => cid(c.id) === cid(city.country_id))?.currency_code || ''
}
function fmtMoney(n, cur = '') { return [cur, Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })].filter(Boolean).join(' ') }
const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
function formatDays(mask) { return (mask || []).map(d => DAY_NAMES[d]).join(', ') }

// Session dates in the first month, for the "will generate N sessions" hint on
// the new-subscription form. The server schedules the real sessions; this is the
// preview beside the date picker. (It was lost in the port — the template still
// called it, so the hint threw as soon as a program was picked.)
function generateMonthlyDates(program, startDateStr) {
  if (!program || !startDateStr) return []
  const dates = []
  const start = new Date(startDateStr)
  if (Number.isNaN(start.getTime())) return []
  const end = new Date(start); end.setMonth(end.getMonth() + 1)
  const mask = new Set(program.day_of_week_mask || [])
  const cur = new Date(start)
  while (cur < end) {
    if (mask.has(cur.getDay())) dates.push(new Date(cur))
    cur.setDate(cur.getDate() + 1)
  }
  return dates
}
const BILLING_INTERVALS = [
  { value: 'weekly', label: 'Weekly' }, { value: 'biweekly', label: 'Bi-weekly' },
  { value: 'monthly', label: 'Monthly' }, { value: 'quarterly', label: 'Quarterly' }, { value: 'yearly', label: 'Yearly' },
]
function intervalLabel(v) { return BILLING_INTERVALS.find(i => i.value === v)?.label || 'Monthly' }

const providerOwnPrograms = computed(() => {
  let list
  if (isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff') {
    // Platform roles: super admin sees everything; country_admin / platform_staff only
    // programs at jurisdiction venues (or run by a provider in their countries).
    list = allowedLocationIds.value
      ? programs.value.filter(p => inJurisdictionLoc(p.location_id) || inJurisdictionOwner(p.owner_user_id))
      : programs.value
  }
  else if (!currentOwnerUserId.value) return []
  else list = programs.value.filter(p => p.owner_user_id === currentOwnerUserId.value)
  // Per-user scope: Managers/Staff pinned to specific locations / programs.
  const locIds = assignedLocationIds.value
  if (locIds.length) { const set = new Set(locIds); list = list.filter(p => set.has(Number(p.location_id))) }
  return scopePrograms(list)
})
const myProgramIds = computed(() => new Set(providerOwnPrograms.value.map(p => p.id)))

// ── Add subscriber modal ──
const subModal = ref(false)
const subTriedSave = ref(false)
async function requestCloseSub() { if (await confirmDiscardIfDirty()) subModal.value = false }
const subForm = ref(defaultSub())
function defaultSub() {
  return { program_id: '', customer_name: '', customer_email: '', started_at: new Date().toISOString().slice(0, 10), card_on_file_id: '', trial_consent: false, comp: false, comp_reason: '' }
}
// The program's single plan, derived from its billing fields.
const subPlan = computed(() => {
  const p = programById(subForm.value.program_id)
  if (!p) return null
  const mode = offerModeFor(p)
  const discount_months = Number(p.discount_months) || 0
  const interval = p.billing_interval || 'monthly'
  const listPrice = Number(p.monthly_price) || 0
  // Discount plans charge the discounted amount; trial plans charge the full price.
  const price = mode === 'discount' ? discountedPrice(listPrice, interval, discount_months) : listPrice
  return {
    interval, mode, listPrice, price,
    trial_days: Number(p.free_trial_days) || 0,
    trial_sessions: Number(p.free_trial_sessions) || 0,
    discount_months,
  }
})
// "Charged today" = discount plans, pay-in-full, and longer upfront intervals (no trial).
const subUpfront = computed(() => {
  const p = subPlan.value
  if (!p) return false
  if (p.mode === 'discount') return true
  return !(Number(p.trial_days) > 0 || Number(p.trial_sessions) > 0)
})
const subIsOneTime = computed(() => subPlan.value?.interval === 'one_time')
const subTrialIsSession = computed(() => Number(subPlan.value?.trial_sessions) > 0)
const subCur = computed(() => programById(subForm.value.program_id)?.currency || '')
function openNewSub(programId = '') {
  subForm.value = { ...defaultSub(), program_id: programId }
  subTriedSave.value = false
  clearDirty()
  subModal.value = true
}

async function saveSubscription() {
  subTriedSave.value = true
  const program = programById(subForm.value.program_id)
  if (!program) { pushToast('Please select a program.', 'error'); return }
  if (rowState(program) !== 'active') { pushToast('This program is not active.', 'error'); return }
  const isComped = !!subForm.value.comp
  if (!subForm.value.customer_name?.trim()) {
    pushToast('Please complete the required fields.', 'error'); return
  }
  if (!isComped && (!subForm.value.card_on_file_id || !subForm.value.trial_consent)) {
    pushToast('Please complete the required fields and trial consent.', 'error'); return
  }

  const res = await createSubscription({
    program_id: program.id,
    customer_name: subForm.value.customer_name.trim(),
    customer_email: subForm.value.customer_email || '',
    started_at: subForm.value.started_at,
    card_on_file_id: subForm.value.card_on_file_id || '',
    trial_consent: !!subForm.value.trial_consent,
    comp: isComped,
    comp_reason: isComped ? (subForm.value.comp_reason?.trim() || null) : null,
  })
  if (!res.success) {
    pushToast(firstError(res, 'Could not add subscriber.'), 'error')
    return
  }

  const inserted = res.data?.subscription
  const charged = !!res.data?.charged

  try { if (inserted) notifyMembershipStarted({ ownerId: program.owner_user_id, sub: inserted, program }) } catch { /* non-blocking */ }

  clearDirty()
  pushToast(isComped ? 'Comped member added' : (charged ? 'Subscriber added & charged' : 'Subscriber added'), 'success')
  subModal.value = false
  loadAll()
}

async function chargeSub(s) {
  const program = programById(s.program_id)
  if (!program) return
  if (!(program.status === 'active' && rowState(program) === 'active')) {
    pushToast('This program is paused or archived and is winding down — its subscriptions can’t be renewed.', 'error')
    return
  }
  const res = await chargeSubscription(s.id)
  if (!res.success) {
    pushToast(firstError(res, 'Could not collect charge.'), 'error')
    return
  }
  const updated = res.data?.subscription || s
  try { notifyMembershipPayment({ ownerId: program.owner_user_id, sub: updated, program }) } catch { /* non-blocking */ }
  detailSub.value = null
  loadAll()
}

// ── Cancel + Save-offer ─────────────────────────────────────────────────────────
// Cancelling opens a small save-offer modal (reason + retention CTAs). The raw DB write
// lives in performCancel() so bulk-cancel + the modal's "Cancel anyway" both reuse it.
const cancelModal = ref(null)      // { sub } while the save-offer modal is open
const cancelReason = ref('')
const CANCEL_REASONS = [
  { value: 'too_expensive', label: 'Too expensive' },
  { value: 'not_using', label: 'Not using it' },
  { value: 'moving', label: 'Moving / relocating' },
  { value: 'other', label: 'Other' },
]
function cancelSub(s) {
  // Open the retention modal instead of an immediate confirm.
  cancelReason.value = ''
  cancelModal.value = { sub: s }
}
// The actual cancellation write. reason is persisted onto the row (graceful column).
async function performCancel(s, reason = '') {
  const res = await cancelSubscription(s.id, reason ? { cancel_reason: reason } : {})
  if (!res.success) { pushToast(firstError(res, 'Could not cancel subscription.'), 'error'); return false }
  try { const program = programById(s.program_id); if (program) notifyMembershipCancelled({ ownerId: program.owner_user_id, sub: s, program }) } catch { /* non-blocking */ }
  return true
}
// Save-offer modal → "Cancel anyway": run the cancellation with the chosen reason.
async function confirmCancelFromModal() {
  const s = cancelModal.value?.sub
  if (!s) return
  const ok = await performCancel(s, cancelReason.value)
  cancelModal.value = null
  if (!ok) return
  pushToast('Subscription cancelled', 'success')
  detailSub.value = null
  loadAll()
}
// Save-offer modal → "Apply 1 month off next cycle": a one-off discount that pushes the
// next charge out by one month (money simulated — no charge is taken now). Keeps the member.
async function retentionMonthOff() {
  const s = cancelModal.value?.sub
  if (!s) return
  const res = await retentionMonthOffApi(s.id, {
    cancel_reason: cancelReason.value || undefined,
  })
  if (!res.success) { pushToast(firstError(res, 'Could not apply the offer.'), 'error'); return }
  cancelModal.value = null
  detailSub.value = null
  pushToast('1 month applied — next charge pushed out', 'success')
  loadAll()
}
// Save-offer modal → "Pause instead": freeze rather than cancel.
async function retentionPause() {
  const s = cancelModal.value?.sub
  cancelModal.value = null
  if (s) await pauseSub(s)
}

// ── Pause / Freeze ──────────────────────────────────────────────────────────────
async function pauseSub(s) {
  if (!(await askConfirm({
    title: 'Pause subscription?',
    message: 'Billing is frozen and upcoming sessions are skipped. You can resume any time — no charge is taken while paused.',
    danger: false, confirmText: 'Pause', cancelText: 'Keep active',
  }))) return
  const res = await pauseSubscription(s.id)
  if (!res.success) { pushToast(firstError(res, 'Could not pause subscription.'), 'error'); return }
  pushToast('Subscription paused', 'success')
  detailSub.value = null
  loadAll()
}
async function resumeSub(s) {
  const res = await resumeSubscription(s.id)
  if (!res.success) { pushToast(firstError(res, 'Could not resume subscription.'), 'error'); return }
  pushToast('Subscription resumed', 'success')
  detailSub.value = null
  loadAll()
}

// ── Dunning / Past-due ──────────────────────────────────────────────────────────
async function markPaymentFailed(s) {
  if (!(await askConfirm({
    title: 'Mark payment failed?',
    message: 'Marks this member past-due (a recurring charge could not be collected). You can retry the charge afterwards.',
    danger: false, confirmText: 'Mark past-due', cancelText: 'Back',
  }))) return
  const res = await markSubscriptionPastDue(s.id)
  if (!res.success) { pushToast(firstError(res, 'Could not update subscription.'), 'error'); return }
  try { const program = programById(s.program_id); if (program) notifyMembershipPaymentFailed({ ownerId: program.owner_user_id, sub: s, program }) } catch { /* non-blocking */ }
  pushToast('Member marked past-due', 'success')
  detailSub.value = null
  loadAll()
}
async function winBack(s) {
  const program = programById(s.program_id)
  if (!program) return
  if (!(await askConfirm({
    title: 'Send win-back message?',
    message: `Send ${s.customer_name || 'this member'} your win-back message inviting them back to “${program.name}”.`,
    danger: false, confirmText: 'Send win-back', cancelText: 'Back',
  }))) return
  await winbackSubscription(s.id)
  try { notifyMembershipWinback({ ownerId: program.owner_user_id, sub: s, program }) } catch { /* non-blocking */ }
  pushToast('Win-back message sent (WhatsApp, if the channel is connected).', 'success')
}
async function retryCharge(s) {
  const program = programById(s.program_id)
  if (!program) return
  if (!(program.status === 'active' && rowState(program) === 'active')) {
    pushToast('This program is paused or archived — its subscriptions can’t be charged.', 'error')
    return
  }
  const res = await retryChargeSubscription(s.id)
  if (!res.success) { pushToast(firstError(res, 'Retry failed — charge could not be collected.'), 'error'); return }
  pushToast('Charge retried & collected', 'success')
  detailSub.value = null
  loadAll()
}
// Members currently past-due (respecting the same scope as the table) — drives the strip.
const pastDueSubs = computed(() => filteredSortedSubs.value.filter(s => s.status === 'past_due'))

function daysLeftInTrial(s) {
  if (!s.trial_ends_at) return 0
  return Math.max(0, Math.ceil((new Date(s.trial_ends_at) - new Date()) / (1000 * 60 * 60 * 24)))
}

// ── Subscription detail drawer ──────────────────────────────────────────────────
const detailSub = ref(null)
const detailSubPayments = ref([])
async function openSubDetail(s) {
  detailSub.value = s
  detailSubPayments.value = []
  const res = await fetchSubscriptionPayments(s.id)
  detailSubPayments.value = res.success ? (res.data?.payments || []) : []
}
function closeSubDetail() { detailSub.value = null }
const detailSubProgram = computed(() => detailSub.value ? programById(detailSub.value.program_id) : null)
function subStatusLabel(s) {
  if (!s) return ''
  if (s.status === 'paused') return 'Paused'
  if (s.status === 'past_due') return 'Payment failed'
  if (s.trial_status === 'in_trial') return 'In Trial'
  if (s.trial_status === 'cancelled_in_trial') return 'Cancelled (trial)'
  if (s.status === 'cancelled') return 'Cancelled'
  if (s.trial_status === 'converted' && s.status === 'active') return 'Active'
  return s.status || '—'
}
const PT_LABEL = { subscription_commission: 'Commission', subscription_earning: 'Subscription' }
const pmtTypeLabel = (t) => PT_LABEL[t] || t
const pmtStatusBadge = (s) => ({ paid: 'badge-paid', pending: 'badge-trial', refunded: 'badge-cancelled' }[s] || '')

// ── Filters / sort / totals ──
const subSearch = ref('')
const subFilterStatus = ref('')
const subFilterProgram = ref('')
const subFilterLocation = ref('')
const subDateFrom = ref('')
const subDateTo = ref('')
const subAmountMin = ref('')
const subAmountMax = ref('')
const subSortKey = ref('started_at')
const subSortDir = ref('desc')
const subFiltersOpen = ref(false)
const filterCountry = ref([])
const filterCity = ref([])

const SUB_STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'in_trial', label: 'In Trial' },
  { value: 'converted', label: 'Active (paid)' },
  { value: 'paused', label: 'Paused' },
  { value: 'past_due', label: 'Payment failed' },
  { value: 'cancelled_in_trial', label: 'Cancelled in trial' },
  { value: 'cancelled', label: 'Cancelled' },
]

function setSubSort(key) {
  if (subSortKey.value === key) subSortDir.value = subSortDir.value === 'asc' ? 'desc' : 'asc'
  else { subSortKey.value = key; subSortDir.value = key === 'started_at' || key === 'next_billing_at' ? 'desc' : 'asc' }
}
function subSortIcon(key) { return subSortKey.value !== key ? 'none' : subSortDir.value }
function clearSubFilters() {
  subSearch.value = ''; subFilterStatus.value = ''; subFilterProgram.value = ''; subFilterLocation.value = ''
  subDateFrom.value = ''; subDateTo.value = ''; subAmountMin.value = ''; subAmountMax.value = ''
}
const activeSubFilterCount = computed(() => [
  subFilterStatus.value, subFilterProgram.value, subFilterLocation.value,
  subDateFrom.value, subDateTo.value, subAmountMin.value, subAmountMax.value,
].filter(Boolean).length)

const scopedSubscriptions = computed(() => {
  let list = subscriptions.value.slice()
  if (!isSuperAdmin.value) {
    // Providers: their own programs' members. country_admin / platform_staff:
    // members of programs inside their jurisdiction (providerOwnPrograms is
    // already jurisdiction-scoped for them).
    const mine = myProgramIds.value
    list = list.filter(s => mine.has(s.program_id))
  }
  return list
})
const filteredSortedSubs = computed(() => {
  let list = scopedSubscriptions.value.slice()
  const q = subSearch.value.trim().toLowerCase()
  if (q) {
    list = list.filter(s =>
      (s.customer_name || '').toLowerCase().includes(q) ||
      (s.customer_email || '').toLowerCase().includes(q) ||
      (programById(s.program_id)?.name || '').toLowerCase().includes(q)
    )
  }
  if (subFilterStatus.value) {
    const f = subFilterStatus.value
    list = list.filter(s => {
      if (f === 'in_trial') return s.trial_status === 'in_trial' && s.status !== 'paused' && s.status !== 'past_due'
      if (f === 'converted') return s.trial_status === 'converted' && s.status === 'active'
      if (f === 'paused') return s.status === 'paused'
      if (f === 'past_due') return s.status === 'past_due'
      if (f === 'cancelled_in_trial') return s.trial_status === 'cancelled_in_trial'
      if (f === 'cancelled') return s.status === 'cancelled'
      return true
    })
  }
  if (subFilterProgram.value) list = list.filter(s => s.program_id === subFilterProgram.value)
  if (subFilterLocation.value) list = list.filter(s => programById(s.program_id)?.location_id === subFilterLocation.value)
  if (filterCountry.value.length || filterCity.value.length) list = list.filter(s => matchesLocationGeo(programById(s.program_id)?.location_id, locations.value, cities.value, filterCountry.value, filterCity.value))
  if (subDateFrom.value) list = list.filter(s => s.started_at >= subDateFrom.value)
  if (subDateTo.value) list = list.filter(s => s.started_at <= subDateTo.value)
  if (subAmountMin.value !== '') list = list.filter(s => Number(s.monthly_amount) >= Number(subAmountMin.value))
  if (subAmountMax.value !== '') list = list.filter(s => Number(s.monthly_amount) <= Number(subAmountMax.value))
  list.sort((a, b) => {
    let av = a[subSortKey.value], bv = b[subSortKey.value]
    if (subSortKey.value === 'program') { av = programById(a.program_id)?.name || ''; bv = programById(b.program_id)?.name || '' }
    if (subSortKey.value === 'location') { av = locationName(programById(a.program_id)?.location_id); bv = locationName(programById(b.program_id)?.location_id) }
    if (typeof av === 'string') av = av.toLowerCase()
    if (typeof bv === 'string') bv = bv.toLowerCase()
    if (av < bv) return subSortDir.value === 'asc' ? -1 : 1
    if (av > bv) return subSortDir.value === 'asc' ? 1 : -1
    return 0
  })
  return list
})

const { page: subPage, perPage: subPerPage, totalPages: subTotalPages, paginated: paginatedSubs, visiblePages: subVisiblePages, goTo: subGoTo } = usePagination(filteredSortedSubs)

// ── Selection & bulk actions (pattern ported from Customers.vue) ─────────────────
const selectedIds = ref(new Set())
function toggleSelect(id) {
  const s = new Set(selectedIds.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selectedIds.value = s
}
// Header checkbox selects/clears EVERY row matching the current filters (all pages).
const allFilteredSelected = computed(() =>
  filteredSortedSubs.value.length > 0 && filteredSortedSubs.value.every(s => selectedIds.value.has(s.id))
)
function toggleSelectAll() {
  selectedIds.value = allFilteredSelected.value ? new Set() : new Set(filteredSortedSubs.value.map(s => s.id))
}
function clearSelection() { selectedIds.value = new Set() }
const selectedSubs = computed(() => subscriptions.value.filter(s => selectedIds.value.has(s.id)))
// Only cancellable (active / paused / past-due / in-trial) selections count for bulk-cancel.
const selectedCancellable = computed(() => selectedSubs.value.filter(s => s.status !== 'cancelled'))

async function bulkCancelSelected() {
  const targets = selectedCancellable.value
  if (!targets.length) { pushToast('None of the selected members can be cancelled.', 'error'); return }
  if (!(await askConfirm({
    title: `Cancel ${targets.length} subscription${targets.length !== 1 ? 's' : ''}?`,
    message: 'The selected members will be cancelled and their upcoming sessions removed. This cannot be undone.',
    confirmText: 'Cancel them', cancelText: 'Keep them',
  }))) return
  const res = await bulkCancelSubscriptions(targets.map(s => s.id))
  clearSelection()
  if (!res.success) {
    pushToast(firstError(res, 'Could not cancel subscriptions.'), 'error')
    return
  }
  const ok = Number(res.data?.count) || 0
  for (const s of targets) {
    try {
      const program = programById(s.program_id)
      if (program) notifyMembershipCancelled({ ownerId: program.owner_user_id, sub: s, program })
    } catch { /* non-blocking */ }
  }
  pushToast(`${ok} subscription${ok !== 1 ? 's' : ''} cancelled`, ok ? 'success' : 'error')
  detailSub.value = null
  loadAll()
}
// Export just the selected rows, reusing the shared CSV column config.
function bulkExportSelected() {
  const rows = selectedSubs.value
  if (!rows.length) return
  downloadCSV(rows, subExportColumns(), 'subscriptions-selected')
}

function subCurrency(s) {
  const prog = programById(s?.program_id)
  return prog ? (prog.currency || currencyForLocation(prog.location_id)) : ''
}
const subSummaryCurrency = computed(() => {
  const first = filteredSortedSubs.value[0]
  if (first) return subCurrency(first)
  const anyProg = providerOwnPrograms.value[0]
  return anyProg ? (anyProg.currency || currencyForLocation(anyProg.location_id)) : ''
})
const subTotals = computed(() => {
  const list = filteredSortedSubs.value
  // 'active' means the paid/trialling live status ONLY — paused + past_due members are NOT
  // billing right now, so they're excluded here and therefore never counted in MRR / payout /
  // platform / the paid-Active figure.
  const active = list.filter(s => s.status === 'active')
  // Comped members are members, but $0 — exclude them from the paid-Active count and
  // from all revenue (MRR / payout / platform) so they don't inflate the money figures.
  const paid = active.filter(s => s.trial_status === 'converted' && !isComp(s))
  const inTrial = active.filter(s => s.trial_status === 'in_trial')
  const cancelled = list.filter(s => s.status === 'cancelled')
  const paused = list.filter(s => s.status === 'paused')
  const pastDue = list.filter(s => s.status === 'past_due')
  const mrr = paid.reduce((a, s) => a + Number(s.monthly_amount || 0), 0)
  const platform = paid.reduce((a, s) => a + Number(s.platform_fee || 0), 0)
  const payout = paid.reduce((a, s) => a + Number(s.provider_payout || 0), 0)

  // Trial conversion: converted / (converted + cancelled-in-trial). Both are existing
  // trial_status values, so this is derived from data already loaded (no new query).
  const converted = list.filter(s => s.trial_status === 'converted').length
  const churnedInTrial = list.filter(s => s.trial_status === 'cancelled_in_trial').length
  const trialDenom = converted + churnedInTrial
  const trialConversion = trialDenom > 0 ? Math.round((converted / trialDenom) * 100) : null

  // Churn (30d): members cancelled in the last 30 days / members active ~30 days ago
  // (approx: currently-active + those cancelled in the last 30 days). The cancel timestamp
  // is taken from the best available signal — see cancelTimestamp().
  const now = Date.now()
  const THIRTY_D = 30 * 24 * 60 * 60 * 1000
  const cancelledLast30 = cancelled.filter(s => {
    const ts = cancelTimestamp(s)
    return ts != null && (now - ts) <= THIRTY_D
  }).length
  const churnDenom = active.length + cancelledLast30
  const churn30 = churnDenom > 0 ? Math.round((cancelledLast30 / churnDenom) * 100) : null

  // Cancellation-reason breakdown (graceful: cancel_reason only exists post-migration).
  // "saved:" entries are retained members, so they're excluded from the churn reasons.
  const reasonCounts = {}
  for (const s of cancelled) {
    const r = s.cancel_reason
    if (!r || String(r).startsWith('saved')) continue
    reasonCounts[r] = (reasonCounts[r] || 0) + 1
  }
  const reasonBreakdown = Object.entries(reasonCounts)
    .map(([value, count]) => ({ value, count, label: (CANCEL_REASONS.find(x => x.value === value)?.label || value) }))
    .sort((a, b) => b.count - a.count)

  return {
    total: list.length, paid: paid.length, inTrial: inTrial.length, cancelled: cancelled.length,
    paused: paused.length, pastDue: pastDue.length,
    mrr, platform, payout, trialConversion, churn30, reasonBreakdown,
  }
})

const activePrograms = computed(() => programs.value.filter(x => x.status === 'active' && rowState(x) === 'active'))
</script>

<template>
  <ExportDialog :open="showExportModal" title="Export subscriptions" noun="subscriptions" :filtered-count="filteredSortedSubs.length" :all-count="scopedSubscriptions.length" @close="showExportModal = false" @export="exportSubs" />
  <div :class="embedded ? 'sub-embed' : 'page'">
    <div class="page-header">
      <div v-if="!embedded">
        <h1 class="page-title">Subscriptions</h1>
        <div class="page-subtitle">Manage members, billing status and upcoming renewals..</div>
      </div>
      <div class="sub-head-actions">
        <button v-if="canExport" class="btn btn-secondary" @click="showExportModal = true"><Download :size="14" />Export<span v-if="!isPremium" class="prem-lock">Premium</span></button>
        <button v-if="canManageSubs" class="btn btn-primary" @click="openNewSub('')"><Plus :size="16" />Add subscriber</button>
      </div>
    </div>

    <!-- Toolbar -->
    <FilterBar v-if="subscriptions.length"
      v-model:search="subSearch"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="jCountries"
      :cities="jCities"
      :extra-count="activeSubFilterCount"
      search-placeholder="Search name, email, program..."
      @clear="clearSubFilters"
    >
      <div class="lf-row">
        <span class="lf-label">Status</span>
        <div class="lf-chips">
          <button v-for="opt in SUB_STATUS_OPTIONS" :key="opt.value" type="button" :class="{ on: subFilterStatus === opt.value }" @click="subFilterStatus = subFilterStatus === opt.value ? '' : opt.value">{{ opt.label }}</button>
        </div>
      </div>
      <div class="lf-selects">
        <div class="lf-field"><label>Program</label><select class="filter-select" v-model="subFilterProgram"><option value="">All programs</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
        <div class="lf-field"><label>Location</label><select class="filter-select" v-model="subFilterLocation"><option value="">All locations</option><option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
      </div>
      <div class="lf-selects">
        <div class="lf-field"><label>Start from</label><input type="date" class="filter-select" v-model="subDateFrom" /></div>
        <div class="lf-field"><label>Start to</label><input type="date" class="filter-select" v-model="subDateTo" /></div>
        <div class="lf-field"><label>Amount min</label><input type="number" class="filter-select" v-model="subAmountMin" placeholder="0" /></div>
        <div class="lf-field"><label>Amount max</label><input type="number" class="filter-select" v-model="subAmountMax" placeholder="∞" /></div>
      </div>
    </FilterBar>

    <!-- Past-due / dunning strip: actionable summary of members whose charge failed. -->
    <div v-if="pastDueSubs.length" class="sub-pastdue-strip">
      <div class="pd-left">
        <AlertTriangle :size="16" />
        <span><strong>{{ pastDueSubs.length }}</strong> member{{ pastDueSubs.length !== 1 ? 's' : '' }} past due</span>
        <span class="pd-sub">A recurring charge failed — retry or follow up.</span>
      </div>
      <button v-if="subFilterStatus !== 'past_due'" class="btn btn-sm btn-secondary" @click="subFilterStatus = 'past_due'">Show past-due</button>
      <button v-else class="btn btn-sm btn-secondary" @click="subFilterStatus = ''">Clear filter</button>
    </div>

    <!-- Empty state -->
    <div v-if="!subscriptions.length" class="sub-empty-state">
      <Users :size="32" style="color:var(--text-muted); opacity:0.4" />
      <div class="sub-empty-title">No members yet</div>
      <div class="sub-empty-sub">Add a subscriber to a program to get started.</div>
      <button v-if="canManageSubs" class="btn btn-primary btn-sm" @click="openNewSub('')"><Plus :size="14" />Add Subscriber</button>
    </div>

    <template v-else>
      <!-- Summary -->
      <div class="sub-summary-row">
        <div class="sub-stat"><div class="sub-stat-val">{{ subTotals.total }}</div><div class="sub-stat-lbl">Total</div></div>
        <div class="sub-stat-sep"></div>
        <div class="sub-stat"><div class="sub-stat-val trial">{{ subTotals.inTrial }}</div><div class="sub-stat-lbl">In Trial</div></div>
        <div class="sub-stat"><div class="sub-stat-val paid">{{ subTotals.paid }}</div><div class="sub-stat-lbl">Active (paid)</div></div>
        <div v-if="subTotals.paused" class="sub-stat"><div class="sub-stat-val paused">{{ subTotals.paused }}</div><div class="sub-stat-lbl">Paused</div></div>
        <div v-if="subTotals.pastDue" class="sub-stat"><div class="sub-stat-val pastdue">{{ subTotals.pastDue }}</div><div class="sub-stat-lbl">Past due</div></div>
        <div class="sub-stat"><div class="sub-stat-val cancelled">{{ subTotals.cancelled }}</div><div class="sub-stat-lbl">Cancelled</div></div>
        <div class="sub-stat-sep"></div>
        <div class="sub-stat"><div class="sub-stat-val mrr">{{ fmtMoney(subTotals.mrr, subSummaryCurrency) }}</div><div class="sub-stat-lbl">MRR</div></div>
        <div class="sub-stat"><div class="sub-stat-val payout">{{ fmtMoney(subTotals.payout, subSummaryCurrency) }}</div><div class="sub-stat-lbl">Provider payout</div></div>
        <div class="sub-stat"><div class="sub-stat-val platform">{{ fmtMoney(subTotals.platform, subSummaryCurrency) }}</div><div class="sub-stat-lbl">Platform fee</div></div>
        <div class="sub-stat-sep"></div>
        <div class="sub-stat"><div class="sub-stat-val conversion">{{ subTotals.trialConversion === null ? '—' : subTotals.trialConversion + '%' }}</div><div class="sub-stat-lbl">Trial conversion</div></div>
        <div class="sub-stat"><div class="sub-stat-val churn">{{ subTotals.churn30 === null ? '—' : subTotals.churn30 + '%' }}</div><div class="sub-stat-lbl">Churn (30d)</div></div>
      </div>

      <!-- Cancellation-reason breakdown (only if any reasons recorded) -->
      <div v-if="subTotals.reasonBreakdown.length" class="sub-reason-strip">
        <span class="srs-label">Why they cancelled:</span>
        <span v-for="r in subTotals.reasonBreakdown" :key="r.value" class="srs-chip">{{ r.label }} · <b>{{ r.count }}</b></span>
      </div>

      <!-- ── Bulk action bar ────────────────────────────────────────────────── -->
      <Transition name="bulk-slide">
        <div v-if="selectedIds.size > 0" class="bulk-bar">
          <div class="bulk-info">
            <div class="bulk-check-icon"><CheckCircle :size="15" /></div>
            <strong>{{ selectedIds.size }}</strong>&nbsp;member{{ selectedIds.size !== 1 ? 's' : '' }} selected
            <button v-if="!allFilteredSelected && filteredSortedSubs.length > selectedIds.size" class="bulk-selectall-link" @click="selectedIds = new Set(filteredSortedSubs.map(s => s.id))">
              Select all {{ filteredSortedSubs.length }} matching
            </button>
          </div>
          <div class="bulk-actions">
            <button v-if="canExport" class="bulk-btn" @click="requirePremium('exports') && bulkExportSelected()"><Download :size="14" />Export selected<span v-if="!isPremium" class="prem-lock">Premium</span></button>
            <button v-if="canManageSubs" class="bulk-btn danger" @click="bulkCancelSelected"><X :size="14" />Cancel selected</button>
            <button class="bulk-deselect" @click="clearSelection" aria-label="Clear selection"><X :size="15" /></button>
          </div>
        </div>
      </Transition>

      <!-- Table -->
      <div class="card table-wrap sub-table-wrap">
        <table class="sub-table">
          <thead>
            <tr>
              <th class="th-check">
                <input type="checkbox" class="row-checkbox"
                  :checked="allFilteredSelected"
                  :indeterminate.prop="selectedIds.size > 0 && !allFilteredSelected"
                  title="Select all matching members (all pages)"
                  @change="toggleSelectAll" />
              </th>
              <th class="sortable" @click="setSubSort('customer_name')">Customer <span class="sort-icon"><ChevronUp v-if="subSortIcon('customer_name')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('customer_name')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('program')">Program <span class="sort-icon"><ChevronUp v-if="subSortIcon('program')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('program')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('location')">Location <span class="sort-icon"><ChevronUp v-if="subSortIcon('location')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('location')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('started_at')">Started <span class="sort-icon"><ChevronUp v-if="subSortIcon('started_at')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('started_at')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('trial_ends_at')">Trial ends <span class="sort-icon"><ChevronUp v-if="subSortIcon('trial_ends_at')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('trial_ends_at')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('next_billing_at')">Next billing <span class="sort-icon"><ChevronUp v-if="subSortIcon('next_billing_at')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('next_billing_at')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th class="sortable" @click="setSubSort('monthly_amount')">Price <span class="sort-icon"><ChevronUp v-if="subSortIcon('monthly_amount')==='asc'" :size="12" /><ChevronDownIcon v-else-if="subSortIcon('monthly_amount')==='desc'" :size="12" /><ChevronsUpDown v-else :size="12" class="muted-icon" /></span></th>
              <th>Provider</th>
              <th>Platform</th>
              <th>Sessions</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!filteredSortedSubs.length">
              <td colspan="13">
                <div class="sub-empty-filtered">
                  <Search :size="20" style="opacity:0.3" />
                  <span>No members match your filters.</span>
                  <button class="btn btn-secondary btn-sm" @click="clearSubFilters">Clear filters</button>
                </div>
              </td>
            </tr>
            <tr v-for="s in paginatedSubs" :key="s.id" class="sub-row" :class="{ 'row-selected': selectedIds.has(s.id) }" @click="openSubDetail(s)">
              <td class="td-check" @click.stop>
                <input type="checkbox" class="row-checkbox" :checked="selectedIds.has(s.id)" @change="toggleSelect(s.id)" />
              </td>
              <td>
                <div class="sub-customer">
                  <div class="sub-avatar">{{ (s.customer_name || s.customer_email || '?')[0].toUpperCase() }}</div>
                  <div>
                    <div class="sub-name">{{ s.customer_name || '—' }}</div>
                    <div class="sub-email">{{ s.customer_email || '—' }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="sub-prog-name">{{ programById(s.program_id)?.name || '—' }}</div>
                <div class="sub-meta">{{ programById(s.program_id)?.sport_category || '' }}</div>
              </td>
              <td class="sub-prog-name">{{ locationName(programById(s.program_id)?.location_id) }}</td>
              <td>{{ s.started_at || '—' }}</td>
              <td>
                <span v-if="s.trial_status === 'in_trial'" class="trial-days-left">{{ daysLeftInTrial(s) }}d left</span>
                <span v-else class="muted">{{ s.trial_ends_at || '—' }}</span>
              </td>
              <td>
                <span v-if="s.status === 'cancelled'" class="muted">—</span>
                <span v-else-if="s.status === 'paused'" class="muted">Paused</span>
                <span v-else-if="s.status === 'past_due'" class="muted">Payment failed</span>
                <span v-else-if="s.trial_status === 'in_trial'" class="muted">After trial</span>
                <span v-else>{{ s.next_billing_at || '—' }}</span>
              </td>
              <td class="mono">{{ fmtMoney(s.monthly_amount, subCurrency(s)) }}</td>
              <td class="mono provider-share">{{ fmtMoney(s.provider_payout, subCurrency(s)) }}</td>
              <td class="mono platform-fee">{{ fmtMoney(s.platform_fee, subCurrency(s)) }}</td>
              <td><span class="sessions-count">{{ sessionsForSub(s.id) }}</span></td>
              <td>
                <span class="sub-status-badge" :class="{ 'badge-trial': s.status !== 'paused' && s.status !== 'past_due' && s.trial_status === 'in_trial', 'badge-paid': s.trial_status === 'converted' && s.status === 'active', 'badge-cancelled': s.status === 'cancelled', 'badge-paused': s.status === 'paused', 'badge-pastdue': s.status === 'past_due' }">
                  <span v-if="s.status === 'paused'">Paused</span>
                  <span v-else-if="s.status === 'past_due'">Payment failed</span>
                  <span v-else-if="s.trial_status === 'in_trial'">In Trial</span>
                  <span v-else-if="s.trial_status === 'converted' && s.status === 'active'">Active</span>
                  <span v-else-if="s.trial_status === 'cancelled_in_trial'">Cancelled (trial)</span>
                  <span v-else>{{ s.status }}</span>
                </span>
                <span v-if="isComp(s)" class="sub-status-badge badge-comp" :title="s.comp_reason || 'Comped — no charge'">Comp</span>
              </td>
              <td>
                <div class="sub-actions">
                  <button v-if="hasAnswers(s)" class="btn-icon" @click.stop="answersFor = s" title="View sign-up answers"><FileText :size="15" /></button>
                  <template v-if="canManageSubs && s.status === 'active'">
                    <button v-if="s.trial_status === 'in_trial'" class="btn btn-sm btn-secondary" @click.stop="chargeSub(s)" title="End trial & charge now"><DollarSign :size="12" />Charge</button>
                    <button v-else-if="s.trial_status === 'converted'" class="btn btn-sm btn-secondary" @click.stop="chargeSub(s)" title="Charge next month"><Repeat :size="12" />Charge</button>
                    <button class="btn-icon" @click.stop="pauseSub(s)" title="Pause / freeze"><Pause :size="15" /></button>
                    <button class="btn-icon danger" @click.stop="cancelSub(s)" title="Cancel"><X :size="15" /></button>
                  </template>
                  <template v-else-if="canManageSubs && s.status === 'paused'">
                    <button class="btn btn-sm btn-secondary" @click.stop="resumeSub(s)" title="Resume billing"><Play :size="12" />Resume</button>
                    <button class="btn-icon danger" @click.stop="cancelSub(s)" title="Cancel"><X :size="15" /></button>
                  </template>
                  <template v-else-if="canManageSubs && s.status === 'past_due'">
                    <button class="btn btn-sm btn-secondary" @click.stop="retryCharge(s)" title="Retry the failed charge"><RotateCcw :size="12" />Retry</button>
                    <button class="btn-icon danger" @click.stop="cancelSub(s)" title="Cancel"><X :size="15" /></button>
                  </template>
                  <template v-else-if="canManageSubs && s.status === 'cancelled'">
                    <button class="btn btn-sm btn-secondary" @click.stop="winBack(s)" title="Send a win-back message"><HeartHandshake :size="12" />Win back</button>
                  </template>
                  <button v-if="canManageSubs && s.status === 'active' && s.trial_status === 'converted'" class="btn-icon" @click.stop="markPaymentFailed(s)" title="Mark payment failed"><AlertTriangle :size="15" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        :page="subPage" :perPage="subPerPage" :totalPages="subTotalPages"
        :total="filteredSortedSubs.length" :visiblePages="subVisiblePages"
        @update:perPage="subPerPage = $event" @goTo="subGoTo"
      />
    </template>

    <!-- ── Subscription detail drawer ──────────────────────────────────────── -->
    <div v-if="detailSub" class="drawer-backdrop" @click.self="closeSubDetail">
      <div class="sub-drawer" v-dialog="closeSubDetail" aria-label="Membership subscriber details">
        <div class="sd-head">
          <div class="sd-head-main">
            <div class="sd-avatar">{{ (detailSub.customer_name || detailSub.customer_email || '?')[0].toUpperCase() }}</div>
            <div>
              <div class="sd-name">{{ detailSub.customer_name || '—' }}</div>
              <div class="sd-email">{{ detailSub.customer_email || '—' }}</div>
            </div>
          </div>
          <button class="btn-icon" @click="closeSubDetail" aria-label="Close"><X :size="18"/></button>
        </div>

        <div class="sd-body">
          <div class="sd-status-row">
            <span class="sub-status-badge" :class="{ 'badge-trial': detailSub.status !== 'paused' && detailSub.status !== 'past_due' && detailSub.trial_status === 'in_trial', 'badge-paid': detailSub.trial_status === 'converted' && detailSub.status === 'active', 'badge-cancelled': detailSub.status === 'cancelled' || detailSub.trial_status === 'cancelled_in_trial', 'badge-paused': detailSub.status === 'paused', 'badge-pastdue': detailSub.status === 'past_due' }">{{ subStatusLabel(detailSub) }}</span>
            <span v-if="isComp(detailSub)" class="sub-status-badge badge-comp" :title="detailSub.comp_reason || 'Comped — no charge'">Comp</span>
            <span v-if="detailSub.status !== 'paused' && detailSub.status !== 'past_due' && detailSub.trial_status === 'in_trial'" class="trial-days-left">{{ daysLeftInTrial(detailSub) }}d trial left</span>
          </div>
          <div v-if="detailSub.status === 'past_due'" class="sd-alert">A recurring charge could not be collected{{ detailSub.payment_failed_at ? ' on ' + String(detailSub.payment_failed_at).slice(0,10) : '' }}. Retry the charge or cancel.</div>
          <div v-else-if="detailSub.status === 'paused'" class="sd-alert paused">Billing is frozen. Resume to re-derive the next charge date, or cancel.</div>
          <div v-if="detailSub.status === 'cancelled' && detailSub.cancel_reason && !String(detailSub.cancel_reason).startsWith('saved')" class="sd-comp-reason">Cancel reason: {{ (CANCEL_REASONS.find(r => r.value === detailSub.cancel_reason)?.label || detailSub.cancel_reason) }}</div>
          <div v-if="isComp(detailSub) && detailSub.comp_reason" class="sd-comp-reason">Comp reason: {{ detailSub.comp_reason }}</div>

          <div class="sd-section">
            <div class="sd-section-title">Membership</div>
            <div class="sd-grid">
              <div class="sd-field"><span>Program</span><b>{{ detailSubProgram?.name || '—' }}</b></div>
              <div class="sd-field"><span>Sport</span><b>{{ detailSubProgram?.sport_category || '—' }}</b></div>
              <div class="sd-field"><span>Location</span><b>{{ locationName(detailSubProgram?.location_id) }}</b></div>
              <div class="sd-field"><span>Provider</span><b>{{ providerName(detailSubProgram?.owner_user_id) }}</b></div>
              <div class="sd-field"><span>Billing</span><b>{{ intervalLabel(detailSub.billing_interval) }}</b></div>
              <div class="sd-field"><span>Sessions</span><b>{{ sessionsForSub(detailSub.id) }}</b></div>
            </div>
          </div>

          <div class="sd-section">
            <div class="sd-section-title">Billing</div>
            <div class="sd-grid">
              <div class="sd-field"><span>Amount</span><b>{{ fmtMoney(detailSub.monthly_amount, subCurrency(detailSub)) }}</b></div>
              <div class="sd-field"><span>Provider payout</span><b class="provider-share">{{ fmtMoney(detailSub.provider_payout, subCurrency(detailSub)) }}</b></div>
              <div class="sd-field"><span>Platform fee</span><b class="platform-fee">{{ fmtMoney(detailSub.platform_fee, subCurrency(detailSub)) }}</b></div>
              <div class="sd-field"><span>Started</span><b>{{ detailSub.started_at || '—' }}</b></div>
              <div class="sd-field"><span>Trial ends</span><b>{{ detailSub.trial_ends_at || '—' }}</b></div>
              <div class="sd-field"><span>Next billing</span><b>{{ detailSub.status === 'cancelled' ? '—' : (detailSub.next_billing_at || '—') }}</b></div>
            </div>
          </div>

          <div v-if="detailSubPayments.length" class="sd-section">
            <div class="sd-section-title">Billing history ({{ detailSubPayments.length }})</div>
            <div class="sd-ledger">
              <div v-for="p in detailSubPayments" :key="p.id" class="sd-ledger-row">
                <span class="sub-status-badge" :class="pmtStatusBadge(p.status)">{{ pmtTypeLabel(p.payment_type) }}</span>
                <span class="sd-ledger-amt">{{ fmtMoney(p.amount, p.currency) }}</span>
                <span class="sd-ledger-to">{{ p.recipient === '@bookboll' || p.recipient === 'bookboll' ? 'Bookboll' : 'Provider' }}</span>
                <span class="sd-ledger-date">{{ (p.paid_at || p.created_at || '').slice(0,10) }}</span>
              </div>
            </div>
          </div>

          <div v-if="hasAnswers(detailSub)" class="sd-section">
            <div class="sd-section-title">Sign-up answers</div>
            <FormAnswers :fields="asFields(detailSubProgram?.signup_form)" :answers="detailSub.form_answers || {}" />
          </div>
        </div>

        <div class="sd-foot">
          <template v-if="canManageSubs && detailSub.status === 'active'">
            <button class="btn btn-secondary" @click="chargeSub(detailSub)">
              <component :is="detailSub.trial_status === 'in_trial' ? DollarSign : Repeat" :size="14" />{{ detailSub.trial_status === 'in_trial' ? 'End trial & charge' : 'Charge now' }}
            </button>
            <button class="btn btn-secondary" @click="pauseSub(detailSub)"><Pause :size="14" />Pause</button>
            <button v-if="detailSub.trial_status === 'converted'" class="btn btn-secondary" @click="markPaymentFailed(detailSub)"><AlertTriangle :size="14" />Mark payment failed</button>
            <button class="btn btn-danger-soft" @click="cancelSub(detailSub)"><X :size="14" />Cancel subscription</button>
          </template>
          <template v-else-if="canManageSubs && detailSub.status === 'paused'">
            <button class="btn btn-secondary" @click="resumeSub(detailSub)"><Play :size="14" />Resume</button>
            <button class="btn btn-danger-soft" @click="cancelSub(detailSub)"><X :size="14" />Cancel subscription</button>
          </template>
          <template v-else-if="canManageSubs && detailSub.status === 'past_due'">
            <button class="btn btn-secondary" @click="retryCharge(detailSub)"><RotateCcw :size="14" />Retry charge</button>
            <button class="btn btn-danger-soft" @click="cancelSub(detailSub)"><X :size="14" />Cancel subscription</button>
          </template>
          <button class="btn btn-secondary" @click="closeSubDetail">Close</button>
        </div>
      </div>
    </div>

    <!-- Sign-up answers -->
    <div v-if="answersFor" class="modal-backdrop" @click.self="answersFor = null">
      <div class="modal answers-modal" v-dialog>
        <div class="modal-header">
          <div class="modal-title">{{ answersFor.customer_name }} — sign-up answers</div>
          <button class="btn-icon" @click="answersFor = null" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <FormAnswers :fields="asFields(programById(answersFor.program_id)?.signup_form)" :answers="answersFor.form_answers || {}" />
        </div>
      </div>
    </div>

    <!-- CANCELLATION SAVE-OFFER MODAL -->
    <div v-if="cancelModal" class="modal-backdrop" @click.self="cancelModal = null">
      <div class="modal save-offer-modal" v-dialog>
        <div class="modal-header">
          <div class="modal-title">Before you cancel {{ cancelModal.sub.customer_name || 'this member' }}…</div>
          <button class="btn-icon" @click="cancelModal = null" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Why are they cancelling?</label>
            <select v-model="cancelReason">
              <option value="">Select a reason (optional)</option>
              <option v-for="r in CANCEL_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </div>
          <div class="save-offer-block">
            <div class="save-offer-title">Try to keep them</div>
            <button class="save-offer-cta" @click="retentionMonthOff">
              <DollarSign :size="16" />
              <span><b>Apply 1 month off next cycle</b><small>Push the next charge out by a month — keeps them subscribed.</small></span>
            </button>
            <button class="save-offer-cta" @click="retentionPause">
              <Pause :size="16" />
              <span><b>Pause instead</b><small>Freeze billing &amp; sessions — resume any time.</small></span>
            </button>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="cancelModal = null">Keep it</button>
          <button class="btn btn-danger-soft" @click="confirmCancelFromModal"><X :size="14" />Cancel anyway</button>
        </div>
      </div>
    </div>

    <!-- ADD SUBSCRIBER MODAL -->
    <div v-if="subModal" class="modal-backdrop" @click.self="requestCloseSub">
      <div class="modal" v-dialog="requestCloseSub" @input="markDirty" @change="markDirty">
        <div class="modal-header">
          <div class="modal-title">Add Subscriber</div>
          <button class="btn-icon" @click="requestCloseSub" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group"><label class="form-label">Program *</label>
            <select aria-label="Membership program" v-model="subForm.program_id" :class="{ 'input-invalid': subTriedSave && !subForm.program_id }">
              <option value="">Select a program</option>
              <option v-for="p in activePrograms" :key="p.id" :value="p.id">{{ p.name }} · {{ intervalLabel(p.billing_interval) }}</option>
            </select>
            <div class="field-hint">Only approved, active programs can accept members.</div>
          </div>
          <div class="form-group"><label class="form-label">Customer Name *</label>
            <input aria-label="Customer name" v-model="subForm.customer_name" :class="{ 'input-invalid': subTriedSave && !subForm.customer_name?.trim() }" />
          </div>
          <div class="form-group"><label class="form-label">Customer Email</label>
            <input aria-label="Customer email" type="email" v-model="subForm.customer_email" />
          </div>
          <div class="form-group"><label class="form-label">Start Date</label>
            <input aria-label="Membership start date" type="date" v-model="subForm.started_at" />
          </div>

          <!-- Comp / free member: waive the card + trial consent and record a $0 membership. -->
          <label class="comp-row">
            <input type="checkbox" v-model="subForm.comp" />
            <span>
              <b>Give a complimentary membership</b>
              <span class="comp-hint">This member will not be charged. No payment method is needed.</span>
            </span>
          </label>
          <div v-if="subForm.comp" class="form-group">
            <label class="form-label">Comp reason (optional)</label>
            <input aria-label="Complimentary membership reason" v-model="subForm.comp_reason" placeholder="e.g. staff, sponsor, goodwill" />
          </div>

          <div v-if="!subForm.comp" class="form-group"><label class="form-label">Saved payment reference *</label>
            <input aria-label="Saved payment reference" v-model="subForm.card_on_file_id" placeholder="Reference from your payment provider" aria-describedby="saved-payment-help" :class="{ 'input-invalid': subTriedSave && !subForm.card_on_file_id }" />
            <div id="saved-payment-help" class="field-hint">Use an existing saved payment reference, never a card number. {{ subUpfront ? 'A payment method is required — this plan is charged today.' : 'A payment method is required to start the free trial. Card is not charged until the trial ends.' }}</div>
          </div>

          <div v-if="!subForm.comp && programById(subForm.program_id) && subPlan" class="disclosure disclosure-trial">
            <div class="disclosure-head"><ShieldCheck :size="15" /><strong>{{ subUpfront ? 'Payment &amp; billing' : 'Free trial &amp; auto-billing' }}</strong></div>
            <div class="disclosure-body">
              <template v-if="subUpfront">
                <template v-if="subPlan.mode === 'discount'">
                  Charged <strong>{{ fmtMoney(subPlan.price, subCur) }} today</strong> ({{ subPlan.discount_months }} month{{ subPlan.discount_months !== 1 ? 's' : '' }} off the {{ fmtMoney(subPlan.listPrice, subCur) }} {{ intervalLabel(subPlan.interval).toLowerCase() }} price), then renews each {{ intervalLabel(subPlan.interval).toLowerCase() }} at the same rate.
                </template>
                <template v-else-if="subIsOneTime">
                  Charged <strong>{{ fmtMoney(subPlan.price, subCur) }} today</strong> — one payment for the whole program, no renewal.
                </template>
                <template v-else>
                  Charged <strong>{{ fmtMoney(subPlan.price, subCur) }} today</strong>, then renews each {{ intervalLabel(subPlan.interval).toLowerCase() }}.
                </template>
              </template>
              <template v-else-if="subTrialIsSession">
                This customer gets their <strong>first session free</strong>. If they don't cancel, their card is auto-charged <strong>{{ fmtMoney(subPlan.price, subCur) }}</strong> on their 2nd session<template v-if="!subIsOneTime"> and each {{ intervalLabel(subPlan.interval).toLowerCase() }} after</template>.
              </template>
              <template v-else>
                This customer gets <strong>{{ subPlan.trial_days }} days free</strong>. If they don't cancel, their card is auto-charged <strong>{{ fmtMoney(subPlan.price, subCur) }}</strong> on day {{ Number(subPlan.trial_days) + 1 }}<template v-if="!subIsOneTime"> and each {{ intervalLabel(subPlan.interval).toLowerCase() }} after</template>.
              </template>
            </div>
            <div class="split-row" style="margin-top:8px;">
              <div class="split-chip">Provider: {{ fmtMoney(subPlan.price * (100 - programById(subForm.program_id).commission_percentage) / 100, subCur) }}</div>
              <div class="split-chip platform">Platform {{ programById(subForm.program_id).commission_percentage }}%: {{ fmtMoney(subPlan.price * programById(subForm.program_id).commission_percentage / 100, subCur) }}</div>
            </div>
            <label class="consent-row">
              <input type="checkbox" v-model="subForm.trial_consent" />
              <span v-if="subUpfront">Customer authorizes payment of {{ fmtMoney(subPlan.price, subCur) }} today{{ subIsOneTime ? '' : ', renewing each ' + intervalLabel(subPlan.interval).toLowerCase() }}.</span>
              <span v-else>Customer consents to auto-charge after the free {{ subTrialIsSession ? 'session' : subPlan.trial_days + '-day trial' }}.</span>
            </label>
            <div class="field-hint" style="margin-top:6px;">Will generate {{ generateMonthlyDates(programById(subForm.program_id), subForm.started_at).length }} scheduled sessions for the first month ({{ formatDays(programById(subForm.program_id).day_of_week_mask) }}).</div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="requestCloseSub">Cancel</button>
          <button class="btn btn-primary" @click="saveSubscription"><Check :size="14" />{{ subForm.comp ? 'Add comp member' : (subUpfront ? 'Add &amp; charge' : 'Start free trial') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sub-section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 0 14px; flex-wrap: wrap; }
.sub-section-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sub-head-actions { display: flex; gap: 10px; align-items: center; }
.answers-modal { max-width: 440px; }

.sub-empty-state { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 56px 24px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; text-align: center; }
.sub-empty-title { font-size: 15px; font-weight: 600; color: var(--text); }
.sub-empty-sub { font-size: 13px; color: var(--text-muted); }
.sub-empty-filtered { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 32px; color: var(--text-muted); font-size: 13px; }

.sub-summary-row { display: flex; align-items: center; gap: 0; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 0 4px; margin-bottom: 12px; flex-wrap: wrap; overflow: hidden; }
.sub-stat { display: flex; flex-direction: column; align-items: center; padding: 12px 20px; gap: 2px; }
.sub-stat-val { font-size: 17px; font-weight: 700; color: var(--text); line-height: 1.2; white-space: nowrap; }
.sub-stat-val.trial { color: var(--warning-ink, #b45309); }
.sub-stat-val.paid { color: var(--positive-ink, #059669); }
.sub-stat-val.cancelled { color: var(--danger-ink, #dc2626); }
.sub-stat-val.mrr { color: var(--info-ink, #0891b2); font-size: 14px; }
.sub-stat-val.payout { color: var(--positive-ink, #059669); font-size: 14px; }
.sub-stat-val.platform { color: var(--warning-ink, #b45309); font-size: 14px; }
.sub-stat-val.conversion { color: var(--purple-ink, #7c3aed); }
.sub-stat-val.churn { color: var(--danger-ink, #dc2626); }
.sub-stat-lbl { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.sub-stat-sep { width: 1px; height: 32px; background: var(--border); margin: 0 4px; }

.status-pills { display: flex; gap: 5px; flex-wrap: wrap; }
.status-pill { padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted); cursor: pointer; transition: all 0.15s; white-space: nowrap; }
.status-pill:hover { border-color: var(--primary); color: var(--primary-ink); }
.status-pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.filter-toggle-btn { position: relative; }
.filter-toggle-btn.filter-active { border-color: var(--primary); color: var(--primary-ink); }
.filter-badge { background: var(--primary); color: #fff; border-radius: 999px; font-size: 10px; padding: 0 5px; margin-left: 4px; }

.filter-panel { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.filter-panel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.filter-panel-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }

.sub-table-wrap { padding: 0; overflow-x: auto; }
.sub-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sub-table thead tr { background: var(--surface-hover, #f8fafc); border-bottom: 2px solid var(--border); }
.sub-table th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); white-space: nowrap; user-select: none; }
.sub-table th.sortable { cursor: pointer; }
.sub-table th.sortable:hover { color: var(--text); }
.sort-icon { display: inline-flex; vertical-align: -2px; margin-left: 2px; }
.muted-icon { opacity: 0.4; }
.sub-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.sub-table tbody tr:last-child td { border-bottom: none; }
.sub-table tbody tr:hover td { background: var(--bg); }
.sub-customer { display: flex; align-items: center; gap: 10px; }
.sub-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sub-name { font-weight: 600; color: var(--text); }
.sub-email { font-size: 12px; color: var(--text-muted); }
.sub-prog-name { font-weight: 500; color: var(--text); }
.sub-meta { font-size: 12px; color: var(--text-muted); }
.mono { font-variant-numeric: tabular-nums; }
.provider-share { color: var(--positive-ink, #065f46); }
.platform-fee { color: var(--warning-ink, #92400e); }
.sessions-count { display: inline-flex; align-items: center; justify-content: center; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.trial-days-left { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.sub-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; border: 1px solid; }
.sub-status-badge.badge-trial { background: var(--warning-bg, #fffbeb); color: var(--warning-ink, #b45309); border-color: var(--warning-border, #fde68a); }
.sub-status-badge.badge-paid { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border-color: var(--positive-border, #a7f3d0); }
.sub-status-badge.badge-cancelled { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #b91c1c); border-color: var(--danger-border, #fecaca); }
.sub-status-badge.badge-comp { background: var(--purple-bg, #f5f3ff); color: var(--purple-ink, #6d28d9); border-color: var(--purple-border, #ddd6fe); margin-left: 6px; }
.sub-status-badge.badge-paused { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); border-color: var(--info-border, #bfdbfe); }
.sub-status-badge.badge-pastdue { background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #c2410c); border-color: var(--warning-border, #fed7aa); }
.sub-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

/* Paused / past-due summary stat colours */
.sub-stat-val.paused { color: var(--info-ink, #1d4ed8); }
.sub-stat-val.pastdue { color: var(--warning-ink, #c2410c); }

/* Past-due / dunning strip */
.sub-pastdue-strip { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: var(--warning-bg, #fff7ed); border: 1px solid var(--warning-border, #fed7aa); color: var(--warning-ink, #9a3412); border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; }
.pd-left { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pd-left strong { color: var(--warning-ink, #7c2d12); }
.pd-sub { color: var(--warning-ink, #b45309); font-size: 12px; }
.sub-pastdue-strip .btn { margin-left: auto; }

/* Cancellation-reason breakdown */
.sub-reason-strip { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: -4px 0 12px; font-size: 12px; }
.srs-label { color: var(--text-muted); font-weight: 600; }
.srs-chip { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #b91c1c); border: 1px solid var(--danger-border, #fecaca); border-radius: 999px; padding: 3px 10px; }

/* Selection checkbox column + selected row (ported from Customers.vue) */
.th-check, .td-check { width: 38px; padding-left: 14px !important; padding-right: 4px !important; }
.row-checkbox { width: 15px; height: 15px; accent-color: var(--primary); cursor: pointer; display: block; }
.sub-table tbody tr.row-selected td { background: var(--primary-soft) !important; }

/* Detail-drawer status alert */
.sd-alert { font-size: 12.5px; color: var(--warning-ink, #9a3412); background: var(--warning-bg, #fff7ed); border: 1px solid var(--warning-border, #fed7aa); border-radius: 8px; padding: 8px 10px; }
.sd-alert.paused { color: var(--info-ink, #1d4ed8); background: var(--info-bg, #eff6ff); border-color: var(--info-border, #bfdbfe); }

/* Bulk action bar (ported from Customers.vue) */
.bulk-bar { display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; background: linear-gradient(90deg, #0f766e 0%, #0d9488 100%); color: #fff; font-size: 13px; border-radius: 10px; margin-bottom: 12px; }
.bulk-info { display: flex; align-items: center; gap: 8px; font-weight: 500; }
.bulk-check-icon { opacity: 0.8; display: flex; }
.bulk-selectall-link { margin-left: 10px; font-size: 12px; font-weight: 600; color: #fff; text-decoration: underline; background: none; border: none; cursor: pointer; opacity: .9; }
.bulk-selectall-link:hover { opacity: 1; }
.bulk-actions { display: flex; align-items: center; gap: 8px; }
.bulk-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 7px; font-size: 12px; font-weight: 600; background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25); cursor: pointer; transition: all 0.15s; }
.bulk-btn:hover { background: rgba(255,255,255,0.28); }
.bulk-btn.danger { background: rgba(220,38,38,0.25); border-color: rgba(220,38,38,0.5); }
.bulk-btn.danger:hover { background: rgba(220,38,38,0.4); }
.bulk-deselect { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; color: rgba(255,255,255,0.7); cursor: pointer; transition: all 0.15s; background: none; border: none; }
.bulk-deselect:hover { background: rgba(255,255,255,0.15); color: #fff; }
.bulk-slide-enter-active, .bulk-slide-leave-active { transition: all 0.2s ease; overflow: hidden; }
.bulk-slide-enter-from, .bulk-slide-leave-to { opacity: 0; max-height: 0; padding-top: 0; padding-bottom: 0; }
.bulk-slide-enter-to, .bulk-slide-leave-from { opacity: 1; max-height: 60px; }

/* Cancellation save-offer modal */
.save-offer-modal { max-width: 460px; }
.save-offer-block { margin-top: 6px; }
.save-offer-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-bottom: 8px; }
.save-offer-cta { display: flex; align-items: flex-start; gap: 10px; width: 100%; text-align: left; padding: 12px; margin-bottom: 8px; background: var(--positive-bg, #ecfdf5); border: 1px solid var(--positive-border, #a7f3d0); border-radius: 10px; cursor: pointer; color: var(--positive-ink, #065f46); transition: all 0.15s; }
.save-offer-cta:hover { background: var(--positive-bg, #d1fae5); }
.save-offer-cta b { display: block; font-size: 13.5px; }
.save-offer-cta small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* Comp / free member */
.comp-row { display: flex; align-items: flex-start; gap: 10px; padding: 12px; margin-bottom: 14px; background: var(--purple-bg, #f5f3ff); border: 1px solid var(--purple-border, #ddd6fe); border-radius: 10px; cursor: pointer; }
.comp-row input { margin-top: 2px; }
.comp-row b { display: block; font-size: 13.5px; color: var(--purple-ink, #5b21b6); }
.comp-hint { display: block; font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.sd-comp-reason { font-size: 12.5px; color: var(--purple-ink, #6d28d9); background: var(--purple-bg, #f5f3ff); border: 1px solid var(--purple-border, #ddd6fe); border-radius: 8px; padding: 8px 10px; }

.disclosure { border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-top: 14px; background: var(--surface, #ffffff); }
.disclosure-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 14px; }
.disclosure-body { font-size: 13px; color: var(--text); line-height: 1.55; }
.disclosure-trial { background: var(--warning-bg, #fffbeb); border-color: var(--warning-border, #fde68a); }
.disclosure-trial .disclosure-head { color: var(--warning-ink, #92400e); }
.split-row { display: flex; gap: 8px; flex-wrap: wrap; }
.split-chip { font-size: 12px; font-weight: 600; background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border: 1px solid var(--positive-border, #a7f3d0); border-radius: 8px; padding: 4px 9px; }
.split-chip.platform { background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #9a3412); border-color: var(--warning-border, #fed7aa); }
.consent-row { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 10px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 8px; font-size: 13px; cursor: pointer; }
.consent-row input { margin-top: 2px; }
.field-hint { font-size: 12px; color: var(--text-muted); margin-top: 5px; }
.muted { color: var(--text-muted); }

/* Clickable rows */
.sub-row { cursor: pointer; }

/* ── Subscription detail drawer ─────────────────────────────────────────────── */
.drawer-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; justify-content: flex-end; z-index: 60; }
.sub-drawer { width: 460px; max-width: 100%; height: 100%; background: var(--surface, #ffffff); display: flex; flex-direction: column; box-shadow: -8px 0 30px rgba(0,0,0,.18); animation: sdslide .18s ease-out; }
@keyframes sdslide { from { transform: translateX(24px); opacity: .6; } to { transform: none; opacity: 1; } }
.sd-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.sd-head-main { display: flex; align-items: center; gap: 12px; }
.sd-avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg,#0ea5e9,#0284c7); color: #fff; font-size: 17px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.sd-name { font-size: 15px; font-weight: 700; color: var(--text); }
.sd-email { font-size: 12.5px; color: var(--text-muted); }
.sd-body { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 18px; }
.sd-status-row { display: flex; align-items: center; gap: 8px; }
.sd-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-bottom: 10px; }
.sd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.sd-field { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sd-field span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.sd-field b { font-size: 13.5px; color: var(--text); font-weight: 600; word-break: break-word; }
.sd-ledger { display: flex; flex-direction: column; gap: 6px; }
.sd-ledger-row { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 12.5px; }
.sd-ledger-amt { font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
.sd-ledger-to { color: var(--text-muted); }
.sd-ledger-date { color: var(--text-muted); font-size: 12px; }
.sd-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--border); background: var(--surface-hover, #f9fafb); flex-wrap: wrap; }
.btn-danger-soft { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #dc2626); border: 1px solid var(--danger-border, #fecaca); }
.btn-danger-soft:hover { background: var(--danger-bg, #fee2e2); }
@media (max-width: 520px) {
  .sub-drawer { width: 100%; }
  .sd-grid { grid-template-columns: 1fr; }
  .sd-foot { flex-direction: column; }
  .sd-foot .btn { width: 100%; justify-content: center; min-height: 44px; }
  .sd-ledger-row { grid-template-columns: auto 1fr; grid-auto-rows: auto; row-gap: 2px; }
  .sd-ledger-date { grid-column: 2; text-align: right; }
}

/* Phone (360px-class devices) */
@media (max-width: 600px) {
  .sub-head-actions { width: 100%; flex-wrap: wrap; }
  .sub-head-actions .btn { flex: 1; justify-content: center; min-height: 44px; }
  .sub-summary-row { display: grid; grid-template-columns: repeat(2, 1fr); padding: 4px; }
  .sub-stat { padding: 10px 8px; }
  .sub-stat-sep { display: none; }
  .sub-stat-val { white-space: normal; }
  .lf-selects { flex-direction: column; }
  .answers-modal { max-width: 100%; }
  .bulk-bar { flex-direction: column; align-items: stretch; gap: 8px; position: relative; padding: 10px 14px; }
  .bulk-info { flex-wrap: wrap; padding-right: 30px; }
  .bulk-actions { flex-wrap: wrap; }
  .bulk-btn { flex: 1 1 auto; justify-content: center; min-height: 40px; }
  .bulk-deselect { position: absolute; top: 8px; right: 8px; }
  .bulk-slide-enter-to, .bulk-slide-leave-from { max-height: 260px; }
  .sub-pastdue-strip .btn { margin-left: 0; width: 100%; justify-content: center; min-height: 40px; }
}
</style>
