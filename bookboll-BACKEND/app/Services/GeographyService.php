<?php

namespace App\Services;

use App\Models\CommissionRate;
use App\Models\MarketSetting;
use App\Support\MarketSettings;
use App\Support\AccessScope;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Admin Countries & Cities page — World package + commission rates.
 */
class GeographyService
{
    public const LISTING_TYPES = ['facility', 'program', 'event'];

    public function countries(Request $request)
    {
        $search = $request->query('search');
        $status = $request->query('status'); // active|inactive|null
        $recordsPerPage = (int) ($request->input('records_per_page') ?? $request->query('records_per_page', 25));
        if ($recordsPerPage < 1) {
            $recordsPerPage = 25;
        }
        if ($recordsPerPage > 250) {
            $recordsPerPage = 250;
        }

        $query = Country::withCount('cities')
            ->with('currency')
            ->selectSub(DB::table('venues as v')->leftJoin('cities as vc', 'vc.id', '=', 'v.city_id')
                ->selectRaw('COUNT(*)')->whereRaw('COALESCE(v.country_id, vc.country_id) = countries.id'), 'venues_count')
            ->selectSub(DB::table('businesses as b')->selectRaw('COUNT(*)')
                ->where(function ($q) {
                    $q->whereColumn('b.country_id', 'countries.id')->orWhereExists(function ($v) {
                        $v->selectRaw('1')->from('venues as bv')->leftJoin('cities as bc', 'bc.id', '=', 'bv.city_id')
                            ->whereRaw('COALESCE(bv.country_id, bc.country_id) = countries.id')
                            ->where(fn ($owner) => $owner->whereColumn('bv.business_id', 'b.id')
                                ->orWhere(fn ($legacy) => $legacy->whereNull('bv.business_id')->whereColumn('bv.owner_user_id', 'b.owner_id')));
                    });
                }), 'businesses_count');
        $sort = $request->query('sort', 'venues');
        if ($sort === 'businesses') $query->orderByDesc('businesses_count')->orderByDesc('venues_count');
        elseif ($sort !== 'name') $query->orderByDesc('venues_count')->orderByDesc('businesses_count');
        $query->orderBy('name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('iso2', 'LIKE', "%{$search}%")
                    ->orWhere('iso3', 'LIKE', "%{$search}%")
                    ->orWhereHas('cities', function ($cq) use ($search) {
                        $cq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($status === 'active') {
            $query->where('status', 1);
        } elseif ($status === 'inactive') {
            $query->where('status', 0);
        }

        $actor = $request->user();
        if ($actor && $actor->hasRole('country_admin') && ! $actor->hasRole('super_admin')) {
            $allowed = \App\Support\AccessScope::jurisdictionCountryIds($actor) ?? [];
            if ($allowed) {
                $query->whereIn('id', $allowed);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $paginator = $query->paginate($recordsPerPage);
        $countries = collect($paginator->items())->map(fn(Country $c) => $this->mapCountry($c))->values();

        $commissionRates = $countries->mapWithKeys(fn ($c) => [$c['id'] => array_intersect_key($c['settings']['effective'], array_flip(self::LISTING_TYPES))]);

        return sendSuccess('Countries fetched successfully', [
            'countries'        => $countries,
            'global_settings' => app(MarketSettings::class)->config(),
            'commission_rates' => $commissionRates,
            'pagination'       => [
                'current_page'     => $paginator->currentPage(),
                'last_page'        => $paginator->lastPage(),
                'records_per_page' => $paginator->perPage(),
                'total'            => $paginator->total(),
                'from'             => $paginator->firstItem(),
                'to'               => $paginator->lastItem(),
            ],
        ]);
    }

    public function cities(Request $request)
    {
        $data = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'search'     => 'nullable|string|max:100',
        ]);

        $this->authorizeCountry($request, $data['country_id'], false);
        $query = City::where('country_id', $data['country_id'])
            ->orderBy('name');

        if (! empty($data['search'])) {
            $query->where('name', 'LIKE', '%' . $data['search'] . '%');
        }

        $cities = $query->get()->map(fn(City $c) => $this->mapCity($c));

        return sendSuccess('Cities fetched successfully', compact('cities'));
    }

    public function updateCountryStatus(Request $request, int $id)
    {
        $country = Country::findOrFail($id);
        $this->authorizeCountry($request, $id);

        $data = $request->validate([
            'status' => 'required|in:active,inactive,0,1',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;
        $country->status = $status;
        $country->save();

        return sendSuccess('Country status updated', [
            'country' => $this->mapCountry($country->fresh()->loadCount('cities')),
        ]);
    }

    public function updateCityStatus(Request $request, int $id)
    {
        $city = City::findOrFail($id);
        $this->authorizeCountry($request, $city->country_id);

        $data = $request->validate([
            'status' => 'required|in:active,inactive,0,1',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;
        if (! \Schema::hasColumn('cities', 'status')) {
            return sendError('City status column is missing. Run migrations.', [], 500);
        }
        $city->status = $status;
        $city->save();

        return sendSuccess('City status updated', [
            'city' => $this->mapCity($city->fresh()),
        ]);
    }

    public function bulkUpdateCityStatus(Request $request)
    {
        $data = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'status'     => 'required|in:active,inactive,0,1',
            'city_ids'   => 'required|array|min:1',
            'city_ids.*' => 'integer',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;

        $this->authorizeCountry($request, $data['country_id']);
        $query = City::where('country_id', $data['country_id']);
        if (! empty($data['city_ids'])) {
            $query->whereIn('id', $data['city_ids']);
        }
        $updated = $query->update(['status' => $status]);

        return sendSuccess('Cities updated', ['updated' => $updated]);
    }

    public function upsertCommission(Request $request)
    {
        $data = $request->validate([
            'country_id'   => 'required|integer|exists:countries,id',
            'listing_type' => 'required|string|in:' . implode(',', self::LISTING_TYPES),
            'rate_percent' => 'required|numeric|min:0|max:100',
        ]);

        $this->authorizeCountry($request, $data['country_id']);
        $rate = CommissionRate::updateOrCreate(
            [
                'country_id'   => $data['country_id'],
                'listing_type' => $data['listing_type'],
            ],
            [
                'rate_percent' => $data['rate_percent'],
            ]
        );

        app(MarketSettings::class)->clear();
        return sendSuccess('Commission rate saved', ['commission_rate' => $rate]);
    }

    /** Every saved per-country program, for the Countries page summary cards. */
    public function loyaltyPrograms()
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);
        $programs = LoyaltyProgram::query()->whereNotNull('country_id')->where('inherits_global', false)->orderBy('country_id')->get();

        // Each country's tier ladder rides along, in the shape the dashboard
        // renders (threshold / sort_order). Rows with a null program_id are the
        // legacy global defaults and are offered to every country.
        $tiers = LoyaltyTier::query()->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(fn (LoyaltyTier $t) => $t->toDashboardRow())
            ->values();

        return sendSuccess('Loyalty programs fetched', [
            'loyalty_programs' => $programs,
            'loyalty_tiers'    => $tiers,
            'defaults'         => LoyaltyProgram::defaults(),
        ]);
    }

    public function upsertLoyaltyProgram(Request $request)
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403);
        if ($request->boolean('use_global')) {
            $data = $request->validate(['country_id' => 'required|integer|exists:countries,id']);
            $program = LoyaltyProgram::where('country_id', $data['country_id'])->first();
            if ($program) $program->update(['inherits_global' => true]);
            return sendSuccess('Using global rewards', ['defaults' => LoyaltyProgram::defaults()]);
        }
        $data = $request->validate([
            'country_id'     => 'present|nullable|integer|exists:countries,id',
            'name'           => 'required|string|max:100',
            'earn_rate'      => 'required|numeric|min:0',
            'redeem_rate'    => 'required|integer|min:1',
            'min_redeem'     => 'required|integer|min:0',
            'max_redeem_pct' => 'required|integer|min:0|max:100',
            'expiry_months'  => 'required|integer|min:0|max:600',
            'is_active'      => 'required|boolean',
            // The tier ladder for this country. Absent = leave the tiers alone;
            // present (even empty) = replace them, matching the Supabase build,
            // which deleted the program's tiers and re-inserted what was on screen.
            'tiers'              => 'sometimes|array',
            // Blank rows are the editor's empty placeholders; replaceTiers drops
            // them. Rejecting the whole save over one would lose the other edits.
            'tiers.*.name'       => 'nullable|string|max:60',
            'tiers.*.threshold'  => 'nullable|numeric|min:0',
            'tiers.*.multiplier' => 'nullable|numeric|min:0',
            'tiers.*.color'      => 'nullable|string|max:32',
            'tiers.*.bg_color'   => 'nullable|string|max:32',
        ]);

        // redeem_value is standardised at 1 currency unit per step for every currency —
        // the only tunable knob is redeem_rate ("points for 1 unit off").
        return DB::transaction(function () use ($data, $request) {
        // Serialize the nullable global key as MySQL permits multiple NULL unique values.
        MarketSetting::where('scope', 'global')->where('scope_id', 0)->lockForUpdate()->first();
        $program = LoyaltyProgram::updateOrCreate(
            [
                'country_id' => $data['country_id'],
            ],
            [
                'name'           => $data['name'],
                'earn_rate'      => $data['earn_rate'],
                'redeem_rate'    => $data['redeem_rate'],
                'redeem_value'   => 1,
                'min_redeem'     => $data['min_redeem'],
                'max_redeem_pct' => $data['max_redeem_pct'],
                'expiry_months'  => $data['expiry_months'],
                'is_active'      => $data['is_active'],
                'inherits_global' => false,
            ]
        );

        if ($request->has('tiers')) {
            $this->replaceTiers($program, $data['tiers'] ?? []);
        }

        return sendSuccess('Loyalty program saved', [
            'loyalty_program' => $program,
            'loyalty_tiers'   => LoyaltyTier::where('program_id', $program->country_id === null ? null : $program->id)->where('is_active', true)->orderBy('order')->get()
                ->map(fn (LoyaltyTier $t) => $t->toDashboardRow())->values(),
        ]);
        });
    }

    /**
     * Replace a program's tiers with exactly what was submitted.
     *
     * Done inside a transaction: a half-written ladder would silently re-grade
     * every customer in the country.
     */
    protected function replaceTiers(LoyaltyProgram $program, array $rows): void
    {
        DB::transaction(function () use ($program, $rows) {
            $programId = $program->country_id === null ? null : $program->id;
            // Historical users may reference a tier. Retire it without breaking that link.
            LoyaltyTier::where('program_id', $programId)->update(['is_active' => false]);

            $order = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;   // blank rows are the editor's empty placeholders
                }

                $slug = Str::slug($name.'-'.($programId ?? 'global').'-'.$order);
                LoyaltyTier::updateOrCreate(['program_id' => $programId, 'slug' => $slug], [
                    'program_id' => $programId,
                    'name'       => $name,
                    'color'      => $row['color'] ?? '#92400e',
                    'bg_color'   => $row['bg_color'] ?? '#fef3c7',
                    'min_points' => (int) ($row['threshold'] ?? 0),
                    'multiplier' => (float) ($row['multiplier'] ?? 1),
                    'order'      => $order,
                    'is_active'  => true,
                ]);
                $order++;
            }
        });
    }

    protected function mapCountry(Country $c): array
    {
        $status = (int) ($c->status ?? 1) === 1 ? 'active' : 'inactive';
        $currency = $c->relationLoaded('currency') ? $c->currency : null;

        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'iso_code'        => $c->iso2,
            'iso2'            => $c->iso2,
            'iso3'            => $c->iso3 ?? null,
            'phone_code'      => $c->phone_code ?? null,
            'region'          => $c->region ?? null,
            'subregion'       => $c->subregion ?? null,
            'currency_code'   => $currency?->code,
            'currency_symbol' => $currency?->symbol,
            'timezone'        => null,
            'status'          => $status,
            'cities_count'    => (int) ($c->cities_count ?? 0),
            'venues_count' => (int) ($c->venues_count ?? 0),
            'businesses_count' => (int) ($c->businesses_count ?? 0),
            'settings' => app(MarketSettings::class)->config($c->id),
        ];
    }

