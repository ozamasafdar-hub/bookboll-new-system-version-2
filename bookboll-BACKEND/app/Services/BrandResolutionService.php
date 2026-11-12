<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Venue;
use Illuminate\Http\Request;

/**
 * White-label brand resolution for the storefront (apps/web).
 *
 * A provider can run a branded copy of the customer storefront on their own
 * subdomain (mybusiness.bookboll.com) showing only their venues, listings,
 * programs and content. The storefront resolves the brand once at boot, before
 * first paint, so this returns everything it needs in a single request:
 * identity + theme, which modules to show, sort defaults, banners, tracking,
 * languages and the About/Contact pages.
 *
 * A brand is only served when it is ENABLED — opt-in. The dashboard's live
 * preview (`preview=1`) is the one exception, so a provider can see their site
 * while still setting it up.
 */
class BrandResolutionService
{
    public const DEFAULT_PRIMARY = '#0f766e';
    public const DEFAULT_ACCENT  = '#14b8a6';

    /**
     * The storefront sections a provider can hide from the Site page. Kept in
     * one list so the public projection and the dashboard agree on the keys —
     * a section missing from here would always read as visible, which is how
     * hidden locations kept showing on the live site.
     */
    public const HOME_SECTIONS = [
        'hero', 'listings', 'programs', 'about', 'blog', 'locations', 'contact',
    ];

    public function resolve(Request $request)
    {
        $slug    = strtolower(trim((string) $request->query('slug', '')));
        $ownerId = trim((string) $request->query('owner_id', ''));
        $preview = filter_var($request->query('preview', false), FILTER_VALIDATE_BOOLEAN);

        $business = $ownerId !== ''
            ? $this->findByOwner($ownerId, $preview)
            : $this->findBySlug($slug, $preview);

        if (! $business) {
            return sendSuccess('Brand resolved', ['brand' => null]);
        }

        return sendSuccess('Brand resolved', ['brand' => $this->mapBrand($business, $slug)]);
    }

    /**
     * Resolve by owner id — dashboard live preview only, so it works for a provider
     * who has not chosen a web address yet.
     */
    protected function findByOwner(string $ownerId, bool $preview): ?Business
    {
        if (! $preview || ! ctype_digit($ownerId)) {
            return null;
        }

        return Business::forOwnerId((int) $ownerId);
    }

    /**
     * Match an enabled brand_subdomain. Falls back to the business username so a
     * provider's storefront is reachable before they pick a subdomain; a disabled
     * brand resolves only under preview.
     */
    protected function findBySlug(string $slug, bool $preview): ?Business
    {
        if ($slug === '') {
            return null;
        }

        $candidates = Business::query()
            ->where(function ($q) use ($slug) {
                $q->whereRaw('LOWER(brand_subdomain) = ?', [$slug])
                    ->orWhereRaw('LOWER(username) = ?', [$slug]);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $bySubdomain = $candidates->first(
            fn (Business $b) => strtolower((string) $b->brand_subdomain) === $slug && $b->brand_enabled
        );
        if ($bySubdomain) {
            return $bySubdomain;
        }

        $byUsername = $candidates->first(
            fn (Business $b) => strtolower((string) $b->username) === $slug && $b->brand_enabled
        );
        if ($byUsername) {
            return $byUsername;
        }

        if (! $preview) {
            return null;   // brand exists but isn't enabled — opt-in respected
        }

        return $candidates->first(fn (Business $b) => strtolower((string) $b->brand_subdomain) === $slug)
            ?: $candidates->first();
    }

    protected function mapBrand(Business $b, string $requestedSlug): array
    {
        $ownerId = (int) ($b->owner_id ?: $b->activeMemberships()->whereIn('role', \App\Models\BusinessMembership::MANAGING_ROLES)->value('user_id'));

        return [
            'ownerUserId'     => (string) $ownerId,
            'businessId'      => (string) $b->id,
            'slug'            => $this->providerSlug($b) ?: $requestedSlug,
            'name'            => $b->brand_name ?: ($b->name ?: ($b->username ?: 'Bookboll')),
            'tagline'         => (string) ($b->brand_tagline ?: ''),
            'logo'            => (string) ($b->brand_logo_url ?: ''),
            'primary'         => $b->brand_primary ?: self::DEFAULT_PRIMARY,
            'accent'          => $b->brand_accent ?: self::DEFAULT_ACCENT,
            'metaTitle'       => (string) ($b->brand_meta_title ?: ''),
            'metaDescription' => (string) ($b->brand_meta_description ?: ''),
            'enabled'         => (bool) $b->brand_enabled,
            'suspended'       => $b->status === Business::STATUS_SUSPENDED,
            'modules'         => $this->modules($b, $ownerId),
            'sort'            => [
                'space' => $b->sort_space ?: 'recommended',
                'venue' => $b->sort_venue ?: 'recommended',
            ],
            'banners'         => $this->banners($b),
            'tracking'        => [
                'metaPixel' => (string) ($b->tracking_meta_pixel ?: ''),
                'ga4'       => (string) ($b->tracking_ga4 ?: ''),
                'gtm'       => (string) ($b->tracking_gtm ?: ''),
                'head'      => (string) ($b->tracking_head ?: ''),
            ],
            'languages'       => $this->languages($b),
            'pages'           => $this->pages($b),
            // The badge only hides while the provider is actually on premium —
            // a lapsed plan brings it back.
            'whitelabelBranding' => (bool) $b->brand_whitelabel && $b->plan === 'premium',
        ];
    }

    /**
     * The effective web-address slug: an explicit subdomain → username → the
     * slugified business name → a stable id fallback, so every provider has one.
     */
    public function providerSlug(Business $b): string
    {
        $explicit = strtolower(trim((string) ($b->brand_subdomain ?: $b->username ?: '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $derived = $this->slugify((string) ($b->brand_name ?: $b->name ?: ''));
        if (strlen($derived) >= 3) {
            return $derived;
        }

        return $b->id ? 'biz-' . $b->id : '';
    }

    public function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(["'", '’'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');

        return rtrim(substr($s, 0, 30), '-');
    }

    /**
     * Which storefront sections the site shows — derived from the content the
     * provider actually has, not manual toggles. Events are a marketplace-only
     * mechanic and are never shown on a white-label site.
     *
     * @return array{venues: bool, events: bool, programs: bool, blog: bool}
     */
    protected function modules(Business $b, int $ownerId): array
    {
        $venueIds = Venue::where(function ($q) use ($b,$ownerId) {
            $q->where('business_id',$b->id);
            if ($ownerId) $q->orWhere(fn($legacy) => $legacy->whereNull('business_id')->where('owner_user_id',$ownerId));
        })->pluck('id');

        $venues = $venueIds->isNotEmpty() && Listing::query()
            ->whereIn('venue_id', $venueIds)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })
            ->exists();

        $programs = ClassProgram::query()
            ->where(function ($q) use ($ownerId) {
                $q->where('mapped_owner_user_id', $ownerId)
                    ->orWhere('owner_user_id', (string) $ownerId);
            })
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('is_draft', false)->orWhereNull('is_draft');
            })
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })
            ->with(['venue', 'listingSubscriptions'])->get()->contains(fn ($p) => \App\Support\MembershipListingPolicy::visible($p, (string) $ownerId));

        // The Blog section appears once the provider has published a post of their own.
        $blog = Post::query()
            ->where('owner_user_id', $ownerId)
            ->where('status', 'published')
            ->exists();

        return [
            'venues'   => $venues,
            'events'   => false,
            'programs' => $programs,
            'blog'     => $blog,
        ];
    }

