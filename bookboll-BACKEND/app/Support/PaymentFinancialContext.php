<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Venue;

/** Capture server-owned facts at booking creation; never rebuild old snapshots on read. */
final class PaymentFinancialContext
{
    public static function forSubscription(\App\Models\ClassSubscription $subscription): array
    {
        $program = $subscription->program;
        if (!$program) return [];
        // A renewal snapshots the contracting business, never a rate looked up
        // for display. The recorded allocation amount is already authoritative.
        $venue = $program->venue;
        $ownerId = $program->mapped_owner_user_id ?: (is_numeric($program->owner_user_id) ? $program->owner_user_id : null);
        $business = $venue?->business_id ? Business::find($venue->business_id)
            : ($ownerId ? Business::where('owner_id', $ownerId)->first() : null);
        return [
            'version' => 1, 'operator' => $venue?->operator_type ?: ($business ? 'provider' : 'unknown'),
            'business_id' => $business?->id, 'business_name' => $business?->name,
            'provider_recipient' => PaymentRecipient::forBusiness($business),
            'channel' => 'unknown', 'fee_rate' => null,
            'country_id' => $subscription->country_id, 'city_id' => $venue?->city_id,
            'venue_id' => $venue?->id, 'program_id' => $program->id,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    public static function forNewBooking(Booking $booking): array
    {
        $venue = Venue::find($booking->venue_id);
        $business = $venue?->business_id ? Business::find($venue->business_id)
            : ($venue?->owner_user_id ? Business::where('owner_id', $venue->owner_user_id)->first() : null);
        $operator = $venue?->operator_type;
        // A business assignment is authoritative even before an owner is assigned.
        if (!$operator && ($business || $venue?->business_id)) $operator = 'provider';
        if (!in_array($operator, ['bookboll', 'provider'], true)) {
            $operator = $venue ? ($venue->owner_user_id ? 'provider' : 'bookboll') : 'unknown';
        }
        return [
            'version' => 1, 'operator' => $operator,
            'business_id' => $business?->id ?? $venue?->business_id,
            'business_name' => $business?->name,
            'provider_recipient' => $operator === 'provider' ? PaymentRecipient::forBusiness($business) : null,
            'channel' => PaymentReportRules::channel($booking->getAttributes()),
            'fee_rate' => PaymentReportRules::channel($booking->getAttributes()) === 'admin' ? 0 : $booking->commission_effective_pct,
            'fee_minor' => $booking->commission_fee_minor,
            'net_fee_minor' => $booking->commission_fee_minor === null ? null : max(0,
                (int) $booking->commission_fee_minor - Money::toMinor((float) ($booking->loyalty_discount ?? 0) + (float) ($booking->platform_promo_discount ?? 0), $booking->commission_currency)),
            'total_minor' => $booking->commission_currency ? Money::toMinor($booking->total_price, $booking->commission_currency) : null,
            'currency' => $booking->commission_currency,
            'payment_method' => $booking->payment_method,
            'merchant_share_minor' => $booking->merchant_share_minor,
            'country_id' => $venue?->country_id, 'city_id' => $venue?->city_id,
            'venue_id' => $booking->venue_id,
            'recorded_at' => now()->toIso8601String(),
        ];
    }
}
