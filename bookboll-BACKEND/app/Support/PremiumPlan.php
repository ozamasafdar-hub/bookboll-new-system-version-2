<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

/**
 * Bookboll for Business PREMIUM — the plan itself, and who is entitled to what.
 *
 * A direct port of the dashboard's lib/premium.js, with one deliberate difference:
 * there the gate was only a modal, so a free provider could still POST straight to
 * the API and get the feature. Here the gate is authoritative — the UI's job is to
 * explain it, not to enforce it.
 *
 * The plan lives on the business OWNER's row, so every team member of a premium
 * business inherits it. Platform roles are never gated.
 */
class PremiumPlan
{
    /** Pricing (USD). Monthly is the headline; yearly bills once at ~17% off. */
    public const PRICE_MONTHLY      = 59;
    public const YEARLY_PER_MONTH   = 49;
    public const PRICE_YEARLY       = 588;   // 49 x 12
    public const YEARLY_SAVE_PCT    = 17;
    public const TRIAL_DAYS         = ['monthly' => 7, 'yearly' => 14];
    public const CURRENCY           = 'usd';

    /** One-time fee to connect a custom domain. */
    public const DOMAIN_FEE = 119;

    /** Metadata markers that tell our webhook which flow a Stripe session belongs to. */
    public const SOURCE_PLAN   = 'provider_plan';
    public const SOURCE_DOMAIN = 'domain_fee';

    /** Canonical feature list (order = upsell display order), mirroring premium.js. */
    public const FEATURES = [
        ['key' => 'membership_website', 'label' => 'Own-website membership listings', 'desc' => 'Show academy contact listings on your own website. Marketplace exposure is billed separately.'],
        ['key' => 'blog',        'label' => 'Website blog',        'desc' => 'Publish posts on your own site — great for Google.'],
        ['key' => 'marketing',   'label' => 'Marketing emails',    'desc' => 'Email campaigns to your opted-in customers.'],
        ['key' => 'exports',     'label' => 'Data exports',        'desc' => 'Download bookings, customers, payments & more as CSV.'],
        ['key' => 'promocodes',  'label' => 'Promo codes',         'desc' => 'Discount codes with targeting & usage limits.'],
        ['key' => 'banners',     'label' => 'Custom home banners', 'desc' => 'Your own hero slides with buttons & images.'],
        ['key' => 'widgets',     'label' => 'Embed & widgets',     'desc' => 'Booking widgets and buttons for your existing website.'],
        ['key' => 'whitelabel',  'label' => 'White-label website', 'desc' => 'Remove the Bookboll badge from your site — only a small footer credit remains.'],
        ['key' => 'own_site_only', 'label' => 'Off-marketplace listings', 'desc' => 'Hide a venue listing from the Bookboll Marketplace and sell it only on your own website.'],
    ];

    public static function billingIsYearly(?string $billing): bool
    {
        return $billing === 'yearly';
    }

    /** The amount actually billed for a plan, after any trial. */
    public static function billedAmount(?string $billing): int
    {
        return self::billingIsYearly($billing) ? self::PRICE_YEARLY : self::PRICE_MONTHLY;
    }

    public static function trialDays(?string $billing): int
    {
        return self::TRIAL_DAYS[self::billingIsYearly($billing) ? 'yearly' : 'monthly'];
    }

    public static function featureLabel(string $key): string
    {
        foreach (self::FEATURES as $f) {
            if ($f['key'] === $key) {
                return $f['label'];
            }
        }

        return 'This feature';
    }

    /**
     * Is this caller entitled to premium features?
     *
     * Platform roles always are — they administer the marketplace, they do not buy a
     * provider plan.
     *
     * For a provider this reads `businesses.plan`, the cache of "entitled right now"
     * that ProviderPlanService::applyEntitlement() derives from the subscription. It
     * is deliberately a single column read: this runs on every gated request and on
     * every white-label page load, and the full history in business_subscriptions is
     * a query nobody on that path needs to pay for.
     */
    public static function entitled(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (AccessScope::ownerUserId($user) === null) {
            return true;   // platform / global roles are never gated
        }

        return self::businessEntitled(self::businessFor($user));
    }

    public static function businessEntitled(?Business $business): bool
    {
        return $business?->plan === 'premium';
    }

    /** The business whose plan governs this user (their own, or their owner's). */
    public static function businessFor(?User $user): ?Business
    {
        $ownerId = $user ? AccessScope::ownerUserId($user) : null;

        return $ownerId === null ? null : Business::forOwnerId($ownerId);
    }

    /**
     * The refusal a gated endpoint returns. 402 Payment Required is the honest code —
     * the caller is authenticated and permitted, they just have not paid for this.
     */
    public static function denied(string $feature)
    {
        return sendError(
            self::featureLabel($feature).' is part of Bookboll Premium. Upgrade your plan to use it.',
            ['premium_feature' => $feature],
            402
        );
    }
}
