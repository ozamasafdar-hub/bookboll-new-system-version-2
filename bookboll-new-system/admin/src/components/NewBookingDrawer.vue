<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import {
  fetchBookingLookups, fetchBookingOccupancy, createDashboardBooking, registerCustomer, firstError,
} from '../lib/bookings.js'
import { validatePromo } from '../lib/promocodes.js'
import {
  X, MapPin, ChevronDown, Clock, User, Plus, Check, Loader,
  CircleAlert as AlertCircle, Calendar, ChevronLeft, ChevronRight,
  RefreshCw, Tag, Package, Users, ToggleLeft, ToggleRight, Minus,
  CalendarX as CalendarX2, Info,
} from 'lucide-vue-next'
import { slotConflicts, toMinutes, fmt12, fmtRange12 } from '../lib/slots.js'
import { dayWindows, slotsForWindows, slotInWindows, applySpecialDayPrice } from '../lib/specialDays.js'
import { notifyBookingConfirmation } from '../customer/notificationDispatch.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { localCalendarDate } from '../lib/calendarDate.js'
import { CAP } from '../lib/permissions.js'

const { role, isSuperAdmin, isCountryAdmin, currentOwnerUserId, can } = useCurrentUser()
const isBookbollSide = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')

const props = defineProps({
  open: Boolean,
  // Optional prefill (e.g. when opened from a calendar slot)
  presetVenueId:   { type: String, default: '' },
  presetListingId: { type: String, default: '' },
  presetDate:      { type: String, default: '' },
  presetStartTime: { type: String, default: '' },
})
const emit  = defineEmits(['close', 'saved'])

// ── Master data ───────────────────────────────────────────────────────────────
const locations     = ref([])
const listings      = ref([])
const customers     = ref([])
const loadingMaster = ref(false)
const masterError = ref('')

// Providers can only book at their own venues (no owner link → none). Platform:
// super admin books anywhere; country_admin/platform_staff only in jurisdiction.
// (MUST come after `locations` above — referencing it earlier was a
// temporal-dead-zone crash that killed the whole drawer on mount.)
const { inJurisdictionLoc } = useJurisdiction({ locations })
const venueOptions = computed(() =>
  isBookbollSide.value
    ? locations.value.filter(l => inJurisdictionLoc(l.id))
    : locations.value.filter(l => currentOwnerUserId.value && l.owner_user_id === currentOwnerUserId.value)
)

async function loadMaster() {
  loadingMaster.value = true
  masterError.value = ''
  const res = await fetchBookingLookups({ withCustomers: true })
  if (!res.success) masterError.value = firstError(res, 'Booking options could not load. Please try again.')
  const d = res.success ? (res.data || {}) : {}
  locations.value = (d.locations || []).filter(l => l.status === 'active')
  listings.value  = (d.listings || []).filter(l => l.status === 'active')
  customers.value = d.customers || []
  loadingMaster.value = false
}

watch(() => props.open, async (v) => {
  if (!v) { clearDirty(); return }
  resetForm()
  await loadMaster()
  // Apply calendar/quick presets after master data is available
  if (props.presetDate)    bookingDate.value = props.presetDate
  if (props.presetVenueId && locations.value.some(l => l.id === props.presetVenueId)) {
    venueId.value = props.presetVenueId
    // Setting venueId triggers a watch that resets listingId; wait for it to
    // flush, then apply the preset listing so it survives.
    if (props.presetListingId) {
      await nextTick()
      if (listings.value.some(l => l.id === props.presetListingId && l.location_id === venueId.value)) {
        listingId.value = props.presetListingId
      }
    }
  }
  if (props.presetStartTime) pendingPresetSlot.value = props.presetStartTime
})

// Holds a preset start time until the slot list is generated (watch added below `slots`)
const pendingPresetSlot = ref('')

// ── Form state ────────────────────────────────────────────────────────────────
const venueId       = ref('')
const listingId     = ref('')
const customerId    = ref('')
const bookingDate   = ref(localCalendarDate())
const paymentMethod = ref('')
const notes         = ref('')
const selectedSlot  = ref(null)
const players       = ref(1)
const saving        = ref(false)
const saveError     = ref('')

// ── Add new customer inline ───────────────────────────────────────────────────
const showAddCustomer  = ref(false)
const newCustomerName  = ref('')
const newCustomerPhone = ref('')
const newCustomerEmail = ref('')
const savingCustomer   = ref(false)

async function createCustomer() {
  if (!newCustomerName.value.trim()) return
  savingCustomer.value = true
  const res = await registerCustomer({
    name: newCustomerName.value.trim(),
    phone: newCustomerPhone.value.trim() || null,
    email: newCustomerEmail.value.trim() || `c-${Date.now()}@placeholder.local`,
  })
  savingCustomer.value = false
  const user = res.success ? (res.data?.user || res.data?.customer) : null
  if (user) {
    const data = { id: String(user.id), full_name: user.name || user.full_name, phone: user.phone, email: user.email }
    customers.value = [...customers.value, data].sort((a,b) => (a.full_name||'').localeCompare(b.full_name||''))
    customerId.value = data.id
    showAddCustomer.value = false
    newCustomerName.value = ''
    newCustomerPhone.value = ''
    newCustomerEmail.value = ''
  }
}

// ── Derived ───────────────────────────────────────────────────────────────────
const venueListings = computed(() =>
  venueId.value ? listings.value.filter(l => l.location_id === venueId.value) : []
)

const selectedListing = computed(() => listings.value.find(l => l.id === listingId.value) || null)

const isPerPerson = computed(() => {
  const t = selectedListing.value?.listing_type
  return t && t !== 'per_space' && t !== 'court'
})

const maxPlayers = computed(() => selectedListing.value?.capacity || 99)

watch(venueId,     () => { listingId.value = ''; selectedSlot.value = null; addonQty.value = {}; recurringDates.value = []; recurringAvail.value = {} })
watch(listingId,   () => { selectedSlot.value = null; addonQty.value = {}; recurringDates.value = []; recurringAvail.value = {}; loadListingAddons() })
watch(bookingDate, () => { selectedSlot.value = null; recurringDates.value = []; recurringAvail.value = {} })
// The recurring-date verdicts (booked? inside that date's special/weekly
// window?) are computed against the CURRENT slot — a different slot needs a
// fresh check, so both the picks and the per-date cache reset with it.
watch(selectedSlot, (s, old) => {
  if (s?.start_time === old?.start_time) return
  recurringDates.value = []
  recurringAvail.value = {}
})

// ── Available time slots ──────────────────────────────────────────────────────
const slotsLoading     = ref(false)
const existingBookings = ref([])
const pricingCells     = ref([])
const pricingGroups    = ref([])
const listingHours     = ref([])   // weekly listing_hours rows for the selected listing
const specialRows      = ref([])   // special_days rows for the venue on the picked date