    protected function mapCity(City $c): array
    {
        $raw = Schema::hasColumn('cities', 'status') ? (int) ($c->status ?? 1) : 1;

        return [
            'id'         => $c->id,
            'country_id' => $c->country_id,
            'state_id'   => $c->state_id,
            'settings' => app(MarketSettings::class)->config($c->country_id, $c),
            'name'       => $c->name,
            'timezone'   => null,
            'status'     => $raw === 1 ? 'active' : 'inactive',
            // Per-city service modules. NULL reads as ON, matching the dashboard's
            // `module_* === false` test and the original Supabase default.
            'module_listings'    => app(MarketSettings::class)->config($c->country_id, $c)['effective']['module_listings'],
            'module_classes'     => app(MarketSettings::class)->config($c->country_id, $c)['effective']['module_classes'],
            'module_matchmaking' => app(MarketSettings::class)->config($c->country_id, $c)['effective']['module_matchmaking'],
        ];
    }

    /**
     * Turn one service module on or off for a city.
     *
     * Disabling a module stops providers creating that kind of thing in the city;
     * anything already published is left alone, exactly as the Supabase build did.
     */
    public function updateCityModule(Request $request, int $id)
    {
        $data = $request->validate([
            'module'  => 'required|in:module_listings,module_classes,module_matchmaking',
            'enabled' => 'present|nullable|boolean',
        ]);

        $city = City::findOrFail($id);
        $this->authorizeCountry($request, $city->country_id);
        $city->{$data['module']} = $data['enabled'] === null ? null : (bool) $data['enabled'];
        $city->save();

        return sendSuccess('City module updated', ['city' => $this->mapCity($city->fresh())]);
    }
    protected function authorizeCountry(Request $request, int $countryId, bool $write = true): void
    {
        $user = $request->user();
        if ($write) abort_unless($user?->hasAnyRole(['super_admin', 'admin', 'country_admin']), 403);
        $allowed = AccessScope::jurisdictionCountryIds($user);
        if (is_array($allowed)) abort_unless(in_array($countryId, $allowed, true), 403, 'This country is outside your jurisdiction.');
    }

