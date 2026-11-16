<?php

namespace App\Services;

use App\Models\Listing;
use App\Support\AccessScope;
use App\Support\AdminAlerts;
use App\Support\Capabilities;
use App\Support\CityModules;
use App\Support\HourPeriods;
use Nnjeim\World\Models\City;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\SportsCategory;
use App\Models\Venue;
use App\Support\PremiumPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Admin dashboard (apps/admin Listings.vue) CRUD for listings.
 * Keeps legacy ListingService /admin/listings routes untouched.
 */
class ListingDashboardService
{
    public const DAY_NAMES = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    public function __construct(protected Listing $listingModel)
    {}

    /**
     * The Listings table — filtered, sorted and paginated ON THE SERVER.
     *
     * The page used to pull every listing and every venue and do all of this in the
     * browser. That is fine at 36 listings and impossible at thousands, so each of
     * the page's filters is expressed as SQL here.
     *
     * Rows carry their venue's name, city and country so the table can render and
     * group without a second lookup list.
     */
    public function index($request)
    {
        $f = $this->listingFilters($request);
        $query = $this->scopedListingQuery($request, $f);

        // Export wants every matching row; the table wants one page.
        if ($request->boolean('export')) {
            $rows = $query->limit(self::EXPORT_MAX)->get();

            return sendSuccess('Listings dashboard fetched', [
                'listings'  => $this->mapListingRows($rows),
                'truncated' => $rows->count() >= self::EXPORT_MAX,
            ]);
        }

        // No pagination asked for → the old whole-list shape, so callers that still
        // want everything (the editor's venue picker, the grouped view's counts)
        // keep working unchanged.
        if (! $request->filled('page') && ! $request->filled('per_page')) {
            return sendSuccess('Listings dashboard fetched', [
                'listings' => $this->mapListingRows($query->get()),
                // The grouped view lands here (it pages over venues, not listings)
                // and still needs the scope-wide numbers for its Sport dropdown
                // and its "export everything" count.
                'scope_total'   => $this->scopeBaseQuery($request)->count(),
                'sport_options' => $this->sportOptions($request),
            ]);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return sendSuccess('Listings dashboard fetched', [
            'listings'   => $this->mapListingRows(collect($page->items())),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'from'         => $page->firstItem(),
                'to'           => $page->lastItem(),
            ],
            // Unfiltered count in scope — the export dialog offers both numbers.
            // Built from the bare scope: `scopedListingQuery` also reads venue_id /
            // venue_ids straight off the request, so reusing it here would have
            // counted only the venues on the current grouped page.
            'scope_total'   => $this->scopeBaseQuery($request)->count(),
            // The sport filter's options. The page used to derive these from the
            // full listing array it held; now that it holds one page, the server
            // has to supply them or the dropdown shrinks as you page.
            'sport_options' => $this->sportOptions($request),
        ]);
    }

    private const EXPORT_MAX = 20000;

    /**
     * Listings this user may see, with NO filters applied — the denominator for
     * "export everything" and for the filter dropdowns' options.
     */
    protected function scopeBaseQuery($request)
    {
        $query = $this->listingModel::query();

        AccessScope::applyVenue($query, 'venue_id', auth()->user());
        $pinned = AccessScope::pinnedListingIds(auth()->user());
        if ($pinned) {
            $query->whereIn('id', $pinned);
        }
        if ($request->filled('owner_user_id')) {
            $ownerId = $request->owner_user_id;
            $query->whereHas('venue', fn ($q) => $q->ownedBy((int) $ownerId));
        }

        return $query;
    }

