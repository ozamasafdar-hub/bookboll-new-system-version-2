<!--
  ThankYou — where Stripe sends the customer back after a successful checkout.

  The booking already exists by the time we get here (the payment return handler
  creates it), so this page confirms what was booked and, importantly, ENDS the
  checkout session: paying online means leaving the site, so the slot hold and its
  countdown are still running until we clear them here.
-->
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { selectedCountry, isCustomer, customer } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { isEmbedded } from '../../lib/embed.js'
import AppDownloadCard from '../../components/AppDownloadCard.vue'
import { appPromptContext } from '../../lib/mobileApps.js'
import { loadBookingsByIds } from '../../customer/apiBooking.js'
import { apiFetch } from '../../lib/api.js'
import { trackBookingConversion } from '../../lib/bookingConversions.js'
import { endCheckoutSession } from '../../customer/bookingSession.js'
import { fmt12 } from '../../lib/slots.js'
import { useSeo } from '../../lib/seo.js'
import { CheckCircle2, Calendar, Clock, MapPin, Building2, CalendarCheck, Home } from 'lucide-vue-next'

useSeo({ title: 'Booking status', description: 'Check your booking and payment status.', path: '/thank-you' })

const route = useRoute()
const router = useRouter()
const bookings = ref([])
const loading = ref(true)
const verifiedPayment = ref(false)
const confirmed = computed(() => bookings.value.length > 0 && bookings.value.every(b => ['confirmed', 'completed'].includes(b.booking_status)))
// A checkout return URL alone is not proof of a successful booking.
const appContext = computed(() => !loading.value && bookings.value.some(b => ['confirmed', 'completed'].includes(b.booking_status))
  ? appPromptContext({ audience: 'customer', signedIn: isCustomer.value, completed: true, whiteLabel: isWhiteLabel.value, embedded: isEmbedded() }) : null)

const cur = computed(() => bookings.value.find(b => b.currency)?.currency
  || selectedCountry.value?.currency_code
  || '')
const total = computed(() => bookings.value.reduce((n, b) => n + (Number(b.total) || 0), 0))
const due = computed(() => bookings.value.reduce((n, b) => n + (Number(b.due) || 0), 0))

function fmtDate(d) {
  return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : ''
}
function fmtTime(t) { return fmt12(t) }
function label(s) { return String(s || '').replace(/_/g, ' ') }

