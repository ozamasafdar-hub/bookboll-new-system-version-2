<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadReferenceData } from '../lib/referenceData.js'
import { askConfirm } from '../lib/confirm.js'
import { askInput } from '../lib/inputPrompt.js'
import { pushToast } from '../lib/toast.js'
import {
  Wallet as WalletIcon, Clock, Check, X, DollarSign, Plus,
  CircleAlert as AlertCircle, TrendingUp, Banknote, Hourglass, Calendar,
  Info, Zap, Building2, Pencil, Globe, Loader,
  ArrowUpRight, ArrowDownLeft, ShieldCheck, RefreshCw,
  CreditCard, Landmark, Lock, ArrowRight, ArrowLeft
} from 'lucide-vue-next'
import { useCurrentUser } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { methodsForCountry, isWalletReady, isWalletUsable, isWalletPaused, isAutomaticPayout, payoutSetupState, WALLET_METHOD_LABELS, normWalletPurpose, WALLET_PURPOSE_LABELS, walletForCountry } from '../lib/walletSetup.js'
import { applyWalletDelta } from '../lib/walletLedger.js'
import {
  firstError as wpErr,
  fetchWalletPayoutsDashboard,
  createWallet as apiCreateWallet,
  updateWallet as apiUpdateWallet,
  deleteWallet as apiDeleteWallet,
  applyMethodToWallets,
  approveWithdrawal as apiApproveWithdrawal,
  markWithdrawalPaid as apiMarkWithdrawalPaid,
  rejectWithdrawal as apiRejectWithdrawal,
  cancelWithdrawal as apiCancelWithdrawal,
  createWithdrawal as apiCreateWithdrawal,
  createWalletTransaction,
  updateWalletTransaction,
  fetchTransactions,
  fetchWithdrawals,
  fetchWallets,
  fetchOwnerWalletReview,
} from '../lib/walletPayouts.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import PaginationBar from '../components/PaginationBar.vue'
import PayoutBankForm from '../components/PayoutBankForm.vue'
import { usePagination } from '../lib/usePagination.js'

const { isSuperAdmin, isCountryAdmin, role: actorRole, currentOwnerUserId, currentCountryId,
  assignedCountryIds, assignedLocationIds, allBusinessOwners, currentUser, can } = useCurrentUser()
// Platform roles (super admin, country admin, platform staff) get the ADMIN view —
// scoped to their jurisdiction (see rowInJurisdiction). Everyone else is a provider.
const isPlatformAdmin = computed(() =>
  isSuperAdmin.value
  || isCountryAdmin.value
  || actorRole.value === 'platform_staff'
  || actorRole.value === 'admin'
)
const role = computed(() => isPlatformAdmin.value ? 'admin' : 'provider')
// Provider wallet sub-permissions (Owner has all; Manager can withdraw but not edit
// payout unless granted; others only view).
const canEditPayout = computed(() => can(CAP.MANAGE_PAYOUTS))
const canWithdraw   = computed(() => can(CAP.WITHDRAW_FUNDS))
const route = useRoute()
const router = useRouter()

// ── Core data ──────────────────────────────────────────────────────────────────
const countries    = ref([])
const wallets      = ref([])
const transactions = ref([])
const withdrawals  = ref([])
const activeSubsByWallet = ref({})   // wallet_id → count of active subscriptions bound to it
const locationsData = ref([])
const citiesData    = ref([])
const selectedProvider = ref('')
const selectedCountryId = ref('')   // country context (for add-wallet + currency)
const selectedWalletId  = ref('')   // the specific wallet row being viewed (current or legacy)
const requestOwners = ref({})   // id → { business_name, full_name, email }
// Business owners for labels / provider-scoped views. Prefer Supabase provider_admin
// rows (UUID ids matching seeded provider_wallets.owner_user_id) — same as Approvals.
const providerDirectory = ref([])   // { id, business_name, full_name, email, country_id }[]
const providers = computed(() => {
  const byId = new Map()
  for (const u of allBusinessOwners.value || []) {
    byId.set(String(u.id), {
      id: String(u.id),
      business_name: u.business_name || u.full_name || u.email || '—',
      email: u.email || '',
      country_id: u.country_id || null,
    })
  }
  for (const u of providerDirectory.value) {
    byId.set(String(u.id), {
      id: String(u.id),
      business_name: u.business_name || u.full_name || u.email || '—',
      email: u.email || '',
      country_id: u.country_id || null,
    })
  }
  for (const w of wallets.value) {
    const id = String(w.owner_user_id || '')
    if (!id || byId.has(id)) continue
    byId.set(id, {
      id,
      business_name: requestOwners.value[id]?.business_name
        || requestOwners.value[id]?.full_name
        || requestOwners.value[id]?.email
        || `Business ${id.slice(0, 8)}`,
      email: requestOwners.value[id]?.email || '',
      country_id: w.country_id || null,
    })
  }
  return [...byId.values()].sort((a, b) => String(a.business_name).localeCompare(String(b.business_name)))
})
// Providers now keep TWO kinds of wallet: a Subscription wallet (memberships; Stripe + Bookboll
// Wallet) and a Bookings wallet (venue full-online; Stripe only — Bookboll Wallet is beta and
// shown disabled). The landing toggles between them; everything below is scoped to this purpose.
// Each wallet purpose is its OWN page: /wallet/subscription · /wallet/bookings (bare /wallet =
// subscription). Purpose is derived from the route, so the sub-nav is real navigation
// (back-button + shareable). `?purpose=` is still honoured for old deep links.
const selectedPurpose = ref('bookings')
const purposeLabel = computed(() => WALLET_PURPOSE_LABELS[selectedPurpose.value] || 'Wallet')
const isBookingsPurpose = computed(() => selectedPurpose.value === 'bookings')
// Switching pages resets to the wallet list and closes any open editor.
function applyPurpose(p) {
  selectedPurpose.value = 'bookings'
  selectedWalletId.value = ''
  methodEditorOpen.value = false
  bookbollSetupOpen.value = false
  walletView.value = 'list'
}
watch(() => route.params.purpose, (p) => applyPurpose(p || route.query.purpose))
const tab   = ref('requests')   // admin default; provider uses 'all'
const txFilter = ref('all')
const txTypeFilter = ref('all')   // 'all' | 'booking' | 'subscription' — the ledger's own Type column
// Default to a tab that exists for the active role (provider tabs differ from admin's).
watch(role, r => { tab.value = r === 'provider' ? 'all' : 'requests' }, { immediate: true })

// Currency for a provider = currency of any location they own (location → city →
// country → currency_code). This is the reliable source of truth and never falls
// back to a hard-coded AED unless a provider has no resolvable location at all.
function currencyForOwner(ownerId) {
  const loc = locationsData.value.find(l => l.owner_user_id === ownerId)
  if (loc?.currency_override) return loc.currency_override
  const city = loc && citiesData.value.find(c => c.id === loc.city_id)
  const cur  = city && countries.value.find(c => c.id === city.country_id)?.currency_code
  if (cur) return cur
  // last resort: provider's own country_id
  const pu = providers.value.find(u => String(u.id) === String(ownerId))
    || allBusinessOwners.value.find(u => u.id === ownerId)
  return countries.value.find(c => c.id === pu?.country_id)?.currency_code || ''
}

// Currency for a specific country (multi-country wallets each hold one currency).
// provider_wallets.country_id is a CHAR column (it used to hold Supabase UUIDs),
// so the API returns "1" while countries.id is the number 1. `===` between them is
// false, and a Set holds "1" and 1 as two different countries — which is why one
// country rendered as two cards: the real wallet under an unmatched id (labelled
// "Country") plus an empty "set up" card for the same place. Every country-id
// comparison on this page goes through here.
const cid = v => (v === null || v === undefined || v === '') ? '' : String(v)

function currencyForCountry(countryId) {
  // The country's own currency, then whatever wallet this provider already holds
  // for that country — that wallet's code is the currency their money actually
  // arrived in. Never a hardcoded guess: that is how a PKR provider's wallet came
  // to read AED in the first place.
  return countries.value.find(c => cid(c.id) === cid(countryId))?.currency_code
    || providerWalletList.value.find(w => cid(w.country_id) === cid(countryId))?.currency
    || currencyForOwner(selectedProvider.value)
    || ''
}

// Countries the given provider operates in (has at least one location).
function operatingCountryIdsFor(ownerId) {
  const ids = new Set()
  const oid = ownerId != null ? String(ownerId) : ''
  for (const l of locationsData.value) {
    if (String(l.owner_user_id) !== oid) continue
    const city = citiesData.value.find(c => c.id === l.city_id)
    if (city?.country_id) ids.add(cid(city.country_id))
  }
  return ids
}
// A wallet's owner_user_id is not one key: bookings wallets store the numeric
// users.id, membership wallets and every legacy Supabase row store the uuid. The
// API now resolves a numeric `owner_id` alongside it, so match on either —
// comparing only owner_user_id is what made the membership wallets invisible.
function walletBelongsTo(w, providerId) {
  const want = String(providerId || '')
  if (!want) return false
  return String(w.owner_user_id ?? '') === want || String(w.owner_id ?? '') === want
}
// All wallet rows belonging to the selected provider.
const providerWalletList = computed(() => wallets.value.filter(w => walletBelongsTo(w, selectedProvider.value)))
// …and just those for the currently-selected PURPOSE (bookings vs subscription). Rows missing
// the purpose column (pre-migration) are treated as 'subscription'.
const purposeWalletList = computed(() => providerWalletList.value.filter(w => normWalletPurpose(w.purpose) === selectedPurpose.value))
// The country chips for the switcher: every country the provider has a wallet in OR
// operates in (plus their home country as a fallback so there's always one).
const walletCountryOptions = computed(() => {
  const ids = new Set(providerWalletList.value.map(w => cid(w.country_id)).filter(Boolean))
  for (const id of operatingCountryIdsFor(selectedProvider.value)) ids.add(id)
  const home = providers.value.find(u => String(u.id) === String(selectedProvider.value))?.country_id
    || allBusinessOwners.value.find(u => u.id === selectedProvider.value)?.country_id
  if (home) ids.add(cid(home))
  return Array.from(ids).map(id => {
    const w = walletForCountry(purposeWalletList.value, id, selectedPurpose.value)
    return {
      id,
      name: countries.value.find(c => cid(c.id) === cid(id))?.name || 'Country',
      currency: currencyForCountry(id),
      hasWallet: !!w,
      ready: isWalletReady(w),
    }
  }).sort((a, b) => a.name.localeCompare(b.name))
})
// Pick a sensible country wallet when the provider or options change (honour ?country=).
watch([walletCountryOptions, selectedProvider], () => {
  const opts = walletCountryOptions.value
  if (!opts.length) return
  if (selectedCountryId.value && opts.some(o => cid(o.id) === cid(selectedCountryId.value))) return
  const wanted = String(route.query.country || '')
  const home = providers.value.find(u => String(u.id) === String(selectedProvider.value))?.country_id
    || allBusinessOwners.value.find(u => u.id === selectedProvider.value)?.country_id
  selectedCountryId.value =
    (wanted && opts.find(o => o.id === wanted)?.id) ||
    (home && opts.find(o => o.id === home)?.id) ||
    opts[0].id
}, { immediate: true })

// ── Provider wallets landing (one wallet per operating country) ───────────────
// Wallets are NOT created by hand: every country the provider has a location in
// gets its own currency wallet automatically. The landing lists those countries;
// the provider just sets up a PAYOUT METHOD for each one to start getting paid.
// A ?country= deep-link opens that country's wallet directly.
const walletView = ref(route.query.country ? 'detail' : 'list')
function openWallet(w) { selectedWalletId.value = w.id; selectedCountryId.value = cid(w.country_id); selectedPurpose.value = normWalletPurpose(w.purpose); walletView.value = 'detail' }
// Open a country card from the landing. The wallet row may not exist yet — it's
// created lazily the moment a payout method is set up.
function openWalletCard(card) {
  selectedCountryId.value = card.country_id
  selectedWalletId.value = card.wallet?.id || ''
  walletView.value = 'detail'
}
function backToWallets() { walletView.value = 'list'; selectedWalletId.value = '' }
// Status pill for a wallet card: Active / Connecting (external rail pending) / Setup needed.
function walletStatusLabel(w) { return w?.setup_status === 'active' ? 'Active' : w?.setup_status === 'pending' ? 'Connecting' : 'No payout method' }
function walletStatusClass(w) { return w?.setup_status === 'active' ? 'ready' : w?.setup_status === 'pending' ? 'pending' : 'setup' }
const isLegacyWallet = (w) => w?.is_current === false

// Landing cards: one per operating country (its current wallet — real or not yet
// created), plus any legacy (closing) wallets shown separately. Built from
// walletCountryOptions so a country with no wallet row yet still appears with a
// "Set up payout method" call to action.
const walletCards = computed(() => {
  const cards = walletCountryOptions.value.map(opt => {
    const w = purposeWalletList.value.find(x => cid(x.country_id) === cid(opt.id) && x.is_current !== false) || null
    return {
      key: w?.id || ('country-' + opt.id),
      country_id: opt.id, name: opt.name, currency: opt.currency,
      wallet: w, legacy: false, hasMethod: isWalletReady(w),
    }
  })
  for (const w of purposeWalletList.value.filter(x => x.is_current === false)) {
    cards.push({ key: w.id, country_id: w.country_id, name: countryName(w.country_id), currency: w.currency, wallet: w, legacy: true, hasMethod: isWalletReady(w) })
  }
  // Safety net: every card above is keyed off a COUNTRY, so a wallet whose
  // country_id is null (or a country this provider no longer operates in) matched
  // no card and simply vanished — real money, shown nowhere. Anything not already
  // rendered gets its own card, labelled by its currency.
  const shown = new Set(cards.map(c => c.wallet?.id).filter(Boolean))
  for (const w of purposeWalletList.value) {
    if (shown.has(w.id)) continue
    cards.push({
      key: w.id,
      country_id: w.country_id || null,
      name: countryName(w.country_id) || `${w.currency || 'Wallet'} wallet`,
      currency: w.currency,
      wallet: w,
      legacy: w.is_current === false,
      hasMethod: isWalletReady(w),
    })
  }
  return cards
})

// ── Change payout method = demote the current wallet to legacy + open a fresh one ──
// The legacy wallet keeps its balance and its existing subscriptions keep paying into
// it; new earnings go to the new current wallet you set up next.
async function changePayoutMethod() {
  if (providerOpenWithdrawals.value.length) {
    pushToast('Finish or cancel your in-progress payout before changing method.', 'error'); return
  }
  const ctry = countryName(currentWallet.value.country_id)
  if (!(await askConfirm({
    title: 'Change payout method?',
    message: `Your current ${ctry} wallet keeps its balance and its historical records. You'll set up a new wallet for new earnings next.`,
    confirmText: 'Continue',
  }))) return
  const old = currentWallet.value
  if (old?.id) {
    const upd = await apiUpdateWallet(old.id, { is_current: false, status: 'legacy' })
    if (upd.success) {
      const row = wallets.value.find(w => w.id === old.id); if (row) { row.is_current = false; row.status = 'legacy' }
    }
  }
  const currency = currencyForCountry(selectedCountryId.value)
  const seed = { owner_user_id: selectedProvider.value, country_id: selectedCountryId.value, currency, is_current: true, status: 'current', purpose: selectedPurpose.value }
  const created = await apiCreateWallet(seed)
  if (created.success && created.data?.wallet) {
    wallets.value.push(created.data.wallet)
    selectedWalletId.value = created.data.wallet.id
  }
  bookbollSetupOpen.value = false
  loadAll()
}

// ── Delete a wallet (only when drained & idle) ──
const currentWalletActiveSubs = computed(() => activeSubsByWallet.value[currentWallet.value.id] || 0)
const canDeleteWallet = computed(() => {
  const w = currentWallet.value
  if (!w?.id) return false
  return Number(w.available_balance || 0) === 0 && Number(w.pending_balance || 0) === 0 &&
    providerOnHold.value.length === 0 && providerOpenWithdrawals.value.length === 0 &&
    currentWalletActiveSubs.value === 0
})
async function deleteWallet() {
  const w = currentWallet.value
  if (!w?.id) return
  if (!canDeleteWallet.value) {
    pushToast('Delete is only possible once the wallet is empty: no balance, nothing clearing, no open payout, and no active subscriptions.', 'error'); return
  }
  const ctry = countryName(w.country_id)
  if (!(await askConfirm({
    title: `Delete your ${ctry} wallet?`,
    message: `You won't be able to take full online payments  in ${ctry} until you set up a wallet there again. Your transaction history is kept.`,
    danger: true, confirmText: 'Delete wallet',
  }))) return
  await apiDeleteWallet(w.id)
  wallets.value = wallets.value.filter(x => x.id !== w.id)
  selectedWalletId.value = ''
  walletView.value = 'list'
  pushToast(`${ctry} wallet deleted.`, 'success')
  loadAll()
}


// ── Provider: payout details ──────────────────────────────────────────────────
const payoutEditing = ref(false)
const payoutSaving  = ref(false)
const payoutError   = ref('')
const payoutForm    = ref({
  payout_bank_country: '', payout_bank_name: '', payout_account_holder: '',
  payout_iban: '', payout_account_number: '', payout_routing_number: '',
  payout_swift: '', payout_account_type: 'checking',
})

// ── Banking countries — grouped for the dropdown ───────────────────────────
const BANKING_COUNTRIES_GROUPED = [
  { group: 'Middle East', list: [
    { code:'AE', name:'United Arab Emirates', type:'iban' },
    { code:'SA', name:'Saudi Arabia',         type:'iban' },
    { code:'QA', name:'Qatar',                type:'iban' },
    { code:'KW', name:'Kuwait',               type:'iban' },
    { code:'BH', name:'Bahrain',              type:'iban' },
    { code:'OM', name:'Oman',                 type:'iban' },
    { code:'EG', name:'Egypt',                type:'iban' },
    { code:'JO', name:'Jordan',               type:'iban' },
    { code:'LB', name:'Lebanon',              type:'iban' },
    { code:'IQ', name:'Iraq',                 type:'iban' },
  ]},
  { group: 'Europe', list: [
    { code:'GB', name:'United Kingdom',  type:'iban' },
    { code:'DE', name:'Germany',         type:'iban' },
    { code:'FR', name:'France',          type:'iban' },
    { code:'IT', name:'Italy',           type:'iban' },
    { code:'ES', name:'Spain',           type:'iban' },
    { code:'NL', name:'Netherlands',     type:'iban' },
    { code:'BE', name:'Belgium',         type:'iban' },
    { code:'CH', name:'Switzerland',     type:'iban' },
    { code:'AT', name:'Austria',         type:'iban' },
    { code:'SE', name:'Sweden',          type:'iban' },
    { code:'NO', name:'Norway',          type:'iban' },
    { code:'DK', name:'Denmark',         type:'iban' },
    { code:'FI', name:'Finland',         type:'iban' },
    { code:'PL', name:'Poland',          type:'iban' },
    { code:'PT', name:'Portugal',        type:'iban' },
    { code:'GR', name:'Greece',          type:'iban' },
    { code:'IE', name:'Ireland',         type:'iban' },
    { code:'CY', name:'Cyprus',          type:'iban' },
    { code:'MT', name:'Malta',           type:'iban' },
    { code:'LU', name:'Luxembourg',      type:'iban' },
    { code:'TR', name:'Turkey',          type:'iban' },
  ]},
  { group: 'Americas', list: [
    { code:'US', name:'United States', type:'us'      },
    { code:'CA', name:'Canada',        type:'default' },
    { code:'MX', name:'Mexico',        type:'default' },
    { code:'BR', name:'Brazil',        type:'default' },
  ]},
  { group: 'Asia Pacific', list: [
    { code:'IN', name:'India',          type:'default' },
    { code:'PK', name:'Pakistan',       type:'default' },
    { code:'BD', name:'Bangladesh',     type:'default' },
    { code:'SG', name:'Singapore',      type:'default' },
    { code:'MY', name:'Malaysia',       type:'default' },
    { code:'PH', name:'Philippines',    type:'default' },
    { code:'LK', name:'Sri Lanka',      type:'default' },
    { code:'NP', name:'Nepal',          type:'default' },
    { code:'AU', name:'Australia',      type:'au'      },
    { code:'NZ', name:'New Zealand',    type:'au'      },
  ]},
  { group: 'North Africa', list: [
    { code:'MA', name:'Morocco', type:'iban' },
    { code:'TN', name:'Tunisia', type:'iban' },
    { code:'DZ', name:'Algeria', type:'default' },
    { code:'LY', name:'Libya',   type:'default' },
  ]},
  { group: 'Sub-Saharan Africa', list: [
    { code:'ZA', name:'South Africa', type:'default' },
    { code:'NG', name:'Nigeria',      type:'default' },
    { code:'KE', name:'Kenya',        type:'default' },
    { code:'GH', name:'Ghana',        type:'default' },
  ]},
]
const BANKING_COUNTRIES_FLAT = BANKING_COUNTRIES_GROUPED.flatMap(g => g.list)

// Derives the field layout from the selected country
const payoutFieldMode = computed(() => {
  const c = payoutForm.value.payout_bank_country
  return BANKING_COUNTRIES_FLAT.find(x => x.code === c)?.type || 'default'
})

// Human-readable country name for display (the selected wallet's bank country)
const payoutCountryName = computed(() => {
  const c = currentWallet.value?.payout_bank_country
  return BANKING_COUNTRIES_FLAT.find(x => x.code === c)?.name || c || '—'
})
const formCountryName = computed(() => {
  const c = payoutForm.value.payout_bank_country
  return BANKING_COUNTRIES_FLAT.find(x => x.code === c)?.name || ''
})

// Payout details now live PER COUNTRY on the wallet row (not on admin_users).
const hasPayoutDetails = computed(() => !!(
  currentWallet.value?.payout_bank_name ||
  currentWallet.value?.payout_iban ||
  currentWallet.value?.payout_account_number
))

function initPayoutForm() {
  const u = currentWallet.value || {}
  payoutForm.value = {
    payout_bank_country:   u.payout_bank_country   || '',
    payout_bank_name:      u.payout_bank_name      || '',
    payout_account_holder: u.payout_account_holder || '',
    payout_iban:           u.payout_iban           || '',
    payout_account_number: u.payout_account_number || '',
    payout_routing_number: u.payout_routing_number || '',
    payout_swift:          u.payout_swift          || '',
    payout_account_type:   u.payout_account_type   || 'checking',
  }
}
// NOTE: the watch on currentWallet is registered AFTER currentWallet is defined
// (further down) to avoid a temporal-dead-zone reference.

const payoutFieldErrors = ref({})

// Validate the payout bank form; populates payoutFieldErrors and returns ok.
function validatePayout() {
  payoutError.value = ''
  payoutFieldErrors.value = {}
  const f   = payoutForm.value
  const err = payoutFieldErrors.value
  let ok    = true

  if (!f.payout_bank_country)          { err.country        = 'Select a country.';              ok = false }
  if (!f.payout_bank_name.trim())      { err.bank_name      = 'Bank name is required.';          ok = false }
  if (!f.payout_account_holder.trim()) { err.holder         = 'Account holder name is required.'; ok = false }

  if (payoutFieldMode.value === 'iban') {
    if (!f.payout_iban.trim())          { err.iban           = 'IBAN is required.'; ok = false }
    else if (!/^[A-Z]{2}\d{2}[A-Z0-9]{4,}$/i.test(f.payout_iban.replace(/\s/g, '')))
                                        { err.iban           = 'IBAN format looks incorrect (e.g. AE070331…).'; ok = false }
  } else if (payoutFieldMode.value === 'us') {
    if (!f.payout_routing_number.trim()) { err.routing       = 'Routing number is required.'; ok = false }
    else if (!/^\d{9}$/.test(f.payout_routing_number.trim()))
                                         { err.routing       = 'Must be exactly 9 digits.'; ok = false }
    if (!f.payout_account_number.trim()) { err.account       = 'Account number is required.'; ok = false }
  } else if (payoutFieldMode.value === 'au') {
    if (!f.payout_routing_number.trim()) { err.routing       = 'BSB code is required.'; ok = false }
    if (!f.payout_account_number.trim()) { err.account       = 'Account number is required.'; ok = false }
  } else {
    if (!f.payout_account_number.trim()) { err.account       = 'Account number is required.'; ok = false }
  }
  return ok
}

