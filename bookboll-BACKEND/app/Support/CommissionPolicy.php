<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessSubscription;
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
 *   Merchant's own site  -> its Free / paid Premium rate, city > country >
 *                           global. Automatic defaults retain standard / half.
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

    /** The own-site multiplier for a merchant on a paid plan. */
    public const PAID_PLAN_MULTIPLIER = 0.5;

    /**
     * Whether this business's plan earns the own-site discount.
     *
     * Deliberately NARROWER than BusinessSubscription::ENTITLING, which also
     * covers 'trialing' and 'past_due'. Those grant product features; they do
     * not grant a cheaper commission:
     *
     *   • a legacy free trial has paid nothing, and readmeAlamgir says in as
     *     many words that it does not receive the paid-plan discount;
     *   • past_due means the last payment FAILED.
     *
     * A subscription cancelled at period end keeps the discount while the
     * period it paid for is still running — the merchant bought that time.
     */
    public static function paidPlanEligible(?Business $business): bool
    {
        if (! $business || $business->plan !== 'premium') {
            return false;
        }

        $sub = $business->relationLoaded('currentSubscription')
            ? $business->currentSubscription
            : $business->currentSubscription()->first();

        if (! $sub || $sub->status !== 'active') {
            return false;
        }

        // Still inside a trial window, however the row is labelled: no payment
        // has been taken yet, so there is no paid plan to discount.
        if ($sub->trial_ends_at && $sub->trial_ends_at->isFuture()) {
            return false;
        }

        // Cancelled but not yet ended is still paid-for time.
        if ($sub->current_period_end && $sub->current_period_end->isPast()) {
            return false;
        }

        return true;
    }

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
            ? Business::with('currentSubscription')->where('owner_id', $ownerId)->first()
            : null;
    }

    /**
     * The rate that actually applies for this channel.
     *
     * Only `site` is ever discounted. Marketplace commission is never reduced
     * by Premium, and an unknown channel is treated as marketplace rather than
     * being given the cheaper branch by accident.
     */
    public static function effectiveRatePct(?Listing $listing, string $channel, ?Business $business = null): float
    {
        $standard = self::standardRatePct($listing);

        if ($channel !== self::CHANNEL_SITE) {
            return $standard;
        }

        $business ??= self::businessFor($listing);

        return app(MarketSettings::class)->websiteRate(
            $standard, self::paidPlanEligible($business),
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
        $business ??= self::businessFor($listing);

        $standard  = self::standardRatePct($listing);
        $eligible  = $channel === self::CHANNEL_SITE && self::paidPlanEligible($business);
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