onMounted(async () => {
  // Paying online takes the customer off-site, so the hold it left behind is still
  // ticking. Releasing it here is what stops the countdown card.
  await endCheckoutSession()

  try {
    let bookingIds = route.query.booking_ids
    const sessionId = route.query.checkout_session
    if (typeof sessionId === 'string' && /^cs_[a-zA-Z0-9_]+$/.test(sessionId)) {
      // Poll only while Stripe settlement is processing. Never infer payment from the URL.
      for (let attempt = 0; attempt < 4; attempt++) {
        const response = await apiFetch(`/customer/payments/checkout-session/${encodeURIComponent(sessionId)}`)
        if (!response.success) break
        const state = response.data
        if (state?.status === 'completed') {
          bookingIds = state.booking_ids
          verifiedPayment.value = !!state.conversion
          await trackBookingConversion('Purchase', state.conversion)
          if (state.booking_conversion) await trackBookingConversion('Schedule', state.booking_conversion)
          break
        }
        if (state?.status !== 'processing') break
        if (attempt < 3) await new Promise(resolve => setTimeout(resolve, 1500))
      }
    }
    bookings.value = await loadBookingsByIds(bookingIds)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="ty">
    <div v-if="loading" class="ty-loading">Confirming your booking…</div>

    <template v-else>
      <div class="ty-head">
        <div class="ty-tick"><CheckCircle2 :size="30" /></div>
        <h1>{{ confirmed ? "Thank you — you're booked!" : 'Check your booking' }}</h1>
        <p v-if="confirmed">
          {{ bookings.length === 1 ? 'Your booking is' : 'Your bookings are' }} confirmed.
          We've emailed you the details.
        </p>
        <p v-else-if="verifiedPayment">
          Your payment went through. If the booking doesn't appear below, check My Bookings in a moment.
        </p>
        <p v-else>We couldn't confirm your booking here. Please check My Bookings before making another payment.</p>
      </div>

      <div v-if="bookings.length" class="ty-list">
        <div v-for="b in bookings" :key="b.id" class="ty-card">
          <div class="ty-card-top">
            <div>
              <div class="ty-name">{{ b.listingName }}</div>
              <div v-if="b.locationName" class="ty-meta"><MapPin :size="12" /> {{ b.locationName }}</div>
              <div class="ty-meta">
                <Calendar :size="12" /> {{ fmtDate(b.booking_date) }}
                · <Clock :size="12" /> {{ fmtTime(b.start_time) }}–{{ fmtTime(b.end_time) }}
              </div>
            </div>
            <div class="ty-right">
              <div class="ty-total">{{ cur }} {{ Number(b.total).toLocaleString() }}</div>
              <div class="ty-ref">{{ b.bookingRef }}</div>
            </div>
          </div>

          <div class="ty-tags">
            <span class="ty-tag ty-tag-ok">{{ label(b.statusLabel || b.booking_status) }}</span>
            <span class="ty-tag">{{ label(b.payment_status) }}</span>
            <span v-if="b.due > 0" class="ty-tag ty-tag-due">
              {{ cur }} {{ Number(b.due).toLocaleString() }} due at the venue
            </span>
          </div>
        </div>

        <div class="ty-sum">
          <div class="ty-sum-row"><span>Total</span><b>{{ cur }} {{ total.toLocaleString() }}</b></div>
          <div v-if="due > 0" class="ty-sum-row ty-sum-due">
            <span>Pay at the venue</span><b>{{ cur }} {{ due.toLocaleString() }}</b>
          </div>
        </div>
      </div>

      <div class="ty-actions">
        <button type="button" class="ty-btn ty-btn-primary" @click="router.push('/my-bookings')">
          <CalendarCheck :size="15" /> View my bookings
        </button>
        <button type="button" class="ty-btn" @click="router.push('/venues')">
          <Building2 :size="15" /> Book another
        </button>
        <button type="button" class="ty-btn" @click="router.push('/')">
          <Home :size="15" /> Home
        </button>
      </div>
    </template>
    <AppDownloadCard :account-id="customer?.id" audience="customer" :context="appContext" />
  </div>
</template>

<style scoped>
.ty { max-width: 640px; margin: 0 auto; padding: 28px 16px 56px; }
.ty-loading { text-align: center; padding: 60px 0; opacity: .7; }

.ty-head { text-align: center; margin-bottom: 24px; }
.ty-tick {
  width: 60px; height: 60px; margin: 0 auto 14px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--success-bg, #dcfce7); color: var(--success-ink, #16a34a);
}
.ty-head h1 { font-size: 24px; font-weight: 800; margin: 0 0 6px; }
.ty-head p { margin: 0; opacity: .75; font-size: 14px; }

.ty-list { display: flex; flex-direction: column; gap: 12px; }
.ty-card { border: 1px solid var(--line, #e5e7eb); border-radius: 12px; padding: 14px; }
.ty-card-top { display: flex; justify-content: space-between; gap: 12px; }
.ty-name { font-weight: 700; font-size: 15px; }
.ty-meta { display: flex; align-items: center; gap: 5px; font-size: 12.5px; opacity: .7; margin-top: 4px; }
.ty-right { text-align: right; white-space: nowrap; }
.ty-total { font-size: 15px; font-weight: 800; }
.ty-ref { font-size: 11.5px; opacity: .6; margin-top: 2px; font-family: ui-monospace, monospace; }

.ty-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.ty-tag { font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: rgba(127, 127, 127, .12); text-transform: capitalize; }
.ty-tag-ok { background: var(--success-bg, #dcfce7); color: var(--success-ink, #15803d); }
.ty-tag-due { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); text-transform: none; }

.ty-sum { border-top: 1px solid var(--line, #e5e7eb); margin-top: 4px; padding-top: 12px; }
.ty-sum-row { display: flex; justify-content: space-between; font-size: 14px; padding: 3px 0; }
.ty-sum-row b { font-weight: 800; }
.ty-sum-due { opacity: .8; }

.ty-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 26px; }
.ty-btn {
  display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600;
  padding: 9px 14px; border-radius: 9px; cursor: pointer;
  border: 1px solid var(--line, #e5e7eb); background: transparent; color: inherit;
}
.ty-btn-primary { background: var(--brand, #0f766e); border-color: var(--brand, #0f766e); color: #fff; }
</style>