// Ensure a wallet row exists for the selected (provider, country); create it if not.
async function ensureCurrentWallet() {
  const w = currentWallet.value
  if (w?.id) return w
  const currency = currencyForCountry(selectedCountryId.value)
  const res = await apiCreateWallet({
    owner_user_id: selectedProvider.value,
    country_id: selectedCountryId.value,
    currency,
    purpose: selectedPurpose.value,
  })
  if (res.success && res.data?.wallet) { wallets.value.push(res.data.wallet); return res.data.wallet }
  return null
}

// Persist the payout bank details onto the SELECTED COUNTRY's wallet row.
async function writePayoutDetails() {
  const w = await ensureCurrentWallet()
  if (!w?.id) return { error: { message: 'Could not create the wallet for this country.' } }
  const f = payoutForm.value
  const patch = {
    payout_bank_country:   f.payout_bank_country      || null,
    payout_bank_name:      f.payout_bank_name.trim()  || null,
    payout_account_holder: f.payout_account_holder.trim() || null,
    payout_iban:           f.payout_iban.replace(/\s/g,'').toUpperCase() || null,
    payout_account_number: f.payout_account_number.trim() || null,
    payout_routing_number: f.payout_routing_number.trim() || null,
    payout_swift:          f.payout_swift.replace(/\s/g,'').toUpperCase() || null,
    payout_account_type:   f.payout_account_type      || null,
  }
  const res = await apiUpdateWallet(w.id, patch)
  if (res.success) Object.assign(w, patch)
  return res.success ? { error: null } : { error: { message: wpErr(res, 'Could not save') } }
}

function startPayoutEdit() {
  initPayoutForm()
  payoutFieldErrors.value = {}
  payoutError.value = ''
  payoutEditing.value = true
}
async function savePayoutDetails() {
  if (!validatePayout()) return
  payoutSaving.value = true
  const res = await writePayoutDetails()
  payoutSaving.value = false
  if (res?.error) { payoutError.value = res.error.message || 'Could not save — please try again.'; return }
  payoutEditing.value = false
  logAudit({ action: AUDIT.UPDATED, entity_type: 'wallet_payout_details', entity_id: currentWallet.value?.id, details: `Payout bank details updated (${payoutForm.value.payout_bank_name}, ${formCountryName.value || payoutForm.value.payout_bank_country})` })
  pushToast('Bank details saved — withdrawals will be sent to this account.', 'success')
  loadAll()
}

// ── Admin: withdrawal request detail panel ────────────────────────────────────
const viewingRequest = ref(null)
const reqTxns        = ref([])   // credit transactions (earnings) — drives reconciliation
const reqLedger      = ref([])   // FULL wallet ledger, both directions — money in & out
const reqEarnings    = ref([])
const reqWallet      = ref(null)
const reqProvider    = ref(null)
const reqHistory     = ref([])   // the provider's other withdrawal requests (any wallet)
const reqPrograms    = ref([])   // the provider's membership programs
const reqMembers     = ref(0)    // active members across those programs
const reqLoading     = ref(false)
const markingPaid    = ref(false)
const approvingReq   = ref(false)
const rejectingReq   = ref(false)

async function openRequest(wd) {
  viewingRequest.value = wd
  reqLoading.value = true
  reqTxns.value = []; reqLedger.value = []; reqEarnings.value = []
  reqHistory.value = []; reqPrograms.value = []; reqMembers.value = 0
  const [txRes, wdRes, reviewRes] = await Promise.all([
    fetchTransactions(wd.wallet_id
      ? { wallet_id: wd.wallet_id, limit: 500 }
      : { owner_user_id: wd.owner_user_id, limit: 500 }),
    fetchWithdrawals({ owner_user_id: wd.owner_user_id, limit: 30 }),
    fetchOwnerWalletReview(wd.owner_user_id),
  ])
  const txs = txRes.success ? (txRes.data?.transactions || []) : []
  reqLedger.value  = txs
  reqTxns.value    = reqLedger.value.filter(t => t.direction === 'credit')
  reqWallet.value = wd.wallet_id
    ? (wallets.value.find(x => x.id === wd.wallet_id) || null)
    : (wallets.value.find(x => x.owner_user_id === wd.owner_user_id && cid(x.country_id) === cid(wd.country_id) && x.is_current !== false) || null)
  // The provider directory is already loaded and scoped, so no extra lookup.
  reqProvider.value = providerDirectory.value.find(p => String(p.id) === String(wd.owner_user_id)) || null
  reqHistory.value = (wdRes.success ? (wdRes.data?.withdrawals || []) : []).filter(r => r.id !== wd.id)
  reqPrograms.value = reviewRes.success ? (reviewRes.data?.programs || []) : []
  reqMembers.value = reviewRes.success ? (reviewRes.data?.member_count || 0) : 0
  // Reconcile wallet credits against Laravel subscription_earning payments (by subscription_id).
  const earnRows = reviewRes.success ? (reviewRes.data?.earnings || []) : []
  const subIds = new Set(reqTxns.value.filter(t => t.source_id).map(t => String(t.source_id)))
  reqEarnings.value = earnRows.filter(e => subIds.has(String(e.subscription_id)))
  reqLoading.value = false
}

// Money in / money out totals over the wallet's full ledger (completed + clearing).
const reqFlow = computed(() => {
  const rows = reqLedger.value.filter(t => t.status !== 'cancelled' && t.status !== 'rejected')
  const sum = list => +list.reduce((a, t) => a + Number(t.provider_share ?? t.amount ?? 0), 0).toFixed(2)
  const inRows = rows.filter(t => t.direction === 'credit')
  const outRows = rows.filter(t => t.direction === 'debit')
  return { totalIn: sum(inRows), totalOut: sum(outRows), net: +(sum(inRows) - sum(outRows)).toFixed(2), count: rows.length }
})

// The identity shown on the panel — straight from the requester's own row (always
// loaded by id), never dependent on a cached list that may not contain them.
const reqProviderName = computed(() =>
  reqProvider.value?.business_name || reqProvider.value?.full_name || reqProvider.value?.email || providerName(viewingRequest.value?.owner_user_id))

// Bank details: the WALLET's payout fields are canonical (setup flow writes them
// there); older accounts kept them on the provider profile — check both.
const reqBank = computed(() => {
  const w = reqWallet.value || {}, p = reqProvider.value || {}
  const pick = k => w[k] || p[k] || ''
  const b = {
    bank_name: pick('payout_bank_name'), bank_country: pick('payout_bank_country'),
    account_holder: pick('payout_account_holder'), iban: pick('payout_iban'),
    account_number: pick('payout_account_number'), routing_number: pick('payout_routing_number'),
    swift: pick('payout_swift'), account_type: pick('payout_account_type'),
  }
  b.any = Object.values(b).some(Boolean)
  return b
})

// Reconciliation verdict shown in the request panel.
const reqRecon = computed(() => {
  const wd = viewingRequest.value, w = reqWallet.value
  const credits = reqTxns.value
  const earnBySub = {}
  for (const e of reqEarnings.value) earnBySub[e.subscription_id] = (earnBySub[e.subscription_id] || 0) + Number(e.amount || 0)
  let matched = 0, unmatched = 0
  const rows = credits.map(c => {
    const share = Number(c.provider_share || c.amount || 0)
    const recorded = c.source_id != null ? earnBySub[c.source_id] : undefined
    const ok = recorded != null && Math.abs(recorded - share) < 0.01
    if (c.source_id != null) { ok ? matched++ : unmatched++ }
    return { ...c, share, recorded, ok, manual: c.source_id == null }
  })
  const totalCredited = credits.reduce((a, c) => a + Number(c.provider_share || c.amount || 0), 0)
  const totalRecorded = reqEarnings.value.reduce((a, e) => a + Number(e.amount || 0), 0)
  const totalsMatch = Math.abs(totalCredited - totalRecorded) < 0.01
  const amount = Number(wd?.amount || 0)
  const netEarned = Number(w?.lifetime_earnings || 0) - Number(w?.lifetime_withdrawn || 0)
  const sufficient = netEarned + 0.01 >= amount
  return { rows, matched, unmatched, totalCredited, totalRecorded, totalsMatch, amount, netEarned, sufficient, clean: totalsMatch && unmatched === 0 && sufficient }
})

function closeRequest() {
  viewingRequest.value = null; reqTxns.value = []; reqLedger.value = []; reqEarnings.value = []
  reqWallet.value = null; reqProvider.value = null; reqHistory.value = []; reqPrograms.value = []; reqMembers.value = 0
}

// STEP 1 — approve: verified & committed to pay. Money stays in escrow (pending_balance);
// the provider sees "approved · paying out" with an ETA. No balance change yet.
async function approveWithdrawal(wd) {
  if (!(await askConfirm({
    title: 'Approve this payout?',
    message: `Confirm ${fmtMoney(wd.amount, wd.currency)} to ${providerName(wd.owner_user_id)} is verified and you will send the bank transfer. The provider sees “Approved · paying out” with an ETA, and you'll mark it Paid once the transfer is done.`,
    danger: false,
    confirmText: 'Approve payout',
  }))) return
  approvingReq.value = true
  const res = await apiApproveWithdrawal(wd.id, { processed_by: currentUser.value?.full_name || 'Super Admin' })
  approvingReq.value = false
  if (!res.success) { pushToast(wpErr(res, 'Could not approve the request.'), 'error'); return }
  Object.assign(wd, res.data?.withdrawal || { status: 'approved' })
  if (viewingRequest.value?.id === wd.id) viewingRequest.value = { ...wd }
  pushToast(`Payout approved — mark it Paid once the transfer is sent.`, 'success')
  loadAll()
}

// STEP 2 — mark paid: the bank transfer is done. Settles the escrow.
async function markWithdrawalPaid(wd) {
  if (!(await askConfirm({
    title: 'Mark withdrawal as paid?',
    message: `Confirm you have completed the bank transfer of ${fmtMoney(wd.amount, wd.currency)} to ${providerName(wd.owner_user_id)}. This marks the request Paid and clears it from the provider's pending balance.`,
    danger: false,
    confirmText: 'Mark as Paid',
  }))) return
  markingPaid.value = true
  const res = await apiMarkWithdrawalPaid(wd.id, { processed_by: currentUser.value?.full_name || 'Super Admin' })
  markingPaid.value = false
  if (!res.success) {
    pushToast(wpErr(res, 'Could not update the request.'), 'error')
    loadAll()
    return
  }
  closeRequest()
  pushToast(`Withdrawal of ${fmtMoney(wd.amount, wd.currency)} marked as paid`, 'success')
  loadAll()
}

async function rejectFromPanel(wd) {
  const reason = await askInput({
    title: 'Reject withdrawal request',
    label: 'Reason for rejection (shown to the provider)',
    placeholder: 'e.g. Bank/IBAN details are incomplete',
    multiline: true,
    required: true,
    confirmText: 'Reject request',
  })
  if (reason === null) return   // cancelled
  rejectingReq.value = true
  const res = await apiRejectWithdrawal(wd.id, {
    reason: reason || '',
    processed_by: currentUser.value?.full_name || 'Super Admin',
  })
  rejectingReq.value = false
  if (!res.success) {
    pushToast(wpErr(res, 'Could not reject the request.'), 'error')
    loadAll()
    return
  }
  closeRequest()
  pushToast('Withdrawal request rejected', 'success')
  loadAll()
}

// Provider cancels their own request while it's still 'pending' (before approval).
// Money returns from pending to available.
async function cancelWithdrawal(wd) {
  if (wd.status !== 'pending') return
  if (!(await askConfirm({
    title: 'Cancel this withdrawal?',
    message: `Your request for ${fmtMoney(wd.amount, wd.currency)} will be cancelled and the money returned to your available balance.`,
    danger: true,
    confirmText: 'Cancel request',
  }))) return
  const res = await apiCancelWithdrawal(wd.id, { processed_by: currentUser.value?.full_name || 'Admin' })
  if (!res.success) { pushToast(wpErr(res, 'Could not cancel the request.'), 'error'); return }
  pushToast('Withdrawal cancelled — funds are back in your available balance.', 'success')
  loadAll()
}

// ── Holds release ─────────────────────────────────────────────────────────────
async function releaseDueHolds() {
  const today = new Date().toISOString().slice(0, 10)
  const txRes = await fetchTransactions({ status: 'on_hold', limit: 500 })
  const due = (txRes.success ? (txRes.data?.transactions || []) : [])
    .filter(t => t.status === 'on_hold' && t.available_on && String(t.available_on).slice(0, 10) <= today)
  if (!due.length) return
  const releasedAt = new Date().toISOString()
  const claimed = []
  for (const t of due) {
    const upd = await updateWalletTransaction(t.id, { status: 'completed', released_at: releasedAt })
    if (upd.success) claimed.push({ ...t, ...(upd.data?.transaction || {}) })
  }
  if (!claimed.length) return
  const byWallet = new Map()
  for (const t of claimed) {
    const k = t.wallet_id || `oc:${t.owner_user_id}|${t.country_id || ''}`
    byWallet.set(k, (byWallet.get(k) || 0) + Number(t.provider_share || 0))
  }
  for (const [k, share] of byWallet) {
    let walletId = null
    if (String(k).startsWith('oc:')) {
      const [pid, cid] = k.slice(3).split('|')
      const list = await fetchWallets({ owner_user_id: pid, country_id: cid || undefined, is_current: true })
      walletId = (list.data?.wallets || [])[0]?.id || null
    } else {
      walletId = k
    }
    if (!walletId) continue
    await applyWalletDelta(walletId, { pending: -share, available: share })
  }
}

// ── Jurisdiction (platform roles see only their countries / assigned venues) ──
// Super admin → everything. Country admin → their assigned countries (fallback to
// their own country_id). Platform staff → the owners of their assigned venues, or
// (if none assigned) their inherited country scope.
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null   // null = no country limit
  const assigned = (assignedCountryIds.value || []).filter(Boolean)
  if (assigned.length) return new Set(assigned.map(cid))
  if (currentCountryId.value) return new Set([cid(currentCountryId.value)])
  return new Set()
})
const jurisdictionOwnerIds = computed(() => {
  if (actorRole.value !== 'platform_staff') return null   // only venue-scoped staff
  const ids = (assignedLocationIds.value || []).filter(Boolean)
  if (!ids.length) return null                            // fall back to country scope
  const set = new Set()
  for (const l of locationsData.value) if (ids.includes(l.id) && l.owner_user_id) set.add(l.owner_user_id)
  return set
})
// True if a wallet/transaction/withdrawal row (has owner_user_id + country_id) falls
// in the current platform user's jurisdiction.
function rowInJurisdiction(row) {
  if (isSuperAdmin.value) return true
  const owners = jurisdictionOwnerIds.value
  if (owners) return owners.has(row.owner_user_id)
  const countrySet = jurisdictionCountryIds.value
  if (!countrySet) return true
  return row.country_id ? countrySet.has(cid(row.country_id)) : true
}

// ── Load ──────────────────────────────────────────────────────────────────────
async function loadAll() {
  await releaseDueHolds()
  const [money, ref] = await Promise.all([
    fetchWalletPayoutsDashboard(),
    loadReferenceData(),
  ])
  if (!money.success) {
    pushToast(wpErr(money, 'Could not load wallets.'), 'error')
    wallets.value = []
    transactions.value = []
    withdrawals.value = []
    activeSubsByWallet.value = {}
  } else {
    wallets.value = money.data?.wallets || []
    transactions.value = money.data?.transactions || []
    withdrawals.value = money.data?.withdrawals || []
    activeSubsByWallet.value = money.data?.active_subs_by_wallet || {}
  }
  countries.value = ref.countries
  locationsData.value = ref.venues
  citiesData.value = ref.cities
  // Keep the whole record — the withdrawal review reads phone / created_at too.
  providerDirectory.value = ref.providers.map(u => ({
    ...u,
    id: String(u.id),
    business_name: u.business_name || u.full_name || u.email || '—',
    full_name: u.full_name || '',
    email: u.email || '',
    country_id: u.country_id || null,
  }))

  // Seed missing HOME-country wallets only for known Supabase providers (super admin).
  // Require a home country — same as pre-Laravel Wallet (no blank country rows).
  if (isSuperAdmin.value) {
    for (const p of providerDirectory.value) {
      const homeCountry = p.country_id || null
      if (!homeCountry) continue
      if (wallets.value.find(w => String(w.owner_user_id) === String(p.id))) continue
      const currency = countries.value.find(c => cid(c.id) === cid(homeCountry))?.currency_code || currencyForOwner(p.id) || ''
      const created = await apiCreateWallet({ owner_user_id: p.id, country_id: homeCountry, currency })
      if (created.success && created.data?.wallet) wallets.value.push(created.data.wallet)
    }
  }
  if (isPlatformAdmin.value && !isSuperAdmin.value) {
    wallets.value = wallets.value.filter(rowInJurisdiction)
    transactions.value = transactions.value.filter(rowInJurisdiction)
    withdrawals.value = withdrawals.value.filter(rowInJurisdiction)
  }

  // Resolve which business context the page is scoped to
  resolveSelectedProvider()

  const ownerIds = [...new Set([
    ...withdrawals.value.map(w => w.owner_user_id),
    ...wallets.value.map(w => w.owner_user_id),
  ].filter(Boolean).map(String))]
  if (ownerIds.length) {
    const map = { ...requestOwners.value }
    for (const p of providerDirectory.value) map[String(p.id)] = p
    requestOwners.value = map
  }
}

function resolveSelectedProvider() {
  if (!isPlatformAdmin.value) {
    const email = (currentUser.value?.email || '').toLowerCase()
    const laravelId = currentOwnerUserId.value ? String(currentOwnerUserId.value) : ''
    if (laravelId && wallets.value.some(w => walletBelongsTo(w, laravelId))) {
      selectedProvider.value = laravelId
      return
    }
    if (email) {
      const match = providerDirectory.value.find(p => (p.email || '').toLowerCase() === email)
      if (match) {
        selectedProvider.value = match.id
        return
      }
    }
    if (laravelId) {
      selectedProvider.value = laravelId
      return
    }
  }
  if (!selectedProvider.value && providers.value.length) {
    // Prefer a provider that already has wallets
    const withWallets = providers.value.find(p => wallets.value.some(w => String(w.owner_user_id) === String(p.id)))
    selectedProvider.value = (withWallets || providers.value[0]).id
  } else if (selectedProvider.value && !providers.value.some(p => String(p.id) === String(selectedProvider.value))) {
    const withWallets = providers.value.find(p => wallets.value.some(w => String(w.owner_user_id) === String(p.id)))
    if (withWallets) selectedProvider.value = withWallets.id
  }
}

let loaded = false
const walletDataReady = ref(false)
async function ensureLoaded() {
  if (!currentUser.value && !getAdminTokenSafe()) return
  loaded = true
  await loadAll()
  walletDataReady.value = true
  openRequestedSetup()
}
function getAdminTokenSafe() {
  try { return !!localStorage.getItem('bb_admin_token') } catch { return false }
}
onMounted(() => { ensureLoaded() })
watch(() => currentUser.value?.id, () => { ensureLoaded() })
watch(currentOwnerUserId, id => {
  if (!isPlatformAdmin.value && id) {
    resolveSelectedProvider()
    if (loaded) loadAll()
  }
})

// ── Computed ──────────────────────────────────────────────────────────────────
// The wallet being viewed in detail: the specific row by id, else the country's CURRENT
// wallet, else an empty placeholder.
const currentWallet = computed(() => {
  const byId = selectedWalletId.value && wallets.value.find(w => w.id === selectedWalletId.value)
  if (byId) return byId
  const list = purposeWalletList.value.filter(w => cid(w.country_id) === cid(selectedCountryId.value))
  return list.find(w => w.is_current !== false) || list[0] ||
    { available_balance: 0, pending_balance: 0, lifetime_earnings: 0, lifetime_withdrawn: 0, currency: currencyForCountry(selectedCountryId.value), hold_days: 7, country_id: selectedCountryId.value, purpose: selectedPurpose.value }
})
// Requester identity for labels (filled in loadAll from admin_users).
const providerName = id => {
  const key = id != null ? String(id) : ''
  const o = requestOwners.value[key]
  return providers.value.find(p => String(p.id) === key)?.business_name
    || o?.business_name || o?.full_name || o?.email || '—'
}
const countryName  = id => countries.value.find(c => cid(c.id) === cid(id))?.name || '—'
// Re-fill the payout form whenever the selected country wallet changes.
watch(currentWallet, initPayoutForm, { immediate: true })

// ── Wallet setup (must be done before creating membership programs) ─────────────
// A provider chooses how they get paid before the wallet (and program creation)
// unlock. Stripe Connect / PayPal Hyperwallet are external integrations wired at
// launch, so picking one is recorded as 'pending' and the gate stays closed.
// Bookboll's own wallet is fully wired: add bank details → 'active' → unlocked.
// "Usable" (active OR a connecting external rail) → show the wallet content; only a
// brand-new wallet with no method chosen shows the setup picker.
const walletReady    = computed(() => isWalletUsable(currentWallet.value))
const currentIsAutomatic = computed(() => isAutomaticPayout(currentWallet.value))   // Stripe split
const setupMethod    = computed(() => currentWallet.value?.setup_method || null)
const setupStatus    = computed(() => currentWallet.value?.setup_status || null)
// The country whose wallet is being set up / viewed (the selected chip).
const businessCountry = computed(() => countries.value.find(c => cid(c.id) === cid(selectedCountryId.value)) || null)
// The payout BANK-ACCOUNT country (where we send the money). This — not the wallet's currency
// — decides which payout methods are available (Stripe needs a bank in a supported country).
// Defaults to the wallet's country but is freely switchable on the setup step.
const payoutCountryId = ref('')
const payoutCountry = computed(() => countries.value.find(c => cid(c.id) === cid(payoutCountryId.value)) || null)
watch(() => currentWallet.value?.id, () => {
  const w = currentWallet.value
  payoutCountryId.value = w?.payout_bank_country || w?.country_id || selectedCountryId.value || payoutCountryId.value || ''
}, { immediate: true })
const walletMethods  = computed(() => methodsForCountry(payoutCountry.value?.iso_code, selectedPurpose.value))
const stripeMethod   = computed(() => walletMethods.value.methods.find(m => m.key === 'stripe_connect') || {})
const bookbollMethod = computed(() => walletMethods.value.methods.find(m => m.key === 'bookboll') || {})
const bookbollSetupOpen = ref(false)
const setupSaving    = ref(false)

// Write setup state to the provider's wallet row (creating it if needed). Best-effort:
// retries without setup_completed_at in case the column isn't there yet.
async function persistWalletSetup(patch) {
  const w = currentWallet.value
  if (!w?.id) {
    const currency = currencyForCountry(selectedCountryId.value)
    const base = { owner_user_id: selectedProvider.value, country_id: selectedCountryId.value, currency, purpose: selectedPurpose.value, ...patch }
    const ins = await apiCreateWallet(base)
    if (ins.success && ins.data?.wallet) wallets.value.push(ins.data.wallet)
    return ins.success ? { error: null, data: ins.data?.wallet } : { error: { message: wpErr(ins) } }
  }
  const upd = await apiUpdateWallet(w.id, patch)
  if (upd.success) Object.assign(w, patch)
  return upd.success ? { error: null } : { error: { message: wpErr(upd) } }
}

