<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Listing;
use App\Services\BookingWalletService;

/**
 * What Bookboll charges for a booking, and who pays it.
 *
 * The single authoritative answer, because the question is asked from the
 * listing editor, the public catalogue, checkout and the ledger, and those four
 * disagreeing is how a merchant gets quoted one fee and charged another.
 *
 *   Marketplace booking  -> the standard rate, on every plan.
 *   Merchant's own site  -> one website rate on every plan, city > country >
 *                           global. Default is 10%; Premium only unlocks features.
 *
 * Two rules that look like details and are not:
 *
 *   • Connecting payouts never changes the commission. It decides how money
 *     reaches the merchant, not how much Bookboll keeps, and must never select
 *     a zero-commission branch.
 *   • On the merchant's own site the online deposit IS Bookboll's commission,
 *     retained by Bookboll. It is not the provider's no-show protection, and it
 *     is included in the price the customer was quoted rather than added on.
 */
class CommissionPolicy
{
    public const CHANNEL_MARKETPLACE = 'marketplace';
    public const CHANNEL_SITE        = 'site';

    /**
     * The listing's standard rate: its own override, else the country's rate
     * for facilities, else the platform default. Resolved live, so changing a
     * country's rate takes effect without re-saving every listing.
     */
    public static function standardRatePct(?Listing $listing): float
    {
        return (float) app(BookingWalletService::class)->courtCommissionPct($listing);
    }

    /** The business that owns a listing, or null when it cannot be resolved. */
    public static function businessFor(?Listing $listing): ?Business
    {
        if ($businessId = $listing?->venue?->business_id) {
            return Business::with('currentSubscription')->find($businessId);
        }
        $ownerId = $listing?->venue?->owner_user_id;

        return $ownerId
            ? Business::forOwnerId($ownerId)?->load('currentSubscription')
            : null;
    }

    /**
     * The rate that actually applies for this channel.
     *
     * Channel and venue geography select the rate. The business plan never does.
     * An unknown channel is treated as marketplace.
     */
    public static function effectiveRatePct(?Listing $listing, string $channel, ?Business $business = null): float
    {
        $standard = self::standardRatePct($listing);

        if ($channel !== self::CHANNEL_SITE) {
            return $standard;
        }

        return app(MarketSettings::class)->websiteRate(
            app(BookingWalletService::class)->countryIdForVenue($listing?->venue),
            $listing?->venue?->city_id
        );
    }

    /**
     * The currency this listing is actually charged in: the venue's override,
     * else its country's currency.
     *
     * Returns '' when it cannot be resolved, rather than guessing a code nobody
     * was charged. Money treats an unknown code as two-decimal, which is the
     * assumption every amount here was already built on.
     */
    public static function currencyFor(?Listing $listing): string
    {
        $venue = $listing?->venue;
        if (! $venue) {
            return '';
        }

        if (! empty($venue->currency_override)) {
            return (string) $venue->currency_override;
        }

        $countryId = app(BookingWalletService::class)->countryIdForVenue($venue);
        if (! $countryId) {
            return '';
        }

        return (string) (\Nnjeim\World\Models\Country::with('currency')
            ->find($countryId)?->currency?->code ?: '');
    }

    /**
     * The full money breakdown for a booking, in minor units, as a snapshot to
     * store alongside it. Stored rather than recomputed so a later plan change
     * or rate edit cannot rewrite what a confirmed booking charged.
     *
     * @return array{
     *   channel:string, standard_rate_pct:float, effective_rate_pct:float,
     *   plan_eligible:bool, currency:string, total_minor:int,
     *   fee_minor:int, merchant_share_minor:int,
     *   due_now_minor:int, arrival_balance_minor:int, method:string
     * }
     */
    public static function quote(
        ?Listing $listing,
        string $channel,
        int $totalMinor,
        string $currency,
        string $method,
        ?Business $business = null
    ): array {
        $standard  = self::standardRatePct($listing);
        $eligible  = false; // Retained snapshot field: no plan-based fee discount on new bookings.
        $effective = self::effectiveRatePct($listing, $channel, $business);

        $split = Money::split(max(0, $totalMinor), $effective);

        // Deposit: the customer pays Bookboll's fee online now and the rest to
        // the merchant on arrival. Full online: the customer pays everything
        // now and the merchant is credited its share.
        $dueNow = $method === 'deposit' ? $split['fee'] : max(0, $totalMinor);
        $arrival = max(0, $totalMinor) - $dueNow;

        return [
            'channel'               => $channel,
            'standard_rate_pct'     => $standard,
            'effective_rate_pct'    => $effective,
            'plan_eligible'         => $eligible,
            'currency'              => strtoupper($currency),
            'total_minor'           => max(0, $totalMinor),
            'fee_minor'             => $split['fee'],
            'merchant_share_minor'  => $split['remainder'],
            'due_now_minor'         => $dueNow,
            'arrival_balance_minor' => $arrival,
            'method'                => $method,
        ];
    }
}