    /**
     * Distinct sport values per category across the whole scope, so the Sport
     * dropdown offers the same options it did when the page held every listing.
     *
     * @return array<int, array{category: string, sport: string}>
     */
    protected function sportOptions($request): array
    {
        // DISTINCT over three columns, so this stays bounded by the number of
        // sports (a few dozen) however many listings exist.
        $rows = $this->scopeBaseQuery($request)
            ->select('category', 'sport_category', 'sports_category_id')
            ->distinct()
            ->get();

        $names = SportsCategory::whereIn('id', $rows->pluck('sports_category_id')->filter()->unique())
            ->pluck('name', 'id');

        return $rows
            ->map(fn ($r) => [
                'category' => (string) ($r->category ?? 'sports'),
                // Same fallback the table displays, so every option is selectable.
                'sport'    => trim((string) ($r->sport_category ?: ($names[$r->sports_category_id] ?? ''))),
            ])
            ->filter(fn ($r) => $r['sport'] !== '')
            ->unique(fn ($r) => mb_strtolower($r['category'].'|'.$r['sport']))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function listingFilters($request): array
    {
        $arr = function ($v) {
            if (is_array($v)) return array_values(array_filter($v, fn ($x) => $x !== '' && $x !== null));
            if (is_string($v) && $v !== '') return array_values(array_filter(explode(',', $v)));
            return [];
        };

        return [
            'search'     => trim((string) $request->input('search', '')),
            'country_id' => $arr($request->input('country_id')),
            'city_id'    => $arr($request->input('city_id')),
            'category'   => (string) $request->input('category', ''),
            'sport'      => (string) $request->input('sport', ''),
            'placement'  => (string) $request->input('placement', ''),
            'visibility' => (string) $request->input('visibility', ''),
            'sort'       => (string) $request->input('sort', ''),
            'sort_dir'   => strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc',
        ];
    }

    protected function scopedListingQuery($request, array $f)
    {
        $query = $this->listingModel::query()->with(['venue:id,name,city_id,owner_user_id', 'sportsCategory:id,name']);

        // Row scope: provider ownership, jurisdiction, venue pins and listing pins —
        // the same rule every other admin surface uses.
        AccessScope::applyVenue($query, 'venue_id', auth()->user());
        $pinned = AccessScope::pinnedListingIds(auth()->user());
        if ($pinned) {
            $query->whereIn('id', $pinned);
        }

        if ($request->filled('owner_user_id')) {
            $ownerId = $request->owner_user_id;
            $query->whereHas('venue', fn ($q) => $q->ownedBy((int) $ownerId));
        }
        if ($request->filled('venue_id') || $request->filled('location_id')) {
            $query->where('venue_id', $request->venue_id ?? $request->location_id);
        }
        // The grouped view asks for exactly the venues on the current card page.
        if ($request->filled('venue_ids')) {
            $ids = array_filter(explode(',', (string) $request->input('venue_ids')));
            $query->whereIn('venue_id', $ids ?: [0]);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $this->applyListingFilters($query, $f);
        $this->applyListingSort($query, $f);

        return $query;
    }

    /**
     * Venue ids that still have at least one listing matching the content filters
     * (search, category, sport, placement).
     *
     * The grouped Listings view pages over VENUE cards but hides a card whose
     * listings all filtered out, so the venue query has to know this BEFORE it
     * paginates — otherwise "10 per page" renders as two cards and the pager
     * counts venues the user can never see.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function matchingVenueIds($request)
    {
        $f = $this->listingFilters($request);

        // Same row scope as scopedListingQuery — a venue must not surface because
        // of a listing this admin cannot see.
        $query = $this->scopeBaseQuery($request);

        $this->applyListingFilters($query, $f);

        return $query->distinct()->pluck('venue_id')->filter()->values();
    }

    protected function applyListingFilters($query, array $f): void
    {
        if ($f['search'] !== '') {
            $term = '%'.$f['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('listings.name', 'LIKE', $term)
                    ->orWhere('sport_category', 'LIKE', $term)
                    // Most listings leave the column empty and show the related
                    // category's name instead; the page searches what it displays.
                    ->orWhere(fn ($w) => $w
                        ->tap($this->sportFromRelation())
                        ->whereHas('sportsCategory', fn ($s) => $s->where('name', 'LIKE', $term)))
                    ->orWhere('description', 'LIKE', $term)
                    ->orWhereHas('venue', fn ($v) => $v->where('name', 'LIKE', $term));
            });
        }

        if ($f['category'] !== '' && $f['category'] !== 'all') {
            $query->where('category', $f['category']);
        }
        if ($f['sport'] !== '' && $f['sport'] !== 'all') {
            $sport = mb_strtolower($f['sport']);
            $query->where(function ($q) use ($sport) {
                $q->whereRaw('LOWER(listings.sport_category) = ?', [$sport])
                    ->orWhere(fn ($w) => $w
                        ->tap($this->sportFromRelation())
                        ->whereHas('sportsCategory', fn ($s) => $s->whereRaw('LOWER(name) = ?', [$sport])));
            });
        }

        // Geography lives on the venue, so both filters resolve to a venue-id set.
        if ($f['city_id']) {
            $query->whereHas('venue', fn ($v) => $v->whereIn('city_id', $f['city_id']));
        }
        if ($f['country_id']) {
            $cityIds = City::whereIn('country_id', $f['country_id'])->pluck('id');
            $query->whereHas('venue', fn ($v) => $v->whereIn('city_id', $cityIds ?: [0]));
        }

        // Placement mirrors the page's own three states EXACTLY (lib/approvalState.js):
        //   rowState is 'active' unless eligibility is 'paused' or approval is
        //   'pending' — `status` plays no part, and a NULL column reads as the
        //   permissive default ('eligible' / 'approved'), which a plain `!=` in SQL
        //   would wrongly exclude.
        $onMarket = fn ($q) => $q->where(fn ($w) => $w->whereNull('show_on_marketplace')->orWhere('show_on_marketplace', true));
        $notPaused = fn ($q) => $q->where(fn ($w) => $w->whereNull('eligibility_status')->orWhere('eligibility_status', '!=', Listing::ELIGIBILITY_PAUSED));
        $notPending = fn ($q) => $q->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', Listing::APPROVAL_PENDING));

        // Who may book it: staff, customers, both, or nobody. Mirrors the
        // Listing::VISIBILITY_* pairs exactly.
        match ($f['visibility']) {
            Listing::VISIBILITY_BOTH          => $query->where('show_on_admin', true)->where('show_on_customer', true),
            Listing::VISIBILITY_ADMIN_ONLY    => $query->where('show_on_admin', true)->where('show_on_customer', false),
            Listing::VISIBILITY_CUSTOMER_ONLY => $query->where('show_on_admin', false)->where('show_on_customer', true),
            Listing::VISIBILITY_INACTIVE      => $query->where('show_on_admin', false)->where('show_on_customer', false),
            default                           => null,
        };

        match ($f['placement']) {
            'live'       => $query->tap($onMarket)->tap($notPaused)->tap($notPending),
            'review'     => $query->tap($onMarket)->where(fn ($q) => $q
                                ->where('eligibility_status', Listing::ELIGIBILITY_PAUSED)
                                ->orWhere('approval_status', Listing::APPROVAL_PENDING)),
            'off_market' => $query->where('show_on_marketplace', false),
            default      => null,
        };
    }

    /** Rows whose sport is shown from the relation because the column is empty. */
    protected function sportFromRelation(): callable
    {
        return fn ($q) => $q->where(fn ($e) => $e
            ->whereNull('listings.sport_category')
            ->orWhere('listings.sport_category', ''));
    }

    protected function applyListingSort($query, array $f): void
    {
        match ($f['sort']) {
            'name'           => $query->orderBy('listings.name', $f['sort_dir']),
            'base_price'     => $query->orderBy('base_price', $f['sort_dir']),
            // Order by the sport the table actually shows, not the mostly-empty
            // column. A correlated subquery rather than a join: a join would make
            // `id`, `name` and `category` ambiguous for every other clause here.
            'sport_category' => $query->orderByRaw(
                "COALESCE(NULLIF(listings.sport_category, ''), "
                ."(SELECT name FROM sports_categories WHERE sports_categories.id = listings.sports_category_id)) "
                .$f['sort_dir']
            ),
            // The page's default is the provider's own drag-ordering.
            default          => $query->orderBy('sort_order')->orderBy('created_at'),
        };
        $query->orderBy('listings.id');   // stable paging
    }

    /**
     * Map rows and attach the venue name, city and country the table shows and
     * groups by — without these the page still needs the whole venue list.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapListingRows($rows): array
    {
        $cityIds = collect($rows)->map(fn ($l) => $l->venue?->city_id)->filter()->unique();
        $cityCountry = $cityIds->isEmpty()
            ? collect()
            : City::whereIn('id', $cityIds)->pluck('country_id', 'id');

        return collect($rows)->map(function (Listing $l) use ($cityCountry) {
            $cityId = $l->venue?->city_id;

            return array_merge($this->mapDashboardListing($l), [
                'location_name' => $l->venue?->name,
                'city_id'       => $cityId ? (string) $cityId : null,
                'country_id'    => $cityId && isset($cityCountry[$cityId]) ? (string) $cityCountry[$cityId] : null,
            ]);
        })->values()->all();
    }

    public function show($id)
    {
        $listing = $this->listingModel::with([
            'schedules',
            'pricingGroups',
            'addOns',
            'resources',
            'venue',
        ])->find($id);

        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        // The grid is a VIEW of the groups now, not a second store. Expanding it
        // here keeps the editor's payload shape unchanged while guaranteeing the
        // two can never disagree — there is nothing left to disagree with.
        $cells = $this->cellsFromGroups($listing);

        return sendSuccess('Listing fetched', [
            'listing'         => $this->mapDashboardListing($listing),
            'hours'           => $this->mapHoursFromSchedules($listing),
            'pricing_groups'  => $listing->pricingGroups->sortBy('sort_order')->values()
                ->map(fn (PricingGroup $g) => $this->mapGroup($g))->all(),
            'pricing_cells'   => $cells,
            'payment'         => $this->mapPaymentOut($listing),
            'addons'          => $listing->addOns->values()->map(fn ($a, $i) => [
                'id'         => (string) $a->id,
                'addon_id'   => (string) $a->id,
                'sort_order' => $i,
            ])->all(),
            'resources'       => $listing->resources->map(fn ($r) => [
                'id'          => (string) $r->id,
                'resource_id' => (string) $r->id,
                'quantity'    => (int) ($r->pivot->quantity ?? 1),
            ])->values()->all(),
        ]);
    }

    /**
     * Copy a listing into a venue the caller may touch.
     *
     * Deliberately routed through store(): the copy then gets the same
     * validation, the same access check on the TARGET venue, the same
     * city-module and marketplace guards, and the same child syncing as a
     * listing typed by hand. A bespoke insert here would be a second way to
     * create a listing, and the two would drift.
     *
     * The destination is checked by store()'s own assertAccess, so a provider
     * cannot copy into another business's venue by naming its id, and the
     * source is checked here for the same reason.
     */
    public function duplicate($request, $id)
    {
        $targetVenueId = (int) ($request->input('venue_id') ?: $request->input('location_id') ?: 0);
        if ($targetVenueId <= 0) {
            return sendError('Choose the venue to copy this listing into.', [
                'venue_id' => ['Required.'],
            ], 422);
        }

        $source = $this->listingModel::with(['schedules', 'pricingGroups', 'addOns', 'resources'])->find($id);
        if (! $source) {
            return sendError('Listing not found', [], 404);
        }
        // Reading the original is its own permission; being able to write to the
        // destination does not entitle anyone to copy from somewhere they
        // cannot see.
        if ($err = $this->assertAccess((int) $source->venue_id)) {
            return $err;
        }

        $detail = $this->show($id);
        $data = $detail instanceof \Illuminate\Http\JsonResponse
            ? ($detail->getData(true)['data'] ?? [])
            : [];
        if (! ($data['listing'] ?? null)) {
            return $detail;
        }

        $payload = $this->duplicatePayload($data, $targetVenueId, (string) $request->input('name', ''));

        $created = $this->store(new \Illuminate\Http\Request($payload));

        // Gallery images live in the `assets` morph table, not in the `photos`
        // column — on this platform almost every listing's pictures are there.
        // store() copies the column, so without this the copy came out with no
        // images at all.
        $newId = $created instanceof \Illuminate\Http\JsonResponse
            ? ($created->getData(true)['data']['listing']['id'] ?? null)
            : null;
        if ($newId) {
            $this->copyAssets($source, (int) $newId);
            $this->autoApproveCopy((int) $newId, $source);

            return $this->show($newId);
        }

        return $created;
    }

    /**
     * Someone who can approve listings does not need to approve their own copy.
     *
     * Sending a platform admin a notification about work they just did, so
     * they can approve it in the same dashboard, is a review that reviews
     * nothing. The capability is the test — not the role name — so whoever may
     * approve a listing may also create an approved copy of one.
     *
     * The copy still cannot out-rank its source: duplicating something that is
     * itself pending or rejected leaves the copy pending, because approving it
     * here would launder unreviewed content through a copy.
     */
    protected function autoApproveCopy(int $newListingId, Listing $source): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasCapability(Capabilities::APPROVE_LISTINGS)) {
            return;
        }

        $sourceApproved = ($source->approval_status ?: Listing::APPROVAL_APPROVED) === Listing::APPROVAL_APPROVED;
        if (! $sourceApproved) {
            return;
        }

        $this->listingModel::whereKey($newListingId)->update([
            'approval_status' => Listing::APPROVAL_APPROVED,
            'submitted_at'    => null,
        ]);
    }