// ── Account-level payout METHOD (one choice for the whole business) ───────────
// The payout method is NOT per-country: the provider picks ONE method (Stripe or
// the Bookboll wallet + bank details) and it's applied to EVERY currency wallet.
// We read the current method from any usable wallet that has one ("the template").
const accountWallet = computed(() => {
  const cur = purposeWalletList.value.filter(w => w.is_current !== false)
  const all = purposeWalletList.value
  // Prefer a usable, set-up CURRENT wallet; fall back to legacy so an existing
  // method on a closing wallet still reads as "set up".
  return cur.find(w => isWalletUsable(w) && w.setup_method)
      || all.find(w => isWalletUsable(w) && w.setup_method)
      || cur.find(w => w.setup_method)
      || all.find(w => w.setup_method) || null
})
const accountMethod      = computed(() => accountWallet.value?.setup_method || null)
const accountStatus      = computed(() => accountWallet.value?.setup_status || null)
// Account-level lifecycle: active | pending_review | rejected | none. The gate
// (lifting listing/program restrictions) requires 'active' — manual payouts only
// reach active after Bookboll approves the KYC; Stripe reaches it on connect.
const accountState       = computed(() => payoutSetupState(purposeWalletList.value))
const accountReady       = computed(() => accountState.value === 'active')
const accountRejectionReason = computed(() =>
  purposeWalletList.value.find(w => w.setup_status === 'rejected')?.kyc_rejection_reason || '')
const accountIsAutomatic = computed(() => accountMethod.value === 'stripe_connect' && accountReady.value)
const accountHasBank     = computed(() => !!(accountWallet.value?.payout_bank_name || accountWallet.value?.payout_iban || accountWallet.value?.payout_account_number))
const accountBankCountryName = computed(() => {
  const c = accountWallet.value?.payout_bank_country
  return BANKING_COUNTRIES_FLAT.find(x => x.code === c)?.name || c || ''
})

// ── Manual payout KYC (business details submitted for Bookboll approval) ───────
const kycForm = ref({ business_name:'', registration_no:'', address:'', contact:'', doc_url:'' })
const kycFieldErrors = ref({})
const kycDocUploading = ref(false)
const kycDocError = ref('')
const kycUploadOk = imageUploadConfigured()
function initKycForm() {
  const w = accountWallet.value || {}
  kycForm.value = {
    business_name:   w.kyc_business_name   || providerName(selectedProvider.value) || '',
    registration_no: w.kyc_registration_no || '',
    address:         w.kyc_address         || '',
    contact:         w.kyc_contact         || '',
    doc_url:         w.kyc_doc_url          || '',
  }
  kycFieldErrors.value = {}
  kycDocError.value = ''
}
function validateKyc() {
  const e = {}
  const f = kycForm.value
  if (!f.business_name.trim())   e.business_name   = 'Business legal name is required.'
  if (!f.registration_no.trim()) e.registration_no = 'Registration / licence number is required.'
  if (!f.address.trim())         e.address         = 'Registered business address is required.'
  kycFieldErrors.value = e
  return Object.keys(e).length === 0
}
async function onKycDocChange(ev) {
  const file = ev.target.files?.[0]; if (!file) return
  kycDocError.value = ''; kycDocUploading.value = true
  const res = await uploadImage(file, 'wallet')
  kycDocUploading.value = false
  if (res?.url) kycForm.value.doc_url = res.url
  else kycDocError.value = res?.error || 'Upload failed — try a smaller image.'
}

// Pre-fill the bank form from the account's existing method (template wallet).
function initAccountPayoutForm() {
  const u = accountWallet.value || {}
  payoutForm.value = {
    payout_bank_country:   u.payout_bank_country   || '',
    payout_bank_name:      u.payout_bank_name      || '',
    payout_account_holder: u.payout_account_holder || '',
    payout_iban:           u.payout_iban           || '',
    payout_account_number: u.payout_account_number || '',
    payout_routing_number: u.payout_routing_number || '',
    payout_swift:          u.payout_swift          || '',
    payout_account_type:   u.payout_account_type   || 'checking',
  }
}

// Write a method patch to EVERY currency wallet (operating countries ∪ existing
// current wallets ∪ home), creating rows that don't exist yet. Resilient to a
// missing setup_completed_at column.
async function applyMethodToAllWallets(patch) {
  const ids = new Set(purposeWalletList.value.filter(w => w.is_current !== false).map(w => cid(w.country_id)).filter(Boolean))
  for (const id of operatingCountryIdsFor(selectedProvider.value)) ids.add(cid(id))
  const home = providers.value.find(u => String(u.id) === String(selectedProvider.value))?.country_id
    || allBusinessOwners.value.find(u => u.id === selectedProvider.value)?.country_id
  if (home) ids.add(cid(home))
  if (!ids.size) return { error: { message: 'Add a location first so we know which country to pay you in.' } }
  const res = await applyMethodToWallets({
    owner_user_id: selectedProvider.value,
    purpose: selectedPurpose.value,
    country_ids: [...ids],
    patch,
  })
  if (!res.success) return { error: { message: wpErr(res) } }
  // Refresh local wallet list from response
  const returned = res.data?.wallets || []
  for (const nw of returned) {
    const i = wallets.value.findIndex(x => x.id === nw.id)
    if (i >= 0) wallets.value[i] = nw
    else wallets.value.push(nw)
  }
  return { error: null }
}

// Open / close the account payout-method editor (lives on the My Wallets landing).
const methodEditorOpen = ref(false)
let openedSetupPath = ''
function openRequestedSetup() {
  if (!walletDataReady.value || route.query.setup !== 'payout' || isPlatformAdmin.value || !canEditPayout.value || !selectedProvider.value) return
  if (openedSetupPath === route.fullPath) return
  openedSetupPath = route.fullPath
  walletView.value = 'list'
  openMethodEditor()
}
watch(() => route.fullPath, () => {
  openedSetupPath = ''
  openRequestedSetup()
})
function openMethodEditor() {
  bookbollSetupOpen.value = false
  payoutError.value = ''
  payoutFieldErrors.value = {}
  // First-time setup: start EMPTY so step 1 is just the country question —
  // methods only appear after the user answers it. Editing keeps the saved country.
  payoutCountryId.value = accountState.value === 'none' ? '' : (
    countries.value.find(c => c.iso_code === accountWallet.value?.payout_bank_country)?.id
    || accountWallet.value?.country_id
    || providers.value.find(u => String(u.id) === String(selectedProvider.value))?.country_id
    || allBusinessOwners.value.find(u => u.id === selectedProvider.value)?.country_id
    || walletCountryOptions.value[0]?.id || '')
  initAccountPayoutForm()
  initKycForm()
  methodEditorOpen.value = true
}
function closeMethodEditor() { methodEditorOpen.value = false; bookbollSetupOpen.value = false }

// Stripe Connect: connect → active immediately (Stripe runs its own KYC and pays out
// automatically at checkout). Applied to every currency wallet.
async function chooseExternalMethod(key) {
  bookbollSetupOpen.value = false
  const res = await applyMethodToAllWallets({
    setup_method: key, setup_status: 'active', setup_completed_at: new Date().toISOString(),
    kyc_rejection_reason: null,
  })
  // Surface the REAL database error — "run the latest update" alone sent us in
  // circles when the failure was a constraint, not a missing column.
  if (res?.error) { pushToast(`Couldn't connect — database said: "${res.error.message || 'unknown error'}". Send this message to support/Claude to get the exact fix.`, 'error'); return }
  methodEditorOpen.value = false
  pushToast(`${WALLET_METHOD_LABELS[key]} connected. Earnings are paid out automatically at checkout.`, 'success')
  loadAll()
}

// Bookboll wallet: reveal the KYC + bank-details form (pre-fill business country if known).
function startBookbollSetup() {
  // The Bookboll Wallet is beta and only powers the Subscription wallet — venue bookings need a
  // real payout rail (Stripe). Block it for the bookings purpose.
  if (isBookingsPurpose.value) {
    pushToast('The Bookboll Wallet is in beta and only available for the Membership wallet. Connect Stripe for your Bookings wallet.', 'error')
    return
  }
  payoutError.value = ''
  payoutFieldErrors.value = {}
  initAccountPayoutForm()
  initKycForm()
  const iso = payoutCountry.value?.iso_code
  if (!payoutForm.value.payout_bank_country && iso && BANKING_COUNTRIES_FLAT.find(x => x.code === iso)) {
    payoutForm.value.payout_bank_country = iso
  }
  bookbollSetupOpen.value = true
}

// Clear the method on every wallet (back to "not set up").
async function resetWalletMethod() {
  bookbollSetupOpen.value = false
  await applyMethodToAllWallets({ setup_method: null, setup_status: null, kyc_rejection_reason: null })
  loadAll()
}

// Submit Bookboll (manual) setup: KYC + bank details go to Bookboll for review.
// The method is recorded as 'pending_review' on every currency wallet — restrictions
// stay until platform staff approve it.
async function saveBookbollSetup() {
  if (!validateKyc()) { payoutError.value = 'Please complete your business details above.'; return }
  if (!validatePayout()) { payoutError.value = ''; return }
  setupSaving.value = true
  const f = payoutForm.value
  const k = kycForm.value
  const patch = {
    setup_method: 'bookboll', setup_status: 'pending_review', setup_completed_at: null,
    kyc_business_name:   k.business_name.trim()   || null,
    kyc_registration_no: k.registration_no.trim() || null,
    kyc_address:         k.address.trim()         || null,
    kyc_contact:         k.contact.trim()         || null,
    kyc_doc_url:         k.doc_url                 || null,
    kyc_submitted_at:    new Date().toISOString(),
    kyc_reviewed_at:     null, kyc_reviewed_by: null, kyc_rejection_reason: null,
    payout_bank_country:   f.payout_bank_country      || null,
    payout_bank_name:      f.payout_bank_name.trim()  || null,
    payout_account_holder: f.payout_account_holder.trim() || null,
    payout_iban:           f.payout_iban.replace(/\s/g,'').toUpperCase() || null,
    payout_account_number: f.payout_account_number.trim() || null,
    payout_routing_number: f.payout_routing_number.trim() || null,
    payout_swift:          f.payout_swift.replace(/\s/g,'').toUpperCase() || null,
    payout_account_type:   f.payout_account_type      || null,
  }
  const res = await applyMethodToAllWallets(patch)
  setupSaving.value = false
  if (res?.error) { payoutError.value = `Couldn't submit — database said: "${res.error.message || 'unknown error'}".`; return }
  methodEditorOpen.value = false
  bookbollSetupOpen.value = false
  pushToast('Submitted for review. Bookboll will verify your details and approve your manual payout method shortly.', 'success')
  loadAll()
}

const providerIncoming     = computed(() => transactions.value.filter(t => t.direction === 'credit' && t.wallet_id === currentWallet.value.id))
const providerOnHold       = computed(() => providerIncoming.value.filter(t => t.status === 'on_hold'))
const providerWithdrawals  = computed(() => withdrawals.value.filter(w => w.wallet_id === currentWallet.value.id))
// Admin queue = anything still in flight (needs approval or needs paying).
const openWithdrawalsAll   = computed(() => withdrawals.value.filter(w => ['pending','approved'].includes(w.status)))
const pendingWithdrawalsAll = computed(() => withdrawals.value.filter(w => w.status === 'pending'))
const approvedWithdrawalsAll = computed(() => withdrawals.value.filter(w => w.status === 'approved'))
const processedWithdrawalsAll = computed(() => withdrawals.value.filter(w => ['paid','rejected','cancelled'].includes(w.status)))
const filteredIncoming = computed(() => providerIncoming.value.filter(t =>
  (txFilter.value === 'all' || t.status === txFilter.value) &&
  (txTypeFilter.value === 'all' ||
    (txTypeFilter.value === 'subscription' ? t.type === 'subscription_online' : t.type !== 'subscription_online'))))
const providerHoldTotal = computed(() => providerOnHold.value.reduce((a,t) => a + Number(t.provider_share || 0), 0))
const providerOpenWithdrawals = computed(() => providerWithdrawals.value.filter(w => ['pending','approved'].includes(w.status)))

// Soonest upcoming release, for the "next unlock" line on the provider hero.
const nextRelease = computed(() => {
  const list = providerOnHold.value.filter(t => t.available_on).slice().sort((a,b) => a.available_on.localeCompare(b.available_on))
  if (!list.length) return null
  const date = list[0].available_on
  const amount = list.filter(t => t.available_on === date).reduce((a,t) => a + Number(t.provider_share || 0), 0)
  return { date, amount, days: daysUntil(date) }
})

// ── Provider payout notices (derived; localStorage-tracked like the customer bell) ──
const NOTICE_KEY = 'bb_wallet_notice_seen'
function readNoticeSeen() { try { return JSON.parse(localStorage.getItem(NOTICE_KEY) || '{}') } catch { return {} } }
const noticeTick = ref(0)
const providerNotices = computed(() => {
  noticeTick.value // dependency so dismiss re-evaluates
  if (role.value !== 'provider') return []
  const seen = readNoticeSeen()
  return providerWithdrawals.value
    .filter(w => ['approved','paid','rejected'].includes(w.status) && seen[w.id] !== w.status)
    .sort((a,b) => new Date(b.processed_at || b.approved_at || 0) - new Date(a.processed_at || a.approved_at || 0))
    .slice(0, 3)
    .map(w => ({ id: w.id, status: w.status, amount: w.amount, currency: w.currency, reason: w.rejection_reason, eta: payoutEta(w) }))
})
function dismissNotice(id, status) { const seen = readNoticeSeen(); seen[id] = status; localStorage.setItem(NOTICE_KEY, JSON.stringify(seen)); noticeTick.value++ }

const combinedActivity = computed(() => [
  ...providerIncoming.value.map(t => ({
    key: `tx-${t.id}`, date: t.created_at,
    kind: t.type === 'subscription_online' ? 'Subscription' : 'Booking',
    label: t.source_label || t.description || '—',
    amount: t.provider_share, currency: t.currency, direction: 'credit',
    statusClass: txStatusClass(t.status), statusLabel: txStatusLabel(t.status),
  })),
  ...providerWithdrawals.value.map(w => ({
    key: `wd-${w.id}`, date: w.requested_at, kind: 'Withdrawal',
    label: w.payout_method === 'bank_transfer' ? 'Bank transfer' : (w.payout_method || 'Payout'),
    amount: w.amount, currency: w.currency, direction: 'debit',
    statusClass: badgeClass(w.status), statusLabel: wdStatusLabel(w.status),
  })),
].sort((a,b) => new Date(b.date) - new Date(a.date)))

// ── Admin "All Earnings" tab filters (provider / type / currency) ─────────────
const earnSearch = ref('')
const earnType = ref('all')       // 'all' | 'booking' | 'subscription'
const earnCurrency = ref('all')   // 'all' | currency code present in the data
const adminEarnings = computed(() => transactions.value.filter(x => x.direction === 'credit'))
const earnCurrencies = computed(() => [...new Set(adminEarnings.value.map(t => t.currency).filter(Boolean))].sort())
const filteredEarnings = computed(() => adminEarnings.value.filter(t =>
  (earnType.value === 'all' || (earnType.value === 'subscription' ? t.type === 'subscription_online' : t.type !== 'subscription_online')) &&
  (earnCurrency.value === 'all' || t.currency === earnCurrency.value) &&
  (!earnSearch.value.trim() || providerName(t.owner_user_id).toLowerCase().includes(earnSearch.value.trim().toLowerCase()))))

const adminKpis = computed(() => {
  const open = withdrawals.value.filter(w => ['pending','approved'].includes(w.status))
  const paid = withdrawals.value.filter(w => w.status === 'paid')
  const onHoldAll = transactions.value.filter(t => t.status === 'on_hold' && t.direction === 'credit')
  return {
    openAmt:      open.reduce((a,w)  => a + Number(w.amount), 0),
    openCount:    open.length,
    paidAmt:      paid.reduce((a,w) => a + Number(w.amount), 0),
    platformFees: transactions.value.reduce((a,t) => a + Number(t.platform_fee || 0), 0),
    onHoldTotal:  onHoldAll.reduce((a,t) => a + Number(t.provider_share || 0), 0),
    onHoldCount:  onHoldAll.length,
  }
})

// Manual payout-method (KYC) approvals were MOVED to the Approvals page (it's a review task,
// not wallet management). See src/pages/Approvals.vue.

// ── Balance integrity guard ──────────────────────────────────────────────────
// A wallet's pending_balance should always equal its still-clearing earnings
// (on_hold credits) PLUS its open payout reservations (pending/approved requests).
// If they diverge, something didn't settle cleanly (e.g. a request that failed to
// save while the balance moved). Surface it so it never goes unnoticed again.
const walletDrift = computed(() => {
  return wallets.value.map(w => {
    const onHold = transactions.value
      .filter(t => t.wallet_id === w.id && t.status === 'on_hold' && t.direction === 'credit')
      .reduce((a, t) => a + Number(t.provider_share || 0), 0)
    const open = withdrawals.value
      .filter(x => x.wallet_id === w.id && ['pending', 'approved'].includes(x.status))
      .reduce((a, x) => a + Number(x.amount || 0), 0)
    const expected = +(onHold + open).toFixed(2)
    const actual = +Number(w.pending_balance || 0).toFixed(2)
    return { wallet: w, onHold, open, expected, actual, diff: +(actual - expected).toFixed(2) }
  }).filter(e => Math.abs(e.diff) > 0.01)
})

// Reconcile: set pending to the expected value and move the difference to/from
// available, keeping the provider's total balance unchanged.
async function reconcileWallet(entry) {
  const { wallet: w, expected, actual, diff } = entry
  const back = diff > 0
  if (!(await askConfirm({
    title: 'Reconcile this wallet?',
    message: `${providerName(w.owner_user_id)}'s pending balance is ${fmtMoney(actual, w.currency)}, but its clearing earnings + open payouts total ${fmtMoney(expected, w.currency)}. Reconciling sets pending to ${fmtMoney(expected, w.currency)} and ${back ? 'returns' : 'moves'} ${fmtMoney(Math.abs(diff), w.currency)} ${back ? 'to' : 'from'} available. Total balance is unchanged.`,
    danger: false,
    confirmText: 'Reconcile',
  }))) return
  // Delta form (pending −diff, available +diff) so a balance that moved after
  // this page loaded (a new payment, a release) is never clobbered.
  const upd = await applyWalletDelta(w.id, { pending: -diff, available: diff })
  if (upd.error) { pushToast('Could not reconcile — please try again.', 'error'); return }
  pushToast(`${providerName(w.owner_user_id)}'s wallet reconciled.`, 'success')
  loadAll()
}

// The other repair, for a NEGATIVE drift caused by a LOST CREDIT: a payment
// wrote its ledger row but its balance increment was overwritten, so the money
// never landed anywhere. Puts pending right WITHOUT raiding available — the
// provider's total goes up by the missing amount, matching what was actually
// collected. (If available is the inflated side, use Reconcile instead.)
async function restoreWalletPending(entry) {
  const { wallet: w, expected, diff } = entry
  if (diff >= 0) return
  if (!(await askConfirm({
    title: 'Add the missing amount to pending?',
    message: `Sets ${providerName(w.owner_user_id)}'s pending balance to ${fmtMoney(expected, w.currency)} without touching available — their total balance goes UP by ${fmtMoney(Math.abs(diff), w.currency)}. Use this when a payment's credit went missing (the provider is short overall). If their available balance looks too high instead, use Reconcile.`,
    danger: false,
    confirmText: 'Add to pending',
  }))) return
  const upd = await applyWalletDelta(w.id, { pending: -diff })   // diff < 0 → adds the missing amount
  if (upd.error) { pushToast('Could not update the wallet — please try again.', 'error'); return }
  pushToast(`${fmtMoney(Math.abs(diff), w.currency)} restored to ${providerName(w.owner_user_id)}'s pending balance.`, 'success')
  loadAll()
}

const releaseSchedule = computed(() => {
  const map = new Map(); const today = new Date(); today.setHours(0,0,0,0)
  for (const t of transactions.value) {
    if (t.status !== 'on_hold' || t.direction !== 'credit' || !t.available_on) continue
    const diff = Math.ceil((new Date(t.available_on) - today) / 864e5)
    if (diff < 0 || diff > 7) continue
    const cur = map.get(t.available_on) || { date: t.available_on, amount: 0, count: 0 }
    cur.amount += Number(t.provider_share || 0); cur.count++; map.set(t.available_on, cur)
  }
  return Array.from(map.values()).sort((a,b) => a.date.localeCompare(b.date))
})

// ── Pagination ─────────────────────────────────────────────────────────────────
const { page: actPage, perPage: actPerPage, totalPages: actTotalPages, paginated: paginatedActivity, visiblePages: actVisiblePages, goTo: actGoTo } = usePagination(combinedActivity)
const { page: inPage,  perPage: inPerPage,  totalPages: inTotalPages,  paginated: paginatedIncoming, visiblePages: inVisiblePages,  goTo: inGoTo  } = usePagination(filteredIncoming)
const { page: pwPage,  perPage: pwPerPage,  totalPages: pwTotalPages,  paginated: paginatedProvWd,   visiblePages: pwVisiblePages,  goTo: pwGoTo  } = usePagination(providerWithdrawals)
const { page: pendPage, perPage: pendPerPage, totalPages: pendTotalPages, paginated: paginatedPendWd, visiblePages: pendVisiblePages, goTo: pendGoTo } = usePagination(openWithdrawalsAll)
const { page: procPage, perPage: procPerPage, totalPages: procTotalPages, paginated: paginatedProcWd, visiblePages: procVisiblePages, goTo: procGoTo } = usePagination(processedWithdrawalsAll)

// ── Formatters ────────────────────────────────────────────────────────────────
// No default currency. Labelling money "AED" when it was charged in PKR is worse
// than showing no code at all — and the aggregate KPIs below genuinely span
// several currencies, so there is no single right code to print for them.
function fmtMoney(n, c) {
  const amt = Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const code = String(c ?? '').trim()
  return code ? `${code} ${amt}` : amt
}
function fmtDate(d)  { if (!d) return '—'; return new Date(d).toLocaleString() }
function fmtDay(d)   { if (!d) return '—'; return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }
function daysUntil(dateStr) { if (!dateStr) return 0; const today = new Date(); today.setHours(0,0,0,0); return Math.max(0, Math.ceil((new Date(dateStr) - today) / 864e5)) }
function txStatusClass(s)  { return s === 'on_hold' ? 'badge-warning' : s === 'completed' ? 'badge-success' : s === 'reversed' ? 'badge-error' : 'badge-neutral' }
function txStatusLabel(s)  { return s === 'on_hold' ? 'Clearing' : s === 'completed' ? 'Available' : s === 'reversed' ? 'Reversed' : s }
// Withdrawal status: pending -> approved -> paid (+ rejected / cancelled)
function badgeClass(s)     { return s === 'paid' ? 'badge-success' : s === 'approved' ? 'badge-info' : s === 'pending' ? 'badge-warning' : s === 'rejected' ? 'badge-error' : 'badge-neutral' }
function wdStatusLabel(s)  { return s === 'paid' ? 'Paid' : s === 'approved' ? 'Approved · paying out' : s === 'pending' ? 'Requested' : s === 'rejected' ? 'Rejected' : s === 'cancelled' ? 'Cancelled' : s }
// Estimated arrival: ~2 business days after approval. Pending requests show the rule.
function addBusinessDays(date, n) { const d = new Date(date); let added = 0; while (added < n) { d.setDate(d.getDate() + 1); const wd = d.getDay(); if (wd !== 0 && wd !== 6) added++ } return d }
function payoutEta(w) {
  if (!w) return ''
  if (w.status === 'approved' && w.approved_at) return `Expected by ${fmtDay(addBusinessDays(w.approved_at, 2))}`
  if (w.status === 'pending') return 'Usually 1–2 business days after approval'
  if (w.status === 'paid') return `Paid ${fmtDay(w.processed_at)}`
  return ''
}
function holdProgress(t)   { if (!t.available_on || !t.created_at) return 0; const s = new Date(t.created_at).getTime(), e = new Date(t.available_on).getTime(), n = Date.now(); return n <= s ? 0 : n >= e ? 100 : Math.round((n-s)/(e-s)*100) }

