<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Venue;
use App\Models\Setting;
use App\Support\AccessScope;
use App\Support\PremiumPlan;
use Illuminate\Http\Request;

/**
 * Everything behind the "My Website" pages — Site, Home banners, Custom domain,
 * Embed, Blog and Tracking.
 *
 * One surface, two destinations, decided by who is asking (the same shape as
 * StorefrontSortService):
 *   • a provider edits THEIR white-label site  → their businesses row
 *   • a platform admin edits the marketplace   → settings rows
 *
 * The destination is never taken from the request, so a provider cannot write
 * the marketplace's branding by passing a flag.
 */
class SiteSettingsService
{
    /** Fields a PROVIDER may edit — column on businesses. */
    protected const PROVIDER_FIELDS = [
        'brand_enabled', 'brand_subdomain', 'brand_name', 'brand_tagline',
        'brand_logo_url', 'brand_primary', 'brand_accent',
        'brand_meta_title', 'brand_meta_description',
        'brand_show_venues', 'brand_show_events', 'brand_show_programs',
        'brand_languages', 'brand_whitelabel',
        // The domain fields are writable only in the narrow ways guardDomain()
        // allows — connecting one is a paid flow (ProviderPlanService), and
        // brand_domain_paid_at is never taken from the browser at all.
        'brand_custom_domain', 'brand_domain_status',
        'site_pages', 'hero_banners',
        'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
    ];

    /** Fields a PLATFORM ADMIN may edit — key in settings. */
    protected const PLATFORM_FIELDS = [
        'brand_name', 'brand_tagline', 'brand_logo_url', 'brand_primary', 'brand_accent',
        'brand_meta_title', 'brand_meta_description',
        'admin_logo_url', 'admin_primary',
        'hero_banners',
        'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
    ];

    /** Stored as JSON rather than a plain string. */
    protected const JSON_FIELDS = ['site_pages', 'hero_banners', 'brand_languages'];

    protected const BOOL_FIELDS = [
        'brand_enabled', 'brand_whitelabel',
        'brand_show_venues', 'brand_show_events', 'brand_show_programs',
    ];