    /** Point the copy at the same stored files; nothing is re-uploaded. */
    protected function copyAssets(Listing $source, int $newListingId): void
    {
        $rows = $source->assets()->get()->map(fn ($a) => [
            'path'           => $a->path,
            'type'           => $a->type,
            'sort_order'     => $a->sort_order,
            'created_by'     => $a->created_by,
            'assetable_type' => Listing::class,
            'assetable_id'   => $newListingId,
            'created_at'     => now(),
            'updated_at'     => now(),
        ])->all();

        if ($rows) {
            DB::table('assets')->insert($rows);
        }
    }

    /**
     * The copy's payload: everything that describes the listing, nothing that
     * identifies the original.
     *
     * Approval is NOT carried over. A copy is a new listing that nobody has
     * reviewed, and inheriting the original's approved status would put
     * unreviewed content straight onto the marketplace.
     */
    protected function duplicatePayload(array $data, int $targetVenueId, string $name): array
    {
        $listing = $data['listing'];

        foreach (['id', 'uuid', 'slug', 'created_at', 'updated_at', 'sort_order',
            'approval_status', 'eligibility_status', 'submitted_at', 'admin_note'] as $identity) {
            unset($listing[$identity]);
        }

        $listing['name'] = trim($name) !== '' ? trim($name) : $this->copyName((string) ($listing['name'] ?? 'Listing'));
        $listing['location_id'] = (string) $targetVenueId;
        $listing['venue_id'] = (string) $targetVenueId;

        return array_merge($listing, [
            'hours'          => $data['hours'] ?? [],
            'pricing_groups' => $this->forgetIds($data['pricing_groups'] ?? []),
            'pricing_cells'  => $data['pricing_cells'] ?? [],
            'payment'        => $data['payment'] ?? [],
            // Add-ons and resources are the venue's own catalogue rows. They are
            // referenced by id, so they only transfer within the same venue —
            // copying the references into another venue would point at things
            // that are not there.
            'addons'    => (int) ($data['listing']['location_id'] ?? 0) === $targetVenueId ? ($data['addons'] ?? []) : [],
            'resources' => (int) ($data['listing']['location_id'] ?? 0) === $targetVenueId ? ($data['resources'] ?? []) : [],
        ]);
    }

    /** Pricing groups are new rows; carrying their ids would edit the originals. */
    protected function forgetIds(array $rows): array
    {
        return array_map(function ($row) {
            unset($row['id'], $row['listing_id']);

            return $row;
        }, $rows);
    }

    /** "Court 1" → "Court 1 (copy)", then "(copy 2)" and so on. */
    protected function copyName(string $name): string
    {
        $base = preg_replace('/\s*\(copy(?: \d+)?\)$/i', '', $name) ?: $name;
        $candidate = $base.' (copy)';

        for ($n = 2; $this->listingModel::where('name', $candidate)->exists() && $n < 50; $n++) {
            $candidate = $base.' (copy '.$n.')';
        }

        return mb_substr($candidate, 0, 255);
    }