// ── Withdrawal modal ──────────────────────────────────────────────────────────
const wdModal = ref(false)
const wdForm  = ref({ amount: '', notes: '' })

// Build a human-readable account summary from saved payout profile
const savedPayoutSummary = computed(() => {
  const u = currentWallet.value
  if (!u?.payout_bank_name) return null
  const country = BANKING_COUNTRIES_FLAT.find(x => x.code === u.payout_bank_country)?.name || u.payout_bank_country || ''
  const lines = [
    { label: 'Bank',           value: u.payout_bank_name  },
    { label: 'Country',        value: country             },
    { label: 'Account Holder', value: u.payout_account_holder },
    u.payout_iban            ? { label: 'IBAN',           value: u.payout_iban } : null,
    u.payout_account_number  ? { label: 'Account No.',    value: u.payout_account_number } : null,
    u.payout_routing_number  ? { label: 'Routing / BSB',  value: u.payout_routing_number } : null,
    u.payout_swift           ? { label: 'SWIFT / BIC',    value: u.payout_swift } : null,
    u.payout_account_type && u.payout_account_type !== 'checking'
      ? { label: 'Account Type', value: u.payout_account_type } : null,
  ].filter(Boolean)
  return lines
})

function openWithdrawModal() {
  wdForm.value = {
    amount: Number(currentWallet.value.available_balance || 0).toFixed(2),
    notes: '',
  }
  wdModal.value = true
}
const wdError = ref('')
async function submitWithdrawal() {
  wdError.value = ''
  const amt = Number(wdForm.value.amount)
  if (!amt || amt <= 0)                                         { wdError.value = 'Enter a valid amount greater than 0.'; return }
  if (amt > Number(currentWallet.value.available_balance))      { wdError.value = `Amount exceeds available balance of ${fmtMoney(currentWallet.value.available_balance, currentWallet.value.currency)}.`; return }
  if (!hasPayoutDetails.value)                                   { wdError.value = 'Please save your payout details first.'; return }

  const u = currentWallet.value
  const details = [
    u.payout_bank_name, u.payout_account_holder,
    u.payout_iban || u.payout_account_number,
    u.payout_routing_number ? `Routing: ${u.payout_routing_number}` : '',
    u.payout_swift ? `SWIFT: ${u.payout_swift}` : '',
    u.payout_account_type && u.payout_account_type !== 'checking' ? u.payout_account_type : '',
  ].filter(Boolean).join(' | ')

  const res = await apiCreateWithdrawal({
    owner_user_id:  selectedProvider.value,
    country_id:     currentWallet.value.country_id || selectedCountryId.value,
    wallet_id:      currentWallet.value.id,
    amount:         amt,
    currency:       currentWallet.value.currency || '',
    payout_method:  'bank_transfer',
    payout_details: details,
    notes:          wdForm.value.notes.trim() || null,
  })
  if (!res.success) {
    wdError.value = wpErr(res, "Couldn't submit the request — your balance was not touched. Please try again.")
    return
  }
  wdModal.value = false
  pushToast(`Withdrawal request for ${fmtMoney(amt, currentWallet.value.currency)} submitted.`, 'success')
  loadAll()
}

// ── Hold policy ───────────────────────────────────────────────────────────────
const policyModal  = ref(false)
const policyTarget = ref(null)
const policyDays   = ref(7)
function openPolicy(w) {
  if (!w?.id) return
  policyTarget.value = w; policyDays.value = Number(w.hold_days) || 7; policyModal.value = true
}
async function savePolicy() {
  const days = Math.max(0, Math.min(90, Number(policyDays.value) || 0))
  await apiUpdateWallet(policyTarget.value.id, { hold_days: days })
  policyModal.value = false; loadAll()
}

// ── Release early ─────────────────────────────────────────────────────────────
async function releaseEarly(t) {
  if (!(await askConfirm({
    title: 'Release funds early?',
    message: `${fmtMoney(t.provider_share, t.currency)} will be released immediately to ${providerName(t.owner_user_id)} and moved from pending to available balance.`,
    danger: false,
    confirmText: 'Release now',
  }))) return
  let w = null
  if (t.wallet_id) {
    w = wallets.value.find(x => x.id === t.wallet_id) || null
    if (!w) {
      const list = await fetchWallets({})
      w = (list.data?.wallets || []).find(x => x.id === t.wallet_id) || null
    }
  } else {
    w = wallets.value.find(x => x.owner_user_id === t.owner_user_id && (!t.country_id || cid(x.country_id) === cid(t.country_id)) && x.is_current !== false) || null
  }
  if (!w) { pushToast('Could not find the provider wallet.', 'error'); return }
  const releasedAt = new Date().toISOString()
  const claim = await updateWalletTransaction(t.id, { status: 'completed', released_at: releasedAt })
  if (!claim.success || claim.data?.transaction?.status !== 'completed') {
    // If already completed, treat as already released
    if (t.status !== 'on_hold') {
      pushToast('This hold was already released.', 'error')
    } else {
      pushToast(wpErr(claim, 'Could not release the hold.'), 'error')
    }
    loadAll()
    return
  }
  // Only move balance if we flipped from on_hold — server update doesn't enforce status claim;
  // re-check via local row
  if (t.status !== 'on_hold') {
    pushToast('This hold was already released.', 'error')
    loadAll()
    return
  }
  const share = Number(t.provider_share || 0)
  await applyWalletDelta(w.id, { pending: -share, available: share })
  pushToast(`${fmtMoney(share, t.currency)} released to ${providerName(t.owner_user_id)}`, 'success')
  loadAll()
}

// ── Pause / resume an approved payout method (super admin only) ────────────────
// Paused = setup_status stays 'active' and balances are untouched, but the wallet no
// longer counts as ready/usable (isWalletReady/isWalletUsable in walletSetup.js), so
// online-payment and program-creation gates close until it's resumed.
const pauseBusyId = ref('')
async function togglePayoutPause(w) {
  if (!isSuperAdmin.value || !w?.id || pauseBusyId.value) return
  const pausing = !isWalletPaused(w)
  if (pausing) {
    const ok = await askConfirm({
      title: 'Pause this payout method?',
      message: `${providerName(w.owner_user_id)}'s ${normWalletPurpose(w.purpose) === 'bookings' ? 'Bookings' : 'Membership'} wallet (${countryName(w.country_id)}) keeps its balance and approval, but stops counting as an active payout method: ${normWalletPurpose(w.purpose) === 'bookings' ? 'venue full-online payment is disabled' : 'new membership programs are blocked'} until you resume it.`,
      confirmText: 'Pause payouts',
      danger: true,
    })
    if (!ok) return
  }
  pauseBusyId.value = w.id
  const patch = pausing
    ? { payout_paused: true, payout_paused_at: new Date().toISOString() }
    : { payout_paused: false, payout_paused_at: null }
  const res = await apiUpdateWallet(w.id, patch)
  pauseBusyId.value = ''
  if (!res.success) {
    pushToast(wpErr(res, 'Could not update the payout method.'), 'error')
    return
  }
  const row = wallets.value.find(x => x.id === w.id)
  if (row) Object.assign(row, patch)
  logAudit({
    action: pausing ? AUDIT.DISABLED : AUDIT.ENABLED, entity_type: 'wallet_payout', entity_id: w.id,
    details: `${pausing ? 'Paused' : 'Resumed'} ${providerName(w.owner_user_id)}'s ${normWalletPurpose(w.purpose)} wallet payout method (${countryName(w.country_id)})`,
  })
  pushToast(pausing ? 'Payout method paused' : 'Payout method resumed', 'success')
}

