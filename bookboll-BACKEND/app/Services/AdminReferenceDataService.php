<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommissionRate;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * The reference bundle the dashboard needs to build scope filters and dropdowns:
 * providers, venues, listings, cities and countries.
 *
 * Nine pages (Moderation, Wallet, Approvals, Subscribers, Banners, Home,
 * Dashboard, Listings, Memberships) each queried these four tables directly in
 * the Supabase build. Serving them together keeps every page's idea of "what I
 * may see" identical, and already narrowed: a provider gets only their own
 * business, a country_admin only their countries.
 */
class AdminReferenceDataService
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Unauthenticated.', [], 401);
        }

        $venueIds = AccessScope::allowedVenueIds($user);   // null = unrestricted
        $ownerId  = AccessScope::ownerUserId($user);

        $venueQuery = Venue::query()->select([
            'id', 'name', 'status', 'owner_user_id', 'city_id', 'country_id',
            // The approvals reviewer shows the venue's phone; it was never selected,
            // so that field sat permanently blank in the review modal.
            'address', 'phone', 'currency_override', 'timezone_override', 'sort_order',
            'approval_status', 'eligibility_status',
        ])->orderBy('sort_order')->orderBy('name');

        if ($venueIds !== null) {
            $venueQuery->whereIn('id', $venueIds ?: [0]);
        }
        $venues = $venueQuery->get();

        $listings = Listing::query()
            ->select(['id', 'name', 'venue_id', 'status', 'sport_category', 'category', 'calendar_color', 'approval_status', 'eligibility_status'])
            ->when($venueIds !== null, fn ($q) => $q->whereIn('venue_id', $venueIds ?: [0]))
            ->orderBy('name')
            ->get();

        return sendSuccess('Reference data fetched successfully', [
            'providers' => $this->providers($user, $ownerId),
            'venues'    => $venues,
            'listings'  => $listings,
            'cities'    => $this->cities($user, $venues),
            'countries' => $this->countries($user),
            // Per-country commission, used by Listings and Memberships to show the
            // effective rate on a venue or programme.
            'commission_rates' => $this->countries($user)->flatMap(fn ($c) => collect(['facility', 'program', 'event'])->map(fn ($type) => [
                'country_id' => $c['id'], 'listing_type' => $type,
                'rate_percent' => app(\App\Support\MarketSettings::class)->rate($type, (int) $c['id']),
            ])),
            'scope'     => [
                'owner_user_id'  => $ownerId,
                'is_global'      => AccessScope::isGlobal($user),
                'is_platform'    => AccessScope::isPlatform($user),
                'venue_ids'      => $venueIds,
                'country_ids'    => AccessScope::jurisdictionCountryIds($user),
            ],
        ]);
    }

    /**
     * Business owners. A provider only ever sees their own business; platform
     * roles see the providers inside their jurisdiction.
     */
    protected function providers(User $user, ?int $ownerId)
    {
        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'provider_admin'))
            ->with(['ownedBusiness:id,owner_id,name,username,status,brand_enabled,brand_subdomain'])
            ->select(['id', 'uuid', 'name', 'email', 'phone', 'country_id', 'status', 'business_status', 'owner_user_id', 'created_at']);

        if (AccessScope::isProviderScoped($user)) {
            $query->where('id', $ownerId ?: 0);
        } elseif (! AccessScope::isGlobal($user)) {
            $countryIds = AccessScope::jurisdictionCountryIds($user);
            if (is_array($countryIds)) {
                $ownersInScope = Venue::whereIn('id', AccessScope::allowedVenueIds($user) ?: [0])
                    ->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id')->all();

                $query->where(function ($q) use ($countryIds, $ownersInScope) {
                    $q->whereIn('country_id', $countryIds ?: [0]);
                    if ($ownersInScope) {
                        $q->orWhereIn('id', $ownersInScope);
                    }
                });
            }
        }

        return $query->orderBy('name')->get()->map(function (User $u) {
            $b = $u->ownedBusiness;

            return [
                'id'              => (string) $u->id,
                'uuid'            => $u->uuid,
                'full_name'       => $u->name,
                'email'           => $u->email,
                'phone'           => $u->phone,
                'role'            => 'provider_admin',
                'country_id'      => $u->country_id,
                'status'          => $u->status,
                'business_status' => $u->business_status ?: ($b?->status),
                'business_name'   => $b?->name,
                'username'        => $b?->username,
                'brand_enabled'   => (bool) ($b?->brand_enabled),
                'brand_subdomain' => $b?->brand_subdomain,
                // The wallet review shows when the business joined.
                'created_at'      => optional($u->created_at)?->toIso8601String(),
            ];
        })->values();
    }

    /** Cities in the user's jurisdiction, always including those their venues sit in. */
    protected function cities(User $user, $venues)
    {
        $countryIds = AccessScope::jurisdictionCountryIds($user);
        $venueCityIds = $venues->pluck('city_id')->filter()->unique()->all();

        // The module_* flags ride along: Listings and Memberships refuse to create
        // in a city whose module is off, and they read them off this payload.
        $query = City::query()->select([
            'id', 'name', 'country_id',
            'module_listings', 'module_classes', 'module_matchmaking',
        ]);

        if (AccessScope::isProviderScoped($user)) {
            $query->whereIn('id', $venueCityIds ?: [0]);
        } elseif (is_array($countryIds)) {
            $query->where(function ($q) use ($countryIds, $venueCityIds) {
                $q->whereIn('country_id', $countryIds ?: [0]);
                if ($venueCityIds) {
                    $q->orWhereIn('id', $venueCityIds);
                }
            });
        } else {
            // A GLOBAL admin has no jurisdiction, so neither branch above applied and
            // every city in the world was returned — 150,716 rows, ~7.8 MB, on every
            // page that loads reference data. Consumers only ever resolve a venue's
            // city or offer the cities Bookboll actually operates in, so the cities
            // referenced by venues are the whole useful set. A true city picker uses
            // /admin/locations/cities, which takes a country and a search term.
            $query->whereIn('id', $venueCityIds ?: [0]);
        }

        // Cast the module flags to real booleans. MySQL hands back 1/0 and the
        // dashboard tests `city.module_listings === false`; an integer 0 is not
        // `false` in JS, so a disabled module would have read as enabled.
        return $query->orderBy('name')->get()->map(fn ($c) => [
            'id'                 => $c->id,
            'name'               => $c->name,
            'country_id'         => $c->country_id,
            'commission_rates' => array_intersect_key(app(\App\Support\MarketSettings::class)->config($c->country_id, $c)['effective'], array_flip(['facility', 'program', 'event'])),
            'module_listings'    => app(\App\Support\MarketSettings::class)->config($c->country_id, $c)['effective']['module_listings'],
            'module_classes'     => app(\App\Support\MarketSettings::class)->config($c->country_id, $c)['effective']['module_classes'],
            'module_matchmaking' => app(\App\Support\MarketSettings::class)->config($c->country_id, $c)['effective']['module_matchmaking'],
        ])->values();
    }

    /** Countries, narrowed to the jurisdiction for platform roles. */
    protected function countries(User $user)
    {
        $countryIds = AccessScope::jurisdictionCountryIds($user);

        $query = Country::query()->with('currency')->select(['id', 'name', 'iso2', 'status']);

        if (is_array($countryIds)) {
            $query->whereIn('id', $countryIds ?: [0]);
        }

        // `countries.status` is a tinyint (1/0) from the world package, but every other
        // endpoint hands the dashboard the string 'active'/'inactive' — see
        // GeographyService::mapCountry(). Emitting the raw column here made the two
        // shapes disagree, and a consumer testing `status === 'active'` silently
        // discarded every country.
        return $query->orderBy('name')->get()->map(fn (Country $c) => [
            'id'            => $c->id,
            'name'          => $c->name,
            'iso_code'      => $c->iso2,
            'status'        => (int) ($c->status ?? 1) === 1 ? 'active' : 'inactive',
            'currency_code' => $c->currency?->code ?: '',
        ])->values();
    }
}