    public function store($request)
    {
        $validated = $this->validatePayload($request, true);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        $venueId = (int) ($validated['location_id'] ?? $validated['venue_id'] ?? 0);
        if ($err = $this->assertAccess($venueId)) {
            return $err;
        }
        // The city may have the Listings module switched off (Countries & Cities).
        if ($err = CityModules::guard($venueId, CityModules::LISTINGS, 'Booking listings')) {
            return $err;
        }
        if ($err = $this->guardOffMarketplace($request, $validated, null)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $attrs = $this->normalizeListingAttrs($validated);
            $attrs['venue_id'] = $venueId;
            $attrs['sort_order'] = (int) ($this->listingModel->where('venue_id', $venueId)->max('sort_order') ?? -1) + 1;
            if (empty($attrs['approval_status'])) {
                $attrs['approval_status'] = Listing::APPROVAL_PENDING;
            }
            if (empty($attrs['eligibility_status'])) {
                $attrs['eligibility_status'] = Listing::ELIGIBILITY_ELIGIBLE;
            }
            if (empty($attrs['status'])) {
                $attrs['status'] = 'active';
            }
            // MySQL listings.payment_options has no default — seed before insert; syncChildren may overwrite.
            if (! array_key_exists('payment_options', $attrs) || $attrs['payment_options'] === null) {
                $paySeed = $this->mapPaymentIn($validated['payment'] ?? []);
                $attrs['online_payment'] = $paySeed['online_payment'];
                $attrs['payment_options'] = $paySeed['payment_options'];
            }

            $listing = $this->listingModel::create($attrs);
            $this->syncChildren($listing, $validated);

            DB::commit();

            $this->alertPlatformIfAwaitingReview($listing->fresh(), true);

            return $this->show($listing->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardStore failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to create listing');
        }
    }

    public function update($request, $id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        $validated = $this->validatePayload($request, false);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        $venueId = (int) ($validated['location_id'] ?? $validated['venue_id'] ?? $listing->venue_id);
        if ($venueId !== (int) $listing->venue_id) {
            if ($err = $this->assertAccess($venueId)) {
                return $err;
            }
        }
        if ($err = $this->guardOffMarketplace($request, $validated, $listing)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $wasPending = ($listing->approval_status ?? null) === Listing::APPROVAL_PENDING;

            $attrs = $this->normalizeListingAttrs($validated);
            $attrs['venue_id'] = $venueId;
            $listing->update($attrs);
            $this->syncChildren($listing->fresh(), $validated);

            DB::commit();

            // Only when it has just ENTERED review — editing something already in
            // the queue must not re-alert on every keystroke-save.
            if (! $wasPending) {
                $this->alertPlatformIfAwaitingReview($listing->fresh(), false);
            }

            return $this->show($listing->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardUpdate failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to update listing');
        }
    }

    public function destroy($id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            PricingGroup::where('listing_id', $listing->id)->delete();
            Schedule::where('listing_id', $listing->id)->delete();
            $listing->addOns()->detach();
            $listing->resources()->detach();
            $listing->delete();
            DB::commit();

            return sendSuccess('Listing deleted');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardDestroy failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to delete listing. It may have bookings linked to it.');
        }
    }

    public function patch($request, $id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        $data = $request->validate([
            'status'                 => 'sometimes|in:active,inactive',
            'show_on_admin'          => 'sometimes|boolean',
            'show_on_customer'       => 'sometimes|boolean',
            'eligibility_status'     => 'sometimes|in:eligible,paused,ineligible',
            'admin_note'             => 'nullable|string',
            'previous_snapshot'      => 'nullable|array',
            'show_on_marketplace'    => 'sometimes|boolean',
            'show_on_provider_site'  => 'sometimes|boolean',
            'show_slot_capacity'     => 'sometimes|boolean',
            'sort_order'             => 'sometimes|integer|min:0',
            'approval_status'        => 'sometimes|in:pending,approved,rejected',
            'pending_content'        => 'nullable|array',
        ]);

        if ($err = $this->guardOffMarketplace($request, $data, $listing)) {
            return $err;
        }

        $listing->update($data);

        return sendSuccess('Listing patched', [
            'listing' => $this->mapDashboardListing($listing->fresh()),
        ]);
    }

    public function sort($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:listings,id',
            'updates.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->input('updates') as $update) {
            $listing = $this->listingModel::find($update['id']);
            if (! $listing) {
                continue;
            }
            if ($err = $this->assertAccess((int) $listing->venue_id)) {
                return $err;
            }
        }

        DB::beginTransaction();
        try {
            foreach ($request->input('updates') as $update) {
                $this->listingModel::where('id', $update['id'])->update(['sort_order' => $update['sort_order']]);
            }
            DB::commit();

            return sendSuccess('Sort order updated');
        } catch (\Throwable $e) {
            DB::rollBack();

            return sendError('Failed to update sort order');
        }
    }