    public function settings(Request $request, string $scope, int $id)
    {
        abort_unless(in_array($scope, ['global', 'country', 'city'], true), 404);
        $city = $scope === 'city' ? City::findOrFail($id) : null;
        if ($scope === 'global') abort_unless($id === 0 && $request->user()?->hasRole('super_admin'), 403);
        else {
            $countryId = $city ? (int) $city->country_id : (int) Country::findOrFail($id)->id;
            $this->authorizeCountry($request, $countryId);
        }
        if ($request->isMethod('put')) {
            $rules = ['values' => 'required|array:facility,program,event,module_listings,module_classes,module_matchmaking'];
            foreach (MarketSettings::DEFAULTS as $key => $default) {
                $rules['values.'.$key] = ($scope === 'global' ? 'required|' : 'present|nullable|')
                    .(is_bool($default) ? 'boolean' : 'numeric|min:0|max:100');
            }
            $values = $request->validate($rules)['values'];
            DB::transaction(function () use ($scope, $id, $city, $values) {
                $stored = [];
                foreach ($values as $key => $value) {
                    if ($scope === 'country' && in_array($key, self::LISTING_TYPES, true)) {
                        $where = ['country_id' => $id, 'listing_type' => $key];
                        if ($value === null) CommissionRate::where($where)->get()->each->delete();
                        else CommissionRate::updateOrCreate($where, ['rate_percent' => $value]);
                    } elseif ($city && str_starts_with($key, 'module_')) {
                        $city->$key = $value;
                    } elseif ($value !== null) {
                        $stored[$key] = is_bool(MarketSettings::DEFAULTS[$key]) ? (bool) $value : (float) $value;
                    }
                }
                if ($city) $city->save();
                MarketSetting::updateOrCreate(['scope' => $scope, 'scope_id' => $id], ['values' => $stored]);
            });
            app(MarketSettings::class)->clear();
        }
        return sendSuccess('Market settings loaded', ['settings' => app(MarketSettings::class)->config($countryId ?? null, $city?->fresh())]);
    }

}
