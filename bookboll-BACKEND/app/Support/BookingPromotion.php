<?php
namespace App\Support;

use App\Models\{Listing,Promocode};

/** The same promo budget is used by checkout preview, booking creation and rewards. */
final class BookingPromotion
{
    public static function platformFunded(?Promocode $promo): bool
    {
        return $promo && !$promo->is_gift_card_promocode && ($promo->recipient !== 'provider');
    }

    public static function fee(Listing $listing, float $gross, string $surface, string $method, int $count = 1): float
    {
        $currency = CommissionPolicy::currencyFor($listing);
        $po = $listing->payment_options ?? [];
        $fixed = (float) ($po['deposit']['amount'] ?? $po['deposit_amount'] ?? 0);
        if ($method === 'deposit_remaining_on_arrival' && $surface !== 'site' && $fixed > 0) {
            return round(min($gross, $fixed * max(1, $count)), 2);
        }
        return Money::toMajor(Money::percentOf(Money::toMinor($gross, $currency), CommissionPolicy::effectiveRatePct($listing, $surface)), $currency);
    }

    public static function discount(Promocode $promo, Listing $listing, float $gross, string $surface, string $method, int $count = 1): float
    {
        if ($promo->comeback_booking_id && $surface !== 'marketplace') return 0;
        $discount = (float) $promo->applyDiscount($gross);
        $budget = self::platformFunded($promo) ? self::fee($listing, $gross, $surface, $method, $count)
            : (self::hasFixedDeposit($listing, $surface, $method) ? max(0, $gross - self::fee($listing, $gross, $surface, $method, $count)) : $gross);
        return round(min($discount, $budget), 2);
    }

    private static function hasFixedDeposit(Listing $listing, string $surface, string $method): bool
    {
        $options = $listing->payment_options ?? [];
        return $method === 'deposit_remaining_on_arrival' && $surface !== 'site'
            && (float) ($options['deposit']['amount'] ?? $options['deposit_amount'] ?? 0) > 0;
    }

    /** Price each slot first so rounding and fixed deposits cannot overspend a fee. */
    public static function breakdown(?Promocode $promo, Listing $listing, array $grossAmounts, string $surface, string $method): array
    {
        $funded = self::platformFunded($promo);
        $fees = array_map(fn ($gross) => self::fee($listing, $gross, $surface, $method), $grossAmounts);
        $weights = $funded ? $fees : $grossAmounts;
        if ($promo && !$funded && self::hasFixedDeposit($listing, $surface, $method)) {
            $weights = array_map(fn ($i) => max(0, $grossAmounts[$i] - $fees[$i]), array_keys($grossAmounts));
        }
        $discount = $promo && (!$promo->comeback_booking_id || $surface === 'marketplace')
            ? min((float) $promo->applyDiscount(array_sum($grossAmounts)), array_sum($weights)) : 0;
        $parts = self::distribute($discount, $weights);
        $platform = $funded ? $parts : array_fill(0, count($parts), 0.0);
        $netFees = array_map(fn ($i) => max(0, self::fee($listing, $grossAmounts[$i] - $parts[$i] + $platform[$i], $surface, $method) - $platform[$i]), array_keys($grossAmounts));
        return ['discount' => round(array_sum($parts), 2), 'parts' => $parts, 'platform_parts' => $platform,
            'platform_discount' => round(array_sum($platform), 2), 'fee_parts' => $netFees, 'fee' => round(array_sum($netFees), 2)];
    }

    /** Largest-remainder allocation: never put an equal discount on unequal slots. */
    public static function distribute(float $amount, array $weights): array
    {
        $cents = array_map(fn ($v) => max(0, (int) round($v * 100)), $weights);
        $total = array_sum($cents); $target = min($total, max(0, (int) round($amount * 100)));
        if (!$total) return array_fill(0, count($weights), 0.0);
        $parts = []; $remainders = [];
        foreach ($cents as $i => $c) { $raw = $target * $c / $total; $parts[$i] = (int) floor($raw); $remainders[$i] = $raw - $parts[$i]; }
        arsort($remainders); $left = $target - array_sum($parts);
        foreach (array_keys($remainders) as $i) { if ($left-- <= 0) break; $parts[$i]++; }
        return array_map(fn ($v) => $v / 100, $parts);
    }
}
