<script setup>
import { ref, computed, watch } from 'vue'
import { fetchBookingCrm, fetchBookingOccupancy, fetchBookingLookups, updateDashboardBooking, registerCustomer, collectDashboardPayment, generateDashboardPaymentLink, firstError } from '../lib/bookings.js'
import { X, MapPin, Calendar, Clock, User, Mail, Phone, Star, CreditCard, FileText, PhoneCall, Receipt, Send, Tag, Check, CircleAlert as AlertCircle, Loader, Pencil, StickyNote, ChevronRight, ChevronDown, Plus } from 'lucide-vue-next'
import WhatsappIcon from './WhatsappIcon.vue'
import CustomerHistoryModal from './CustomerHistoryModal.vue'
import { openCustomer } from '../lib/customerModal.js'
import { slotConflicts, fmt12 } from '../lib/slots.js'
import { bookingRefLabel } from '../lib/bookingRef.js'
import { dayWindows, slotsForWindows, slotInWindows } from '../lib/specialDays.js'
import { notifyBookingChanged, notifyPaymentReceipt } from '../customer/notificationDispatch.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { bookingChannel, bookingCreatorLabel } from '../lib/bookingMeta.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { CAP } from '../lib/permissions.js'
import { recordRedemption, validatePromo } from '../lib/promocodes.js'
import { hasActivePayoutMethodFor, isAutomaticPayout, isWalletReady } from '../lib/walletSetup.js'
import { appBaseUrl } from '../lib/appBase.js'
import { pushToast } from '../lib/toast.js'
import QRCode from 'qrcode'

const { can, role, isSuperAdmin, isCountryAdmin, currentOwnerUserId } = useCurrentUser()

// Who may see pending payments beyond this booking's business:
// ONLY Bookboll platform roles (super admin sees all; country admin / platform
// staff see their jurisdiction — enforced below). Every provider-side account is
// strictly limited to its own business: a staff row that is missing its
// owner_user_id link sees NOTHING extra, never everything (that hole leaked
// other businesses' outstanding payments to providers).
const isPlatformSide = computed(() =>
  isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')
const canSeeAllPayments = computed(() => isPlatformSide.value)

// Jurisdiction check for the pending-payments context list (country_admin /
// platform_staff). Fed with the pending bookings' own venues (loaded below).
const pendLocs = ref([])
const pendCities = ref([])
const { inJurisdictionLoc } = useJurisdiction({ locations: pendLocs, cities: pendCities })

const props = defineProps({
  bookingId:   { type: String,  default: null },
  startInEdit: { type: Boolean, default: false },
  // Open straight into the Accept Payment sheet for this pending payment row
  // (used by the Payments table's Accept button — one collect flow everywhere).
  collectPaymentId: { type: String, default: null },
})
const emit = defineEmits(['close', 'viewBooking'])

// ── Data ──────────────────────────────────────────────────────────────────────
const booking    = ref(null)
const customer   = ref(null)
const listing    = ref(null)
const location   = ref(null)
const payments   = ref([])
const auditLogs  = ref([])
const loading    = ref(false)

// Other PENDING payments (scoped) surfaced for context. Each item:
// { id, amount, currency, payment_type, booking_id, ref, venue, listingName, booking_date }
const otherPendingSameCustomer = ref([])  // this customer's other unpaid bookings
const otherPendingBusiness     = ref([])  // other customers' unpaid bookings (same business)
const showBusinessPending      = ref(false) // collapsible — collapsed by default to stay focused

// ── Promocode ─────────────────────────────────────────────────────────────────
const promoCode      = ref('')
const promoApplied   = ref(null)
// Promo already redeemed on this booking (discount baked into the total) —
// shown read-only instead of the apply input. { code, discount_amount }
const existingPromo  = ref(null)
const promoError     = ref('')
const promoLoading   = ref(false)

// ── Customer history ──────────────────────────────────────────────────────────
const showHistory = ref(false)

// ── Edit booking ──────────────────────────────────────────────────────────────
const editing      = ref(false)
const editSaving   = ref(false)
const editError    = ref('')

// Edit form fields mirroring the create drawer flow
const editVenueId       = ref('')
const editListingId     = ref('')
const editCustomerId    = ref('')
const editDate          = ref('')
const editBookingStatus = ref('confirmed')
const editPaymentMethod = ref('')
const editNotes         = ref('')
const editSelectedSlot  = ref(null)

// Edit master data
const editLocations     = ref([])
const editListings      = ref([])
const editCustomers     = ref([])
const editExistingBookings = ref([])
const editPricingCells  = ref([])
const editPricingGroups = ref([])
const editListingHours  = ref([])
const editSpecialRows   = ref([])
const editSlotsLoading  = ref(false)

// Edit add-new-customer inline
const editShowAddCustomer  = ref(false)
const editNewCustomerName  = ref('')
const editNewCustomerPhone = ref('')
const editNewCustomerEmail = ref('')
const editSavingCustomer   = ref(false)

// Edit customer search
const editCustomerSearch   = ref('')
const editShowCustDropdown = ref(false)

const editVenueListings = computed(() =>
  editVenueId.value ? editListings.value.filter(l => l.location_id === editVenueId.value) : []
)
const editSelectedListing = computed(() => editListings.value.find(l => l.id === editListingId.value) || null)
const editSelectedCustomer = computed(() => editCustomers.value.find(c => c.id === editCustomerId.value))
const editFilteredCustomers = computed(() => {
  const q = editCustomerSearch.value.toLowerCase()
  return q ? editCustomers.value.filter(c =>
    c.full_name.toLowerCase().includes(q) || c.phone?.includes(q) || c.email?.toLowerCase().includes(q)
  ) : editCustomers.value
})

watch(editVenueId, () => { editListingId.value = ''; editSelectedSlot.value = null })
watch(editListingId, () => { editSelectedSlot.value = null })
watch([editListingId, editDate], async ([lid, date]) => {
  editExistingBookings.value = []
  editPricingCells.value = []
  editPricingGroups.value = []
  editListingHours.value = []
  editSpecialRows.value = []
  if (!lid || !date) return
  editSlotsLoading.value = true
  const occ = await fetchBookingOccupancy(lid, date)
  const occData = occ.success ? (occ.data || {}) : {}
  editExistingBookings.value = (occData.existing_bookings || []).filter(b => String(b.id) !== String(booking.value?.id))
  editPricingCells.value     = occData.pricing_cells || []
  editPricingGroups.value    = occData.pricing_groups || []
  editListingHours.value     = occData.listing_hours || []
  editSpecialRows.value      = occData.special_days || []
  editSlotsLoading.value = false
})

function editResolvePrice(startTime) {
  if (!editDate.value || !editSelectedListing.value) return Number(editSelectedListing.value?.base_price) || 0
  const dow = new Date(editDate.value + 'T00:00:00').getDay()
  const cell = editPricingCells.value.find(c => c.day_of_week === dow && c.slot_time === startTime)
  if (cell) {
    if (cell.direct_price != null) return Number(cell.direct_price)
    if (cell.pricing_group_id) {
      const group = editPricingGroups.value.find(g => g.id === cell.pricing_group_id)
      if (group) return Number(group.price)
    }
  }
  return Number(editSelectedListing.value?.base_price) || 0
}

// Special-day override → weekly hours → default window (reschedule must respect
// the target date's actual schedule, not a fixed grid).
const editDaySched = computed(() => {
  if (!editSelectedListing.value || !editDate.value) return null
  return dayWindows({
    date: editDate.value,
    listing: editSelectedListing.value,
    hoursRows: editListingHours.value,
    specialRows: editSpecialRows.value,
  })
})

const editSlots = computed(() => {
  if (!editSelectedListing.value) return []
  const sched = editDaySched.value
  if (!sched || sched.closed) return []
  return slotsForWindows(editSelectedListing.value, sched.windows, editDate.value)   // buffer baked into the slot step
})

function editIsBooked(slot) {
  const buffer = Number(editSelectedListing.value?.buffer_time) || 0
  return slotConflicts(slot, editExistingBookings.value, buffer)
}

const editTotal = computed(() => editSelectedSlot.value ? editResolvePrice(editSelectedSlot.value.start_time) : 0)

// No invented currency anywhere on this screen: an amount whose currency cannot
// be resolved prints bare rather than claiming to be dirhams.
const editCurrency = computed(() => editLocations.value.find(l => l.id === editVenueId.value)?.currency_override || '')

async function openEdit() {
  editError.value = ''
  editVenueId.value       = booking.value.location_id || ''
  editListingId.value     = booking.value.listing_id  || ''
  editCustomerId.value    = booking.value.customer_id || ''
  editDate.value          = booking.value.booking_date || new Date().toISOString().slice(0,10)
  editBookingStatus.value = booking.value.booking_status || 'confirmed'
  editPaymentMethod.value = booking.value.payment_method || ''
  editNotes.value         = booking.value.notes || ''
  editShowAddCustomer.value = false
  editCustomerSearch.value  = ''

  // Pre-select the current slot
  editSelectedSlot.value = {
    start_time: booking.value.start_time,
    end_time:   booking.value.end_time,
  }

  editing.value = true

  // Load master data
  const dash = await fetchBookingLookups({ withCustomers: true })
  const d = dash.success ? (dash.data || {}) : {}
  editLocations.value = (d.locations || []).filter(l => l.status === 'active')
  editListings.value  = (d.listings || []).filter(l => l.status === 'active')
  editCustomers.value = d.customers || []
}

async function createEditCustomer() {
  if (!editNewCustomerName.value.trim()) return
  editSavingCustomer.value = true
  const res = await registerCustomer({
    name: editNewCustomerName.value.trim(),
    phone: editNewCustomerPhone.value.trim() || null,
    email: editNewCustomerEmail.value.trim() || `c-${Date.now()}@placeholder.local`,
  })
  editSavingCustomer.value = false
  const user = res.success ? (res.data?.user || res.data?.customer) : null
  if (user) {
    const row = { id: String(user.id), full_name: user.name || user.full_name, phone: user.phone, email: user.email }
    editCustomers.value = [...editCustomers.value, row].sort((a,b) => (a.full_name||'').localeCompare(b.full_name||''))
    editCustomerId.value = row.id
    editShowAddCustomer.value = false
    editNewCustomerName.value = editNewCustomerPhone.value = editNewCustomerEmail.value = ''
  }
}

function selectEditCustomer(c) {
  editCustomerId.value = c.id
  editCustomerSearch.value = ''
  editShowCustDropdown.value = false
}

async function saveEdit() {
  editError.value = ''
  if (!editVenueId.value)        { editError.value = 'Please select a venue.'; return }
  if (!editListingId.value)      { editError.value = 'Please select a listing.'; return }
  if (!editSelectedSlot.value)   { editError.value = 'Please select a time slot.'; return }
  // The picked time must exist inside the target date's resolved schedule
  // (special day → weekly hours → default) — a slot carried over from the
  // original date must not land on a closed day or outside a custom window.
  {
    const sched = editDaySched.value
    if (!sched || sched.closed) {
      editError.value = 'That date is closed (special day). Please pick another date.'; return
    }
    if (!slotInWindows(editSelectedSlot.value, sched.windows)) {
      editError.value = 'The selected time is outside that date’s schedule. Please pick a slot from the list.'; return
    }
  }
  if (!editPaymentMethod.value)  { editError.value = 'Please select a payment method.'; return }
  editSaving.value = true
  // Field-level change summary for the activity trail (compare BEFORE the write).
  const before = booking.value
  const changes = []
  if (before.booking_date !== editDate.value) changes.push(`date ${fmtDate(before.booking_date)} → ${fmtDate(editDate.value)}`)
  if (before.start_time !== editSelectedSlot.value.start_time || before.end_time !== editSelectedSlot.value.end_time)
    changes.push(`time ${fmt12(before.start_time)}–${fmt12(before.end_time)} → ${fmt12(editSelectedSlot.value.start_time)}–${fmt12(editSelectedSlot.value.end_time)}`)
  if (before.location_id !== editVenueId.value) changes.push('venue changed')
  if (before.listing_id !== editListingId.value) changes.push('listing changed')
  if ((before.customer_id || null) !== (editCustomerId.value || null)) changes.push('customer changed')
  if (before.booking_status !== editBookingStatus.value) changes.push(`status ${before.booking_status} → ${editBookingStatus.value}`)
  if (before.payment_method !== editPaymentMethod.value) changes.push(`payment method → ${editPaymentMethod.value}`)
  if (Number(before.total) !== Number(editTotal.value)) changes.push(`total ${Number(before.total).toLocaleString()} → ${Number(editTotal.value).toLocaleString()}`)
  if ((before.notes || '') !== editNotes.value.trim()) changes.push('notes updated')
  const upd = await updateDashboardBooking(booking.value.id, {
    location_id:    editVenueId.value,
    listing_id:     editListingId.value,
    customer_id:    editCustomerId.value || null,
    booking_date:   editDate.value,
    start_time:     editSelectedSlot.value.start_time,
    end_time:       editSelectedSlot.value.end_time,
    total:          editTotal.value,
    booking_status: editBookingStatus.value,
    payment_method: editPaymentMethod.value,
    notes:          editNotes.value.trim() || '',
  })
  if (!upd.success) {
    editSaving.value = false
    editError.value = firstError(upd, 'Could not save booking.')
    return
  }

  const crm = await fetchBookingCrm(booking.value.id)
  const d = crm.success ? (crm.data || {}) : {}
  booking.value  = d.booking || upd.data?.booking
  customer.value = d.customer || customer.value
  listing.value  = d.listing || listing.value
  location.value = d.location || location.value
  payments.value = d.payments || payments.value
  auditLogs.value = d.audit_logs || auditLogs.value

  // Fire-and-forget: tell the customer their booking was changed.
  try {
    if (customer.value) notifyBookingChanged({
      ownerId: location.value?.owner_user_id || null,
      customer: { id: customer.value.id, name: customer.value.full_name, email: customer.value.email, phone: customer.value.phone },
      booking: {
        listing: listing.value?.name, venue: location.value?.name, business_name: location.value?.name,
        date: booking.value?.booking_date, start_time: booking.value?.start_time ? fmt12(booking.value.start_time) : '',
        end_time: booking.value?.end_time ? fmt12(booking.value.end_time) : '',
        // The booking's own reference, not a slice of the row id.
        code: booking.value?.booking_id || String(booking.value?.id || ''),
        currency: location.value?.currency_override || '',
      },
    })
  } catch { /* non-blocking */ }

  // Activity trail: what changed, who did it, exactly when (best-effort, never blocks).
  await logAudit({
    action: AUDIT.UPDATED, entity_type: 'booking', entity_id: before.id,
    details: `Booking edited in the admin dashboard${changes.length ? ' — ' + changes.join(', ') : ''}`,
  })
  const crmLogs = await fetchBookingCrm(before.id)
  if (crmLogs.success) auditLogs.value = crmLogs.data?.audit_logs || auditLogs.value

  editSaving.value = false
  editing.value    = false
}

// ── Mark as paid ──────────────────────────────────────────────────────────────
const collectFor  = ref(null)   // pending payment row open in the Accept Payment sheet
const markMethod  = ref('')     // collection method — staff must pick one, nothing preselected
const markSaving  = ref(false)
const COLLECT_METHODS = [
  { value: 'cash',          label: 'Cash' },
  { value: 'card',          label: 'Card' },
  { value: 'cheque',        label: 'Cheque' },
  { value: 'wire_transfer', label: 'Wire Transfer' },
]

// Pay-by-QR state must be declared before the bookingId watch (it resets these).
const ownerWallets = ref([])
const payQrImg = ref('')
const linkCopied = ref(false)
let payQrTimer = null
const payLinkOverride = ref('')

/** The booking's own reference — see lib/bookingRef.js. */
const makeRef = (b) => (b ? bookingRefLabel(b) : '')

/**
 * Load OTHER bookings' PENDING payments for context, properly scoped:
 *   - A provider business only sees pending payments on bookings at ITS OWN
 *     locations (location.owner_user_id === currentOwnerUserId); a row with no
 *     owner link sees none.
 *   - Super admin sees all; country_admin / platform_staff only their
 *     jurisdiction's venues.
 *   - Only pending payments are surfaced — never other bookings' paid ones.
 * Split into this customer's other unpaid bookings vs. the rest of the business.
 */
async function loadOtherPending(cur, rows = []) {
  if (!cur || !rows.length) return
  pendLocs.value = rows
    .filter(p => p.location_id)
    .map(p => ({ id: p.location_id, name: p.venue, owner_user_id: p.owner_user_id }))

  const sameCust = []
  const business = []
  for (const p of rows) {
    if (isPlatformSide.value) {
      if (!inJurisdictionLoc(p.location_id)) continue
    } else if (!currentOwnerUserId.value || p.owner_user_id !== currentOwnerUserId.value) continue
    const item = {
      id: p.id,
      booking_id: p.booking_id,
      amount: Number(p.amount || 0),
      currency: p.currency || '',
      payment_type: p.payment_type,
      booking_date: p.booking_date,
      ref: makeRef({ id: String(p.booking_id || ''), booking_id: p.reference || '' }),
      venue: p.venue || '—',
      listingName: p.listingName || '',
    }
    if (cur.customer_id && String(p.customer_id) === String(cur.customer_id)) sameCust.push(item)
    else business.push(item)
  }
  const byDate = (a, z) => (String(a.booking_date) < String(z.booking_date) ? -1 : 1)
  sameCust.sort(byDate)
  business.sort(byDate)
  otherPendingSameCustomer.value = sameCust
  otherPendingBusiness.value = business
}

/** "AED 210.00 · SAR 200.00" summary for a list of pending items. */
function summarizeAmounts(items) {
  const byCur = {}
  for (const it of items) byCur[it.currency || ''] = (byCur[it.currency || ''] || 0) + it.amount
  return Object.entries(byCur)
    // An unresolved currency contributes its own subtotal, printed bare.
    .map(([c, a]) => [c, a.toLocaleString(undefined, { minimumFractionDigits: 2 })].filter(Boolean).join(' '))
    .join(' · ')
}

watch(() => props.bookingId, async (id) => {
  if (!id) { booking.value = null; return }
  loading.value = true
  try {
    booking.value = null
    payments.value = []
    auditLogs.value = []
    otherPendingSameCustomer.value = []
    otherPendingBusiness.value = []
    showBusinessPending.value = false
    promoApplied.value = null
    promoError.value = ''
    promoCode.value = ''
    existingPromo.value = null
    collectFor.value = null
    payQrImg.value = ''
    ownerWallets.value = []
    clearInterval(payQrTimer)
    showHistory.value = false
    editing.value = false

    const res = await fetchBookingCrm(id)
    const d = res.success ? (res.data || {}) : {}
    booking.value = d.booking || null
    payments.value = d.payments || []
    auditLogs.value = d.audit_logs || []
    existingPromo.value = d.existing_promo || (d.booking && Number(d.booking.discount) > 0
      ? { code: '', discount_amount: Number(d.booking.discount) || 0 }
      : null)
    customer.value = d.customer || null
    listing.value = d.listing || null
    location.value = d.location || null
    ownerWallets.value = d.wallets || []
    if (d.booking) {
      try { await loadOtherPending(d.booking, d.other_pending || []) } catch { /* optional context */ }
    }

    // If opened from the edit shortcut, jump straight into edit mode
    if (props.startInEdit && booking.value) {
      openEdit()
    }
    // Opened from the Payments table's Accept button → jump straight into the
    // Accept Payment sheet for that pending installment.
    if (props.collectPaymentId && booking.value) {
      const want = String(props.collectPaymentId)
      const row = payments.value.find(p => String(p.id) === want && (p.status === 'pending' || p.status === 'unpaid'))
      if (row) openCollect(row)
    }
  } finally {
    loading.value = false
  }
}, { immediate: true })

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
}

