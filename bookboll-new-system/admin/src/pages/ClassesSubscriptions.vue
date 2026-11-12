<script setup>
import ExportDialog from '../components/ExportDialog.vue'
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadReferenceData } from '../lib/referenceData.js'
import { fetchWallets } from '../lib/walletPayouts.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { notifySetupChanged } from '../lib/overview.js'
import { markDirty, clearDirty, confirmDiscardIfDirty, registerDraftSaver } from '../lib/unsavedGuard.js'
import { saveFormDraft, loadFormDraft, clearFormDraft, draftAgeLabel } from '../lib/formDrafts.js'
import { rowState, TRIAL_TERMS_VERSION, PLATFORM_COMMISSION, DEFAULT_TRIAL_DAYS } from '../lib/approvalState.js'
import { MEMBERSHIP_CATEGORIES, MEMBERSHIP_CATEGORY_BY_KEY, normMembershipCategory, AUDIENCES, GYM_AUDIENCES, audienceOptionsFor, categoryNeedsAudience, categoryIsGym, GENDER_POLICIES, normGenderPolicy, effectiveGenderPolicy } from '../lib/membershipCategories.js'
import { loadApprovedTypes, approvedTypesFor, addPendingType } from '../lib/customTypes.js'
import { programSpanDays, offerModeFor, trialOptionsForSpan, recommendedTrialForSpan, trialOptionKey, discountOptionsForInterval, recommendedDiscountForInterval, discountedPrice } from '../lib/billingPlans.js'
import { uploadImage } from '../lib/imageUpload.js'
import { reservationSummary } from '../lib/programSessions.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { logAudit } from '../lib/audit.js'
import { Plus, Search, Trash2, Pencil as Edit2, Users, CalendarDays, DollarSign, X, Check, Repeat, BookOpen, CirclePause as PauseCircle, CirclePlay as PlayCircle, TriangleAlert as AlertTriangle, RefreshCw, ShieldCheck, Info, Clock, Image as ImageIcon, ChevronUp, ChevronDown as ChevronDownIcon, ListFilter as Filter, SlidersHorizontal, ChevronsUpDown, Infinity as InfinityIcon, Timer, CalendarCheck, Eye, EyeOff, GraduationCap, Download, Loader, ImagePlus, Wallet as WalletIcon, Store, Globe, TrendingDown, Copy, Share2, QrCode, ExternalLink } from 'lucide-vue-next'
import { downloadCSV } from '../lib/useExport.js'
import { usePremium } from '../lib/premium.js'
import PaginationBar from '../components/PaginationBar.vue'
import LocationModal from '../components/LocationModal.vue'
import FilterBar from '../components/FilterBar.vue'
import MultiSelect from '../components/MultiSelect.vue'
import { idIn } from '../lib/geoFilter.js'
import CustomFormBuilder from '../components/CustomFormBuilder.vue'
import PayoutSetupModal from '../components/PayoutSetupModal.vue'
import { asFields } from '../lib/customForms.js'
import { usePagination } from '../lib/usePagination.js'
import { SLOT_DURATIONS, fmt12 } from '../lib/slots.js'
import { CAP } from '../lib/permissions.js'
import { isWalletReady, isCountryWalletReady, hasActivePayoutMethodFor } from '../lib/walletSetup.js'
import { appBaseUrl } from '../lib/appBase.js'
import {
  fetchSubscriptionsDashboard, deleteProgram as deleteProgramApi, firstError,
  createProgram, updateProgram, setProgramState,
} from '../lib/subscriptions.js'
import QRCode from 'qrcode'

const { isSuperAdmin, isCountryAdmin, currentOwnerUserId, role: authRole, can, currentUser } = useCurrentUser()
// Platform-side accounts (Bookboll): super/country admin + platform staff.
const isPlatformSide = computed(() => isSuperAdmin.value || isCountryAdmin.value || authRole.value === 'platform_staff')
const canExport = computed(() => can(CAP.EXPORT_CATALOG))
const { isPremium, requirePremium: requirePremiumFeature } = usePremium()   // exports are Premium
const canManagePrograms = computed(() => can(CAP.MANAGE_LISTINGS))
const canManageBookings = computed(() => can(CAP.MANAGE_BOOKINGS))
const route = useRoute()

// When `embedded`, this component is mounted inside another page (Listings) as a
// form-only host: the table/header chrome is hidden and only the program form +
// its modals render. The parent drives it via the exposed open* methods.
const props = defineProps({ embedded: { type: Boolean, default: false } })
const emit = defineEmits(['saved'])
defineExpose({ openNewProgram: (...a) => openNewProgram(...a), openEditProgram: (...a) => openEditProgram(...a), openDuplicateProgram: (...a) => openDuplicateProgram(...a) })

const PROGRAM_FIELDS = [
  { key: 'name', label: 'Name' },
  { key: 'category', label: 'Category' },
  { key: 'sport_category', label: 'Type' },
  { key: 'coach_name', label: 'Coach' },
  { key: 'description', label: 'Description' },
  { key: 'monthly_price', label: 'Monthly Price' },
  { key: 'currency', label: 'Currency' },
  { key: 'session_duration_minutes', label: 'Session Duration (min)' },
  { key: 'session_time', label: 'Session Time' },
  { key: 'capacity', label: 'Capacity' },
  { key: 'photo_url', label: 'Photo URL' },
]

const programs = ref([])
const subscriptions = ref([])
const sessions = ref([])
const providers = ref([])
const listings = ref([])
const locations = ref([])
const cities = ref([])
const countries = ref([])
// Venue payloads carry city_id / country_id as strings, reference-data cities[] and
// countries[] carry integers — a strict === between them never matches. Same helper
// as Wallet.vue and Listings.vue.
const cid = v => (v === null || v === undefined || v === '') ? '' : String(v)
const search = ref('')
const filterCountry = ref([])
const filterCity = ref([])
const pgModuleError = ref('')
// Provider's wallets (one per country) — a program can't be created in a country
// until that country's wallet is active.
const providerWallets = ref([])

// Platform jurisdiction (country_admin / platform_staff only see their countries' data)
const { allowedLocationIds, inJurisdictionLoc, inJurisdictionOwner, scopeCountries, scopeCities } = useJurisdiction({ locations, cities, providers })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))
// Geo options derive from programs THIS viewer can see (a provider's own programs
// sit in one or two cities — don't offer the whole platform). Fallback: jurisdiction.
const fbCountries = computed(() => {
  const used = new Set(visiblePrograms.value.map(p => countryOfLocation(p.location_id)).filter(Boolean))
  const rows = jCountries.value.filter(c => used.has(c.id))
  return rows.length ? rows : jCountries.value
})
const fbCities = computed(() => {
  const used = new Set(visiblePrograms.value.map(p => locations.value.find(l => l.id === p.location_id)?.city_id).filter(Boolean))
  const rows = jCities.value.filter(c => used.has(c.id))
  return rows.length ? rows : jCities.value
})
// Program lifecycle filter — the states this table actually badges (Live / Not
// live / In review / Action needed / Draft), NOT generic booking statuses.
const filterState = ref([])
function programStateKey(p) {
  if (p.is_draft) return 'draft'
  const r = programReadiness(p)
  if (r.live) return 'live'
  if (r.label === 'In review') return 'review'
  if (r.label === 'Action needed') return 'action'
  return 'not_live'
}
const stateOptions = computed(() => [
  { value: 'live', label: 'Live' },
  { value: 'not_live', label: 'Not live' },
  { value: 'review', label: 'In review' },
  { value: 'action', label: 'Action needed' },
  ...(programDraftSupported.value ? [{ value: 'draft', label: 'Draft' }] : []),
])

function countryOfLocation(locationId) {
  const loc = locations.value.find(l => l.id === locationId)
  const city = cities.value.find(c => cid(c.id) === cid(loc?.city_id))
  return city?.country_id || null
}

async function loadAll() {
  // reference-data is already narrowed: a provider gets their own venues, a
  // platform admin gets their jurisdiction.
  const [dash, ref] = await Promise.all([
    fetchSubscriptionsDashboard(),
    loadReferenceData(),
  ])
  if (!dash.success) {
    pushToast(firstError(dash, 'Could not load memberships.'), 'error')
    programs.value = []
    subscriptions.value = []
    sessions.value = []
    providers.value = []
  } else {
    programs.value = dash.data?.programs || []
    subscriptions.value = dash.data?.subscriptions || []
    sessions.value = dash.data?.sessions || []
    providers.value = dash.data?.providers || []
  }
  // Laravel mapProgram always includes these columns.
  programColorSupported.value = true
  programCategorySupported.value = true
  programPlacementSupported.value = true
  programBonusSupported.value = true
  programOfferSupported.value = true
  programFormSupported.value = true
  programAudienceSupported.value = true
  programGenderSupported.value = true
  programJoiningFeeSupported.value = true
  programDraftSupported.value = true
  listings.value = ref.listings
  locations.value = ref.venues
  cities.value = ref.cities
  countries.value = ref.countries
  // Platform jurisdiction: country_admin / platform_staff only keep venues in their scope.
  const allowed = allowedLocationIds.value
  if (allowed) locations.value = locations.value.filter(l => allowed.has(l.id))

  // Wallet gate (per country): load the provider's wallets so we can check whether
  // the program's country has an active wallet before allowing creation.
  if (!isPlatformSide.value && currentOwnerUserId.value) {
    const ws = await fetchWallets({ owner_user_id: currentOwnerUserId.value })
    providerWallets.value = ws.success ? (ws.data?.wallets || []) : []
  }

  // Wind-down runs server-side on the subscriptions dashboard bootstrap.

  // auto-open new program if url hint present (standalone page only)
  if (!props.embedded && route.query.new === '1') {
    openNewProgram(route.query.location_id ? String(route.query.location_id) : '')
  } else if (!props.embedded && route.query.edit) {
    const found = programs.value.find(p => p.id === route.query.edit)
    if (found) openEditProgram(found)
  }
}
onMounted(loadAll)
onMounted(() => loadApprovedTypes())

// Wind-down (cancelling members of an archived programme once their billing date
// passes) runs server-side on the subscriptions dashboard bootstrap.

const providerName = (id) => { const p = providers.value.find(p => p.id === id); return p ? (p.business_name || p.full_name) : '—' }
const listingName = (id) => listings.value.find(l => l.id === id)?.name || '—'
const locationName = (id) => locations.value.find(l => l.id === id)?.name || '—'

// Returns '' when the location's currency cannot be resolved — an unset location,
// a venue with no city, or lists that have not loaded yet.
//
// This used to return 'AED' in those cases, which was worse than useless: every
// caller reads `currencyForLocation(...) || program.currency || ''`, so the
// hardcoded 'AED' always won and the program's OWN stored currency was never
// reached. A PKR program with no location displayed as AED — and because the save
// payload is built from the same expression, opening and saving it rewrote the
// currency to AED while its Stripe price stayed in PKR.
function currencyForLocation(locationId) {
  const loc = locations.value.find(l => l.id === locationId)
  if (!loc) return ''
  if (loc.currency_override) return loc.currency_override
  const city = cities.value.find(c => cid(c.id) === cid(loc.city_id))
  if (!city) return ''
  const country = countries.value.find(c => cid(c.id) === cid(city.country_id))
  return country?.currency_code || ''
}
const programById = (id) => programs.value.find(p => p.id === id)
const subsForProgram = (id) => subscriptions.value.filter(s => s.program_id === id).length
const sessionsForSub = (id) => sessions.value.filter(s => s.subscription_id === id).length

/* Effective perspective is driven by the real authenticated role —
   super/country admins get the platform-wide admin view, everyone else
   (provider_admin and provider-scoped staff) gets their own scoped view. */
const role = computed(() => isPlatformSide.value ? 'admin' : 'provider')