    protected function syncChildren(Listing $listing, array $validated): void
    {
        if (array_key_exists('hours', $validated)) {
            $this->syncHours($listing, $validated['hours'] ?? []);
        }
        if (array_key_exists('pricing_groups', $validated) || array_key_exists('pricing_cells', $validated)) {
            $this->syncPricing($listing, $validated['pricing_groups'] ?? [], $validated['pricing_cells'] ?? []);
        }
        if (array_key_exists('payment', $validated)) {
            $pay = $this->mapPaymentIn($validated['payment'] ?? []);
            $listing->update([
                'online_payment'  => $pay['online_payment'],
                'payment_options' => $pay['payment_options'],
            ]);
        }
        if (array_key_exists('addons', $validated)) {
            $ids = collect($validated['addons'] ?? [])
                ->pluck('addon_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $listing->addOns()->sync($ids);
        }
        if (array_key_exists('resources', $validated)) {
            $sync = [];
            foreach ($validated['resources'] ?? [] as $row) {
                $rid = (int) ($row['resource_id'] ?? 0);
                if (! $rid) {
                    continue;
                }
                $sync[$rid] = ['quantity' => max(1, (int) ($row['quantity'] ?? 1))];
            }
            $listing->resources()->sync($sync);
        }
    }

    /**
     * Save the opening hours WITHOUT throwing the rows away first.
     *
     * This deleted every Schedule for the listing and re-inserted the payload,
     * so merely opening the editor and pressing Save burned seven ids and wrote
     * seven "Schedule created" lines into the activity log — for a listing
     * nobody had touched. (The mass delete fired no model events, so the
     * matching deletions were not even recorded.)
     *
     * A row's identity is its day plus its position in that day's list of
     * periods — a day can legitimately have two windows. Match on that, update
     * in place, and only insert or delete what the payload actually changed.
     */
    protected function syncHours(Listing $listing, array $hoursUi): void
    {
        $venueId = (int) $listing->venue_id;

        // Grouped by day, in a stable order, so period 2 of Tuesday keeps being
        // period 2 of Tuesday across saves.
        $existing = Schedule::where('listing_id', $listing->id)
            ->orderBy('id')
            ->get()
            ->groupBy('day');

        $keep = [];

        foreach ($hoursUi as $day) {
            $dow     = (int) ($day['day_of_week'] ?? -1);
            $dayName = self::DAY_NAMES[$dow] ?? null;
            if (! $dayName) {
                continue;
            }

            $isOpen  = (bool) ($day['is_open'] ?? false);
            $inherit = (bool) ($day['inherit_from_location'] ?? false);

            $rows = [];
            if (! $isOpen) {
                $rows[] = ['is_open' => false, 'start_time' => null, 'end_time' => null];
            } else {
                $periods = $day['periods'] ?? [];
                if (! $periods && isset($day['open_time'])) {
                    $periods = [['open_time' => $day['open_time'], 'close_time' => $day['close_time'] ?? '23:00']];
                }
                if (! $periods) {
                    $periods = [['open_time' => '09:00', 'close_time' => '23:00']];
                }
                foreach ($periods as $p) {
                    $rows[] = [
                        'is_open'    => true,
                        'start_time' => $this->normalizeHm($p['open_time'] ?? '09:00'),
                        'end_time'   => $this->normalizeHm($p['close_time'] ?? '23:00'),
                    ];
                }
            }

            $forDay = $existing->get($dayName, collect())->values();

            foreach ($rows as $idx => $attrs) {
                $attrs += [
                    'venue_id'              => $venueId,
                    'listing_id'            => $listing->id,
                    'day'                   => $dayName,
                    'inherit_from_location' => $inherit,
                ];

                $row = $forDay->get($idx);
                if ($row) {
                    $row->fill($attrs)->save();
                } else {
                    $row = Schedule::create($attrs);
                }
                $keep[] = (int) $row->id;
            }
        }

        // Only the periods the editor actually dropped. Deleted one at a time so
        // the removal is logged — a mass delete fires no model events.
        $stale = Schedule::where('listing_id', $listing->id)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->get();
        foreach ($stale as $row) {
            $row->delete();
        }
    }

    /**
     * Save the pricing grid. Groups are the only thing stored.
     *
     * `pricing_cells` is still accepted in the payload so an older client does
     * not fail validation, but it is IGNORED. It used to be folded back into the
     * groups' days — and the editor posts back the cells it was handed at open
     * time, derived from the groups as they were THEN. Untick Thursday on a
     * group and the stale Thursday squares in that list still named the group,
     * so the fold put Thursday straight back. The save reported success, the
     * card reopened with Thursday ticked, and nothing explained why. The grid is
     * a view of the groups; it is never an input to them.
     */
    protected function syncPricing(Listing $listing, array $groups, array $cells = []): void
    {
        $this->syncPricingGroups($listing, $groups);
    }

    /**
     * Match the payload against what is stored: UPDATE in place, INSERT only what
     * is new, delete only what the editor dropped. Saving used to delete every
     * group and re-insert, so ids churned on every save and a group that nobody
     * touched came back as a different row.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    protected function syncPricingGroups(Listing $listing, array $groups): void
    {
        // Keyed on the stored id, so a payload id belonging to ANOTHER listing
        // misses and becomes a new row here rather than editing theirs.
        $existing = PricingGroup::where('listing_id', $listing->id)
            ->get()
            ->keyBy(fn (PricingGroup $g) => (string) $g->id);

        $keep = [];

        foreach (array_values($groups) as $idx => $g) {
            $attrs = [
                'listing_id'     => $listing->id,
                'name'           => $g['name'] ?? ('Group ' . ($idx + 1)),
                'color'          => $g['color'] ?? null,
                'price_per_hour' => (float) ($g['price'] ?? $g['price_per_hour'] ?? 0),
                'start_time'     => $this->normalizeHm($g['start_time'] ?? '18:00'),
                'end_time'       => $this->normalizeHm($g['end_time'] ?? '22:00'),
                // Presence matters: the grid always sends `days`, so an empty one means
                // "no days selected" and must stay empty. Only a caller that omits the
                // key entirely gets the legacy Mon-Fri default.
                'days'           => array_key_exists('days', $g)
                    ? $this->normalizeGroupDays($g['days'])
                    : ['1', '2', '3', '4', '5'],
                'sort_order'     => (int) ($g['sort_order'] ?? $idx),
            ];
            // The grid does not send a currency, and the old save wrote '' over
            // whatever was there — which the slot breakdown reads back.
            $currency = $g['currency'] ?? null;
            if (is_string($currency) && $currency !== '') {
                $attrs['currency'] = $currency;
            }

            $payloadId = isset($g['id']) ? trim((string) $g['id']) : '';
            $row       = $payloadId !== '' ? $existing->get($payloadId) : null;

            if ($row) {
                $row->fill($attrs)->save();
            } else {
                $row = PricingGroup::create($attrs);
            }

            $keep[] = (int) $row->id;
        }

        // Deleted one at a time so the activity log records the removal — a mass
        // delete fires no model events, which is how the old churn stayed invisible.
        $stale = PricingGroup::where('listing_id', $listing->id)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->get();
        foreach ($stale as $row) {
            $row->delete();
        }
    }

    /** Expand the stored groups into the square-per-hour shape the editor draws. */
    protected function cellsFromGroups(Listing $listing): array
    {
        $listing->loadMissing('pricingGroups');

        $step = max(15, (int) $listing->slotDurationMinutes());
        $out  = [];

        foreach (array_flip(\App\Models\Schedule::DAY_INDEX) as $dow => $weekday) {
            for ($t = 0; $t + $step <= 24 * 60; $t += $step) {
                $slot = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                $end  = $t + $step;
                $endHm = $end === 24 * 60 ? '00:00' : sprintf('%02d:%02d', intdiv($end, 60), $end % 60);

                $group = matchingPricingGroupForSlot($listing, $weekday, $slot, $endHm);
                if (! $group) {
                    continue;
                }

                $out[] = [
                    'id'               => 'g' . $group->id . '-' . $dow . '-' . $slot,
                    'listing_id'       => (string) $listing->id,
                    'day_of_week'      => (int) $dow,
                    'slot_time'        => $slot,
                    'pricing_group_id' => (string) $group->id,
                    'direct_price'     => null,
                ];
            }
        }

        return $out;
    }

    protected function normalizeGroupDays($days): array
    {
        if (is_array($days)) {
            return array_values(array_filter(
                array_map(fn ($d) => is_string($d) ? trim($d) : $d,
                    $days),
                fn ($d) => $d !== '' && $d !== null,
            ));
        }
        if (is_string($days)) {
            return array_values(array_filter(
                array_map('trim', explode(',', $days)),
                fn ($d) => $d !== '',
            ));
        }

        // Reached only for null — which is also what the framework's
        // convert-empty-strings middleware hands us for a cleared day list.
        return [];
    }

    protected function mapHoursFromSchedules(Listing $listing): array
    {
        $byDay = [];
        foreach ($listing->schedules as $row) {
            $dow = $row->day_of_week;
            if ($dow === null) {
                continue;
            }
            if (! isset($byDay[$dow])) {
                $byDay[$dow] = [
                    'day_of_week'           => $dow,
                    'is_open'               => false,
                    'periods'               => [],
                    'inherit_from_location' => (bool) $row->inherit_from_location,
                ];
            }
            if ($row->is_open) {
                $byDay[$dow]['is_open'] = true;
                $byDay[$dow]['periods'][] = [
                    'open_time'  => $this->fmtHm($row->start_time) ?? '09:00',
                    'close_time' => $this->fmtHm($row->end_time) ?? '23:00',
                ];
            }
            if ($row->inherit_from_location) {
                $byDay[$dow]['inherit_from_location'] = true;
            }
        }

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $byDay[$i] ?? [
                'day_of_week'           => $i,
                'is_open'               => false,
                'periods'               => [],
                'inherit_from_location' => false,
            ];
            // Stored one row per period; imported listings often carry a row per
            // half hour, which the editor would draw as 48 lines a day. Merge for
            // display — the stored rows and the bookable window are unchanged.
            $day['periods'] = HourPeriods::merge($day['periods']);
            $out[] = $day;
        }

        return $out;
    }

