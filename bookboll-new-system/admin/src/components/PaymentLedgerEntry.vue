<script setup>
import { computed, ref } from 'vue'
import { ChevronRight, ReceiptText, RotateCcw } from 'lucide-vue-next'
import { paymentCategories, channelOptions, methodOptions, statusOptions, labelFor, money } from '../lib/paymentReport.js'
import { stripeInvoiceUrl, isStripeOnlinePayment } from '../lib/stripeInvoice.js'
import CheckoutPaymentBreakdown from './CheckoutPaymentBreakdown.vue'

const props = defineProps({ payment:Object, canRefund:Boolean, canCollect:Function })
const emit = defineEmits(['details','booking'])
const p = computed(() => props.payment)
const expanded = ref(false)
const grouped = computed(() => ['online_group','remaining_group'].includes(p.value.row_kind))
const category = row => labelFor(paymentCategories,row.category)
const status = row => row.status === 'partially_paid' ? 'Partly collected' : row.status === 'mixed' ? 'Mixed statuses' : labelFor(statusOptions,row.status)
const hasFee = row => ['provider_booking_fee','owned_platform_fee','provider_membership_fee','event_fee'].includes(row.category)
const reference = row => row.checkout_payment_reference || row.payment_id || '#' + row.id
const sharedReceipt = computed(() => stripeInvoiceUrl(p.value.anchor) || p.value.bookings?.flatMap(b => b.allocations).map(stripeInvoiceUrl).find(Boolean))
const collectGroup = computed(() => p.value.kind === 'remaining' && !p.value.partial && p.value.bookings.flatMap(b => b.allocations).some(r => props.canCollect(r)))
const details = (row, refund = false) => emit('details',row,refund)
</script>

<template>
  <template v-if="grouped">
    <tr class="pay-online-group" :class="{ 'is-expanded':expanded }">
      <td><button class="pay-link pay-checkout-reference" @click="details(p.anchor)">{{ p.payment_id }}</button><small>{{ p.payment_date || 'Date not recorded' }}</small><button class="pay-group-toggle" :aria-expanded="expanded" :aria-controls="p.id" @click="expanded = !expanded"><ChevronRight :size="16" /><span>{{ p.booking_count }} {{ p.booking_count === 1 ? 'booking' : 'bookings' }} · view split</span></button></td>
      <td><b>{{ p.customer_name }}</b><small>{{ p.venue_name }}</small></td>
      <td>{{ p.channel === 'mixed' ? 'Multiple channels' : labelFor(channelOptions,p.channel) }}</td>
      <td><b>{{ p.title }}</b><small>{{ p.recipient_label }}</small><small v-if="p.partial || p.scoped">{{ p.partial ? 'Matching allocations only' : 'Amounts visible to your business' }}</small></td>
      <td>{{ labelFor(methodOptions,p.method) }}<small>{{ p.kind === 'online' ? 'One Stripe payment' : 'One checkout balance' }}</small></td>
      <td class="pay-number"><b>{{ money(p.amount,p.currency) }}</b><small v-if="p.status === 'partially_paid'">{{ money(p.paid_amount,p.currency) }} collected<br>{{ money(p.pending_amount,p.currency) }} pending</small></td>
      <td><span class="pay-status" :class="'pay-status-' + p.status">{{ status(p) }}</span><button v-if="collectGroup" class="pay-link pay-collect" @click="details(p.anchor)">Record payment</button></td>
      <td><div class="pay-row-actions"><button class="pay-icon" :aria-label="'Details for ' + p.title" title="Payment details" @click="details(p.anchor)"><ChevronRight :size="18" /></button><a v-if="sharedReceipt && p.kind === 'online'" class="pay-icon" :href="sharedReceipt" target="_blank" rel="noopener noreferrer" :aria-label="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'" :title="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'"><ReceiptText :size="16" /></a><button v-if="canRefund && p.can_request_refund" class="pay-icon pay-refund-button" aria-label="Review refund of shared online payment" title="Review refund of the full shared payment" @click="details(p.anchor,true)"><RotateCcw :size="15" /></button></div></td>
    </tr>
    <tr v-if="expanded" class="pay-group-expanded"><td colspan="8"><div :id="p.id" class="pay-group-content">
      <p class="pay-group-explanation">{{ p.partial ? 'Only allocations matching your filters are included in the amount above.' : p.scoped ? 'These are the booking allocations visible to your business.' : 'One payment for this checkout. The amounts below are each booking’s share of the total.' }}</p>
      <CheckoutPaymentBreakdown :payment="p" @booking="row => emit('booking',row)" />
    </div></td></tr>
  </template>  <tr v-else>
    <td><button class="pay-link" @click="details(p)">{{ reference(p) }}</button><small>{{ p.payment_date || 'Date not recorded' }}</small><small v-if="p.booking_ref">{{ p.booking_ref }}</small></td>
    <td><b>{{ p.customer_name }}</b><small>{{ p.venue_name }}</small><small v-if="p.listing_name">{{ p.listing_name }}</small></td>
    <td>{{ labelFor(channelOptions,p.channel) }}<small v-if="p.created_by_name">Created by {{ p.created_by_name }}</small><small v-if="p.channel === 'admin'">No platform fee</small></td>
    <td><b>{{ category(p) }}</b><small>{{ p.recipient_label }}</small><small v-if="hasFee(p) && p.fee_rate != null">{{ p.fee_rate }}% {{ p.allocation_basis === 'owner_confirmed_legacy_20pct' ? 'owner-confirmed historical rate' : 'recorded rate' }}</small></td>
    <td>{{ labelFor(methodOptions,p.method) }}</td><td class="pay-number"><b>{{ money(p.amount,p.currency) }}</b></td>
    <td><span class="pay-status" :class="'pay-status-' + p.status">{{ status(p) }}</span><button v-if="canCollect(p)" class="pay-link pay-collect" @click="emit('booking',p,true)">Record payment</button></td>
    <td><div class="pay-row-actions"><button class="pay-icon" :aria-label="'Details for payment ' + reference(p)" title="Payment details" @click="details(p)"><ChevronRight :size="18" /></button><template v-if="isStripeOnlinePayment(p)"><a v-if="stripeInvoiceUrl(p)" class="pay-icon" :href="stripeInvoiceUrl(p)" target="_blank" rel="noopener noreferrer" :aria-label="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'" :title="p.status === 'refunded' ? 'Stripe refund receipt' : 'Stripe receipt'"><ReceiptText :size="16" /></a><button v-else class="pay-icon" disabled title="Stripe receipt not available" aria-label="Stripe receipt not available"><ReceiptText :size="16" /></button></template><button v-if="canRefund && p.can_request_refund" class="pay-icon pay-refund-button" aria-label="Refund online payment" title="Refund online payment" @click="details(p,true)"><RotateCcw :size="15" /></button></div><small v-if="p.refund_status && p.refund_status !== 'succeeded'" class="pay-refund-label">{{ ['pending','requires_action'].includes(p.refund_status) ? 'Refund pending' : 'Check refund status' }}</small></td>
  </tr>
</template>