function fmtTime(t) {
  if (!t) return ''
  return fmt12(t)
}

function fmtDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
    + ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}

// ── Activity timeline ─────────────────────────────────────────────────────────
// Everything that happened to this booking, in order, each with an exact
// date+time: created (and WHERE — marketplace / provider site / dashboard),
// every payment collected, every admin edit (audit log), cancellation, check-in.
function createdSourceText(b) {
  const o = b?.origin || ''
  const ch = bookingChannel(b)
  if (ch === 'provider_site') return "on the provider's own website"
  if (ch === 'manual') return 'manually in the admin dashboard'
  if (ch === 'auto') return 'automatically (membership schedule)'
  if (ch === 'marketplace') return o === 'bookboll_app' ? 'on the Bookboll app' : o === 'customer_game' ? 'from a hosted event' : 'on the Bookboll marketplace'
  return 'through an unrecorded channel'
}
const PMT_LABELS = { booking: 'Full payment', deposit: 'Deposit', balance: 'Balance', remaining: 'Balance' }
const METHOD_LABELS = { cash: 'cash', card: 'card', cheque: 'cheque', wire_transfer: 'wire transfer', online: 'online' }
function fmtAmt(p) { return `${p.currency || currency.value} ${Number(p.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}` }
const timeline = computed(() => {
  const b = booking.value
  if (!b) return []
  const ev = []
  ev.push({
    key: 'created', ts: b.created_at, tone: 'create',
    text: `Booking created · ${bookingCreatorLabel(b)}`,
    sub: `Booked ${createdSourceText(b)}${bookingChannel(b) === 'manual' ? ' · No platform fee' : ''}`,
  })
  if (b.creation_context?.history_description) {
    ev[0].recorded = b.creation_context.history_description
  }
  for (const p of payments.value) {
    // Customer-facing installments only — commission/earning ledger splits are internal.
    if (/_commission$|_earning$/.test(p.payment_type || '')) continue
    const label = PMT_LABELS[p.payment_type] || 'Payment'
    if (p.status === 'paid') {
      ev.push({
        key: 'pmt' + p.id, ts: p.paid_at || p.created_at, tone: 'paid',
        text: `${label} of ${fmtAmt(p)} ${p.method === 'online' ? 'paid online' : `collected in ${METHOD_LABELS[p.method] || p.method || 'person'}`}`,
        sub: p.paid_at ? '' : 'Recorded with the booking',
      })
    } else {
      ev.push({
        key: 'pmt' + p.id, ts: p.created_at, tone: 'muted',
        text: `${label} of ${fmtAmt(p)} recorded — awaiting payment`,
        sub: '',
      })
    }
  }
  for (const log of auditLogs.value) {
    ev.push({
      key: 'log' + log.id, ts: log.created_at, tone: 'log',
      text: log.details || log.action,
      sub: log.actor ? `By ${log.actor}${log.role ? ` (${String(log.role).replace(/_/g, ' ')})` : ''}` : '',
    })
  }
  if (b.booking_status === 'cancelled') {
    ev.push({ key: 'cancel', ts: b.cancelled_at || b.updated_at || b.created_at, tone: 'cancel', text: 'Booking cancelled', sub: '' })
  }
  if (b.checked_in_at) {
    ev.push({ key: 'checkin', ts: b.checked_in_at, tone: 'paid', text: 'Customer checked in', sub: '' })
  }
  return ev.filter(e => e.ts).sort((a, z) => new Date(a.ts) - new Date(z.ts))
})