    protected function mapDashboardListing(Listing $l): array
    {
        $photos = $l->photos;
        if (! is_array($photos)) {
            $photos = [];
        }
        if (! $photos && $l->photo_url) {
            $photos = [$l->photo_url];
        }

        return [
            'id'                      => (string) $l->id,
            'location_id'             => $l->venue_id ? (string) $l->venue_id : null,
            'venue_id'                => $l->venue_id ? (string) $l->venue_id : null,
            'name'                    => $l->name,
            'description'             => $l->description ?? '',
            'sport_category'          => $l->sport_category ?: ($l->sportsCategory?->name ?? ''),
            'category'                => $l->category ?? 'sports',
            'base_price'              => (float) ($l->base_price ?? 0),
            'slot_duration'           => $l->slotDurationMinutes(),
            'buffer_time'             => (int) ($l->buffer_time ?? 0),
            'start_time_mode'         => $l->startTimeMode(),
            'fixed_start_times'       => $l->fixedStartTimes(),
            'custom_slots'            => $l->customSlots(),
            'capacity'                => (int) ($l->capacity ?? 0),
            'status'                  => $l->status ?? 'active',
            // Per-surface visibility. `status` stays in the payload and is still
            // derived from these two, so older readers are unaffected.
            'show_on_admin'           => (bool) ($l->show_on_admin ?? true),
            'show_on_customer'        => (bool) ($l->show_on_customer ?? true),
            'visibility'              => $l->visibility ?? Listing::VISIBILITY_BOTH,
            'loyalty_points'          => (int) ($l->loyalty_points ?? 0),
            'booking_color'           => $l->calendar_color ?? '#10b981',
            'listing_type'            => $l->listing_type ?? 'per_space',
            'max_bookings_per_slot'   => (int) ($l->max_bookings_per_slot ?? 1),
            'commission_rate'         => $l->commission_rate !== null ? (float) $l->commission_rate : null,
            'photo_url'               => $l->photo_url ?? ($photos[0] ?? ''),
            'photos'                  => $photos,
            'approval_status'         => $l->approval_status ?? Listing::APPROVAL_APPROVED,
            'eligibility_status'      => $l->eligibility_status ?? Listing::ELIGIBILITY_ELIGIBLE,
            'admin_note'              => $l->admin_note ?? '',
            'previous_snapshot'       => $l->previous_snapshot,
            'pending_content'         => $l->pending_content,
            'show_on_marketplace'     => $l->show_on_marketplace !== false,
            'show_on_provider_site'   => $l->show_on_provider_site !== false,
            // Presentational only — capacity is still enforced either way.
            'show_slot_capacity'      => $l->show_slot_capacity !== false,
            'extension_pricing'       => $l->extension_pricing ?? 'none',
            'extension_value'         => (float) ($l->extension_value ?? 0),
            'extension_max_slots'     => (int) ($l->extension_max_slots ?? 1),
            'booking_hold_minutes'    => (int) ($l->booking_hold_minutes ?? 0),
            'sort_order'              => (int) ($l->sort_order ?? 0),
            'submitted_at'            => $l->submitted_at?->toIso8601String(),
            'submitted_by_name'       => $l->submitted_by_name,
            'created_at'              => $l->created_at?->toIso8601String(),
        ];
    }

    protected function mapGroup(PricingGroup $g): array
    {
        $days = $g->days;
        if (is_array($days)) {
            $daysStr = implode(',', $days);
        } else {
            $daysStr = (string) ($days ?? '1,2,3,4,5');
        }

        return [
            'id'         => (string) $g->id,
            'listing_id' => (string) $g->listing_id,
            'name'       => $g->name,
            'color'      => $g->color,
            'price'      => (float) ($g->price_per_hour ?? 0),
            'start_time' => $g->start_time,
            'end_time'   => $g->end_time,
            'days'       => $daysStr,
            'sort_order' => (int) ($g->sort_order ?? 0),
        ];
    }

    protected function mapPaymentOut(Listing $l): array
    {
        $po = is_array($l->payment_options) ? $l->payment_options : [];
        $deposit = is_array($po['deposit'] ?? null) ? $po['deposit'] : [];

        return [
            'cash_on_arrival'         => (bool) ($po['cash_on_arrival'] ?? false),
            'deposit_enabled'         => (bool) ($po['deposit_enabled'] ?? ($deposit['enabled'] ?? ! empty($deposit))),
            'deposit_amount'          => (float) ($po['deposit_amount'] ?? ($deposit['amount'] ?? 0)),
            'deposit_percentage'      => (float) ($po['deposit_percentage'] ?? ($deposit['percentage'] ?? 20)),
            'online_payment_enabled'  => (bool) ($l->online_payment || ($po['online_payment_enabled'] ?? false)),
            'site_deposit_enabled'    => (bool) ($po['site_deposit_enabled'] ?? false),
            'site_deposit_percentage' => (float) ($po['site_deposit_percentage'] ?? 10),
            'site_online_enabled'     => (bool) ($po['site_online_enabled'] ?? false),
            'site_cash_enabled'       => (bool) ($po['site_cash_enabled'] ?? false),
        ];
    }

    protected function mapPaymentIn(array $pay): array
    {
        $pct = (float) ($pay['deposit_percentage'] ?? 20);
        $enabled = (bool) ($pay['deposit_enabled'] ?? false);

        return [
            'online_payment'  => (bool) ($pay['online_payment_enabled'] ?? false),
            'payment_options' => [
                'cash_on_arrival'         => (bool) ($pay['cash_on_arrival'] ?? false),
                'deposit_enabled'         => $enabled,
                'deposit_amount'          => (float) ($pay['deposit_amount'] ?? 0),
                'deposit_percentage'      => $pct,
                'online_payment_enabled'  => (bool) ($pay['online_payment_enabled'] ?? false),
                'deposit'                 => [
                    'enabled'    => $enabled,
                    'amount'     => (float) ($pay['deposit_amount'] ?? 0),
                    'percentage' => $pct,
                ],
                'site_deposit_enabled'    => (bool) ($pay['site_deposit_enabled'] ?? false),
                // Stored as a RECORD of the preview the merchant was shown, never
                // as the authority. The own-site deposit is Bookboll's commission,
                // derived at booking time by CommissionPolicy — accepting this
                // number as the fee would let a merchant set their own rate by
                // editing the payload. readmeAlamgir: "Ignore client-supplied
                // commission percentages as authority."
                'site_deposit_percentage' => (float) ($pay['site_deposit_percentage'] ?? 0),
                'site_online_enabled'     => (bool) ($pay['site_online_enabled'] ?? false),
                // Retired. Full payment on arrival is no longer an own-site
                // method, so the flag is never stored true again — a legacy row
                // keeps its value but is ignored everywhere that matters.
                'site_cash_enabled'       => false,
            ],
        ];
    }


