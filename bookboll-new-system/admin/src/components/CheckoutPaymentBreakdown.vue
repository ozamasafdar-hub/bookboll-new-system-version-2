<script setup>
import { paymentCategories, statusOptions, labelFor, money } from '../lib/paymentReport.js'
defineProps({ payment:Object })
defineEmits(['booking'])
</script>

<template>
  <div class="pay-booking-breakdowns">
    <section v-for="booking in payment.bookings" :key="booking.id" class="pay-booking-breakdown">
      <header><button v-if="booking.allocations[0].can_view_booking !== false" class="pay-link" @click="$emit('booking',booking.allocations[0])">{{ booking.reference }}</button><b v-else>{{ booking.reference }}</b><small>{{ booking.listing_name || booking.venue_name }}</small></header>
      <div v-for="row in booking.allocations" :key="row.id" class="pay-group-allocation">
        <div><b>{{ labelFor(paymentCategories,row.category) }}</b><small>{{ row.recipient_label }}</small><small v-if="row.fee_rate != null && ['provider_booking_fee','owned_platform_fee','provider_membership_fee','event_fee'].includes(row.category)">{{ row.fee_rate }}% {{ row.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'owner-confirmed historical rate' : 'recorded rate' }}</small></div>
        <div><b>{{ money(row.amount,row.currency) }}</b><span class="pay-status" :class="'pay-status-' + row.status">{{ labelFor(statusOptions,row.status) }}</span></div>
      </div>
      <details class="pay-detail-note"><summary>Original allocation records</summary><p v-for="row in booking.allocations" :key="row.id">{{ row.payment_id || '#' + row.id }} · {{ money(row.amount,row.currency) }}</p></details>
    </section>
  </div>
</template>