const duration = computed(() => {
  if (!booking.value?.start_time || !booking.value?.end_time) return '—'
  const [sh, sm] = booking.value.start_time.split(':').map(Number)
  const [eh, em] = booking.value.end_time.split(':').map(Number)
  const mins = (eh * 60 + em) - (sh * 60 + sm)
  if (mins <= 0) return '—'
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return h > 0 ? (m > 0 ? `${h}h ${m}m` : `${h} hour${h > 1 ? 's' : ''}`) : `${m} min`
})

const currency = computed(() => location.value?.currency_override || '')

const bookingRef = computed(() => makeRef(booking.value))

function statusClass(s) {
  return {
    confirmed: 'status-confirmed', completed: 'status-completed',
    cancelled: 'status-cancelled', pending: 'status-pending',
    paid: 'status-paid', deposit_paid: 'status-deposit', no_show: 'status-noshow',
    on_hold: 'status-hold', available_for_payout: 'status-payout', refunded: 'status-refunded',
    skipped: 'status-skipped',
  }[s] || 'status-neutral'
}

function methodLabel(m) {
  return { online: 'Online', cash: 'Pay on Arrival', card: 'Card', cheque: 'Cheque', wire_transfer: 'Wire Transfer', wire: 'Wire Transfer', payment_skipped: 'Payment Skipped' }[m] || m || '—'
}

function paymentTypeLabel(t) {
  return { full: 'Full Payment', deposit: 'Deposit', remaining: 'Remaining Balance', booking: 'Full Payment' }[t] || t
}

const pendingPayments = computed(() => payments.value.filter(p => p.status === 'pending'))
const totalPending    = computed(() => pendingPayments.value.reduce((s, p) => s + Number(p.amount || 0), 0))

// The host "service fee" is Bookboll's non-refundable platform fee (recipient 'bookboll').
// It's recorded against this booking only because booking_payments needs a booking_id —
// it has nothing to do with the court booking's payment breakdown, so it's hidden here.
// (It still shows in the Payments page as Bookboll revenue.)
const visiblePayments = computed(() => payments.value.filter(p => p.payment_type !== 'service_fee'))

// ── Promo ─────────────────────────────────────────────────────────────────────
async function applyPromo() {
  if (!promoCode.value.trim()) return
  promoLoading.value = true
  promoError.value = ''
  const isProgram = booking.value?.origin === 'class_program'
  const result = await validatePromo(promoCode.value, {
    amount: Number(booking.value?.total || 0),
    applicableTo: isProgram ? 'program' : 'facility',
    locationId: booking.value?.location_id,
    listingId: booking.value?.listing_id,
    customerEmail: customer.value?.email || '',
  })
  promoLoading.value = false
  if (!result.ok) { promoError.value = result.reason || 'Invalid or expired promocode.'; return }
  promoApplied.value = result.code
}

// Which pool of money this promo draws from
const promoRecipientLabel = computed(() => {
  if (!promoApplied.value) return ''
  return promoApplied.value.recipient === 'bookboll'
    ? 'Bookboll absorbs this discount from its platform fee'
    : "Provider absorbs this discount from their share of the payment"
})
const promoRecipientClass = computed(() =>
  promoApplied.value?.recipient === 'bookboll' ? 'promo-absorbed-bookboll' : 'promo-absorbed-provider'
)

// Discount is capped to the relevant recipient's share of the booking total
const discountAmount = computed(() => {
  if (!promoApplied.value || !booking.value) return 0
  const total     = Number(booking.value.total || 0)
  const commAmt   = Number(booking.value.commission_amount || 0)

  // Determine the cap based on who absorbs the cost
  let cap = total
  if (promoApplied.value.recipient === 'bookboll') {
    // Can only discount up to Bookboll's platform fee
    cap = commAmt > 0 ? commAmt : total
  } else if (promoApplied.value.recipient === 'provider') {
    // Can only discount up to provider's share (total minus platform fee)
    cap = commAmt > 0 ? Math.max(0, total - commAmt) : total
  }

  let discount = 0
  if (promoApplied.value.discount_type === 'percentage')
    discount = Math.round(total * Number(promoApplied.value.discount_value) / 100 * 100) / 100
  else
    discount = Number(promoApplied.value.discount_value)

  return Math.min(discount, cap, total)
})

const amountAfterDiscount = computed(() => {
  return Math.max(0, totalPending.value - discountAmount.value)
})
const paidSoFar = computed(() =>
  booking.value?.recorded_payment_summary ? booking.value.recorded_payment_summary.paid : payments.value.filter(p => p.status === 'paid').reduce((s, p) => s + Number(p.amount || 0), 0))

// ── Pay by QR: the customer scans and pays the balance online ─────────────────
// Available only when online money has somewhere to go: a Bookboll-run venue
// (no owner), or a provider whose BOOKINGS wallet has an active Stripe rail.
const qrPayAvailable = computed(() => {
  if (!(totalPending.value > 0) || !location.value || !can(CAP.ACCEPT_PAYMENTS)) return false
  if (!location.value.owner_user_id) return true   // Bookboll-run venue
  return hasActivePayoutMethodFor(ownerWallets.value, 'bookings')
    && ownerWallets.value.some(w => isWalletReady(w) && isAutomaticPayout(w))
})
// Fallback link when a Stripe link can't be minted. It uses the booking's uuid,
// not its id — the page is public, so the identifier in it is the credential.
const payLink = computed(() => payLinkOverride.value || (booking.value?.uuid ? `${appBaseUrl()}/pay/${booking.value.uuid}` : ''))
// While the sheet is open the poll refreshes payments — when the customer pays
// online, the row flips to paid and the sheet switches to its success state.
const collectRowPaid = computed(() =>
  !!collectFor.value && payments.value.find(p => p.id === collectFor.value.id)?.status === 'paid')

async function openCollect(pmt) {
  collectFor.value = pmt
  markMethod.value = ''
  linkCopied.value = false
  payQrImg.value = ''
  payLinkOverride.value = ''
  if (qrPayAvailable.value) {
    try {
      const linkRes = await generateDashboardPaymentLink({
        booking_id: booking.value.id,
        amount: collectAmountFor(pmt),
      })
      if (linkRes.success && linkRes.data?.link) payLinkOverride.value = linkRes.data.link
    } catch { /* fall back to /pay/:id */ }
    try { payQrImg.value = await QRCode.toDataURL(payLink.value, { width: 420, margin: 1 }) } catch { payQrImg.value = '' }
    startPayPoll()
  }
}
function startPayPoll() {
  clearInterval(payQrTimer)
  payQrTimer = setInterval(async () => {
    if (!collectFor.value || !booking.value) { clearInterval(payQrTimer); return }
    const crm = await fetchBookingCrm(booking.value.id)
    if (crm.success) {
      payments.value = crm.data?.payments || payments.value
      if (crm.data?.booking) booking.value = crm.data.booking
    }
    if (!payments.value.some(p => p.status === 'pending')) {
      clearInterval(payQrTimer)
    }
  }, 4000)
}
function closeCollect() { collectFor.value = null; clearInterval(payQrTimer) }
async function copyPayLink() {
  try {
    await navigator.clipboard.writeText(payLink.value)
    linkCopied.value = true
    setTimeout(() => { linkCopied.value = false }, 2000)
  } catch { /* clipboard unavailable — the link is selectable in the field */ }
}

/** The amount a pending row's Collect button will actually take — the desk promo
 *  (if one is entered) comes off the FIRST pending installment, capped at it. */
function collectAmountFor(pmt) {
  let amt = Number(pmt.amount) || 0
  if (promoApplied.value && discountAmount.value > 0 && pendingPayments.value[0]?.id === pmt.id) {
    amt = Math.max(0, +(amt - Math.min(discountAmount.value, amt)).toFixed(2))
  }
  return amt
}

// ── Mark as paid ──────────────────────────────────────────────────────────────
async function markPaid(pmtId) {
  markSaving.value = true

  const pmtRow = payments.value.find(p => String(p.id) === String(pmtId))
  let deskDiscount = 0
  const codeUsed = promoApplied.value
  if (promoApplied.value && discountAmount.value > 0 && pmtRow) {
    deskDiscount = Math.min(discountAmount.value, Number(pmtRow.amount) || 0)
  }

  const res = await collectDashboardPayment(booking.value.id, pmtId, {
    method: markMethod.value,
    discount: deskDiscount || undefined,
    promocode_id: deskDiscount > 0 ? (codeUsed?.id || null) : null,
  })

  if (!res.success) {
    markSaving.value = false
    pushToast(firstError(res, 'Could not accept payment.'), 'error')
    return
  }

  if (deskDiscount > 0 && codeUsed) {
    try {
      await recordRedemption({
        code: codeUsed,
        customer: { email: customer.value?.email || '', name: customer.value?.full_name || '' },
        source: { type: 'booking', id: booking.value.id, countryId: booking.value.country_id || null },
        amount: Number(pmtRow.amount) || 0, discount: deskDiscount,
        currency: pmtRow.currency || currency.value,
      })
    } catch { /* best-effort */ }
    existingPromo.value = { code: codeUsed.code || '', discount_amount: deskDiscount }
    promoApplied.value = null
    promoCode.value = ''
  }

  payments.value = res.data?.payments || payments.value
  if (res.data?.booking) booking.value = res.data.booking
  if (res.data?.audit_logs) auditLogs.value = res.data.audit_logs

  const allPaid = payments.value.every(p => p.status === 'paid')

  {
    const pmt = payments.value.find(p => String(p.id) === String(pmtId))
    if (pmt) {
      await logAudit({
        action: 'collected', entity_type: 'booking', entity_id: booking.value.id,
        details: `${PMT_LABELS[pmt.payment_type] || 'Payment'} of ${fmtAmt(pmt)} collected via ${METHOD_LABELS[markMethod.value] || markMethod.value}${allPaid ? ' — booking fully paid' : ''}`,
      })
      const crmLogs = await fetchBookingCrm(booking.value.id)
      if (crmLogs.success) auditLogs.value = crmLogs.data?.audit_logs || auditLogs.value
    }
  }

  collectFor.value = null
  clearInterval(payQrTimer)
  markSaving.value = false
  pushToast('Payment accepted.', 'success')
  try {
    if (customer.value) {
      const pmt = payments.value.find(p => String(p.id) === String(pmtId))
      notifyPaymentReceipt({
        ownerId: location.value?.owner_user_id || null,
        customer: { id: customer.value.id, name: customer.value.full_name, email: customer.value.email, phone: customer.value.phone },
        booking: {
          listing: listing.value?.name, venue: location.value?.name, business_name: location.value?.name,
          date: booking.value?.booking_date, start_time: booking.value?.start_time ? fmt12(booking.value.start_time) : '',
          // The reference the customer can actually quote back, not a slice of
          // the row id — see lib/bookingRef.js.
          code: booking.value?.booking_id || String(booking.value?.id || ''),
          amount: pmt?.amount, currency: pmt?.currency || location.value?.currency_override || '',
          // Capitalised for the reader: "Cash", "Wire transfer", "Online".
          payment_method: (pmt?.method || markMethod.value) ? methodLabel(pmt?.method || markMethod.value) : '',
        },
      })
    }
  } catch { /* non-blocking */ }
}

function whatsapp() {
  if (!customer.value?.phone) return
  const phone = customer.value.phone.replace(/\D/g, '')
  window.open(`https://wa.me/${phone}`, '_blank')
}

function callCustomer() {
  if (!customer.value?.phone) return
  window.open(`tel:${customer.value.phone}`)
}

