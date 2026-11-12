<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { customer, isCustomer, selectedCountry } from '../../lib/customerSession.js'
import { getCustomerToken } from '../../lib/api.js'
import { loadCustomerBookings, cancelCustomerBooking } from '../../customer/apiBooking.js'
import { CalendarCheck, Search, Clock, MapPin, CheckCircle2, XCircle, CircleDashed, X } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'
import { fmt12 } from '../../lib/slots.js'
import PersonalPage from '../components/PersonalPage.vue'
import { bookingBucket, selectPersonalBookings } from '../../lib/personalBookings.js'
useSeo({ title: 'My bookings', noindex: true, path: '/my-bookings' })

const bookings = ref([])
const loading = ref(true)
const loadError = ref('')
const cancelling = ref('')
const view = ref('upcoming')
const search = ref('')
const cur = computed(() => selectedCountry.value?.currency_code || '')
const signedIn = computed(() => isCustomer.value && !!getCustomerToken())

function canCancel(b) {
  return !!b?.canCancel
}

async function cancelBooking(b) {
  if (!b || cancelling.value || !canCancel(b)) return
  if (!window.confirm(`Cancel ${b.listingName} on ${fmtDate(b.booking_date)} at ${fmtTime(b.start_time)}? This can't be undone.`)) return
  const reason = window.prompt('Optional: why are you cancelling?')
  if (reason === null) return
  cancelling.value = b.id
  const res = await cancelCustomerBooking(b.id, (reason || '').trim())
  cancelling.value = ''
  if (res.error) { window.alert(res.error); return }
  load()
}

async function load() {
  if (!signedIn.value) { loading.value = false; bookings.value = []; loadError.value = ''; return }
  loading.value = true
  loadError.value = ''
  const res = await loadCustomerBookings()
  bookings.value = res.bookings || []
  loadError.value = res.error || ''
  view.value = bookings.value.some(b => bookingBucket(b, today) === 'upcoming') ? 'upcoming' : bookings.value.some(b => bookingBucket(b, today) === 'past') ? 'past' : 'cancelled'
  loading.value = false
}
onMounted(load)
// Separate sources so the callback compares by value: replacing customer.value
// with an equivalent object must not trigger a refetch.
watch([() => customer.value?.id, signedIn], load)