/* program drawer */
const pgModal = ref(false)
// The form's draft saver must never outlive the modal — otherwise leaving the
// page with some OTHER dirty state would snapshot a stale program form.
watch(pgModal, v => { if (!v) registerDraftSaver(null) })
const pgOpen = ref('details')   // which accordion section is expanded
const pgTriedSave = ref(false)
// Whether the class_programs.color column exists yet (migration applied). Probed
// on load so saving never fails if the column hasn't been added to the DB.
const programColorSupported = ref(false)
const programCategorySupported = ref(false)
const programPlacementSupported = ref(false)
const programBonusSupported = ref(false)
const programOfferSupported = ref(false)   // free_trial_sessions + discount_months columns
const programFormSupported = ref(false)    // signup_form column (custom questions)
const programAudienceSupported = ref(false) // audience column (kids/adults/both)
const programGenderSupported = ref(false)   // gender_policy column (mixed/men/women)
const programJoiningFeeSupported = ref(false) // joining_fee column (one-off sign-up fee)
const programDraftSupported = ref(false)      // is_draft column (saved but not yet submitted)
const PROGRAM_COLORS = ['#7c3aed', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#db2777', '#475569']
async function requestCloseProgram() {
  const res = await confirmDiscardIfDirty()   // false = stay · true = discard · 'draft' = saved a draft
  if (!res) return
  registerDraftSaver(null)
  if (res === 'draft') pushToast('Draft saved — it will be offered when you reopen this form', 'success')
  pgModal.value = false
}

// ── Local "save as draft" for the program form (complements the DB Save-draft
// button): snapshots pgForm to localStorage and offers it back on reopen. ──
function programDraftKey() { return pgEditing.value?.id ? `program_${pgEditing.value.id}` : 'program_new' }
function programDraftSnapshot() { return { pgForm: pgForm.value, pgActiveTemplate: pgActiveTemplate.value } }
async function offerProgramDraft() {
  const key = programDraftKey()
  const d = loadFormDraft(key)
  if (!d) return
  const resume = await askConfirm({
    title: 'Resume your draft?', danger: false,
    message: `You saved an unfinished version of this form ${draftAgeLabel(d.at)}. Continue where you left off?`,
    confirmText: 'Resume draft', cancelText: 'Start fresh',
  })
  if (resume) {
    if (d.data.pgForm) pgForm.value = { ...pgForm.value, ...d.data.pgForm }
    if (d.data.pgActiveTemplate !== undefined) pgActiveTemplate.value = d.data.pgActiveTemplate
    markDirty()   // restored draft is still unsaved — keep the guard armed
  } else {
    clearFormDraft(key)
  }
}
const pgEditing = ref(null)
// True while editing a live program whose content (title/description/photos) is awaiting
// approval — the editor shows the SUBMITTED version and a "current one stays live" banner.
const pgContentUnderReview = ref(false)
const pgForm = ref(defaultProgram())
const pgTrialAccepted = ref(false)

// Currency always follows the program's location → city → country. Keep the
// form's currency in lock-step with the selected location so it can never drift.
watch(() => pgForm.value.location_id, (locId) => {
  if (locId) pgForm.value.currency = currencyForLocation(locId) || pgForm.value.currency || ''
})

// ── MOAT: every program must offer an incentive — never "none" ────────────────
// Two modes, picked automatically by the plan (offerModeFor):
//   • trial    → monthly ongoing + ALL fixed seasons (installments & pay-in-full).
//                A mandatory free trial, with options gated by the program's length.
//   • discount → longer-than-monthly ONGOING plans (quarterly / yearly, paid
//                upfront): "N months off" the list price instead of a trial.
const isFixed = computed(() => pgForm.value.program_type === 'fixed_duration')
const offerMode = computed(() => offerModeFor(pgForm.value))   // 'trial' | 'discount'

// Free-trial options, gated by how long the program runs (its span). Always ≥1 option.
const trialSpan = computed(() => programSpanDays(pgForm.value))
const trialOptions = computed(() => trialOptionsForSpan(trialSpan.value))
const recommendedTrialKey = computed(() => recommendedTrialForSpan(trialSpan.value).key)
const currentTrialKey = computed(() => trialOptionKey(pgForm.value.free_trial_days, pgForm.value.free_trial_sessions))
function setTrialOption(opt) {
  pgForm.value.free_trial_days = opt.days || 0
  pgForm.value.free_trial_sessions = opt.sessions || 0
  pgForm.value.discount_months = 0
}
// If the current trial isn't offered for this span (e.g. after a duration change),
// fall back to the recommended one — the MOAT means there's always a trial selected.
function ensureValidTrial() {
  const keys = trialOptions.value.map(o => o.key)
  if (!keys.includes(currentTrialKey.value)) {
    const rec = trialOptions.value.find(o => o.key === recommendedTrialKey.value) || trialOptions.value[trialOptions.value.length - 1]
    setTrialOption(rec)
  }
}

// Discount options (months off the list price) for longer ongoing plans.
const discountOptions = computed(() => discountOptionsForInterval(pgForm.value.billing_interval))
const recommendedDiscount = computed(() => recommendedDiscountForInterval(pgForm.value.billing_interval))
const discountedAmount = computed(() => discountedPrice(pgForm.value.monthly_price, pgForm.value.billing_interval, pgForm.value.discount_months))
function setDiscount(n) {
  pgForm.value.discount_months = n
  pgForm.value.free_trial_days = 0
  pgForm.value.free_trial_sessions = 0
}
function ensureValidDiscount() {
  if (!discountOptions.value.includes(Number(pgForm.value.discount_months))) setDiscount(recommendedDiscount.value)
}
// Re-seed the right incentive for the current mode.
function reseedOffer() {
  if (offerMode.value === 'discount') ensureValidDiscount()
  else ensureValidTrial()
}
function onBillingIntervalChange() { reseedOffer() }   // ongoing interval picker

// Fixed-duration: "pay in full" (one charge) or "monthly installments". Both still
// require a free trial (the MOAT) — switching styles keeps a valid trial selected.
function setPayStyle(style) {
  pgForm.value.discount_months = 0
  pgForm.value.billing_interval = style === 'full' ? 'one_time' : 'monthly'
  ensureValidTrial()
}

const fmtShortDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
function trialPhrase() {
  if (Number(pgForm.value.free_trial_sessions) > 0) return '1 free session'
  const d = Number(pgForm.value.free_trial_days) || 0
  return d > 0 ? `${d} days free` : ''
}
// Noun phrase for the trial-agreement modal: "1 free session" or "14-day free trial".
const trialAgreeLabel = computed(() =>
  Number(pgForm.value.free_trial_sessions) > 0 ? '1 free session' : `${Number(pgForm.value.free_trial_days) || 0}-day free trial`
)
const trialIsSession = computed(() => Number(pgForm.value.free_trial_sessions) > 0)
// One-line "what the customer sees" preview for the program's billing + incentive.
const planPreviewText = computed(() => {
  const cur = pgForm.value.currency || ''
  const price = fmtMoney(pgForm.value.monthly_price, cur)
  if (!(Number(pgForm.value.monthly_price) > 0)) return ''
  // A one-off sign-up fee (if set) is charged once at joining, before the recurring plan.
  const fee = Number(pgForm.value.joining_fee) || 0
  const joinLead = fee > 0 ? `${fmtMoney(fee, cur)} joining fee, then ` : ''
  const withJoin = (s) => joinLead + s
  if (offerMode.value === 'discount') {
    const iv = intervalLabel(pgForm.value.billing_interval).toLowerCase()
    const m = Number(pgForm.value.discount_months) || 0
    return withJoin(m > 0 ? `${price} → ${fmtMoney(discountedAmount.value, cur)}/${iv} · ${m} month${m !== 1 ? 's' : ''} off` : `${price}/${iv}`)
  }
  const tp = trialPhrase()
  const lead = tp ? `${tp}, then ` : ''
  // A "1 session" trial bills on the 2nd session, so say so instead of "until <end>".
  const onSecond = Number(pgForm.value.free_trial_sessions) > 0
  if (isFixed.value) {
    if (pgForm.value.billing_interval === 'one_time') {
      return withJoin(onSecond ? `${lead}${price} on the 2nd session` : `${lead}${price} once for the whole program`)
    }
    if (onSecond) return withJoin(`${lead}${price}/month from the 2nd session`)
    const end = pgForm.value.end_date ? ` until ${fmtShortDate(pgForm.value.end_date)}` : ' until the program ends'
    return withJoin(`${lead}${price}/month${end}`)
  }
  const iv = intervalLabel(pgForm.value.billing_interval).toLowerCase()
  return withJoin(onSecond ? `${lead}${price}/${iv} from the 2nd session` : `${lead}${price}/${iv}`)
})

// Switching Ongoing ↔ Fixed swaps the billing model; re-seed a valid incentive.
watch(() => pgForm.value.program_type, (t) => {
  if (t === 'fixed_duration') {
    pgForm.value.discount_months = 0
    if (!['one_time', 'monthly'].includes(pgForm.value.billing_interval)) pgForm.value.billing_interval = 'one_time'
    ensureValidTrial()
  } else {
    if (pgForm.value.billing_interval === 'one_time') pgForm.value.billing_interval = 'monthly'
    reseedOffer()
  }
})
// A fixed season's length changes which trials are offered — keep the choice valid.
watch(trialSpan, () => { if (offerMode.value === 'trial') ensureValidTrial() })

// Combined category + type — same pattern as the venue listing form: the user searches/picks
// ONE Type from a flat list (each tagged with its category); the category is derived from the
// chosen type and shown read-only. No need to pick a category first.
const pgSportOpen = ref(false)
const pgSportQuery = ref('')
const pgExtra = ref([])   // types added in THIS session (pending approval), shown right away
const pgTypeOptions = computed(() => {
  const opts = []
  const seen = new Set()
  const push = (type, cat, catLabel) => {
    const k = (cat + '::' + type).toLowerCase()
    if (seen.has(k)) return; seen.add(k)
    opts.push({ type, cat, catLabel })
  }
  MEMBERSHIP_CATEGORIES.forEach(c => c.subcategories.forEach(t => push(t, c.key, c.label)))
  approvedTypesFor('program').forEach(r => push(r.name, r.category, MEMBERSHIP_CATEGORY_BY_KEY[r.category]?.label || r.category))
  pgExtra.value.forEach(o => push(o.type, o.cat, o.catLabel))
  return opts
})
const pgFilteredTypes = computed(() => {
  const q = pgSportQuery.value.trim().toLowerCase()
  if (!q) return pgTypeOptions.value
  return pgTypeOptions.value.filter(o => o.type.toLowerCase().includes(q) || o.catLabel.toLowerCase().includes(q))
})
const pgDerivedCategoryLabel = computed(() => pgForm.value.category ? (MEMBERSHIP_CATEGORY_BY_KEY[pgForm.value.category]?.label || '') : '')
function selectPgType(opt) {
  pgForm.value.sport_category = opt.type
  pgForm.value.category = opt.cat            // category derived from the chosen type
  pgSportOpen.value = false
  pgSportQuery.value = ''
  pgAddingType.value = false
}
function onPgSportFocus() { pgSportOpen.value = true }
function onPgSportBlur() { setTimeout(() => { pgSportOpen.value = false; pgAddingType.value = false }, 150) }

// "Add new type": name = what was typed, provider picks ONE category. Stored on this program
// now; joins the shared catalog once the program is approved (lib/customTypes.js).
const pgAddingType = ref(false)
const pgNewTypeName = computed(() => pgSportQuery.value.trim())
const pgCanAddType = computed(() =>
  pgNewTypeName.value.length >= 2 &&
  !pgTypeOptions.value.some(o => o.type.toLowerCase() === pgNewTypeName.value.toLowerCase()))
function startPgAddType() { pgAddingType.value = true }
async function confirmPgAddType(catKey) {
  const name = pgNewTypeName.value
  if (!name || !catKey) return
  await addPendingType({ domain: 'program', name, category: catKey })
  pgExtra.value.push({ type: name, cat: catKey, catLabel: MEMBERSHIP_CATEGORY_BY_KEY[catKey]?.label || catKey })
  pgForm.value.sport_category = name
  pgForm.value.category = catKey
  pgAddingType.value = false
  pgSportOpen.value = false
  pgSportQuery.value = ''
}

// ── Category-driven form shape ───────────────────────────────────────────────────
// Gym = facility-access membership: no coach, capacity, sessions, session time, or fixed
// seasons — it's ongoing access during the venue's opening hours. Class/Academy keep the
// full session schedule + an audience (kids/adults/both).
const pgIsGym = computed(() => categoryIsGym(pgForm.value.category))
const pgNeedsAudience = computed(() => categoryNeedsAudience(pgForm.value.category))  // required (class/academy)
const pgShowAudience = computed(() => pgIsGym.value || pgNeedsAudience.value)         // shown for all 3 once a category is picked
const pgAudienceOptions = computed(() => audienceOptionsFor(pgForm.value.category))
// When the category changes, normalise fields so the rest of the form behaves correctly and
// the audience value stays valid for the new category's option set (gym=access, else=age).
watch(() => pgForm.value.category, (cat) => {
  if (categoryIsGym(cat)) {
    pgForm.value.program_type = 'ongoing'        // gyms bill ongoing, never fixed seasons
    pgForm.value.day_of_week_mask = [0, 1, 2, 3, 4, 5, 6]   // open all week (hours govern)
    if (!GYM_AUDIENCES.some(a => a.key === pgForm.value.audience)) pgForm.value.audience = 'everyone'
  } else if (categoryNeedsAudience(cat)) {
    if (!AUDIENCES.some(a => a.key === pgForm.value.audience)) pgForm.value.audience = ''
  }
})

// Placement: warn before turning OFF the recommended Bookboll marketplace.
const mktConfirm = ref(false)
function onMarketplaceToggle() {
  if (!pgForm.value.show_on_marketplace) {
    // Selling off-marketplace (own website only) is a PREMIUM feature.
    if (!requirePremiumFeature('own_site_only')) { pgForm.value.show_on_marketplace = true; return }
    mktConfirm.value = true   // turning off → confirm
  }
}
function keepOnMarketplace() { pgForm.value.show_on_marketplace = true; mktConfirm.value = false }
function confirmMarketplaceOff() { mktConfirm.value = false }

// ── Create new location from the program form — uses the shared <LocationModal> ──
const newLocModal = ref(false)
function openNewLocation() { newLocModal.value = true }
function onLocationSaved(saved) {
  newLocModal.value = false
  if (!saved) return
  notifySetupChanged()   // a venue can be created from here too — keep the guide honest
  // Merge into the local list (insert or replace) and auto-select it
  if (locations.value.some(l => l.id === saved.id)) {
    locations.value = locations.value.map(l => (l.id === saved.id ? saved : l))
  } else {
    locations.value = [...locations.value, saved]
  }
  pgForm.value.location_id = saved.id            // select it (currency watch fires)
  if (saved.owner_user_id) pgForm.value.owner_user_id = saved.owner_user_id
}

/* trial agreement modal */
const trialAgreementModal = ref(false)
// "Set up your Subscription wallet" gate modal (shown when a provider tries to create a
// program without an active subscription payout method). `payoutModalPending` switches the
// copy to "under review" when the method was submitted but not yet approved by Bookboll.
const payoutModal = ref(false)
const payoutModalCountry = ref('')
const payoutModalPending = ref(false)
const trialAgreementChecked = ref(false)
// PROVIDERS are hard-gated: no creating or submitting a membership listing until their
// membership (subscription) payout method is connected AND approved by Bookboll — a
// merely-submitted method (pending_review) does not count. Platform-side staff can still
// create programs for a provider; those save as not-yet-live ('inactive') via this flag
// (carried from requestSaveProgram through the trial-agreement hop into saveProgram).
const pgForcedInactive = ref(false)

// The hard gate itself. Returns true when the current actor may set up / submit a
// membership listing. Providers without an ACTIVE subscription payout method get the
// guided payout modal and a clear toast instead. Checked LIVE against the DB (not a
// cached flag) so it can't be bypassed by a stale session.
async function providerPayoutGate(locationId = '') {
  if (isPlatformSide.value) return true
  const ownerId = currentOwnerUserId.value
  if (!ownerId) return true
  const wRes = await fetchWallets({ owner_user_id: ownerId }); const ws = wRes.success ? (wRes.data?.wallets || []) : []
  if (hasActivePayoutMethodFor(ws || [], 'subscription')) return true
  payoutModalPending.value = (ws || []).some(w => w.setup_status === 'pending_review')
  payoutModalCountry.value = countryOfLocation(locationId) || ''
  payoutModal.value = true
  pushToast(payoutModalPending.value
    ? 'Your membership payout method is still under Bookboll review — you can create membership listings once it\'s approved.'
    : 'Connect a membership payout method first — then you can create membership listings.', 'error')
  return false
}

function defaultProgram() {
  return {
    owner_user_id: currentOwnerUserId.value || '',
    location_id: '',
    listing_id: '',
    listing_ids: [],
    name: '', description: '', category: '', sport_category: '', audience: '', gender_policy: 'mixed',
    show_on_marketplace: true, show_on_provider_site: true,   // placement (both on by default)
    // Price starts blank so we can show a currency-aware "Suggested: X" hint instead of a
    // hardcoded number the provider might overlook. joining_fee is a one-off sign-up charge.
    coach_name: '', monthly_price: '', joining_fee: 0, currency: '', billing_interval: 'monthly',
    is_draft: false,   // becomes true only when saved via "Save draft"
    bonus_months: 0,
    discount_months: 0,
    free_trial_sessions: 0,
    sessions_per_week: 2, session_duration_minutes: 60,
    day_of_week_mask: [], session_time: '18:00',   // days start EMPTY — the provider picks them
    capacity: 12,
    commission_percentage: PLATFORM_COMMISSION,
    free_trial_days: DEFAULT_TRIAL_DAYS,
    free_trial_enabled: true,
    status: 'active',
    program_type: 'ongoing',
    duration_weeks: null,
    start_date: '',
    end_date: '',
    photo_url: '',
    images: [],
    signup_form: [],
    color: '#7c3aed',
    approval_status: 'approved',
    eligibility_status: 'eligible',
    admin_note: '',
    previous_snapshot: null,
    trial_terms_accepted_at: null,
    trial_terms_version: '',
  }
}

// ── Starter templates ─────────────────────────────────────────────────────────
// One-click presets that pre-fill a fresh program with sensible defaults for common
// setups. Client-only — each just patches defaultProgram() then lets the recommended
// incentive logic re-seed a valid trial/discount for the resulting plan.
const PROGRAM_TEMPLATES = [
  {
    key: 'monthly_gym', label: 'Monthly Gym',
    patch: { category: 'gym', sport_category: 'General Gym', program_type: 'ongoing', billing_interval: 'monthly', monthly_price: 250, audience: 'everyone', day_of_week_mask: [0, 1, 2, 3, 4, 5, 6] },
  },
  {
    key: 'kids_academy', label: 'Kids Academy (term)',
    patch: { category: 'academy', sport_category: 'Football', program_type: 'fixed_duration', billing_interval: 'monthly', monthly_price: 400, audience: 'kids', day_of_week_mask: [1, 3], session_time: '17:00', capacity: 16 },
  },
  {
    key: 'weekly_class', label: 'Weekly Class Pass',
    patch: { category: 'class', sport_category: 'Yoga', program_type: 'ongoing', billing_interval: 'weekly', monthly_price: 90, audience: 'adults', day_of_week_mask: [2, 4], session_time: '18:30', capacity: 12 },
  },
]
function applyTemplate(tpl) {
  const loc = pgForm.value.location_id
  const cur = pgForm.value.currency
  const owner = pgForm.value.owner_user_id
  // Start from a clean default so a second template click never leaves stale fields,
  // then overlay the template patch. Keep any location the provider already chose.
  pgForm.value = { ...defaultProgram(), ...tpl.patch }
  if (loc) { pgForm.value.location_id = loc; pgForm.value.currency = cur || currencyForLocation(loc) || '' }
  if (owner) pgForm.value.owner_user_id = owner
  reseedOffer()   // pick a valid recommended trial/discount for the new plan
  pgActiveTemplate.value = tpl.key
  markDirty()
}
const pgActiveTemplate = ref('')

async function openNewProgram(locationId = '') {
  // Providers must have an APPROVED membership payout method before they can even
  // start a membership listing (the modal guides them to wallet setup instead).
  if (!(await providerPayoutGate(locationId))) return
  pgEditing.value = null
  pgContentUnderReview.value = false
  pgActiveTemplate.value = ''
  pgForcedInactive.value = false
  pgForm.value = defaultProgram()
  if (locationId) {
    pgForm.value.location_id = locationId
    const loc = locations.value.find(l => l.id === locationId)
    if (loc?.owner_user_id) pgForm.value.owner_user_id = loc.owner_user_id
    pgForm.value.currency = currencyForLocation(locationId) || pgForm.value.currency || ''
  }
  pgTrialAccepted.value = false
  pgTriedSave.value = false
  clearDirty()
  pgModal.value = true
  offerProgramDraft().then(() => registerDraftSaver(() => saveFormDraft(programDraftKey(), programDraftSnapshot())))
}
function openEditProgram(p) {
  pgEditing.value = p
  pgForm.value = {
    ...p,
    category: normMembershipCategory(p.category),
    gender_policy: effectiveGenderPolicy(p),   // 'mixed' when the column is missing pre-migration
    show_on_marketplace: p.show_on_marketplace !== false,
    show_on_provider_site: p.show_on_provider_site !== false,
    bonus_months: Number(p.bonus_months) || 0,
    discount_months: Number(p.discount_months) || 0,
    free_trial_sessions: Number(p.free_trial_sessions) || 0,
    joining_fee: Number(p.joining_fee) || 0,
    is_draft: p.is_draft === true,
    currency: currencyForLocation(p.location_id) || p.currency || '',
    day_of_week_mask: Array.isArray(p.day_of_week_mask) ? [...p.day_of_week_mask] : [],
    images: Array.isArray(p.images) ? [...p.images] : [],
    signup_form: asFields(p.signup_form),
    listing_ids: Array.isArray(p.listing_ids) ? [...p.listing_ids] : (p.listing_id ? [p.listing_id] : []),
  }
  // A live program with content awaiting review → edit the SUBMITTED version (the approved
  // one keeps serving). Reverting these back to the approved values withdraws the request.
  const pgc = p.pending_content
  pgContentUnderReview.value = !!(pgc && (p.approval_status || 'approved') === 'approved')
  if (pgContentUnderReview.value) {
    if (pgc.name != null) pgForm.value.name = pgc.name
    if (pgc.description != null) pgForm.value.description = pgc.description
    if (pgc.photo_url != null) pgForm.value.photo_url = pgc.photo_url
    if (Array.isArray(pgc.images)) pgForm.value.images = [...pgc.images]
  }
  pgTrialAccepted.value = !!p.trial_terms_accepted_at && p.trial_terms_version === TRIAL_TERMS_VERSION
  pgTriedSave.value = false
  clearDirty()
  pgModal.value = true
  offerProgramDraft().then(() => registerDraftSaver(() => saveFormDraft(programDraftKey(), programDraftSnapshot())))
}

// Clone an existing program as a fresh NEW one — same details, schedule, pricing, offer,
// images and sign-up questions, but a new identity. The single biggest time-saver for a
// provider adding their 2nd..Nth program (Kids vs Adults, or the same program at another
// location). The trial agreement re-runs and approval starts fresh.
async function openDuplicateProgram(p) {
  // Same hard gate as a brand-new listing — a duplicate is a new program.
  if (!(await providerPayoutGate(p.location_id || ''))) return
  pgEditing.value = null
  pgContentUnderReview.value = false
  pgForcedInactive.value = false
  pgForm.value = {
    ...defaultProgram(),
    ...p,
    id: undefined,
    name: (p.name || 'Program') + ' (copy)',
    category: normMembershipCategory(p.category),
    gender_policy: effectiveGenderPolicy(p),
    show_on_marketplace: p.show_on_marketplace !== false,
    show_on_provider_site: p.show_on_provider_site !== false,
    bonus_months: Number(p.bonus_months) || 0,
    discount_months: Number(p.discount_months) || 0,
    free_trial_sessions: Number(p.free_trial_sessions) || 0,
    joining_fee: Number(p.joining_fee) || 0,
    is_draft: false,   // a duplicate is a fresh program, never inherit draft state
    currency: currencyForLocation(p.location_id) || p.currency || '',
    day_of_week_mask: Array.isArray(p.day_of_week_mask) ? [...p.day_of_week_mask] : [],
    images: Array.isArray(p.images) ? [...p.images] : [],
    signup_form: asFields(p.signup_form),
    listing_ids: Array.isArray(p.listing_ids) ? [...p.listing_ids] : (p.listing_id ? [p.listing_id] : []),
    // fresh identity — never inherit the source's approval / agreement / snapshot
    status: 'active',
    approval_status: 'approved',
    eligibility_status: 'eligible',
    admin_note: '',
    previous_snapshot: null,
    trial_terms_accepted_at: null,
    trial_terms_version: '',
  }
  pgTrialAccepted.value = false
  pgTriedSave.value = false
  clearDirty()
  pgModal.value = true
  // a duplicate is a NEW program — no resume prompt (it starts from the source), just protection
  registerDraftSaver(() => saveFormDraft(programDraftKey(), programDraftSnapshot()))
}

/* images management — upload (auto-compress → our own storage), URL paste fallback */
const newImageUrl = ref('')
const imgUploading = ref(false)
const imgError = ref('')
const dropActive = ref(false)
const pgFileInput = ref(null)
async function addProgramImages(files) {
  imgError.value = ''
  const list = [...(files || [])].filter(f => f.type?.startsWith('image/'))
  if (!list.length) return
  if (!Array.isArray(pgForm.value.images)) pgForm.value.images = []
  imgUploading.value = true
  for (const f of list) {
    const r = await uploadImage(f, 'programs')
    if (r.error) { imgError.value = r.error; break }
    pgForm.value.images.push(r.url)
  }
  imgUploading.value = false
}
function onProgramFilesChange(e) { addProgramImages(e.target.files); e.target.value = '' }
function onProgramDrop(e) { dropActive.value = false; addProgramImages(e.dataTransfer?.files) }
function addImage() {   // URL paste fallback
  const url = newImageUrl.value.trim()
  if (!url) return
  if (!Array.isArray(pgForm.value.images)) pgForm.value.images = []
  pgForm.value.images.push(url)
  newImageUrl.value = ''
}
function removeImage(i) {
  pgForm.value.images.splice(i, 1)
}
function toggleDay(d) {
  const arr = pgForm.value.day_of_week_mask || []
  const i = arr.indexOf(d)
  if (i >= 0) arr.splice(i, 1); else arr.push(d)
  pgForm.value.day_of_week_mask = [...arr].sort()
}


// ── Save draft ────────────────────────────────────────────────────────────────
// Persist a work-in-progress program with ONLY a name + location required. Skips the
// incentive / trial-agreement / price validation entirely. Saved as status:'inactive'
// (already hidden from the storefront) plus is_draft:true so the row shows a "Draft"
// badge and a normal Save later runs full validation + clears the flag.
async function saveDraft() {
  pgTriedSave.value = true
  if (!pgForm.value.name || !pgForm.value.location_id) {
    pgOpen.value = 'details'
    pushToast('A draft needs at least a name and a location.', 'error')
    return
  }
  // Block if the program's city has the Classes module disabled (same as saveProgram).
  const pgLoc  = locations.value.find(l => l.id === pgForm.value.location_id)
  const pgCity = cities.value.find(c => cid(c.id) === cid(pgLoc?.city_id))
  if (pgCity && pgCity.module_classes === false) {
    pgModuleError.value = `Membership programs are disabled for ${pgCity.name}. A Super Admin must enable the "Memberships" module for this city in Countries & Cities before programs can be created here.`
    return
  }
  pgModuleError.value = ''

  // Derive owner from the location when not already set.
  let ownerId = pgForm.value.owner_user_id
  if (!ownerId && pgLoc?.owner_user_id) ownerId = pgLoc.owner_user_id

  const isGym = categoryIsGym(pgForm.value.category)
  // Whatever the provider has entered so far — no incentive/price requirements. A draft
  // is never live, so status is always 'inactive' and no money can move.
  const base = {
    owner_user_id: ownerId,
    location_id: pgForm.value.location_id || null,
    listing_ids: [],
    listing_id: null,
    name: pgForm.value.name,
    description: pgForm.value.description || '',
    sport_category: pgForm.value.sport_category || '',
    coach_name: isGym ? '' : (pgForm.value.coach_name || ''),
    monthly_price: Number(pgForm.value.monthly_price) || 0,
    currency: currencyForLocation(pgForm.value.location_id) || pgForm.value.currency || '',
    billing_interval: pgForm.value.billing_interval || 'monthly',
    sessions_per_week: isGym ? 0 : (Number(pgForm.value.sessions_per_week) || 1),
    session_duration_minutes: isGym ? 0 : (Number(pgForm.value.session_duration_minutes) || 60),
    day_of_week_mask: isGym ? [0, 1, 2, 3, 4, 5, 6] : (pgForm.value.day_of_week_mask || []),
    session_time: isGym ? null : (pgForm.value.session_time || '18:00'),
    capacity: isGym ? 0 : (Number(pgForm.value.capacity) || 12),
    commission_percentage: Math.max(0, Number(pgForm.value.commission_percentage ?? PLATFORM_COMMISSION)),
    free_trial_days: Number(pgForm.value.free_trial_days) || 0,
    free_trial_enabled: false,
    status: 'inactive',   // drafts are never live (already hidden from the storefront)
    program_type: isGym ? 'ongoing' : (pgForm.value.program_type || 'ongoing'),
    duration_weeks: (!isGym && pgForm.value.program_type === 'fixed_duration') ? (Number(pgForm.value.duration_weeks) || null) : null,
    start_date: isGym ? null : (pgForm.value.start_date || null),
    end_date: isGym ? null : (pgForm.value.end_date || null),
    photo_url: pgForm.value.photo_url || (Array.isArray(pgForm.value.images) && pgForm.value.images[0]) || '',
    images: Array.isArray(pgForm.value.images) ? pgForm.value.images : [],
  }
  // Guarded optional columns (probed on load) — same pattern as saveProgram.
  if (programColorSupported.value) base.color = pgForm.value.color || '#7c3aed'
  if (programCategorySupported.value && pgForm.value.category) base.category = normMembershipCategory(pgForm.value.category)
  if (programAudienceSupported.value && pgForm.value.audience) base.audience = pgForm.value.audience
  if (programGenderSupported.value && !isGym) base.gender_policy = normGenderPolicy(pgForm.value.gender_policy)
  if (programPlacementSupported.value) {
    base.show_on_marketplace = pgForm.value.show_on_marketplace !== false
    base.show_on_provider_site = pgForm.value.show_on_provider_site !== false
  }
  if (programOfferSupported.value) {
    base.free_trial_sessions = Number(pgForm.value.free_trial_sessions) || 0
    base.discount_months = Number(pgForm.value.discount_months) || 0
  }
  if (programFormSupported.value) base.signup_form = asFields(pgForm.value.signup_form)
  if (programJoiningFeeSupported.value) base.joining_fee = Math.max(0, Number(pgForm.value.joining_fee) || 0)
  // The draft flag itself. If the column doesn't exist yet the retry below drops it.
  if (programDraftSupported.value) base.is_draft = true

  // is_draft tells the API this is a draft: never live, never reviewed.
  const payload = { ...base, is_draft: true }
  const res = pgEditing.value
    ? await updateProgram(pgEditing.value.id, payload)
    : await createProgram(payload)
  if (!res.success) { pushToast(res.message || 'Could not save the draft.', 'error'); return }
  const savedId = res.data?.program?.id || null

  clearDirty()
  clearFormDraft(programDraftKey())   // persisted to the DB — the local snapshot is superseded
  registerDraftSaver(null)
  pgModal.value = false
  pushToast(savedId ? 'Draft saved — finish and submit it any time.' : 'Draft saved.', 'success')
  loadAll()
  emit('saved')
}
// A plan is only chargeable once it has a price.
const hasValidPlan = () => Number(pgForm.value.monthly_price) > 0


async function requestSaveProgram() {
  // Submitting for real always clears the draft flag and runs full validation.
  pgForm.value.is_draft = false
  if (!pgForm.value.name || !pgForm.value.location_id || !pgForm.value.category || !pgForm.value.sport_category) {
    pgTriedSave.value = true
    pgOpen.value = 'details'   // reveal the invalid field instead of leaving it collapsed
    pushToast('Please fill in the required fields (name, location, category and type).', 'error')
    return
  }
  // Class & academy programs must declare who they're for (kids / adults / both).
  if (pgNeedsAudience.value && !pgForm.value.audience) {
    pgTriedSave.value = true
    pgOpen.value = 'details'   // audience lives in the Details section
    pushToast('Select who this program is for — kids, adults or both.', 'error')
    return
  }
  // Class & academy sessions run on specific days — days start unselected, so
  // require an explicit choice (gym is exempt: access follows venue hours).
  if (!pgIsGym.value && !(pgForm.value.day_of_week_mask || []).length) {
    pgTriedSave.value = true
    pgOpen.value = 'schedule'   // the day picker lives in the Schedule section
    pushToast('Pick at least one session day in the Schedule section.', 'error')
    return
  }
  // Wallet gate (OWNER-based, authoritative): subscriptions are full-online, so the owner
  // needs an ACTIVE (Bookboll-approved) SUBSCRIPTION-wallet payout method — but NOT per
  // country. Any usable subscription wallet (in any country) is enough; payout falls back
  // to it (a local wallet just avoids FX). Checked live against the DB so it can't be
  // bypassed. PROVIDERS are hard-blocked from submitting at all until approved (a
  // pending_review submission doesn't count); platform-side staff may still save a
  // program for the provider — it lands NOT-YET-LIVE ('inactive'), so money never moves.
  const pgLoc = locations.value.find(l => l.id === pgForm.value.location_id)
  const ownerId = pgLoc?.owner_user_id || null
  pgForcedInactive.value = false
  if (ownerId) {
    // select('*') so newer columns (purpose, payout_paused) are seen whenever they exist.
    const wRes = await fetchWallets({ owner_user_id: ownerId }); const ws = wRes.success ? (wRes.data?.wallets || []) : []
    if (!hasActivePayoutMethodFor(ws || [], 'subscription')) {
      payoutModalPending.value = (ws || []).some(w => w.setup_status === 'pending_review')
      payoutModalCountry.value = countryOfLocation(pgForm.value.location_id) || ''
      if (!isPlatformSide.value) {
        // HARD GATE: the provider cannot submit this listing yet. Nothing saves.
        payoutModal.value = true
        pushToast(payoutModalPending.value
          ? 'Your membership payout method is still under Bookboll review — you can submit this listing once it\'s approved.'
          : 'Connect a membership payout method first — then you can submit this listing.', 'error')
        return
      }
      pgForcedInactive.value = true   // platform-side save → 'inactive'; payout nudge after save
    }
  }
  if (!hasValidPlan()) {
    pgTriedSave.value = true
    pgOpen.value = 'pricing'   // price lives in the Pricing section
    pushToast('Set a price for this program.', 'error')
    return
  }
  // MOAT: every program must carry an incentive — a free trial (trial mode) or a
  // discount (longer ongoing plans). "None" is never allowed.
  const hasTrial = Number(pgForm.value.free_trial_days) > 0 || Number(pgForm.value.free_trial_sessions) > 0
  const hasOffer = offerMode.value === 'discount' ? Number(pgForm.value.discount_months) > 0 : hasTrial
  if (!hasOffer) {
    pgTriedSave.value = true
    pgOpen.value = 'pricing'   // the trial/discount offer lives in the Pricing section
    pushToast(offerMode.value === 'discount' ? 'Pick a discount offer for this plan.' : 'Pick a free trial for this program.', 'error')
    return
  }
  // The trial agreement is required whenever the program offers a free trial (trial mode).
  const offersTrial = offerMode.value === 'trial' && hasTrial
  const needsTrialAcceptance = offersTrial && (!pgEditing.value || pgForm.value.trial_terms_version !== TRIAL_TERMS_VERSION)
  if (needsTrialAcceptance) {
    trialAgreementChecked.value = false
    trialAgreementModal.value = true
  } else {
    pgTrialAccepted.value = true
    saveProgram()
  }
}

function confirmTrialAgreement() {
  if (!trialAgreementChecked.value) return
  trialAgreementModal.value = false
  pgTrialAccepted.value = true
  saveProgram()
}

async function saveProgram() {
  pgTriedSave.value = true
  if (!pgForm.value.name || !pgForm.value.location_id || !pgForm.value.category || !pgForm.value.sport_category) {
    pushToast('Please fill in the program name, location, category and type.', 'error')
    return
  }
  if (!hasValidPlan()) {
    pushToast('Set a price for this program.', 'error')
    return
  }

  // Block if the program's city has the Classes module disabled
  const pgLoc  = locations.value.find(l => l.id === pgForm.value.location_id)
  const pgCity = cities.value.find(c => cid(c.id) === cid(pgLoc?.city_id))
  if (pgCity && pgCity.module_classes === false) {
    pgModuleError.value = `Membership programs are disabled for ${pgCity.name}. A Super Admin must enable the "Memberships" module for this city in Countries & Cities before programs can be created here.`
    return
  }
  pgModuleError.value = ''

  // derive owner from location if not set
  if (!pgForm.value.owner_user_id) {
    const loc = locations.value.find(l => l.id === pgForm.value.location_id)
    if (loc && loc.owner_user_id) pgForm.value.owner_user_id = loc.owner_user_id
  }
  if (!pgTrialAccepted.value) return

  // enforce min 20% commission
  const commission = Math.max(0, Number(pgForm.value.commission_percentage ?? PLATFORM_COMMISSION))
  const isAdmin = isSuperAdmin.value || isCountryAdmin.value || role.value === 'admin'
  const now = new Date().toISOString()
  const needsAcceptanceRecord = !pgEditing.value || pgForm.value.trial_terms_version !== TRIAL_TERMS_VERSION

  // MOAT incentive (mutually exclusive by mode): trial mode keeps the free trial
  // (days OR 1 session); discount mode keeps "N months off". Persist only the active one.
  const interval = pgForm.value.billing_interval || 'monthly'
  const mode = offerMode.value
  const trialDays = mode === 'trial' ? (Number(pgForm.value.free_trial_days) || 0) : 0
  const trialSessions = mode === 'trial' ? (Number(pgForm.value.free_trial_sessions) || 0) : 0
  const discountMonths = mode === 'discount' ? (Number(pgForm.value.discount_months) || 0) : 0

  // Gym = facility-access membership: no coach, capacity, sessions, session time or fixed
  // seasons. Normalise those so the row is clean and no calendar sessions are derived
  // (programSessions only generates when there's a session_time + days).
  const isGym = categoryIsGym(pgForm.value.category)

  const base = {
    owner_user_id: pgForm.value.owner_user_id,
    location_id: pgForm.value.location_id || null,
    // Programs are no longer tied to listings — they show on the calendar at their
    // location only and never block bookable slots. Always clear any old links.
    listing_ids: [],
    listing_id: null,
    name: pgForm.value.name,
    description: pgForm.value.description || '',
    sport_category: pgForm.value.sport_category || '',
    coach_name: isGym ? '' : (pgForm.value.coach_name || ''),
    monthly_price: Number(pgForm.value.monthly_price) || 0,
    currency: currencyForLocation(pgForm.value.location_id) || pgForm.value.currency || '',
    billing_interval: interval,
    sessions_per_week: isGym ? 0 : (Number(pgForm.value.sessions_per_week) || 1),
    session_duration_minutes: isGym ? 0 : (Number(pgForm.value.session_duration_minutes) || 60),
    day_of_week_mask: isGym ? [0, 1, 2, 3, 4, 5, 6] : (pgForm.value.day_of_week_mask || []),
    session_time: isGym ? null : (pgForm.value.session_time || '18:00'),
    capacity: isGym ? 0 : (Number(pgForm.value.capacity) || 12),
    commission_percentage: commission,
    free_trial_days: trialDays,
    free_trial_enabled: trialDays > 0 || trialSessions > 0,
    // No active payout method → keep it not-yet-live (hidden from the storefront) until
    // the provider connects a wallet. Otherwise honour the form's status.
    status: pgForcedInactive.value ? 'inactive' : (pgForm.value.status || 'active'),
    program_type: isGym ? 'ongoing' : (pgForm.value.program_type || 'ongoing'),
    duration_weeks: (!isGym && pgForm.value.program_type === 'fixed_duration') ? (Number(pgForm.value.duration_weeks) || null) : null,
    start_date: isGym ? null : (pgForm.value.start_date || null),
    end_date: isGym ? null : (pgForm.value.end_date || null),
    photo_url: pgForm.value.photo_url || (Array.isArray(pgForm.value.images) && pgForm.value.images[0]) || '',
    images: Array.isArray(pgForm.value.images) ? pgForm.value.images : [],
    trial_terms_accepted_at: now,
    trial_terms_version: TRIAL_TERMS_VERSION,
  }
  // Only persist colour if the DB column exists, so saves never break pre-migration.
  if (programColorSupported.value) base.color = pgForm.value.color || '#7c3aed'
  // Only persist the main category if the column exists (migration applied).
  if (programCategorySupported.value) base.category = normMembershipCategory(pgForm.value.category)
  // Audience: gym = access (everyone/ladies/men, default everyone); class/academy = age.
  if (programAudienceSupported.value) base.audience = isGym ? (pgForm.value.audience || 'everyone') : (pgForm.value.audience || null)
  // Gender policy (mixed/men/women) — only when the column exists so saves never break
  // pre-migration. Gyms have no second selector: their access audience derives it.
  if (programGenderSupported.value) {
    base.gender_policy = isGym
      ? ({ ladies: 'women', men: 'men' }[pgForm.value.audience] || 'mixed')
      : normGenderPolicy(pgForm.value.gender_policy)
  }
  // Placement (Bookboll marketplace / own website) — only if those columns exist.
  if (programPlacementSupported.value) {
    base.show_on_marketplace = pgForm.value.show_on_marketplace !== false
    base.show_on_provider_site = pgForm.value.show_on_provider_site !== false
  }
  // MOAT incentive columns — session-based trial + discount months (guarded by migration).
  if (programOfferSupported.value) {
    base.free_trial_sessions = trialSessions
    base.discount_months = discountMonths
  }
  // Legacy bonus_months is deprecated (replaced by discount_months) — clear it.
  if (programBonusSupported.value) base.bonus_months = 0
  // Optional custom sign-up questions (guarded by migration).
  if (programFormSupported.value) base.signup_form = asFields(pgForm.value.signup_form)
  // One-off joining / sign-up fee (guarded by migration). The recurring charge is
  // unchanged; customer/data.js applies this fee once at enrolment.
  if (programJoiningFeeSupported.value) base.joining_fee = Math.max(0, Number(pgForm.value.joining_fee) || 0)
  // A full Save (not a draft) always clears the draft flag so the row is a real program.
  if (programDraftSupported.value) base.is_draft = false

  // The API decides the approval staging and returns which path it took, so the
  // browser can't declare its own edit "operational" and skip review:
  //   full        whole program under review      content  live stays, content staged
  //   operational live now, nothing to review     admin    platform-admin edit
  const payload = { ...base }
  if (needsAcceptanceRecord) {
    payload.accept_trial_terms = true
    payload.terms_version = TRIAL_TERMS_VERSION
  }
  const res = pgEditing.value
    ? await updateProgram(pgEditing.value.id, payload)
    : await createProgram(payload)
  if (!res.success) {
    pushToast(res.message || (pgEditing.value ? 'Could not save the program.' : 'Could not create the program.'), 'error')
    return
  }
  const savedId = res.data?.program?.id || null
  const stageMode = res.data?.stage_mode || 'admin'

  // Audit the submission — only when something actually entered the queue. A purely
  // operational edit is live and never reviewed.
  if (savedId && !isAdmin && (stageMode === 'full' || stageMode === 'content')) {
    logAudit({
      action: 'submitted', entity_type: 'program', entity_id: savedId,
      details: stageMode === 'content'
        ? `Submitted updated content (title/description/photos) for “${pgForm.value.name}” for review`
        : `${pgEditing.value ? 'Resubmitted' : 'Submitted'} program “${pgForm.value.name}” for review`,
    })
  }

  clearDirty()
  clearFormDraft(programDraftKey())   // a real save supersedes any local draft
  registerDraftSaver(null)
  pgModal.value = false
  if (pgForcedInactive.value) {
    // Saved but NOT live (no payout method yet) — open the guided payout modal and say so.
    payoutModal.value = true
    pushToast(pgEditing.value
      ? '“' + pgForm.value.name + '” saved — connect a payout method to take it live.'
      : '“' + pgForm.value.name + '” created (not live yet) — connect a payout method to start enrolling members.', 'success')
  } else {
    pushToast(pgEditing.value ? 'Program updated' : 'Program created', 'success')
  }
  pgForcedInactive.value = false

  // Calendar slots are derived from the program's schedule now — nothing to
  // materialise. The calendar and booking form pick up the recurrence automatically.
  loadAll()
  // A programme counts as a listing on the setup checklist, so the guide has to
  // hear about it too — it is saved in a modal with no navigation.
  notifySetupChanged()
  emit('saved')   // let an embedding parent (Listings) refresh its program list
}

async function toggleProgramStatus(p) {
  const newStatus = p.status === 'active' ? 'inactive' : 'active'
  // Money-safety invariant: a program can only go LIVE if its owner has an active
  // membership payout method (otherwise a member could enrol with no way to pay the
  // provider). Deactivating is always free. Checked live so it can't be bypassed.
  if (newStatus === 'active') {
    const ownerId = p.owner_user_id || null
    if (ownerId) {
      const wRes = await fetchWallets({ owner_user_id: ownerId }); const ws = wRes.success ? (wRes.data?.wallets || []) : []
      if (!hasActivePayoutMethodFor(ws || [], 'subscription')) {
        payoutModalPending.value = (ws || []).some(w => w.setup_status === 'pending_review')
        payoutModalCountry.value = countryOfLocation(p.location_id) || ''
        payoutModal.value = true
        return
      }
    }
  }
  // Deactivating stops the recurrence, which frees its reserved calendar slots
  // automatically (they're derived, not stored).
  await setProgramState(p.id, { status: newStatus })
  loadAll()
}

function activeSubsForProgram(id) {
  return subscriptions.value.filter(s => s.program_id === id && (s.status === 'active' || s.trial_status === 'in_trial'))
}

// A program's run horizon. Programs no longer reserve listing slots, so a live ongoing
// program needs no extra line; only a fixed end date or an archived wind-down is shown.
function reservationHorizon(p) {
  if (!p.day_of_week_mask?.length || !p.session_time) return ''
  const fmtD = d => new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
  const live = p.status === 'active' && rowState(p) === 'active'
  if (live) {
    if (p.program_type === 'fixed_duration' && p.end_date) return 'Ends ' + fmtD(p.end_date)
    return ''   // ongoing & live → the schedule above already says everything
  }
  const today = new Date().toISOString().slice(0, 10)
  const wd = subscriptions.value
    .filter(s => s.program_id === p.id && (s.status === 'active' || s.trial_status === 'in_trial') && s.next_billing_at && s.next_billing_at >= today)
    .map(s => s.next_billing_at).sort().pop()
  return wd ? 'Winding down · until ' + fmtD(wd) : ''
}

// Archive = graceful wind-down: stop new sign-ups and renewals now, but keep current
// subscribers' slots reserved until their next billing date. Each plan is then cancelled
// (handled by windDownSweep when that date passes); records are always kept.
async function archiveProgram(p) {
  const res = await setProgramState(p.id, { status: 'inactive' })
  if (!res.success) { pushToast(res.message || 'Could not archive the program.', 'error'); return }
  pushToast(`“${p.name}” archived — winding down: no new sign-ups or renewals; current members keep their slots until their next billing date`, 'success')
  loadAll()
}

// Deleting a program with active members: offer a choice — archive (safe wind-down)
// or delete immediately (provider takes responsibility for refunds). delModal holds
// the target program + its active count; forceAck gates the immediate delete.
const delModal = ref(null)
const forceAck = ref(false)
function closeDelModal() { delModal.value = null; forceAck.value = false }

async function removeProgram(id) {
  const p = programs.value.find(x => x.id === id)
  const active = activeSubsForProgram(id)
  // Program has active/in-trial members → open the archive-or-delete-now choice modal.
  if (active.length) {
    forceAck.value = false
    delModal.value = { program: p, activeCount: active.length }
    return
  }
  // No active subscribers → safe to permanently delete.
  if (!(await askConfirm({
    title: 'Delete program?',
    message: `“${p?.name || 'This program'}” will be permanently removed (it has no active members). This cannot be undone.`,
    confirmText: 'Delete',
  }))) return
  if (await hardDeleteProgram(p)) {
    pushToast(`“${p?.name || 'Program'}” deleted`, 'success')
    loadAll()
  }
}

// ── Share a program: copy marketplace link + QR ───────────────────────────────
const shareRow = ref(null)      // { id, name, kind, url }
const shareQr = ref('')
const shareCopied = ref(false)
function programPublicUrl(row) {
  // The public site looks a program up by uuid. Sharing the numeric id produced
  // links like /programs/2 that 404'd for everyone who opened them.
  return `${appBaseUrl()}/programs/${row.uuid || row.id}`
}
async function openShare(row) {
  shareCopied.value = false
  const url = programPublicUrl(row)
  shareRow.value = { id: row.id, name: row.name, kind: 'academy', url }
  shareQr.value = ''
  try { shareQr.value = await QRCode.toDataURL(url, { width: 320, margin: 1, errorCorrectionLevel: 'M' }) } catch { shareQr.value = '' }
}
function closeShare() { shareRow.value = null; shareQr.value = ''; shareCopied.value = false }
async function copyShareLink() {
  if (!shareRow.value) return
  try {
    await navigator.clipboard.writeText(shareRow.value.url)
    shareCopied.value = true
    setTimeout(() => { shareCopied.value = false }, 1800)
  } catch { /* clipboard blocked — link is visible to copy manually */ }
}
function downloadQr() {
  if (!shareQr.value || !shareRow.value) return
  const a = document.createElement('a')
  a.href = shareQr.value
  a.download = `${(shareRow.value.name || 'bookboll').replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-qr.png`
  a.click()
}

// Shared hard-delete via Laravel (bookings + program; subs/sessions cascade).
async function hardDeleteProgram(p) {
  if (!p?.id) return false
  const res = await deleteProgramApi(p.id)
  if (!res.success) {
    pushToast(firstError(res, 'Could not delete program.'), 'error')
    return false
  }
  return true
}

async function archiveFromModal() {
  const p = delModal.value?.program
  closeDelModal()
  if (p) await archiveProgram(p)
}

async function forceDeleteProgram() {
  const p = delModal.value?.program
  if (!p || !forceAck.value) return
  const n = delModal.value.activeCount
  closeDelModal()
  if (await hardDeleteProgram(p)) {
    pushToast(`“${p.name}” deleted — ${n} member${n !== 1 ? 's' : ''} cancelled immediately.`, 'success')
    loadAll()
  }
}

/* pause / resume / send back (admin) */
const pauseModal = ref(false)
const pauseTarget = ref(null)
const pauseNote = ref('')
function openPauseProgram(p) {
  pauseTarget.value = p
  pauseNote.value = p.admin_note || ''
  pauseModal.value = true
}
async function confirmPauseProgram() {
  if (!pauseNote.value.trim()) return
  const id = pauseTarget.value.id
  pauseModal.value = false
  // Pausing makes the program not-live → its reserved slots free up automatically.
  await setProgramState(id, { eligibility_status: 'paused', admin_note: pauseNote.value.trim() })
  loadAll()
}
async function resumeProgram(p) {
  await setProgramState(p.id, { eligibility_status: 'eligible', admin_note: '' })
  loadAll()
}

function programStateLabel(p) {
  const st = rowState(p)
  if (st === 'active') return 'Active'
  if (st === 'paused') return 'Action required'
  if (st === 'resubmitted') return 'Under review'
  if (st === 'pending_new') return 'Awaiting approval'
  return st
}

function getChanges(row) {
  if (!row.previous_snapshot) return []
  return PROGRAM_FIELDS
    .map(f => {
      const before = String(row.previous_snapshot[f.key] ?? '')
      const after = String(row[f.key] ?? '')
      if (before !== after) return { label: f.label, before, after }
      return null
    })
    .filter(Boolean)
}

// All programs that belong to the current provider (unfiltered, for empty-state check).
// Match strictly by owner_user_id — every program now carries its owner, so this is
// deterministic regardless of the order locations load in.
const providerOwnPrograms = computed(() => {
  if (isPlatformSide.value) {
    // Super admin sees everything; country_admin / platform_staff only programs at
    // jurisdiction venues (or run by a provider in their countries).
    return allowedLocationIds.value
      ? programs.value.filter(p => inJurisdictionLoc(p.location_id) || inJurisdictionOwner(p.owner_user_id))
      : programs.value
  }
  if (!currentOwnerUserId.value) return []
  return programs.value.filter(p => p.owner_user_id === currentOwnerUserId.value)
})

// True when the current provider has at least one program (ignores active filters)
const providerHasAnyPrograms = computed(() => {
  if (isPlatformSide.value) return true
  return providerOwnPrograms.value.length > 0
})

// Wallet-first gate: a provider must have at least one active country wallet before
// they can create membership programs. Admins are never gated.
const hasAnyActiveWallet = computed(() => providerWallets.value.some(w => isWalletReady(w)))
const walletReady = computed(() =>
  isPlatformSide.value || hasAnyActiveWallet.value
)
// Per-country gate is OWNER-based (not role-based): a subscription program can only be
// created in a country where the program's OWNER (provider) has an ACTIVE wallet — the
// split pays out there. This applies even when a super/country admin creates it for a
// provider. Bookboll-owned locations (no owner) need no wallet.
const formLocationOwnerId = computed(() => {
  const loc = locations.value.find(l => l.id === pgForm.value.location_id)
  return loc ? (loc.owner_user_id || null) : null
})
const formLocationIsBookbollOwned = computed(() => {
  const loc = locations.value.find(l => l.id === pgForm.value.location_id)
  return !!loc && !loc.owner_user_id
})
// Wallets of the selected location's owner, loaded reactively so the inline warning is
// accurate for both providers and admins acting on a provider's behalf.
const formOwnerWallets = ref([])
async function loadFormOwnerWallets() {
  const ownerId = formLocationOwnerId.value
  if (!ownerId) { formOwnerWallets.value = []; return }
  // select('*') so newer columns (purpose, payout_paused) are seen whenever they exist.
  const wRes2 = await fetchWallets({ owner_user_id: ownerId }); const data = wRes2.success ? (wRes2.data?.wallets || []) : []
  formOwnerWallets.value = data || []
}
// NEW programs default their commission to the location country's "program" rate set on
// the Countries page (super admin), falling back to the platform default. Editing an
// existing program never touches its stored commission.
async function applyCountryProgramCommission() {
  if (pgEditing.value) return
  const countryId = countryOfLocation(pgForm.value.location_id)
  if (!countryId) return
  // commissionRates is a plain array from the Laravel reference-data call. The
  // Supabase query chain this replaced (.select().eq().maybeSingle()) threw on it,
  // the catch below ate the error, and every new programme quietly kept the
  // platform default no matter what the Countries page said.
  try {
    const refs = await loadReferenceData()
    const venue = refs.venues.find(v => cid(v.id) === cid(pgForm.value.location_id))
    const city = refs.cities.find(c => cid(c.id) === cid(venue?.city_id))
    const countryRate = refs.commissionRates.find(r => cid(r.country_id) === cid(countryId) && r.listing_type === 'program')?.rate_percent
    const rate = city?.commission_rates?.program ?? countryRate
    if (rate != null) pgForm.value.commission_percentage = Number(rate)
  } catch { /* keep the platform default */ }
}
watch(() => pgForm.value.location_id, () => { loadFormOwnerWallets(); applyCountryProgramCommission() })

const pgCountryId = computed(() => countryOfLocation(pgForm.value.location_id))
const pgCountryName = computed(() => countries.value.find(c => cid(c.id) === cid(pgCountryId.value))?.name || 'this country')
const pgProviderProgram = computed(() =>
  !!pgForm.value.location_id && !formLocationIsBookbollOwned.value && !!formLocationOwnerId.value)
// Blocks creation: the owner has no ACTIVE payout method anywhere (Stripe connected or
// manual approved). A merely-submitted manual method ('pending_review') still blocks.
const pgOwnerWalletMissing = computed(() =>
  pgProviderProgram.value && !formOwnerWallets.value.some(isWalletReady))
// Soft note only: owner has an active method, just not a wallet for THIS country (payout
// falls back to another country's wallet, with FX).
const pgLocalWalletMissing = computed(() =>
  pgProviderProgram.value && formOwnerWallets.value.some(isWalletReady) &&
  !isCountryWalletReady(formOwnerWallets.value, pgCountryId.value))
// The owner submitted a manual method that's still awaiting Bookboll approval.
const pgOwnerWalletPending = computed(() =>
  pgOwnerWalletMissing.value && formOwnerWallets.value.some(w => w.setup_status === 'pending_review'))

// Gate: providers can now build a program BEFORE connecting a payout method (payout is the
// last step). The page shows once they have >=1 program; brand-new providers get the
// create-first onboarding card. No wallet requirement to reach the page anymore.
const showProgramPages = computed(() =>
  isPlatformSide.value || providerHasAnyPrograms.value
)

// Per-program go-live readiness — what (if anything) stands between a program and taking
// money. Derived purely from already-loaded data (no new column). Powers the row badge in
// this page and in Listings' program cards.
function programReadiness(p) {
  if (!p) return { live: false, label: '', reason: '', action: '' }
  const mine = !isPlatformSide.value && p.owner_user_id === currentOwnerUserId.value
  if (p.status !== 'active') {
    // Inactive: most commonly because there's no payout method yet.
    if (mine && !hasAnyActiveWallet.value)
      return { live: false, label: 'Not live', reason: 'Connect a payout method to go live', action: 'wallet' }
    return { live: false, label: 'Not live', reason: 'Switched off — turn on to enrol members', action: 'activate' }
  }
  if ((p.approval_status || 'approved') !== 'approved') {
    if (p.eligibility_status === 'paused')
      return { live: false, label: 'Action needed', reason: p.admin_note || 'Paused by Bookboll — open and resubmit', action: '' }
    return { live: false, label: 'In review', reason: 'Awaiting Bookboll approval', action: '' }
  }
  return { live: true, label: 'Live', reason: 'Enrolling members', action: '' }
}

// Scope only (no user filters): platform roles → jurisdiction; providers → own.
const visiblePrograms = computed(() => programs.value.filter(p => {
  if (isPlatformSide.value) {
    return inJurisdictionLoc(p.location_id) || inJurisdictionOwner(p.owner_user_id)
  }
  return p.owner_user_id === currentOwnerUserId.value
}))

const filteredPrograms = computed(() => {
  const q = search.value.toLowerCase()
  return visiblePrograms.value.filter(p => {
    if (filterState.value.length && !filterState.value.includes(programStateKey(p))) return false
    if (!idIn(countryOfLocation(p.location_id), filterCountry.value)) return false
    if (filterCity.value.length) {
      const loc = locations.value.find(l => l.id === p.location_id)
      if (!idIn(loc?.city_id, filterCity.value)) return false
    }
    if (!q) return true
    return p.name.toLowerCase().includes(q) ||
      (p.sport_category || '').toLowerCase().includes(q) ||
      (p.coach_name || '').toLowerCase().includes(q) ||
      // Platform admins see Provider + Location columns — search matches them too.
      (isPlatformSide.value && (
        providerName(p.owner_user_id).toLowerCase().includes(q) ||
        locationName(p.location_id).toLowerCase().includes(q)))
  })
})

// ── Export ──────────────────────────────────────────────────────────────────
const showExportModal = ref(false)
function doExport(useFilters) {
  if (!canExport.value) return
  if (!requirePremiumFeature('exports')) { showExportModal.value = false; return }
  const rows = useFilters ? filteredPrograms.value : providerOwnPrograms.value
  downloadCSV(rows, [
    { label: 'ID',          key: 'id' },
    { label: 'Name',        key: 'name' },
    { label: 'Category',    key: 'category' },
    { label: 'Type',        key: 'sport_category' },
    { label: 'Coach',       key: 'coach_name' },
    { label: 'Location',    fn: r => locationName(r.location_id) },
    { label: 'Monthly Price', fn: r => r.monthly_price ?? '' },
    { label: 'Currency',    key: 'currency' },
    { label: 'Capacity',    key: 'capacity' },
    { label: 'Status',      key: 'status' },
    { label: 'Approval',    key: 'approval_status' },
  ], 'programs')
  showExportModal.value = false
}

const { page: pgPage, perPage: pgPerPage, totalPages: pgTotalPages, paginated: paginatedPrograms, visiblePages: pgVisiblePages, goTo: pgGoTo } = usePagination(filteredPrograms)

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
function formatDays(mask) { return (mask || []).map(d => DAY_NAMES[d]).join(', ') }
function fmtMoney(n, cur = '') { return [cur, Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })].filter(Boolean).join(' ') }

