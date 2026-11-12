<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\HostedGame;
use App\Models\Listing;
use App\Services\CategoryPopularityService;
use App\Support\CommissionPolicy;
use App\Services\BookingWalletService;
use App\Models\LoyaltyProgram;
use App\Models\Post;
use App\Models\ProviderWallet;
use App\Models\Setting;
use App\Models\Venue;
use App\Support\QatarMarketGeo;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Public marketplace catalog for apps/web (bookboll.com).
 *
 * Separate from ListingService::fetchListings / VenueService::all so the old
 * bookboll-FRONTEND payloads stay unchanged.
 */
class MarketplaceCatalogService
{
    /** Resolved country currency codes, keyed by country id — see venueCurrency(). */
    protected array $countryCurrencies = [];

    /** The platform default, read at most once per request. */
    protected ?string $platformCurrency = null;

    public function listings(Request $request)
    {
        $scope = $this->resolveScope($request);
        if ($scope['empty']) {
            return sendSuccess('Listings fetched successfully', ['listings' => []]);
        }

        $venues = $this->venueQuery($scope)->with(['city:id,name'])->get();
        if ($venues->isEmpty()) {
            return sendSuccess('Listings fetched successfully', ['listings' => []]);
        }

        $venueById = $venues->keyBy('id');
        $listings = $this->listingQuery($scope)
            ->whereIn('venue_id', $venues->pluck('id'))
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->orderBy('name')
            ->get();

        $cards = $listings->map(function (Listing $listing) use ($venueById) {
            $venue = $venueById->get($listing->venue_id);
            if (! $venue) {
                return null;
            }

            return $this->listingCard($listing, $venue);
        })->filter()->values();

        return sendSuccess('Listings fetched successfully', array_merge(
            ['listings' => $cards],
            $this->categoryPopularityPayload($request, $scope)
        ));
    }

    /**
     * Category ranking by what visitors choose, for this exact scope.
     *
     * Returned alongside the listings so Home and the catalogue rank
     * identically — they ask the same question and get the same answer.
     *
     * The tracking flag is advertised only because the endpoint that receives
     * clicks exists. The document is explicit that it must not be set before
     * then, or the storefront posts into nothing.
     */
    protected function categoryPopularityPayload(Request $request, array $scope): array
    {
        $cityId = is_array($scope['cityIds'] ?? null) && count($scope['cityIds']) === 1
            ? (int) $scope['cityIds'][0]
            : null;

        $countryId = null;
        if ($cityId) {
            $countryId = City::where('id', $cityId)->value('country_id');
        } elseif ($iso = strtoupper(trim((string) $request->input('country_iso', '')))) {
            $countryId = Country::where('iso2', $iso)->value('id');
        }

        $brandSlug = $scope['whiteLabel'] ?? false
            ? strtolower(trim((string) $request->input('brand_slug', ''))) ?: null
            : null;

        $result = app(CategoryPopularityService::class)->forScope($cityId, $countryId, $brandSlug);

        return [
            'category_popularity' => $result['popularity'],
            // Which scope produced these, said plainly — a country-level
            // fallback for a new city is reasonable, but only when reported.
            //
            // Echoed in the client's own terms: the city id and brand slug it
            // asked with, and the ISO code it sent. Internal numeric country
            // ids are deliberately NOT echoed — the storefront checks this
            // block against its own request before trusting the counts, and
            // an id it never sent reads as a mismatched scope, which would
            // silently discard every count.
            'category_popularity_scope' => [
                'city_id'     => $result['scope']['city_id'],
                'country_iso' => $result['scope']['country_id']
                    ? Country::where('id', $result['scope']['country_id'])->value('iso2')
                    : null,
                'brand_slug'  => $brandSlug,
                'fallback'    => $result['scope']['fallback'],
                'window_days' => CategoryPopularityService::WINDOW_DAYS,
            ],
            'category_click_tracking_enabled' => true,
        ];
    }

    public function venues(Request $request)
    {
        $scope = $this->resolveScope($request);
        if ($scope['empty']) {
            return sendSuccess('Venues fetched successfully', ['venues' => []]);
        }

        $venues = $this->venueQuery($scope)
            ->with(['city:id,name'])
            ->orderBy('name')
            ->get();

        if ($venues->isEmpty()) {
            return sendSuccess('Venues fetched successfully', ['venues' => []]);
        }

        $listings = $this->listingQuery($scope)
            ->whereIn('venue_id', $venues->pluck('id'))
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            // Ordered so the photo a venue borrows below is the same one on every
            // request, rather than whatever the database happened to return first.
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('venue_id');

        $cards = $venues->map(function (Venue $venue) use ($listings, $scope) {
            $courts = $listings->get($venue->id) ?? collect();
            if (! $scope['whiteLabel'] && $courts->isEmpty()) {
                return null;
            }

            return $this->venueCard($venue, $courts);
        })->filter()->values();

        return sendSuccess('Venues fetched successfully', ['venues' => $cards]);
    }