    public function show(Request $request)
    {
        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        if ($ownerId !== null) {
            $b = Business::forOwnerId($ownerId);

            return sendSuccess('Site settings', [
                'mode'     => 'provider',
                'settings' => $this->providerPayload($b),
                'username' => $b?->username,
                'plan'     => $b?->plan ?: 'free',
                'counts'   => $this->providerCounts($ownerId, $b?->id),
            ]);
        }

        return sendSuccess('Site settings', [
            'mode'     => 'platform',
            'settings' => $this->platformPayload(),
            'username' => null,
            'plan'     => 'premium',
            'counts'   => ['venues' => 0, 'programs' => 0, 'posts' => 0],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        if ($ownerId !== null) {
            return $this->updateProvider($request, $ownerId);
        }

        // Not under any business: only a platform user may write the
        // platform's settings. A provider account that resolves to no business
        // gets a clear refusal rather than the marketplace's own site.
        if (! AccessScope::isPlatform($user)) {
            return sendError('No business found for this account.', [], 404);
        }

        return $this->updatePlatform($request);
    }

    protected function updateProvider(Request $request, int $ownerId)
    {
        $business = Business::forOwnerId($ownerId);
        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        $patch = [];
        foreach (self::PROVIDER_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $patch[$field] = $this->normalize($field, $request->input($field));
        }

        if (! $patch) {
            return sendError('Nothing to save.');
        }

        // Premium is a paywall, not a hint. The dashboard opens an upgrade modal, but
        // the entitlement is decided here — otherwise a free provider could simply
        // POST the field the modal was covering.
        if ($denied = $this->guardPremium($request, $patch, $business)) {
            return $denied;
        }
        if ($denied = $this->guardDomain($patch, $business)) {
            return $denied;
        }

        // A web address is the site's identity, so it must stay unique and may
        // not take one of the reserved subdomains.
        if (array_key_exists('brand_subdomain', $patch)) {
            $slug = BusinessService::normalizeUsername((string) $patch['brand_subdomain']);
            if ($slug === '') {
                unset($patch['brand_subdomain']);
            } else {
                if (BusinessService::isReservedUsername($slug)) {
                    return sendError('That web address is reserved. Please choose another.', [
                        'brand_subdomain' => ['That web address is reserved.'],
                    ], 422);
                }
                $taken = Business::where('id', '!=', $business->id)
                    ->where(function ($q) use ($slug) {
                        $q->where('brand_subdomain', $slug)->orWhere('username', $slug);
                    })->exists();
                if ($taken) {
                    return sendError('That web address is already taken.', [
                        'brand_subdomain' => ['That web address is already taken.'],
                    ], 422);
                }
                $patch['brand_subdomain'] = $slug;
            }
        }

        // Removing the domain retires the whole record — a later reconnect is a new
        // domain and a new fee, so no stale paid_at is left to imply otherwise. The
        // paid purchase stays in business_domain_purchases as the receipt.
        if (array_key_exists('brand_custom_domain', $patch) && ! $patch['brand_custom_domain']) {
            $patch['brand_custom_domain']  = null;
            $patch['brand_domain_status']  = null;
            $patch['brand_domain_paid_at'] = null;
            $business->domainPurchases()->pending()
                ->update(['status' => \App\Models\BusinessDomainPurchase::STATUS_EXPIRED]);
            \App\Http\Middleware\RestrictApiOrigin::forgetCustomDomains();
        }

        $business->update($patch);

        return sendSuccess('Site settings saved', [
            'mode'     => 'provider',
            'settings' => $this->providerPayload($business->fresh()),
            'username' => $business->username,
            'plan'     => $business->plan ?: 'free',
        ]);
    }

    /**
     * The two site fields that cost money: custom home banners and the white-label
     * badge removal. Mirrors the `requirePremium('banners')` / `requirePremium(
     * 'whitelabel')` gates in Banners.vue and Brand.vue.
     *
     * A provider whose plan lapsed keeps the slides they already published and may
     * delete them; they may not add or rewrite one. Comparing counts alone is not
     * enough — replacing one slide with different content keeps the count identical
     * and would hand back the whole feature.
     */
    protected function guardPremium(Request $request, array $patch, Business $business)
    {
        if (PremiumPlan::entitled($request->user())) {
            return null;
        }

        if (array_key_exists('hero_banners', $patch)) {
            $current = is_array($business->hero_banners) ? $business->hero_banners : [];
            if (! $this->isRemovalOnly($patch['hero_banners'], $current)) {
                return PremiumPlan::denied('banners');
            }
        }

        if (! empty($patch['brand_whitelabel']) && ! $business->brand_whitelabel) {
            return PremiumPlan::denied('whitelabel');
        }

        return null;
    }

    /**
     * True when the new slide list only drops slides that are already there — no new
     * slide, no rewritten one, and no reordering. Counting alone would not do it:
     * swapping two slides, or replacing two with one different slide, both keep the
     * count at or below where it was while still handing over the paid feature.
     */
    protected function isRemovalOnly(array $next, array $current): bool
    {
        $remaining = $current;
        $at = 0;
        foreach ($next as $slide) {
            // Slides must still appear in their original order, so scan forward only.
            while ($at < count($remaining) && $remaining[$at] != $slide) {
                $at++;
            }
            if ($at >= count($remaining)) {
                return false;
            }
            $at++;
        }

        return true;
    }

    /**
     * Connecting a custom domain costs a one-time fee, so it may only be written by
     * the Stripe fulfilment path. What is still allowed here is removing it (free)
     * and nothing else — "verify" has its own endpoint that checks real DNS.
     */
    protected function guardDomain(array $patch, Business $business)
    {
        $clearing = array_key_exists('brand_custom_domain', $patch)
            && ($patch['brand_custom_domain'] === null || $patch['brand_custom_domain'] === '');

        if (array_key_exists('brand_custom_domain', $patch) && ! $clearing) {
            return sendError(
                'Connecting a custom domain needs the one-time setup fee. Start the payment from My Website → Custom domain.',
                ['brand_custom_domain' => ['Pay the one-time setup fee to connect this domain.']],
                402
            );
        }

        if (array_key_exists('brand_domain_status', $patch)) {
            $status = (string) ($patch['brand_domain_status'] ?? '');
            if ($status !== '' && ! $clearing) {
                return sendError(
                    'A domain goes live once its DNS record is verified.',
                    ['brand_domain_status' => ['Use “verify” to take this domain live.']],
                    422
                );
            }
        }

        return null;
    }

    protected function updatePlatform(Request $request)
    {
        $saved = 0;
        foreach (self::PLATFORM_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $value = $this->normalize($field, $request->input($field));
            Setting::updateOrCreate(
                ['key' => $field],
                ['value' => in_array($field, self::JSON_FIELDS, true) ? json_encode($value) : (string) $value]
            );
            $saved++;
        }

        if (! $saved) {
            return sendError('Nothing to save.');
        }

        return sendSuccess('Site settings saved', [
            'mode'     => 'platform',
            'settings' => $this->platformPayload(),
            'username' => null,
            'plan'     => 'premium',
        ]);
    }

    protected function normalize(string $field, $value)
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }
        if (in_array($field, self::JSON_FIELDS, true)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            }

            return is_array($value) ? $value : [];
        }

