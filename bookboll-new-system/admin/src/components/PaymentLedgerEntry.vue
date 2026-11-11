<script setup>
import { computed, ref } from 'vue'
import { ChevronRight, ReceiptText, RotateCcw } from 'lucide-vue-next'
import { paymentCategories, channelOptions, methodOptions, statusOptions, labelFor, money } from '../lib/paymentReport.js'
import { stripeInvoiceUrl, isStripeOnlinePayment } from '../lib/stripeInvoice.js'

const props = defineProps({ payment:Object, canRefund:Boolean, canCollect:Function })
const emit = defineEmits(['details','booking'])
const p = computed(() => props.payment)
const expanded = ref(false)
const grouped = computed(() => p.value.row_kind === 'online_group')
const category = row => labelFor(paymentCategories,row.category)
const status = row => row.status === 'mixed' ? 'Mixed statuses' : labelFor(statusOptions,row.status)
const hasFee = row => ['provider_booking_fee','owned_platform_fee','provider_membership_fee','event_fee'].includes(row.category)
const reference = row => row.payment_id || '#' + row.id
const sharedReceipt = computed(() => stripeInvoiceUrl(p.value.anchor) || p.value.bookings?.flatMap(b => b.online).map(stripeInvoiceUrl).find(Boolean))
const details = (row, refund = false) => emit('details',row,refund)
</script>