    /**
     * Every venue a provider operates, with its weekly opening hours and the
     * listings inside it — the white-label "Our locations" page and the
     * "Visit Us" block. Brand-scoped: with no brand_slug there is nothing to show.
     */
    public function brandLocations(Request $request)
    {
        $scope = $this->resolveScope($request);
        if ($scope['empty'] || ! $scope['ownerId']) {
            return sendSuccess('Brand locations fetched successfully', ['locations' => []]);
        }

        $venues = Venue::where('status', Venue::ACTIVE)
            ->where('owner_user_id', $scope['ownerId'])
            ->with(['city:id,name', 'schedules'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($venues->isEmpty()) {
            return sendSuccess('Brand locations fetched successfully', ['locations' => []]);
        }

        $listings = $this->listingQuery($scope)
            ->whereIn('venue_id', $venues->pluck('id'))
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->orderBy('name')
            ->get()
            ->groupBy('venue_id');

        $locations = $venues->map(function (Venue $venue) use ($listings) {
            $courts = $listings->get($venue->id) ?? collect();
            $photos = $this->photos($venue);                        // what a court may borrow
            $cardPhotos = $this->venuePhotos($venue, $courts);      // what the venue shows
            $prices = $courts->map(fn (Listing $l) => $this->startingPrice($l))
                ->filter(fn ($p) => $p !== null && $p > 0);
            $sports = $courts->map(fn (Listing $l) => $this->sportName($l))->filter()->unique()->values();

            return [
                'id'         => $venue->id,
                'name'       => $venue->name,
                'photo'      => $cardPhotos[0] ?? '',
                'address'    => $venue->address ?: '',
                'cityName'   => $venue->city?->name ?: '',
                'mapsUrl'    => $this->mapsUrl($venue),
                'price'      => $prices->isNotEmpty() ? $prices->min() : null,
                'sportsText' => $sports->implode(' · '),
                'listings'   => $courts->map(function (Listing $l) use ($photos) {
                    $courtPhotos = $this->photos($l);

                    return [
                        'id'    => $l->id,
                        'name'  => $l->name,
                        'sport' => $this->sportName($l),
                        'photo' => $courtPhotos[0] ?? ($photos[0] ?? ''),
                        'price' => $this->startingPrice($l),
                    ];
                })->values()->all(),
                'hours'      => $this->weeklyHours($venue),
            ];
        })->values();

        return sendSuccess('Brand locations fetched successfully', ['locations' => $locations]);
    }

    public function venue($id, Request $request)
    {
        $scope = $this->resolveScope($request);

        $venue = Venue::with(['city:id,name', 'country:id,name,iso2'])
            ->where('id', $id)
            ->where('status', Venue::ACTIVE)
            ->first();

        if (! $venue) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        if ($scope['ownerId'] && (int) $venue->owner_user_id !== (int) $scope['ownerId']) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        if (! $scope['whiteLabel'] && $this->ownerSuspended($venue->owner_user_id)) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        $listings = $this->listingQuery($scope)
            ->where('venue_id', $venue->id)
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->orderBy('name')
            ->get();

        // Two different questions. $photos is what a court may BORROW when it has no
        // photo of its own, and must stay the venue's alone — borrowing from the
        // venue's photo pool would show one court a different court's picture.
        // $gallery is what the venue itself shows, which may come from the courts.
        $photos = $this->photos($venue);
        $gallery = $this->venuePhotos($venue, $listings);
        $decorated = $listings->map(function (Listing $listing) use ($venue, $photos) {
            $courtPhotos = $this->photos($listing);
            $start = $this->startingPrice($listing);

            return array_merge($this->listingPayload($listing, $venue), [
                'photo'       => $courtPhotos[0] ?? ($photos[0] ?? ''),
                'startPrice'  => $start,
                'sport_category' => $this->sportName($listing),
            ]);
        })->values();

        return sendSuccess('Venue fetched successfully', [
            'facility' => [
                'location' => $this->locationPayload($venue),
                'photo'    => $gallery[0] ?? '',
                'photos'   => $gallery,
                'mapsUrl'  => $this->mapsUrl($venue),
                'listings' => $decorated,
            ],
        ]);
    }

    public function listing($id, Request $request)
    {
        $scope = $this->resolveScope($request);

        $listing = Listing::with([
                'venue.city:id,name',
                'venue.country:id,name,iso2',
                'sportsCategory:id,name',
                'pricingGroups',
                'assets',
                'addOns',
            ])
            ->where('id', $id)
            ->where('status', 'active')
            ->first();

        if (! $listing || ! $listing->venue || $listing->venue->status !== Venue::ACTIVE) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        $venue = $listing->venue;

        if ($scope['ownerId'] && (int) $venue->owner_user_id !== (int) $scope['ownerId']) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        if (! $this->listingVisible($listing, $scope['whiteLabel'])) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        if (! $scope['whiteLabel'] && $this->ownerSuspended($venue->owner_user_id)) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        $tiers = $listing->pricingGroups
            ->map(fn ($g) => [
                'name'  => $g->name,
                'price' => (float) ($g->price_per_hour ?? 0),
            ])
            ->filter(fn ($t) => $t['price'] > 0)
            ->sortBy('price')
            ->values();

        $addons = $listing->addOns
            ->filter(fn ($a) => ($a->status ?: 'active') !== 'inactive')
            ->map(fn ($a) => [
                'id'              => $a->id,
                'name'            => $a->name,
                'description'     => $a->description,
                'price'           => (float) ($a->price ?? 0),
                'image'           => $a->image,
                'status'          => $a->status,
                'kind'            => 'product',
                'charge_unit'     => 'per_item',
                'hours_extension' => (int) ($a->hours_extension ?? 0),
            ])
            ->values();

        $listingPhotos = $this->photos($listing);
        $venuePhotos = $this->photos($venue);
        $gallery = count($listingPhotos) ? $listingPhotos : $venuePhotos;
        $base = (float) ($listing->base_price ?? 0);
        $tierPrices = $tiers->pluck('price')->all();
        $cands = array_values(array_filter([$base, ...$tierPrices], fn ($p) => $p > 0));
        $startPrice = $cands ? min($cands) : $base;

        $holdMinutes = (int) (Setting::where('key', 'default_bookings_slot_lock_time')->value('value') ?: 10);

        return sendSuccess('Listing fetched successfully', [
            'detail' => [
                'listing'           => $this->listingPayload($listing, $venue),
                'location'          => $this->locationPayload($venue),
                'photo'             => $gallery[0] ?? '',
                'photos'            => $gallery,
                'mapsUrl'           => $this->mapsUrl($venue),
                'pricingTiers'      => $tiers,
                'addons'            => $addons,
                'paymentOptions'    => $this->paymentOptions($listing),
                'providerHasPayout' => $this->providerHasPayout($venue->owner_user_id),
                'startPrice'        => $startPrice,
                'holdMinutes'       => max(1, $holdMinutes),
            ],
        ]);
    }

    /**
     * Loyalty earn/redeem config for a country. A country without a row uses the
     * platform defaults, so this never 404s — the storefront always gets a config.
     */
    /**
     * Everything the build-time sitemap generator needs, in one request.
     *
     * The generator used to read Supabase directly and re-implement the
     * marketplace visibility rules in JavaScript. Two copies of one rule drift:
     * a listing hidden here could still be advertised to crawlers. This returns
     * only what the marketplace actually shows, using the same scope the
     * storefront queries with.
     */
    public function sitemap(): array
    {
        $visible = $this->listingQuery(['whiteLabel' => false]);

        $listings = (clone $visible)->get(['id', 'venue_id', 'category', 'sport_category', 'created_at']);

        $venueIds = $listings->pluck('venue_id')->filter()->unique();
        $venues = Venue::whereIn('id', $venueIds)
            ->where('status', 'active')
            ->get(['id', 'city_id', 'created_at']);

        $programs = ClassProgram::where('status', 'active')
            ->where(fn ($q) => $q->where('show_on_marketplace', true)->orWhereNull('show_on_marketplace'))
            ->where(fn ($q) => $q->where('approval_status', 'approved')->orWhereNull('approval_status'))
            ->where(fn ($q) => $q->whereNull('eligibility_status')->orWhere('eligibility_status', '!=', 'paused'))
            ->with(['venue', 'listingSubscriptions'])->get()->filter(fn ($p) => \App\Support\MembershipListingPolicy::visible($p));

        // Only games a crawler can still act on: open, not removed, not past.
        $today = now()->toDateString();
        $games = HostedGame::where(fn ($q) => $q->where('status', 'open')->orWhereNull('status'))
            ->where(fn ($q) => $q->whereNull('moderation_status')->orWhere('moderation_status', '!=', 'removed'))
            ->where(fn ($q) => $q->whereNull('game_date')->orWhereDate('game_date', '>=', $today))
            ->get(['id', 'game_type', 'game_date', 'created_at']);

        $posts = Post::where('status', 'published')
            ->get(['slug', 'published_at']);

        $cityIds = $venues->pluck('city_id')->filter()->unique();
        $cities = City::whereIn('id', $cityIds)->get(['id', 'name', 'country_id']);
        $countries = Country::whereIn('id', $cities->pluck('country_id')->filter()->unique())
            ->get(['id', 'name']);

        return [
            'venues'    => $venues->map(fn ($v) => [
                'id' => (string) $v->id, 'city_id' => $v->city_id ? (string) $v->city_id : null,
                'created_at' => optional($v->created_at)->toIso8601String(),
            ])->values()->all(),
            'listings'  => $listings->map(fn ($l) => [
                'id' => (string) $l->id, 'location_id' => (string) $l->venue_id,
                'category' => $l->category, 'sport_category' => $l->sport_category,
                'created_at' => optional($l->created_at)->toIso8601String(),
            ])->values()->all(),
            'programs'  => $programs->map(fn ($p) => [
                'id' => (string) $p->id, 'location_id' => (string) $p->venue_id,
                'category' => $p->category, 'sport_category' => $p->sport_category,
                'created_at' => optional($p->created_at)->toIso8601String(),
            ])->values()->all(),
            'games'     => $games->map(fn ($g) => [
                'id' => (string) $g->id, 'game_type' => $g->game_type,
                'created_at' => optional($g->created_at)->toIso8601String(),
            ])->values()->all(),
            'posts'     => $posts->map(fn ($p) => [
                'slug' => $p->slug, 'published_at' => optional($p->published_at)->toIso8601String(),
            ])->values()->all(),
            'cities'    => $cities->map(fn ($c) => [
                'id' => (string) $c->id, 'name' => $c->name,
                'country_id' => $c->country_id ? (string) $c->country_id : null,
            ])->values()->all(),
            'countries' => $countries->map(fn ($c) => [
                'id' => (string) $c->id, 'name' => $c->name,
            ])->values()->all(),
        ];
    }

    public function loyaltyConfig(Request $request)
    {
        $data = $request->validate([
            'country_id' => 'nullable|integer',
        ]);

        $loyalty = LoyaltyProgram::effective($data['country_id'] ?? null)->only(array_keys(LoyaltyProgram::defaults()));
        $loyalty['country_id'] = $data['country_id'] ?? null;

        return sendSuccess('Loyalty config fetched successfully', ['loyalty' => $loyalty]);
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    protected function resolveScope(Request $request): array
    {
        $slug = strtolower(trim((string) $request->input('brand_slug', '')));
        $ownerId = null;
        $whiteLabel = false;

        if ($slug !== '') {
            $whiteLabel = true;
            $business = Business::where(function ($q) use ($slug) {
                    $q->whereRaw('LOWER(username) = ?', [$slug])
                        ->orWhereRaw('LOWER(brand_subdomain) = ?', [$slug]);
                })
                ->first();

            if (! $business) {
                return ['empty' => true, 'whiteLabel' => true, 'ownerId' => null, 'cityIds' => []];
            }

            $ownerId = $business->owner_id;
        }

        $cityIds = $this->resolveCityIds($request);

        return [
            'empty'      => false,
            'whiteLabel' => $whiteLabel,
            'ownerId'    => $ownerId,
            'cityIds'    => $cityIds,
            'countryId'  => $this->resolveCountryId($request),
        ];
    }

    /**
     * The country the visitor is browsing from, as a `countries.id`.
     *
     * The storefront sends this on every catalogue call (`country_iso`, and
     * `country_id` once its picker has resolved one), but nothing used to read
     * it for filtering — only the city did any narrowing. A visitor whose city
     * did not resolve (nothing picked yet, a stale city_id, GPS landing on a
     * town the world table does not carry) therefore got the ENTIRE catalogue:
     * someone browsing from Lahore was shown Qatar venues, priced in PKR
     * because the storefront labels prices with the visitor's own currency.
     *
     * Only a value that resolves to a real row narrows anything. An ISO the
     * world table does not carry is treated as no geography at all — the same
     * answer a crawler gets — rather than as an empty market, because a visitor
     * is better served by the full catalogue than by a blank page.
     */
    protected function resolveCountryId(Request $request): ?int
    {
        $raw = $request->input('country_id');

        if ($raw !== null && $raw !== '' && ctype_digit((string) $raw)) {
            $id = (int) $raw;
            if (Country::where('id', $id)->exists()) {
                return $id;
            }
        }

        $iso = strtoupper(trim((string) $request->input('country_iso', '')));

        if ($iso !== '') {
            $id = Country::where('iso2', $iso)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    protected function resolveCityIds(Request $request): ?array
    {
        $rawId = $request->input('city_id');
        $name = trim((string) $request->input('city', ''));
        $iso = strtoupper(trim((string) $request->input('country_iso', '')));
        $attemptedCity = ($rawId !== null && $rawId !== '') || $name !== '';

        $ids = null;
        if ($rawId !== null && $rawId !== '' && ctype_digit((string) $rawId)) {
            $ids = [(int) $rawId];
        } elseif ($name !== '') {
            $country = $iso !== '' ? Country::where('iso2', $iso)->first() : null;

            $query = City::where('name', $name);
            if ($country) {
                $query->where('country_id', $country->id);
            }

            $ids = $query->pluck('id')->all();
            if (! $ids) {
                $fuzzy = City::where('name', 'like', $name . '%')->limit(20);
                if ($country) {
                    $fuzzy->where('country_id', $country->id);
                }
                $ids = $fuzzy->pluck('id')->all();
            }

            // Last resort: the same name with the country constraint dropped. The
            // caller's `country_iso` is the VISITOR's picker, so "Lahore in Qatar" is
            // a routine mismatch rather than a typo — and resolving it to nothing used
            // to hand the request to the Qatar fallback below, which answered a Lahore
            // page with Qatar venues. The city the caller named wins over the country
            // they happen to be sitting in.
            if (! $ids && $country) {
                $ids = City::where('name', $name)->limit(20)->pluck('id')->all();
            }
        }

        // Qatar is one market, so a city anywhere in QA should show the whole country
        // — including when the attempt resolves to nothing, because GPS or the picker
        // can land on a town the world table does not carry, or carry a stale city_id.
        //
        // ONLY then, though. `country_iso` follows the VISITOR's location picker, not
        // the city being asked for: someone browsing from Doha who opens /venues/lahore
        // sends city_id=<Lahore>&country_iso=QA. This used to fire on the ISO alone,
        // throw the resolved city away and answer with all 23 Qatar venues — the city
        // in the URL was decided by the visitor's own location. A city that DID resolve
        // is handled by expandCityIds() below, which expands only if it is Qatari.
        if ($iso === QatarMarketGeo::ISO && $attemptedCity && ! $this->citiesExist($ids)) {
            $all = QatarMarketGeo::allCityIds();
            if ($all) {
                return $all;
            }
        }

        return QatarMarketGeo::expandCityIds($ids);
    }

    /**
     * Did the request's city_id / city name match a real city row?
     *
     * A city_id is taken on trust above (no lookup), so this is the only thing that
     * separates "the visitor asked for a city we know" from "the id is stale or the
     * name matched nothing".
     *
     * @param  list<int>|null  $ids
     */
    protected function citiesExist(?array $ids): bool
    {
        return $ids ? City::whereIn('id', $ids)->exists() : false;
    }

    protected function venueQuery(array $scope)
    {
        $query = Venue::where('status', Venue::ACTIVE);

        if (is_array($scope['cityIds'])) {
            if ($scope['cityIds'] === []) {
                $query->whereRaw('1 = 0');
            } else {
                QatarMarketGeo::constrainVenues($query, $scope['cityIds']);
            }
        } elseif (! empty($scope['countryId'])) {
            // No city resolved, so the visitor's country is the only thing left
            // to narrow by. Without this the catalogue answered with every
            // country it has — Qatar venues on a Pakistani visitor's page.
            //
            // Deliberately the weaker of the two: a city that DID resolve wins
            // outright and this never runs, because `country_*` follows the
            // visitor's location picker rather than the place they asked for
            // (opening /venues/lahore from Doha sends country_iso=QA).
            $query->where('country_id', $scope['countryId']);
        }

        if ($scope['ownerId']) {
            $query->where('owner_user_id', $scope['ownerId']);
        } elseif (! $scope['whiteLabel']) {
            $suspended = $this->suspendedOwnerIds();
            if ($suspended) {
                $query->where(function ($q) use ($suspended) {
                    $q->whereNull('owner_user_id')
                        ->orWhereNotIn('owner_user_id', $suspended);
                });
            }
        }

        return $query;
    }

    protected function listingQuery(array $scope)
    {
        // Who may BOOK it, before any question of where it is placed. The
        // catalogue feeds the apex marketplace, white-label provider sites
        // (wildcard subdomain or custom domain) and the customer mobile app —
        // all customer surfaces — so the `from:` header resolves this to
        // show_on_customer for every real caller, and for a request with no
        // header at all (crawlers, the sitemap). `status` cannot stand in for
        // it: Listing::booted() derives status as active when EITHER flag is
        // set, so a staff-only listing is still 'active'.
        $query = Listing::where('status', 'active')->visibleToRequestSurface();

        if ($scope['whiteLabel']) {
            $query->where(function ($q) {
                $q->where('show_on_provider_site', true)
                    ->orWhereNull('show_on_provider_site');
            });
        } else {
            $query->where(function ($q) {
                $q->where('show_on_marketplace', true)
                    ->orWhereNull('show_on_marketplace');
            })->where(function ($q) {
                $q->where('approval_status', Listing::APPROVAL_APPROVED)
                    ->orWhereNull('approval_status');
            })->where(function ($q) {
                $q->whereNull('eligibility_status')
                    ->orWhere('eligibility_status', '!=', Listing::ELIGIBILITY_PAUSED);
            });
        }

        return $query;
    }

    protected function listingVisible(Listing $listing, bool $whiteLabel): bool
    {
        // Same gate as listingQuery(), for a listing already loaded.
        if (! $listing->isVisibleToRequestSurface()) {
            return false;
        }

        if ($whiteLabel) {
            return $listing->show_on_provider_site !== false;
        }

        $approved = ($listing->approval_status ?: Listing::APPROVAL_APPROVED) === Listing::APPROVAL_APPROVED;
        $paused = ($listing->eligibility_status ?: Listing::ELIGIBILITY_ELIGIBLE) === Listing::ELIGIBILITY_PAUSED;

        return $listing->show_on_marketplace !== false && $approved && ! $paused;
    }

    protected function suspendedOwnerIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = Business::query()
                ->where('status', Business::STATUS_SUSPENDED)
                ->pluck('owner_id')
                ->filter()
                ->all();
        }

        return $ids;
    }

    protected function ownerSuspended(?int $ownerId): bool
    {
        if (! $ownerId) {
            return false;
        }

        return in_array($ownerId, $this->suspendedOwnerIds(), true);
    }

    // ── Cards / payloads ─────────────────────────────────────────────────────

    /**
     * The currency a card's price is quoted in — the VENUE's, which is the only
     * one that is true of the price next to it.
     *
     * Every card used to ship a bare number and let the storefront label it from
     * the visitor's location picker. That is only ever right by coincidence: the
     * picker says where the visitor is, not what the venue charges in, so a
     * Qatari venue on a Pakistani visitor's screen read as PKR.
     *
     * Same ladder as Booking::currencyForVenue() — override, then the country's,
     * then the platform default — so a card and the booking made from it can
     * never disagree. Memoised per country because a catalogue response spans
     * one or two, not one per venue.
     */
    protected function venueCurrency(Venue $venue): string
    {
        if (! blank($venue->currency_override)) {
            return (string) $venue->currency_override;
        }

        $countryId = $venue->country_id
            ?: ($venue->city_id ? City::whereKey($venue->city_id)->value('country_id') : null);

        if ($countryId) {
            if (! array_key_exists($countryId, $this->countryCurrencies)) {
                $this->countryCurrencies[$countryId] = Country::with('currency')
                    ->find($countryId)?->currency?->code ?: null;
            }

            if (! blank($this->countryCurrencies[$countryId])) {
                return (string) $this->countryCurrencies[$countryId];
            }
        }

        if ($this->platformCurrency === null) {
            $this->platformCurrency = (string) (Setting::where('key', 'platform_default_currency')
                ->value('value') ?: 'QAR');
        }

        return $this->platformCurrency;
    }

    protected function listingCard(Listing $listing, Venue $venue): array
    {
        $photos = $this->photos($listing);
        $venuePhotos = $this->photos($venue);

        return [
            'id'         => $listing->id,
            'locationId' => $listing->venue_id,
            'name'       => $listing->name,
            'photo'      => $photos[0] ?? ($venuePhotos[0] ?? ''),
            'price'      => $this->startingPrice($listing),
            'currency'   => $this->venueCurrency($venue),
            'slotMins'   => $this->slotMinutes($listing),
            'pinText'    => $venue->name ?: '',
            'mapsUrl'    => $this->mapsUrl($venue),
            'lat'        => $venue->latitude !== null ? (float) $venue->latitude : null,
            'lng'        => $venue->longitude !== null ? (float) $venue->longitude : null,
            'category'   => $listing->category ?: 'sports',
            'sport'      => $this->sportName($listing),
            'sortOrder'  => (int) ($listing->sort_order ?? 9999),
            'createdAt'  => optional($listing->created_at)?->toIso8601String(),
        ];
    }

    protected function venueCard(Venue $venue, $courts): array
    {
        $prices = $courts->map(fn (Listing $l) => $this->startingPrice($l))->filter(fn ($p) => $p !== null && $p > 0);
        $sports = $courts->map(fn (Listing $l) => $this->sportName($l))->filter()->unique()->values();
        $categories = $courts->map(fn (Listing $l) => $l->category ?: 'sports')->unique()->values();
        $photos = $this->venuePhotos($venue, $courts);

        return [
            'id'         => $venue->id,
            'name'       => $venue->name,
            'photo'      => $photos[0] ?? '',
            'price'      => $prices->isNotEmpty() ? $prices->min() : null,
            'currency'   => $this->venueCurrency($venue),
            'sportsText' => $sports->implode(' · '),
            'mapsUrl'    => $this->mapsUrl($venue),
            'lat'        => $venue->latitude !== null ? (float) $venue->latitude : null,
            'lng'        => $venue->longitude !== null ? (float) $venue->longitude : null,
            'categories' => $categories->all(),
            'subcats'    => $sports->all(),
            'sortOrder'  => (int) ($venue->sort_order ?? 9999),
            'createdAt'  => optional($venue->created_at)?->toIso8601String(),
        ];
    }

    protected function locationPayload(Venue $venue): array
    {
        $cityName = $venue->city?->name;

        return [
            'id'                 => $venue->id,
            'name'               => $venue->name,
            'address'            => $venue->address,
            'description'        => $venue->description,
            'phone'              => $venue->phone,
            'city_id'            => $venue->city_id,
            'city_name'          => $cityName,
            'city'               => $cityName,
            'country_id'         => $venue->country_id,
            'country_name'       => $venue->country?->name,
            'state_id'           => $venue->state_id,
            'owner_user_id'      => $venue->owner_user_id,
            'latitude'           => $venue->latitude !== null ? (float) $venue->latitude : null,
            'longitude'          => $venue->longitude !== null ? (float) $venue->longitude : null,
            'currency_override'  => $venue->currency_override,
            // The override is nullable, so it alone left the detail pages
            // guessing from the visitor's picker. This is the resolved answer.
            'currency'           => $this->venueCurrency($venue),
            'timezone_override'  => $venue->timezone_override,
            'google_maps_url'    => $venue->maps_url,
            'maps_url'           => $venue->maps_url,
            'photo_url'          => $this->publicUrl($venue->photo_url),
            'photos'             => $this->photos($venue),
            'status'             => $venue->status,
            'sort_order'         => $venue->sort_order,
        ];
    }

    protected function listingPayload(Listing $listing, Venue $venue): array
    {
        $photos = $this->photos($listing);

        return [
            'id'                      => $listing->id,
            'name'                    => $listing->name,
            'venue_id'                => $listing->venue_id,
            'location_id'             => $listing->venue_id,
            'base_price'              => (float) ($listing->base_price ?? 0),
            'slot_duration'           => $this->slotMinutes($listing),
            'buffer_time'             => (int) ($listing->buffer_time ?? 0),
            'capacity'                => (int) ($listing->capacity ?? 1),
            // Whether a slot advertises how many spaces are left. Presentation
            // only: capacity is still enforced and a full slot still stops
            // selling, whatever this says.
            'show_slot_capacity'      => $listing->show_slot_capacity !== false,
            'description'             => $listing->description,
            'category'                => $listing->category ?: 'sports',
            'sport_category'          => $this->sportName($listing),
            'listing_type'            => $listing->listing_type,
            'photos'                  => $photos,
            'photo_url'               => $photos[0] ?? '',
            'amenities'               => $listing->amenities ?? [],
            'tags'                    => $listing->tags ?? [],
            'payment_options'         => $listing->payment_options,
            'online_payment'          => (bool) $listing->online_payment,
            'max_bookings_per_slot'   => (int) ($listing->max_bookings_per_slot ?? 1),
            'max_multiple_bookings'   => $listing->max_multiple_bookings,
            'allow_multiple_bookings' => (bool) $listing->allow_multiple_bookings,
            'extension_pricing'       => $listing->extension_pricing,
            'extension_value'         => $listing->extension_value !== null ? (float) $listing->extension_value : null,
            'extension_max_slots'     => $listing->extension_max_slots !== null ? (int) $listing->extension_max_slots : null,
            'commission_rate'         => $listing->commission_rate !== null ? (float) $listing->commission_rate : null,
            'loyalty_points'          => $listing->loyalty_points,
            'show_on_marketplace'     => $listing->show_on_marketplace,
            'show_on_provider_site'   => $listing->show_on_provider_site,
            'approval_status'         => $listing->approval_status,
            'eligibility_status'      => $listing->eligibility_status,
            'sort_order'              => $listing->sort_order,
            'created_at'              => optional($listing->created_at)?->toIso8601String(),
        ];
    }

    protected function paymentOptions(Listing $listing): array
    {
        $po = is_array($listing->payment_options) ? $listing->payment_options : [];
        $deposit = is_array($po['deposit'] ?? null) ? $po['deposit'] : [];

        return [
            'cash_on_arrival'         => (bool) ($po['cash_on_arrival'] ?? false),
            'deposit_enabled'         => (bool) ($po['deposit_enabled'] ?? ($deposit['enabled'] ?? ! empty($deposit))),
            'deposit_amount'          => (float) ($po['deposit_amount'] ?? ($deposit['amount'] ?? 0)),
            // LIVE, not the stored snapshot. On the marketplace the deposit is
            // Bookboll's platform fee, so it has to be the same number
            // PaymentService::depositToCharge() will bill — otherwise the storefront
            // quotes one figure and Stripe takes another. The stored value is only a
            // record of what the rate was when the listing was last saved.
            'deposit_percentage'      => $this->marketplaceDepositPct($listing, $po, $deposit),
            'online_payment_enabled'  => (bool) ($listing->online_payment || ($po['online_payment_enabled'] ?? false)),
            'site_deposit_enabled'    => (bool) ($po['site_deposit_enabled'] ?? false),
            // LIVE, like the marketplace figure above. On the merchant's own site
            // the deposit IS Bookboll's commission — standard rate, halved for a
            // paid Premium merchant — so it must be the number checkout will
            // actually bill. The stored value is a record of the preview the
            // merchant last saw, never the authority, or a merchant could set
            // their own fee by editing the payload.
            'site_deposit_percentage' => CommissionPolicy::effectiveRatePct($listing, CommissionPolicy::CHANNEL_SITE),
            'site_online_enabled'     => (bool) ($po['site_online_enabled'] ?? false),
            // Retired. Full payment on arrival is no longer an own-site method,
            // so a legacy cash-only listing reports no usable method and the
            // storefront says online booking is unavailable rather than quietly
            // taking a cash booking the policy no longer allows.
            'site_cash_enabled'       => false,
        ];
    }

    /**
     * The marketplace deposit percentage to quote: the listing's live commission.
     *
     * A fixed deposit amount wins over any percentage (booking.js and
     * PaymentService agree on that), and a listing carrying one reports 0% so the
     * storefront shows the amount rather than a misleading percentage.
     *
     * @param  array<string, mixed>  $po
     * @param  array<string, mixed>  $deposit
     */
    protected function marketplaceDepositPct(Listing $listing, array $po, array $deposit): float
    {
        if ((float) ($deposit['amount'] ?? $po['deposit_amount'] ?? 0) > 0) {
            return 0.0;
        }

        return app(BookingWalletService::class)->courtCommissionPct($listing);
    }

    /**
     * Vue marketplace expects minutes. Legacy booking listings stored hours (1–12);
     * the admin dashboard stores minutes (15/30/45/60/90/120).
     */
    protected function slotMinutes(Listing $listing): int
    {
        return $listing->slotDurationMinutes();
    }

    protected function startingPrice(Listing $listing): ?float
    {
        $cands = [(float) ($listing->base_price ?? 0)];
        foreach ($listing->pricingGroups ?? [] as $g) {
            $cands[] = (float) ($g->price_per_hour ?? 0);
        }
        $cands = array_values(array_filter($cands, fn ($p) => $p > 0));

        return $cands ? min($cands) : null;
    }

    protected function sportName(Listing $listing): string
    {
        return (string) ($listing->sport_category ?: $listing->sportsCategory?->name ?: '');
    }

    protected function photos($row): array
    {
        $out = [];
        if (is_array($row->photos ?? null)) {
            $out = array_values(array_filter($row->photos));
        }
        if (! $out && is_array($row->images_urls ?? null)) {
            $out = array_values(array_filter($row->images_urls));
        }
        if (! $out && ! empty($row->photo_url)) {
            $out = [$row->photo_url];
        }
        if (! $out && $row instanceof Listing) {
            $fromAssets = $row->relationLoaded('assets')
                ? $row->assets->map(fn ($a) => $a->url)->filter()->all()
                : [];
            if ($fromAssets) {
                $out = $fromAssets;
            }
        }

        return array_values(array_filter(array_map(fn ($p) => $this->publicUrl($p), $out)));
    }

    /**
     * The photos to show for a VENUE, falling back to the photos of the courts
     * inside it.
     *
     * Most venue rows carry no photos of their own: providers upload against the
     * court or pitch they are listing, not against the address. The venue card then
     * had nothing to show and rendered its monogram instead — on the /facilities
     * page that is the DEFAULT view, so a city of fully photographed courts looked
     * like a wall of initials.
     *
     * This is the same fallback a listing already makes in the other direction
     * (listingCard: own photo, else the venue's), just completing the pair.
     *
     * @param  iterable<Listing>|null  $courts
     * @return array<int, string>
     */
    protected function venuePhotos(Venue $venue, $courts = null): array
    {
        $own = $this->photos($venue);

        if ($own || ! $courts) {
            return $own;
        }

        // Keyed to de-duplicate — several courts in one venue are often photographed
        // together and share a shot — while keeping the courts' own order.
        $borrowed = [];
        foreach ($courts as $court) {
            foreach ($this->photos($court) as $photo) {
                $borrowed[$photo] = true;
            }
        }

        return array_keys($borrowed);
    }

    protected function publicUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_contains($path, '/storage/')) {
            return $path;
        }

        return url('storage/' . ltrim($path, '/'));
    }

    /**
     * Weekly opening hours in the storefront's shape — Monday first, a 12-hour
     * range per day (several periods joined by a comma), "Closed" for a day with
     * none. An empty array when the venue has no hours at all, so the page can
     * say "Hours not set" rather than print seven closed days.
     *
     * `from` / `to` carry the day's widest window in 24-hour form, so a caller can
     * compare and summarise days without parsing the display label.
     *
     * @return array<int, array{day: string, open: bool, label: string, from: string, to: string}>
     */
    protected function weeklyHours(Venue $venue): array
    {
        // Venue hours are `schedules` rows with no listing_id — the same rows the
        // booking engine uses, so the storefront can never advertise hours the
        // venue cannot actually be booked in.
        $rows = $venue->relationLoaded('schedules')
            ? $venue->schedules
            : $venue->schedules()->get();

        $byDay = [];
        foreach ($rows as $row) {
            $dow = $row->day_of_week;
            if ($dow === null || ! $row->is_open) {
                continue;
            }
            $open  = $this->hm($row->open_time);
            $close = $this->hm($row->close_time);
            if (! $open || ! $close) {
                continue;
            }
            $byDay[$dow][] = ['open' => $open, 'close' => $close];
        }

        if (! $byDay) {
            return [];
        }

        $labels = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        $out = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            $periods = $this->mergePeriods($byDay[$dow] ?? []);
            $ranges = array_map(
                fn ($p) => $this->fmt12m($p['open']) . ' – ' . $this->fmt12m($p['close']),
                $periods
            );
            $out[] = [
                'day'   => $labels[$dow],
                'open'  => (bool) $periods,
                'label' => $ranges ? implode(', ', $ranges) : 'Closed',
                'from'  => $periods ? $this->hmFromMinutes($periods[0]['open']) : '',
                'to'    => $periods ? $this->hmFromMinutes(end($periods)['close']) : '',
            ];
        }

        return $out;
    }