        return $value === '' ? null : $value;
    }

    /**
     * How much content is actually LIVE on the provider's site — what the Site
     * page shows next to each storefront section. A missing
     * show_on_provider_site counts as visible, matching the storefront.
     *
     * @return array{venues: int, programs: int, posts: int}
     */
    protected function providerCounts(int $ownerId, ?int $businessId = null): array
    {
        // A venue reaches its business by the legacy owner key or by
        // venues.business_id — the only link for an ownerless business.
        $venueIds = Venue::query()
            ->where(function ($q) use ($ownerId, $businessId) {
                $q->where('owner_user_id', $ownerId);
                if ($businessId) {
                    $q->orWhere('business_id', $businessId);
                }
            })
            ->pluck('id');

        $venues = $venueIds->isEmpty() ? 0 : Listing::whereIn('venue_id', $venueIds)
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })->count();

        $programs = ClassProgram::query()
            ->where(function ($q) use ($ownerId) {
                $q->where('mapped_owner_user_id', $ownerId)->orWhere('owner_user_id', (string) $ownerId);
            })
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })->count();

        $posts = Post::where('owner_user_id', $ownerId)->where('status', 'published')->count();

        return ['venues' => $venues, 'programs' => $programs, 'posts' => $posts];
    }

    protected function providerPayload(?Business $b): array
    {
        if (! $b) {
            return array_fill_keys(self::PROVIDER_FIELDS, null);
        }

        return [
            'brand_enabled'          => (bool) $b->brand_enabled,
            'brand_subdomain'        => $b->brand_subdomain ?: $b->username,
            'brand_name'             => $b->brand_name ?: $b->name,
            'brand_tagline'          => $b->brand_tagline ?: '',
            'brand_logo_url'         => $b->brand_logo_url ?: '',
            'brand_primary'          => $b->brand_primary ?: '',
            'brand_accent'           => $b->brand_accent ?: '',
            'brand_meta_title'       => $b->brand_meta_title ?: '',
            'brand_meta_description' => $b->brand_meta_description ?: '',
            // NULL means "never set" and reads as ON, so an existing site keeps
            // showing every section until the provider turns one off.
            'brand_show_venues'      => $b->brand_show_venues !== false,
            'brand_show_events'      => $b->brand_show_events !== false,
            'brand_show_programs'    => $b->brand_show_programs !== false,
            'brand_languages'        => is_array($b->brand_languages) ? $b->brand_languages : [],
            'brand_whitelabel'       => (bool) $b->brand_whitelabel,
            'brand_custom_domain'    => $b->brand_custom_domain ?: '',
            'brand_domain_status'    => $b->brand_domain_status ?: '',
            'brand_domain_paid_at'   => optional($b->brand_domain_paid_at)?->toIso8601String(),
            'site_pages'             => is_array($b->site_pages) ? $b->site_pages : [],
            'hero_banners'           => is_array($b->hero_banners) ? $b->hero_banners : [],
            'tracking_meta_pixel'    => $b->tracking_meta_pixel ?: '',
            'tracking_ga4'           => $b->tracking_ga4 ?: '',
            'tracking_gtm'           => $b->tracking_gtm ?: '',
            'tracking_head'          => $b->tracking_head ?: '',
        ];
    }

    protected function platformPayload(): array
    {
        $rows = Setting::whereIn('key', self::PLATFORM_FIELDS)->pluck('value', 'key');

        $out = [];
        foreach (self::PLATFORM_FIELDS as $field) {
            $raw = $rows[$field] ?? null;
            if (in_array($field, self::JSON_FIELDS, true)) {
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                $out[$field] = is_array($decoded) ? $decoded : [];

                continue;
            }
            $out[$field] = $raw ?? '';
        }

        return $out;
    }
}
