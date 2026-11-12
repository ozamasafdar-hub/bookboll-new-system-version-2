<?php
namespace App\Support;

use App\Models\Booking;
use App\Models\Venue;

final class BookingRewards
{
    /** Applied only on creation. Historical bookings retain their original promise. */
    public static function snapshot(Booking $booking): void
    {
        $country = LoyaltyCountry::countryForVenue(Venue::find($booking->venue_id));
        $program = $country ? LoyaltyCountry::program($country) : null;
        $eligible = $program && $booking->user_id
            && ($booking->creation_context['channel'] ?? BookingProvenance::originChannel($booking)) === 'marketplace'
            && $booking->record_purpose === BookingPurpose::SALE
            && $booking->origin !== 'customer_game';
        $rate = $eligible ? (float) $program->earn_rate : 0.0;
        $multiplier = 1.0;
        if ($eligible) {
            $earned = LoyaltyCountry::balance($booking->user_id, $country)['lifetime_earned'];
            $tiers = collect(LoyaltyCountry::tiers($program))->sortBy('threshold');
            foreach ($tiers as $tier) {
                if ($earned >= $tier['threshold']) $multiplier = $tier['multiplier'];
            }
        }
        $booking->loyalty_policy = ['version' => 1, 'country_id' => $country,
            'earn_rate' => $rate, 'multiplier' => $multiplier,
            'expiry_months' => $eligible ? (int) $program->expiry_months : 0,
            'eligible' => (bool) $eligible, 'basis' => 'net_booking_value'];
        // total_price is already net of discounts, including redeemed rewards.
        $booking->loyalty_points_earned = (int) floor(round(max(0, (float) $booking->total_price) * $rate * $multiplier, 8));
    }
}