// ── Demo seed ─────────────────────────────────────────────────────────────────
async function seedPayment(type) {
  const gross = type === 'subscription_online' ? 400 : 250
  const platform = +(gross * 0.2).toFixed(2), share = +(gross - platform).toFixed(2)
  const w = currentWallet.value
  const holdDays = type === 'subscription_online' ? (Number(w.hold_days) || 7) : 0
  if (holdDays > 0) {
    const avail = new Date(); avail.setDate(avail.getDate() + holdDays)
    await createWalletTransaction({
      owner_user_id: selectedProvider.value, country_id: selectedCountryId.value, wallet_id: w.id, type, direction: 'credit',
      amount: gross, provider_share: share, platform_fee: platform,
      source_label: 'Subscription (simulated)', description: 'Monthly subscription charge (online)',
      status: 'on_hold', currency: w.currency || '',
      available_on: avail.toISOString().slice(0, 10), hold_days_snapshot: holdDays,
    })
    await applyWalletDelta(w.id, { pending: share, earned: share })
  } else {
    await createWalletTransaction({
      owner_user_id: selectedProvider.value, country_id: selectedCountryId.value, wallet_id: w.id, type, direction: 'credit',
      amount: gross, provider_share: share, platform_fee: platform,
      source_label: 'Online booking', description: 'Online booking payment',
      status: 'completed', currency: w.currency || '',
    })
    await applyWalletDelta(w.id, { available: share, earned: share })
  }
  loadAll()
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ role === 'provider' ? 'My Wallets' : 'Earnings &amp; Payouts' }}</h1>
        <div class="page-subtitle">
          {{ role === 'provider'
            ? 'A wallet per country — your earnings, what\'s still clearing, and your payouts.'
            : 'Provider earnings, funds on hold, and withdrawal payouts to settle.' }}
        </div>
      </div>
    </div>

    <!-- ══════════════════════ PROVIDER VIEW ══════════════════════════════ -->
    <template v-if="role === 'provider'">

      <!-- ── Sub-nav: a distinct PAGE per wallet purpose (separate routes) ── -->
      <div class="wnav" role="tablist">

        <router-link to="/wallet/bookings" class="wnav-tab" :class="{ active: selectedPurpose==='bookings' }" role="tab" :aria-selected="selectedPurpose==='bookings'">
          <Banknote :size="16" /><span>Bookings wallet</span>
        </router-link>
      </div>

      <!-- ── Landing: ONE payout method + your country wallets ─────────── -->
      <div v-if="walletView === 'list'" class="wallets-landing">
        <div class="wl-head">
          <div>
            <div class="wl-title">{{ purposeLabel }} payouts</div>
            <div class="wl-sub"><template v-if="isBookingsPurpose">Your share of online venue bookings — one Stripe payout method covers all currencies.</template><template v-else>Your membership earnings — one payout method covers all currencies.</template></div>
          </div>
        </div>

        <!-- Account payout method — a single choice applied to every currency wallet -->
        <div class="card pm-panel">
          <!-- Summary -->
          <div v-if="!methodEditorOpen" class="pm-row">
            <div class="pm-ic" :class="accountState==='active' ? (accountIsAutomatic ? 'auto' : 'manual') : accountState==='paused' ? 'pending' : accountState==='pending_review' ? 'pending' : accountState==='rejected' ? 'rejected' : 'none'">
              <component :is="accountState==='rejected' ? AlertCircle : accountState==='paused' ? Lock : accountState==='pending_review' ? Clock : accountIsAutomatic ? Zap : WalletIcon" :size="20" />
            </div>
            <div class="pm-info">
              <div class="pm-title">Payout method</div>
              <div v-if="accountState==='active' && accountIsAutomatic" class="pm-sub">Stripe Connect · automatic split — your share is paid straight to your bank, nothing to withdraw.</div>
              <div v-else-if="accountState==='active'" class="pm-sub">Bookboll Wallet · {{ accountWallet?.payout_bank_name || 'bank account on file' }}<template v-if="accountBankCountryName"> ({{ accountBankCountryName }})</template> — manual withdrawals · <b style="color: var(--positive-ink)">approved</b>.</div>
              <div v-else-if="accountState==='paused'" class="pm-sub">Your payout method is <b style="color: var(--warning-ink)">paused by Bookboll</b>. Your balance is safe, but online payments &amp; new programs that rely on this wallet are disabled until it's resumed — please contact Bookboll support.</div>
              <div v-else-if="accountState==='pending_review'" class="pm-sub">Manual payout (Bookboll Wallet) — <b style="color: var(--info-ink)">submitted for review</b>. Listings &amp; programs stay paused until Bookboll approves your details.</div>
              <div v-else-if="accountState==='rejected'" class="pm-sub">Manual payout — <b style="color: var(--danger-ink)">not approved</b><template v-if="accountRejectionReason">: {{ accountRejectionReason }}</template>. Update your details and resubmit.</div>
              <div v-else class="pm-sub">Your <b>{{ purposeLabel }} wallet</b> isn't set up yet. Choose how you'd like to get paid — it applies to <b>every</b> {{ purposeLabel }} currency wallet below.</div>
            </div>
            <button v-if="canEditPayout" class="btn" :class="(accountState==='none' || accountState==='rejected') ? 'btn-primary' : 'btn-secondary'" @click="openMethodEditor">
              <component :is="(accountState==='none' || accountState==='rejected') ? Plus : Pencil" :size="14" />{{ accountState==='none' ? 'Set up payout method' : accountState==='rejected' ? 'Fix & resubmit' : accountState==='pending_review' ? 'Edit submission' : 'Edit payout method' }}
            </button>
            <span v-else class="pm-viewonly">View only</span>
          </div>

          <!-- Editor (the chooser) -->
          <div v-else class="pm-editor">
            <button type="button" class="wl-back" @click="closeMethodEditor"><ArrowLeft :size="15" />Back</button>
            <div class="ws-mini">{{ accountState !== 'none' ? 'Edit payout method' : 'Set up payout method' }}</div>

            <!-- ONE question leads. Everything else (hint, method cards) appears only
                 after a country is chosen — nothing to read or scan before answering. -->
            <div v-if="!bookbollSetupOpen" class="ws-bankq" :class="{ hero: !payoutCountryId }">
              <label class="ws-bankq-label"><Globe :size="16" /> <strong>Which country is your <em class="ws-bankq-hl">business's bank account</em> in?</strong></label>
              <select v-model="payoutCountryId" class="ws-bankq-select">
                <option value="" disabled>Select country…</option>
                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <p v-if="payoutCountryId" class="ws-bankq-hint">Payouts go to a bank in {{ payoutCountry?.name || 'this country' }} — that decides which methods you can use below. One method covers <b>all</b> your {{ purposeLabel }} currency wallets, and you can switch it anytime.</p>
            </div>

            <!-- Bookboll path: KYC business details + bank details, submitted for review -->
            <div v-if="bookbollSetupOpen" class="card ws-bookboll">
              <div class="ws-kyc-note"><Info :size="14" /> Bookboll verifies these details and manually pays out your earnings. Your manual payout method goes live once our team approves it.</div>

              <div class="ws-bb-head"><Building2 :size="16" />Business details (for verification)</div>
              <div class="kyc-grid">
                <div class="form-group">
                  <label class="form-label">Business legal name *</label>
                  <input type="text" v-model="kycForm.business_name" placeholder="Registered company name" />
                  <div v-if="kycFieldErrors.business_name" class="field-err">{{ kycFieldErrors.business_name }}</div>
                </div>
                <div class="form-group">
                  <label class="form-label">Registration / licence no. *</label>
                  <input type="text" v-model="kycForm.registration_no" placeholder="Trade licence or company reg. number" />
                  <div v-if="kycFieldErrors.registration_no" class="field-err">{{ kycFieldErrors.registration_no }}</div>
                </div>
                <div class="form-group kyc-wide">
                  <label class="form-label">Registered business address *</label>
                  <input type="text" v-model="kycForm.address" placeholder="Street, city, country" />
                  <div v-if="kycFieldErrors.address" class="field-err">{{ kycFieldErrors.address }}</div>
                </div>
                <div class="form-group kyc-wide">
                  <label class="form-label">Contact (phone or email)</label>
                  <input type="text" v-model="kycForm.contact" placeholder="So Bookboll can reach you about payouts" />
                </div>
                <div class="form-group kyc-wide" v-if="kycUploadOk">
                  <label class="form-label">Verification document <span class="muted">(optional — trade licence / ID)</span></label>
                  <div class="kyc-doc">
                    <input type="file" accept="image/*" @change="onKycDocChange" />
                    <span v-if="kycDocUploading" class="muted">Uploading…</span>
                    <a v-else-if="kycForm.doc_url" :href="kycForm.doc_url" target="_blank" rel="noopener" class="kyc-doc-link"><Check :size="13" />Uploaded — view</a>
                  </div>
                  <div v-if="kycDocError" class="field-err">{{ kycDocError }}</div>
                </div>
              </div>

              <div class="ws-bb-head" style="margin-top:14px"><Building2 :size="16" />Payout bank details</div>
              <PayoutBankForm :form="payoutForm" :errors="payoutFieldErrors" :field-mode="payoutFieldMode" :countries-grouped="BANKING_COUNTRIES_GROUPED" />
              <div v-if="payoutError" class="form-error">{{ payoutError }}</div>
              <div class="ws-bb-actions">
                <button class="btn btn-secondary" @click="bookbollSetupOpen=false">Back</button>
                <button class="btn btn-primary" :disabled="setupSaving" @click="saveBookbollSetup"><Check :size="14" />{{ setupSaving ? 'Submitting…' : 'Submit for review' }}</button>
              </div>
            </div>

            <!-- Method cards — step 2: only after the country is chosen -->
            <div v-else-if="payoutCountryId" class="ws-methods">
              <!-- Stripe can't pay out in this country + BOOKINGS wallet: say sorry
                   plainly first, then present the deposit rail as the hero — it needs
                   NO payout method at all (the online deposit covers Bookboll's fee,
                   the rest is paid at the venue directly). Stripe shrinks to one row. -->
              <template v-if="!stripeMethod.available && isBookingsPurpose">
                <div class="ws-sorry">
                  <span class="ws-sorry-ic"><CreditCard :size="18" /></span>
                  <div class="ws-sorry-t">
                    <b>Sorry — Stripe isn't available in {{ payoutCountry?.name || 'your selected country' }}.</b>
                    <span>But bookings don't need it: the option below handles everything, with no bank setup at all.</span>
                  </div>
                </div>
                <div class="ws-group">
                  <div class="ws-method recommended">
                    <div class="wsm-top">
                      <div class="wsm-icon recommended"><Banknote :size="20" /></div>
                      <div class="wsm-titles">
                        <div class="wsm-name">Deposit + rest on arrival</div>
                        <span class="wsm-badge recommended">Best for bookings · works everywhere</span>
                      </div>
                    </div>
                    <div class="ws-dep-steps">
                      <span class="ws-dep-step"><span class="ws-dep-ic"><CreditCard :size="15" /></span> Customer pays a <b>small deposit online</b> to lock the slot — it covers Bookboll's fee</span>
                      <span class="ws-dep-step"><span class="ws-dep-ic"><Building2 :size="15" /></span> They pay <b>the rest at your venue</b> — straight into your hands</span>
                      <span class="ws-dep-step"><span class="ws-dep-ic"><Check :size="15" /></span> <b>Nothing to withdraw</b> — no bank transfers, no waiting</span>
                    </div>
                    <router-link to="/listings" class="btn btn-primary ws-method-btn"><ArrowRight :size="14" />Turn it on per listing — Listings → Payment options</router-link>
                  </div>
                </div>
                <div class="ws-stripe-mini">
                  <Globe :size="14" /> Prefer Stripe's automatic payouts? Switch the <b>bank-account country</b> above to a supported one.
                </div>
              </template>
              <div v-else class="ws-group">
                <div class="ws-group-head"><Zap :size="14" /> Automatic split <span>Your share is split at checkout and sent to your bank automatically — nothing to withdraw.</span></div>
                <div class="ws-method" :class="stripeMethod.available ? 'recommended' : 'ws-off'">
                  <div class="wsm-top">
                    <div class="wsm-icon" :class="stripeMethod.available ? 'recommended' : 'disabled'"><CreditCard :size="20" /></div>
                    <div class="wsm-titles">
                      <div class="wsm-name">Stripe Connect</div>
                      <span class="wsm-badge" :class="stripeMethod.available ? 'recommended' : 'na'">{{ stripeMethod.available ? 'Recommended' : `Not available in ${payoutCountry?.name || 'this country'}` }}</span>
                    </div>
                  </div>
                  <div v-if="stripeMethod.available" class="wsm-blurb">{{ stripeMethod.blurb }}</div>
                  <div v-if="!stripeMethod.available" class="wsm-switch"><Globe :size="13" /> Have a business bank account elsewhere? Switch the country above to use Stripe.</div>
                  <button v-if="stripeMethod.available" class="btn btn-primary ws-method-btn" @click="chooseExternalMethod('stripe_connect')"><ArrowRight :size="14" />Connect Stripe account</button>
                </div>
              </div>
              <!-- Bookboll Wallet is the FALLBACK rail only: shown solely where
                   it applies (membership wallet) AND Stripe is not available for
                   the provider's country. Where Stripe works we want providers on
                   the automatic split — no manual-withdrawal alternative. -->
              <div v-if="bookbollMethod.available && !stripeMethod.available" class="ws-group">
                <div class="ws-group-head"><Banknote :size="14" /> Manual withdrawal <span>Earnings collect in your Bookboll wallet — withdraw to your bank whenever you like.</span></div>
                <div class="ws-method">
                  <div class="wsm-top">
                    <div class="wsm-icon last"><WalletIcon :size="20" /></div>
                    <div class="wsm-titles">
                      <div class="wsm-name">Bookboll Wallet</div>
                      <span class="wsm-badge beta">Beta</span>
                    </div>
                  </div>
                  <div class="wsm-blurb">{{ bookbollMethod.blurb }}</div>
                  <button class="btn btn-primary ws-method-btn" @click="startBookbollSetup()"><Check :size="14" />Use Bookboll Wallet</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Currency wallets (auto-created per operating country; all share the method above) -->
        <div class="wl-subhead">Your {{ purposeLabel }} currency wallets</div>
        <div class="wl-grid">
          <button v-for="c in walletCards" :key="c.key" type="button" class="wl-card" :class="{ legacy: c.legacy }" @click="openWalletCard(c)">
            <div class="wl-card-top">
              <span class="wl-flag"><Globe :size="16" /></span>
              <span class="wl-country">{{ c.name }}</span>
              <span class="wl-badge muted">{{ c.currency }}</span>
            </div>
            <div class="wl-card-bal">{{ fmtMoney(c.wallet?.available_balance || 0, c.currency) }}</div>
            <div class="wl-card-foot">
              <span v-if="c.legacy">Legacy · {{ activeSubsByWallet[c.wallet?.id] || 0 }} active sub{{ (activeSubsByWallet[c.wallet?.id]||0)===1?'':'s' }}</span>
              <span v-else-if="accountReady">{{ accountIsAutomatic ? 'Auto-paid' : 'Available' }}</span>
              <span v-else class="wl-cta-text">No payout method</span>
              <ArrowRight :size="15" />
            </div>
          </button>
        </div>
      </div>

      <!-- ── Detail: the chosen country wallet ────────────────────────── -->
      <template v-else>
      <button type="button" class="wl-back" @click="backToWallets"><ArrowLeft :size="15" />All wallets</button>

      <!-- Which wallet you're viewing -->
      <div class="wd-head">
        <div class="wd-head-title">
          <Globe :size="16" />{{ countryName(currentWallet.country_id) }} · {{ purposeLabel }} wallet
          <span class="wl-badge muted">{{ currentWallet.currency }}</span>
          <span v-if="isLegacyWallet(currentWallet)" class="wd-legacy-tag">Legacy · {{ currentWalletActiveSubs }} active sub{{ currentWalletActiveSubs===1?'':'s' }}</span>
        </div>
        <div class="wd-head-actions">
          <button v-if="currentWallet.id" class="btn btn-danger-soft btn-sm" :disabled="!canDeleteWallet" :title="canDeleteWallet ? 'Delete this wallet' : 'Withdraw the balance, let funds clear, and end active subscriptions first'" @click="deleteWallet"><X :size="13" />Delete</button>
        </div>
      </div>
      <div v-if="isLegacyWallet(currentWallet)" class="wd-legacy-note">
        <Info :size="13" /> This is a closing wallet — its existing subscriptions keep paying out here. New earnings go to your current {{ countryName(currentWallet.country_id) }} wallet.
      </div>

      <!-- ── Gate: no ACTIVE payout method yet → send them back to set it up ─── -->
      <div v-if="!accountReady" class="wallet-setup">
        <div class="ws-head">
          <div class="ws-icon" :class="accountState==='pending_review' || accountState==='paused' ? 'pending' : accountState==='rejected' ? 'pending' : ''">
            <component :is="accountState==='paused' ? Lock : accountState==='pending_review' ? Clock : accountState==='rejected' ? AlertCircle : WalletIcon" :size="34" />
          </div>
          <div class="ws-title">{{ accountState==='paused' ? 'Payout method paused' : accountState==='pending_review' ? 'Manual payout method under review' : accountState==='rejected' ? 'Payout method not approved' : 'No payout method yet' }}</div>
          <div class="ws-body">
            <template v-if="accountState==='paused'">Bookboll has temporarily paused your payout method. Your balance is safe, but online payments &amp; new programs on this wallet are disabled until it's resumed — please contact Bookboll support.</template>
            <template v-else-if="accountState==='pending_review'">Your manual (Bookboll Wallet) details are with the Bookboll team. This wallet unlocks as soon as they approve it.</template>
            <template v-else-if="accountState==='rejected'">Your submission wasn't approved<template v-if="accountRejectionReason">: {{ accountRejectionReason }}</template>. Update your details and resubmit.</template>
            <template v-else>Set up a payout method to start getting paid. One method covers all your currency wallets.</template>
          </div>
          <button class="btn btn-primary" @click="backToWallets"><ArrowLeft :size="14" />Go to payout setup</button>
        </div>
      </div>

    <!-- ── Wallet content (only once a payout method is set up) ───── -->
    <template v-else>

      <!-- ── Payout status notices (derived) ─────────────────────────── -->
      <div v-if="providerNotices.length" class="pay-notices">
        <div v-for="n in providerNotices" :key="n.id" class="pay-notice" :class="n.status">
          <component :is="n.status==='paid' ? Check : n.status==='rejected' ? X : Banknote" :size="16" class="pn-ico" />
          <div class="pn-text">
            <template v-if="n.status==='paid'">Your withdrawal of <b>{{ fmtMoney(n.amount, n.currency) }}</b> was paid out. {{ n.eta }}.</template>
            <template v-else-if="n.status==='approved'">Your withdrawal of <b>{{ fmtMoney(n.amount, n.currency) }}</b> was approved and is on its way. {{ n.eta }}.</template>
            <template v-else>Your withdrawal of <b>{{ fmtMoney(n.amount, n.currency) }}</b> wasn't approved<template v-if="n.reason">: {{ n.reason }}</template>. The funds are back in your available balance.</template>
          </div>
          <button class="pn-x" @click="dismissNotice(n.id, n.status)" title="Dismiss"><X :size="14" /></button>
        </div>
      </div>

      <!-- ── Balance hero ────────────────────────────────────────────── -->
      <div class="pay-hero">
        <div class="ph-main">
          <!-- Automatic (Stripe split): paid out per earning, nothing to withdraw -->
          <template v-if="accountIsAutomatic">
            <div class="ph-label">Auto-paid via Stripe</div>
            <div class="ph-amount">{{ fmtMoney(currentWallet.lifetime_earnings, currentWallet.currency) }}</div>
            <div class="ph-next ph-auto"><Zap :size="13" /> Your share is split at checkout and sent to your bank automatically — there's nothing to withdraw.</div>
          </template>
          <!-- Manual (Bookboll): accrue, clear, withdraw -->
          <template v-else>
            <div class="ph-label">Available to withdraw</div>
            <div class="ph-amount">{{ fmtMoney(currentWallet.available_balance, currentWallet.currency) }}</div>
            <div v-if="nextRelease" class="ph-next"><Hourglass :size="13" /> {{ fmtMoney(nextRelease.amount, currentWallet.currency) }} more clears {{ nextRelease.days === 0 ? 'today' : 'in ' + nextRelease.days + 'd' }} · {{ fmtDay(nextRelease.date) }}</div>
            <div v-else class="ph-next ph-muted">Earnings become available after a {{ currentWallet.hold_days || 7 }}-day clearing period.</div>
            <button v-if="canWithdraw" class="btn btn-primary ph-btn" :disabled="!currentWallet.available_balance" @click="openWithdrawModal"><Banknote :size="15" />Withdraw</button>
            <div v-if="canWithdraw && !hasPayoutDetails" class="ph-warn"><AlertCircle :size="13" /> Add your bank details in the “Payout bank details” card below before withdrawing.</div>
          </template>
        </div>
        <div class="ph-side">
          <div class="ph-stat clearing">
            <div class="phs-top"><Hourglass :size="15" /><span>Clearing</span></div>
            <div class="phs-val">{{ fmtMoney(providerHoldTotal, currentWallet.currency) }}</div>
            <div class="phs-sub">{{ providerOnHold.length }} payment{{ providerOnHold.length===1?'':'s' }} maturing</div>
          </div>
          <div class="ph-stat paying">
            <div class="phs-top"><Clock :size="15" /><span>Being paid out</span></div>
            <div class="phs-val">{{ fmtMoney(currentWallet.pending_balance, currentWallet.currency) }}</div>
            <div class="phs-sub">{{ providerOpenWithdrawals.length }} request{{ providerOpenWithdrawals.length===1?'':'s' }} in progress</div>
          </div>
          <div class="ph-stat lifetime">
            <div class="phs-top"><TrendingUp :size="15" /><span>Lifetime earned</span></div>
            <div class="phs-val">{{ fmtMoney(currentWallet.lifetime_earnings, currentWallet.currency) }}</div>
            <div class="phs-sub">{{ fmtMoney(currentWallet.lifetime_withdrawn, currentWallet.currency) }} withdrawn</div>
          </div>
        </div>
      </div>

      <!-- ── Payouts in progress ─────────────────────────────────────── -->
      <div v-if="providerOpenWithdrawals.length" class="card pay-progress">
        <div class="pp-head"><Banknote :size="15" />Payouts in progress</div>
        <div v-for="w in providerOpenWithdrawals" :key="w.id" class="pp-row">
          <div class="pp-main">
            <div class="pp-amt">{{ fmtMoney(w.amount, w.currency) }}</div>
            <div class="pp-meta">Requested {{ fmtDay(w.requested_at) }} · {{ payoutEta(w) }}</div>
          </div>
          <span class="badge" :class="badgeClass(w.status)">{{ wdStatusLabel(w.status) }}</span>
          <button v-if="w.status==='pending'" class="btn btn-secondary btn-sm" @click="cancelWithdrawal(w)"><X :size="13" />Cancel</button>
          <span v-else class="pp-processing">Processing</span>
        </div>
      </div>

      <!-- ── Payout bank details (manual rail) — where withdrawals are sent.
           This is THE place a provider adds/edits their bank account; the
           withdrawal modal and the hero warning both point here. ─────────── -->
      <div v-if="!accountIsAutomatic" class="card pd-card">
        <div class="pd-head">
          <span class="pd-title"><Building2 :size="15" /> Payout bank details</span>
          <button v-if="canEditPayout && !payoutEditing" class="btn btn-secondary btn-sm" @click="startPayoutEdit"><Pencil :size="12" />{{ hasPayoutDetails ? 'Edit' : 'Add details' }}</button>
        </div>
        <template v-if="!payoutEditing">
          <div v-if="savedPayoutSummary" class="pd-rows">
            <div v-for="row in savedPayoutSummary" :key="row.label" class="pd-row"><span>{{ row.label }}</span><b>{{ row.value }}</b></div>
          </div>
          <div v-else class="pd-empty"><AlertCircle :size="14" /> No bank account saved for this wallet yet — add your details so withdrawals can be paid out.</div>
        </template>
        <template v-else>
          <PayoutBankForm :form="payoutForm" :errors="payoutFieldErrors" :field-mode="payoutFieldMode" :countries-grouped="BANKING_COUNTRIES_GROUPED" />
          <div v-if="payoutError" class="form-error">{{ payoutError }}</div>
          <div class="pd-actions">
            <button class="btn btn-secondary" @click="payoutEditing = false">Cancel</button>
            <button class="btn btn-primary" :disabled="payoutSaving" @click="savePayoutDetails"><Check :size="14" />{{ payoutSaving ? 'Saving…' : 'Save bank details' }}</button>
          </div>
        </template>
      </div>

      <!-- Payout method is set per-account on the My Wallets page; a small reminder here -->
      <div class="card pm-mini">
        <component :is="accountIsAutomatic ? Zap : WalletIcon" :size="15" class="pm-mini-ic" />
        <span class="pm-mini-text">
          Paid via <b>{{ accountIsAutomatic ? 'Stripe Connect (automatic split)' : 'Bookboll Wallet (manual withdrawal)' }}</b> — your payout method covers every currency wallet.
        </span>
        <button class="btn btn-secondary btn-sm pm-mini-btn" @click="backToWallets"><Pencil :size="12" />Manage on My Wallets</button>
      </div>

      <!-- On-hold breakdown -->
      <div v-if="providerOnHold.length" class="card" style="padding:16px;margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div><div style="font-weight:600;display:flex;align-items:center;gap:6px"><Hourglass :size="16"/>Upcoming releases</div>
          <div class="muted" style="font-size:12px">Funds move to Available automatically on release date.</div></div>
        </div>
        <div class="hold-list">
          <div v-for="t in providerOnHold" :key="t.id" class="hold-row">
            <div class="hold-main"><div class="hold-title">{{ t.source_label || 'Subscription' }}</div><div class="hold-sub muted">Credited {{ fmtDay(t.created_at) }}</div></div>
            <div class="hold-bar-wrap"><div class="hold-bar"><div class="hold-bar-fill" :style="{width:holdProgress(t)+'%'}"></div></div><div class="hold-bar-label">Available {{ fmtDay(t.available_on) }} · in {{ daysUntil(t.available_on) }}d</div></div>
            <div class="hold-amount">{{ fmtMoney(t.provider_share, t.currency) }}</div>
          </div>
        </div>
      </div>

      <!-- Testing tools (collapsed; simulate incoming earnings) -->
      <details class="demo-tools">
        <summary><Zap :size="13" />Testing tools</summary>
        <div class="demo-tools-body">
          <span class="muted" style="font-size:12px;margin-right:auto">Simulate incoming earnings to test the wallet:</span>
          <button class="btn btn-secondary btn-sm" @click="seedPayment('booking_online')"><Plus :size="13"/>Simulate Online Booking</button>
          <button class="btn btn-secondary btn-sm" @click="seedPayment('subscription_online')"><Plus :size="13"/>Simulate Subscription</button>
        </div>
      </details>

      <!-- Activity tabs -->
      <div class="tabs">
        <div class="tab" :class="{active:tab==='all'}"         @click="tab='all'">All Activity ({{ combinedActivity.length }})</div>
        <div class="tab" :class="{active:tab==='incoming'}"    @click="tab='incoming'">Incoming ({{ providerIncoming.length }})</div>
        <div class="tab" :class="{active:tab==='withdrawals'}" @click="tab='withdrawals'">Withdrawals ({{ providerWithdrawals.length }})</div>
      </div>

      <div v-if="tab==='all'" class="card table-wrap">
        <table><thead><tr><th>Date</th><th>Kind</th><th>Description</th><th style="text-align:right">Amount</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="row in paginatedActivity" :key="row.key">
            <td>{{ fmtDate(row.date) }}</td>
            <td><span class="badge" :class="row.kind==='Withdrawal'?'badge-neutral':row.kind==='Subscription'?'badge-info':'badge-neutral'">{{ row.kind }}</span></td>
            <td>{{ row.label }}</td>
            <td style="text-align:right;font-weight:600" :style="{color:row.direction==='credit'?'#059669':'#b45309'}">{{ row.direction==='credit'?'+':'-' }} {{ fmtMoney(row.amount,row.currency) }}</td>
            <td><span class="badge" :class="row.statusClass">{{ row.statusLabel }}</span></td>
          </tr>
          <tr v-if="!combinedActivity.length"><td colspan="5" class="empty">No activity yet.</td></tr>
        </tbody></table>
        <PaginationBar :page="actPage" :perPage="actPerPage" :totalPages="actTotalPages" :total="combinedActivity.length" :visiblePages="actVisiblePages" @update:perPage="actPerPage=$event" @goTo="actGoTo"/>
      </div>

      <div v-else-if="tab==='incoming'">
        <div class="filter-row">
          <button class="chip" :class="{active:txFilter==='all'}"       @click="txFilter='all'">All</button>
          <button class="chip" :class="{active:txFilter==='on_hold'}"   @click="txFilter='on_hold'">On hold</button>
          <button class="chip" :class="{active:txFilter==='completed'}" @click="txFilter='completed'">Available</button>
          <button v-if="providerIncoming.some(t => t.status === 'reversed')" class="chip" :class="{active:txFilter==='reversed'}" @click="txFilter='reversed'">Reversed</button>
          <span class="chip-sep"></span>
          <button class="chip" :class="{active:txTypeFilter==='all'}"          @click="txTypeFilter='all'">All types</button>
          <button class="chip" :class="{active:txTypeFilter==='booking'}"      @click="txTypeFilter='booking'">Bookings</button>
          <button class="chip" :class="{active:txTypeFilter==='subscription'}" @click="txTypeFilter='subscription'">Memberships</button>
        </div>
        <div class="card table-wrap">
          <table><thead><tr><th>Date</th><th>Type</th><th>Source</th><th style="text-align:right">Gross</th><th style="text-align:right">Platform %</th><th style="text-align:right">Your Share</th><th>Status</th><th>Available on</th></tr></thead>
          <tbody>
            <tr v-for="t in paginatedIncoming" :key="t.id">
              <td>{{ fmtDate(t.created_at) }}</td>
              <td><span class="badge" :class="t.type==='subscription_online'?'badge-info':'badge-neutral'">{{ t.type==='subscription_online'?'Subscription':'Booking' }}</span></td>
              <td>{{ t.source_label||'—' }}</td>
              <td style="text-align:right">{{ fmtMoney(t.amount,t.currency) }}</td>
              <td style="text-align:right;color: var(--warning-ink)">- {{ fmtMoney(t.platform_fee,t.currency) }}</td>
              <td style="text-align:right;font-weight:600;color: var(--positive-ink)">{{ fmtMoney(t.provider_share,t.currency) }}</td>
              <td><span class="badge" :class="txStatusClass(t.status)">{{ txStatusLabel(t.status) }}</span></td>
              <td><template v-if="t.status==='on_hold'">{{ fmtDay(t.available_on) }} <span class="muted">({{ daysUntil(t.available_on) }}d)</span></template><template v-else-if="t.released_at">{{ fmtDay(t.released_at) }}</template><template v-else>—</template></td>
            </tr>
            <tr v-if="!filteredIncoming.length"><td colspan="8" class="empty">No transactions.</td></tr>
          </tbody></table>
          <PaginationBar :page="inPage" :perPage="inPerPage" :totalPages="inTotalPages" :total="filteredIncoming.length" :visiblePages="inVisiblePages" @update:perPage="inPerPage=$event" @goTo="inGoTo"/>
        </div>
      </div>

      <div v-else class="card table-wrap">
        <table><thead><tr><th>Requested</th><th>Amount</th><th>Method</th><th>Status</th><th>Processed</th><th>Note</th></tr></thead>
        <tbody>
          <tr v-for="w in paginatedProvWd" :key="w.id">
            <td>{{ fmtDate(w.requested_at) }}</td><td>{{ fmtMoney(w.amount,w.currency) }}</td><td>{{ w.payout_method === 'bank_transfer' ? 'Bank transfer' : w.payout_method }}</td>
            <td><span class="badge" :class="badgeClass(w.status)">{{ wdStatusLabel(w.status) }}</span></td>
            <td>{{ fmtDate(w.processed_at) }}</td><td>{{ w.rejection_reason||'—' }}</td>
          </tr>
          <tr v-if="!providerWithdrawals.length"><td colspan="6" class="empty">No withdrawal requests yet.</td></tr>
        </tbody></table>
        <PaginationBar :page="pwPage" :perPage="pwPerPage" :totalPages="pwTotalPages" :total="providerWithdrawals.length" :visiblePages="pwVisiblePages" @update:perPage="pwPerPage=$event" @goTo="pwGoTo"/>
      </div>
    </template><!-- /wallet content gate -->
    </template><!-- /wallet detail -->
    </template><!-- /provider view -->

    <!-- ══════════════════════ ADMIN VIEW ════════════════════════════════ -->
    <template v-else>
      <!-- Balance integrity guard -->
      <div v-if="walletDrift.length" class="drift-banner">
        <div class="drift-head"><AlertCircle :size="17" />{{ walletDrift.length }} wallet{{ walletDrift.length===1?'':'s' }} need reconciling</div>
        <div class="drift-sub">A provider's pending balance doesn't match its clearing earnings plus open payout requests. <b>Reconcile</b> moves the difference to/from available (total unchanged) — right when available is the inflated side. <b>Add to pending</b> restores the amount without touching available — right when a payment's credit went missing and the provider is short overall.</div>
        <div v-for="e in walletDrift" :key="e.wallet.id" class="drift-row">
          <div class="drift-main">
            <b>{{ providerName(e.wallet.owner_user_id) }}</b>
            <span>Pending shows {{ fmtMoney(e.actual, e.wallet.currency) }} · clearing + open payouts = {{ fmtMoney(e.expected, e.wallet.currency) }} · off by {{ fmtMoney(Math.abs(e.diff), e.wallet.currency) }}</span>
          </div>
          <span class="drift-actions">
            <button class="btn btn-secondary btn-sm" @click="reconcileWallet(e)"><RefreshCw :size="13" />Reconcile</button>
            <button v-if="e.diff < 0" class="btn btn-secondary btn-sm" @click="restoreWalletPending(e)"><Plus :size="13" />Add to pending</button>
          </span>
        </div>
      </div>

      <!-- KPIs -->
      <div class="grid grid-4" style="margin-bottom:20px">
        <div class="card kpi">
          <div class="kpi-top"><span class="kpi-label">Funds on Hold</span><div class="kpi-icon" style="background: var(--warning-bg);color: var(--warning-ink)"><Hourglass :size="18"/></div></div>
          <div class="kpi-value">{{ fmtMoney(adminKpis.onHoldTotal) }}</div>
          <div class="kpi-sub">{{ adminKpis.onHoldCount }} credits across all providers</div>
        </div>
        <div class="card kpi">
          <div class="kpi-top"><span class="kpi-label">Awaiting Payout</span><div class="kpi-icon" style="background: var(--warning-bg);color: var(--warning-ink)"><Clock :size="18"/></div></div>
          <div class="kpi-value">{{ fmtMoney(adminKpis.openAmt) }}</div>
          <div class="kpi-sub kpi-delta neutral">{{ adminKpis.openCount }} request{{ adminKpis.openCount===1?'':'s' }} to action</div>
        </div>
        <div class="card kpi">
          <div class="kpi-top"><span class="kpi-label">Total Paid Out</span><div class="kpi-icon" style="background: var(--positive-bg);color: var(--positive-ink)"><Check :size="18"/></div></div>
          <div class="kpi-value">{{ fmtMoney(adminKpis.paidAmt) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-top"><span class="kpi-label">Platform Revenue</span><div class="kpi-icon" style="background: var(--info-bg);color: var(--info-ink)"><DollarSign :size="18"/></div></div>
          <div class="kpi-value">{{ fmtMoney(adminKpis.platformFees) }}</div>
        </div>
      </div>

      <!-- Release schedule -->
      <div class="card" style="padding:16px;margin-bottom:20px">
        <div style="font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:10px"><Calendar :size="16"/>Next 7-day release schedule</div>
        <div v-if="!releaseSchedule.length" class="muted" style="font-size:13px">No scheduled releases in the next 7 days.</div>
        <div v-else class="schedule-grid">
          <div v-for="s in releaseSchedule" :key="s.date" class="schedule-cell">
            <div class="schedule-date">{{ fmtDay(s.date) }}</div>
            <div class="schedule-amt">{{ fmtMoney(s.amount) }}</div>
            <div class="schedule-count">{{ s.count }} credit{{ s.count===1?'':'s' }}</div>
          </div>
        </div>
      </div>

      <!-- Hold policy + payout methods per provider -->
      <div class="card" style="padding:16px;margin-bottom:20px">
        <div style="font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:6px"><Hourglass :size="16"/>Provider wallets — hold policy &amp; payout methods</div>
        <div class="muted" style="font-size:12px;margin-bottom:10px">Hold periods apply to future subscription credits only. Pausing an approved payout method closes that wallet's online-payment / program gates until you resume it — balances stay untouched.</div>
        <div class="mini-table-scroll">
        <table class="mini-table">
          <thead><tr><th>Provider</th><th>Country</th><th>Payout method</th><th>Hold period</th><th>Pending balance</th><th>Available balance</th><th></th></tr></thead>
          <tbody>
            <tr v-for="w in wallets" :key="w.id" :class="{ 'row-paused': isWalletPaused(w) }">
              <td>{{ providerName(w.owner_user_id) }}</td><td>{{ countryName(w.country_id) }}</td>
              <td>
                <template v-if="w.setup_method">
                  {{ WALLET_METHOD_LABELS[w.setup_method] || w.setup_method }}
                  <span v-if="isWalletPaused(w)" class="badge badge-warning" style="margin-left:5px">Paused</span>
                  <span v-else-if="w.setup_status==='active'" class="badge badge-success" style="margin-left:5px">Active</span>
                  <span v-else class="badge badge-neutral" style="margin-left:5px">{{ w.setup_status==='pending_review' ? 'In review' : (w.setup_status || '—') }}</span>
                </template>
                <span v-else class="muted">—</span>
              </td>
              <td>{{ w.hold_days||7 }} days</td>
              <td>{{ fmtMoney(w.pending_balance,w.currency) }}</td><td>{{ fmtMoney(w.available_balance,w.currency) }}</td>
              <td style="text-align:right;white-space:nowrap">
                <button
                  v-if="isSuperAdmin && w.setup_method && (w.setup_status==='active' || isWalletPaused(w))"
                  class="btn btn-sm" :class="isWalletPaused(w) ? 'btn-success' : 'btn-secondary'"
                  :disabled="pauseBusyId === w.id"
                  :title="isWalletPaused(w) ? 'Resume this payout method' : 'Pause this payout method'"
                  @click="togglePayoutPause(w)"
                >
                  <component :is="isWalletPaused(w) ? Check : Lock" :size="13"/>{{ isWalletPaused(w) ? 'Resume' : 'Pause' }}
                </button>
                <button class="btn btn-sm btn-secondary" style="margin-left:6px" @click="openPolicy(w)"><Zap :size="13"/>Edit</button>
              </td>
            </tr>
            <tr v-if="!wallets.length"><td colspan="7" class="empty">No wallets yet.</td></tr>
          </tbody>
        </table>
        </div>
      </div>

      <!-- Tabs -->
      <div class="tabs">
        <div class="tab" :class="{active:tab==='requests'}"    @click="tab='requests'">
          Payout Queue ({{ openWithdrawalsAll.length }})
          <span v-if="openWithdrawalsAll.length" class="tab-badge">{{ openWithdrawalsAll.length }}</span>
        </div>
        <div class="tab" :class="{active:tab==='earnings'}"    @click="tab='earnings'">All Earnings ({{ transactions.filter(x=>x.direction==='credit').length }})</div>
        <div class="tab" :class="{active:tab==='onhold'}"      @click="tab='onhold'">On-Hold Credits ({{ adminKpis.onHoldCount }})</div>
        <div class="tab" :class="{active:tab==='processed'}"   @click="tab='processed'">Processed ({{ processedWithdrawalsAll.length }})</div>
      </div>

      <!-- Payout Queue (main action area) -->
      <div v-if="tab==='requests'">
        <div v-if="!openWithdrawalsAll.length" class="card" style="padding:32px;text-align:center;color:var(--text-muted)">
          <Check :size="32" style="margin:0 auto 10px;color:#10b981"/>
          <div style="font-weight:600">All caught up!</div>
          <div style="font-size:13px;margin-top:4px">No withdrawals waiting to be approved or paid.</div>
        </div>
        <div v-else class="wr-list">
          <div v-for="w in paginatedPendWd" :key="w.id" class="wr-card" @click="openRequest(w)">
            <div class="wr-left">
              <div class="wr-provider">{{ providerName(w.owner_user_id) }}</div>
              <div class="wr-meta">{{ countryName(w.country_id) }} · Requested {{ fmtDay(w.requested_at) }}<template v-if="w.status==='approved'"> · {{ payoutEta(w) }}</template></div>
            </div>
            <div class="wr-center">
              <span class="badge" :class="badgeClass(w.status)">{{ wdStatusLabel(w.status) }}</span>
            </div>
            <div class="wr-amount">{{ fmtMoney(w.amount, w.currency) }}</div>
            <div class="wr-queue-actions" @click.stop>
              <button v-if="w.status==='pending'" class="btn btn-success btn-sm" :disabled="approvingReq" @click="approveWithdrawal(w)"><Check :size="13"/>Approve</button>
              <button v-if="w.status==='approved'" class="btn btn-primary btn-sm" :disabled="markingPaid" @click="markWithdrawalPaid(w)"><Banknote :size="13"/>Mark paid</button>
              <button class="btn btn-secondary btn-sm" @click="openRequest(w)"><ArrowUpRight :size="13"/>Review</button>
            </div>
          </div>
        </div>
        <PaginationBar v-if="openWithdrawalsAll.length" :page="pendPage" :perPage="pendPerPage" :totalPages="pendTotalPages" :total="openWithdrawalsAll.length" :visiblePages="pendVisiblePages" @update:perPage="pendPerPage=$event" @goTo="pendGoTo" style="margin-top:12px"/>
      </div>

      <!-- All Earnings -->
      <div v-else-if="tab==='earnings'" class="card table-wrap">
        <div class="filter-row" style="padding:12px 14px 0;flex-wrap:wrap;gap:8px;display:flex;align-items:center;">
          <input v-model="earnSearch" placeholder="Search provider…" style="height:34px;padding:0 10px;border:1px solid var(--border,#e2e8f0);border-radius:8px;font-size:13px;min-width:170px;" />
          <span class="chip-sep"></span>
          <button class="chip" :class="{active:earnType==='all'}"          @click="earnType='all'">All types</button>
          <button class="chip" :class="{active:earnType==='booking'}"      @click="earnType='booking'">Bookings</button>
          <button class="chip" :class="{active:earnType==='subscription'}" @click="earnType='subscription'">Memberships</button>
          <template v-if="earnCurrencies.length > 1">
            <span class="chip-sep"></span>
            <button class="chip" :class="{active:earnCurrency==='all'}" @click="earnCurrency='all'">All currencies</button>
            <button v-for="c in earnCurrencies" :key="c" class="chip" :class="{active:earnCurrency===c}" @click="earnCurrency=c">{{ c }}</button>
          </template>
        </div>
        <table><thead><tr><th>Date</th><th>Provider</th><th>Source</th><th>Type</th><th style="text-align:right">Gross</th><th style="text-align:right">Platform fee</th><th style="text-align:right">Provider share</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="t in filteredEarnings.slice(0,100)" :key="t.id">
            <td>{{ fmtDate(t.created_at) }}</td><td>{{ providerName(t.owner_user_id) }}</td><td>{{ t.source_label||'—' }}</td>
            <td><span class="badge" :class="t.type==='subscription_online'?'badge-info':'badge-neutral'">{{ t.type==='subscription_online'?'Subscription':'Booking' }}</span></td>
            <td style="text-align:right">{{ fmtMoney(t.amount,t.currency) }}</td>
            <td style="text-align:right;color: var(--warning-ink)">- {{ fmtMoney(t.platform_fee,t.currency) }}</td>
            <td style="text-align:right;font-weight:600;color: var(--positive-ink)">{{ fmtMoney(t.provider_share,t.currency) }}</td>
            <td><span class="badge" :class="txStatusClass(t.status)">{{ txStatusLabel(t.status) }}</span></td>
          </tr>
          <tr v-if="!filteredEarnings.length"><td colspan="8" class="empty">{{ adminEarnings.length ? 'Nothing matches these filters.' : 'No earnings yet.' }}</td></tr>
        </tbody></table>
        <div v-if="filteredEarnings.length > 100" class="muted" style="padding:10px 14px;font-size:12px;">Showing the 100 most recent of {{ filteredEarnings.length }} matching credits — narrow with the filters above.</div>
      </div>

      <!-- On-Hold Credits -->
      <div v-else-if="tab==='onhold'" class="card table-wrap">
        <table><thead><tr><th>Credited</th><th>Provider</th><th>Source</th><th style="text-align:right">Provider share</th><th>Available on</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
          <tr v-for="t in transactions.filter(x=>x.status==='on_hold'&&x.direction==='credit')" :key="t.id">
            <td>{{ fmtDay(t.created_at) }}</td><td>{{ providerName(t.owner_user_id) }}</td><td>{{ t.source_label||'—' }}</td>
            <td style="text-align:right;font-weight:600">{{ fmtMoney(t.provider_share,t.currency) }}</td>
            <td>{{ fmtDay(t.available_on) }} <span class="muted">({{ daysUntil(t.available_on) }}d)</span></td>
            <td style="text-align:right"><button class="btn btn-sm btn-secondary" @click="releaseEarly(t)"><Zap :size="13"/>Release early</button></td>
          </tr>
          <tr v-if="!adminKpis.onHoldCount"><td colspan="6" class="empty">No funds currently on hold.</td></tr>
        </tbody></table>
      </div>

      <!-- Processed Withdrawals -->
      <div v-else class="card table-wrap">
        <table><thead><tr><th>Requested</th><th>Provider</th><th>Amount</th><th>Method</th><th>Status</th><th>Processed</th><th>By</th><th>Note</th></tr></thead>
        <tbody>
          <tr v-for="w in paginatedProcWd" :key="w.id">
            <td>{{ fmtDate(w.requested_at) }}</td><td>{{ providerName(w.owner_user_id) }}</td>
            <td>{{ fmtMoney(w.amount,w.currency) }}</td><td>{{ w.payout_method }}</td>
            <td><span class="badge" :class="badgeClass(w.status)">{{ w.status }}</span></td>
            <td>{{ fmtDate(w.processed_at) }}</td><td>{{ w.processed_by||'—' }}</td>
            <td>{{ w.rejection_reason||'—' }}</td>
          </tr>
          <tr v-if="!processedWithdrawalsAll.length"><td colspan="8" class="empty">No processed requests yet.</td></tr>
        </tbody></table>
        <PaginationBar :page="procPage" :perPage="procPerPage" :totalPages="procTotalPages" :total="processedWithdrawalsAll.length" :visiblePages="procVisiblePages" @update:perPage="procPerPage=$event" @goTo="procGoTo"/>
      </div>
    </template>

    <!-- ══════════ WITHDRAWAL REQUEST DETAIL PANEL (Admin) ══════════════ -->
    <Teleport to="body">
      <div v-if="viewingRequest" class="req-backdrop" @click.self="closeRequest">
        <div class="req-panel" v-dialog="closeRequest">
          <!-- Header -->
          <div class="req-header">
            <div>
              <div class="req-title">Withdrawal Request</div>
              <div class="req-ref">#REQ_{{ String(viewingRequest.id).slice(0,8).toUpperCase() }}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="badge" :class="badgeClass(viewingRequest.status)">{{ viewingRequest.status }}</span>
              <button class="req-close" @click="closeRequest" aria-label="Close"><X :size="18"/></button>
            </div>
          </div>

          <div v-if="reqLoading" class="req-loading"><Loader :size="22" class="spin"/>Loading details…</div>

          <div v-else class="req-body">
            <!-- Overview -->
            <div class="req-section">
              <div class="req-section-title">Request Overview</div>
              <div class="req-grid">
                <div class="req-item"><div class="req-item-label">Provider</div><div class="req-item-value">{{ reqProviderName }}</div></div>
                <div class="req-item"><div class="req-item-label">Amount</div><div class="req-item-value req-amount">{{ fmtMoney(viewingRequest.amount, viewingRequest.currency) }}</div></div>
                <div class="req-item"><div class="req-item-label">Payout Method</div><div class="req-item-value">{{ viewingRequest.payout_method }}</div></div>
                <div class="req-item"><div class="req-item-label">Requested on</div><div class="req-item-value">{{ fmtDate(viewingRequest.requested_at) }}</div></div>
              </div>
              <div v-if="viewingRequest.payout_details" style="margin-top:10px">
                <div class="req-item-label">Payout details (from request)</div>
                <div class="req-item-value" style="font-family:monospace;font-size:13px;margin-top:2px;word-break:break-all">{{ viewingRequest.payout_details }}</div>
              </div>
            </div>

            <!-- WHO is asking: the provider's business & contact details -->
            <div class="req-section">
              <div class="req-section-title">Provider &amp; Business</div>
              <div v-if="!reqProvider" class="req-warn"><AlertCircle :size="14"/>Provider account not found — do NOT approve until this is resolved.</div>
              <template v-else>
                <div class="req-grid">
                  <div class="req-item"><div class="req-item-label">Business</div><div class="req-item-value">{{ reqProvider.business_name || '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Contact person</div><div class="req-item-value">{{ reqProvider.full_name || '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Email</div><div class="req-item-value" style="word-break:break-all">{{ reqProvider.email || '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Phone</div><div class="req-item-value">{{ reqProvider.phone || '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Country</div><div class="req-item-value">{{ countryName(reqProvider.country_id) || '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Provider since</div><div class="req-item-value">{{ reqProvider.created_at ? fmtDay(reqProvider.created_at) : '—' }}</div></div>
                  <div class="req-item"><div class="req-item-label">Membership programs</div><div class="req-item-value">{{ reqPrograms.length }}</div></div>
                  <div class="req-item"><div class="req-item-label">Active members</div><div class="req-item-value">{{ reqMembers }}</div></div>
                </div>
                <div v-if="reqPrograms.length" style="margin-top:10px">
                  <div class="req-item-label" style="margin-bottom:6px">Their programs</div>
                  <div class="req-prog-chips">
                    <span v-for="p in reqPrograms" :key="p.id" class="req-prog-chip" :class="{ off: p.status !== 'active' }">
                      {{ p.name }} · {{ fmtMoney(p.monthly_price, p.currency) }}<template v-if="p.status !== 'active'"> · {{ p.status }}</template>
                    </span>
                  </div>
                </div>
              </template>
            </div>

            <!-- Business verification submitted with the wallet (KYC) -->
            <div class="req-section" v-if="reqWallet && (reqWallet.kyc_business_name || reqWallet.kyc_registration_no || reqWallet.kyc_address || reqWallet.kyc_contact || reqWallet.kyc_doc_url)">
              <div class="req-section-title">Business Verification (KYC on the wallet)</div>
              <div class="payout-summary" style="background: var(--bg);border-radius:8px;padding:12px">
                <div class="ps-row"><span class="ps-label">Legal / trading name</span><span class="ps-value">{{ reqWallet.kyc_business_name || '—' }}</span></div>
                <div class="ps-row"><span class="ps-label">Registration #</span><span class="ps-value ps-mono">{{ reqWallet.kyc_registration_no || '—' }}</span></div>
                <div class="ps-row"><span class="ps-label">Address</span><span class="ps-value">{{ reqWallet.kyc_address || '—' }}</span></div>
                <div class="ps-row"><span class="ps-label">Contact</span><span class="ps-value">{{ reqWallet.kyc_contact || '—' }}</span></div>
                <div v-if="reqWallet.kyc_doc_url" class="ps-row"><span class="ps-label">Document</span><span class="ps-value"><a :href="reqWallet.kyc_doc_url" target="_blank" rel="noopener" style="color:var(--primary);font-weight:700">View uploaded document ↗</a></span></div>
                <div class="ps-row"><span class="ps-label">Method status</span><span class="ps-value">{{ reqWallet.setup_status || '—' }}<template v-if="reqWallet.setup_completed_at"> · approved {{ fmtDay(reqWallet.setup_completed_at) }}</template></span></div>
              </div>
            </div>

            <!-- Payout details on file (wallet first — the setup flow saves them there) -->
            <div class="req-section">
              <div class="req-section-title">Bank Account on File</div>
              <div v-if="!reqBank.any" class="req-warn">
                <AlertCircle :size="14"/>No payout bank details on file — do not send a transfer until the provider adds them.
              </div>
              <div v-else class="payout-summary" style="background: var(--bg);border-radius:8px;padding:12px">
                <div class="ps-row"><span class="ps-label">Bank</span><span class="ps-value">{{ reqBank.bank_name||'—' }}</span></div>
                <div class="ps-row"><span class="ps-label">Country</span><span class="ps-value">{{ reqBank.bank_country||'—' }}</span></div>
                <div class="ps-row"><span class="ps-label">Account Holder</span><span class="ps-value">{{ reqBank.account_holder||'—' }}</span></div>
                <div v-if="reqBank.iban" class="ps-row"><span class="ps-label">IBAN</span><span class="ps-value ps-mono">{{ reqBank.iban }}</span></div>
                <div v-if="reqBank.account_number" class="ps-row"><span class="ps-label">Account #</span><span class="ps-value ps-mono">{{ reqBank.account_number }}</span></div>
                <div v-if="reqBank.routing_number" class="ps-row"><span class="ps-label">Routing #</span><span class="ps-value ps-mono">{{ reqBank.routing_number }}</span></div>
                <div v-if="reqBank.swift" class="ps-row"><span class="ps-label">SWIFT/BIC</span><span class="ps-value ps-mono">{{ reqBank.swift }}</span></div>
              </div>
            </div>

            <!-- Verification + reconciliation -->
            <div class="req-section">
              <div class="req-section-title">Verification</div>

              <div class="rv-verdict" :class="reqRecon.clean ? 'ok' : 'warn'">
                <component :is="reqRecon.clean ? ShieldCheck : AlertCircle" :size="20" />
                <div>
                  <b>{{ reqRecon.clean ? 'Looks good to pay' : 'Review before approving' }}</b>
                  <span>{{ reqRecon.clean ? 'Balance and earnings records all reconcile.' : 'One or more checks below need a look.' }}</span>
                </div>
              </div>

              <div class="rv-checks">
                <div class="rv-check" :class="reqRecon.sufficient ? 'ok' : 'bad'">
                  <component :is="reqRecon.sufficient ? Check : X" :size="14" />
                  <span>Net earned {{ fmtMoney(reqRecon.netEarned, reqWallet?.currency) }} {{ reqRecon.sufficient ? 'covers' : 'is short of' }} the {{ fmtMoney(reqRecon.amount, reqWallet?.currency) }} requested</span>
                </div>
                <div class="rv-check" :class="reqRecon.totalsMatch ? 'ok' : 'bad'">
                  <component :is="reqRecon.totalsMatch ? Check : X" :size="14" />
                  <span>Wallet credits {{ fmtMoney(reqRecon.totalCredited, reqWallet?.currency) }} {{ reqRecon.totalsMatch ? 'match' : 'do not match' }} recorded earnings {{ fmtMoney(reqRecon.totalRecorded, reqWallet?.currency) }}</span>
                </div>
                <div class="rv-check" :class="reqRecon.unmatched === 0 ? 'ok' : 'bad'">
                  <component :is="reqRecon.unmatched === 0 ? Check : X" :size="14" />
                  <span>{{ reqRecon.matched }} credit{{ reqRecon.matched===1?'':'s' }} matched to a payment record<template v-if="reqRecon.unmatched"> · {{ reqRecon.unmatched }} without a match</template></span>
                </div>
              </div>

              <div v-if="reqWallet" class="req-wallet-stats" style="margin-top:14px">
                <div class="rws-item"><div class="rws-label">Available</div><div class="rws-value">{{ fmtMoney(reqWallet.available_balance, reqWallet.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Being paid out</div><div class="rws-value">{{ fmtMoney(reqWallet.pending_balance, reqWallet.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Lifetime earned</div><div class="rws-value">{{ fmtMoney(reqWallet.lifetime_earnings, reqWallet.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Lifetime withdrawn</div><div class="rws-value">{{ fmtMoney(reqWallet.lifetime_withdrawn, reqWallet.currency) }}</div></div>
              </div>

              <div style="margin-top:14px">
                <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Earnings history ({{ reqRecon.rows.length }} credit{{ reqRecon.rows.length===1?'':'s' }})</div>
                <div v-if="!reqRecon.rows.length" class="muted" style="font-size:13px;padding:10px 0">No credit transactions found for this provider.</div>
                <div v-else class="req-tx-list">
                  <div class="req-tx-head">
                    <span>Date</span><span>Source</span><span style="text-align:right">Share</span><span style="text-align:right">Recorded</span><span>Match</span>
                  </div>
                  <div v-for="t in reqRecon.rows.slice(0, 30)" :key="t.id" class="req-tx-row">
                    <span>{{ fmtDay(t.created_at) }}</span>
                    <span>{{ t.source_label||'—' }}</span>
                    <span style="text-align:right;font-weight:600;color: var(--positive-ink)">{{ fmtMoney(t.share,t.currency) }}</span>
                    <span style="text-align:right">{{ t.recorded != null ? fmtMoney(t.recorded, t.currency) : '—' }}</span>
                    <span><span class="rv-tag" :class="t.manual ? 'na' : (t.ok ? 'ok' : 'bad')">{{ t.manual ? 'manual' : (t.ok ? 'matched' : 'no record') }}</span></span>
                  </div>
                </div>
              </div>
            </div>

            <!-- FULL wallet ledger — every payment in and out of this membership wallet -->
            <div class="req-section">
              <div class="req-section-title">Wallet Ledger — Money In &amp; Out</div>
              <div class="req-wallet-stats">
                <div class="rws-item"><div class="rws-label">Money in</div><div class="rws-value" style="color: var(--positive-ink)">{{ fmtMoney(reqFlow.totalIn, reqWallet?.currency || viewingRequest.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Money out</div><div class="rws-value" style="color: var(--danger-ink)">{{ fmtMoney(reqFlow.totalOut, reqWallet?.currency || viewingRequest.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Net</div><div class="rws-value">{{ fmtMoney(reqFlow.net, reqWallet?.currency || viewingRequest.currency) }}</div></div>
                <div class="rws-item"><div class="rws-label">Transactions</div><div class="rws-value">{{ reqFlow.count }}</div></div>
              </div>
              <div v-if="!reqLedger.length" class="muted" style="font-size:13px;padding:10px 0">No transactions on this wallet yet.</div>
              <div v-else class="req-tx-list" style="margin-top:12px">
                <div class="req-tx-head req-led-head">
                  <span>Date</span><span>Description</span><span>Status</span><span style="text-align:right">In</span><span style="text-align:right">Out</span>
                </div>
                <div v-for="t in reqLedger.slice(0, 40)" :key="'led-'+t.id" class="req-tx-row req-led-row">
                  <span>{{ fmtDay(t.created_at) }}</span>
                  <span>{{ t.source_label || t.description || t.type || '—' }}</span>
                  <span><span class="rv-tag" :class="t.status === 'completed' ? 'ok' : (t.status === 'on_hold' ? 'na' : 'bad')">{{ t.status === 'on_hold' ? 'clearing' : (t.status || '—') }}</span></span>
                  <span style="text-align:right;font-weight:600;color: var(--positive-ink)">{{ t.direction === 'credit' ? fmtMoney(t.provider_share ?? t.amount, t.currency) : '' }}</span>
                  <span style="text-align:right;font-weight:600;color: var(--danger-ink)">{{ t.direction === 'debit' ? fmtMoney(t.provider_share ?? t.amount, t.currency) : '' }}</span>
                </div>
                <div v-if="reqLedger.length > 40" class="muted" style="font-size:12px;padding:8px 0 0">Showing the latest 40 of {{ reqLedger.length }} transactions.</div>
              </div>
            </div>

            <!-- The provider's withdrawal track record -->
            <div class="req-section">
              <div class="req-section-title">Withdrawal History</div>
              <div v-if="!reqHistory.length" class="muted" style="font-size:13px;padding:6px 0">First withdrawal request from this provider.</div>
              <div v-else class="req-tx-list">
                <div class="req-tx-head req-wh-head">
                  <span>Requested</span><span style="text-align:right">Amount</span><span>Status</span><span>Settled</span>
                </div>
                <div v-for="h in reqHistory.slice(0, 15)" :key="'wh-'+h.id" class="req-tx-row req-wh-row">
                  <span>{{ fmtDay(h.requested_at) }}</span>
                  <span style="text-align:right;font-weight:600">{{ fmtMoney(h.amount, h.currency) }}</span>
                  <span><span class="badge" :class="badgeClass(h.status)">{{ h.status }}</span></span>
                  <span>{{ h.paid_at ? fmtDay(h.paid_at) : (h.rejected_at ? fmtDay(h.rejected_at) : (h.cancelled_at ? fmtDay(h.cancelled_at) : '—')) }}</span>
                </div>
              </div>
            </div>

            <!-- Actions: pending -> approve; approved -> mark paid -->
            <div v-if="viewingRequest.status === 'pending' || viewingRequest.status === 'approved'" class="req-actions">
              <div class="req-action-note">
                <ShieldCheck :size="14"/>
                <template v-if="viewingRequest.status === 'pending'">Approve once the checks above look right, then send the bank transfer and mark it Paid.</template>
                <template v-else>Approved {{ fmtDay(viewingRequest.approved_at) }}. {{ payoutEta(viewingRequest) }}. Mark Paid once the transfer is done.</template>
              </div>
              <div style="display:flex;gap:10px;margin-top:12px">
                <button class="btn btn-danger" style="flex:1" :disabled="rejectingReq" @click="rejectFromPanel(viewingRequest)">
                  <X :size="14"/>{{ rejectingReq ? 'Rejecting…' : 'Reject' }}
                </button>
                <button v-if="viewingRequest.status === 'pending'" class="btn btn-success" style="flex:2" :disabled="approvingReq" @click="approveWithdrawal(viewingRequest)">
                  <Check :size="14"/>{{ approvingReq ? 'Approving…' : 'Approve payout' }}
                </button>
                <button v-else class="btn btn-primary" style="flex:2" :disabled="markingPaid" @click="markWithdrawalPaid(viewingRequest)">
                  <Banknote :size="14"/>{{ markingPaid ? 'Saving…' : 'Mark as Paid' }}
                </button>
              </div>
            </div>

            <!-- Already processed info -->
            <div v-else class="req-section" style="background: var(--bg);border-radius:10px;padding:14px">
              <div class="req-section-title">Processing Info</div>
              <div class="req-grid">
                <div class="req-item"><div class="req-item-label">Status</div><div><span class="badge" :class="badgeClass(viewingRequest.status)">{{ viewingRequest.status }}</span></div></div>
                <div class="req-item"><div class="req-item-label">Processed on</div><div class="req-item-value">{{ fmtDate(viewingRequest.processed_at) }}</div></div>
                <div class="req-item"><div class="req-item-label">Processed by</div><div class="req-item-value">{{ viewingRequest.processed_by||'—' }}</div></div>
                <div v-if="viewingRequest.rejection_reason" class="req-item" style="grid-column:1/-1"><div class="req-item-label">Rejection Reason</div><div class="req-item-value">{{ viewingRequest.rejection_reason }}</div></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══ WITHDRAWAL REQUEST MODAL ═══════════════════════════════════ -->
    <div v-if="wdModal" class="modal-backdrop" @click.self="wdModal=false; wdError=''">
      <div class="modal wd-modal" v-dialog>
        <div class="modal-header">
          <div>
            <div class="modal-title">Request Withdrawal</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
              Submit a request — your Super Admin will process the bank transfer and mark it paid.
            </div>
          </div>
          <button class="btn-icon" @click="wdModal=false; wdError=''" aria-label="Close"><X :size="18"/></button>
        </div>

        <div class="modal-body">

          <!-- No payout details yet -->
          <div v-if="!hasPayoutDetails" class="wd-no-payout">
            <AlertCircle :size="20" class="wd-np-icon"/>
            <div>
              <div class="wd-np-title">No bank account saved</div>
              <div class="wd-np-body">
                You must save your payout details (bank account / IBAN) before requesting a withdrawal.
                Close this dialog and click <strong>Add details</strong> in the <strong>Payout bank details</strong> card.
              </div>
            </div>
          </div>

          <template v-else>
            <!-- Payout destination summary -->
            <div class="wd-destination">
              <div class="wd-dest-label">Sending to</div>
              <div class="wd-dest-card">
                <Building2 :size="15" class="wd-dest-icon"/>
                <div class="wd-dest-body">
                  <div class="wd-dest-bank">{{ currentWallet?.payout_bank_name }} <span class="wd-dest-country">{{ payoutCountryName }}</span></div>
                  <div v-for="row in savedPayoutSummary.slice(2)" :key="row.label" class="wd-dest-row">
                    <span class="wd-dest-key">{{ row.label }}</span>
                    <span class="wd-dest-val">{{ row.value }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Balance summary -->
            <div class="wd-balance-bar">
              <div class="wd-bal-item">
                <span class="wd-bal-label">Available to withdraw</span>
                <span class="wd-bal-value available">{{ fmtMoney(currentWallet.available_balance, currentWallet.currency) }}</span>
              </div>
              <div class="wd-bal-sep"></div>
              <div class="wd-bal-item">
                <span class="wd-bal-label">Still clearing</span>
                <span class="wd-bal-value hold">{{ fmtMoney(providerHoldTotal, currentWallet.currency) }}</span>
              </div>
            </div>

            <!-- Amount -->
            <div class="form-group" style="margin-bottom:14px">
              <label class="form-label">Amount <span class="req">*</span></label>
              <div class="wd-amount-wrap">
                <span class="wd-currency-badge">{{ currentWallet.currency }}</span>
                <input
                  type="number" v-model="wdForm.amount"
                  :max="currentWallet.available_balance" min="1" step="0.01"
                  class="wd-amount-input"
                  placeholder="0.00"
                />
                <button
                  class="wd-max-btn"
                  @click="wdForm.amount = Number(currentWallet.available_balance).toFixed(2)"
                  type="button"
                >Max</button>
              </div>
              <div class="form-hint">Maximum: {{ fmtMoney(currentWallet.available_balance, currentWallet.currency) }}</div>
            </div>

            <!-- Notes -->
            <div class="form-group">
              <label class="form-label">Internal Note <span class="form-opt">optional</span></label>
              <input v-model="wdForm.notes" placeholder="e.g. May 2026 earnings payout" />
              <div class="form-hint">Visible only to you and the Super Admin.</div>
            </div>
          </template>

          <!-- Error -->
          <div v-if="wdError" class="form-error" style="margin-top:12px">
            <AlertCircle :size="14"/>{{ wdError }}
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="wdModal=false; wdError=''">Cancel</button>
          <button
            v-if="hasPayoutDetails"
            class="btn btn-primary"
            :disabled="!wdForm.amount || Number(wdForm.amount) <= 0"
            @click="submitWithdrawal"
          >
            <Banknote :size="14"/>Submit Withdrawal Request
          </button>
        </div>
      </div>
    </div>

    <!-- HOLD POLICY MODAL -->
    <div v-if="policyModal" class="modal-backdrop" @click.self="policyModal=false">
      <div class="modal" v-dialog style="max-width:460px">
        <div class="modal-header">
          <div class="modal-title">Hold period — {{ policyTarget && providerName(policyTarget.owner_user_id) }}</div>
          <button class="btn-icon" @click="policyModal=false" aria-label="Close"><X :size="18"/></button>
        </div>
        <div class="modal-body">
          <div class="muted" style="font-size:13px;margin-bottom:10px">Applies to future subscription credits only. Existing on-hold transactions keep their original release date.</div>
          <div class="form-group"><label class="form-label">Hold period (days)</label><input type="number" v-model="policyDays" min="0" max="90"/></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="policyModal=false">Cancel</button>
          <button class="btn btn-primary" @click="savePolicy"><Check :size="14"/>Save</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Shared ──────────────────────────────────────────────────────────────────── */
.muted { color: var(--text-muted); }
.hint-dot { display:inline-flex; margin-left:4px; vertical-align:middle; color:var(--text-muted); cursor:help; }
.field-hint, .form-hint { font-size:12px; color:var(--text-muted); margin-top:4px; }
.btn-icon { padding:6px; border-radius:6px; color:var(--text-muted); background:transparent; border:0; cursor:pointer; }
.btn-icon:hover { background:var(--bg); color:var(--text); }
.kpi-sub { font-size:12px; color:var(--text-muted); margin-top:4px; }
.kpi-delta { font-size:12px; margin-top:4px; }
.alert { display:flex; align-items:center; gap:8px; padding:10px 12px; background:var(--warning-bg, #fef3c7); border:1px solid var(--warning-border, #fde68a); color:var(--warning-ink, #92400e); border-radius:8px; margin-bottom:14px; font-size:13px; }
.req { color:'#ef4444'; } .form-opt { font-size:11px; font-weight:400; color:var(--text-muted); margin-left:4px; }
.form-error { display:flex; align-items:center; gap:7px; padding:10px 13px; background:var(--danger-bg, #fef2f2); border:1px solid var(--danger-border, #fecaca); border-radius:8px; font-size:13px; color:var(--danger-ink, #dc2626); margin-top:10px; }
.tab-badge { background:#ef4444; color:#fff; border-radius:99px; font-size:11px; font-weight:700; padding:1px 6px; margin-left:4px; }

/* ── Wallet setup (choose a payout method before creating programs) ───────────── */
/* Country wallet switcher */
.wc-switch { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
.wc-label { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); }
.wc-chips { display:flex; flex-wrap:wrap; gap:8px; }
.wc-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 13px; border:1.5px solid var(--border); background:var(--surface, #ffffff); border-radius:999px; font-size:13.5px; font-weight:700; color:var(--text); cursor:pointer; }
.wc-chip:hover { border-color:var(--brand-accent, #14b8a6); }
.wc-chip.on { border-color:var(--brand, #0f766e); background:var(--primary-soft, #f0fdfa); color:var(--primary-ink, #0f766e); }
.wc-state { font-size:11px; font-weight:700; padding:1px 7px; border-radius:999px; }
.wc-state.ready { background:var(--positive-bg, #dcfce7); color:var(--positive-ink, #15803d); }
.wc-state.pending { background:var(--warning-bg, #fef3c7); color:var(--warning-ink, #b45309); }
.wc-state.none { background:var(--border, #e2e8f0); color:var(--text-secondary, #475569); }
.ws-country-note { margin-left:auto; font-size:11.5px; color:var(--text-muted); }

/* Wallets landing (select a wallet / add a new one) */
.wallets-landing { max-width:980px; }
.wl-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
.wl-title { font-size:18px; font-weight:800; color:var(--text); }
.wl-sub { font-size:13px; color:var(--text-muted); margin-top:3px; max-width:520px; }
/* Wallet-purpose switcher (Subscription vs Bookings) */
/* Wallet purpose sub-nav (page tabs: Subscription / Bookings) */
.wnav { display:flex; gap:4px; border-bottom:1.5px solid var(--border); margin-bottom:18px; }
.wnav-tab { display:inline-flex; align-items:center; gap:7px; padding:11px 16px; font-size:14px; font-weight:600; color:var(--text-muted); background:none; border:none; border-bottom:2.5px solid transparent; margin-bottom:-1.5px; cursor:pointer; transition:color .12s, border-color .12s; white-space:nowrap; text-decoration:none; }
.wnav-tab:hover { color:var(--text); }
.wnav-tab.active { color:var(--primary-ink, #0f766e); border-bottom-color:var(--brand, #0f766e); }
.wnav-tab svg { flex-shrink:0; }
.wp-note { display:flex; align-items:flex-start; gap:8px; font-size:12.5px; line-height:1.5; color:var(--warning-ink, #9a3412); background:var(--warning-bg, #fff7ed); border:1px solid var(--warning-border, #fed7aa); border-radius:11px; padding:10px 12px; margin-bottom:16px; }
.wp-note svg { flex-shrink:0; margin-top:1px; }
@media (max-width:560px){ .wnav-tab { padding:10px 12px; font-size:13px; } }
.wl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:14px; }
.wl-card { display:flex; flex-direction:column; gap:10px; text-align:left; padding:18px; border:1px solid var(--border); border-radius:16px; background:var(--surface, #ffffff); cursor:pointer; transition:border-color .12s, box-shadow .12s, transform .12s; }
.wl-card:hover { border-color:var(--brand-accent, #14b8a6); box-shadow:0 8px 22px rgba(15,23,42,.07); transform:translateY(-2px); }
.wl-card-top { display:flex; align-items:center; gap:8px; }
.wl-flag { width:32px; height:32px; border-radius:9px; background:var(--primary-soft, #f0fdfa); color:var(--primary-ink, #0f766e); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.wl-country { font-size:14.5px; font-weight:700; color:var(--text); flex:1; min-width:0; }
.wl-badge { font-size:11px; font-weight:700; padding:2px 9px; border-radius:999px; }
.wl-badge.ready { background:var(--positive-bg, #dcfce7); color:var(--positive-ink, #15803d); }
.wl-badge.pending { background:var(--info-bg, #dbeafe); color:var(--info-ink, #1d4ed8); }
.wl-badge.setup { background:var(--warning-bg, #fef3c7); color:var(--warning-ink, #b45309); }
.wl-card.legacy { background:var(--surface-hover, #f8fafc); opacity:.92; }

/* Wallet detail header (Change method / Delete) */
.wd-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.wd-head-title { display:flex; align-items:center; gap:8px; font-size:16px; font-weight:800; color:var(--text); }
.wd-head-actions { display:flex; gap:8px; flex-wrap:wrap; }
.wd-legacy-tag { font-size:11px; font-weight:700; color:var(--warning-ink, #b45309); background:var(--warning-bg, #fef3c7); padding:2px 9px; border-radius:999px; }
.wd-legacy-note { display:flex; align-items:center; gap:7px; font-size:12.5px; color:var(--warning-ink, #92400e); background:var(--warning-bg, #fffbeb); border:1px solid var(--warning-border, #fde68a); border-radius:10px; padding:9px 12px; margin-bottom:16px; }
.btn-danger-soft { background:var(--danger-bg, #fef2f2); color:var(--danger-ink, #dc2626); border:1px solid var(--danger-border, #fecaca); }
.btn-danger-soft:hover:not(:disabled) { background:var(--danger-bg, #fee2e2); }
.btn-danger-soft:disabled { opacity:.5; cursor:not-allowed; }
.wl-card-bal { font-size:24px; font-weight:800; color:var(--text); }
.wl-card-foot { display:flex; align-items:center; justify-content:space-between; font-size:12.5px; color:var(--text-muted); }
.wl-card-foot svg { color:var(--text-light, #cbd5e1); }
.wl-card:hover .wl-card-foot svg { color:var(--primary-ink, #0f766e); }
.wl-add { align-items:center; justify-content:center; text-align:center; border-style:dashed; background:var(--surface-hover, #f8fafc); gap:6px; }
.wl-add-ic { width:44px; height:44px; border-radius:12px; background:var(--surface, #ffffff); border:1px solid var(--border); color:var(--primary-ink, #0f766e); display:flex; align-items:center; justify-content:center; }
.wl-add-label { font-size:14px; font-weight:700; color:var(--text); }
.wl-add-sub { font-size:12px; color:var(--text-muted); }
.wl-badge.muted { background:var(--bg, #f1f5f9); color:var(--text-muted, #64748b); }
.wl-cta-text { display:inline-flex; align-items:center; gap:4px; color:var(--primary-ink, #0f766e); font-weight:700; }
.wl-subhead { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:var(--text-muted); margin:24px 0 12px; }

/* Account payout-method panel (shared across all currency wallets) */
.pm-panel { padding:18px; margin-bottom:8px; }
.pm-row { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.pm-ic { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pm-ic.auto { background:var(--positive-bg, #ecfdf5); color:var(--positive-ink, #059669); }
.pm-ic.manual { background:var(--primary-soft, #f0fdfa); color:var(--primary-ink, #0f766e); }
.pm-ic.pending { background:var(--info-bg, #dbeafe); color:var(--info-ink, #1d4ed8); }
.pm-ic.rejected { background:var(--danger-bg, #fee2e2); color:var(--danger-ink, #dc2626); }
.pm-ic.none { background:var(--warning-bg, #fef3c7); color:var(--warning-ink, #b45309); }

/* KYC business details (manual payout review) */
.ws-kyc-note { display:flex; align-items:flex-start; gap:8px; font-size:12.5px; color:var(--info-ink, #1e40af); background:var(--info-bg, #eff6ff); border:1px solid var(--info-border, #bfdbfe); border-radius:10px; padding:10px 12px; margin-bottom:14px; }
.kyc-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.kyc-grid .kyc-wide { grid-column:1 / -1; }
.kyc-grid input[type="text"] { width:100%; }
.kyc-doc { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.kyc-doc-link { display:inline-flex; align-items:center; gap:4px; font-size:12.5px; font-weight:600; color:var(--primary-ink, #0f766e); }
.field-err { color:var(--danger-ink, #dc2626); font-size:12px; margin-top:4px; }
@media (max-width:560px) { .kyc-grid { grid-template-columns:1fr; } }
.pm-info { flex:1; min-width:200px; }
.pm-title { font-size:14.5px; font-weight:800; color:var(--text); }
.pm-sub { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
.pm-viewonly { font-size:11.5px; font-weight:700; color:var(--text-muted); background:var(--bg); border:1px solid var(--border); border-radius:999px; padding:2px 9px; }
.pm-editor { padding-top:2px; }
.pm-mini { display:flex; align-items:center; gap:10px; padding:12px 16px; margin-bottom:20px; flex-wrap:wrap; }
.pm-mini-ic { color:var(--primary-ink, #0f766e); flex-shrink:0; }
.pm-mini-text { font-size:12.5px; color:var(--text-muted); flex:1; min-width:200px; }
.pm-mini-btn { margin-left:auto; }

/* Admin: payout-method approval queue */
.wl-back { display:inline-flex; align-items:center; gap:6px; background:none; border:none; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer; padding:2px 4px; margin-bottom:14px; }
.wl-back:hover { color:var(--primary-ink, #0f766e); }

.wallet-setup { max-width:760px; margin:0 auto; }
.ws-head { text-align:center; margin-bottom:20px; }
.ws-icon {
  width:72px;height:72px;border-radius:18px;margin:0 auto 16px;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--primary-soft, #ccfbf1),#99f6e4);color:var(--primary-ink, #0f766e);
}
.ws-icon.pending { background:linear-gradient(135deg,var(--warning-bg, #fef3c7),#fde68a);color:var(--warning-ink, #b45309); }
.ws-title { font-size:21px;font-weight:700;margin-bottom:8px; }
.ws-body { font-size:14px;color:var(--text-muted);max-width:520px;margin:0 auto 18px;line-height:1.6; }
.ws-country {
  display:flex;align-items:center;gap:8px;flex-wrap:wrap;
  padding:11px 16px;margin-bottom:18px;
  background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:13px;
}
.ws-country .ws-country-label { color:var(--text-muted); }
.ws-country b { color:var(--text); }
.ws-locked {
  margin-left:auto;display:inline-flex;align-items:center;gap:4px;
  font-size:11px;font-weight:600;color:var(--text-muted);
  background:var(--surface, #ffffff);border:1px solid var(--border);border-radius:99px;padding:2px 9px;
}
/* Bank-account country question on the setup step */
.ws-mini { font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light,#94a3b8);margin:12px 0 10px; }
.ws-bankq { padding:14px 16px;margin-bottom:18px;background:var(--bg);border:1px solid var(--border);border-radius:12px; }
/* First-time landing: the question IS the page — roomy and centered. */
.ws-bankq.hero { padding:36px 22px; text-align:center; }
.ws-bankq.hero .ws-bankq-label { justify-content:center; font-size:17px; }
.ws-bankq.hero .ws-bankq-select { max-width:420px; margin:0 auto; }
.ws-bankq-label { display:flex;align-items:center;gap:7px;font-size:15px;color:var(--text);margin-bottom:10px;flex-wrap:wrap; }
.ws-bankq-label strong { font-weight:800; }
.ws-bankq-hl { font-style:normal; color:var(--primary-ink); }
.ws-bankq-label svg { color:var(--primary-ink); flex-shrink:0; }
.ws-bankq-hint { font-size:12.5px;color:var(--text-muted);line-height:1.5;margin:10px 0 0; }
.ws-bankq-select { width:100%;box-sizing:border-box;border:1.5px solid var(--border);border-radius:10px;padding:11px 12px;font-size:14.5px;color:var(--text);background:var(--surface, #ffffff); }
.ws-bankq-select:focus { outline:none;border-color:var(--primary); }

.ws-methods { display:flex;flex-direction:column;gap:20px; }
.ws-group-head { display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:13px;font-weight:800;color:var(--text);margin-bottom:9px; }
.ws-group-head svg { color:var(--primary-ink); }
.ws-group-head span { flex-basis:100%;font-size:12px;font-weight:500;color:var(--text-muted);line-height:1.45;margin-left:21px; }
.ws-method {
  border:1px solid var(--border);border-radius:14px;padding:16px 18px;background:var(--surface, #ffffff);
  display:flex;flex-direction:column;gap:10px;
}
.ws-method.recommended { border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.08); }
/* Disabled method (e.g. Stripe where unsupported): visible but clearly inactive. */
.ws-method.disabled { background:var(--bg);border-style:dashed;opacity:.8; }
.wsm-top { display:flex;align-items:center;gap:12px; }
.wsm-icon {
  width:42px;height:42px;border-radius:11px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  background:var(--bg, #f1f5f9);color:var(--text-muted);
}
.wsm-icon.recommended { background:var(--primary-soft, #ccfbf1);color:var(--primary-ink, #0f766e); }
.wsm-icon.good { background:var(--info-bg, #dbeafe);color:var(--info-ink, #1d4ed8); }
.wsm-icon.last { background:var(--warning-bg, #fef3c7);color:var(--warning-ink, #b45309); }
.wsm-icon.disabled { background:var(--bg, #f1f5f9);color:var(--text-light, #94a3b8); }
.wsm-titles { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
.wsm-name { font-size:15px;font-weight:700; }
.wsm-badge { font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;background:var(--bg, #f1f5f9);color:var(--text-muted); }
.wsm-badge.recommended { background:var(--primary-soft, #ccfbf1);color:var(--primary-ink, #0f766e); }
.wsm-badge.good { background:var(--info-bg, #dbeafe);color:var(--info-ink, #1d4ed8); }
.wsm-badge.last { background:var(--warning-bg, #fef3c7);color:var(--warning-ink, #b45309); }
.wsm-badge.disabled { background:var(--border, #e2e8f0);color:var(--text-muted, #64748b); }
.wsm-badge.muted { background:var(--bg, #f1f5f9);color:var(--text-light, #94a3b8); }
.wsm-badge.na { background:var(--danger-bg, #fee2e2);color:var(--danger-ink, #b91c1c); }
.wsm-badge.beta { background:var(--warning-bg, #fff7ed);color:var(--warning-ink, #ea580c);border:1px solid var(--warning-border, #fed7aa); }
.wsm-switch { display:flex;align-items:flex-start;gap:7px;padding:9px 11px;background:var(--surface, #ffffff);border:1px dashed var(--border);border-radius:9px;font-size:12.5px;color:var(--text-muted, #64748b);line-height:1.5; }
.wsm-switch svg { flex-shrink:0;margin-top:1px;color:var(--text-muted); }
/* Stripe-unavailable: the card steps back so the deposit hero above reads first */
.ws-method.ws-off { opacity:.75; }
/* "Sorry — Stripe isn't available here" lead-in (bookings wallet) */
.ws-sorry { display:flex;align-items:flex-start;gap:11px;background:var(--warning-bg, #fffbeb);border:1px solid var(--warning-border, #fde68a);border-radius:12px;padding:12px 14px;margin-bottom:14px; }
.ws-sorry-ic { width:34px;height:34px;border-radius:10px;background:var(--warning-bg, #fef3c7);color:var(--warning-ink, #b45309);display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.ws-sorry-t { display:flex;flex-direction:column;gap:2px;font-size:13px;line-height:1.5; }
.ws-sorry-t b { color:var(--warning-ink, #92400e);font-size:13.5px; }
.ws-sorry-t span { color:var(--warning-ink, #a16207); }
/* One-line Stripe footnote under the deposit hero */
.ws-stripe-mini { display:flex;align-items:center;gap:8px;margin-top:12px;padding:10px 12px;border:1px dashed var(--border, #e2e8f0);border-radius:10px;font-size:12.5px;color:var(--text-muted, #64748b);line-height:1.5; }
.ws-stripe-mini svg { flex-shrink:0; }
.ws-stripe-mini b { color:var(--text-secondary, #334155); }
/* "Deposit + rest on arrival" — icon-led 3-step story, one glance to get it */
.ws-dep-steps { display:flex;flex-direction:column;gap:8px;margin:12px 0 2px; }
.ws-dep-step { display:flex;align-items:center;gap:10px;font-size:13px;color:var(--text-secondary, #334155);font-weight:600;line-height:1.4; }
.ws-dep-step b { color:var(--text, #0f172a); }
.ws-dep-ic { width:30px;height:30px;border-radius:9px;background:var(--positive-bg, #ecfdf5);color:var(--positive-ink, #059669);display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.wsm-blurb { font-size:13px;color:var(--text-muted);line-height:1.55; }
.wsm-unavailable {
  display:flex;align-items:flex-start;gap:7px;
  padding:9px 11px;background:var(--bg, #f1f5f9);border:1px solid var(--border);border-radius:9px;
  font-size:12.5px;color:var(--text-muted, #64748b);line-height:1.5;
}
.wsm-unavailable svg { flex-shrink:0;margin-top:1px; }
.ws-method-btn { align-self:flex-start; }
.ws-bookboll { padding:18px 20px; }
.ws-bb-head { display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px;margin-bottom:14px; }
.ws-bb-actions { display:flex;justify-content:flex-end;gap:8px;padding-top:6px;border-top:1px solid var(--border); }

/* ── No-programs hint banner (legacy) ────────────────────────────────────────── */
.no-programs-hint {
  display:flex; align-items:center; gap:12px; flex-wrap:wrap;
  padding:14px 18px; margin-bottom:18px;
  background:var(--info-bg, #eff6ff); border:1px solid var(--info-border, #bfdbfe); border-radius:10px;
  font-size:13.5px; color:var(--info-ink, #1e40af);
}
.nph-icon { color:var(--info-ink, #3b82f6); flex-shrink:0; }
.nph-text { flex:1; min-width:0; line-height:1.5; }
.nph-text strong { color:var(--info-ink, #1e3a8a); }

/* ── Payout details card ─────────────────────────────────────────────────────── */
.payout-card { padding:18px 20px; }
.payout-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.payout-icon { color:var(--primary-ink); }

/* Missing state */
.payout-missing {
  display:flex; align-items:flex-start; gap:10px;
  padding:14px; background:var(--warning-bg, #fef3c7); border:1px solid var(--warning-border, #fde68a); border-radius:10px;
  font-size:13px; color:var(--warning-ink, #92400e);
}
.payout-missing svg { flex-shrink:0; margin-top:1px; }

/* View state */
.payout-view { display:flex; flex-direction:column; gap:12px; }
.pv-bank-row { display:flex; align-items:center; gap:8px; }
.pv-bank-icon { color:var(--primary-ink); flex-shrink:0; }
.pv-bank-name { font-size:15px; font-weight:700; color:var(--text); }
.pv-country-pill { font-size:11px; font-weight:600; padding:2px 8px; border-radius:99px; background:var(--info-bg, #eff6ff); color:var(--info-ink, #1d4ed8); margin-left:4px; }
.payout-summary { display:flex; flex-direction:column; gap:7px; padding:12px 14px; background:var(--bg); border-radius:9px; border:1px solid var(--border); }
.ps-row { display:flex; align-items:baseline; gap:16px; font-size:13px; }
.ps-label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; min-width:120px; flex-shrink:0; }
.ps-value { color:var(--text); font-weight:500; }
.ps-mono { font-family:'SF Mono',Monaco,monospace; letter-spacing:.04em; word-break:break-all; }

/* Edit form */
.payout-edit-form { display:flex; flex-direction:column; gap:0; }
.payout-select {
  width:100%; padding:8px 11px; border:1px solid var(--border); border-radius:8px;
  font-size:13.5px; color:var(--text); background:var(--surface, #ffffff); appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 10px center;
  padding-right:30px; cursor:pointer;
  transition:border-color .15s, box-shadow .15s;
}
.payout-select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.mono-input { font-family:'SF Mono',Monaco,monospace; font-size:13px; letter-spacing:.04em; }
.field-error input, .field-error select { border-color:#ef4444 !important; }
.field-err-msg { font-size:12px; color:var(--danger-ink, #dc2626); margin-top:4px; display:flex; align-items:center; gap:4px; }
.field-err-msg::before { content:'⚠'; font-size:11px; }

/* ── Hold progress ───────────────────────────────────────────────────────────── */
.hold-list { display:flex; flex-direction:column; gap:10px; }
.hold-row { display:grid; grid-template-columns:1.3fr 2fr auto; gap:16px; align-items:center; padding:12px; border:1px solid var(--border); border-radius:10px; background:var(--surface, #ffffff); }
.hold-title { font-weight:600; font-size:14px; }
.hold-sub { font-size:12px; margin-top:2px; }
.hold-bar-wrap { display:flex; flex-direction:column; gap:4px; }
.hold-bar { height:6px; background:var(--bg, #f1f5f9); border-radius:999px; overflow:hidden; }
.hold-bar-fill { height:100%; background:linear-gradient(90deg,#f59e0b,#10b981); transition:width .3s; }
.hold-bar-label { font-size:12px; color:var(--text-muted); }
.hold-amount { font-weight:700; color:var(--primary-ink, #0f766e); white-space:nowrap; }

/* ── Filter row ──────────────────────────────────────────────────────────────── */
.filter-row { display:flex; gap:6px; margin-bottom:12px; flex-wrap:wrap; }
.chip { padding:6px 12px; border:1px solid var(--border); border-radius:999px; background:var(--surface, #ffffff); font-size:12px; color:var(--text-muted); cursor:pointer; transition:all .15s; }
.chip.active { background:var(--primary); color:#fff; border-color:var(--primary); }

/* ── Release schedule ────────────────────────────────────────────────────────── */
.schedule-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
.schedule-cell { padding:12px; border:1px solid var(--border); border-radius:8px; background:var(--surface-hover, #f8fafc); }
.schedule-date { font-size:12px; color:var(--text-muted); text-transform:uppercase; font-weight:600; }
.schedule-amt { font-weight:700; font-size:16px; margin-top:4px; color:var(--primary-ink, #0f766e); }
.schedule-count { font-size:12px; color:var(--text-muted); margin-top:2px; }

/* ── Hold policy table ───────────────────────────────────────────────────────── */
.mini-table { width:100%; font-size:13px; border-collapse:collapse; }
.mini-table th, .mini-table td { padding:8px 10px; text-align:left; border-bottom:1px solid var(--border); }
.mini-table th { font-weight:600; font-size:12px; color:var(--text-muted); text-transform:uppercase; }
.mini-table tr.row-paused td { background:var(--warning-bg, #fffbeb); }

/* ── Withdrawal request cards (admin) ───────────────────────────────────────── */
.wr-list { display:flex; flex-direction:column; gap:8px; }
.wr-card {
  display:grid; grid-template-columns:1fr auto auto auto;
  align-items:center; gap:16px;
  background:var(--surface, #ffffff); border:1.5px solid var(--border); border-radius:12px;
  padding:14px 18px; cursor:pointer; transition:all .15s;
}
.wr-card:hover { border-color:var(--primary); box-shadow:0 2px 10px rgba(0,0,0,.08); }
.wr-provider { font-weight:600; font-size:14px; }
.wr-meta { font-size:12px; color:var(--text-muted); margin-top:2px; }
.wr-amount { font-size:18px; font-weight:700; color:var(--text); white-space:nowrap; }
.wr-action { display:flex; align-items:center; gap:4px; font-size:13px; font-weight:500; color:var(--primary-ink); white-space:nowrap; }

/* ── Request detail panel ────────────────────────────────────────────────────── */
.req-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1000; display:flex; justify-content:flex-end; backdrop-filter:blur(2px); }
.req-panel {
  width:100%; max-width:560px; height:100%; background:var(--surface, #ffffff);
  display:flex; flex-direction:column;
  box-shadow:-8px 0 40px rgba(0,0,0,.18);
  animation:slideInRight .22s cubic-bezier(.22,1,.36,1); overflow:hidden;
}
@keyframes slideInRight { from { transform:translateX(40px); opacity:0 } to { transform:translateX(0); opacity:1 } }
.req-header { display:flex; align-items:center; justify-content:space-between; padding:18px 20px 14px; border-bottom:1px solid var(--border, #e2e8f0); flex-shrink:0; }
.req-title { font-size:17px; font-weight:700; color:var(--text, #0f172a); }
.req-ref { font-size:12px; color:var(--text-muted, #64748b); font-family:'SF Mono',Monaco,monospace; margin-top:3px; }
.req-close { width:32px; height:32px; border-radius:8px; border:none; background:var(--bg, #f1f5f9); display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--text-muted, #64748b); }
.req-close:hover { background:var(--border, #e2e8f0); color:var(--text, #0f172a); }
.req-loading { flex:1; display:flex; align-items:center; justify-content:center; gap:10px; color:var(--text-muted, #64748b); font-size:14px; }
.req-body { flex:1; overflow-y:auto; padding:0 0 24px; }
.req-section { padding:18px 20px; border-bottom:1px solid var(--border, #f1f5f9); }
.req-section-title { font-size:11px; font-weight:700; color:var(--text-light, #94a3b8); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.req-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.req-item-label { font-size:11px; font-weight:600; color:var(--text-light, #94a3b8); text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px; }
.req-item-value { font-size:13.5px; font-weight:500; color:var(--text, #0f172a); }
.req-amount { font-size:20px; font-weight:800; color:var(--text, #0f172a); }
.req-warn { display:flex; align-items:center; gap:8px; padding:10px 14px; background:var(--warning-bg, #fef3c7); border:1px solid var(--warning-border, #fde68a); border-radius:8px; font-size:13px; color:var(--warning-ink, #92400e); }
.req-wallet-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
.rws-item { background:var(--surface-hover, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:12px; }
.rws-label { font-size:11px; font-weight:600; color:var(--text-light, #94a3b8); text-transform:uppercase; margin-bottom:4px; }
.rws-value { font-size:15px; font-weight:700; color:var(--text, #0f172a); }
.req-tx-list { border:1px solid var(--border, #e2e8f0); border-radius:8px; overflow:hidden; font-size:12px; }
.req-tx-head, .req-tx-row { display:grid; grid-template-columns:auto 1fr auto auto auto; gap:8px; padding:8px 12px; align-items:center; }
.req-tx-head { background:var(--surface-hover, #f8fafc); font-size:11px; font-weight:700; color:var(--text-light, #94a3b8); text-transform:uppercase; border-bottom:1px solid var(--border, #e2e8f0); }
.req-tx-row { border-bottom:1px solid var(--border, #f1f5f9); }
.req-tx-row:last-child { border-bottom:none; }
/* Withdrawal-history table: 4 columns (requested · amount · status · settled) */
.req-wh-head, .req-wh-row { grid-template-columns:auto 1fr auto auto; }
/* The provider's membership programs, as quick-glance chips */
.req-prog-chips { display:flex; flex-wrap:wrap; gap:6px; }
.req-prog-chip { font-size:12px; font-weight:700; color:var(--text-secondary, #334155); background:var(--bg, #f1f5f9); border:1px solid var(--border, #e2e8f0); border-radius:999px; padding:4px 11px; }
.req-prog-chip.off { color:var(--text-light, #94a3b8); background:var(--surface-hover, #f8fafc); }
.req-actions { padding:16px 20px; border-top:1px solid var(--border, #e2e8f0); background:var(--surface, #ffffff); flex-shrink:0; }
.req-action-note { display:flex; align-items:center; gap:7px; padding:10px 13px; background:var(--info-bg, #f0f9ff); border:1px solid var(--info-border, #bae6fd); border-radius:8px; font-size:12.5px; color:var(--info-ink, #0369a1); }

.empty { text-align:center; color:var(--text-muted); padding:20px; }
.spin { animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── Withdrawal modal ───────────────────────────────────────────────────────── */
.wd-modal { max-width: 500px; }

/* No payout state */
.wd-no-payout {
  display:flex; align-items:flex-start; gap:14px;
  padding:18px; background:var(--warning-bg, #fef3c7); border:1px solid var(--warning-border, #fde68a); border-radius:12px;
}
.wd-np-icon { color:var(--warning-ink, #d97706); flex-shrink:0; margin-top:2px; }
.wd-np-title { font-size:14px; font-weight:700; color:var(--warning-ink, #92400e); margin-bottom:5px; }
.wd-np-body  { font-size:13px; color:var(--warning-ink, #92400e); line-height:1.55; }

/* Destination card */
.wd-destination { margin-bottom:16px; }
.wd-dest-label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.wd-dest-card {
  display:flex; align-items:flex-start; gap:10px;
  padding:12px 14px; background:var(--bg); border:1px solid var(--border); border-radius:10px;
}
.wd-dest-icon { color:var(--primary-ink); flex-shrink:0; margin-top:2px; }
.wd-dest-body { flex:1; min-width:0; }
.wd-dest-bank { font-size:14px; font-weight:700; color:var(--text); margin-bottom:6px; }
.wd-dest-country { font-size:11px; font-weight:500; color:var(--info-ink, #1d4ed8); background:var(--info-bg, #eff6ff); padding:1px 7px; border-radius:99px; margin-left:6px; }
.wd-dest-row { display:flex; gap:10px; font-size:12.5px; margin-top:3px; }
.wd-dest-key { color:var(--text-muted); min-width:90px; flex-shrink:0; }
.wd-dest-val { color:var(--text); font-family:'SF Mono',Monaco,monospace; font-weight:500; word-break:break-all; }

/* Balance bar */
.wd-balance-bar {
  display:flex; gap:0; margin-bottom:18px;
  background:var(--bg); border:1px solid var(--border); border-radius:10px; overflow:hidden;
}
.wd-bal-item { flex:1; padding:12px 14px; display:flex; flex-direction:column; gap:4px; }
.wd-bal-sep  { width:1px; background:var(--border); flex-shrink:0; }
.wd-bal-label { font-size:11px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.wd-bal-value { font-size:16px; font-weight:700; }
.wd-bal-value.available { color:var(--positive-ink, #059669); }
.wd-bal-value.hold      { color:var(--warning-ink, #d97706); }

/* Amount input */
.wd-amount-wrap { display:flex; align-items:center; border:1px solid var(--border); border-radius:8px; overflow:hidden; transition:border-color .15s, box-shadow .15s; }
.wd-amount-wrap:focus-within { border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.wd-currency-badge { padding:0 12px; background:var(--bg); font-size:12px; font-weight:700; color:var(--text-muted); border-right:1px solid var(--border); height:40px; display:flex; align-items:center; white-space:nowrap; }
.wd-amount-input { flex:1; padding:8px 12px; border:none; font-size:16px; font-weight:600; color:var(--text); outline:none; background:var(--surface, #ffffff); }
.wd-amount-input::placeholder { color:#d1d5db; font-weight:400; font-size:14px; }
.wd-max-btn { padding:0 14px; height:40px; background:var(--info-bg, #eff6ff); color:var(--info-ink, #2563eb); font-size:12px; font-weight:700; border:none; border-left:1px solid var(--border); cursor:pointer; transition:background .1s; }
.wd-max-btn:hover { background:var(--info-bg, #dbeafe); }

/* ── Payout notices (provider) ─────────────────────────────────────────────── */
.pay-notices { display:flex; flex-direction:column; gap:10px; margin-bottom:18px; }
.pay-notice { display:flex; align-items:center; gap:11px; padding:12px 14px; border-radius:12px; font-size:13.5px; border:1px solid; }
.pay-notice.paid { background:var(--positive-bg, #f0fdf4); border-color:var(--positive-border, #bbf7d0); color:var(--positive-ink, #166534); }
.pay-notice.approved { background:var(--info-bg, #eff6ff); border-color:var(--info-border, #bfdbfe); color:var(--info-ink, #1e40af); }
.pay-notice.rejected { background:var(--danger-bg, #fef2f2); border-color:var(--danger-border, #fecaca); color:var(--danger-ink, #991b1b); }
.pn-ico { flex-shrink:0; }
.pn-text { flex:1; line-height:1.45; }
.pn-x { flex-shrink:0; width:26px; height:26px; border:none; background:transparent; color:inherit; opacity:.65; cursor:pointer; border-radius:6px; display:flex; align-items:center; justify-content:center; }
.pn-x:hover { opacity:1; background:rgba(0,0,0,.06); }

/* ── Balance hero (provider) ───────────────────────────────────────────────── */
.pay-hero { display:grid; grid-template-columns:1.1fr 2fr; gap:16px; margin-bottom:20px; }
.ph-main { background:linear-gradient(135deg,#0f766e,#0d9488); color:#fff; border-radius:16px; padding:22px 24px; display:flex; flex-direction:column; }
.ph-label { font-size:13px; font-weight:600; opacity:.85; }
.ph-amount { font-size:34px; font-weight:800; margin:6px 0 4px; letter-spacing:-.01em; }
.ph-next { font-size:12.5px; opacity:.92; display:flex; align-items:center; gap:6px; }
.ph-next.ph-muted { opacity:.72; }
.ph-next.ph-auto { margin-top:10px; align-items:flex-start; line-height:1.5; background:rgba(255,255,255,.15); padding:8px 11px; border-radius:8px; }
.ph-btn { margin-top:16px; align-self:flex-start; background:var(--surface, #ffffff); color:var(--primary-ink, #0f766e); font-weight:700; }
.ph-btn:hover:not(:disabled) { background:var(--primary-soft, #f0fdfa); }
.ph-btn:disabled { opacity:.55; cursor:not-allowed; }
.ph-warn { margin-top:10px; font-size:12px; display:flex; align-items:center; gap:6px; background:rgba(255,255,255,.15); padding:7px 10px; border-radius:8px; }
.ph-side { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
/* Payout bank details card (wallet detail view) */
.pd-card { padding:16px; margin-bottom:20px; }
.pd-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; }
.pd-title { display:inline-flex; align-items:center; gap:7px; font-weight:700; font-size:14px; color:var(--text); }
.pd-rows { display:flex; flex-direction:column; gap:6px; }
.pd-row { display:flex; justify-content:space-between; gap:12px; font-size:13px; padding:5px 0; border-bottom:1px dashed var(--border, #e8edf3); }
.pd-row:last-child { border-bottom:none; }
.pd-row span { color:var(--text-muted, #64748b); }
.pd-row b { color:var(--text); text-align:right; overflow-wrap:anywhere; }
.pd-empty { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--warning-ink, #92400e); background:var(--warning-bg, #fffbeb); border:1px solid var(--warning-border, #fde68a); border-radius:10px; padding:10px 12px; }
.pd-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:14px; }
.ph-stat { background:var(--surface, #ffffff); border:1px solid var(--border); border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:6px; }
.phs-top { display:flex; align-items:center; gap:7px; font-size:12.5px; font-weight:600; color:var(--text-muted); }
.ph-stat.clearing .phs-top { color:var(--warning-ink, #c2410c); }
.ph-stat.paying .phs-top { color:var(--warning-ink, #b45309); }
.ph-stat.lifetime .phs-top { color:var(--info-ink, #0891b2); }
.phs-val { font-size:20px; font-weight:800; color:var(--text); }
.phs-sub { font-size:12px; color:var(--text-muted); }

/* ── Payouts in progress (provider) ────────────────────────────────────────── */
.pay-progress { padding:14px 18px; margin-bottom:20px; }
.pp-head { display:flex; align-items:center; gap:7px; font-weight:700; font-size:14px; margin-bottom:6px; }
.pp-row { display:flex; align-items:center; gap:12px; padding:11px 0; border-top:1px solid var(--border); }
.pp-main { flex:1; min-width:0; }
.pp-amt { font-weight:700; font-size:15px; color:var(--text); }
.pp-meta { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
.pp-processing { font-size:12px; color:var(--text-muted); font-style:italic; }

/* ── Testing tools (collapsed) ─────────────────────────────────────────────── */
.demo-tools { margin-bottom:20px; border:1px dashed var(--border); border-radius:10px; background:var(--surface, #ffffff); }
.demo-tools > summary { cursor:pointer; padding:10px 14px; font-size:12.5px; font-weight:600; color:var(--text-muted); display:flex; align-items:center; gap:6px; list-style:none; }
.demo-tools > summary::-webkit-details-marker { display:none; }
.demo-tools[open] > summary { border-bottom:1px solid var(--border); }
.demo-tools-body { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:12px 14px; }

/* ── Payout queue quick actions (admin) ────────────────────────────────────── */
.wr-queue-actions { display:flex; align-items:center; gap:6px; flex-shrink:0; }

/* ── Reconciliation verdict + checks (admin panel) ─────────────────────────── */
.rv-verdict { display:flex; align-items:flex-start; gap:11px; border-radius:10px; padding:12px 14px; margin-bottom:12px; }
.rv-verdict.ok { background:var(--positive-bg, #f0fdf4); border:1px solid var(--positive-border, #bbf7d0); color:var(--positive-ink, #166534); }
.rv-verdict.warn { background:var(--warning-bg, #fff7ed); border:1px solid var(--warning-border, #fed7aa); color:var(--warning-ink, #9a3412); }
.rv-verdict b { display:block; font-size:14px; }
.rv-verdict span { font-size:12.5px; opacity:.9; }
.rv-checks { display:flex; flex-direction:column; gap:7px; }
.rv-check { display:flex; align-items:flex-start; gap:8px; font-size:12.5px; line-height:1.4; color:var(--text); }
.rv-check.ok { color:var(--positive-ink, #166534); }
.rv-check.bad { color:var(--danger-ink, #b91c1c); }
.rv-check svg { flex-shrink:0; margin-top:1px; }
.rv-tag { font-size:10px; font-weight:700; text-transform:uppercase; padding:1px 7px; border-radius:999px; }
.rv-tag.ok { background:var(--positive-bg, #dcfce7); color:var(--positive-ink, #166534); }
.rv-tag.bad { background:var(--danger-bg, #fee2e2); color:var(--danger-ink, #991b1b); }
.rv-tag.na { background:var(--bg); color:var(--text-muted); }

/* approved withdrawals get a blue badge */
.badge-info { background:var(--info-bg, #eff6ff); color:var(--info-ink, #1d4ed8); }

/* ── Balance integrity guard (admin) ───────────────────────────────────────── */
.drift-banner { border:1px solid var(--danger-border, #fecaca); background:var(--danger-bg, #fef2f2); border-radius:14px; padding:16px 18px; margin-bottom:20px; }
.drift-head { display:flex; align-items:center; gap:8px; font-weight:800; font-size:15px; color:var(--danger-ink, #991b1b); }
.drift-sub { font-size:12.5px; color:var(--danger-ink, #b91c1c); margin:4px 0 12px; line-height:1.5; }
.drift-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-top:1px solid var(--danger-border, #fecaca); }
.drift-actions { display:flex; gap:8px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
.drift-main { flex:1; min-width:0; }
.drift-main b { display:block; font-size:14px; color:var(--danger-ink, #7f1d1d); }
.drift-main span { font-size:12.5px; color:var(--danger-ink, #b91c1c); }

@media (max-width: 860px) {
  .pay-hero { grid-template-columns:1fr; }
  .ph-side { grid-template-columns:1fr; }
}

/* ── Phone ≤768px: admin hold-policy table scrolls inside its card ─────────── */
@media (max-width: 768px) {
  .mini-table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .mini-table { min-width:640px; }
}

/* ── Phone ≤600px: 360px-wide fixes (mobile-only, no desktop impact) ───────── */
@media (max-width: 600px) {
  /* Admin payout-queue cards: 4-col row overflows narrow screens — stack it */
  .wr-card { grid-template-columns:1fr auto; row-gap:10px; padding:12px 14px; }
  .wr-amount { font-size:16px; }
  .wr-queue-actions { grid-column:1 / -1; flex-wrap:wrap; }
  .wr-queue-actions .btn { flex:1; min-height:44px; justify-content:center; }

  /* Request panel: 5-col earnings table scrolls inside its own container */
  .req-tx-list { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .req-tx-head, .req-tx-row { min-width:480px; }

  /* Request panel: Reject / Approve stack full-width */
  .req-actions > div { flex-direction:column; }
  .req-actions .btn { width:100%; min-height:44px; justify-content:center; }

  /* Request panel: overview grid single-column so long mono values don't squash */
  .req-grid { grid-template-columns:1fr; }

  /* Upcoming releases: progress bar drops to its own full-width row */
  .hold-row { grid-template-columns:1fr auto; gap:8px 12px; }
  .hold-row .hold-bar-wrap { grid-column:1 / -1; }
  .hold-row .hold-amount { grid-row:1; grid-column:2; }

  /* 16px fields stop iOS auto-zoom (these scoped rules beat the global one) */
  .ws-bankq-select, .payout-select, .mono-input { font-size:16px; }

  /* Withdrawal modal: comfy tap targets + tighter destination label column */
  .wd-modal .modal-footer .btn { min-height:44px; }
  .ps-label { min-width:90px; }

  /* Payout-method summary: action button full-width and thumb-friendly */
  .pm-row > .btn { width:100%; justify-content:center; min-height:44px; }

  /* Balance hero: slightly smaller amount, full-width primary action */
  .ph-amount { font-size:28px; }
  .ph-btn { align-self:stretch; justify-content:center; min-height:44px; }

  /* Wallet detail header: long country label + pills wrap instead of overflowing */
  .wd-head-title { flex-wrap:wrap; align-items:flex-start; overflow-wrap:anywhere; }
  .wd-head-title .wl-badge, .wd-head-title .wd-legacy-tag { order:2; }
}
.chip-sep { width: 1px; align-self: stretch; background: var(--border, #e2e8f0); margin: 0 4px; }
</style>
