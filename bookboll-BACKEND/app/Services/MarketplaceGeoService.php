<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Public marketplace geography for apps/web location picker + SEO.
 * Uses nnjeim/world with admin status toggles; scoped lists avoid dumping all cities.
 */
class MarketplaceGeoService
{
    public function countries(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $slug = strtolower(trim((string) $request->query('slug', '')));

        $query = $this->activeCountriesQuery();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('iso2', 'like', '%' . $search . '%');
            });
        }

        $rows = $query->get();
        // How many cities each country actually has, so the location picker can
        // say "no cities are listed for this country yet" instead of leaving a
        // failed request and a genuinely empty country looking identical. One
        // grouped query, not one per row.
        $cityCounts = $this->cityCountsFor($rows->pluck('id')->all());
        $countries = $rows
            ->map(fn (Country $c) => $this->mapCountry($c, (int) ($cityCounts[$c->id] ?? 0)))
            ->values();

        if ($slug !== '') {
            $match = $countries->first(fn ($c) => $this->slugify($c['name']) === $slug);
            if ($match) {
                return sendSuccess('Country resolved', ['country' => $match, 'countries' => [$match]]);
            }

            return sendSuccess('Country not found', ['country' => null, 'countries' => []]);
        }

        return sendSuccess('Countries fetched successfully', ['countries' => $countries]);
    }

    public function cities(Request $request)
    {
        $countryId = $request->query('country_id');
        $search = trim((string) $request->query('search', ''));
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $limit = min(500, max(1, (int) $request->query('limit', 500)));

        if ($slug !== '') {
            $city = $this->resolveCityBySlug($slug);
            if (! $city) {
                return sendSuccess('City not found', ['city' => null, 'cities' => []]);
            }

            // A slug is resolved globally, so on its own it would happily answer
            // `country_id=<Qatar>&slug=lahore` with Lahore. When the caller has
            // told us the country too, the two have to agree — a city is not a
            // match for a country it is not in.
            if ($countryId !== null && $countryId !== ''
                && (int) ($city['country_id'] ?? 0) !== (int) $countryId) {
                return sendSuccess('City not found', ['city' => null, 'cities' => []]);
            }

            return sendSuccess('City resolved', [
                'city'   => $city,
                'cities' => [[
                    'id'         => $city['id'],
                    'name'       => $city['name'],
                    'country_id' => $city['country_id'],
                ]],
            ]);
        }

        if ($countryId === null || $countryId === '') {
            return sendError('country_id is required', [], 422);
        }

        $query = $this->activeCitiesQuery()
            ->where('country_id', (int) $countryId);

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $cities = $query->limit($limit)->get()->map(fn (City $c) => [
            'id'         => $c->id,
            'name'       => $c->name,
            'country_id' => $c->country_id,
        ])->values();

        return sendSuccess('Cities fetched successfully', ['cities' => $cities]);
    }

    public function city($id)
    {
        $city = $this->activeCitiesQuery()
            ->with(['country.currency'])
            ->where('id', $id)
            ->first();

        if (! $city) {
            return sendSuccess('City fetched successfully', ['city' => null]);
        }

        return sendSuccess('City fetched successfully', [
            'city' => $this->mapCityDetail($city),
        ]);
    }

    public function directory()
    {
        $venueRows = Venue::query()
            ->where('status', Venue::ACTIVE)
            ->whereNotNull('city_id')
            ->select('id', 'city_id', 'country_id')
            ->get();

        if ($venueRows->isEmpty()) {
            return sendSuccess('Directory fetched successfully', ['groups' => []]);
        }

        $venueIds = $venueRows->pluck('id');
        $listings = Listing::query()
            ->where('status', 'active')
            // Customer surface: a staff-only court must not raise a city's
            // sport counts or spawn directory pages for a crawler. `status`
            // alone would let it through — it is derived as active when EITHER
            // visibility flag is set.
            ->visibleToRequestSurface()
            ->whereIn('venue_id', $venueIds)
            ->where(function ($q) {
                $q->where('show_on_marketplace', true)
                    ->orWhereNull('show_on_marketplace');
            })
            ->where(function ($q) {
                $q->where('approval_status', Listing::APPROVAL_APPROVED)
                    ->orWhereNull('approval_status');
            })
            ->where(function ($q) {
                $q->whereNull('eligibility_status')
                    ->orWhere('eligibility_status', '!=', Listing::ELIGIBILITY_PAUSED);
            })
            ->select('venue_id', 'sport_category')
            ->get();

        $venueCity = $venueRows->keyBy('id');
        $cityAgg = [];

        foreach ($listings as $listing) {
            $venue = $venueCity->get($listing->venue_id);
            if (! $venue?->city_id) {
                continue;
            }
            $cid = (int) $venue->city_id;
            if (! isset($cityAgg[$cid])) {
                $cityAgg[$cid] = ['count' => 0, 'programCount' => 0, 'sports' => [], 'memberships' => []];
            }
            $cityAgg[$cid]['count']++;
            $sport = trim((string) ($listing->sport_category ?: ''));
            if ($sport !== '') {
                $sl = $this->slugify($sport);
                if (! isset($cityAgg[$cid]['sports'][$sl])) {
                    $cityAgg[$cid]['sports'][$sl] = ['name' => $sport, 'slug' => $sl, 'count' => 0];
                }
                $cityAgg[$cid]['sports'][$sl]['count']++;
            }
        }

        $programs = ClassProgram::query()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('is_draft', false)->orWhereNull('is_draft');
            })
            ->where(function ($q) {
                $q->where('show_on_marketplace', true)->orWhereNull('show_on_marketplace');
            })
            ->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })
            ->where(function ($q) {
                $q->whereNull('eligibility_status')->orWhere('eligibility_status', '!=', 'paused');
            })
            ->whereNotNull('venue_id')
            ->with(['venue', 'listingSubscriptions'])->get()->filter(fn ($p) => \App\Support\MembershipListingPolicy::visible($p));

        foreach ($programs as $program) {
            $venue = $venueCity->get($program->venue_id);
            if (! $venue) {
                $venue = Venue::query()->find($program->venue_id);
            }
            if (! $venue?->city_id) {
                continue;
            }
            $cid = (int) $venue->city_id;
            if (! isset($cityAgg[$cid])) {
                $cityAgg[$cid] = ['count' => 0, 'programCount' => 0, 'sports' => [], 'memberships' => []];
            }
            $cityAgg[$cid]['programCount']++;
            $cat = trim((string) ($program->category ?: 'academy'));
            if ($cat !== '') {
                $cityAgg[$cid]['memberships'][$cat] = ($cityAgg[$cid]['memberships'][$cat] ?? 0) + 1;
            }
            $sport = trim((string) ($program->sport_category ?: ''));
            if ($sport !== '') {
                $sl = $this->slugify($sport);
                if (! isset($cityAgg[$cid]['sports'][$sl])) {
                    $cityAgg[$cid]['sports'][$sl] = ['name' => $sport, 'slug' => $sl, 'count' => 0];
                }
                $cityAgg[$cid]['sports'][$sl]['count']++;
            }
        }

        if (! $cityAgg) {
            return sendSuccess('Directory fetched successfully', ['groups' => []]);
        }

        $cities = $this->activeCitiesQuery()
            ->whereIn('id', array_keys($cityAgg))
            ->get()
            ->keyBy('id');

        $countryIds = $cities->pluck('country_id')->filter()->unique()->values();
        $countries = $this->activeCountriesQuery()
            ->whereIn('id', $countryIds)
            ->get()
            ->keyBy('id');

        $byCountry = [];
        foreach ($cityAgg as $cid => $agg) {
            if (($agg['count'] ?? 0) < 1 && ($agg['programCount'] ?? 0) < 1) {
                continue;
            }
            $city = $cities->get($cid);
            if (! $city) {
                continue;
            }
            $country = $countries->get($city->country_id);
            $key = $country?->id ?: '_';
            if (! isset($byCountry[$key])) {
                $byCountry[$key] = [
                    'country' => [
                        'name' => $country?->name ?: 'Other',
                        'slug' => $country ? $this->slugify($country->name) : 'other',
                    ],
                    'cities' => [],
                ];
            }
            $topSports = collect($agg['sports'] ?? [])->values()->sortByDesc('count')->values()->all();
            $memberships = collect($agg['memberships'] ?? [])
                ->map(fn ($count, $cat) => ['category' => $cat, 'count' => $count])
                ->values()
                ->all();
            $byCountry[$key]['cities'][] = [
                'name'          => $city->name,
                'slug'          => $this->slugify($city->name),
                'count'         => (int) ($agg['count'] ?? 0),
                'programCount'  => (int) ($agg['programCount'] ?? 0),
                'topSports'     => $topSports,
                'memberships'   => $memberships,
            ];
        }

        $groups = collect($byCountry)->map(function ($g) {
            $g['cities'] = collect($g['cities'])
                ->sortByDesc(fn ($c) => $c['count'])
                ->values()
                ->all();

            return $g;
        })->sortByDesc(fn ($g) => collect($g['cities'])->sum('count'))
            ->values()
            ->all();

        return sendSuccess('Directory fetched successfully', ['groups' => $groups]);
    }

    public function defaultCity(Request $request)
    {
        $iso = strtoupper(trim((string) $request->query('country_iso', '')));

        $tally = Venue::query()
            ->where('status', Venue::ACTIVE)
            ->whereNotNull('city_id')
            ->selectRaw('city_id, COUNT(*) as venue_count')
            ->groupBy('city_id')
            ->pluck('venue_count', 'city_id');

        if ($tally->isEmpty()) {
            return sendSuccess('Default city fetched', ['country' => null, 'city' => null]);
        }

        $cityIds = $tally->keys()->map(fn ($id) => (int) $id)->all();
        $cities = $this->activeCitiesQuery()
            ->whereIn('id', $cityIds)
            ->get();

        $chosen = null;
        if ($iso !== '') {
            $countryIds = $this->activeCountriesQuery()
                ->where('iso2', $iso)
                ->pluck('id');
            $inCountry = $cities->filter(fn (City $c) => $countryIds->contains($c->country_id))
                ->sortByDesc(fn (City $c) => (int) ($tally[$c->id] ?? 0));
            $chosen = $inCountry->first();
        }

        if (! $chosen) {
            $chosen = $cities->sortByDesc(fn (City $c) => (int) ($tally[$c->id] ?? 0))->first();
        }

        if (! $chosen) {
            return sendSuccess('Default city fetched', ['country' => null, 'city' => null]);
        }

        $country = $this->activeCountriesQuery()
            ->where('id', $chosen->country_id)
            ->first();

        return sendSuccess('Default city fetched', [
            'country' => $country ? $this->mapCountry($country) : null,
            'city'    => [
                'id'         => $chosen->id,
                'name'       => $chosen->name,
                'country_id' => $chosen->country_id,
            ],
        ]);
    }

    public function servedRegions(Request $request)
    {
        $slug = strtolower(trim((string) $request->query('brand_slug', '')));
        if ($slug === '') {
            return sendSuccess('Served regions fetched', ['countries' => [], 'cities' => []]);
        }

        $business = Business::query()
            ->where(function ($q) use ($slug) {
                $q->whereRaw('LOWER(username) = ?', [$slug])
                    ->orWhereRaw('LOWER(brand_subdomain) = ?', [$slug]);
            })
            ->first();

        if (! $business?->owner_id) {
            return sendSuccess('Served regions fetched', ['countries' => [], 'cities' => []]);
        }

        $tally = Venue::query()
            ->where('status', Venue::ACTIVE)
            ->where('owner_user_id', $business->owner_id)
            ->whereNotNull('city_id')
            ->selectRaw('city_id, COUNT(*) as venue_count')
            ->groupBy('city_id')
            ->pluck('venue_count', 'city_id');

        if ($tally->isEmpty()) {
            return sendSuccess('Served regions fetched', ['countries' => [], 'cities' => []]);
        }

        $cities = $this->activeCitiesQuery()
            ->whereIn('id', $tally->keys()->map(fn ($id) => (int) $id)->all())
            ->get()
            ->sortByDesc(fn (City $c) => (int) ($tally[$c->id] ?? 0))
            ->values();

        $countryIds = $cities->pluck('country_id')->filter()->unique()->values();
        $countries = $this->activeCountriesQuery()
            ->whereIn('id', $countryIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Country $c) => $this->mapCountry($c))
            ->values();

        $cityRows = $cities->map(fn (City $c) => [
            'id'         => $c->id,
            'name'       => $c->name,
            'country_id' => $c->country_id,
        ])->values();

        return sendSuccess('Served regions fetched', [
            'countries' => $countries,
            'cities'    => $cityRows,
        ]);
    }

    protected function resolveCityBySlug(string $slug): ?array
    {
        $cityIds = Venue::query()
            ->where('status', Venue::ACTIVE)
            ->whereNotNull('city_id')
            ->distinct()
            ->pluck('city_id');

        if ($cityIds->isEmpty()) {
            return null;
        }

        $cities = $this->activeCitiesQuery()
            ->with(['country.currency'])
            ->whereIn('id', $cityIds)
            ->get();

        $city = $cities->first(fn (City $c) => $this->slugify($c->name) === $slug);
        if (! $city) {
            return null;
        }

        return $this->mapCityDetail($city);
    }

    protected function activeCountriesQuery()
    {
        return Country::query()
            ->with('currency')
            ->where('status', 1)
            ->orderBy('name');
    }

    protected function activeCitiesQuery()
    {
        $query = City::query()->orderBy('name');
        if (Schema::hasColumn('cities', 'status')) {
            $query->where('status', 1);
        }

        return $query;
    }

    /**
     * @param  int|null  $cityCount  Cities available in this country, or null when
     *                               it was not looked up. Null means UNKNOWN and
     *                               must not be read as zero: a country with no
     *                               seeded cities is a data gap to fix, which is
     *                               a different thing from not having asked.
     */
    protected function mapCountry(Country $c, ?int $cityCount = null): array
    {
        $currency = $c->relationLoaded('currency') ? $c->currency : null;

        return [
            'id'            => $c->id,
            'name'          => $c->name,
            'iso_code'      => $c->iso2,
            'currency_code' => $currency?->code ?: '',
            'cities_count'  => $cityCount,
            'has_cities'    => $cityCount === null ? null : $cityCount > 0,
        ];
    }

    /**
     * Active city counts keyed by country id, for the countries asked about.
     *
     * Builds its own query rather than reusing activeCitiesQuery(): that one
     * orders by name, and an ORDER BY on a non-grouped column is rejected
     * outright by MySQL in ONLY_FULL_GROUP_BY.
     *
     * @param  array<int, int|string>  $countryIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected function cityCountsFor(array $countryIds)
    {
        if (! $countryIds) {
            return collect();
        }

        $query = City::query()->whereIn('country_id', $countryIds);
        if (Schema::hasColumn('cities', 'status')) {
            $query->where('status', 1);
        }

        return $query->selectRaw('country_id, COUNT(*) as cnt')
            ->groupBy('country_id')
            ->pluck('cnt', 'country_id');
    }

    protected function mapCityDetail(City $city): array
    {
        $country = $city->relationLoaded('country') ? $city->country : null;

        return [
            'id'         => $city->id,
            'name'       => $city->name,
            'country_id' => $city->country_id,
            'country'    => $country ? $this->mapCountry($country) : null,
        ];
    }

    protected function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace("/['\"]/", '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim($s, '-');
    }
}