    /**
     * Collapse a day's rows into the windows a customer actually reads.
     *
     * Hours can be stored one row per bookable slot (09:00–09:30, 09:30–10:00, …),
     * which would otherwise print as a list of half-hours. Touching or overlapping
     * rows merge; a genuine gap (a morning block and an evening block) stays split.
     * Works in minutes so a close of 00:00 reads as end-of-day, not midnight-past.
     *
     * @param  array<int, array{open: string, close: string}>  $periods
     * @return array<int, array{open: int, close: int}>
     */
    protected function mergePeriods(array $periods): array
    {
        $spans = [];
        foreach ($periods as $p) {
            $open = $this->toMinutes($p['open']);
            $close = $this->toMinutes($p['close']);
            if ($close <= $open) {
                $close = 1440;   // closes at/after midnight → end of day
            }
            $spans[] = ['open' => $open, 'close' => $close];
        }
        if (! $spans) {
            return [];
        }

        usort($spans, fn ($a, $b) => $a['open'] <=> $b['open']);

        $merged = [array_shift($spans)];
        foreach ($spans as $span) {
            $last = &$merged[count($merged) - 1];
            if ($span['open'] <= $last['close']) {
                $last['close'] = max($last['close'], $span['close']);
            } else {
                $merged[] = $span;
            }
            unset($last);
        }

        return $merged;
    }