// ── Invoice & confirmation ──────────────────────────────────────────────────
function fmtMoney(n) {
  return `${currency.value} ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
/** total / amount paid / balance still pending for this booking */
function invoiceTotals() {
  const total   = Number(booking.value?.total || 0)
  const pending = totalPending.value
  return { total, pending, paid: Math.max(0, total - pending) }
}

/**
 * Generate a clean, customer-facing invoice and open the browser print dialog
 * (which lets the user Save as PDF). No backend / PDF library required.
 * Deliberately omits Bookboll's internal platform/provider commission split.
 */
function generateInvoice() {
  if (!booking.value) return
  const b = booking.value
  const { total, pending, paid } = invoiceTotals()
  const cust = customer.value || {}
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
  const rowsHtml = []
  rowsHtml.push(`<tr><td>${esc(listing.value?.name || 'Booking')} <span class="muted">(${esc(duration.value)})</span></td><td class="r">${esc(fmtMoney(b.base_price))}</td></tr>`)
  if (b.addon_total) rowsHtml.push(`<tr><td>Add-ons</td><td class="r">${esc(fmtMoney(b.addon_total))}</td></tr>`)
  if (b.discount)    rowsHtml.push(`<tr><td>Discount</td><td class="r">− ${esc(fmtMoney(b.discount))}</td></tr>`)

  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${esc(bookingRef.value)}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: var(--text, #0f172a); margin: 0; padding: 40px; }
  .wrap { max-width: 640px; margin: 0 auto; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 24px; }
  .brand { font-size: 24px; font-weight: 800; letter-spacing: -.02em; color: var(--primary-ink, #0f766e); }
  .brand small { display:block; font-size: 11px; font-weight: 600; color:var(--text-muted, #64748b); letter-spacing:.08em; text-transform:uppercase; margin-top:2px; }
  .doc { text-align: right; }
  .doc .t { font-size: 20px; font-weight: 700; letter-spacing:.04em; }
  .doc .ref { font-size: 13px; color:var(--text-secondary, #475569); margin-top:4px; }
  .doc .date { font-size: 12px; color:var(--text-light, #94a3b8); }
  .cols { display:flex; gap:32px; margin-bottom:24px; }
  .col { flex:1; }
  .col h4 { font-size: 11px; text-transform: uppercase; letter-spacing:.06em; color:var(--text-light, #94a3b8); margin:0 0 6px; }
  .col p { margin:0; font-size: 13.5px; line-height: 1.5; }
  table { width:100%; border-collapse: collapse; margin-bottom: 8px; }
  th { text-align:left; font-size: 11px; text-transform: uppercase; letter-spacing:.06em; color:var(--text-light, #94a3b8); border-bottom:1px solid var(--border, #e2e8f0); padding:8px 0; }
  td { padding: 10px 0; font-size: 14px; border-bottom: 1px solid var(--border, #f1f5f9); }
  td.r, th.r { text-align: right; }
  .muted { color:var(--text-light, #94a3b8); font-weight:400; }
  .totals { margin-left:auto; width: 260px; margin-top: 10px; }
  .totals .row { display:flex; justify-content:space-between; padding:6px 0; font-size: 14px; }
  .totals .grand { border-top:2px solid #0f172a; margin-top:4px; padding-top:10px; font-weight:800; font-size: 16px; }
  .totals .paid { color:var(--positive-ink, #059669); font-weight:600; }
  .totals .bal  { color:var(--danger-ink, #dc2626); font-weight:700; }
  .status { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
  .pill-paid { background:var(--positive-bg, #d1fae5); color:var(--positive-ink, #065f46); }
  .pill-due  { background:var(--danger-bg, #fee2e2); color:var(--danger-ink, #991b1b); }
  .foot { margin-top: 40px; padding-top:16px; border-top:1px solid var(--border, #e2e8f0); font-size: 12px; color:var(--text-light, #94a3b8); text-align:center; }
  @media print { body { padding: 0; } .noprint { display:none; } }
  .noprint { text-align:center; margin-bottom: 24px; }
  .btn { background:#0f766e; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; }
</style></head><body>
<div class="wrap">
  <div class="noprint"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="top">
    <div class="brand">Bookboll<small>Booking Invoice</small></div>
    <div class="doc">
      <div class="t">INVOICE</div>
      <div class="ref">${esc(bookingRef.value)}</div>
      <div class="date">Issued ${esc(fmtDateTime(new Date().toISOString()))}</div>
    </div>
  </div>
  <div class="cols">
    <div class="col">
      <h4>Billed to</h4>
      <p><strong>${esc(cust.full_name || '—')}</strong><br>${esc(cust.email || '')}<br>${esc(cust.phone || '')}</p>
    </div>
    <div class="col">
      <h4>Booking</h4>
      <p><strong>${esc(location.value?.name || '—')}</strong><br>${esc(fmtDate(b.booking_date))}<br>${esc(fmtTime(b.start_time))} – ${esc(fmtTime(b.end_time))}</p>
    </div>
  </div>
  <table>
    <thead><tr><th>Description</th><th class="r">Amount</th></tr></thead>
    <tbody>${rowsHtml.join('')}</tbody>
  </table>
  <div class="totals">
    <div class="row"><span>Total</span><span>${esc(fmtMoney(total))}</span></div>
    <div class="row paid"><span>Paid</span><span>${esc(fmtMoney(paid))}</span></div>
    ${pending > 0 ? `<div class="row bal"><span>Balance due</span><span>${esc(fmtMoney(pending))}</span></div>` : ''}
    <div class="row grand"><span>${pending > 0 ? 'Amount due' : 'Status'}</span><span>${pending > 0 ? esc(fmtMoney(pending)) : '<span class="status pill-paid">PAID</span>'}</span></div>
  </div>
  <div class="foot">Thank you for booking with Bookboll · ${esc(bookingRef.value)}</div>
</div>
</body></html>`

  const w = window.open('', '_blank', 'width=760,height=920')
  if (!w) { alert('Please allow pop-ups for this site to generate the invoice.'); return }
  w.document.write(html)
  w.document.close()
  w.focus()
}

/**
 * Open the user's mail client with a pre-filled booking-confirmation email.
 * The platform has no transactional-email backend, so we hand off to the
 * user's own mail app — they review and send (never sent automatically).
 */