watch([listingId, bookingDate], async ([lid, date]) => {
  existingBookings.value = []
  pricingCells.value = []
  pricingGroups.value = []
  listingHours.value = []
  specialRows.value = []
  if (!lid || !date) return
  slotsLoading.value = true
  const occ = await fetchBookingOccupancy(lid, date)
  const d = occ.success ? (occ.data || {}) : {}
  existingBookings.value = d.existing_bookings || []
  pricingCells.value     = d.pricing_cells || []
  pricingGroups.value    = d.pricing_groups || []
  listingHours.value     = d.listing_hours || []
  specialRows.value      = d.special_days || []
  slotsLoading.value = false
}, { immediate: false })

// The day's schedule (special-day override → weekly hours → default window).
// Slot prices are untouched — special days only change availability.
const daySched = computed(() => {
  if (!selectedListing.value || !bookingDate.value) return null
  return dayWindows({
    date: bookingDate.value,
    listing: selectedListing.value,
    hoursRows: listingHours.value,
    specialRows: specialRows.value,
  })
})

function resolveSlotPrice(startTime) {
  // A special day may override pricing for the date (flat price or multiplier).
  const listPrice = (() => {
    if (!bookingDate.value || !selectedListing.value) return Number(selectedListing.value?.base_price) || 0
    const dow  = new Date(bookingDate.value + 'T00:00:00').getDay()
    const cell = pricingCells.value.find(c => c.day_of_week === dow && c.slot_time === startTime)
    if (cell) {
      if (cell.direct_price != null) return Number(cell.direct_price)
      if (cell.pricing_group_id) {
        const group = pricingGroups.value.find(g => g.id === cell.pricing_group_id)
        if (group) return Number(group.price)
      }
    }
    return Number(selectedListing.value?.base_price) || 0
  })()
  return applySpecialDayPrice(listPrice, daySched.value)
}

function isBooked(slot, bookingsList) {
  const buffer = Number(selectedListing.value?.buffer_time) || 0
  return slotConflicts(slot, bookingsList || existingBookings.value, buffer)
}

const slots = computed(() => {
  if (!selectedListing.value) return []
  const sched = daySched.value
  if (!sched) return []
  if (sched.closed) return []
  // buffer is baked into the slot step via the shared helper
  return slotsForWindows(selectedListing.value, sched.windows, bookingDate.value)
})

// Auto-select the slot matching a calendar-preset start time once slots are ready.
// The clicked calendar time is snapped to 15 min, which may not line up exactly
// with this listing's slot grid (e.g. hourly courts), so fall back to the
// nearest available slot start.
// Waits for slotsLoading: while the hours/special-days fetch is in flight the
// grid is an interim default (06:00–23:00) — selecting from it could pin a time
// that the real schedule (special day / weekly hours) doesn't allow.
watch([slots, slotsLoading], ([list, loading]) => {
  if (loading || !pendingPresetSlot.value || !list?.length) return
  const target = toMinutes(pendingPresetSlot.value)
  let best = list.find(s => s.start_time === pendingPresetSlot.value)
  if (!best) {
    best = list.reduce((a, s) =>
      Math.abs(toMinutes(s.start_time) - target) < Math.abs(toMinutes(a.start_time) - target) ? s : a
    )
  }
  if (best) { selectedSlot.value = best; pendingPresetSlot.value = '' }
})

// A slot picked before the schedule fetch resolved (or kept across a data
// refresh) may not exist in the final grid — drop it once loading settles.
watch(slotsLoading, (loading) => {
  if (loading || !selectedSlot.value) return
  if (!slots.value.some(s => s.start_time === selectedSlot.value.start_time)) selectedSlot.value = null
})

function fmtTime(t) {
  if (!t) return ''
  return fmt12(t)
}

// ── Add-ons ───────────────────────────────────────────────────────────────────
const listingAddons = ref([])
const addonQty      = ref({})
const addonsLoading = ref(false)

async function loadListingAddons() {
  listingAddons.value = []
  addonQty.value = {}
  if (!listingId.value) return
  const fromListing = selectedListing.value?.add_ons
  if (fromListing?.length) {
    listingAddons.value = fromListing.filter(a => a && (a.status || 'active') === 'active')
    return
  }
  addonsLoading.value = true
  const occ = await fetchBookingOccupancy(listingId.value, bookingDate.value || localCalendarDate())
  addonsLoading.value = false
  listingAddons.value = ((occ.success ? occ.data?.add_ons : null) || []).filter(a => a && (a.status || 'active') === 'active')
}

function addonMax(addon) { return addon.stock != null ? addon.stock : 99 }

function setAddonQty(addonId, delta) {
  const addon = listingAddons.value.find(a => a.id === addonId)
  if (!addon) return
  const current = addonQty.value[addonId] || 0
  const next    = Math.max(0, Math.min(addonMax(addon), current + delta))
  if (next === 0) {
    const copy = { ...addonQty.value }
    delete copy[addonId]
    addonQty.value = copy
  } else {
    addonQty.value = { ...addonQty.value, [addonId]: next }
  }
}

const addonTotal = computed(() =>
  listingAddons.value.reduce((sum, a) => sum + (addonQty.value[a.id] || 0) * Number(a.price), 0)
)

const selectedAddons = computed(() =>
  listingAddons.value.filter(a => (addonQty.value[a.id] || 0) > 0)
)

// ── Promo code ────────────────────────────────────────────────────────────────
const showPromo    = ref(false)
const promoInput   = ref('')
const promoApplied = ref(null)
const promoError   = ref('')
const promoLoading = ref(false)

async function applyPromo() {
  promoError.value   = ''
  promoApplied.value = null
  if (!promoInput.value.trim()) return
  promoLoading.value = true
  const result = await validatePromo(promoInput.value, {
    amount: basePrice.value + addonTotal.value,
    applicableTo: 'facility',
    locationId: venueId.value,
    listingId: listingId.value,
    customerEmail: selectedCustomer.value?.email || '',
  })
  promoLoading.value = false
  if (!result.ok) { promoError.value = result.reason || 'Promo code not found.'; return }
  promoApplied.value = result.code
}

function removePromo() {
  promoApplied.value = null
  promoInput.value   = ''
  promoError.value   = ''
}

const discountAmount = computed(() => {
  if (!promoApplied.value) return 0
  const subtotal = basePrice.value + addonTotal.value
  if (promoApplied.value.discount_type === 'percentage') {
    return Math.round((subtotal * Number(promoApplied.value.discount_value) / 100) * 100) / 100
  }
  return Math.min(Number(promoApplied.value.discount_value), subtotal)
})

// ── Price calculation ─────────────────────────────────────────────────────────
const basePrice = computed(() => {
  if (!selectedSlot.value) return 0
  const slotPrice = resolveSlotPrice(selectedSlot.value.start_time)
  return isPerPerson.value ? slotPrice * players.value : slotPrice
})

const grandTotal = computed(() =>
  Math.max(0, basePrice.value + addonTotal.value - discountAmount.value)
)

// ── Recurring booking ─────────────────────────────────────────────────────────
const recurringEnabled = ref(false)
const recurringDates   = ref([])
const recurringAvail   = ref({})
const recurringLoading = ref(false)
const calendarMonth    = ref(null)

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const DAYS   = ['Su','Mo','Tu','We','Th','Fr','Sa']

function initCalendar() {
  const d = bookingDate.value ? new Date(bookingDate.value + 'T00:00:00') : new Date()
  calendarMonth.value = { year: d.getFullYear(), month: d.getMonth() }
}

