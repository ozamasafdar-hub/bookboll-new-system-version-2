<?php
namespace App\Support;

use App\Models\Business;
use App\Models\ClassProgram;

/** Advertising entitlement, independent of customer enrolments and provider wallets. */
class MembershipListingPolicy
{
    public const SOURCE = 'membership_listing';
    public static function business(ClassProgram $p): ?Business
    {
        $venue = $p->venue;
        $owner = $p->mapped_owner_user_id ?: $p->owner_user_id;
        return $venue?->business_id ? Business::find($venue->business_id)
            : Business::forOwnerId(ctype_digit((string) $owner) ? (int) $owner : null);
    }
    public static function phone(ClassProgram $p): string { return trim((string) ($p->contact_phone ?: $p->venue?->phone)); }
    public static function phoneReady(ClassProgram $p): bool { return preg_match('/^\+?[0-9 ()-]{7,40}$/', self::phone($p)) === 1 && strlen(preg_replace('/\D/', '', self::phone($p))) >= 7; }
    public static function monthlyPrice(ClassProgram $p): float
    {
        return app(MarketSettings::class)->rate('membership_listing_monthly', $p->venue?->country_id, $p->venue?->city_id);
    }
    public static function websiteEntitled(ClassProgram $p): bool
    {
        $business = self::business($p);
        return PremiumPlan::businessEntitled($business) && \App\Models\BusinessSubscription::where('business_id', $business->id)
            ->where('plan', 'premium')->where('status', 'active')->where('amount', '>', 0)->where('current_period_end', '>', now())->exists();
    }
    public static function marketplaceEntitled(ClassProgram $p): bool
    {
        return $p->listingSubscriptions->contains(fn ($s) => $s->paid_until?->isFuture() && in_array($s->status, ['active', 'past_due', 'canceled'], true));
    }
    public static function visible(ClassProgram $p, ?string $brandOwner = null): bool
    {
        if ($p->status !== 'active' || $p->is_draft || !self::phoneReady($p)) return false;
        if (in_array($p->eligibility_status, ['paused', 'ineligible'], true)) return false;
        if ($brandOwner !== null) {
            if (!in_array($brandOwner, [(string) $p->owner_user_id, (string) $p->mapped_owner_user_id], true)) return false;
            return $p->show_on_provider_site && self::websiteEntitled($p);
        }
        return $p->show_on_marketplace && ($p->approval_status ?: 'approved') === 'approved' && self::marketplaceEntitled($p);
    }
    public static function scope($query, $user)
    {
        $venues = AccessScope::allowedVenueIds($user);
        if ($venues !== null) $query->whereIn('id', AccessScope::scopedProgramIds($user, $venues));
        return $query;
    }
    public static function retired()
    {
        return sendError('Online membership enrolment and customer billing are unavailable. Providers manage offline enrolments in the member register.', ['membership_model' => 'contact_listing_manual_register'], 410);
    }
}