const BILLING_INTERVALS = [
  { value: 'weekly',    label: 'Weekly' },
  { value: 'biweekly',  label: 'Bi-weekly' },
  { value: 'monthly',   label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'yearly',    label: 'Yearly' },
]
function intervalLabel(v) {
  if (v === 'one_time') return 'One-time'
  return BILLING_INTERVALS.find(i => i.value === v)?.label || 'Monthly'
}


const providerPct = computed(() => 100 - Number(pgForm.value.commission_percentage ?? PLATFORM_COMMISSION))
// What the member is actually charged on the FIRST cycle: the discounted amount in
// discount mode, else the list price. (During a free trial nothing is charged up front —
// this is what's taken once billing starts.) Keeps the payout preview honest instead of
// always showing the list-price split even for discounted plans.
const effectiveFirstCharge = computed(() =>
  offerMode.value === 'discount' && Number(pgForm.value.discount_months) > 0
    ? discountedAmount.value
    : Number(pgForm.value.monthly_price) || 0
)

// ── Currency-aware price suggestion ───────────────────────────────────────────
// A gentle "Suggested: X" hint (never enforced) so a provider isn't staring at an
// empty price box. Base monthly figures are in AED per category; other currencies are
// scaled by a rough purchasing-power factor. Client-only — no schema, no money moves.
const SUGGESTED_MONTHLY_AED = { gym: 250, class: 300, academy: 400 }
const CURRENCY_FACTOR = {
  AED: 1, SAR: 1, QAR: 1, USD: 0.27, EUR: 0.25, GBP: 0.22,
  KWD: 0.083, BHD: 0.10, OMR: 0.10, EGP: 13, INR: 22, PKR: 75,
}
function roundNice(n) {
  if (n >= 1000) return Math.round(n / 100) * 100
  if (n >= 100) return Math.round(n / 25) * 25
  return Math.max(1, Math.round(n / 5) * 5)
}
const suggestedMonthlyPrice = computed(() => {
  const base = SUGGESTED_MONTHLY_AED[normMembershipCategory(pgForm.value.category)] || 300
  const cur = pgForm.value.currency || ''
  const factor = CURRENCY_FACTOR[cur] != null ? CURRENCY_FACTOR[cur] : 1
  return roundNice(base * factor)
})
function applySuggestedPrice() { pgForm.value.monthly_price = suggestedMonthlyPrice.value }