watch(recurringEnabled, (v) => { if (v) initCalendar() })

function prevMonth() {
  let { year, month } = calendarMonth.value
  if (--month < 0) { month = 11; year-- }
  calendarMonth.value = { year, month }
}
function nextMonth() {
  let { year, month } = calendarMonth.value
  if (++month > 11) { month = 0; year++ }
  calendarMonth.value = { year, month }
}

const calendarDays = computed(() => {
  if (!calendarMonth.value) return []
  const { year, month } = calendarMonth.value
  const firstDay    = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const days = []
  for (let i = 0; i < firstDay; i++) days.push(null)
  for (let d = 1; d <= daysInMonth; d++) {
    days.push(`${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`)
  }
  return days
})

const todayIso = computed(() => { props.open; return localCalendarDate() })

async function toggleRecurringDate(iso) {
  if (!iso || iso < todayIso || iso === bookingDate.value) return
  if (!selectedSlot.value || !listingId.value) return

  if (recurringDates.value.includes(iso)) {
    recurringDates.value = recurringDates.value.filter(d => d !== iso)
    return
  }

  if (recurringAvail.value[iso] === undefined) {
    recurringLoading.value = true
    const occ = await fetchBookingOccupancy(listingId.value, iso)
    recurringLoading.value = false
    const occData = occ.success ? (occ.data || {}) : {}
    const data = occData.existing_bookings || []
    const sdRows = occData.special_days || []
    // A repeat date is unavailable when it's booked, the venue/listing is closed
    // that day, or the picked time falls outside that date's (special) schedule.
    const sched = dayWindows({ date: iso, listing: selectedListing.value, hoursRows: listingHours.value, specialRows: sdRows })
    const blocked = sched.closed
      || !slotInWindows(selectedSlot.value, sched.windows)
      || isBooked(selectedSlot.value, data || [])
    recurringAvail.value = { ...recurringAvail.value, [iso]: !blocked }
    if (blocked) return
  } else if (!recurringAvail.value[iso]) {
    return
  }

  recurringDates.value = [...recurringDates.value, iso].sort()
}

const allBookingDates = computed(() =>
  [...new Set([bookingDate.value, ...recurringDates.value])].sort()
)

const recurringTotal = computed(() => grandTotal.value * allBookingDates.value.length)

// ── Save ──────────────────────────────────────────────────────────────────────
async function save() {
  saveError.value = ''
  if (!venueId.value)       { saveError.value = 'Please select a venue.'; return }
  if (!isBookbollSide.value && !venueOptions.value.some(l => l.id === venueId.value)) {
    saveError.value = 'You can only create bookings for your own venues.'; return
  }
  if (!listingId.value)     { saveError.value = 'Please select a listing.'; return }
  if (!selectedSlot.value)  { saveError.value = 'Please select a time slot.'; return }
  // Final schedule gate: the picked time must exist inside the booking date's
  // resolved windows (special day → weekly hours → default). Catches a slot
  // pinned from a preset before the schedule fetch landed, or a date closed
  // by a special day after the grid was rendered.
  {
    const sched = daySched.value
    if (!sched || sched.closed) {
      saveError.value = 'This date is closed (special day). Please pick another date.'; return
    }
    if (!slotInWindows(selectedSlot.value, sched.windows)) {
      saveError.value = 'The selected time is outside this date’s schedule. Please pick a slot from the list.'; return
    }
  }
  if (!paymentMethod.value) { saveError.value = 'Please select a payment method.'; return }

  saving.value = true
  const location  = locations.value.find(l => l.id === venueId.value)
  const isPrepaid = ['paid_cash', 'paid_cheque', 'paid_card', 'paid_wire'].includes(paymentMethod.value)
  const curr      = location?.currency_override || ''

  const dates      = allBookingDates.value
  const createdIds = []
  const createdRefs = []   // the bookings' own references, for the confirmation email

  for (const date of dates) {
    const res = await createDashboardBooking({
      listing_id:     listingId.value,
      location_id:    venueId.value,
      customer_id:    customerId.value || null,
      booking_date:   date,
      start_time:     selectedSlot.value.start_time,
      end_time:       selectedSlot.value.end_time,
      players:        players.value,
      total:          grandTotal.value,
      discount:       discountAmount.value,
      payment_method: paymentMethod.value,
      notes:          notes.value.trim() || '',
      promocode_id:   promoApplied.value?.id || null,
      addons:         selectedAddons.value.map(a => ({
        id: a.id,
        quantity: addonQty.value[a.id] || 1,
        unit_price: Number(a.price),
      })),
    })

    if (!res.success) {
      saveError.value = firstError(res, `Failed to create booking for ${date}.`)
      saving.value = false
      return
    }

    createdIds.push(res.data?.booking?.id)
    createdRefs.push(res.data?.booking?.booking_id || '')
  }

  // Fire-and-forget: send the configured booking confirmation to the customer (WhatsApp).
  try {
    if (customerId.value && createdIds.length) {
      const c = selectedCustomer.value
      notifyBookingConfirmation({
        ownerId: location?.owner_user_id || null,
        customer: { id: customerId.value, name: c?.full_name, email: c?.email, phone: c?.phone },
        booking: {
          listing: selectedListing.value?.name, venue: location?.name, business_name: location?.name,
          date: new Date(dates[0] + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }),
          start_time: selectedSlot.value?.start_time ? fmt12(selectedSlot.value.start_time) : '',
          end_time: selectedSlot.value?.end_time ? fmt12(selectedSlot.value.end_time) : '',
          code: createdRefs[0] || String(createdIds[0] || ''), amount: grandTotal.value,
          balance_due: isPrepaid ? 0 : grandTotal.value, currency: curr,
        },
      })
    }
  } catch { /* non-blocking */ }

  saving.value = false
  clearDirty()
  emit('saved', createdIds)
}

// Guarded close — prompt if the user has unsaved edits.
async function requestClose() {
  if (await confirmDiscardIfDirty()) emit('close')
}

function resetForm() {
  clearDirty()
  venueId.value = ''; listingId.value = ''; customerId.value = ''
  bookingDate.value = localCalendarDate()
  paymentMethod.value = ''; notes.value = ''; selectedSlot.value = null
  players.value = 1; saveError.value = ''; showAddCustomer.value = false
  existingBookings.value = []; listingAddons.value = []; addonQty.value = {}
  showPromo.value = false; promoInput.value = ''; promoApplied.value = null; promoError.value = ''
  recurringEnabled.value = false; recurringDates.value = []; recurringAvail.value = {}; calendarMonth.value = null
  newCustomerName.value = ''; newCustomerPhone.value = ''; newCustomerEmail.value = ''
}

// ── Customer search ───────────────────────────────────────────────────────────
const customerSearch       = ref('')
const showCustomerDropdown = ref(false)

const filteredCustomers = computed(() => {
  const q = customerSearch.value.toLowerCase()
  return q ? customers.value.filter(c =>
    c.full_name.toLowerCase().includes(q) || c.phone?.includes(q) || c.email?.toLowerCase().includes(q)
  ) : customers.value
})

const selectedCustomer = computed(() => customers.value.find(c => c.id === customerId.value))