function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : '' }
function fmtTime(t) { return fmt12(t) }
const now = new Date()
const today = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`
const groups = computed(() => ['upcoming','past','cancelled'].map(key => ({ key, label: {upcoming:'Upcoming',past:'Past',cancelled:'Cancelled'}[key], count: bookings.value.filter(b => bookingBucket(b,today) === key).length })))
const shown = computed(() => selectPersonalBookings(bookings.value, view.value, search.value, today))
function datePart(d, part) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', part === 'month' ? {month:'short'} : {day:'numeric'}) : '—' }
const payLabel = { paid: 'Paid in full', pending: 'Payment pending', partial: 'Deposit paid', skipped: 'Payment skipped', refunded: 'Refunded', cancelled: 'Cancelled' }
const statusLabel = { confirmed: 'Confirmed', pending: 'Pending confirmation', completed: 'Completed', cancelled: 'Cancelled', canceled: 'Cancelled' }
function statusIcon(b) { return ['cancelled','canceled'].includes(b.booking_status) ? XCircle : b.booking_status === 'pending' ? CircleDashed : CheckCircle2 }
</script>

<template>
  <PersonalPage title="My bookings" description="Your upcoming visits, booking details and past reservations.">
    <template #actions><router-link to="/facilities" class="personal-button primary">Find a venue</router-link></template>
    <div v-if="!signedIn" class="personal-empty"><CalendarCheck :size="32" /><h2>Your bookings belong here</h2><p>Sign in to view and manage your reservations.</p><router-link to="/account" class="personal-button primary">Sign in</router-link></div>
    <div v-else-if="loading" class="personal-empty" role="status">Loading your bookings…</div>
    <div v-else-if="loadError" class="personal-empty" role="alert"><h2>Couldn't load bookings</h2><p>{{ loadError }}</p><button class="personal-button" @click="load">Try again</button></div>
    <template v-else>
      <div class="personal-tools">
        <div class="personal-tabs" aria-label="Booking period"><button v-for="group in groups" :key="group.key" :aria-pressed="view === group.key" @click="view = group.key">{{ group.label }}<span>{{ group.count }}</span></button></div>
        <label class="personal-search"><Search :size="18" /><input v-model="search" aria-label="Search your bookings" placeholder="Search venue, listing or reference" /></label>
      </div>
      <div v-if="!shown.length" class="personal-empty"><CalendarCheck :size="36" /><h2>{{ search ? 'No matching bookings' : !bookings.length ? 'Plan your next visit' : `No ${view} bookings` }}</h2><p>{{ search ? 'Try a different name or booking reference.' : 'Choose a venue and a time that works for you. Your reservations will appear here.' }}</p><button v-if="search" class="personal-button" @click="search = ''">Clear search</button><router-link v-else to="/facilities" class="personal-button primary">Explore venues</router-link></div>
      <div v-else class="booking-list" aria-live="polite">
        <article v-for="b in shown" :key="b.id" class="booking-row">
          <div class="booking-date" aria-hidden="true"><span>{{ datePart(b.booking_date, 'month') }}</span><strong>{{ datePart(b.booking_date, 'day') }}</strong></div>
          <div class="booking-info"><div class="booking-heading"><h2>{{ b.listingName }}</h2><span class="booking-status" :class="b.booking_status"><component :is="statusIcon(b)" :size="14" />{{ statusLabel[b.booking_status] || b.booking_status }}</span></div><p v-if="b.locationName"><MapPin :size="14" /><span>{{ b.locationName }}</span></p><p><Clock :size="14" /><span>{{ fmtDate(b.booking_date) }}{{ b.booking_date ? `, ${b.booking_date.slice(0,4)}` : '' }} · {{ fmtTime(b.start_time) }}<template v-if="b.end_time">–{{ fmtTime(b.end_time) }}</template></span></p><small>Reference <span data-no-translate>{{ b.bookingRef }}</span></small></div>
          <div class="booking-payment"><strong>{{ b.currency || cur }} {{ Number(b.total).toLocaleString() }}</strong><span :class="'payment-' + b.payment_status">{{ payLabel[b.payment_status] || b.payment_status }}</span><small v-if="b.payment_status === 'partial' && !['cancelled', 'canceled', 'refunded'].includes(b.booking_status)"><template v-if="b.due != null">{{ b.currency || cur }} {{ Number(b.due).toLocaleString() }} </template>due on arrival</small><button v-if="view === 'upcoming' && canCancel(b)" :disabled="cancelling === b.id" @click="cancelBooking(b)"><X :size="14" />{{ cancelling === b.id ? 'Cancelling…' : 'Cancel booking' }}</button></div>
        </article>
      </div>
    </template>
  </PersonalPage>
</template>
<style scoped>
.booking-list { border: 1px solid var(--border); border-radius: 14px; overflow: hidden; background: var(--surface); }
.booking-row { display: grid; grid-template-columns: 64px minmax(0, 1fr) 180px; gap: 22px; padding: 24px; align-items: start; }
.booking-row + .booking-row { border-top: 1px solid var(--border); }.booking-date { text-align: center; border-radius: 10px; background: var(--canvas); padding: 10px 4px; color: var(--brand-ink, var(--brand)); }.booking-date span { display: block; font-size: 12px; font-weight: 600; }.booking-date strong { font-size: 27px; line-height: 1.2; }
.booking-heading { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; }.booking-heading h2 { margin: 0; font-size: 16px; line-height: 1.45; font-weight: 700; }.booking-status { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); }.booking-status.confirmed, .booking-status.completed { color: var(--success-ink, #16835a); }.booking-status.pending { color: var(--warning-ink, #986004); }.booking-status.cancelled, .booking-status.canceled { color: var(--danger-ink, #b91c1c); }
.booking-info p { display: flex; gap: 6px; align-items: flex-start; color: var(--text-muted); font-size: 13px; margin: 8px 0; }.booking-info p svg { flex: 0 0 14px; margin-top: 2px; }.booking-info p span { min-width: 0; }.booking-info small { font-size: 12px; color: var(--text-muted); }.booking-info small span { font-weight: 600; }.booking-payment { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; text-align: right; }.booking-payment strong { font-size: 18px; }.booking-payment > span, .booking-payment small { font-size: 12px; color: var(--text-muted); }.booking-payment .payment-paid, .booking-payment .payment-partial { color: var(--success-ink, #16835a); }.booking-payment button { min-height: 44px; display: inline-flex; align-items: center; gap: 5px; border: 0; background: transparent; color: var(--danger-ink, #b91c1c); padding: 8px 0; cursor: pointer; font-size: 13px; }
@media(max-width:760px) { .booking-row { grid-template-columns: 48px minmax(0, 1fr); gap: 12px; padding: 18px 14px; }.booking-date strong { font-size: 23px; }.booking-payment { grid-column: 2; align-items: flex-start; text-align: left; }.booking-heading { gap: 6px; }.booking-heading h2 { font-size: 15px; } }
</style>