    /** @return array<int, array<string, string>> */
    protected function banners(Business $b): array
    {
        $raw = $b->hero_banners;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $image = (string) ($row['image'] ?? $row['image_url'] ?? '');
            $title = (string) ($row['title'] ?? '');
            if ($image === '' && $title === '') {
                continue;
            }
            $out[] = [
                'image'    => $image,
                'title'    => $title,
                'subtitle' => (string) ($row['subtitle'] ?? $row['sub'] ?? ''),
                'ctaLabel' => (string) ($row['ctaLabel'] ?? $row['cta_label'] ?? ''),
                'ctaHref'  => (string) ($row['ctaHref'] ?? $row['cta_href'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<int, string> */
    protected function languages(Business $b): array
    {
        $raw = $b->brand_languages;
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($c) => is_string($c) && $c !== ''));
    }

    /**
     * Which homepage sections are switched on, as real booleans for every known
     * key. A section is hidden only when it was explicitly turned off: a missing
     * key, a null or an unreadable value all stay visible, so an existing site
     * never loses content because a setting was never saved. Legacy rows that
     * stored the string "false" are read as off rather than shipped to the
     * storefront as a truthy string.
     *
     * @return array<string, bool>
     */
    public static function homeSections(array $home): array
    {
        $saved = is_array($home['sections'] ?? null) ? $home['sections'] : [];

        $out = [];
        foreach (self::HOME_SECTIONS as $key) {
            $raw = $saved[$key] ?? null;
            // null and '' mean "never set", not "off" — filter_var reads both as
            // false, which would hide a section nobody switched off.
            $flag = ($raw === null || $raw === '')
                ? null
                : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $out[$key] = $flag ?? true;
        }

        return $out;
    }

    /** About / Contact content, always the full shape so the storefront can read it flat. */
    protected function pages(Business $b): array
    {
        $pg = is_array($b->site_pages) ? $b->site_pages : [];
        $hero = fn ($h) => [
            'hero_title' => (string) ($h['hero_title'] ?? ''),
            'hero_sub'   => (string) ($h['hero_sub'] ?? ''),
            'hero_image' => (string) ($h['hero_image'] ?? ''),
        ];

        $about   = is_array($pg['about'] ?? null) ? $pg['about'] : [];
        $contact = is_array($pg['contact'] ?? null) ? $pg['contact'] : [];
        $home    = is_array($pg['home'] ?? null) ? $pg['home'] : [];

        return [
            'home'    => [
                'hero_image' => (string) ($home['hero_image'] ?? ''),
                'sections'   => self::homeSections($home),
            ],
            'about'   => array_merge(['text' => (string) ($about['text'] ?? '')], $hero($about)),
            'contact' => array_merge([
                'phone'     => (string) ($contact['phone'] ?? ''),
                'whatsapp'  => (string) ($contact['whatsapp'] ?? ''),
                'email'     => (string) ($contact['email'] ?? ''),
                'address'   => (string) ($contact['address'] ?? ''),
                'maps_url'  => (string) ($contact['maps_url'] ?? ''),
                'hours'     => (string) ($contact['hours'] ?? ''),
            ], $hero($contact)),
        ];
    }
}