// When start + end are both set → auto-calculate weeks
watch([() => pgForm.value.start_date, () => pgForm.value.end_date], ([start, end]) => {
  if (pgForm.value.program_type !== 'fixed_duration') return
  if (start && end && end > start) {
    const days = (new Date(end) - new Date(start)) / (1000 * 60 * 60 * 24)
    pgForm.value.duration_weeks = Math.round(days / 7)
  } else if (!end) {
    pgForm.value.duration_weeks = null
  }
})

// When start + weeks are set and end is blank → auto-fill end
watch(() => pgForm.value.duration_weeks, (weeks) => {
  if (pgForm.value.program_type !== 'fixed_duration') return
  if (!pgForm.value.start_date || !weeks) return
  if (pgForm.value.end_date) return  // already set by user
  const d = new Date(pgForm.value.start_date)
  d.setDate(d.getDate() + Number(weeks) * 7)
  pgForm.value.end_date = d.toISOString().slice(0, 10)
})

// Clear date/duration fields when switching type
watch(() => pgForm.value.program_type, (type) => {
  if (type === 'ongoing') {
    pgForm.value.duration_weeks = null
    pgForm.value.start_date = ''
    pgForm.value.end_date = ''
  }
})

// Auto-set currency and owner from selected location
watch(() => pgForm.value.location_id, (locId) => {
  if (!locId) return
  pgForm.value.currency = currencyForLocation(locId) || pgForm.value.currency || ''
  const loc = locations.value.find(l => l.id === locId)
  if (loc?.owner_user_id && !pgEditing.value) {
    pgForm.value.owner_user_id = loc.owner_user_id
  }
})

const canSaveProgram = computed(() =>
  !!pgForm.value.name
  && !!pgForm.value.location_id
)

</script>