    /**
     * Hiding a listing from the Bookboll Marketplace and selling it only on your own
     * site is the `own_site_only` Premium feature (Listings.vue gates the toggle).
     * Turning it back ON — going public again — is always free.
     */
    protected function guardOffMarketplace($request, array $data, ?Listing $listing)
    {
        // `boolean` validation accepts false, 0 and "0" without casting, so read the
        // intent rather than the literal.
        if (! array_key_exists('show_on_marketplace', $data)
            || filter_var($data['show_on_marketplace'], FILTER_VALIDATE_BOOLEAN) !== false) {
            return null;
        }
        if ($listing && $listing->show_on_marketplace === false) {
            return null;   // already off the marketplace — not a new use of the feature
        }
        // auth() to match assertAccess() above — same user, one source of truth.
        if (PremiumPlan::entitled($request->user() ?? auth()->user())) {
            return null;
        }

        return PremiumPlan::denied('own_site_only');
    }

    protected function normalizeListingAttrs(array $v): array
    {
        $photos = $v['photos'] ?? [];
        if (! is_array($photos)) {
            $photos = [];
        }
        $photoUrl = $v['photo_url'] ?? ($photos[0] ?? '');

        $attrs = [
            'name'                  => $v['name'] ?? '',
            'description'           => $v['description'] ?? '',
            'sport_category'        => $v['sport_category'] ?? null,
            'category'              => $v['category'] ?? 'sports',
            'base_price'            => $v['base_price'] ?? 0,
            'slot_duration'         => $v['slot_duration'] ?? 60,
            'buffer_time'           => $v['buffer_time'] ?? 0,
            'start_time_mode'       => $v['start_time_mode'] ?? 'auto',
            'fixed_start_times'     => $v['fixed_start_times'] ?? [],
            // Absent on an update → left alone (filtered out below as null).
            'custom_slots'          => array_key_exists('custom_slots', $v) ? $this->normalizeCustomSlots($v['custom_slots']) : null,
            'capacity'              => $v['capacity'] ?? 0,
            'status'                => $v['status'] ?? 'active',
            'loyalty_points'        => $v['loyalty_points'] ?? 0,
            'calendar_color'        => $v['booking_color'] ?? ($v['calendar_color'] ?? '#10b981'),
            'listing_type'          => $v['listing_type'] ?? 'per_space',
            'max_bookings_per_slot' => $v['max_bookings_per_slot'] ?? 1,
            // Only written when sent, so a partial save cannot silently turn
            // the label back on. Absent means "leave it as it is".
            ...(array_key_exists('show_slot_capacity', $v)
                ? ['show_slot_capacity' => filter_var($v['show_slot_capacity'], FILTER_VALIDATE_BOOLEAN)]
                : []),
            'commission_rate'       => array_key_exists('commission_rate', $v) ? $v['commission_rate'] : null,
            'photo_url'             => $photoUrl ?: '',
            'photos'                => $photos,
            'approval_status'       => $v['approval_status'] ?? null,
            'eligibility_status'    => $v['eligibility_status'] ?? null,
            'admin_note'            => $v['admin_note'] ?? null,
            'previous_snapshot'     => array_key_exists('previous_snapshot', $v) ? $v['previous_snapshot'] : null,
            'pending_content'       => array_key_exists('pending_content', $v) ? $v['pending_content'] : null,
            'show_on_marketplace'   => array_key_exists('show_on_marketplace', $v) ? (bool) $v['show_on_marketplace'] : true,
            'show_on_provider_site' => array_key_exists('show_on_provider_site', $v) ? (bool) $v['show_on_provider_site'] : true,
            // Per-surface visibility. Dropped below when the client sent neither,
            // so a partial update never silently changes who can book a listing.
            'show_on_admin'         => array_key_exists('show_on_admin', $v) ? (bool) $v['show_on_admin'] : true,
            'show_on_customer'      => array_key_exists('show_on_customer', $v) ? (bool) $v['show_on_customer'] : true,
            'extension_pricing'     => $v['extension_pricing'] ?? 'none',
            'extension_value'       => $v['extension_value'] ?? 0,
            'extension_max_slots'   => $v['extension_max_slots'] ?? 1,
            'booking_hold_minutes'  => $v['booking_hold_minutes'] ?? null,
            'submitted_at'          => array_key_exists('submitted_at', $v) ? $v['submitted_at'] : null,
            'submitted_by_name'     => array_key_exists('submitted_by_name', $v) ? $v['submitted_by_name'] : null,
        ];

        // `status` is derived from the two flags by Listing::booted(). When the
        // client sends visibility, its own `status` is stale by definition, so it
        // is dropped rather than written and immediately overwritten.
        if (array_key_exists('show_on_admin', $v) || array_key_exists('show_on_customer', $v)) {
            unset($attrs['status']);
        }

        // On partial updates, omit nullable fields the client did not send so we
        // do not wipe previous_snapshot / submitted_* / etc.
        foreach ([
            'previous_snapshot', 'pending_content', 'submitted_at', 'submitted_by_name',
            'admin_note', 'approval_status', 'eligibility_status', 'commission_rate',
            'booking_hold_minutes', 'sport_category',
            'show_on_admin', 'show_on_customer',
        ] as $optionalKey) {
            if (! array_key_exists($optionalKey, $v)) {
                unset($attrs[$optionalKey]);
            }
        }

        return array_filter(
            $attrs,
            fn ($val, $key) => $val !== null || in_array($key, [
                'commission_rate', 'pending_content', 'previous_snapshot', 'admin_note',
                'submitted_at', 'submitted_by_name', 'approval_status', 'eligibility_status',
            ], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function validatePayload($request, bool $creating)
    {
        $nameRule = $creating ? 'required|string|max:255' : 'sometimes|required|string|max:255';
        $locRule = $creating ? 'required' : 'sometimes|required';

        $data = $this->validatePayloadShape($request, $nameRule, $locRule);

        // Shape is not enough for custom slots: each needs a real length and
        // two slots on the same day must not overlap, or the capacity count
        // would double-sell the hour where they meet.
        if ($problem = $this->customSlotsProblem($data)) {
            throw ValidationException::withMessages(['custom_slots' => [$problem]]);
        }

        return $data;
    }

    /** Custom slots as the model stores them: 'HH:MM' times, integer days, start-sorted. */
    protected function normalizeCustomSlots($raw): array
    {
        $model = new Listing(['custom_slots' => is_array($raw) ? $raw : []]);

        return $model->customSlots();
    }

    /** Why the submitted custom slots cannot be sold, or null when they can. */
    protected function customSlotsProblem(array $data): ?string
    {
        if (($data['start_time_mode'] ?? null) === Listing::START_TIME_CUSTOM && empty($data['custom_slots'])) {
            return 'Add at least one custom time slot, or switch back to automatic slots.';
        }
        $rows = $data['custom_slots'] ?? [];
        if (! is_array($rows) || ! $rows) {
            return null;
        }
        $minutes = fn (string $t) => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $byDay   = [];
        foreach ($rows as $i => $row) {
            $start = Listing::normalizeClock($row['start_time'] ?? null);
            $end   = Listing::normalizeClock($row['end_time'] ?? null);
            $days  = Listing::normalizeDays($row['days'] ?? []);
            $n     = $i + 1;
            if ($start === null || $end === null) {
                return "Slot {$n}: enter a start and an end time.";
            }
            if ($start === $end) {
                return "Slot {$n}: the end time must be after the start time.";
            }
            if (! $days) {
                return "Slot {$n}: pick at least one day.";
            }
            $s = $minutes($start);
            $e = $minutes($end);
            if ($e <= $s) {
                $e += 24 * 60;   // 00:00 or crosses midnight
            }
            foreach ($days as $d) {
                foreach ($byDay[$d] ?? [] as [$os, $oe, $on]) {
                    if ($s < $oe && $e > $os) {
                        return "Slot {$n} overlaps slot {$on} on the same day. Slots on one day must not overlap.";
                    }
                }
                $byDay[$d][] = [$s, $e, $n];
            }
        }

        return null;
    }

    protected function validatePayloadShape($request, string $nameRule, string $locRule): array
    {
        return $request->validate([
            'name'                    => $nameRule,
            'location_id'             => $locRule . '|integer|exists:venues,id',
            'venue_id'                => 'nullable|integer|exists:venues,id',
            'description'             => 'nullable|string',
            'sport_category'          => 'nullable|string|max:255',
            'category'                => 'nullable|string|max:64',
            'base_price'              => 'nullable|numeric|min:0',
            'slot_duration'           => 'nullable|integer|min:5',
            'buffer_time'             => 'nullable|integer|min:0',
            'start_time_mode'         => 'nullable|in:auto,fixed,custom',
            'fixed_start_times'       => 'nullable|array',
            'custom_slots'            => 'nullable|array|max:200',
            // Shape only - Listing::normalizeClock does the real parsing, and
            // customSlotsProblem() rejects whatever it cannot read. date_format:H:i
            // refused '5:00 PM' outright, which is the one form a 12-hour picker
            // can send without its meaning going missing on the way here.
            'custom_slots.*.start_time' => ['required', 'string', 'regex:/^\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?\s*$/i'],
            'custom_slots.*.end_time'   => ['required', 'string', 'regex:/^\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?\s*$/i'],
            'custom_slots.*.days'       => 'required|array|min:1',
            'custom_slots.*.days.*'     => 'integer|between:0,6',
            'capacity'                => 'nullable|integer|min:0',
            'status'                  => 'nullable|in:active,inactive',
            // A listing is bookable per SURFACE now, not simply active/inactive.
            // Omitting both leaves the existing flags alone (the model seeds them
            // from `status` for callers that predate them).
            'show_on_admin'           => 'sometimes|boolean',
            'show_on_customer'        => 'sometimes|boolean',
            'loyalty_points'          => 'nullable|integer|min:0',
            'booking_color'           => 'nullable|string|max:32',
            'listing_type'            => 'nullable|string|max:64',
            'max_bookings_per_slot'   => 'nullable|integer|min:1',
            'show_slot_capacity'      => 'sometimes|boolean',
            'commission_rate'         => 'nullable|numeric',
            'photo_url'               => 'nullable|string|max:1024',
            'photos'                  => 'nullable|array',
            'approval_status'         => 'nullable|in:pending,approved,rejected',
            'eligibility_status'      => 'nullable|in:eligible,paused,ineligible',
            'admin_note'              => 'nullable|string',
            'previous_snapshot'       => 'nullable|array',
            'pending_content'         => 'nullable|array',
            'show_on_marketplace'     => 'nullable|boolean',
            'show_on_provider_site'   => 'nullable|boolean',
            'extension_pricing'       => 'nullable|string|max:32',
            'extension_value'         => 'nullable|numeric',
            'extension_max_slots'     => 'nullable|integer|min:1',
            'booking_hold_minutes'    => 'nullable|integer|min:0',
            'submitted_at'            => 'nullable|date',
            'submitted_by_name'       => 'nullable|string|max:255',
            'hours'                   => 'nullable|array',
            'pricing_groups'          => 'nullable|array',
            'pricing_cells'           => 'nullable|array',
            'payment'                 => 'nullable|array',
            'addons'                  => 'nullable|array',
            'resources'               => 'nullable|array',
        ]);
    }

    /**
     * Tell Bookboll staff a listing is waiting on them.
     *
     * Approval alerts only ever ran in the OTHER direction — ApprovalsDashboardService
     * tells the provider once staff decide — so a submission sat in the queue with
     * nobody told it was there.
     *
     * Only marketplace-bound listings raise one: a provider's own-site listing needs
     * no Bookboll review, and alerting on those would bury the real queue. Failure to
     * notify must never fail the save, so it is best-effort.
     */
    protected function alertPlatformIfAwaitingReview(?Listing $listing, bool $isNew): void
    {
        if (! $listing) {
            return;
        }
        if (($listing->approval_status ?: Listing::APPROVAL_APPROVED) !== Listing::APPROVAL_PENDING) {
            return;
        }
        if ($listing->show_on_marketplace === false) {
            return;   // own-site only — Bookboll has nothing to review
        }
        // Nobody is asked to review their own work. A platform admin creating
        // or copying a listing was being sent an approval notification for it,
        // then approving it in the same dashboard — a review that reviews
        // nothing. Providers still go to the queue exactly as before.
        if (auth()->user()?->hasCapability(Capabilities::APPROVE_LISTINGS)) {
            return;
        }

        try {
            $venue = $listing->venue ?: Venue::find($listing->venue_id);
            $venueName = $venue?->name ?: 'a venue';

            AdminAlerts::toPlatform(
                'approval',
                'info',
                ($isNew ? 'New listing awaiting review — ' : 'Listing resubmitted for review — ').($listing->name ?: 'Untitled'),
                $venueName,
                '/approvals',
                $venue?->country_id ? (int) $venue->country_id : null,
            );
        } catch (\Throwable $e) {
            Log::warning('listing approval alert failed: '.$e->getMessage());
        }
    }

    protected function assertAccess(int $venueId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
        }
        if (! Venue::find($venueId)) {
            return sendError('Venue not found', [], 404);
        }

        // One authority for "may I touch this venue": ownership, explicit
        // assignment and platform jurisdiction all live in AccessScope. Asking
        // the user_venue pivot directly missed venues a provider OWNS.
        if (AccessScope::canTouchVenue($user, $venueId)) {
            return null;
        }

        return sendError('You do not have access to this venue.', [], 403);
    }

    protected function normalizeHm(?string $t): string
    {
        if (! $t) {
            return '09:00';
        }
        $t = trim($t);
        if (strlen($t) >= 5) {
            return substr($t, 0, 5);
        }

        return $t;
    }

    protected function fmtHm($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $s = (string) $value;

        return strlen($s) >= 5 ? substr($s, 0, 5) : $s;
    }
}