<template>
  <template v-if="grouped">
    <tr class="pay-online-group" :class="{ 'is-expanded':expanded }">
      <td><button class="pay-group-toggle" :aria-expanded="expanded" :aria-controls="p.id" @click="expanded = !expanded"><ChevronRight :size="16" /><span>One online payment</span></button><small>{{ p.payment_date || 'Date not recorded' }}</small><small>{{ p.booking_count }} {{ p.booking_count === 1 ? 'booking' : 'bookings' }} · {{ p.allocation_count }} allocations shown</small></td>
      <td><b>{{ p.customer_name }}</b><small>{{ p.venue_name }}</small></td>
      <td>{{ p.channel === 'mixed' ? 'Multiple channels' : labelFor(channelOptions,p.channel) }}</td>
      <td><b>{{ p.recipient_label }}</b><small>{{ p.partial ? 'Matching allocations only' : p.scoped ? 'Amounts visible to your business' : 'Combined online allocations' }}</small><small>Expand for the booking breakdown</small></td>
      <td>Online payment<small>Shared Stripe payment</small></td>
      <td class="pay-number"><b>{{ money(p.amount,p.currency) }}</b><small>{{ p.partial || p.scoped ? 'Online amount shown' : 'Combined online amount' }}</small></td>
      <td><span class="pay-status" :class="'pay-status-' + p.status">{{ status(p) }}</span></td>
      <td><div class="pay-row-actions"><a v-if="sharedReceipt" class="pay-icon" :href="sharedReceipt" target="_blank" rel="noopener noreferrer" :aria-label="p.status === 'refunded' ? 'Shared Stripe refund receipt' : 'Shared Stripe receipt'" :title="p.status === 'refunded' ? 'Shared Stripe refund receipt' : 'Shared Stripe receipt'"><ReceiptText :size="16" /></a><button v-if="canRefund && p.can_request_refund" class="pay-icon pay-refund-button" aria-label="Review refund of shared online payment" title="Review refund of the full shared payment" @click="details(p.anchor,true)"><RotateCcw :size="15" /></button></div></td>
    </tr>
    <tr v-if="expanded" class="pay-group-expanded"><td colspan="8"><div :id="p.id" class="pay-group-content">
      <p class="pay-group-explanation">{{ p.partial ? 'Only allocations matching your filters are included in the amount above.' : p.scoped ? 'Only records accessible to your business are shown; this may be part of a larger Stripe payment.' : 'These allocations belong to the same Stripe payment. They are portions of the amount above, not additional charges.' }}</p>
      <div class="pay-booking-breakdowns"><section v-for="booking in p.bookings" :key="booking.id" class="pay-booking-breakdown">
        <header><button v-if="booking.online[0].can_view_booking !== false" class="pay-link" @click="emit('booking',booking.online[0])">{{ booking.reference }}</button><b v-else>{{ booking.reference }}</b><small>{{ booking.listing_name || booking.venue_name }}</small></header>
        <div v-for="row in booking.online" :key="row.id" class="pay-group-allocation"><div><b>{{ row.type === 'deposit' ? 'Online deposit' : category(row) }}</b><small>{{ category(row) }} · {{ row.recipient_label }}</small><small v-if="hasFee(row) && row.fee_rate != null">{{ row.fee_rate }}% {{ row.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'owner-confirmed historical rate' : 'recorded rate' }}</small><button class="pay-link" @click="details(row)">{{ reference(row) }}</button></div><div><b>{{ money(row.amount,row.currency) }}</b><span class="pay-status" :class="'pay-status-' + row.status">{{ status(row) }}</span></div></div>
        <div v-if="booking.arrival_balances.length" class="pay-group-arrivals"><p>Separate arrival balance <small>Not included in the online amount. Also listed separately in payment records.</small></p><div v-for="row in booking.arrival_balances" :key="row.id" class="pay-group-allocation"><div><b>{{ row.recipient_label }}</b><small>{{ labelFor(methodOptions,row.method) }}</small><button class="pay-link" @click="details(row)">View balance details</button><button v-if="canCollect(row)" class="pay-link pay-collect" @click="emit('booking',row,true)">Record payment</button></div><div><b>{{ money(row.amount,row.currency) }}</b><span class="pay-status" :class="'pay-status-' + row.status">{{ status(row) }}</span></div></div></div>
      </section></div>
    </div></td></tr>
  </template>
  <tr v-else>
    <td><button class="pay-link" @click="details(p)">{{ reference(p) }}</button><small>{{ p.payment_date || 'Date not recorded' }}</small><small v-if="p.booking_ref">{{ p.booking_ref }}</small></td>
    <td><b>{{ p.customer_name }}</b><small>{{ p.venue_name }}</small><small v-if="p.listing_name">{{ p.listing_name }}</small></td>
    <td>{{ labelFor(channelOptions,p.channel) }}<small v-if="p.created_by_name">Created by {{ p.created_by_name }}</small><small v-if="p.channel === 'admin'">No platform fee</small></td>
    <td><b>{{ category(p) }}</b><small>{{ p.recipient_label }}</small><small v-if="hasFee(p) && p.fee_rate != null">{{ p.fee_rate }}% {{ p.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'owner-confirmed historical rate' : 'recorded rate' }}</small></td>
    <td>{{ labelFor(methodOptions,p.method) }}</td><td class="pay-number"><b>{{ money(p.amount,p.currency) }}</b></td>
    <td><span class="pay-status" :class="'pay-status-' + p.status">{{ status(p) }}</span><button v-if="canCollect(p)" class="pay-link pay-collect" @click="emit('booking',p,true)">Record payment</button></td>
    <td><div class="pay-row-actions"><button class="pay-icon" :aria-label="'Details for payment ' + reference(p)" title="Payment details" @click="details(p)"><ChevronRight :size="18" /></button><template v-if="isStripeOnlinePayment(p)"><a v-if="stripeInvoiceUrl(p)" class="pay-icon" :href="stripeInvoiceUrl(p)" target="_blank" rel="noopener noreferrer" :aria-label="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'" :title="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'"><ReceiptText :size="16" /></a><button v-else class="pay-icon" disabled title="Stripe receipt not available" aria-label="Stripe receipt not available"><ReceiptText :size="16" /></button></template><button v-if="canRefund && p.can_request_refund" class="pay-icon pay-refund-button" aria-label="Refund online payment" title="Refund online payment" @click="details(p,true)"><RotateCcw :size="15" /></button></div><small v-if="p.refund_status && p.refund_status !== 'succeeded'" class="pay-refund-label">{{ ['pending','requires_action'].includes(p.refund_status) ? 'Refund pending' : 'Check refund status' }}</small></td>
  </tr>
</template>