function selectCustomer(c) {
  customerId.value = c.id
  customerSearch.value = ''
  showCustomerDropdown.value = false
}

const currency = computed(() => locations.value.find(l => l.id === venueId.value)?.currency_override || '')
function fmt(n) { return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="nbm-backdrop" @click.self="requestClose">
      <div class="nbm-panel" v-dialog="requestClose" @input="markDirty" @change="markDirty">

        <!-- Header -->
        <div class="nbm-header">
          <div>
            <div class="nbm-title">New booking</div>
            <div class="nbm-subtitle">Choose a listing and time, then record the customer and payment.</div>
          </div>
          <button class="nbm-close" aria-label="Close booking form" @click="requestClose"><X :size="18" /></button>
        </div>

        <div class="nbm-body">

          <!-- ── Basic Information ── -->
          <div class="nbm-section-label">Booking details</div>
          <div v-if="masterError" class="nbm-error" role="alert">
            <AlertCircle :size="16" /><span>{{ masterError }}</span>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="loadingMaster" @click="loadMaster">Try again</button>
          </div>

          <!-- Venue -->
          <div class="nbm-field">
            <label class="nbm-label">Venue <span class="nbm-req">*</span></label>
            <div class="nbm-select-wrap">
              <MapPin :size="15" class="nbm-field-icon" />
              <select v-model="venueId" aria-label="Venue" :disabled="loadingMaster" class="nbm-select nbm-select-with-icon">
                <option value="">{{ loadingMaster ? 'Loading venues…' : 'Select venue' }}</option>
                <option v-for="loc in venueOptions" :key="loc.id" :value="loc.id">{{ loc.name }}</option>
              </select>
              <ChevronDown :size="15" class="nbm-select-arrow" />
            </div>
            <p v-if="!loadingMaster && !masterError && !venueOptions.length" class="nbm-subtitle" role="status">No active venues are available to this account. Check your venue status and team access in Listings.</p>
          </div>

          <!-- Listing -->
          <div class="nbm-field">
            <label class="nbm-label">Listing <span class="nbm-req">*</span></label>
            <div class="nbm-select-wrap">
              <select v-model="listingId" aria-label="Listing" class="nbm-select" :disabled="!venueId">
                <option value="">{{ venueId ? 'Select Listing' : 'Select a venue first' }}</option>
                <option v-for="l in venueListings" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
              <ChevronDown :size="15" class="nbm-select-arrow" />
            </div>
          </div>

          <!-- Players (only for per-person listings) -->
          <div v-if="isPerPerson" class="nbm-field">
            <label class="nbm-label">Players</label>
            <div class="nbm-stepper">
              <button class="nbm-step-btn" :disabled="players <= 1" @click="players = Math.max(1, players - 1)">
                <Minus :size="14" />
              </button>
              <span class="nbm-step-val">{{ players }}</span>
              <button class="nbm-step-btn" :disabled="players >= maxPlayers" @click="players = Math.min(maxPlayers, players + 1)">
                <Plus :size="14" />
              </button>
              <span class="nbm-step-hint">max {{ maxPlayers }}</span>
            </div>
          </div>

          <!-- Customer -->
          <div class="nbm-field">
            <div class="nbm-label-row">
              <label class="nbm-label">Customer <span class="nbm-req">*</span></label>
              <button class="nbm-add-link" @click="showAddCustomer = !showAddCustomer">
                <Plus :size="13" />{{ showAddCustomer ? 'Cancel' : 'Add New' }}
              </button>
            </div>

            <div v-if="showAddCustomer" class="nbm-add-customer">
              <input v-model="newCustomerName" placeholder="Full name *" class="nbm-input" />
              <div class="nbm-grid-2">
                <input v-model="newCustomerPhone" placeholder="Phone" class="nbm-input" />
                <input v-model="newCustomerEmail" placeholder="Email" class="nbm-input" type="email" />
              </div>
              <button class="nbm-btn-save-customer" :disabled="!newCustomerName.trim() || savingCustomer" @click="createCustomer">
                <Check :size="13" />{{ savingCustomer ? 'Saving...' : 'Save Customer' }}
              </button>
            </div>

            <div v-else class="nbm-customer-picker">
              <div v-if="selectedCustomer" class="nbm-customer-selected" @click="customerId = ''; showCustomerDropdown = true">
                <div class="nbm-customer-avatar">{{ selectedCustomer.full_name.split(' ').map(n=>n[0]).slice(0,2).join('') }}</div>
                <div class="nbm-customer-info">
                  <div class="nbm-customer-name">{{ selectedCustomer.full_name }}</div>
                  <div class="nbm-customer-sub">{{ selectedCustomer.phone || selectedCustomer.email || '' }}</div>
                </div>
                <button class="nbm-customer-clear" aria-label="Remove selected customer" @click.stop="customerId = ''"><X :size="13" /></button>
              </div>
              <div v-else>
                <input
                  v-model="customerSearch"
                  placeholder="Search by name, phone or email..." aria-label="Search customers"
                  class="nbm-input"
                  @focus="showCustomerDropdown = true"
                  @blur="setTimeout(() => showCustomerDropdown = false, 180)"
                />
                <div v-if="showCustomerDropdown && filteredCustomers.length" class="nbm-customer-dropdown">
                  <div
                    v-for="c in filteredCustomers.slice(0, 12)"
                    :key="c.id"
                    class="nbm-customer-option"
                    @mousedown.prevent="selectCustomer(c)"
                  >
                    <div class="nbm-customer-avatar sm">{{ c.full_name.split(' ').map(n=>n[0]).slice(0,2).join('') }}</div>
                    <div>
                      <div class="nbm-customer-name">{{ c.full_name }}</div>
                      <div class="nbm-customer-sub">{{ c.phone || c.email || '' }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Date -->
          <div class="nbm-field">
            <label class="nbm-label">Date <span class="nbm-req">*</span></label>
            <div class="nbm-input-wrap">
              <!-- No decorative left icon here: the native date input renders its
                   own picker icon, and the overlay icon sat on top of the day digits.
                   .lazy: native date inputs fire `input` per SEGMENT while typing,
                   so an eager v-model wrote partial values into state mid-edit.
                   min blocks booking days that are already gone. -->
              <input type="date" v-model.lazy="bookingDate" aria-label="Booking date" :min="todayIso" class="nbm-input" />
            </div>
          </div>

          <!-- Time Slots -->
          <div class="nbm-field">
            <label class="nbm-label">Available Time Slots</label>
            <div v-if="!listingId || !bookingDate" class="nbm-slots-placeholder">
              <Clock :size="16" />
              Please select a listing and date to view available time slots.
            </div>
            <div v-else-if="slotsLoading" class="nbm-slots-placeholder">
              <Loader :size="16" class="nbm-spin" />Loading slots...
            </div>
            <div v-else-if="daySched?.closed" class="nbm-closed-note">
              <CalendarX2 :size="15" />
              <span>Closed on this date<template v-if="daySched.name && daySched.name !== 'Closed'"> — {{ daySched.name }}</template> (special day). Pick another date.</span>
            </div>
            <div v-else-if="!slots.length" class="nbm-slots-placeholder">
              <Clock :size="16" />No bookable times on this date.
            </div>
            <template v-else>
              <div v-if="daySched?.special" class="nbm-special-note">
                <Info :size="13" /> Special schedule on this date<template v-if="daySched.name && daySched.name !== 'Special schedule'"> — {{ daySched.name }}</template>
              </div>
            <div class="nbm-slots-grid">
              <button
                v-for="slot in slots"
                :key="slot.start_time"
                class="nbm-slot"
                :class="{
                  'nbm-slot-taken': isBooked(slot),
                  'nbm-slot-selected': selectedSlot?.start_time === slot.start_time
                }"
                :disabled="isBooked(slot)"
                @click="selectedSlot = slot"
              >
                <span class="nbm-slot-time">{{ fmtTime(slot.start_time) }}</span>
                <span class="nbm-slot-end">– {{ fmtTime(slot.end_time) }}</span>
                <span class="nbm-slot-price">{{ currency }} {{ resolveSlotPrice(slot.start_time).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}</span>
              </button>
            </div>
            </template>
            <div v-if="selectedSlot" class="nbm-slot-summary">
              <Clock :size="13" />
              <strong>{{ fmtTime(selectedSlot.start_time) }} – {{ fmtTime(selectedSlot.end_time) }}</strong>
              <span v-if="basePrice > 0">&nbsp;·&nbsp; {{ currency }} {{ fmt(basePrice) }}</span>
            </div>
          </div>

          <!-- ── Recurring ── -->
          <template v-if="selectedSlot">
            <div class="nbm-section-label">Recurring Booking</div>
            <div class="nbm-field">
              <div class="nbm-toggle-row" @click="recurringEnabled = !recurringEnabled">
                <div class="nbm-toggle-label">
                  <RefreshCw :size="14" />
                  Repeat this booking on other dates?
                </div>
                <component :is="recurringEnabled ? ToggleRight : ToggleLeft" :size="28"
                  :class="['nbm-toggle-icon', recurringEnabled ? 'nbm-toggle-on' : '']" />
              </div>

              <div v-if="recurringEnabled && calendarMonth" class="nbm-calendar-wrap">
                <div class="nbm-cal-header">
                  <button class="nbm-cal-nav" @click="prevMonth"><ChevronLeft :size="16" /></button>
                  <span class="nbm-cal-month">{{ MONTHS[calendarMonth.month] }} {{ calendarMonth.year }}</span>
                  <button class="nbm-cal-nav" @click="nextMonth"><ChevronRight :size="16" /></button>
                </div>
                <div class="nbm-cal-days-header">
                  <span v-for="d in DAYS" :key="d" class="nbm-cal-day-name">{{ d }}</span>
                </div>
                <div class="nbm-cal-grid">
                  <div v-for="(iso, i) in calendarDays" :key="i" class="nbm-cal-cell">
                    <button
                      v-if="iso"
                      class="nbm-cal-btn"
                      :class="{
                        'nbm-cal-today':       iso === todayIso,
                        'nbm-cal-primary':     iso === bookingDate,
                        'nbm-cal-selected':    recurringDates.includes(iso),
                        'nbm-cal-available':   recurringAvail[iso] === true && !recurringDates.includes(iso) && iso !== bookingDate,
                        'nbm-cal-unavailable': recurringAvail[iso] === false,
                        'nbm-cal-past':        iso < todayIso,
                      }"
                      :disabled="iso < todayIso || iso === bookingDate || recurringAvail[iso] === false"
                      @click="toggleRecurringDate(iso)"
                    >
                      {{ parseInt(iso.slice(8)) }}
                      <span v-if="recurringAvail[iso] === false" class="nbm-cal-x">✕</span>
                    </button>
                    <div v-else class="nbm-cal-empty"></div>
                  </div>
                </div>
                <div class="nbm-cal-legend">
                  <span class="nbm-leg nbm-leg-primary"></span><span>Primary date</span>
                  <span class="nbm-leg nbm-leg-selected"></span><span>Selected</span>
                  <span class="nbm-leg nbm-leg-unavail"></span><span>Slot taken</span>
                </div>
                <div v-if="recurringDates.length" class="nbm-recurring-summary">
                  <RefreshCw :size="12" />
                  Will create <strong>{{ allBookingDates.length }} bookings</strong>:
                  {{ allBookingDates.map(d => new Date(d+'T00:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric'})).join(', ') }}
                </div>
              </div>
            </div>
          </template>

          <!-- ── Add-ons ── -->
          <template v-if="selectedListing && (listingAddons.length > 0 || addonsLoading)">
            <div class="nbm-section-label">Add-ons</div>
            <div v-if="addonsLoading" class="nbm-slots-placeholder">
              <Loader :size="16" class="nbm-spin" />Loading add-ons...
            </div>
            <div v-else class="nbm-addons-list">
              <div
                v-for="addon in listingAddons"
                :key="addon.id"
                class="nbm-addon-row"
                :class="{ 'nbm-addon-oos': addon.stock === 0 }"
              >
                <div class="nbm-addon-info">
                  <div class="nbm-addon-name">
                    <Package :size="13" class="nbm-addon-icon" />
                    {{ addon.name }}
                    <span v-if="addon.stock === 0" class="nbm-badge-oos">Out of stock</span>
                    <span v-else-if="addon.stock != null && addon.stock <= 5" class="nbm-badge-low">{{ addon.stock }} left</span>
                  </div>
                  <div v-if="addon.description" class="nbm-addon-desc">{{ addon.description }}</div>
                  <div class="nbm-addon-price">{{ currency }} {{ fmt(addon.price) }} each</div>
                </div>
                <div class="nbm-stepper" :class="{ 'nbm-stepper-disabled': addon.stock === 0 }">
                  <button class="nbm-step-btn" :disabled="!addonQty[addon.id] || addon.stock === 0"
                    @click="setAddonQty(addon.id, -1)"><Minus :size="13" /></button>
                  <span class="nbm-step-val">{{ addonQty[addon.id] || 0 }}</span>
                  <button class="nbm-step-btn"
                    :disabled="addon.stock === 0 || (addonQty[addon.id] || 0) >= addonMax(addon)"
                    @click="setAddonQty(addon.id, 1)"><Plus :size="13" /></button>
                </div>
              </div>
            </div>
          </template>

          <!-- ── Payment ── -->
          <div class="nbm-section-label">Payment &amp; notes</div>

          <div class="nbm-field">
            <label class="nbm-label">Payment Method <span class="nbm-req">*</span></label>
            <div class="nbm-select-wrap">
              <select v-model="paymentMethod" aria-label="Payment method" class="nbm-select">
                <option value="" disabled>Select payment method</option>
                <option value="payment_skipped">Skip payment</option>
                <option value="cash">Pay on Arrival</option>
                <option v-if="can(CAP.ACCEPT_PAYMENTS)" value="paid_cash">Paid cash</option>
                <option v-if="can(CAP.ACCEPT_PAYMENTS)" value="paid_cheque">Paid cheque</option>
                <option v-if="can(CAP.ACCEPT_PAYMENTS)" value="paid_card">Paid by card at venue</option>
                <option v-if="can(CAP.ACCEPT_PAYMENTS)" value="paid_wire">Paid bank transfer</option>
              </select>
              <ChevronDown :size="15" class="nbm-select-arrow" />
            </div>
            <p v-if="paymentMethod" class="nbm-hint" role="status">
              <template v-if="paymentMethod === 'payment_skipped'">Reserves the slot only. No payment record or revenue is created.</template>
              <template v-else-if="paymentMethod === 'cash'">Creates an unpaid balance to collect at the venue.</template>
              <template v-else>Records money already collected. It does not charge a card or send a payment request.</template>
              <template v-if="paymentMethod !== 'payment_skipped'"> For bookings added here, the venue operator keeps the full payment: the external business, or Bookboll for its own venues. No platform fee is added.</template>
            </p>
          </div>

          <!-- Promo code -->
          <div class="nbm-field">
            <div class="nbm-toggle-row nbm-toggle-row-sm" @click="showPromo = !showPromo">
              <div class="nbm-toggle-label">
                <Tag :size="14" />
                Have a promo code?
              </div>
              <component :is="showPromo ? ToggleRight : ToggleLeft" :size="24"
                :class="['nbm-toggle-icon', showPromo ? 'nbm-toggle-on' : '']" />
            </div>

            <div v-if="showPromo" class="nbm-promo-wrap">
              <div v-if="promoApplied" class="nbm-promo-applied">
                <div class="nbm-promo-applied-info">
                  <Tag :size="13" />
                  <div>
                    <div class="nbm-promo-code">{{ promoApplied.code }}</div>
                    <div class="nbm-promo-savings">
                      {{ promoApplied.discount_type === 'percentage' ? promoApplied.discount_value + '% off' : currency + ' ' + fmt(promoApplied.discount_value) + ' off' }}
                      · saving {{ currency }} {{ fmt(discountAmount) }}
                    </div>
                  </div>
                </div>
                <button class="nbm-promo-remove" @click="removePromo" aria-label="Close"><X :size="13" /></button>
              </div>
              <div v-else class="nbm-promo-input-row">
                <input v-model="promoInput" placeholder="Enter promo code" class="nbm-input nbm-promo-input"
                  @keydown.enter="applyPromo" />
                <button class="nbm-btn-apply-promo" :disabled="!promoInput.trim() || promoLoading" @click="applyPromo">
                  <Loader v-if="promoLoading" :size="13" class="nbm-spin" />
                  <span v-else>Apply</span>
                </button>
              </div>
              <div v-if="promoError" class="nbm-promo-error">{{ promoError }}</div>
            </div>
          </div>

          <!-- Notes -->
          <div class="nbm-field">
            <label class="nbm-label">Notes (Optional)</label>
            <textarea v-model="notes" placeholder="Add any notes for this booking..." class="nbm-textarea" rows="3"></textarea>
          </div>

          <!-- ── Price Summary ── -->
          <div v-if="selectedSlot" class="nbm-price-summary">
            <div class="nbm-price-row">
              <span>Base price{{ isPerPerson && players > 1 ? ` × ${players} players` : '' }}</span>
              <span>{{ currency }} {{ fmt(basePrice) }}</span>
            </div>
            <div v-if="addonTotal > 0" class="nbm-price-row">
              <span>Add-ons ({{ selectedAddons.length }} item{{ selectedAddons.length !== 1 ? 's' : '' }})</span>
              <span>{{ currency }} {{ fmt(addonTotal) }}</span>
            </div>
            <div v-if="discountAmount > 0" class="nbm-price-row nbm-price-discount">
              <span>Promo ({{ promoApplied?.code }})</span>
              <span>− {{ currency }} {{ fmt(discountAmount) }}</span>
            </div>
            <div class="nbm-price-row nbm-price-total">
              <span>Total per booking</span>
              <span>{{ currency }} {{ fmt(grandTotal) }}</span>
            </div>
            <div v-if="recurringEnabled && allBookingDates.length > 1" class="nbm-price-row nbm-price-recurring">
              <span>× {{ allBookingDates.length }} bookings</span>
              <span>{{ currency }} {{ fmt(recurringTotal) }}</span>
            </div>
          </div>

          <!-- Error -->
          <div v-if="saveError" class="nbm-error">
            <AlertCircle :size="14" />{{ saveError }}
          </div>

        </div>

        <!-- Footer -->
        <div class="nbm-footer">
          <button class="nbm-btn-cancel" @click="requestClose">Cancel</button>
          <button class="nbm-btn-save" :disabled="saving" @click="save">
            <Loader v-if="saving" :size="15" class="nbm-spin" />
            {{ saving ? 'Creating...' : (recurringEnabled && allBookingDates.length > 1 ? `Create ${allBookingDates.length} Bookings` : 'Create Booking') }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>

<style>
/* ── Backdrop & Panel ─────────────────────────────────────────────────────── */
.nbm-backdrop {
  position: fixed; inset: 0; background: rgba(15,23,42,.45);
  z-index: 1000; display: flex; justify-content: flex-end;
  backdrop-filter: blur(2px);
}
.nbm-panel {
  width: 100%; max-width: 520px; height: 100%; background: var(--surface, #ffffff);
  display: flex; flex-direction: column;
  box-shadow: -8px 0 40px rgba(0,0,0,.18);
  animation: nbmSlide .22s cubic-bezier(.22,1,.36,1);
}
@keyframes nbmSlide { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

.nbm-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding: 20px 20px 16px; border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.nbm-title { font-size: 17px; font-weight: 700; color: var(--text, #0f172a); }
.nbm-subtitle { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 2px; }
.nbm-close {
  width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9);
  display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted, #64748b);
}
.nbm-close:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }

.nbm-body { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 20px; }

.nbm-section-label {
  font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8);
  text-transform: uppercase; letter-spacing: .07em;
  padding-bottom: 4px; border-bottom: 1px solid var(--border, #f1f5f9);
}

.nbm-field { display: flex; flex-direction: column; gap: 7px; }
.nbm-label { font-size: 13px; font-weight: 600; color: var(--text-secondary, #374151); display: flex; align-items: center; gap: 6px; }
.nbm-label-hint { font-size: 11px; font-weight: 400; color: var(--text-light, #94a3b8); }
.nbm-label-row { display: flex; align-items: center; justify-content: space-between; }
.nbm-req { color: var(--danger-ink, #ef4444); }
.nbm-add-link {
  display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500;
  color: var(--info-ink, #3b82f6); background: none; border: none; cursor: pointer; padding: 0;
}
.nbm-add-link:hover { text-decoration: underline; }

.nbm-input {
  width: 100%; padding: 9px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 9px;
  font-size: 13.5px; color: var(--text, #0f172a); background: var(--surface, #ffffff); box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.nbm-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.nbm-input-wrap { position: relative; }
.nbm-input-with-icon { padding-left: 36px; }
.nbm-field-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text-light, #94a3b8); pointer-events: none; }
.nbm-textarea {
  width: 100%; padding: 9px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 9px;
  font-size: 13.5px; color: var(--text, #0f172a); background: var(--surface, #ffffff); box-sizing: border-box;
  resize: vertical; font-family: inherit; min-height: 72px;
  transition: border-color .15s, box-shadow .15s;
}
.nbm-textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.nbm-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

.nbm-select-wrap { position: relative; }
.nbm-select {
  width: 100%; padding: 9px 36px 9px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 9px;
  font-size: 13.5px; color: var(--text, #0f172a); background: var(--surface, #ffffff); appearance: none; cursor: pointer;
  transition: border-color .15s, box-shadow .15s; box-sizing: border-box;
}
.nbm-select-with-icon { padding-left: 36px; }
.nbm-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
.nbm-select:disabled { opacity: .5; cursor: not-allowed; }
.nbm-select-arrow { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: var(--text-light, #94a3b8); pointer-events: none; }

/* Stepper */
.nbm-stepper { display: flex; align-items: center; gap: 4px; }
.nbm-stepper-disabled { opacity: .4; pointer-events: none; }
.nbm-step-btn {
  width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface-hover, #f8fafc); display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: var(--text-secondary, #374151); transition: all .15s;
}
.nbm-step-btn:hover:not(:disabled) { background: var(--border, #e2e8f0); border-color: var(--border-strong, #cbd5e1); }
.nbm-step-btn:disabled { opacity: .4; cursor: not-allowed; }
.nbm-step-val { min-width: 28px; text-align: center; font-size: 14px; font-weight: 700; color: var(--text, #0f172a); }
.nbm-step-hint { font-size: 11px; color: var(--text-light, #94a3b8); margin-left: 4px; }

/* Add customer */
.nbm-add-customer {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  padding: 14px; display: flex; flex-direction: column; gap: 8px;
}
.nbm-btn-save-customer {
  display: flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px;
  background: #0f172a; color: #fff; font-size: 13px; font-weight: 600; border: none;
  cursor: pointer; align-self: flex-start;
}
.nbm-btn-save-customer:disabled { opacity: .6; cursor: not-allowed; }

/* Customer picker */
.nbm-customer-selected {
  display: flex; align-items: center; gap: 10px; padding: 10px 12px;
  border: 1px solid var(--border, #e2e8f0); border-radius: 9px; background: var(--surface-hover, #f8fafc); cursor: pointer;
}
.nbm-customer-avatar {
  width: 34px; height: 34px; border-radius: 50%; background: #0f172a; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; flex-shrink: 0;
}
.nbm-customer-avatar.sm { width: 30px; height: 30px; font-size: 11px; }
.nbm-customer-info { flex: 1; }
.nbm-customer-name { font-size: 13.5px; font-weight: 600; color: var(--text, #0f172a); }
.nbm-customer-sub { font-size: 12px; color: var(--text-muted, #64748b); }
.nbm-customer-clear {
  background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); padding: 4px;
  display: flex; align-items: center;
}
.nbm-customer-clear:hover { color: var(--danger-ink, #ef4444); }
.nbm-customer-dropdown {
  position: absolute; z-index: 100; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.1);
  max-height: 240px; overflow-y: auto; margin-top: 4px; width: 100%;
}
.nbm-customer-picker { position: relative; }
.nbm-customer-option {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; transition: background .1s;
}
.nbm-customer-option:hover { background: var(--surface-hover, #f8fafc); }

/* Slots */
.nbm-slots-placeholder {
  display: flex; align-items: center; gap: 8px; padding: 14px 16px;
  background: var(--surface-hover, #f8fafc); border: 1px dashed var(--border, #e2e8f0); border-radius: 10px; font-size: 13px; color: var(--text-light, #94a3b8);
}
.nbm-closed-note {
  display: flex; align-items: flex-start; gap: 8px; padding: 12px 14px;
  background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); border-radius: 10px; font-size: 13px; color: var(--danger-ink, #b91c1c); font-weight: 600;
}
.nbm-special-note {
  display: flex; align-items: center; gap: 6px; padding: 7px 11px; margin-bottom: 8px;
  background: var(--info-bg, #eef2ff); border: 1px solid var(--purple-border, #c7d2fe); border-radius: 8px; font-size: 12px; color: var(--purple-ink, #4338ca); font-weight: 600;
}
.nbm-slots-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.nbm-slot {
  display: flex; flex-direction: column; align-items: center; padding: 9px 6px;
  border: 1.5px solid var(--border, #e2e8f0); border-radius: 9px; background: var(--surface, #ffffff); cursor: pointer; transition: all .15s;
}
.nbm-slot:hover:not(:disabled) { border-color: #3b82f6; background: var(--info-bg, #eff6ff); }
.nbm-slot-selected { border-color: #3b82f6 !important; background: var(--info-bg, #eff6ff) !important; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.nbm-slot-taken { background: var(--surface-hover, #f8fafc); border-color: var(--border, #f1f5f9); cursor: not-allowed; opacity: .45; }
.nbm-slot-time  { font-size: 12.5px; font-weight: 700; color: var(--text, #0f172a); }
.nbm-slot-end   { font-size: 11px; color: var(--text-light, #94a3b8); margin-top: 1px; }
.nbm-slot-price { font-size: 11px; font-weight: 600; color: var(--positive-ink, #16a34a); margin-top: 3px; }
.nbm-slot-summary {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px;
  background: var(--info-bg, #eff6ff); border-radius: 8px; font-size: 13px; color: var(--info-ink, #1e40af); margin-top: 4px;
}
.nbm-spin { animation: nbmSpinAnim 1s linear infinite; }
@keyframes nbmSpinAnim { to { transform: rotate(360deg); } }

/* Toggle row */
.nbm-toggle-row {
  display: flex; align-items: center; justify-content: space-between; padding: 10px 14px;
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  cursor: pointer; user-select: none; transition: background .15s;
}
.nbm-toggle-row:hover { background: var(--bg, #f1f5f9); }
.nbm-toggle-row-sm { padding: 8px 12px; }
.nbm-toggle-label { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 600; color: var(--text-secondary, #374151); }
.nbm-toggle-icon { color: var(--text-light, #cbd5e1); transition: color .2s; }
.nbm-toggle-on   { color: var(--info-ink, #3b82f6); }

/* Calendar */
.nbm-calendar-wrap {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px; margin-top: 4px;
}
.nbm-cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.nbm-cal-month { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.nbm-cal-nav {
  width: 28px; height: 28px; border-radius: 7px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff);
  display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted, #64748b); transition: all .15s;
}
.nbm-cal-nav:hover { background: var(--border, #e2e8f0); }
.nbm-cal-days-header { display: grid; grid-template-columns: repeat(7, 1fr); margin-bottom: 4px; }
.nbm-cal-day-name { text-align: center; font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); padding: 2px 0; }
.nbm-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.nbm-cal-cell { aspect-ratio: 1; }
.nbm-cal-empty { width: 100%; height: 100%; }
.nbm-cal-btn {
  width: 100%; height: 100%; border-radius: 7px; border: none; background: transparent; cursor: pointer;
  font-size: 12px; font-weight: 500; color: var(--text-secondary, #374151);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  position: relative; transition: all .15s;
}
.nbm-cal-btn:hover:not(:disabled) { background: var(--border, #e2e8f0); }
.nbm-cal-today       { font-weight: 700; color: var(--info-ink, #3b82f6); }
.nbm-cal-primary     { background: #0f172a !important; color: #fff !important; font-weight: 700; cursor: default; }
.nbm-cal-selected    { background: #3b82f6 !important; color: #fff !important; font-weight: 700; }
.nbm-cal-available   { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #16a34a); font-weight: 600; }
.nbm-cal-available:hover { background: #bbf7d0 !important; }
.nbm-cal-unavailable { background: var(--danger-bg, #fff1f2) !important; color: #fca5a5 !important; cursor: not-allowed !important; }
.nbm-cal-past        { opacity: .35; cursor: not-allowed !important; }
.nbm-cal-x           { position: absolute; font-size: 9px; bottom: 2px; color: #f87171; line-height: 1; }
.nbm-cal-legend {
  display: flex; align-items: center; gap: 10px; margin-top: 10px;
  font-size: 11px; color: var(--text-muted, #64748b); flex-wrap: wrap;
}
.nbm-leg           { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.nbm-leg-primary   { background: #0f172a; }
.nbm-leg-selected  { background: #3b82f6; }
.nbm-leg-unavail   { background: var(--danger-bg, #fff1f2); border: 1px solid var(--danger-border, #fca5a5); }
.nbm-recurring-summary {
  display: flex; align-items: center; gap: 6px; margin-top: 10px; padding: 8px 12px;
  background: var(--info-bg, #eff6ff); border-radius: 8px; font-size: 12px; color: var(--info-ink, #1e40af);
}

/* Add-ons */
.nbm-addons-list { display: flex; flex-direction: column; gap: 8px; }
.nbm-addon-row {
  display: flex; align-items: center; justify-content: space-between; padding: 10px 12px;
  border: 1px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff); gap: 10px;
}
.nbm-addon-oos { opacity: .5; background: var(--surface-hover, #f8fafc); }
.nbm-addon-info { flex: 1; min-width: 0; }
.nbm-addon-name { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text, #0f172a); }
.nbm-addon-icon { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.nbm-addon-desc { font-size: 11.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.nbm-addon-price { font-size: 12px; font-weight: 500; color: var(--positive-ink, #16a34a); margin-top: 3px; }
.nbm-badge-oos {
  font-size: 10px; font-weight: 600; color: var(--danger-ink, #ef4444); background: var(--danger-bg, #fef2f2);
  border-radius: 4px; padding: 1px 6px; border: 1px solid var(--danger-border, #fecaca);
}
.nbm-badge-low {
  font-size: 10px; font-weight: 600; color: var(--warning-ink, #d97706); background: var(--warning-bg, #fffbeb);
  border-radius: 4px; padding: 1px 6px; border: 1px solid var(--warning-border, #fde68a);
}

/* Promo code */
.nbm-promo-wrap { display: flex; flex-direction: column; gap: 6px; margin-top: 2px; }
.nbm-promo-input-row { display: flex; gap: 8px; }
.nbm-promo-input { flex: 1; }
.nbm-btn-apply-promo {
  padding: 9px 16px; border-radius: 9px; border: none; background: #0f172a; color: #fff;
  font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;
  white-space: nowrap; transition: background .15s;
}
.nbm-btn-apply-promo:hover:not(:disabled) { background: #1e293b; }
.nbm-btn-apply-promo:disabled { opacity: .5; cursor: not-allowed; }
.nbm-promo-applied {
  display: flex; align-items: center; justify-content: space-between; padding: 10px 12px;
  background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 9px;
}
.nbm-promo-applied-info { display: flex; align-items: flex-start; gap: 8px; color: var(--positive-ink, #166534); }
.nbm-promo-code { font-size: 13px; font-weight: 700; color: var(--positive-ink, #166534); }
.nbm-promo-savings { font-size: 11.5px; color: var(--positive-ink, #16a34a); margin-top: 1px; }
.nbm-promo-remove {
  background: none; border: none; cursor: pointer; color: #86efac; padding: 2px; display: flex; align-items: center;
}
.nbm-promo-remove:hover { color: var(--danger-ink, #ef4444); }
.nbm-promo-error { font-size: 12px; color: var(--danger-ink, #dc2626); padding: 0 2px; }

/* Price summary */
.nbm-price-summary {
  background: var(--surface-hover, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px;
  display: flex; flex-direction: column; gap: 8px;
}
.nbm-price-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--text-secondary, #475569); }
.nbm-price-discount { color: var(--positive-ink, #16a34a); }
.nbm-price-total {
  font-size: 15px; font-weight: 700; color: var(--text, #0f172a);
  padding-top: 8px; border-top: 1px solid var(--border, #e2e8f0); margin-top: 2px;
}
.nbm-price-recurring {
  font-size: 13px; font-weight: 600; color: var(--info-ink, #3b82f6);
  padding-top: 6px; border-top: 1px dashed var(--info-border, #bfdbfe); margin-top: 2px;
}

/* Error */
.nbm-error {
  display: flex; align-items: center; gap: 8px; padding: 10px 14px;
  background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); border-radius: 9px; font-size: 13px; color: var(--danger-ink, #dc2626);
}

/* Footer */
.nbm-footer {
  display: flex; gap: 10px; padding: 16px 20px; border-top: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.nbm-btn-cancel {
  flex: 1; padding: 10px; border-radius: 9px; border: 1px solid var(--border, #e2e8f0);
  background: var(--surface-hover, #f8fafc); color: var(--text-secondary, #475569); font-size: 14px; font-weight: 500; cursor: pointer; transition: all .15s;
}
.nbm-btn-cancel:hover { background: var(--bg, #f1f5f9); }
.nbm-btn-save {
  flex: 2; display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 10px; border-radius: 9px; border: none; background: #0f172a; color: #fff;
  font-size: 14px; font-weight: 600; cursor: pointer; transition: background .15s;
}
.nbm-btn-save:hover:not(:disabled) { background: #1e293b; }
.nbm-btn-save:disabled { opacity: .6; cursor: not-allowed; }

/* ── Phone (≤600px) — mobile-only adjustments, no desktop impact ──────────── */
@media (max-width: 600px) {
  /* 16px controls prevent iOS auto-zoom on focus */
  .nbm-input, .nbm-select, .nbm-textarea { font-size: 16px; }
  /* Tighter side gutters on narrow phones (360/344px) */
  .nbm-header { padding: 16px 14px 12px; }
  .nbm-body   { padding: 16px 14px; }
  .nbm-footer { padding: 12px 14px; }
  /* 3-col slot grid gets cramped between 344-375px → 2 columns */
  .nbm-slots-grid { grid-template-columns: repeat(2, 1fr); }
  /* New-customer Phone/Email side-by-side → stack */
  .nbm-grid-2 { grid-template-columns: 1fr; }
  /* ≥44px touch targets */
  .nbm-footer .nbm-btn-cancel,
  .nbm-footer .nbm-btn-save { padding: 13px 10px; }
  .nbm-btn-apply-promo   { padding: 12px 16px; }
  .nbm-btn-save-customer { padding: 12px 16px; }
  .nbm-step-btn { width: 38px; height: 38px; }
  .nbm-cal-nav  { width: 34px; height: 34px; }
}
</style>