<template>
  <ExportDialog :open="showExportModal" title="Export programs" noun="programs" :filtered-count="filteredPrograms.length" :all-count="providerOwnPrograms.length" @close="showExportModal = false" @export="doExport" />
  <div :class="embedded ? 'cs-embed-host' : 'page'">
    <!-- Page chrome (table/header) — hidden when embedded as a form-only host in Listings -->
    <template v-if="!embedded">
    <div class="page-header">
      <div>
        <h1 class="page-title">Memberships</h1>
        <div class="page-subtitle">Manage recurring classes, passes and academy programs.</div>
      </div>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button v-if="canExport" class="btn btn-secondary" @click="showExportModal = true">
          <Download :size="14" />Export
        </button>
        <button v-if="canManagePrograms" class="btn btn-primary" @click="openNewProgram()"><Plus :size="14" />New Program</button>
      </div>
    </div>

    <!-- ── Provider with no programs: onboarding empty state (gates page) ──── -->
    <div v-if="!showProgramPages" class="cs-no-programs-state">
      <div class="cs-nps-icon"><GraduationCap :size="40" /></div>
      <div class="cs-nps-title">Create your first membership program</div>
      <div class="cs-nps-body">
        Set up your membership payout method, then add a program with its schedule and price. New members receive a required trial of 3–30 days, based on the billing interval. The platform commission is {{ PLATFORM_COMMISSION }}%.
      </div>
      <button v-if="canManagePrograms" class="btn btn-primary" @click="openNewProgram()"><Plus :size="15" />Create First Program</button>
      <div v-else class="muted" style="font-size:13px">Ask your provider admin to set up a program — your role can't create programs.</div>
    </div>

    <!-- ── Page content (hidden until the provider has a program) ──────────── -->
    <template v-if="showProgramPages">
    <!-- Non-blocking nudge: programs are built, just not live until payout is connected. -->
    <div v-if="!walletReady && canManagePrograms" class="cs-payout-banner">
      <div class="cs-pb-ic"><WalletIcon :size="18" /></div>
      <div class="cs-pb-txt"><strong>Your programs aren't live yet.</strong> Connect a payout method to start enrolling members and earning — it's the last setup step.</div>
      <a href="/wallet" class="btn btn-secondary btn-sm cs-pb-cta"><WalletIcon :size="14" />Connect payout</a>
    </div>
    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="fbCountries"
      :cities="fbCities"
      :extra-count="filterState.length ? 1 : 0"
      search-placeholder="Search programs..."
      @clear="filterState = []; filterCountry = []; filterCity = []; search = ''"
    >
      <div class="cs-filter-slot">
        <label class="cs-filter-label">Program status</label>
        <MultiSelect v-model="filterState" placeholder="All statuses" :options="stateOptions" />
      </div>
    </FilterBar>

    <!-- PROGRAMS -->
    <div>
      <div v-if="!filteredPrograms.length" class="card empty">
        {{ (isSuperAdmin || isCountryAdmin) ? 'No membership programs yet.' : providerHasAnyPrograms ? 'No programs match your filters.' : '' }}
      </div>
      <div v-else class="card table-wrap" style="padding:0;">
        <table class="programs-table">
          <thead>
            <tr>
              <th>Program</th>
              <th>Schedule</th>
              <th>Capacity</th>
              <th>Price</th>
              <th>Location</th>
              <th v-if="role === 'admin'">Provider</th>
              <th>Status</th>
              <th>Approval</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in paginatedPrograms" :key="p.id" :class="{ 'prog-row-inactive': p.status === 'inactive' }">
              <td>
                <div class="prog-name">{{ p.name }}</div>
                <div class="prog-sub">{{ p.sport_category }}<span v-if="p.coach_name"> · {{ p.coach_name }}</span></div>
                <div v-if="rowState(p) === 'paused' && p.admin_note" class="prog-note">
                  <AlertTriangle :size="11" />{{ p.admin_note }}
                </div>
              </td>
              <td>
                <div class="prog-sub">{{ formatDays(p.day_of_week_mask) }} {{ p.session_time ? fmt12(p.session_time) : '' }}</div>
                <div class="prog-sub muted">{{ p.sessions_per_week }}×/wk · {{ p.session_duration_minutes }}min</div>
                <div v-if="reservationHorizon(p)" class="prog-reserved"><CalendarCheck :size="10" />{{ reservationHorizon(p) }}</div>
              </td>
              <td>
                <div class="prog-sub">{{ subsForProgram(p.id) }} / {{ p.capacity }}</div>
              </td>
              <td>
                <div class="prog-sub" style="font-weight:600;">{{ fmtMoney(p.monthly_price, p.currency) }}</div>
                <div class="prog-sub muted">/ {{ intervalLabel(p.billing_interval) }}</div>
              </td>
              <td>
                <div class="prog-sub">{{ locationName(p.location_id) }}</div>
                <div v-if="(p.listing_ids || []).length" class="prog-sub muted">{{ (p.listing_ids || []).map(id => listingName(id)).join(', ') }}</div>
              </td>
              <td v-if="role === 'admin'">
                <div class="prog-sub">{{ providerName(p.owner_user_id) }}</div>
              </td>
              <td>
                <span class="pg-ready" :class="programReadiness(p).live ? 'ready-live' : 'ready-off'" :title="programReadiness(p).reason">
                  <Check v-if="programReadiness(p).live" :size="11" /><AlertTriangle v-else :size="11" />
                  {{ programReadiness(p).label }}
                </span>
                <div v-if="!programReadiness(p).live && programReadiness(p).reason" class="pg-ready-reason">{{ programReadiness(p).reason }}</div>
              </td>
              <td>
                <span v-if="p.is_draft" class="pg-state-badge state-draft">
                  <Edit2 :size="11" />Draft
                </span>
                <span v-else class="pg-state-badge" :class="`state-${rowState(p)}`">
                  <RefreshCw v-if="rowState(p) === 'resubmitted'" :size="11" />
                  <AlertTriangle v-else-if="rowState(p) === 'paused'" :size="11" />
                  <Clock v-else-if="rowState(p) === 'pending_new'" :size="11" />
                  <Check v-else :size="11" />
                  {{ programStateLabel(p) }}
                </span>
              </td>
              <td>
                <div class="row-actions">
                  <button v-if="canManagePrograms" class="btn-icon" title="Edit program" @click="openEditProgram(p)"><Edit2 :size="15" /></button>
                  <button v-if="canManagePrograms" class="btn-icon" title="Duplicate program" @click="openDuplicateProgram(p)"><Copy :size="15" /></button>
                  <button class="btn-icon" title="Share program (link + QR)" @click="openShare(p)"><Share2 :size="15" /></button>
                  <button
                    v-if="canManagePrograms"
                    class="btn-icon"
                    :title="p.status === 'active' ? 'Hide program' : 'Show program'"
                    :style="p.status === 'inactive' ? 'color:#059669' : 'color:#64748b'"
                    @click="toggleProgramStatus(p)"
                  >
                    <EyeOff v-if="p.status === 'active'" :size="15" />
                    <Eye v-else :size="15" />
                  </button>
                  <button
                    v-if="(isSuperAdmin || isCountryAdmin) && rowState(p) !== 'paused'"
                    class="btn-icon"
                    style="color: var(--warning-ink)"
                    title="Pause and request changes"
                    @click="openPauseProgram(p)"
                  ><PauseCircle :size="15" /></button>
                  <button
                    v-if="(isSuperAdmin || isCountryAdmin) && rowState(p) === 'paused'"
                    class="btn-icon"
                    style="color: var(--positive-ink)"
                    title="Resume"
                    @click="resumeProgram(p)"
                  ><PlayCircle :size="15" /></button>
                  <button v-if="canManagePrograms" class="btn-icon danger" title="Delete program" @click="removeProgram(p.id)"><Trash2 :size="15" /></button>
                  <span v-if="!canManagePrograms && !canManageBookings" class="muted" style="font-size:12px">View only</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        v-if="filteredPrograms.length > 0"
        :page="pgPage" :perPage="pgPerPage" :totalPages="pgTotalPages"
        :total="filteredPrograms.length" :visiblePages="pgVisiblePages"
        @update:perPage="pgPerPage = $event"
        @goTo="pgGoTo"
      />
    </div>

    </template>
    </template><!-- /page chrome (v-if !embedded) -->

    <!-- PROGRAM MODAL -->
    <teleport to="body">
    <div v-if="pgModal" class="bb-drawer-backdrop" style="z-index:3000" @click.self="requestCloseProgram">
      <div class="bb-drawer" v-dialog="requestCloseProgram" @input="markDirty" @change="markDirty">
        <div class="bb-drawer-header">
          <div>
            <div class="bb-drawer-title">{{ pgEditing ? 'Edit Membership Listing' : 'New Membership Listing' }}</div>
            <div class="bb-drawer-subtitle">Schedule, pricing, colour &amp; members</div>
          </div>
          <button class="bb-drawer-close" @click="requestCloseProgram" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="bb-drawer-body">
          <!-- resubmission banner when paused by admin -->
          <div v-if="pgEditing && rowState(pgEditing) === 'paused'" class="banner banner-warning">
            <AlertTriangle :size="14" />
            <div>
              <strong>This program was paused by an admin.</strong>
              <div v-if="pgEditing.admin_note" class="banner-sub">Reason: &ldquo;{{ pgEditing.admin_note }}&rdquo;</div>
              <div class="banner-sub">Update the fields below and save — your changes will go back to admin for review.</div>
            </div>
          </div>
          <div v-else-if="pgEditing && rowState(pgEditing) === 'resubmitted'" class="banner banner-info">
            <RefreshCw :size="14" />
            <div><strong>Under review.</strong><div class="banner-sub">Your changes are with the admin. They will approve or send back with a note.</div></div>
          </div>
          <div v-else-if="pgEditing && rowState(pgEditing) === 'pending_new'" class="banner banner-info">
            <Clock :size="14" />
            <div><strong>Awaiting first approval.</strong></div>
          </div>
          <div v-else-if="pgEditing && pgContentUnderReview" class="banner banner-info">
            <Clock :size="14" />
            <div><strong>Your membership is live.</strong><div class="banner-sub">The new title, description or photos below are awaiting Bookboll approval — your current ones keep showing until then. Price, schedule and other settings you change here go live right away.</div></div>
          </div>

          <!-- Starter templates — quick presets for a fresh program (client-only patch). -->
          <div v-if="!pgEditing" class="pg-templates">
            <span class="pg-templates-label">Start from a template</span>
            <div class="pg-template-chips">
              <button
                v-for="tpl in PROGRAM_TEMPLATES" :key="tpl.key" type="button"
                class="pg-template-chip" :class="{ active: pgActiveTemplate === tpl.key }"
                @click="applyTemplate(tpl)"
              ><Repeat :size="13" />{{ tpl.label }}</button>
            </div>
          </div>

          <section class="pg-section" :class="{ open: pgOpen === 'details' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'details'" @click="pgOpen = pgOpen === 'details' ? '' : 'details'">
            <div class="pg-section-titles"><div class="pg-section-name">Program Details</div><div class="pg-section-sub">Name, location, category, description &amp; colour</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'details' }" />
          </button>
          <div v-show="pgOpen === 'details'" class="pg-section-body">
          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">Program Name <span class="req-star">*</span></label>
              <input v-model="pgForm.name" :class="{ 'input-invalid': pgTriedSave && !pgForm.name }" placeholder="e.g. Junior Football Skills" />
              <div v-if="pgTriedSave && !pgForm.name" class="field-error-text"><Info :size="12" />Program name is required</div>
            </div>
            <div class="form-group">
              <div class="label-with-action">
                <label class="form-label">Location <span class="req-star">*</span></label>
                <button type="button" class="link-btn" @click="openNewLocation"><Plus :size="12" />New location</button>
              </div>
              <select v-model="pgForm.location_id" :class="{ 'input-invalid': pgTriedSave && !pgForm.location_id }">
                <option value="">Select location</option>
                <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
              <div v-if="pgTriedSave && !pgForm.location_id" class="field-error-text"><Info :size="12" />Location is required</div>
              <div v-else-if="pgOwnerWalletPending" class="cs-wallet-warn">
                <Info :size="12" />Manual payout method is <b>under review</b> — you can run subscription programs once Bookboll approves it.
              </div>
              <div v-else-if="pgOwnerWalletMissing" class="cs-wallet-warn">
                <Info :size="12" />No active payout method yet — <a href="/wallet">set one up</a> before running a subscription program.
              </div>
              <div v-else-if="pgLocalWalletMissing" class="cs-wallet-note">
                <Info :size="12" />A {{ pgCountryName }} wallet will be set up automatically using your payout method, so you're paid in local currency.
              </div>
            </div>
            <div class="form-group"><label class="form-label">Type <span class="req-star">*</span></label>
              <!-- One field (like the venue listing form): search/pick a TYPE; its category is
                   derived and shown read-only below. -->
              <div class="combo" @click.stop>
                <input
                  class="combo-input"
                  :class="{ 'input-invalid': pgTriedSave && !pgForm.sport_category }"
                  :value="pgSportOpen ? pgSportQuery : pgForm.sport_category"
                  @input="e => { pgSportQuery = e.target.value; pgSportOpen = true }"
                  @focus="onPgSportFocus"
                  @blur="onPgSportBlur"
                  placeholder="Search a type — e.g. Football, Yoga, CrossFit…"
                />
                <ChevronDownIcon :size="16" class="combo-caret" :class="{ rot: pgSportOpen }" />
                <div v-if="pgSportOpen" class="combo-menu">
                  <!-- Add-new flow: name comes from what was typed; provider picks ONE category. -->
                  <div v-if="pgAddingType" class="combo-addpanel" @mousedown.prevent>
                    <div class="combo-add-title">Add “{{ pgNewTypeName }}” — pick a category</div>
                    <div class="combo-add-cats">
                      <button v-for="c in MEMBERSHIP_CATEGORIES" :key="c.key" type="button" class="combo-add-cat" @mousedown.prevent="confirmPgAddType(c.key)">{{ c.label }}</button>
                    </div>
                    <div class="combo-add-note">Added to your program now; joins the catalog once the program is approved.</div>
                  </div>
                  <template v-else>
                    <div v-if="!pgFilteredTypes.length && !pgCanAddType" class="combo-empty">No matches</div>
                    <button
                      v-for="opt in pgFilteredTypes"
                      :key="opt.cat + '::' + opt.type"
                      type="button"
                      class="combo-item"
                      :class="{ active: opt.type === pgForm.sport_category && opt.cat === pgForm.category }"
                      @mousedown.prevent="selectPgType(opt)"
                    >
                      <span class="combo-item-name">{{ opt.type }}</span>
                      <span class="combo-cat">{{ opt.catLabel }}</span>
                    </button>
                    <button v-if="pgCanAddType" type="button" class="combo-add" @mousedown.prevent="startPgAddType">
                      <Plus :size="14" /> Add “{{ pgNewTypeName }}” as a new type
                    </button>
                  </template>
                </div>
              </div>
              <div v-if="pgTriedSave && !pgForm.sport_category" class="field-error-text"><Info :size="12" />Type is required</div>
            </div>
            <div class="form-group"><label class="form-label">Category</label>
              <div class="readonly-field" :class="{ empty: !pgForm.sport_category }">
                {{ pgForm.sport_category ? pgDerivedCategoryLabel : 'Set automatically from the type' }}
              </div>
              <div v-if="pgForm.category && !programCategorySupported" class="field-hint">Apply the pending DB migration to persist it.</div>
            </div>
            <!-- Audience — age for class/academy; access (everyone / ladies / men) for gym. -->
            <div v-if="pgShowAudience" class="form-group"><label class="form-label">{{ pgIsGym ? 'Who can attend?' : 'Who is it for?' }} <span v-if="pgNeedsAudience" class="req-star">*</span></label>
              <div class="aud-pills">
                <button v-for="a in pgAudienceOptions" :key="a.key" type="button" class="aud-pill" :class="{ active: pgForm.audience === a.key }" @click="pgForm.audience = a.key">{{ a.label }}</button>
              </div>
              <div v-if="pgTriedSave && pgNeedsAudience && !pgForm.audience" class="field-error-text"><Info :size="12" />Select who this program is for</div>
              <div v-else-if="pgForm.audience && !programAudienceSupported" class="field-hint">Apply the pending DB migration to persist it.</div>
              <div v-else-if="pgIsGym" class="field-hint">Shown on your page as a Mixed / Men only / Women only tag.</div>
            </div>
            <!-- Gender policy — who can join (class/academy only; gyms derive it from "Who can attend?"). -->
            <div v-if="!pgIsGym" class="form-group"><label class="form-label">Who can join?</label>
              <div class="aud-pills">
                <button v-for="g in GENDER_POLICIES" :key="g.key" type="button" class="aud-pill" :class="{ active: pgForm.gender_policy === g.key }" @click="pgForm.gender_policy = g.key">{{ g.label }}</button>
              </div>
              <div v-if="pgForm.gender_policy !== 'mixed' && !programGenderSupported" class="field-hint">Apply the pending DB migration to persist it.</div>
              <div v-else class="field-hint">Shown as a tag on your program's card and page.</div>
            </div>
            <div class="form-group"><label class="form-label">Status</label>
              <select v-model="pgForm.status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea v-model="pgForm.description" rows="3" placeholder="Describe the membership program, target age, coaching philosophy..."></textarea>
          </div>

          <!-- Calendar colour -->
          <div class="form-group">
            <label class="form-label">Calendar colour</label>
            <div class="pg-color-row">
              <button v-for="c in PROGRAM_COLORS" :key="c" type="button" class="pg-swatch" :class="{ active: pgForm.color === c }" :style="{ background: c }" :title="c" @click="pgForm.color = c"></button>
              <label class="pg-swatch pg-swatch-custom" :class="{ active: pgForm.color && !PROGRAM_COLORS.includes(pgForm.color) }" title="Custom colour">
                <input type="color" v-model="pgForm.color" />
              </label>
            </div>
            <div class="field-hint">Used for this program's sessions in the calendar.<span v-if="!programColorSupported"> Apply the pending DB migration to persist it.</span></div>
          </div>
          </div><!-- /.pg-section-body Program Details -->
          </section>

          <section class="pg-section" :class="{ open: pgOpen === 'schedule' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'schedule'" @click="pgOpen = pgOpen === 'schedule' ? '' : 'schedule'">
            <div class="pg-section-titles"><div class="pg-section-name">Schedule</div><div class="pg-section-sub">{{ pgIsGym ? 'Access during the venue’s opening hours' : 'Duration, session days, time &amp; capacity' }}</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'schedule' }" />
          </button>
          <div v-show="pgOpen === 'schedule'" class="pg-section-body">

          <!-- Gym = facility access: no sessions/seasons; members come during opening hours. -->
          <div v-if="pgIsGym" class="gym-sched-note">
            <Info :size="15" />
            <div>
              <strong>Gym memberships give access during the venue’s opening hours.</strong>
              There are no fixed class sessions, capacity or coach to set. Set the opening hours on the
              <a href="/listings">venue/location</a> — customers see them on your site.
            </div>
          </div>

          <!-- Class / Academy: full session schedule -->
          <template v-else>
          <!-- Duration section -->
          <div class="duration-section">
            <div class="duration-toggle">
              <button type="button" class="duration-opt" :class="{ active: pgForm.program_type === 'ongoing' }" @click="pgForm.program_type = 'ongoing'">
                <InfinityIcon :size="15" />Ongoing
              </button>
              <button type="button" class="duration-opt" :class="{ active: pgForm.program_type === 'fixed_duration' }" @click="pgForm.program_type = 'fixed_duration'">
                <Timer :size="15" />Fixed duration
              </button>
            </div>
            <div v-if="pgForm.program_type === 'ongoing'" class="duration-hint">
              Program runs indefinitely — monthly billing continues until the subscriber cancels.
            </div>
            <div v-else class="duration-fields">
              <div class="grid grid-3" style="gap:12px;">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Start date</label>
                  <input type="date" v-model="pgForm.start_date" />
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">End date</label>
                  <input type="date" v-model="pgForm.end_date" :min="pgForm.start_date || undefined" />
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Duration (weeks)</label>
                  <input
                    type="number"
                    v-model="pgForm.duration_weeks"
                    min="1"
                    placeholder="auto"
                    :class="{ 'input-auto': pgForm.start_date && pgForm.end_date }"
                    :title="pgForm.start_date && pgForm.end_date ? 'Auto-calculated from start and end dates' : ''"
                  />
                  <div v-if="pgForm.start_date && pgForm.end_date" class="field-hint">Auto-calculated</div>
                </div>
              </div>
              <div v-if="pgForm.start_date && pgForm.end_date && pgForm.duration_weeks" class="duration-summary">
                <CalendarDays :size="13" />
                {{ pgForm.start_date }} → {{ pgForm.end_date }} &middot; {{ pgForm.duration_weeks }} weeks
              </div>
              <div v-else-if="!pgForm.start_date && !pgForm.end_date" class="duration-hint" style="margin-top:8px;">
                Set start and end dates to auto-calculate the duration, or enter weeks manually.
              </div>
            </div>
          </div>

          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">Session Duration</label>
              <select v-model.number="pgForm.session_duration_minutes">
                <option v-for="d in SLOT_DURATIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Session Time</label>
              <input type="time" step="900" v-model="pgForm.session_time" />
            </div>
            <div class="form-group"><label class="form-label">Capacity</label>
              <input type="number" v-model="pgForm.capacity" />
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Days of Week <span class="req">*</span></label>
            <div class="day-picker" :class="{ 'dp-invalid': pgTriedSave && !(pgForm.day_of_week_mask || []).length }">
              <button
                v-for="(d, i) in DAY_NAMES" :key="i" type="button"
                class="day-pill"
                :class="{ active: pgForm.day_of_week_mask?.includes(i) }"
                @click="toggleDay(i)"
              >{{ d }}</button>
            </div>
          </div>
          </template>

          </div><!-- /.pg-section-body Schedule -->
          </section>

          <section class="pg-section" :class="{ open: pgOpen === 'pricing' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'pricing'" @click="pgOpen = pgOpen === 'pricing' ? '' : 'pricing'">
            <div class="pg-section-titles"><div class="pg-section-name">Pricing</div><div class="pg-section-sub">Plans, offers &amp; commission</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'pricing' }" />
          </button>
          <div v-show="pgOpen === 'pricing'" class="pg-section-body">

          <label class="form-label">Billing &amp; offer <span class="req-star">*</span></label>
          <div class="plan-card" :class="{ 'plan-invalid': pgTriedSave && !hasValidPlan() }">

            <!-- TOP: how the program is billed -->
            <!-- FIXED-DURATION season: pay in full or monthly installments (no recurring interval) -->
            <div v-if="isFixed" class="plan-top">
              <div class="plan-field">
                <label class="plan-label">Payment</label>
                <div class="duration-toggle">
                  <button type="button" class="duration-opt" :class="{ active: pgForm.billing_interval === 'one_time' }" @click="setPayStyle('full')">Pay in full</button>
                  <button type="button" class="duration-opt" :class="{ active: pgForm.billing_interval === 'monthly' }" @click="setPayStyle('installments')">Monthly installments</button>
                </div>
              </div>
              <div class="plan-field">
                <label class="plan-label">{{ pgForm.billing_interval === 'one_time' ? 'Season price' : 'Monthly amount' }} <span v-if="pgForm.currency" class="currency-badge">{{ pgForm.currency }}</span></label>
                <input type="number" min="0" v-model.number="pgForm.monthly_price" placeholder="0" />
              </div>
            </div>
            <!-- ONGOING: recurring billing interval -->
            <div v-else class="plan-top">
              <div class="plan-field">
                <label class="plan-label">Billing</label>
                <select v-model="pgForm.billing_interval" @change="onBillingIntervalChange">
                  <option v-for="opt in BILLING_INTERVALS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
              </div>
              <div class="plan-field">
                <label class="plan-label">{{ offerMode === 'discount' ? 'List price' : 'Price' }} <span v-if="pgForm.currency" class="currency-badge">{{ pgForm.currency }}</span></label>
                <input type="number" min="0" v-model.number="pgForm.monthly_price" placeholder="0" />
              </div>
            </div>

            <!-- Currency-aware suggestion + provider take-home under the price -->
            <div class="price-hints">
              <button v-if="!(Number(pgForm.monthly_price) > 0)" type="button" class="price-suggest" @click="applySuggestedPrice">
                Suggested: {{ fmtMoney(suggestedMonthlyPrice, pgForm.currency) }} — tap to use
              </button>
              <span v-else class="price-takehome">You keep {{ fmtMoney(effectiveFirstCharge * providerPct / 100, pgForm.currency) }} ({{ providerPct }}%) of every {{ fmtMoney(effectiveFirstCharge, pgForm.currency) }} charge.</span>
            </div>

            <!-- One-off joining / sign-up fee (charged once at enrolment, before the plan) -->
            <div class="plan-field plan-joining-fee">
              <label class="plan-label">Joining fee <span class="plan-opt-tag">optional</span> <span v-if="pgForm.currency" class="currency-badge">{{ pgForm.currency }}</span></label>
              <input type="number" min="0" v-model.number="pgForm.joining_fee" placeholder="0" />
              <div class="field-hint">A one-off sign-up charge taken once when a member joins, on top of the recurring price.<span v-if="!programJoiningFeeSupported"> Apply the pending DB migration to persist it.</span></div>
            </div>

            <!-- OFFER (mandatory MOAT): a free trial, or a discount on longer ongoing plans -->
            <div class="plan-offer">
              <template v-if="offerMode === 'trial'">
                <span class="plan-offer-label">Free trial <span class="offer-required">required</span></span>
                <div class="offer-pills">
                  <button v-for="o in trialOptions" :key="o.key" type="button" class="offer-pill" :class="{ on: currentTrialKey === o.key }" @click="setTrialOption(o)">
                    {{ o.label }}<span v-if="o.key === recommendedTrialKey" class="offer-rec">Recommended</span>
                  </button>
                </div>
              </template>
              <template v-else>
                <span class="plan-offer-label">Discount offer <span class="offer-required">required</span></span>
                <div class="offer-pills">
                  <button v-for="n in discountOptions" :key="n" type="button" class="offer-pill" :class="{ on: Number(pgForm.discount_months) === n }" @click="setDiscount(n)">
                    {{ n }} month{{ n !== 1 ? 's' : '' }} off<span v-if="n === recommendedDiscount" class="offer-rec">Recommended</span>
                  </button>
                </div>
                <span v-if="Number(pgForm.monthly_price) > 0 && Number(pgForm.discount_months) > 0" class="offer-note">Customer pays {{ pgForm.currency }} {{ discountedAmount.toLocaleString() }} / {{ intervalLabel(pgForm.billing_interval).toLowerCase() }}</span>
              </template>
            </div>

            <div v-if="planPreviewText" class="plan-preview"><Users :size="12" />{{ planPreviewText }}</div>
          </div>
          <div v-if="pgTriedSave && !hasValidPlan()" class="field-error-text" style="margin-top:6px;"><Info :size="12" />Set a price for this program</div>
          <div class="form-hint" style="margin-top:8px;">Every program must offer a free trial (or, for longer plans, a discount) — it's required to list on Bookboll.<span v-if="!programOfferSupported"> Apply the pending DB migration to persist the trial/discount.</span></div>

          <div class="form-group" style="margin-top:16px;max-width:300px;">
            <label class="form-label">Platform Commission (%)</label>
            <input type="number" v-model="pgForm.commission_percentage" min="0" max="100" :disabled="role !== 'admin'" />
            <div class="field-hint">Minimum {{ PLATFORM_COMMISSION }}%. Applies to every plan.</div>
          </div>

          <!-- Commission disclosure -->
          <div class="disclosure disclosure-commission">
            <div class="disclosure-head"><Info :size="15" /><strong>Platform commission &amp; payout</strong></div>
            <div class="disclosure-body">
              The platform automatically retains <strong>{{ pgForm.commission_percentage }}%</strong> of every successful charge.
              You receive the remaining <strong>{{ providerPct }}%</strong>.
            </div>
            <div class="split-row">
              <div class="split-chip">You keep ({{ providerPct }}%): {{ fmtMoney(effectiveFirstCharge * providerPct / 100, pgForm.currency) }}</div>
              <div class="split-chip platform">Platform ({{ pgForm.commission_percentage }}%): {{ fmtMoney(effectiveFirstCharge * pgForm.commission_percentage / 100, pgForm.currency) }}</div>
            </div>
            <div v-if="offerMode === 'trial'" class="split-caption">
              Based on {{ fmtMoney(effectiveFirstCharge, pgForm.currency) }}/{{ intervalLabel(pgForm.billing_interval).toLowerCase() }} once billing starts — nothing is charged during the free trial.
            </div>
            <div v-else-if="offerMode === 'discount' && Number(pgForm.discount_months) > 0" class="split-caption">
              During the {{ pgForm.discount_months }} discounted {{ Number(pgForm.discount_months) === 1 ? 'month' : 'months' }} the member pays {{ fmtMoney(discountedAmount, pgForm.currency) }}; from the list price after, you keep {{ fmtMoney(pgForm.monthly_price * providerPct / 100, pgForm.currency) }}.
            </div>
          </div>

          </div><!-- /.pg-section-body Pricing -->
          </section>

          <section class="pg-section" :class="{ open: pgOpen === 'placement' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'placement'" @click="pgOpen = pgOpen === 'placement' ? '' : 'placement'">
            <div class="pg-section-titles"><div class="pg-section-name">Placement</div><div class="pg-section-sub">Where this program appears</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'placement' }" />
          </button>
          <div v-show="pgOpen === 'placement'" class="pg-section-body">
            <div class="pl-opt" :class="{ on: pgForm.show_on_marketplace }">
              <div class="pl-opt-icon mkt"><Store :size="20" /></div>
              <div class="pl-opt-body">
                <div class="pl-opt-name">Bookboll Marketplace <span class="placement-rec">Recommended</span></div>
                <div class="pl-opt-desc">Reach Bookboll's global audience. Reviewed before going live.</div>
              </div>
              <label class="dp-switch" :title="pgForm.show_on_marketplace ? 'On' : 'Off'">
                <input type="checkbox" v-model="pgForm.show_on_marketplace" @change="onMarketplaceToggle" />
                <span class="dp-slider"></span>
              </label>
            </div>
            <div class="pl-opt" :class="{ on: pgForm.show_on_provider_site }">
              <div class="pl-opt-icon site"><Globe :size="20" /></div>
              <div class="pl-opt-body">
                <div class="pl-opt-name">My own website</div>
                <div class="pl-opt-desc">Your white-label site. Goes live instantly — no approval.</div>
              </div>
              <label class="dp-switch" :title="pgForm.show_on_provider_site ? 'On' : 'Off'">
                <input type="checkbox" v-model="pgForm.show_on_provider_site" />
                <span class="dp-slider"></span>
              </label>
            </div>
            <div v-if="!pgForm.show_on_marketplace && !pgForm.show_on_provider_site" class="placement-warn">
              <AlertTriangle :size="14" /> This program won't appear anywhere until you turn on a placement.
            </div>
            <div v-if="!programPlacementSupported" class="field-hint" style="margin-top:8px;">Apply the pending DB migration to persist placement.</div>
          </div>
          </section>

          <section class="pg-section" :class="{ open: pgOpen === 'media' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'media'" @click="pgOpen = pgOpen === 'media' ? '' : 'media'">
            <div class="pg-section-titles"><div class="pg-section-name">Photos</div><div class="pg-section-sub">Program images</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'media' }" />
          </button>
          <div v-show="pgOpen === 'media'" class="pg-section-body">
          <div class="form-group">
            <label class="form-label">Program Images</label>
            <div class="img-grid" v-if="(pgForm.images || []).length">
              <div v-for="(src, i) in pgForm.images" :key="i" class="img-tile">
                <img :src="src" alt="" />
                <span v-if="i === 0" class="img-cover-badge">Cover</span>
                <button type="button" class="img-remove" @click="removeImage(i)" title="Remove"><X :size="13" /></button>
              </div>
            </div>
            <div class="img-dropzone" :class="{ active: dropActive }"
              @click="pgFileInput.click()"
              @dragover.prevent="dropActive = true" @dragleave.prevent="dropActive = false" @drop.prevent="onProgramDrop">
              <input ref="pgFileInput" type="file" accept="image/*" multiple style="display:none" @change="onProgramFilesChange" />
              <template v-if="imgUploading"><Loader :size="18" class="img-spin" /> Uploading…</template>
              <template v-else>
                <ImagePlus :size="20" />
                <span>Click or drop images to upload</span>
                <span class="img-sub">Auto-compressed · first image is the cover</span>
              </template>
            </div>
            <div v-if="imgError" class="field-error">{{ imgError }}</div>
            <details class="img-url-fallback">
              <summary>or paste an image URL</summary>
              <div class="img-add-row">
                <input v-model="newImageUrl" placeholder="https://example.com/image.jpg" @keyup.enter="addImage" />
                <button type="button" class="btn btn-secondary btn-sm" @click="addImage"><Plus :size="13" />Add</button>
              </div>
            </details>
          </div>

          </div><!-- /.pg-section-body Description & Photos -->
          </section>

          <section v-if="!pgIsGym" class="pg-section" :class="{ open: pgOpen === 'extras' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'extras'" @click="pgOpen = pgOpen === 'extras' ? '' : 'extras'">
            <div class="pg-section-titles"><div class="pg-section-name">Coach</div><div class="pg-section-sub">Lead coach (optional)</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'extras' }" />
          </button>
          <div v-show="pgOpen === 'extras'" class="pg-section-body">
          <div class="form-group"><label class="form-label">Coach (optional)</label>
            <input v-model="pgForm.coach_name" placeholder="Lead coach name" />
            <div class="field-hint">Membership sessions show on the calendar at this location. To reserve a specific court/listing, create a booking or edit that listing's schedule.</div>
          </div>

          <!-- Diff for admin reviewing resubmitted -->
          <div v-if="role === 'admin' && pgEditing && rowState(pgEditing) === 'resubmitted'" class="disclosure">
            <div class="disclosure-head"><RefreshCw :size="15" /><strong>Provider changes since last approval</strong></div>
            <div v-for="ch in getChanges(pgEditing)" :key="ch.label" class="diff-line">
              <span class="diff-field">{{ ch.label }}</span>
              <span class="diff-val before">{{ ch.before || '—' }}</span>
              <span class="diff-arrow">→</span>
              <span class="diff-val after">{{ ch.after || '—' }}</span>
            </div>
            <div v-if="!getChanges(pgEditing).length" class="muted" style="font-size:12px">No tracked field changes detected.</div>
          </div>
          </div><!-- /.pg-section-body Coach & Listings -->
          </section>

          <!-- ── Sign-up questions (optional custom form) ──────────────── -->
          <section class="pg-section" :class="{ open: pgOpen === 'signup' }">
          <button type="button" class="pg-section-head" :aria-expanded="pgOpen === 'signup'" @click="pgOpen = pgOpen === 'signup' ? '' : 'signup'">
            <div class="pg-section-titles"><div class="pg-section-name">Sign-up questions</div><div class="pg-section-sub">Optional — ask customers anything at checkout</div></div>
            <ChevronDownIcon :size="18" class="pg-chev" :class="{ rot: pgOpen === 'signup' }" />
          </button>
          <div v-show="pgOpen === 'signup'" class="pg-section-body">
            <CustomFormBuilder v-model:fields="pgForm.signup_form" />
            <div v-if="!programFormSupported" class="field-hint" style="margin-top:8px;">Apply the pending DB migration to persist sign-up questions.</div>
          </div>
          </section>
        </div>
        <div v-if="pgModuleError" class="module-block-error" style="margin:0 24px 12px;">
          <AlertTriangle :size="14" />{{ pgModuleError }}
        </div>
        <div class="bb-drawer-footer">
          <button class="btn btn-secondary" @click="requestCloseProgram">Cancel</button>
          <!-- Save draft: only name + location required; saves hidden (status:'inactive') + is_draft -->
          <button
            class="btn btn-secondary pg-draft-btn"
            :disabled="!canSaveProgram"
            title="Save your progress without submitting — only a name and location are needed"
            @click="saveDraft"
          ><BookOpen :size="14" />Save draft</button>
          <button
            class="btn btn-primary"
            :disabled="!canSaveProgram"
            @click="requestSaveProgram"
          ><Check :size="14" />{{ pgEditing ? (role === 'admin' ? 'Save' : 'Save &amp; Resubmit') : 'Submit for Approval' }}</button>
        </div>
      </div>
    </div>
    </teleport>

    <!-- PAUSE / SEND BACK MODAL -->
    <div v-if="pauseModal" class="modal-backdrop" @click.self="pauseModal=false">
      <div class="modal" v-dialog style="max-width: 480px;">
        <div class="modal-header">
          <div class="modal-title"><PauseCircle :size="18" />Send back for changes</div>
          <button class="btn-icon" @click="pauseModal=false" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="muted" style="font-size:13px; margin-bottom:12px;">The provider will see this note and must edit and resubmit before the program goes live again.</div>
          <label class="form-label">Reason / required changes</label>
          <textarea v-model="pauseNote" rows="4" placeholder="e.g. Coach certification details missing."></textarea>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="pauseModal=false">Cancel</button>
          <button class="btn btn-primary" :disabled="!pauseNote.trim()" @click="confirmPauseProgram"><PauseCircle :size="14" />Pause &amp; Send back</button>
        </div>
      </div>
    </div>

    <!-- TRIAL AGREEMENT MODAL -->
    <div v-if="trialAgreementModal" class="modal-backdrop" style="z-index:3400">
      <div class="modal trial-agreement-modal" v-dialog style="max-width:520px;">
        <div class="modal-header">
          <div class="modal-title"><ShieldCheck :size="18" style="color: var(--positive-ink)" />Free Trial Agreement</div>
        </div>
        <div class="modal-body" style="padding-bottom:0;">
          <p class="trial-agree-intro">To list classes on this platform you must offer all customers a <strong>{{ trialAgreeLabel }}</strong>. Please read and accept the terms below to continue.</p>
          <div class="trial-terms-box">
            <div class="trial-term-item">
              <span class="trial-term-icon">1</span>
              <span>Customers subscribe using their credit card but are <strong>not charged</strong> during the {{ trialAgreeLabel }}.</span>
            </div>
            <div class="trial-term-item">
              <span class="trial-term-icon">2</span>
              <span>Customers can <strong>cancel anytime</strong> {{ trialIsSession ? 'before their second session' : 'within the ' + (Number(pgForm.free_trial_days) || 0) + '-day window' }} and will not be charged.</span>
            </div>
            <div class="trial-term-item">
              <span class="trial-term-icon">3</span>
              <span>If the customer does not cancel before the trial ends, the card on file will be <strong>automatically charged {{ fmtMoney(pgForm.monthly_price, pgForm.currency) }}</strong>{{ trialIsSession ? ' on their second session' : '' }} and billing continues {{ isFixed && pgForm.billing_interval === 'one_time' ? 'once for the whole program' : intervalLabel(pgForm.billing_interval).toLowerCase() }}.</span>
            </div>
            <div class="trial-term-item">
              <span class="trial-term-icon">4</span>
              <span>The platform commission of <strong>{{ pgForm.commission_percentage }}%</strong> applies only once billing starts — not during the free trial.</span>
            </div>
          </div>
          <label class="trial-consent-row">
            <input type="checkbox" v-model="trialAgreementChecked" />
            <span>I agree to offer the {{ trialAgreeLabel }} on this program and acknowledge the {{ pgForm.commission_percentage }}% platform commission on all paid subscriptions.</span>
          </label>
        </div>
        <div class="modal-footer" style="gap:8px;">
          <button class="btn btn-secondary" @click="trialAgreementModal=false">Go back</button>
          <button
            class="btn btn-primary"
            :disabled="!trialAgreementChecked"
            @click="confirmTrialAgreement"
          ><ShieldCheck :size="14" />I Agree &amp; Continue</button>
        </div>
      </div>
    </div>

    <!-- ── Delete program with active members: archive or delete immediately ── -->
    <div v-if="delModal" class="modal-backdrop" style="z-index:3400" @click.self="closeDelModal">
      <div class="modal del-modal" v-dialog style="max-width:540px;">
        <div class="del-head">
          <div class="del-ic"><AlertTriangle :size="22" /></div>
          <div>
            <div class="del-title">“{{ delModal.program?.name }}” has {{ delModal.activeCount }} active member{{ delModal.activeCount !== 1 ? 's' : '' }}</div>
            <div class="del-subtitle">Choose how to remove this program.</div>
          </div>
        </div>

        <!-- Option A — Archive (recommended) -->
        <div class="del-option">
          <div class="del-option-text">
            <div class="del-option-name">Archive <span class="del-rec">Recommended</span></div>
            <p>Stops new sign-ups and renewals. Current members keep their reserved slots until their next billing date, then their subscription is cancelled — no further charges. All records are kept.</p>
          </div>
          <button class="btn btn-secondary del-option-btn" @click="archiveFromModal"><BookOpen :size="14" />Archive program</button>
        </div>

        <!-- Option B — Delete immediately (danger) -->
        <div class="del-option del-option-danger">
          <div class="del-option-text">
            <div class="del-option-name">Delete immediately</div>
            <p>Permanently deletes the program now. All {{ delModal.activeCount }} member{{ delModal.activeCount !== 1 ? 's' : '' }} lose access right away and their subscriptions are cancelled. This cannot be undone.</p>
          </div>
          <label class="del-ack">
            <input type="checkbox" v-model="forceAck" />
            <span>I understand members lose access immediately, and I take responsibility for issuing any refunds myself.</span>
          </label>
          <button class="btn del-delete-btn" :disabled="!forceAck" @click="forceDeleteProgram"><Trash2 :size="14" />Delete immediately</button>
        </div>

        <div class="del-foot">
          <button class="btn btn-ghost" @click="closeDelModal">Keep active</button>
        </div>
      </div>
    </div>

    <!-- SHARE: copy link + QR for one membership program -->
    <div v-if="shareRow" class="modal-backdrop" style="z-index:3600" @click.self="closeShare">
      <div class="modal share-modal" v-dialog>
        <div class="modal-header">
          <div class="modal-title"><Share2 :size="16" /> Share “{{ shareRow.name }}”</div>
          <button class="btn-icon" @click="closeShare" aria-label="Close"><X :size="18" /></button>
        </div>
        <div class="modal-body share-body">
          <p class="share-hint">Anyone with this link lands straight on the membership page — paste it in your Instagram bio or a WhatsApp broadcast, or print the QR for a door poster or flyer.</p>
          <div class="share-linkrow">
            <input class="share-link" :value="shareRow.url" readonly @focus="$event.target.select()" />
            <button class="btn btn-primary btn-sm share-copy" @click="copyShareLink">
              <component :is="shareCopied ? Check : Copy" :size="14" />{{ shareCopied ? 'Copied' : 'Copy' }}
            </button>
          </div>
          <div class="share-qr-wrap">
            <img v-if="shareQr" :src="shareQr" alt="QR code for this page" class="share-qr" />
            <div v-else class="share-qr share-qr-empty"><QrCode :size="34" /></div>
            <button v-if="shareQr" class="btn btn-secondary btn-sm" @click="downloadQr"><Download :size="14" />Download QR</button>
          </div>
          <a :href="shareRow.url" target="_blank" rel="noopener" class="share-open"><ExternalLink :size="13" />Open the page</a>
        </div>
      </div>
    </div>


    <!-- ── Hide-from-marketplace confirm (same as the listing form) ───────── -->
    <div v-if="mktConfirm" class="modal-backdrop" style="z-index:3600" @click.self="keepOnMarketplace">
      <div class="modal mkt-modal" v-dialog>
        <div class="mkt-icon"><Store :size="28" /></div>
        <div class="mkt-title">Hide from Bookboll Marketplace?</div>
        <div class="mkt-points">
          <div class="mkt-point neg"><TrendingDown :size="18" /><span>Fewer bookings &amp; revenue</span></div>
          <div class="mkt-point neg"><Globe :size="18" /><span>Lose Bookboll's global reach</span></div>
          <div class="mkt-point pos"><Check :size="18" /><span>Still live on your own website</span></div>
        </div>
        <div class="mkt-actions">
          <button class="btn btn-primary mkt-keep" @click="keepOnMarketplace"><Store :size="15" />Keep on marketplace</button>
          <button class="mkt-anyway" @click="confirmMarketplaceOff">Hide anyway</button>
        </div>
      </div>
    </div>

    <!-- ── New location modal (from program form) — shared LocationModal ──── -->
    <LocationModal :open="newLocModal" :editing="null" @close="newLocModal=false" @saved="onLocationSaved" />

    <!-- ── "Set up your Subscription wallet" gate ──────────────────────────── -->
    <PayoutSetupModal :open="payoutModal" purpose="subscription" :country-id="payoutModalCountry" :pending="payoutModalPending" @close="payoutModal=false" />

    <!-- ── Export modal ──────────────────────────────────────────────────── -->

  </div>