function sendConfirmationEmail() {
  if (!customer.value?.email || !booking.value) return
  const b = booking.value
  const { total, pending } = invoiceTotals()
  const venue   = listing.value?.name ? `${listing.value.name} (${location.value?.name || ''})` : (location.value?.name || 'your booking')
  const dateStr = fmtDate(b.booking_date)
  const timeStr = `${fmtTime(b.start_time)}–${fmtTime(b.end_time)}`
  const payLine = pending > 0
    ? `Total: ${fmtMoney(total)} — Balance due on arrival: ${fmtMoney(pending)}.`
    : `Total: ${fmtMoney(total)} (paid).`
  const subject = `Booking confirmed — ${bookingRef.value}`
  const body = [
    `Hi ${customer.value.full_name || 'there'},`,
    ``,
    `Your booking at ${venue} on ${dateStr}, ${timeStr} is confirmed.`,
    `Reference: ${bookingRef.value}`,
    payLine,
    ``,
    `See you there!`,
    `— Bookboll`,
  ].join('\n')
  window.location.href = `mailto:${encodeURIComponent(customer.value.email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
}
</script>

<template>
  <Teleport to="body">
    <div v-if="bookingId" class="bdm-backdrop" @click.self="emit('close')">
      <div class="bdm-panel" v-dialog="() => emit('close')">

        <!-- Header -->
        <div class="bdm-header">
          <div class="bdm-header-left">
            <div class="bdm-title">Booking Details</div>
            <span v-if="booking" class="bdm-ref">{{ bookingRef }}</span>
          </div>
          <div class="bdm-header-actions">
            <button v-if="booking && !editing && can(CAP.MANAGE_BOOKINGS)" class="bdm-edit-btn" @click="openEdit"><Pencil :size="14" />Edit</button>
            <button class="bdm-close" @click="emit('close')" aria-label="Close"><X :size="18" /></button>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="bdm-loading">
          <Loader :size="22" class="spin" />
          <span>Loading booking…</span>
        </div>

        <div v-else-if="booking" class="bdm-body">

          <!-- ── Venue & Listing ─────────────────────────────── -->
          <section class="bdm-section">
            <div class="bdm-info-grid">
              <div class="bdm-info-item">
                <div class="bdm-info-label"><MapPin :size="13" />Venue</div>
                <div class="bdm-info-value">{{ location?.name || '—' }}</div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><FileText :size="13" />Listing</div>
                <div class="bdm-info-value">{{ listing?.name || '—' }}</div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><Calendar :size="13" />Date &amp; Time</div>
                <div class="bdm-info-value">
                  {{ fmtDate(booking.booking_date) }}
                  <span class="bdm-time-range">from {{ fmtTime(booking.start_time) }} to {{ fmtTime(booking.end_time) }}</span>
                </div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><Clock :size="13" />Duration</div>
                <div class="bdm-info-value">{{ duration }}</div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label">Status</div>
                <div class="bdm-info-value">
                  <span class="bdm-status-chip" :class="statusClass(booking.booking_status)">{{ booking.booking_status }}</span>
                </div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><CreditCard :size="13" />Payment Method</div>
                <div class="bdm-info-value">{{ methodLabel(booking.payment_method) }}</div>
              </div>
            </div>
          </section>

          <!-- ── Customer ───────────────────────────────────────── -->
          <section v-if="customer" class="bdm-section">
            <div class="bdm-section-header">
              <User :size="15" />Customer Information
              <button class="bdm-view-history" @click="openCustomer(customer.id)">View Profile</button>
            </div>
            <div class="bdm-info-grid">
              <div class="bdm-info-item">
                <div class="bdm-info-label"><User :size="13" />Name</div>
                <div class="bdm-info-value"><button class="bdm-cust-link" @click="openCustomer(customer.id)">{{ customer.full_name }}</button></div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><Mail :size="13" />Email</div>
                <div class="bdm-info-value">{{ customer.email || '—' }}</div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><Phone :size="13" />Phone</div>
                <div class="bdm-info-value">{{ customer.phone || '—' }}</div>
              </div>
              <div class="bdm-info-item">
                <div class="bdm-info-label"><Star :size="13" />Loyalty Points Earned</div>
                <div class="bdm-info-value">{{ customer.loyalty_points ?? 0 }} points</div>
              </div>
            </div>
          </section>

          <!-- ── Notes ─────────────────────────────────────────── -->
          <section v-if="booking.notes" class="bdm-section">
            <div class="bdm-section-header"><StickyNote :size="15" />Notes</div>
            <div class="bdm-notes-box">{{ booking.notes }}</div>
          </section>

          <!-- ── Activity History ───────────────────────────────── -->
          <section class="bdm-section">
            <div class="bdm-section-header"><FileText :size="15" />Activity History</div>
            <div class="bdm-activity-list">
              <div v-for="e in timeline" :key="e.key" class="bdm-activity-item">
                <div class="bdm-activity-dot" :class="'tone-' + e.tone"></div>
                <div>
                  <div class="bdm-activity-text" :class="{ muted: e.tone === 'muted' }">{{ e.text }}</div>
                  <div v-if="e.sub" class="bdm-activity-sub">{{ e.sub }}</div>
                  <details v-if="e.recorded" class="bdm-activity-sub"><summary>Original creation log</summary>{{ e.recorded }}</details>
                  <div class="bdm-activity-date">{{ fmtDateTime(e.ts) }}</div>
                </div>
              </div>
            </div>
          </section>

          <!-- ── Booking Payments ───────────────────────────────── -->
          <section class="bdm-section">
            <div class="bdm-section-header"><CreditCard :size="15" />Booking Payments</div>

            <div v-if="!visiblePayments.length" class="bdm-empty-state">No payment records found</div>

            <div v-for="pmt in visiblePayments" :key="pmt.id" class="bdm-pmt-card">
              <div class="bdm-pmt-top">
                <div class="bdm-pmt-type">{{ paymentTypeLabel(pmt.payment_type) }}</div>
                <span class="bdm-status-chip" :class="statusClass(pmt.status)">{{ pmt.status }}</span>
              </div>
              <div class="bdm-pmt-meta">
                Method: {{ methodLabel(pmt.method) }} &nbsp;|&nbsp; Status: {{ pmt.status }}
              </div>
              <div v-if="pmt.status === 'pending'" class="bdm-pmt-ref">
                Payment pending for booking {{ bookingRef }}
              </div>
              <div class="bdm-pmt-row">
                <span class="bdm-pmt-amount">{{ pmt.currency }} {{ Number(pmt.amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
                <span class="bdm-pmt-date">Date: {{ fmtDateTime(pmt.paid_at || pmt.created_at) }}</span>
              </div>

              <!-- ONE collect button → the Accept Payment sheet (methods + link + QR) -->
              <div v-if="pmt.status === 'pending' && pmt.method !== 'online' && can(CAP.ACCEPT_PAYMENTS)" class="bdm-mark-paid">
                <button class="bdm-btn-mark" @click="openCollect(pmt)">
                  Collect {{ pmt.currency }} {{ collectAmountFor(pmt).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}
                  <template v-if="collectAmountFor(pmt) !== Number(pmt.amount)"> (promo applied)</template>
                </button>
              </div>
            </div>
          </section>

          <!-- ── Other pending payments (scoped to the viewer's business) ─── -->
          <section v-if="otherPendingSameCustomer.length || otherPendingBusiness.length" class="bdm-section">
            <div class="bdm-section-header"><AlertCircle :size="15" />Other Pending Payments</div>

            <!-- This customer's other unpaid bookings -->
            <div v-if="otherPendingSameCustomer.length" class="bdm-pending-group">
              <div class="bdm-pending-grouphead">
                <span>{{ customer?.full_name || 'This customer' }} — other unpaid bookings</span>
                <span class="bdm-pending-sum">{{ summarizeAmounts(otherPendingSameCustomer) }}</span>
              </div>
              <button v-for="it in otherPendingSameCustomer" :key="it.id" class="bdm-pending-row" @click="emit('viewBooking', it.booking_id)" title="Open this booking to investigate">
                <div class="bdm-pending-main">
                  <span class="bdm-pending-venue">{{ it.venue }}<span v-if="it.listingName"> · {{ it.listingName }}</span></span>
                  <span class="bdm-pending-sub">{{ fmtDate(it.booking_date) }} · {{ it.ref }}</span>
                </div>
                <div class="bdm-pending-right">
                  <span class="bdm-pending-amt">{{ [it.currency, it.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })].filter(Boolean).join(' ') }}</span>
                  <span class="bdm-status-chip status-pending">pending</span>
                  <ChevronRight :size="16" class="bdm-pending-arrow" />
                </div>
              </button>
            </div>

            <!-- Rest of the business (collapsible, collapsed by default to stay focused) -->
            <div v-if="otherPendingBusiness.length" class="bdm-pending-group">
              <button class="bdm-pending-toggle" @click="showBusinessPending = !showBusinessPending">
                <component :is="showBusinessPending ? ChevronDown : ChevronRight" :size="15" />
                <span>Other outstanding in {{ canSeeAllPayments ? 'the platform' : 'your business' }} ({{ otherPendingBusiness.length }})</span>
                <span class="bdm-pending-sum">{{ summarizeAmounts(otherPendingBusiness) }}</span>
              </button>
              <template v-if="showBusinessPending">
                <button v-for="it in otherPendingBusiness" :key="it.id" class="bdm-pending-row" @click="emit('viewBooking', it.booking_id)" title="Open this booking to investigate">
                  <div class="bdm-pending-main">
                    <span class="bdm-pending-venue">{{ it.venue }}<span v-if="it.listingName"> · {{ it.listingName }}</span></span>
                    <span class="bdm-pending-sub">{{ fmtDate(it.booking_date) }} · {{ it.ref }}</span>
                  </div>
                  <div class="bdm-pending-right">
                    <span class="bdm-pending-amt">{{ [it.currency, it.amount.toLocaleString(undefined, { minimumFractionDigits: 2 })].filter(Boolean).join(' ') }}</span>
                    <span class="bdm-status-chip status-pending">pending</span>
                    <ChevronRight :size="16" class="bdm-pending-arrow" />
                  </div>
                </button>
              </template>
            </div>

            <div class="bdm-pending-note">Only unpaid (pending) payments are shown. Click any row to open that booking and investigate.</div>
          </section>

          <!-- ── Payment Summary ────────────────────────────────── -->
          <section v-if="booking.origin !== 'class_program' && booking.payment_method !== 'payment_skipped'" class="bdm-section">
            <div class="bdm-section-header"><Receipt :size="15" />Payment</div>
            <div class="bdm-summary-table">
              <div class="bdm-summary-row">
                <span>Base Price</span>
                <span>{{ currency }} {{ Number(booking.base_price || 0).toLocaleString() }}</span>
              </div>
              <div v-if="booking.addon_total" class="bdm-summary-row">
                <span>Add-ons</span>
                <span>{{ currency }} {{ Number(booking.addon_total).toLocaleString() }}</span>
              </div>
              <div v-if="booking.discount" class="bdm-summary-row discount-row">
                <span>Discount<template v-if="existingPromo?.code"> ({{ existingPromo.code }})</template></span>
                <span>- {{ currency }} {{ Number(booking.discount).toLocaleString() }}</span>
              </div>
              <div class="bdm-summary-row total-row">
                <span>Total<template v-if="promoApplied"> (after {{ promoApplied.code }})</template></span>
                <span>{{ currency }} {{ (Number(booking.total || 0) - (promoApplied ? discountAmount : 0)).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </div>
              <!-- The receipt math, spelled out: paid so far and what's still due.
                   ONE collect action lives on the pending installment card above —
                   the old duplicate "Collect Full Amount" button is gone. -->
              <div class="bdm-summary-row">
                <span>Paid so far</span>
                <span>{{ paidSoFar == null ? 'Needs review' : currency + ' ' + paidSoFar.toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </div>
              <div v-if="totalPending > 0" class="bdm-summary-row due-row">
                <span>Balance due</span>
                <span>{{ currency }} {{ amountAfterDiscount.toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </div>
              <div class="bdm-summary-row">
                <span>Recorded payment status</span>
                <span class="bdm-status-chip" :class="statusClass(booking.recorded_payment_summary?.status || booking.payment_status)">{{ booking.recorded_payment_summary?.status || booking.payment_status }}</span>
              </div>
            </div>

            <!-- Promocode. A booking whose total already carries a discount shows
                 WHICH code was used (read-only) — offering the input again invited
                 double-discounting and read as a bug. Applying a code changes the
                 money collected, so the input is part of ACCEPT_PAYMENTS — read-only
                 roles (Viewer) see an already-applied code but never the apply box. -->
            <div v-if="existingPromo || promoApplied || can(CAP.ACCEPT_PAYMENTS)" class="bdm-promo-block">
              <div class="bdm-promo-header"><Tag :size="14" />{{ existingPromo ? 'Promocode' : 'Apply Promocode' }}</div>
              <div v-if="existingPromo" class="bdm-promo-applied">
                <Check :size="14" />
                <div style="flex:1;min-width:0">
                  <div>
                    <strong>{{ existingPromo.code || 'Promocode' }}</strong> applied
                    <span style="font-weight:600"> — saved {{ currency }} {{ Number(existingPromo.discount_amount || booking?.discount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
                  </div>
                  <div class="bdm-promo-absorbed">Already included in this booking's total.</div>
                </div>
              </div>
              <div v-else-if="promoApplied" class="bdm-promo-applied">
                <Check :size="14" />
                <div style="flex:1;min-width:0">
                  <div>
                    <strong>{{ promoApplied.code }}</strong>
                    — {{ promoApplied.discount_type === 'percentage' ? `${promoApplied.discount_value}% off` : `${currency} ${promoApplied.discount_value} off` }}
                    <span style="font-weight:600"> (saving {{ currency }} {{ discountAmount.toFixed(2) }})</span>
                  </div>
                  <div class="bdm-promo-absorbed" :class="promoRecipientClass">
                    {{ promoRecipientLabel }}
                  </div>
                </div>
                <button class="bdm-promo-remove" @click="promoApplied = null; promoCode = ''" aria-label="Close"><X :size="13" /></button>
              </div>
              <template v-else-if="can(CAP.ACCEPT_PAYMENTS)">
                <div class="bdm-promo-input-row">
                  <input v-model="promoCode" placeholder="Enter promocode" class="bdm-promo-input" @keyup.enter="applyPromo" />
                  <button class="bdm-btn-promo" :disabled="promoLoading" @click="applyPromo">
                    {{ promoLoading ? '…' : 'Apply' }}
                  </button>
                </div>
                <div v-if="promoError" class="bdm-promo-error"><AlertCircle :size="13" />{{ promoError }}</div>
                <div class="bdm-promo-hint">Enter a valid code to apply a discount to this booking</div>
              </template>
            </div>
          </section>

          <!-- ── Actions ────────────────────────────────────────── -->
          <section class="bdm-section bdm-actions-section">
            <div class="bdm-section-header">Actions</div>
            <div class="bdm-actions-grid">
              <button v-if="customer?.phone" class="bdm-action-btn" @click="whatsapp">
                <WhatsappIcon :size="15" />Message customer
              </button>
              <button v-if="customer?.phone" class="bdm-action-btn" @click="callCustomer">
                <PhoneCall :size="15" />Call Customer
              </button>
              <button class="bdm-action-btn" @click="generateInvoice">
                <Receipt :size="15" />Generate Invoice
              </button>
              <button v-if="customer?.email" class="bdm-action-btn" @click="sendConfirmationEmail">
                <Send :size="15" />Send Confirmation Email
              </button>
            </div>
          </section>

        </div>
        <div v-else-if="!loading" class="bdm-loading">Booking not found.</div>

        <!-- ── Edit Booking overlay ──────────────────────────────── -->
        <div v-if="editing" class="bdm-edit-overlay">
          <div class="bdm-edit-header">
            <div>
              <div class="bdm-edit-title">Edit Booking</div>
              <div class="bdm-edit-subtitle">Update booking details</div>
            </div>
            <button class="bdm-close" @click="editing = false" aria-label="Close"><X :size="18" /></button>
          </div>

          <div class="bdm-edit-body">

            <div class="bdm-edit-section-label">Basic Information</div>

            <!-- Venue -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Venue <span class="bdm-req">*</span></label>
              <div class="bdm-edit-select-wrap">
                <MapPin :size="14" class="bdm-edit-field-icon" />
                <select v-model="editVenueId" class="bdm-edit-select bdm-edit-select-icon">
                  <option value="">Select Venue</option>
                  <option v-for="loc in editLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
                </select>
              </div>
            </div>

            <!-- Listing -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Listing <span class="bdm-req">*</span></label>
              <div class="bdm-edit-select-wrap">
                <select v-model="editListingId" class="bdm-edit-select" :disabled="!editVenueId">
                  <option value="">{{ editVenueId ? 'Select Listing' : 'Select a venue first' }}</option>
                  <option v-for="l in editVenueListings" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
              </div>
            </div>

            <!-- Customer -->
            <div class="bdm-edit-field">
              <div class="bdm-edit-label-row">
                <label class="bdm-edit-label">Customer</label>
                <button class="bdm-edit-add-link" @click="editShowAddCustomer = !editShowAddCustomer">
                  <Plus :size="12" />{{ editShowAddCustomer ? 'Cancel' : 'Add New' }}
                </button>
              </div>
              <div v-if="editShowAddCustomer" class="bdm-edit-add-customer">
                <input v-model="editNewCustomerName" placeholder="Full name *" class="bdm-edit-input" />
                <div class="bdm-edit-row">
                  <input v-model="editNewCustomerPhone" placeholder="Phone" class="bdm-edit-input" />
                  <input v-model="editNewCustomerEmail" placeholder="Email" class="bdm-edit-input" type="email" />
                </div>
                <button class="bdm-edit-save-customer-btn" :disabled="!editNewCustomerName.trim() || editSavingCustomer" @click="createEditCustomer">
                  <Check :size="12" />{{ editSavingCustomer ? 'Saving...' : 'Save Customer' }}
                </button>
              </div>
              <div v-else class="bdm-edit-cust-picker">
                <div v-if="editSelectedCustomer" class="bdm-edit-cust-selected" @click="editCustomerId = ''; editShowCustDropdown = true">
                  <div class="bdm-edit-cust-avatar">{{ editSelectedCustomer.full_name.split(' ').map(n=>n[0]).slice(0,2).join('') }}</div>
                  <div class="bdm-edit-cust-info">
                    <div class="bdm-edit-cust-name">{{ editSelectedCustomer.full_name }}</div>
                    <div class="bdm-edit-cust-sub">{{ editSelectedCustomer.phone || editSelectedCustomer.email || '' }}</div>
                  </div>
                  <button class="bdm-edit-cust-clear" @click.stop="editCustomerId = ''" aria-label="Close"><X :size="12" /></button>
                </div>
                <div v-else style="position:relative;">
                  <input
                    v-model="editCustomerSearch"
                    placeholder="Search by name, phone or email..."
                    class="bdm-edit-input"
                    @focus="editShowCustDropdown = true"
                    @blur="setTimeout(() => editShowCustDropdown = false, 180)"
                  />
                  <div v-if="editShowCustDropdown && editFilteredCustomers.length" class="bdm-edit-cust-dropdown">
                    <div
                      v-for="c in editFilteredCustomers.slice(0,12)"
                      :key="c.id"
                      class="bdm-edit-cust-option"
                      @mousedown.prevent="selectEditCustomer(c)"
                    >
                      <div class="bdm-edit-cust-avatar sm">{{ c.full_name.split(' ').map(n=>n[0]).slice(0,2).join('') }}</div>
                      <div>
                        <div class="bdm-edit-cust-name">{{ c.full_name }}</div>
                        <div class="bdm-edit-cust-sub">{{ c.phone || c.email || '' }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Date -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Date <span class="bdm-req">*</span></label>
              <div class="bdm-edit-input-wrap">
                <!-- No overlay icon: the native date input draws its own picker icon,
                     and the decorative one sat on top of the day digits.
                     A user-driven date change invalidates the carried-over slot: the new
                     date's schedule (special days / weekly hours) may not include it.
                     (openEdit's programmatic prefill is unaffected — no change event.) -->
                <input type="date" v-model="editDate" class="bdm-edit-input" @change="editSelectedSlot = null" />
              </div>
            </div>

            <!-- Time Slots -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Available Time Slots</label>
              <div v-if="!editListingId || !editDate" class="bdm-edit-slots-placeholder">
                <Clock :size="14" />Please select a listing and date to view available time slots.
              </div>
              <div v-else-if="editSlotsLoading" class="bdm-edit-slots-placeholder">
                <Loader :size="14" class="bdm-edit-spin" />Loading slots...
              </div>
              <div v-else-if="editDaySched?.closed" class="bdm-edit-slots-placeholder bdm-closed-note">
                Closed on this date<template v-if="editDaySched.name && editDaySched.name !== 'Closed'"> — {{ editDaySched.name }}</template> (special day). Pick another date.
              </div>
              <div v-else-if="!editSlots.length" class="bdm-edit-slots-placeholder">
                <Clock :size="14" />No bookable times on this date.
              </div>
              <div v-else class="bdm-edit-slots-grid">
                <button
                  v-for="slot in editSlots"
                  :key="slot.start_time"
                  class="bdm-edit-slot"
                  :class="{
                    'bdm-edit-slot-taken':    editIsBooked(slot),
                    'bdm-edit-slot-selected': editSelectedSlot?.start_time === slot.start_time,
                  }"
                  :disabled="editIsBooked(slot)"
                  @click="editSelectedSlot = slot"
                >
                  <span class="bdm-edit-slot-time">{{ fmtTime(slot.start_time) }}</span>
                  <span class="bdm-edit-slot-end">– {{ fmtTime(slot.end_time) }}</span>
                  <span class="bdm-edit-slot-price">{{ editCurrency }} {{ editResolvePrice(slot.start_time).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}</span>
                </button>
              </div>
              <div v-if="editSelectedSlot" class="bdm-edit-slot-summary">
                <Clock :size="12" />
                <strong>{{ fmtTime(editSelectedSlot.start_time) }} – {{ fmtTime(editSelectedSlot.end_time) }}</strong>
                <span v-if="editTotal > 0">&nbsp;·&nbsp; {{ editCurrency }} {{ editTotal.toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </div>
            </div>

            <!-- Booking Status -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Booking Status</label>
              <div class="bdm-edit-select-wrap">
                <select v-model="editBookingStatus" class="bdm-edit-select">
                  <option value="confirmed">Confirmed</option>
                  <option value="completed">Completed</option>
                  <option value="cancelled">Cancelled</option>
                  <option value="no_show">No Show</option>
                  <option value="pending">Pending</option>
                </select>
              </div>
            </div>

            <!-- Payment Method -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Payment Method <span class="bdm-req">*</span></label>
              <div class="bdm-edit-select-wrap">
                <select v-model="editPaymentMethod" class="bdm-edit-select">
                  <option value="" disabled>Select payment method</option>
                  <option value="payment_skipped">Skip payment</option>
                  <option value="cash">Pay on Arrival</option>
                  <option value="paid_cash">Paid Cash (Pre-paid)</option>
                  <option value="paid_cheque">Paid Cheque (Pre-paid)</option>
                  <option value="paid_card">Paid Credit Card (Pre-paid)</option>
                  <option value="paid_wire">Paid Wire Transfer</option>
                </select>
              </div>
            </div>

            <!-- Notes -->
            <div class="bdm-edit-field">
              <label class="bdm-edit-label">Notes (Optional)</label>
              <textarea v-model="editNotes" placeholder="Add any notes..." class="bdm-edit-textarea" rows="3"></textarea>
            </div>

            <!-- Error -->
            <div v-if="editError" class="bdm-edit-error">
              <AlertCircle :size="13" />{{ editError }}
            </div>

          </div>

          <div class="bdm-edit-footer">
            <button class="bdm-edit-btn-cancel" @click="editing = false">Cancel</button>
            <button class="bdm-edit-btn-save" :disabled="editSaving" @click="saveEdit">
              <Loader v-if="editSaving" :size="14" class="bdm-edit-spin" />
              {{ editSaving ? 'Saving…' : 'Save Changes' }}
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Accept Payment sheet — one place to collect: method chips + pay-online link/QR ── -->
    <div v-if="collectFor" class="bdm-collect-backdrop" @click.self="closeCollect">
      <div class="bdm-collect">
        <div class="bdm-collect-head">
          <span><CreditCard :size="16" /> Accept Payment</span>
          <button type="button" class="bdm-collect-x" @click="closeCollect" aria-label="Close"><X :size="17" /></button>
        </div>

        <div class="bdm-collect-body">
          <template v-if="!collectRowPaid">
            <div class="bdm-collect-amt">
              <div class="bdm-collect-amt-l">Amount to collect</div>
              <div class="bdm-collect-amt-v">{{ collectFor.currency }} {{ collectAmountFor(collectFor).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</div>
              <div v-if="collectAmountFor(collectFor) !== Number(collectFor.amount)" class="bdm-collect-amt-promo">
                <Tag :size="12" /> Promocode discount already deducted
              </div>
            </div>

            <div class="bdm-collect-sub">How is the customer paying?</div>
            <div class="bdm-collect-methods">
              <button
                v-for="m in COLLECT_METHODS" :key="m.value" type="button"
                class="bdm-collect-method" :class="{ on: markMethod === m.value }"
                @click="markMethod = markMethod === m.value ? '' : m.value"
              >
                <Check v-if="markMethod === m.value" :size="14" />{{ m.label }}
              </button>
            </div>
            <button type="button" class="bdm-collect-confirm" :disabled="!markMethod || markSaving" @click="markPaid(collectFor.id)">
              {{ markSaving ? 'Saving…' : (markMethod ? `Accept payment — ${COLLECT_METHODS.find(m => m.value === markMethod)?.label}` : 'Choose a payment method above') }}
            </button>

            <template v-if="qrPayAvailable">
              <div class="bdm-collect-or"><span>or the customer pays online</span></div>

              <div class="bdm-collect-link-l">Payment link</div>
              <div class="bdm-collect-link">
                <input readonly :value="payLink" @focus="$event.target.select()" />
              </div>
              <div class="bdm-collect-link-btns">
                <a class="bdm-collect-open" :href="payLink" target="_blank" rel="noopener">Open</a>
                <button type="button" class="bdm-collect-copy" @click="copyPayLink">{{ linkCopied ? 'Copied!' : 'Copy' }}</button>
              </div>

              <div v-if="payQrImg" class="bdm-collect-qrwrap">
                <div class="bdm-collect-link-l" style="text-align:center">Scan QR code to pay</div>
                <img :src="payQrImg" class="bdm-collect-qr" alt="Scan to pay online" />
                <div class="bdm-collect-qr-hint">The customer scans with their phone camera and pays by card — this screen updates by itself once it's paid.</div>
              </div>
            </template>
          </template>

          <div v-else class="bdm-collect-done">
            <div class="bdm-collect-done-badge"><Check :size="22" /></div>
            <div class="bdm-collect-done-t">Payment received</div>
            <div class="bdm-collect-done-s">This payment has been settled.</div>
            <button type="button" class="bdm-collect-confirm" @click="closeCollect">Done</button>
          </div>
        </div>
      </div>
    </div>

    <CustomerHistoryModal
      :customerId="showHistory ? customer?.id : null"
      @close="showHistory = false"
      @viewBooking="showHistory = false; emit('viewBooking', $event)"
    />
  </Teleport>
</template>

<style>
/* ── Backdrop & Panel ─────────────────────────────────────────────────────── */
.bdm-backdrop {
  position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45);
  z-index: 1000; display: flex; justify-content: flex-end;
  backdrop-filter: blur(2px);
}
.bdm-panel {
  position: relative;
  width: 100%; max-width: 520px; height: 100%;
  background: var(--surface, #ffffff); display: flex; flex-direction: column;
  box-shadow: -8px 0 40px rgba(0,0,0,.18);
  animation: slideInRight .22s cubic-bezier(.22,1,.36,1);
  overflow: hidden;
}
@keyframes slideInRight { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* ── Header ──────────────────────────────────────────────────────────────── */
.bdm-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 20px 14px; border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.bdm-header-left { display: flex; flex-direction: column; gap: 3px; }
.bdm-title { font-size: 17px; font-weight: 700; color: var(--text, #0f172a); }
.bdm-ref { font-size: 12px; color: var(--text-muted, #64748b); font-family: 'SF Mono', Monaco, monospace; }
.bdm-close {
  width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9);
  display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted, #64748b);
}
.bdm-close:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }

/* ── Loading ──────────────────────────────────────────────────────────────── */
.bdm-loading {
  flex: 1; display: flex; align-items: center; justify-content: center;
  gap: 10px; color: var(--text-muted, #64748b); font-size: 14px;
}
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Body ─────────────────────────────────────────────────────────────────── */
.bdm-body { flex: 1; overflow-y: auto; padding: 0 0 32px; }

/* ── Section ─────────────────────────────────────────────────────────────── */
.bdm-section { padding: 18px 20px; border-bottom: 1px solid var(--border, #f1f5f9); }
.bdm-actions-section { border-bottom: none; }
.bdm-section-header {
  display: flex; align-items: center; gap: 7px;
  font-size: 13px; font-weight: 700; color: var(--text, #0f172a);
  text-transform: uppercase; letter-spacing: .05em;
  margin-bottom: 14px;
}
.bdm-view-history {
  margin-left: auto; font-size: 12px; font-weight: 500; color: var(--info-ink, #3b82f6);
  text-decoration: none; text-transform: none; letter-spacing: 0;
}
.bdm-view-history:hover { text-decoration: underline; }

/* ── Info grid ────────────────────────────────────────────────────────────── */
.bdm-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.bdm-info-item { display: flex; flex-direction: column; gap: 4px; }
.bdm-info-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8);
  text-transform: uppercase; letter-spacing: .05em;
}
.bdm-info-value { font-size: 13.5px; font-weight: 500; color: var(--text, #0f172a); }
.bdm-cust-link { background: none; border: none; padding: 0; font: inherit; font-weight: 500; color: var(--primary-ink, #0f766e); cursor: pointer; }
.bdm-cust-link:hover { text-decoration: underline; }
.bdm-time-range { display: block; font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px; }

/* ── Status chips ─────────────────────────────────────────────────────────── */
.bdm-status-chip {
  display: inline-flex; align-items: center;
  padding: 2px 8px; border-radius: 99px;
  font-size: 11px; font-weight: 600; text-transform: capitalize;
}
.status-confirmed  { background: var(--info-bg, #dbeafe); color: var(--info-ink, #1e40af); }
.status-completed  { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #065f46); }
.status-cancelled  { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }
.status-pending    { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.status-paid       { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #065f46); }
.status-deposit    { background: var(--info-bg, #dbeafe); color: var(--info-ink, #1e40af); }
.status-noshow     { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #991b1b); }
.status-hold       { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.status-payout     { background: var(--primary-soft, #ccfbf1); color: var(--primary-ink, #0f766e); }
.status-refunded   { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #991b1b); }
.status-skipped    { background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }
.status-neutral    { background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }

/* ── Activity ─────────────────────────────────────────────────────────────── */
.bdm-activity-list, .bdm-empty-state { display: flex; flex-direction: column; gap: 10px; }
.bdm-activity-item { display: flex; gap: 12px; align-items: flex-start; }
.bdm-activity-dot {
  width: 8px; height: 8px; border-radius: 50%; background: #3b82f6;
  margin-top: 4px; flex-shrink: 0;
}
.bdm-activity-dot.tone-paid   { background: #16a34a; }
.bdm-activity-dot.tone-cancel { background: #dc2626; }
.bdm-activity-dot.tone-log    { background: #7c3aed; }
.bdm-activity-dot.tone-muted  { background: #cbd5e1; }
.bdm-activity-text { font-size: 13px; color: var(--text, #0f172a); font-weight: 500; }
.bdm-activity-text.muted { color: var(--text-muted, #64748b); font-weight: 500; }
.bdm-activity-sub { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 1px; }
.bdm-activity-date { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 2px; }

/* ── Payment cards ────────────────────────────────────────────────────────── */
.bdm-pmt-card {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  padding: 12px 14px; margin-bottom: 10px;
}
.bdm-pmt-card:last-child { margin-bottom: 0; }
.bdm-pmt-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.bdm-pmt-type { font-size: 13px; font-weight: 600; color: var(--text, #0f172a); text-transform: capitalize; }
.bdm-pmt-meta { font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 4px; }
.bdm-pmt-ref { font-size: 11px; color: var(--text-light, #94a3b8); margin-bottom: 6px; font-family: 'SF Mono', Monaco, monospace; }
.bdm-pmt-row { display: flex; justify-content: space-between; align-items: center; }
.bdm-pmt-amount { font-size: 15px; font-weight: 700; color: var(--text, #0f172a); }
.bdm-pmt-date { font-size: 12px; color: var(--text-light, #94a3b8); }

/* Other pending payments (scoped) */
.bdm-pending-group { margin-bottom: 12px; }
.bdm-pending-group:last-of-type { margin-bottom: 0; }
.bdm-pending-grouphead { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: .03em; margin-bottom: 8px; }
.bdm-pending-sum { font-size: 12px; font-weight: 700; color: var(--warning-ink, #b45309); text-transform: none; letter-spacing: 0; }
.bdm-pending-toggle { display: flex; align-items: center; gap: 8px; width: 100%; background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 9px; padding: 9px 12px; font-size: 12.5px; font-weight: 600; color: var(--text-secondary, #334155); cursor: pointer; margin-bottom: 8px; }
.bdm-pending-toggle:hover { background: var(--bg, #f1f5f9); }
.bdm-pending-toggle span:first-of-type { flex: 1; text-align: left; }
.bdm-pending-row {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  width: 100%; text-align: left;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 9px;
  padding: 10px 12px; margin-bottom: 6px; cursor: pointer; transition: all .12s;
}
.bdm-pending-row:hover { border-color: #f59e0b; background: var(--warning-bg, #fffbeb); }
.bdm-pending-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.bdm-pending-venue { font-size: 13px; font-weight: 600; color: var(--text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bdm-pending-sub { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.bdm-pending-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.bdm-pending-amt { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.bdm-pending-arrow { color: var(--text-light, #cbd5e1); flex-shrink: 0; }
.bdm-pending-row:hover .bdm-pending-arrow { color: #f59e0b; }
.bdm-pending-note { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 8px; font-style: italic; }

/* Mark paid inline */
.bdm-mark-paid { display: flex; gap: 6px; align-items: center; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border, #e2e8f0); flex-wrap: wrap; }
.bdm-btn-mark {
  width: 100%; padding: 8px 14px; border-radius: 8px;
  background: #0f172a; color: #fff; font-size: 13px; font-weight: 600;
  border: none; cursor: pointer; transition: background .15s;
}
.bdm-btn-mark:hover { background: #1e293b; }
.bdm-select-sm {
  padding: 5px 8px; border-radius: 6px; border: 1px solid var(--border, #e2e8f0);
  font-size: 13px; background: var(--surface, #ffffff); color: var(--text, #0f172a);
}
.bdm-btn-confirm {
  display: flex; align-items: center; gap: 5px;
  padding: 6px 14px; border-radius: 6px; background: #16a34a; color: #fff;
  font-size: 13px; font-weight: 600; border: none; cursor: pointer;
}
.bdm-btn-confirm:disabled { opacity: .6; }
.bdm-btn-cancel-sm {
  padding: 6px 10px; border-radius: 6px; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b);
  font-size: 13px; border: 1px solid var(--border, #e2e8f0); cursor: pointer;
}

/* ── Payment summary ──────────────────────────────────────────────────────── */
.bdm-summary-table { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border, #e2e8f0); border-radius: 10px; overflow: hidden; margin-bottom: 14px; }
.bdm-summary-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px; font-size: 13.5px; border-bottom: 1px solid var(--border, #f1f5f9);
}
.bdm-summary-row:last-child { border-bottom: none; }
.bdm-summary-row span:first-child { color: var(--text-muted, #64748b); }
.bdm-summary-row span:last-child { font-weight: 500; color: var(--text, #0f172a); }
.total-row { background: var(--surface-hover, #f8fafc); }
.total-row span { font-weight: 700 !important; font-size: 14px !important; color: var(--text, #0f172a) !important; }
.due-row span { font-weight: 800 !important; color: var(--warning-ink, #b45309) !important; }

/* ── Accept Payment sheet ─────────────────────────────────────────────────── */
.bdm-collect-backdrop {
  position: fixed; inset: 0; z-index: 1300; background: rgba(15,23,42,.55);
  display: flex; align-items: center; justify-content: center; padding: 14px;
}
.bdm-collect {
  width: 100%; max-width: 420px; max-height: 92vh; display: flex; flex-direction: column;
  background: var(--surface, #ffffff); border-radius: 18px; box-shadow: 0 24px 64px rgba(15,23,42,.35); overflow: hidden;
}
.bdm-collect-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 16px; border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.bdm-collect-head > span { display: inline-flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.bdm-collect-x { background: none; border: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 4px; border-radius: 8px; display: inline-flex; }
.bdm-collect-x:hover { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); }
.bdm-collect-body { padding: 16px; overflow-y: auto; }
.bdm-collect-amt { background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; }
.bdm-collect-amt-l { font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: .03em; }
.bdm-collect-amt-v { font-size: 24px; font-weight: 800; color: var(--primary-ink, #0f766e); margin-top: 2px; }
.bdm-collect-amt-promo { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 12px; font-weight: 700; color: var(--positive-ink, #16a34a); }
.bdm-collect-sub { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); margin-bottom: 8px; }
.bdm-collect-methods { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.bdm-collect-method {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px 8px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 11px; background: var(--surface, #ffffff);
  font-size: 13.5px; font-weight: 700; color: var(--text-secondary, #334155); cursor: pointer; transition: all .12s;
}
.bdm-collect-method:hover { border-color: var(--primary, #0f766e); }
.bdm-collect-method.on { border-color: var(--primary, #0f766e); background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
.bdm-collect-confirm {
  width: 100%; padding: 12px; border: none; border-radius: 11px;
  background: #0f172a; color: #fff; font-size: 14px; font-weight: 800; cursor: pointer;
}
.bdm-collect-confirm:hover:not(:disabled) { background: #1e293b; }
.bdm-collect-confirm:disabled { opacity: .5; cursor: not-allowed; }
.bdm-collect-or { display: flex; align-items: center; gap: 10px; margin: 16px 0 12px; color: var(--text-light, #94a3b8); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.bdm-collect-or::before, .bdm-collect-or::after { content: ''; flex: 1; height: 1px; background: var(--border, #e2e8f0); }
.bdm-collect-link-l { font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #475569); margin-bottom: 5px; }
.bdm-collect-link input {
  width: 100%; padding: 9px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  font-size: 12.5px; color: var(--text-secondary, #334155); background: var(--surface-hover, #f8fafc);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bdm-collect-link-btns { display: flex; gap: 8px; margin-top: 8px; }
.bdm-collect-open, .bdm-collect-copy {
  flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 9px; border-radius: 10px; font-size: 13px; font-weight: 800; cursor: pointer; text-decoration: none;
}
.bdm-collect-open { background: var(--primary, #0f766e); color: #fff; border: 1.5px solid var(--primary, #0f766e); }
.bdm-collect-open:hover { opacity: .92; }
.bdm-collect-copy { background: var(--surface, #ffffff); color: var(--primary-ink, #0f766e); border: 1.5px solid var(--primary, #0f766e); }
.bdm-collect-copy:hover { background: var(--primary-soft, #f0fdfa); }
.bdm-collect-qrwrap { margin-top: 14px; padding: 14px; border: 1.5px dashed var(--primary, #0f766e); border-radius: 14px; text-align: center; background: var(--surface, #ffffff); }
.bdm-collect-qr { width: 200px; height: 200px; max-width: 65vw; border-radius: 10px; }
.bdm-collect-qr-hint { font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.5; margin-top: 6px; }
.bdm-collect-done { text-align: center; padding: 10px 4px 4px; }
.bdm-collect-done-badge { width: 52px; height: 52px; margin: 0 auto 10px; border-radius: 50%; background: var(--positive-bg, #f0fdf4); border: 1.5px solid var(--positive-border, #bbf7d0); color: var(--positive-ink, #15803d); display: flex; align-items: center; justify-content: center; }
.bdm-collect-done-t { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); }
.bdm-collect-done-s { font-size: 13px; color: var(--text-muted, #64748b); margin: 4px 0 16px; }
.discount-row span:last-child { color: var(--positive-ink, #16a34a) !important; }

/* Collect button */
.bdm-collect-block { margin-bottom: 14px; }
.bdm-btn-collect {
  width: 100%; padding: 11px; border-radius: 10px;
  background: #0f172a; color: #fff; font-size: 14px; font-weight: 600;
  border: none; cursor: pointer; transition: background .15s;
}
.bdm-btn-collect:hover { background: #1e293b; }

/* ── Promocode ────────────────────────────────────────────────────────────── */
.bdm-promo-block {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 14px;
}
.bdm-promo-header {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; font-weight: 600; color: var(--text, #0f172a); margin-bottom: 10px;
}
.bdm-promo-input-row { display: flex; gap: 8px; }
.bdm-promo-input {
  flex: 1; padding: 8px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: var(--text, #0f172a); background: var(--surface, #ffffff); text-transform: uppercase;
}
.bdm-promo-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.bdm-btn-promo {
  padding: 8px 16px; border-radius: 8px; background: #3b82f6; color: #fff;
  font-size: 13px; font-weight: 600; border: none; cursor: pointer;
}
.bdm-btn-promo:disabled { opacity: .6; }
.bdm-promo-hint { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 8px; }

/* Who absorbs the promo cost */
.bdm-promo-absorbed {
  font-size: 11.5px; font-weight: 500; margin-top: 4px;
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 7px; border-radius: 99px;
}
.promo-absorbed-bookboll { background: var(--purple-bg, #ede9fe); color: var(--purple-ink, #4f46e5); }
.promo-absorbed-provider  { background: var(--positive-bg, #d1fae5); color: var(--positive-ink, #059669); }
.bdm-promo-error {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; color: var(--danger-ink, #dc2626); margin-top: 6px;
}
.bdm-promo-applied {
  display: flex; align-items: center; gap: 8px;
  background: var(--positive-bg, #d1fae5); border-radius: 8px; padding: 8px 10px;
  font-size: 13px; color: var(--positive-ink, #065f46);
}
.bdm-promo-remove {
  margin-left: auto; background: none; border: none; cursor: pointer; color: var(--positive-ink, #065f46); padding: 2px;
  display: flex; align-items: center;
}

/* ── Actions ──────────────────────────────────────────────────────────────── */
.bdm-actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bdm-action-btn {
  display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 10px 14px; border-radius: 10px;
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0);
  font-size: 13px; font-weight: 500; color: var(--text-secondary, #334155); cursor: pointer;
  transition: all .15s;
}
.bdm-action-btn:hover { background: var(--bg, #f1f5f9); border-color: var(--border-strong, #cbd5e1); color: var(--text, #0f172a); }

/* ── Header actions ───────────────────────────────────────────────────────── */
.bdm-header-actions { display: flex; align-items: center; gap: 8px; }
.bdm-edit-btn {
  display: flex; align-items: center; gap: 5px;
  padding: 6px 12px; border-radius: 8px;
  border: 1px solid var(--border, #e2e8f0); background: var(--surface-hover, #f8fafc);
  font-size: 13px; font-weight: 500; color: var(--text-secondary, #374151); cursor: pointer;
  transition: all .15s;
}
.bdm-edit-btn:hover { background: var(--bg, #f1f5f9); border-color: #94a3b8; color: var(--text, #0f172a); }

/* ── Notes ────────────────────────────────────────────────────────────────── */
.bdm-notes-box {
  background: var(--warning-bg, #fefce8); border: 1px solid #fef08a; border-radius: 9px;
  padding: 12px 14px; font-size: 13.5px; color: var(--warning-ink, #713f12);
  line-height: 1.6; white-space: pre-wrap; word-break: break-word;
}

/* ── Edit overlay ─────────────────────────────────────────────────────────── */
.bdm-edit-overlay {
  position: absolute; inset: 0; background: var(--surface, #ffffff);
  display: flex; flex-direction: column;
  animation: slideInRight .18s cubic-bezier(.22,1,.36,1); z-index: 2; overflow: hidden;
}
.bdm-edit-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 18px 20px 14px; border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.bdm-edit-title    { font-size: 16px; font-weight: 700; color: var(--text, #0f172a); }
.bdm-edit-subtitle { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 2px; }
.bdm-edit-body     { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.bdm-edit-footer   { display: flex; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--border, #e2e8f0); flex-shrink: 0; }
.bdm-edit-section-label {
  font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8);
  text-transform: uppercase; letter-spacing: .07em;
  border-bottom: 1px solid var(--border, #f1f5f9); padding-bottom: 4px;
}
.bdm-edit-field     { display: flex; flex-direction: column; gap: 6px; }
.bdm-edit-label     { font-size: 13px; font-weight: 600; color: var(--text-secondary, #374151); }
.bdm-edit-label-row { display: flex; align-items: center; justify-content: space-between; }
.bdm-req            { color: var(--danger-ink, #ef4444); }
.bdm-edit-row       { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bdm-edit-add-link {
  display: flex; align-items: center; gap: 3px;
  font-size: 12px; font-weight: 500; color: var(--info-ink, #3b82f6);
  background: none; border: none; cursor: pointer; padding: 0;
}
.bdm-edit-add-link:hover { text-decoration: underline; }
/* inputs */
.bdm-edit-input {
  width: 100%; padding: 8px 11px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: var(--text, #0f172a); background: var(--surface, #ffffff); box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.bdm-edit-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.1); }
.bdm-edit-input-wrap { position: relative; }
.bdm-edit-input-icon { padding-left: 34px; }
.bdm-edit-field-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-light, #94a3b8); pointer-events: none; }
.bdm-edit-textarea {
  width: 100%; padding: 8px 11px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: var(--text, #0f172a); background: var(--surface, #ffffff); resize: vertical;
  font-family: inherit; min-height: 68px; box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.bdm-edit-textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.1); }
/* select */
.bdm-edit-select-wrap { position: relative; }
.bdm-edit-select {
  width: 100%; padding: 8px 32px 8px 11px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: var(--text, #0f172a); background: var(--surface, #ffffff); appearance: none; cursor: pointer;
  transition: border-color .15s; box-sizing: border-box;
}
.bdm-edit-select-icon { padding-left: 34px; }
.bdm-edit-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.1); }
.bdm-edit-select:disabled { opacity: .5; cursor: not-allowed; }
.bdm-edit-select-wrap::after {
  content: ''; position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
  width: 0; height: 0;
  border-left: 4px solid transparent; border-right: 4px solid transparent;
  border-top: 5px solid #94a3b8; pointer-events: none;
}
/* add customer */
.bdm-edit-add-customer {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 9px;
  padding: 12px; display: flex; flex-direction: column; gap: 8px;
}
.bdm-edit-save-customer-btn {
  display: flex; align-items: center; gap: 5px;
  padding: 7px 12px; border-radius: 7px; background: #0f172a; color: #fff;
  font-size: 12px; font-weight: 600; border: none; cursor: pointer; align-self: flex-start;
}
.bdm-edit-save-customer-btn:disabled { opacity: .6; cursor: not-allowed; }
/* customer picker */
.bdm-edit-cust-picker { position: relative; }
.bdm-edit-cust-selected {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 11px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  background: var(--surface-hover, #f8fafc); cursor: pointer;
}
.bdm-edit-cust-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: #0f172a; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.bdm-edit-cust-avatar.sm { width: 28px; height: 28px; font-size: 10px; }
.bdm-edit-cust-info { flex: 1; }
.bdm-edit-cust-name { font-size: 13px; font-weight: 600; color: var(--text, #0f172a); }
.bdm-edit-cust-sub  { font-size: 11.5px; color: var(--text-muted, #64748b); }
.bdm-edit-cust-clear { background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); padding: 3px; display: flex; align-items: center; }
.bdm-edit-cust-clear:hover { color: var(--danger-ink, #ef4444); }
.bdm-edit-cust-dropdown {
  position: absolute; z-index: 100; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,.1);
  max-height: 220px; overflow-y: auto; margin-top: 3px; width: 100%;
}
.bdm-edit-cust-option {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 12px; cursor: pointer; transition: background .1s;
}
.bdm-edit-cust-option:hover { background: var(--surface-hover, #f8fafc); }
/* slots */
.bdm-edit-slots-placeholder {
  display: flex; align-items: center; gap: 7px;
  padding: 12px 14px; background: var(--surface-hover, #f8fafc); border: 1px dashed var(--border, #e2e8f0);
  border-radius: 9px; font-size: 13px; color: var(--text-light, #94a3b8);
}
.bdm-closed-note { background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c); font-weight: 600; }
.bdm-edit-slots-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; }
.bdm-edit-slot {
  display: flex; flex-direction: column; align-items: center;
  padding: 8px 5px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 8px;
  background: var(--surface, #ffffff); cursor: pointer; transition: all .15s;
}
.bdm-edit-slot:hover:not(:disabled) { border-color: #3b82f6; background: var(--info-bg, #eff6ff); }
.bdm-edit-slot-selected  { border-color: #3b82f6 !important; background: var(--info-bg, #eff6ff) !important; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.bdm-edit-slot-taken     { background: var(--surface-hover, #f8fafc); border-color: var(--border, #f1f5f9); cursor: not-allowed; opacity: .45; }
.bdm-edit-slot-time  { font-size: 12px; font-weight: 700; color: var(--text, #0f172a); }
.bdm-edit-slot-end   { font-size: 10.5px; color: var(--text-light, #94a3b8); margin-top: 1px; }
.bdm-edit-slot-price { font-size: 10.5px; font-weight: 600; color: var(--positive-ink, #16a34a); margin-top: 2px; }
.bdm-edit-slot-summary {
  display: flex; align-items: center; gap: 5px;
  padding: 7px 11px; background: var(--info-bg, #eff6ff); border-radius: 7px;
  font-size: 12.5px; color: var(--info-ink, #1e40af); margin-top: 3px;
}
/* error */
.bdm-edit-error {
  display: flex; align-items: center; gap: 7px;
  padding: 9px 12px; background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca);
  border-radius: 8px; font-size: 12.5px; color: var(--danger-ink, #dc2626);
}
/* footer buttons */
.bdm-edit-btn-cancel {
  flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface-hover, #f8fafc); color: var(--text-secondary, #475569); font-size: 13.5px; font-weight: 500; cursor: pointer;
  transition: all .15s;
}
.bdm-edit-btn-cancel:hover { background: var(--bg, #f1f5f9); }
.bdm-edit-btn-save {
  flex: 2; display: flex; align-items: center; justify-content: center; gap: 7px;
  padding: 10px; border-radius: 8px; border: none;
  background: #0f172a; color: #fff; font-size: 13.5px; font-weight: 600; cursor: pointer;
  transition: background .15s;
}
.bdm-edit-btn-save:hover:not(:disabled) { background: #1e293b; }
.bdm-edit-btn-save:disabled { opacity: .6; cursor: not-allowed; }
.bdm-edit-spin { animation: bdmSpin 1s linear infinite; }
@keyframes bdmSpin { to { transform: rotate(360deg); } }

</style>