    protected function toMinutes(string $hm): int
    {
        [$h, $m] = array_pad(explode(':', $hm), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    protected function hmFromMinutes(int $mins): string
    {
        $mins = min($mins, 1439);

        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    /** Minutes-since-midnight → "9:00 AM"; 1440 is end-of-day, shown as 12:00 AM. */
    protected function fmt12m(int $mins): string
    {
        return $this->fmt12(sprintf('%02d:%02d', intdiv($mins, 60) % 24, $mins % 60));
    }

    /** "HH:MM" from a time column, cast or raw. */
    protected function hm($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    /** "09:00" → "9:00 AM" — the storefront prints 12-hour times. */
    protected function fmt12(string $hm): string
    {
        [$h, $m] = array_pad(explode(':', $hm), 2, '00');
        $h = (int) $h;
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) {
            $h12 = 12;
        }

        return $h12 . ':' . str_pad((string) (int) $m, 2, '0', STR_PAD_LEFT) . ' ' . $suffix;
    }

    protected function mapsUrl(?Venue $venue): string
    {
        if (! $venue) {
            return '';
        }
        if ($venue->maps_url) {
            return $venue->maps_url;
        }
        if ($venue->latitude !== null && $venue->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query=' . $venue->latitude . ',' . $venue->longitude;
        }
        $q = trim(implode(', ', array_filter([$venue->name, $venue->address])));

        return $q !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($q) : '';
    }

    protected function providerHasPayout(?int $ownerId): bool
    {
        if (! $ownerId) {
            return false;
        }

        $wallets = ProviderWallet::where('owner_user_id', $ownerId)->get();
        if ($wallets->isEmpty()) {
            return false;
        }

        $migrated = $wallets->contains(fn ($w) => $w->purpose !== null);
        $ofPurpose = $migrated
            ? $wallets->filter(fn ($w) => ($w->purpose ?: 'subscription') === 'bookings')
            : $wallets;

        return $ofPurpose->contains(function ($w) {
            return $w->setup_method
                && $w->setup_status === 'active'
                && ! $w->payout_paused;
        });
    }
}