</template>

<style scoped>
/* ── Program drawer: accordion section cards (like the listing/location forms) ── */
.pg-section { background:var(--surface, #ffffff); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:box-shadow .15s; }
.pg-section + .pg-section { margin-top:12px; }
.pg-section.open { box-shadow:0 4px 16px rgba(15,23,42,.06); }
.pg-section-head { width:100%; display:flex; align-items:center; gap:14px; padding:15px 18px; background:transparent; border:none; cursor:pointer; text-align:left; }
.pg-section-head:hover { background:var(--surface-hover, #fafafa); }
.pg-section-titles { flex:1; min-width:0; }
.pg-section-name { font-weight:600; font-size:14px; color:var(--text); }
.pg-section-sub { font-size:12px; color:var(--text-muted); margin-top:2px; }
.pg-chev { color:var(--text-muted); transition:transform .15s; flex-shrink:0; }
.pg-chev.rot { transform:rotate(180deg); }
.pg-section-body { padding:8px 18px 18px; border-top:1px solid var(--border); }

/* ── Program colour picker ── */
.pg-color-row { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.pg-swatch { width:30px; height:30px; border-radius:8px; border:2px solid #fff; box-shadow:0 0 0 1px var(--border); cursor:pointer; padding:0; transition:transform .1s, box-shadow .1s; position:relative; }
.pg-swatch:hover { transform:scale(1.08); }
.pg-swatch.active { box-shadow:0 0 0 2px #fff, 0 0 0 4px var(--primary, #0891b2); }
.pg-swatch-custom { display:inline-flex; align-items:center; justify-content:center; overflow:hidden; background-image:conic-gradient(#ef4444,#f59e0b,#10b981,#3b82f6,#a855f7,#ef4444); }
.pg-swatch-custom input[type="color"] { opacity:0; width:100%; height:100%; cursor:pointer; border:none; padding:0; }

/* ── Provider no-programs state ──────────────────── */
.cs-no-programs-state {
  text-align:center; padding:48px 32px; background:var(--surface, #ffffff);
  border:1.5px dashed var(--border); border-radius:14px; margin-bottom:20px;
}
.cs-nps-icon { width:72px;height:72px;border-radius:16px;background:linear-gradient(135deg,var(--purple-bg, #ede9fe),#c7d2fe);color:var(--purple-ink, #4f46e5);display:flex;align-items:center;justify-content:center;margin:0 auto 16px; }
.cs-nps-title { font-size:20px;font-weight:700;margin-bottom:8px; }
.cs-nps-body { font-size:14px;color:var(--text-muted);max-width:440px;margin:0 auto 20px;line-height:1.6; }
.cs-wallet-warn { display:flex; align-items:center; gap:6px; margin-top:6px; font-size:12.5px; color:var(--warning-ink, #b45309); }
.cs-wallet-warn a { color:var(--warning-ink, #b45309); font-weight:700; text-decoration:underline; white-space:nowrap; }
.cs-wallet-note { display:flex; align-items:center; gap:6px; margin-top:6px; font-size:12.5px; color:var(--text-muted, #64748b); }
.cs-wallet-note a { color: var(--primary-ink); font-weight:700; text-decoration:underline; }

/* ── Trial days field ── */
.trial-days-field { display:flex; align-items:center; gap:8px; }
.trial-days-field input { max-width:90px; }
.trial-days-unit { font-size:13px; color:var(--text-muted); }
.trial-pills { display:flex; flex-wrap:wrap; gap:8px; }
.trial-pill { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; font-size:13px; font-weight:600; border:1px solid var(--border); border-radius:9px; background:var(--surface, #ffffff); color:var(--text-primary); cursor:pointer; transition:all .12s; }
.trial-pill:hover { border-color:var(--border-strong, #cbd5e1); }
.trial-pill.active { border-color:var(--primary); background:var(--primary-soft, #f0fdfa); color:var(--primary-ink); }
.trial-pill-rec { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--positive-ink, #047857); background:var(--positive-bg, #d1fae5); border-radius:999px; padding:2px 7px; }
.trial-pill.active .trial-pill-rec { background:#a7f3d0; }
.form-opt-tag { font-size:10.5px; font-weight:600; color:var(--primary-ink); background:var(--info-bg, #eff6ff); padding:1px 6px; border-radius:99px; margin-left:6px; text-transform:none; letter-spacing:0; }
.form-hint-sm { font-size:11.5px; color:var(--text-muted); margin-top:4px; }

/* Billing plans (repeatable rows) */
.plans-list { display:flex; flex-direction:column; gap:12px; }
.plan-card { padding:14px; border:1px solid var(--border); border-radius:12px; background:var(--surface-hover, #fafafa); }
.plan-card.plan-invalid { border-color:var(--danger-border, #fca5a5); background: var(--danger-bg); }
.plan-top { display:flex; align-items:flex-end; gap:12px; }
.plan-field { display:flex; flex-direction:column; gap:4px; flex:1; }
.plan-label { font-size:11.5px; font-weight:600; color:var(--text-muted); }
.plan-remove { flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border:1px solid var(--danger-border, #fecaca); background:var(--danger-bg, #fef2f2); color:var(--danger-ink, #dc2626); border-radius:8px; cursor:pointer; }
.plan-remove:hover { background:var(--danger-bg, #fee2e2); }
/* Contextual offer row */
.plan-offer { display:flex; align-items:center; flex-wrap:wrap; gap:8px 12px; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); }
.plan-offer-label { font-size:12px; font-weight:600; color:var(--text); }
.offer-pills { display:flex; flex-wrap:wrap; gap:6px; }
.offer-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 11px; font-size:12.5px; font-weight:600; border:1px solid var(--border); border-radius:8px; background:var(--surface, #ffffff); color:var(--text); cursor:pointer; transition:all .12s; }
.offer-pill:hover { border-color:var(--border-strong, #cbd5e1); }
.offer-pill.on { border-color:var(--primary); background:var(--primary-soft, #f0fdfa); color:var(--primary-ink); }
.offer-rec { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:var(--positive-ink, #047857); background:var(--positive-bg, #d1fae5); border-radius:999px; padding:1px 6px; }
.offer-required { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:var(--warning-ink, #b45309); background:var(--warning-bg, #fef3c7); border-radius:999px; padding:1px 6px; margin-left:6px; }
.offer-stepper { display:inline-flex; align-items:center; gap:0; border:1px solid var(--border); border-radius:8px; overflow:hidden; background:var(--surface, #ffffff); }
.offer-stepper button { width:30px; height:30px; border:none; background:var(--surface, #ffffff); font-size:16px; color:var(--text); cursor:pointer; }
.offer-stepper button:hover:not(:disabled) { background:var(--bg, #f1f5f9); }
.offer-stepper button:disabled { color:var(--text-light, #cbd5e1); cursor:not-allowed; }
.offer-step-val { min-width:28px; text-align:center; font-size:13.5px; font-weight:700; }
.offer-note { font-size:11.5px; color:var(--text-muted); }
.offer-auto { font-size:13px; font-weight:600; color:var(--positive-ink, #047857); }
.plan-preview { display:flex; align-items:center; gap:6px; margin-top:10px; font-size:12px; color:var(--text-muted); }
.plan-preview svg { flex-shrink:0; color:var(--text-muted); }
.input-locked { background:var(--bg, #f1f5f9); color:var(--text-muted); cursor:not-allowed; }

/* ── Currency-aware price suggestion + take-home ── */
.price-hints { margin-top:8px; min-height:20px; }
.price-suggest { display:inline-flex; align-items:center; font-size:12px; font-weight:600; color:var(--primary-ink); background:var(--primary-soft, #f0fdfa); border:1px dashed var(--primary-border, #99f6e4); border-radius:8px; padding:4px 10px; cursor:pointer; transition:background .12s; }
.price-suggest:hover { background:var(--primary-soft, #ccfbf1); }
.price-takehome { font-size:12px; color:var(--positive-ink, #047857); font-weight:500; }
/* One-off joining fee row */
.plan-joining-fee { margin-top:12px; padding-top:12px; border-top:1px solid var(--border); max-width:260px; }
.plan-joining-fee input { max-width:160px; }
.plan-opt-tag { font-size:10px; font-weight:600; color:var(--text-muted); background:var(--bg, #f1f5f9); border-radius:99px; padding:1px 7px; margin-left:2px; text-transform:none; letter-spacing:0; }

/* ── Starter templates chip row ── */
.pg-templates { margin-bottom:14px; }
.pg-templates-label { display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:7px; }
.pg-template-chips { display:flex; flex-wrap:wrap; gap:8px; }
.pg-template-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; font-size:13px; font-weight:600; border:1px solid var(--border); border-radius:999px; background:var(--surface, #ffffff); color:var(--text); cursor:pointer; transition:all .12s; }
.pg-template-chip:hover { border-color:var(--border-strong, #cbd5e1); background:var(--surface-hover, #fafafa); }
.pg-template-chip.active { border-color:var(--primary); background:var(--primary-soft, #f0fdfa); color:var(--primary-ink); }
.pg-template-chip svg { flex-shrink:0; }

/* Save draft footer button spacing */
.pg-draft-btn { margin-right:auto; }

/* ── Label with inline action (e.g. + New location) ── */
.label-with-action { display:flex; align-items:center; justify-content:space-between; }
.link-btn {
  display:inline-flex; align-items:center; gap:3px;
  font-size:12px; font-weight:600; color:var(--primary-ink);
  background:none; border:none; cursor:pointer; padding:0;
}
.link-btn:hover { text-decoration:underline; }

/* ── Export modal choice buttons ─────────────────── */
.export-choice-btn {
  display:flex;align-items:center;gap:14px;padding:14px 16px;
  background:var(--bg);border:1.5px solid var(--border);border-radius:10px;
  cursor:pointer;text-align:left;width:100%;transition:all .15s;
}
.export-choice-btn:hover { border-color:var(--primary);background:var(--info-bg, #f0f9ff); }
.export-choice-btn svg { color:var(--primary-ink);flex-shrink:0; }
.export-choice-btn strong { font-size:14px;color:var(--text); }
.export-choice-btn span { font-size:12px;color:var(--text-muted); }

/* Non-blocking payout nudge (programs saved but not live) */
.cs-payout-banner { display: flex; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 14px; background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; }
.cs-pb-ic { width: 34px; height: 34px; border-radius: 9px; background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cs-pb-txt { flex: 1; min-width: 0; font-size: 13px; color: var(--warning-ink, #92400e); line-height: 1.45; }
.cs-pb-txt strong { color: var(--warning-ink, #78350f); }
.cs-pb-cta { flex-shrink: 0; }
/* Honest payout caption under the split chips */
.split-caption { margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.45; }
/* Per-program go-live readiness badge */
.pg-ready { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.pg-ready.ready-live { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #047857); }
.pg-ready.ready-off { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); }
.pg-ready-reason { font-size: 11px; color: var(--text-muted); margin-top: 3px; max-width: 160px; line-height: 1.35; }

/* ── Programs table ──────────────────────────────── */
.programs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.programs-table th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); background: var(--bg); border-bottom: 1px solid var(--border); white-space: nowrap; }
.programs-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.programs-table tbody tr:last-child td { border-bottom: none; }
.programs-table tbody tr:hover td { background: var(--bg); }
.prog-row-inactive td { opacity: 0.55; }
.prog-name { font-weight: 600; font-size: 13px; color: var(--text); }
.prog-sub { font-size: 12px; color: var(--text); line-height: 1.5; }
.prog-sub.muted { color: var(--text-muted); }
.prog-note { display: flex; align-items: center; gap: 5px; margin-top: 4px; font-size: 11px; color: var(--warning-ink, #9a3412); background: var(--warning-bg, #fff7ed); border: 1px solid var(--warning-border, #fed7aa); border-radius: 6px; padding: 3px 7px; }
.prog-reserved { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; font-size: 10.5px; font-weight: 600; color: var(--purple-ink, #6d28d9); background: var(--purple-bg, #f5f3ff); border: 1px solid var(--purple-border, #ddd6fe); border-radius: 6px; padding: 2px 7px; }

/* legacy card styles kept for any remaining references */
.program-card { padding: 18px; display: flex; flex-direction: column; gap: 10px; transition: transform 0.15s, box-shadow 0.15s; position: relative; }
.program-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.pg-state-wrap { display: flex; justify-content: flex-start; }
.pg-state-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; border: 1px solid; }
.pg-state-badge.state-active { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border-color: var(--positive-border, #a7f3d0); }
.pg-state-badge.state-paused { background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #9a3412); border-color: var(--warning-border, #fed7aa); }
.pg-state-badge.state-resubmitted { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); border-color: var(--info-border, #bfdbfe); }
.pg-state-badge.state-pending_new { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); border-color: var(--border-strong, #cbd5e1); }
.pg-state-badge.state-draft { background: var(--info-bg, #eef2ff); color: var(--purple-ink, #4338ca); border-color: var(--purple-border, #c7d2fe); }
.pg-admin-note { display: flex; align-items: flex-start; gap: 8px; background: var(--warning-bg, #fff7ed); border: 1px solid var(--warning-border, #fed7aa); color: var(--warning-ink, #9a3412); border-radius: 8px; padding: 8px 10px; font-size: 12px; }
.pg-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.pg-name { font-weight: 600; font-size: 16px; }
.pg-meta { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.pg-body { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--text); }
.pg-row { display: flex; align-items: center; gap: 8px; color: var(--text); }
.pg-row.muted { color: var(--text-muted); font-size: 12px; }
.pg-terms-chip { display: inline-flex; align-items: center; gap: 5px; background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; width: fit-content; margin-top: 4px; border: 1px solid var(--positive-border, #a7f3d0); }
.pg-actions { display: flex; gap: 6px; align-items: center; padding-top: 10px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.pg-actions .btn { margin-right: auto; }
.btn-icon { padding: 6px; border-radius: 6px; color: var(--text-muted); background: transparent; border: 0; cursor: pointer; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: var(--danger-bg, #fee2e2); color: var(--error); }
.day-picker { display: flex; gap: 6px; flex-wrap: wrap; }
/* No day picked at save time → the whole picker flags red */
.day-picker.dp-invalid { border: 1px solid #dc2626; background: var(--danger-bg, #fef2f2); border-radius: 10px; padding: 8px; }
.day-pill { padding: 7px 13px; border: 1px solid var(--border); border-radius: 999px; font-size: 13px; background: var(--surface, #ffffff); transition: all 0.15s; }
.day-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.aud-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.aud-pill { padding: 8px 16px; border: 1px solid var(--border); border-radius: 999px; font-size: 13.5px; font-weight: 600; background: var(--surface, #ffffff); color: var(--text); cursor: pointer; transition: all 0.15s; }
.aud-pill:hover { border-color: var(--primary); }
.aud-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.gym-sched-note { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 12px; font-size: 13px; line-height: 1.5; color: var(--text); }
.gym-sched-note :deep(svg) { color: var(--primary-ink, #0f766e); flex-shrink: 0; margin-top: 1px; }
.gym-sched-note a { color: var(--primary-ink, #0f766e); font-weight: 700; }
.split-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.split-chip { padding: 5px 10px; border-radius: 999px; background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); font-size: 12px; font-weight: 500; }
.split-chip.platform { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.muted { color: var(--text-muted); }
.module-block-error { display:flex; align-items:flex-start; gap:8px; padding:10px 14px; background:var(--danger-bg, #fef2f2); border:1px solid var(--danger-border, #fecaca); border-radius:8px; color:var(--danger-ink, #991b1b); font-size:13px; line-height:1.5; }
.kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.field-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

/* Type combobox + read-only derived Category (mirrors the venue listing form). */
.combo { position: relative; }
.combo-input { width: 100%; padding-right: 34px; }
.combo-caret { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; transition: transform 0.15s; }
.combo-caret.rot { transform: translateY(-50%) rotate(180deg); }
.combo-menu { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-lg); max-height: 260px; overflow-y: auto; z-index: 20; padding: 4px; }
.combo-item { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 8px 10px; font-size: 14px; text-align: left; border: none; background: none; cursor: pointer; border-radius: 6px; color: var(--text); }
.combo-item:hover { background: var(--bg); }
.combo-item.active { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink); font-weight: 500; }
.combo-item-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.combo-cat { flex-shrink: 0; margin-left: 10px; font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; }
.combo-empty { padding: 12px; font-size: 13px; color: var(--text-muted); text-align: center; }
.combo-add { display: flex; align-items: center; gap: 6px; width: 100%; padding: 9px 10px; margin-top: 2px; font-size: 13.5px; font-weight: 700; text-align: left; border: none; border-top: 1px solid var(--border); background: none; color: var(--primary-ink); cursor: pointer; border-radius: 0 0 6px 6px; }
.combo-add:hover { background: var(--primary-soft, #f0fdfa); }
.combo-addpanel { padding: 10px; }
.combo-add-title { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.combo-add-cats { display: flex; flex-wrap: wrap; gap: 6px; }
.combo-add-cat { padding: 7px 12px; font-size: 13px; font-weight: 600; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); border-radius: 999px; cursor: pointer; }
.combo-add-cat:hover { border-color: var(--primary); color: var(--primary-ink); background: var(--primary-soft, #f0fdfa); }
.combo-add-note { font-size: 11.5px; color: var(--text-muted); margin-top: 8px; line-height: 1.4; }
.readonly-field { display: flex; align-items: center; min-height: 38px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font-size: 14px; font-weight: 600; }
.readonly-field.empty { color: var(--text-muted); font-weight: 400; font-style: italic; }

.disclosure { border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-top: 14px; background: var(--surface, #ffffff); }
.disclosure-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 14px; }
.disclosure-body { font-size: 13px; color: var(--text); line-height: 1.55; }
.disclosure-body ul { margin: 8px 0 0 18px; padding: 0; }
.disclosure-body li { margin-bottom: 4px; }
.disclosure-commission { background: var(--primary-soft, #f0fdfa); border-color: var(--primary-border, #99f6e4); }
.disclosure-commission .disclosure-head { color: var(--primary-ink, #0f766e); }
.disclosure-trial { background: var(--warning-bg, #fffbeb); border-color: var(--warning-border, #fde68a); }
.disclosure-trial .disclosure-head { color: var(--warning-ink, #92400e); }
.trial-agree-intro { margin: 0 0 16px; line-height: 1.6; color: var(--text-primary); font-size: 14px; }
.trial-terms-box { display: flex; flex-direction: column; gap: 12px; background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 10px; padding: 16px; margin-bottom: 16px; }
.trial-term-item { display: flex; align-items: flex-start; gap: 12px; font-size: 13.5px; line-height: 1.6; color: var(--text-primary); }
.trial-term-icon { flex-shrink: 0; width: 22px; height: 22px; background: #059669; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; margin-top: 1px; }
.trial-consent-row { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: var(--warning-bg, #fffbeb); border: 1.5px solid #fbbf24; border-radius: 8px; font-size: 13px; cursor: pointer; line-height: 1.5; color: var(--text-primary); margin-bottom: 4px; }
.trial-consent-row input { margin-top: 2px; flex-shrink: 0; width: 16px; height: 16px; cursor: pointer; accent-color: #059669; }

/* Delete-program choice modal (archive vs delete-now) */
.del-modal { padding: 22px; }
.del-head { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 16px; }
.del-ic { flex-shrink: 0; width: 40px; height: 40px; border-radius: 11px; background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #c2410c); display: flex; align-items: center; justify-content: center; }
.del-title { font-size: 16px; font-weight: 700; color: var(--text-primary); line-height: 1.35; }
.del-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.del-option { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
.del-option-text p { margin: 4px 0 0; font-size: 13px; line-height: 1.55; color: var(--text-muted); }
.del-option-name { font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.del-rec { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--positive-ink, #047857); background: var(--positive-bg, #d1fae5); border-radius: 999px; padding: 2px 7px; }
.del-option-btn { margin-top: 12px; }
.del-option-danger { border-color: var(--danger-border, #fecaca); background: var(--danger-bg); }
.del-ack { display: flex; align-items: flex-start; gap: 10px; margin: 12px 0; padding: 10px 12px; background: var(--surface, #ffffff); border: 1.5px solid var(--danger-border, #fca5a5); border-radius: 9px; font-size: 12.5px; line-height: 1.5; color: var(--text-primary); cursor: pointer; }
.del-ack input { margin-top: 2px; flex-shrink: 0; width: 16px; height: 16px; cursor: pointer; accent-color: #dc2626; }
.del-delete-btn { background: #dc2626; color: #fff; border: 1px solid #dc2626; }
.del-delete-btn:hover:not(:disabled) { background: #b91c1c; }
.del-delete-btn:disabled { opacity: .5; cursor: not-allowed; }
.del-foot { display: flex; justify-content: flex-end; margin-top: 4px; }
.btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid transparent; }
.btn-ghost:hover { background: var(--bg, #f1f5f9); color: var(--text-primary); }

/* Placement toggles (mirrors the listing form) */
.dp-switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.dp-switch input { opacity: 0; width: 0; height: 0; }
.dp-slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 999px; transition: background .15s; }
.dp-slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .15s; }
.dp-switch input:checked + .dp-slider { background: var(--primary); }
.dp-switch input:checked + .dp-slider::before { transform: translateX(18px); }
.pl-opt { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1.5px solid var(--border); border-radius: 12px; margin-bottom: 12px; transition: border-color .15s, background .15s; }
.pl-opt.on { border-color: var(--primary); background: var(--primary-soft, #f0fdfa); }
.pl-opt-icon { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pl-opt-icon.mkt { background: var(--primary-soft, #ccfbf1); color: var(--primary-ink, #0f766e); }
.pl-opt-icon.site { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #6d28d9); }
.pl-opt-body { flex: 1; min-width: 0; }
.pl-opt-name { font-size: 14.5px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pl-opt-desc { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
.placement-rec { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--positive-ink, #047857); background: var(--positive-bg, #d1fae5); border-radius: 999px; padding: 2px 7px; }
.placement-warn { display: flex; align-items: center; gap: 7px; margin-top: 2px; padding: 9px 12px; background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 8px; font-size: 12.5px; color: var(--warning-ink, #92400e); }

/* Hide-from-marketplace confirm (same as the listing form) */
.mkt-modal { max-width: 380px; text-align: center; padding: 26px 24px 20px; }
.mkt-icon { width: 60px; height: 60px; border-radius: 50%; background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #dc2626); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.mkt-title { font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 18px; }
.mkt-points { display: flex; flex-direction: column; gap: 11px; text-align: left; margin-bottom: 22px; }
.mkt-point { display: flex; align-items: center; gap: 11px; font-size: 14px; font-weight: 600; }
.mkt-point svg { flex-shrink: 0; }
.mkt-point.neg { color: var(--danger-ink, #b91c1c); }
.mkt-point.pos { color: var(--positive-ink, #15803d); }
.mkt-actions { display: flex; flex-direction: column; gap: 6px; }
.mkt-keep { width: 100%; justify-content: center; }
.mkt-anyway { background: transparent; border: 0; color: var(--text-muted); font-size: 13px; font-weight: 600; padding: 9px; cursor: pointer; border-radius: 8px; }
.mkt-anyway:hover { color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); }
.disclosure-preview { background: var(--surface-hover, #f8fafc); border-color: var(--border-strong, #cbd5e1); }
.preview-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
.preview-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 13px; }
.preview-row.muted { color: var(--text-muted); font-size: 12px; }
.consent-row { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; padding: 10px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 8px; font-size: 13px; cursor: pointer; }
.consent-row input { margin-top: 2px; }

.banner { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; border: 1px solid; }
.banner-warning { background: var(--warning-bg, #fff7ed); border-color: var(--warning-border, #fed7aa); color: var(--warning-ink, #9a3412); }
.banner-info { background: var(--info-bg, #eff6ff); border-color: var(--info-border, #bfdbfe); color: var(--info-ink, #1e3a5f); }
.banner-sub { font-size: 12px; margin-top: 3px; opacity: 0.9; }

.diff-line { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 4px 0; border-bottom: 1px dashed var(--border); }
.diff-line:last-child { border-bottom: 0; }
.diff-field { font-weight: 600; text-transform: uppercase; font-size: 11px; color: var(--text-muted); min-width: 120px; }
.diff-val { padding: 2px 6px; border-radius: 4px; }
.diff-val.before { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #991b1b); }
.diff-val.after { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #166534); }
.diff-arrow { color: var(--text-muted); }

.role-toggle { display: inline-flex; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 3px; }
.role-toggle button { padding: 5px 12px; border-radius: 999px; font-size: 12px; border: 0; background: transparent; cursor: pointer; color: var(--text-muted); font-weight: 500; }
.role-toggle button.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: 0 1px 2px rgba(0,0,0,0.08); }

.img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 8px; margin-bottom: 8px; }
.img-tile { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); background: var(--surface-hover, #f8fafc); }
.img-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.img-remove { position: absolute; top: 4px; right: 4px; background: rgba(17,24,39,0.8); color: #fff; border: 0; border-radius: 6px; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.img-add-row { display: flex; gap: 8px; }
.img-add-row input { flex: 1; }
.img-cover-badge { position: absolute; bottom: 4px; left: 4px; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #fff; background: rgba(17,24,39,.8); padding: 2px 6px; border-radius: 5px; }
.img-dropzone { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 22px; border: 1.5px dashed var(--border); border-radius: 10px; color: var(--text-muted); font-size: 13px; cursor: pointer; text-align: center; transition: .15s; }
.img-dropzone:hover, .img-dropzone.active { border-color: var(--primary, #7c3aed); background: rgba(124,58,237,.05); color: var(--primary-ink, #7c3aed); }
.img-sub { font-size: 11px; opacity: .8; }
.img-spin { animation: imgspin 1s linear infinite; } @keyframes imgspin { to { transform: rotate(360deg); } }
.field-error { font-size: 12.5px; color: var(--danger-ink, #dc2626); margin-top: 6px; }
.img-url-fallback { margin-top: 8px; font-size: 12.5px; }
.img-url-fallback summary { cursor: pointer; color: var(--text-muted); user-select: none; }
.img-url-fallback .img-add-row { margin-top: 8px; }

/* ── Subscribers section header ──────────────────── */
.sub-section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 36px 0 14px; flex-wrap: wrap; }
.sub-section-title { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: var(--text); }
.sub-section-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ── Empty state ─────────────────────────────────── */
.sub-empty-state { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 56px 24px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; text-align: center; }
.sub-empty-title { font-size: 15px; font-weight: 600; color: var(--text); }
.sub-empty-sub { font-size: 13px; color: var(--text-muted); }
.sub-empty-filtered { display: flex; align-items: center; gap: 10px; justify-content: center; padding: 32px; color: var(--text-muted); font-size: 13px; }

/* ── Summary bar ─────────────────────────────────── */
.sub-summary-row { display: flex; align-items: center; gap: 0; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 0 4px; margin-bottom: 12px; flex-wrap: wrap; overflow: hidden; }
.sub-stat { display: flex; flex-direction: column; align-items: center; padding: 12px 20px; gap: 2px; }
.sub-stat-val { font-size: 17px; font-weight: 700; color: var(--text); line-height: 1.2; white-space: nowrap; }
.sub-stat-val.trial { color: var(--warning-ink, #b45309); }
.sub-stat-val.paid { color: var(--positive-ink, #059669); }
.sub-stat-val.cancelled { color: var(--danger-ink, #dc2626); }
.sub-stat-val.mrr { color: var(--info-ink, #0891b2); font-size: 14px; }
.sub-stat-val.payout { color: var(--positive-ink, #059669); font-size: 14px; }
.sub-stat-val.platform { color: var(--warning-ink, #b45309); font-size: 14px; }
.sub-stat-lbl { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
.sub-stat-sep { width: 1px; height: 32px; background: var(--border); margin: 0 4px; }

/* ── Toolbar ─────────────────────────────────────── */
.status-pills { display: flex; gap: 5px; flex-wrap: wrap; }
.status-pill { padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted); cursor: pointer; transition: all 0.15s; white-space: nowrap; }
.status-pill:hover { border-color: var(--primary); color: var(--primary-ink); }
.status-pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.filter-toggle-btn { position: relative; }
.filter-badge { display: inline-flex; align-items: center; justify-content: center; background: var(--primary); color: #fff; border-radius: 999px; min-width: 17px; height: 17px; font-size: 10px; font-weight: 700; padding: 0 3px; margin-left: 2px; }
.filter-active { border-color: var(--primary); color: var(--primary-ink); }

/* ── Filter panel ────────────────────────────────── */
.filter-panel { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.filter-panel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.filter-panel-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }

/* ── Table ───────────────────────────────────────── */
.sub-table-wrap { padding: 0; overflow-x: auto; }
.sub-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sub-table thead tr { background: var(--surface-hover, #f8fafc); border-bottom: 2px solid var(--border); }
.sub-table th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); white-space: nowrap; user-select: none; }
.sub-table th.sortable { cursor: pointer; }
.sub-table th.sortable:hover { color: var(--text); }
.sort-icon { display: inline-flex; align-items: center; margin-left: 3px; vertical-align: middle; }
.muted-icon { opacity: 0.3; }
.sub-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.sub-row:last-child td { border-bottom: 0; }
.sub-row:hover td { background: var(--surface-hover, #fafafa); }
.sub-customer { display: flex; align-items: center; gap: 10px; }
.sub-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sub-name { font-weight: 600; color: var(--text); line-height: 1.3; }
.sub-email { font-size: 12px; color: var(--text-muted); }
.sub-prog-name { font-weight: 500; color: var(--text); font-size: 13px; }
.sub-meta { font-size: 12px; color: var(--text-muted); }
.mono { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 12.5px; }
.provider-share { color: var(--positive-ink, #065f46); }
.platform-fee { color: var(--warning-ink, #92400e); }

/* Share modal (copy link + QR) */
.share-modal { max-width: 420px; }
.share-body { display: flex; flex-direction: column; gap: 14px; }
.share-hint { font-size: 13px; color: var(--text-muted); line-height: 1.5; margin: 0; }
.share-linkrow { display: flex; gap: 8px; }
.share-link { flex: 1; min-width: 0; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; padding: 9px 11px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text); }
.share-copy { flex-shrink: 0; white-space: nowrap; }
.share-qr-wrap { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.share-qr { width: 200px; height: 200px; border-radius: 12px; border: 1px solid var(--border); background: var(--surface, #ffffff); padding: 8px; }
.share-qr-empty { display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
.share-open { display: inline-flex; align-items: center; gap: 6px; align-self: center; font-size: 13px; font-weight: 700; color: var(--primary-ink, #0f766e); text-decoration: none; }
.share-open:hover { text-decoration: underline; }

.sessions-count { display: inline-flex; align-items: center; justify-content: center; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
.trial-days-left { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.sub-status-badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; border: 1px solid; }
.badge-trial { background: var(--warning-bg, #fffbeb); color: var(--warning-ink, #92400e); border-color: var(--warning-border, #fde68a); }
.badge-paid { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border-color: var(--positive-border, #a7f3d0); }
.badge-cancelled { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #991b1b); border-color: var(--danger-border, #fecaca); }
.sub-actions { display: flex; gap: 6px; align-items: center; }

/* ── Duration section ─────────────────────────────── */
.duration-section { margin: 4px 0 16px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.duration-toggle { display: flex; border-bottom: 1px solid var(--border); background: var(--surface-hover, #f8fafc); }
.duration-opt { flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: transparent; border: 0; cursor: pointer; transition: all 0.15s; }
.duration-opt:first-child { border-right: 1px solid var(--border); }
.duration-opt.active { background: var(--surface, #ffffff); color: var(--text); font-weight: 600; }
.duration-opt.active svg { color: var(--primary-ink); }
.duration-hint { padding: 12px 16px; font-size: 13px; color: var(--text-muted); }
.duration-fields { padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
.duration-summary { display: flex; align-items: center; gap: 7px; padding: 8px 12px; background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 8px; font-size: 12.5px; font-weight: 500; color: var(--primary-ink, #0f766e); }
.input-auto { background: var(--primary-soft, #f0fdfa) !important; color: var(--primary-ink, #0f766e); font-weight: 600; }

/* ── Listing multi-select ─────────────────────────── */
.listing-checkbox-list { display: flex; flex-direction: column; gap: 2px; border: 1px solid var(--border); border-radius: 8px; padding: 6px 4px; max-height: 160px; overflow-y: auto; background: var(--surface, #ffffff); }
.listing-checkbox-row { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: background 0.12s; }
.listing-checkbox-row:hover { background: var(--bg, #f1f5f9); }
.listing-checkbox-row input[type="checkbox"] { width: 15px; height: 15px; flex-shrink: 0; accent-color: var(--primary); cursor: pointer; }
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); }

/* ── Currency auto badge ─────────────────────────── */
.currency-badge { display: inline-block; margin-left: 6px; background: var(--info-bg, #e0f2fe); color: var(--info-ink, #0369a1); font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.04em; vertical-align: middle; }

/* ── Conflict modal ───────────────────────────────── */
.conflict-intro { font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; }
.conflict-table-wrap { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; max-height: 260px; overflow-y: auto; }
.conflict-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.conflict-table thead tr { background: var(--surface-hover, #f8fafc); }
.conflict-table th { padding: 8px 12px; text-align: left; font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border); font-size: 12px; }
.conflict-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
.conflict-table tbody tr:last-child td { border-bottom: none; }
.conflict-table tbody tr:nth-child(even) { background: var(--surface-hover, #fafafa); }

/* ── Danger button ───────────────────────────────── */
.btn-danger { background: #ef4444; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background 0.15s; }
.btn-danger:hover { background: #dc2626; }
.btn-warning { background: #f59e0b; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: background 0.15s; }
.btn-warning:hover { background: #d97706; }
.btn-success-outline { background: transparent; color: var(--positive-ink, #059669); border: 1.5px solid #059669; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: background 0.15s, color 0.15s; }
.btn-success-outline:hover { background: #059669; color: #fff; }

/* ── Phone (≤600px): 360px-wide layout fixes, mobile-only ── */
@media (max-width: 600px) {
  /* Fixed-duration Start/End/Weeks: 3 date inputs can't fit 3-up at 360px */
  .grid-3 { grid-template-columns: 1fr; }

  /* Plan card: billing control + price stack full-width instead of side-by-side */
  .plan-top { flex-direction: column; align-items: stretch; }

  /* Resubmission diff rows: wrap label/before/after instead of overflowing the drawer */
  .diff-line { flex-wrap: wrap; }
  .diff-field { min-width: 100%; }

  /* Drawer accordion gutters: reclaim horizontal space for the narrow content column */
  .pg-section-head { padding: 13px 14px; }
  .pg-section-body { padding: 8px 14px 14px; }

  /* Empty/gate states: less dead space so the primary CTA stays near the fold */
  .cs-no-programs-state { padding: 28px 16px; }
  .cs-nps-icon { width: 56px; height: 56px; }

  /* Micro-typography floor: nothing below 11px on phones */
  .offer-rec, .offer-required, .del-rec, .placement-rec,
  .filter-badge, .img-cover-badge, .prog-reserved { font-size: 11px; }
}
.cs-filter-slot { display: flex; flex-direction: column; gap: 4px; max-width: 260px; }
.cs-filter-label { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .05em; }
</style>
