<?php
namespace App\Services;

use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\HourPeriods;
use App\Support\QatarMarketGeo;
use Nnjeim\World\Models\City;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VenueService
{
    public function __construct(
        protected Venue $venueModel,
        protected LocationService $locationService,
    ) {}

    public function all($request, $applyVenueFilter = true)
    {
        $search           = $request->search ?? null;
        $schedule         = $request->boolean('schedule', true);
        $pagination         = $request->boolean('pagination', false);
        $records_per_page = $request->records_per_page ?? 20;
        $countryId        = $request->country_id ?? null;
        $stateId          = $request->state_id ?? null;
        $cityId           = $request->city_id ?? null;

        $query = $this->venueModel::when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('min_advance_booking', 'LIKE', "%{$search}%")
                        ->orWhere('max_bookings_per_slot', 'LIKE', "%{$search}%")
                        ->orWhereHas('country', fn ($countryQuery) => $countryQuery->where('name', 'LIKE', "%{$search}%"))
                        ->orWhereHas('state', fn ($stateQuery) => $stateQuery->where('name', 'LIKE', "%{$search}%"))
                        ->orWhereHas('city', fn ($cityQuery) => $cityQuery->where('name', 'LIKE', "%{$search}%"));
                });
            })
            ->when($countryId, fn ($query) => $query->where('country_id', $countryId))
            ->when($stateId, fn ($query) => $query->where('state_id', $stateId))
            ->when($cityId, function ($query) use ($cityId, $request) {
                $isCustomer = $request->header('from') != 'admin-web';
                if ($isCustomer && QatarMarketGeo::touchesQatar([(int) $cityId])) {
                    QatarMarketGeo::constrainVenues($query, [(int) $cityId]);
                } else {
                    $query->where('city_id', $cityId);
                }
            })
            ->with(['country:id,name,iso2', 'state:id,name', 'city:id,name'])
            ->orderBy('sort_order', 'ASC');

        if ($schedule) {
            $query->with(['schedules']);
        }

        if ($applyVenueFilter) {
            $query = applyVenueFilter($query, 'id');
        }

        if ($request->header('from') != 'admin-web') {
            $query->active();
        }

        $venues = $pagination ? $query->paginate($records_per_page) : $query->get();

        return sendSuccess('Venues fetched successfully', compact('venues'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'status'                => ['required', 'in:active,inactive'],
            'maps_url'              => ['required', 'url'],
            'country_id'            => ['required', 'integer', 'exists:countries,id'],
            'state_id'              => ['nullable', 'integer', 'exists:states,id'],
            'city_id'               => ['required', 'integer', 'exists:cities,id'],
            'description'           => ['nullable', 'string'],
            'min_advance_booking'   => ['required', 'integer', 'min:0'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1'],
            'schedule'              => ['required', 'array', 'min:1'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
        ]);

        $this->locationService->validateVenueLocation($formData);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    continue;
                }

                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    continue;
                }

                if ($endMinutes === 0 && $startMinutes > 0) {
                    continue;
                }

                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    return sendError("Schedule entry #{$index}: end_time must be after start_time", [], 422);
                }
            }
        }

        $formData['sort_order'] = $this->venueModel->max('id') + 1;

        $venue = $this->venueModel::create($formData);

        foreach ($schedules as $schedule) {
            $scheduleData = [
                'day'        => $schedule['day'],
                'is_open'    => $schedule['is_open'],
                'start_time' => $schedule['is_open'] ? ($schedule['start_time'] ?? null) : null,
                'end_time'   => $schedule['is_open'] ? ($schedule['end_time'] ?? null) : null,
            ];
            $venue->schedules()->create($scheduleData);
        }
        $venue->load(['schedules', 'country:id,name,iso2', 'state:id,name', 'city:id,name']);

        return sendSuccess('Venue added successfully', compact('venue'));
    }

    public function update($request, $id)
    {
        $venue    = $this->venueModel->findOrFail($id);
        $formData = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'status'                => ['required', 'in:active,inactive'],
            'maps_url'              => ['required', 'url'],
            'country_id'            => ['required', 'integer', 'exists:countries,id'],
            'state_id'              => ['nullable', 'integer', 'exists:states,id'],
            'city_id'               => ['required', 'integer', 'exists:cities,id'],
            'description'           => ['nullable', 'string'],
            'min_advance_booking'   => ['required', 'integer', 'min:0'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1'],
            'schedule'              => ['required', 'array', 'min:1'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
        ]);

        $this->locationService->validateVenueLocation($formData);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    continue;
                }

                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    continue;
                }

                if ($endMinutes === 0 && $startMinutes > 0) {
                    continue;
                }

                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    return sendError("Schedule entry #{$index}: end_time must be after start_time", [], 422);
                }
            }
        }

        $venue->update($formData);

        $this->syncSchedules($venue, $schedules);

        $venue->load(['schedules', 'country:id,name,iso2', 'state:id,name', 'city:id,name']);

        return sendSuccess('Venue updated successfully', compact('venue'));
    }

    public function getVenue($id)
    {
        $venue = $this->venueModel::with([
            'schedules',
            'users',
            'listings',
            'country:id,name,iso2',
            'state:id,name',
            'city:id,name',
        ])->findOrFail($id);

        $user = auth()->user();
        if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
            // AccessScope: ownership + assignment + jurisdiction, one authority.
            $venueIds = AccessScope::allowedVenueIds($user) ?? [];
            if (! in_array($venue->id, $venueIds)) {
                return sendError('You do not have access to this venue', [], 403);
            }
        }

        return sendSuccess('Venue fetched successfully', compact('venue'));
    }

    public function updateSortingOrder($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:venues,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates  = $request->input('updates');
        $venueIds = collect($updates)->pluck('id')->toArray();

        if (count(array_unique($venueIds)) !== count($venueIds)) {
            return sendError('Each venue can only appear once in the updates.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders)) {
            return sendError('Sort orders must be unique within the request.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->venueModel::where('id', $update['id'])->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', ['data' => ['updated_venues' => $venueIds]]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update venue sort order: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to update sort order: ' . $e->getMessage());
        }
    }

    public function moveOnePosition($id, $request)
    {
        $request->validate([
            'direction' => 'required|in:up,down',
        ]);

        $venue = $this->venueModel::find($id);
        if (! $venue) {
            return sendError('Venue not found.', [], 404);
        }

        $direction   = $request->input('direction');
        $currentSort = (int) ($venue->sort_order ?? 0);

        $neighborQuery = $this->venueModel::query();
        if ($direction === 'up') {
            $neighbor = $neighborQuery->where('sort_order', '<', $currentSort)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->first();
        } else {
            $neighbor = $neighborQuery->where('sort_order', '>', $currentSort)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
        }

        if (! $neighbor) {
            return sendError('No adjacent venue to swap with.', [], 422);
        }

        DB::beginTransaction();

        try {
            $neighborSort = (int) ($neighbor->sort_order ?? 0);

            $venue->update(['sort_order' => $neighborSort]);
            $neighbor->update(['sort_order' => $currentSort]);

            DB::commit();

            return sendSuccess('Venue moved successfully.', [
                'data' => [
                    'venue'    => ['id' => $venue->id, 'sort_order' => $neighborSort],
                    'neighbor' => ['id' => $neighbor->id, 'sort_order' => $currentSort],
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to move venue: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to move venue: ' . $e->getMessage());
        }
    }

    protected function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return 0;
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }

    protected function syncSchedules($venue, array $newSchedules)
    {
        $existingSchedules = $venue->schedules()->get();

        $normalizedNewSchedules = collect($newSchedules)->map(function ($schedule) {
            return [
                'day'        => $schedule['day'],
                'is_open'    => $schedule['is_open'],
                'start_time' => $schedule['is_open'] ? ($schedule['start_time'] ?? null) : null,
                'end_time'   => $schedule['is_open'] ? ($schedule['end_time'] ?? null) : null,
            ];
        });

        $normalizedExistingSchedules = $existingSchedules->map(function ($schedule) {
            $startTime = $schedule->start_time ?
            (is_string($schedule->start_time) ? $schedule->start_time : $schedule->start_time->format('H:i'))
                : null;
            $endTime = $schedule->end_time ?
            (is_string($schedule->end_time) ? $schedule->end_time : $schedule->end_time->format('H:i'))
                : null;

            return [
                'id'         => $schedule->id,
                'day'        => $schedule->day,
                'is_open'    => $schedule->is_open,
                'start_time' => $startTime,
                'end_time'   => $endTime,
            ];
        });

        $schedulesToDelete = $normalizedExistingSchedules->filter(function ($existing) use ($normalizedNewSchedules) {
            return ! $normalizedNewSchedules->contains(function ($new) use ($existing) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        $schedulesToCreate = $normalizedNewSchedules->filter(function ($new) use ($normalizedExistingSchedules) {
            return ! $normalizedExistingSchedules->contains(function ($existing) use ($new) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        if ($schedulesToDelete->isNotEmpty()) {
            $idsToDelete = $schedulesToDelete->pluck('id')->toArray();
            $venue->schedules()->whereIn('id', $idsToDelete)->whereNull('listing_id')->delete();
        }

        foreach ($schedulesToCreate as $schedule) {
            $venue->schedules()->create($schedule);
        }
    }

    protected function schedulesMatch($schedule1, $schedule2)
    {
        return $schedule1['day'] === $schedule2['day'] &&
            $schedule1['is_open'] === $schedule2['is_open'] &&
            $schedule1['start_time'] === $schedule2['start_time'] &&
            $schedule1['end_time'] === $schedule2['end_time'];
    }

    // ── New admin dashboard (apps/admin LocationModal / Listings) ──────────────

    public const DAY_NAMES = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    /**
     * Venue cards for the Listings page, paginated ON THE SERVER.
     *
     * This is the page's DEFAULT view, and it previously loaded every venue at once
     * with no paging at all. Each card also reports how many listings it holds, so
     * the page need not hold every listing just to print a count.
     *
     * Callers that pass no page/per_page still get the whole list, so the venue
     * pickers elsewhere are unaffected.
     */
    public function dashboardIndex($request)
    {
        $query = $this->venueModel::query()
            ->orderBy('sort_order')
            ->orderBy('created_at');

        // Same row scope as every other admin surface: ownership, jurisdiction, pins.
        AccessScope::applyVenue($query, 'id', auth()->user());

        if ($request->filled('owner_user_id')) {
            // Owner key OR business link — see Venue::scopeOwnedBy.
            $query->ownedBy((int) $request->owner_user_id);
        }
        // The dashboard sends multi-selects as one comma-joined value, so
        // "2,5" has to become [2, 5] — `where('country_id', '2,5')` matched
        // nothing and `(array) '2,5'` was ['2,5'], which matched nothing either.
        $arr = function ($v) {
            if (is_array($v)) return array_values(array_filter($v, fn ($x) => $x !== '' && $x !== null));
            if (is_string($v) && $v !== '') return array_values(array_filter(explode(',', $v)));
            return [];
        };

        $cityIds = $arr($request->input('city_id'));
        if ($cityIds) {
            $query->whereIn('city_id', $cityIds);
        }
        // The page derives a venue's country from its CITY (see countryOfLocation
        // in Listings.vue), not from venues.country_id, which can be stale. Resolve
        // it the same way so both surfaces agree.
        if ($countryIds = $arr($request->input('country_id'))) {
            $countryCityIds = City::whereIn('country_id', $countryIds)->pluck('id');
            $query->whereIn('city_id', $countryCityIds->all() ?: [0]);
        }
        if ($request->filled('venue_id')) {
            $query->where('id', $request->venue_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }

        // `match_listings` is the grouped Listings view: a venue card is shown only
        // when it still holds a listing matching the content filters, and a bare
        // search may also match the venue's own name. Every other caller keeps the
        // plain name/address/phone search below.
        $matchListings = $request->boolean('match_listings');
        $search = trim((string) $request->input('search', ''));

        if ($matchListings) {
            $hasContentFilter = ($request->filled('category') && $request->category !== 'all')
                || ($request->filled('sport') && $request->sport !== 'all')
                || $request->filled('placement');

            if ($hasContentFilter || $search !== '') {
                $venueIds = app(ListingDashboardService::class)->matchingVenueIds($request)->all();

                if ($hasContentFilter) {
                    $query->whereIn('id', $venueIds ?: [0]);
                } else {
                    // Search only: a venue with no listings at all still shows when
                    // its own name matches, exactly as filteredLocations does.
                    $query->where(fn ($q) => $q
                        ->whereIn('id', $venueIds ?: [0])
                        ->orWhere('name', 'LIKE', "%{$search}%"));
                }
            }
        } elseif ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('address', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // Callers that ask for no page get everything, as before — venue pickers
        // elsewhere depend on that shape.
        if (! $request->filled('page') && ! $request->filled('per_page')) {
            return sendSuccess('Venues dashboard fetched', [
                'locations' => $this->mapVenueRows($query->get()),
            ]);
        }

        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);
        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return sendSuccess('Venues dashboard fetched', [
            'locations'  => $this->mapVenueRows(collect($page->items())),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'from'         => $page->firstItem(),
                'to'           => $page->lastItem(),
            ],
        ]);
    }

    /**
     * Map venue rows and attach the listing count each card shows, in one grouped
     * query rather than one per card.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapVenueRows($rows): array
    {
        $ids = collect($rows)->pluck('id');
        $counts = $ids->isEmpty()
            ? collect()
            : \App\Models\Listing::whereIn('venue_id', $ids)
                ->selectRaw('venue_id, count(*) as c')
                ->groupBy('venue_id')
                ->pluck('c', 'venue_id');

        return collect($rows)->map(fn (Venue $v) => array_merge(
            $this->mapDashboardVenue($v),
            ['listing_count' => (int) ($counts[$v->id] ?? 0)]
        ))->values()->all();
    }

    public function dashboardShow($id)
    {
        $venue = $this->venueModel::with(['schedules', 'listings.sportsCategory'])->find($id);
        if (! $venue) {
            return sendError('Venue not found', [], 404);
        }
        if ($err = $this->assertDashboardVenueAccess((int) $venue->id)) {
            return $err;
        }

        $location = $this->mapDashboardVenue($venue);
        // `schedules` is the single source of venue hours (rows with no
        // listing_id). Stored one row per period, and imported data often holds a
        // row per half hour — a venue open all day is 48 rows and the editor would
        // draw 48 lines — so merge for display. The rows themselves are untouched
        // and the bookable window is identical either way.
        $hours = HourPeriods::mergeDashboardRows(
            $venue->schedules
                ->map(fn ($row) => $row->toDashboardLocationHour())
                ->filter(fn ($r) => $r['day_of_week'] !== null)
                ->values()
                ->all()
        );

        $listings = $venue->listings->map(fn ($l) => [
            'id'             => (string) $l->id,
            'name'           => $l->name,
            'sport_category' => $l->sport_category ?: ($l->sportsCategory?->name ?? ''),
        ])->values()->all();

        return sendSuccess('Venue fetched', [
            'location' => $location,
            'hours'    => $hours,
            'listings' => $listings,
        ]);
    }

    public function dashboardStore($request)
    {
        $validated = $this->validateDashboardVenue($request);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        DB::beginTransaction();
        try {
            $attrs = $this->normalizeDashboardVenueAttrs($validated);
            $attrs['sort_order'] = (int) ($this->venueModel->max('sort_order') ?? 0) + 1;
            $attrs['approval_status'] = $attrs['approval_status'] ?? Venue::APPROVAL_APPROVED;
            $attrs['eligibility_status'] = $attrs['eligibility_status'] ?? Venue::ELIGIBILITY_ELIGIBLE;
            $attrs['min_advance_booking'] = $attrs['min_advance_booking'] ?? 2;
            $attrs['max_bookings_per_slot'] = $attrs['max_bookings_per_slot'] ?? 1;
            if (empty($attrs['status'])) {
                $attrs['status'] = Venue::ACTIVE;
            }

            // A provider-side creator owns what they create. Without this the venue
            // is saved with no owner and no user_venue row, so the very next request
            // — loading it back, saving its schedule — is refused as "not your venue".
            // A provider may not hand the venue to someone else, so their own id wins
            // over anything posted; platform roles keep the supplied owner.
            $actor = auth()->user();
            $ownerId = AccessScope::ownerUserId($actor);
            if ($ownerId !== null) {
                $attrs['owner_user_id'] = $ownerId;
            }

            $venue = $this->venueModel::create($attrs);

            // The business link too, where the schema has it: for an ownerless
            // business this is the only key its other members resolve through.
            $businessId = AccessScope::businessId($actor);
            if ($businessId && schemaHas('venues', 'business_id')) {
                $venue->forceFill(['business_id' => $businessId])->save();
            }

            // Both notions of "my venue" are written: AccessScope reads
            // venues.owner_user_id, assertDashboardVenueAccess reads the pivot.
            if ($actor) {
                DB::table('user_venue')->updateOrInsert(
                    ['user_id' => $actor->id, 'venue_id' => $venue->id],
                    []
                );
            }

            $this->syncDashboardHours($venue, $validated['hours'] ?? []);

            DB::commit();

            $venue->load('schedules');

            return sendSuccess('Venue created', [
                'location' => $this->mapDashboardVenue($venue),
                'hours'    => $this->venueHourRows($venue),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardStore venue failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to create venue');
        }
    }

    public function dashboardUpdate($request, $id)
    {
        $venue = $this->venueModel::find($id);
        if (! $venue) {
            return sendError('Venue not found', [], 404);
        }
        if ($err = $this->assertDashboardVenueAccess((int) $venue->id)) {
            return $err;
        }

        $validated = $this->validateDashboardVenue($request, false);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        DB::beginTransaction();
        try {
            $attrs = $this->normalizeDashboardVenueAttrs($validated);
            // These may legitimately be set to null (a Bookboll-owned venue has no
            // owner_user_id, say) — but only when the client actually SENT the key.
            // Treating "absent" as "null" wiped owner_user_id on every ordinary save,
            // which silently un-owned the venue: the next list call no longer showed
            // it and the next update was refused as someone else's.
            $nullable = [
                'owner_user_id', 'maps_url', 'latitude', 'longitude',
                'timezone_override', 'currency_override',
            ];
            $keepNull = array_values(array_filter(
                $nullable,
                fn ($k) => array_key_exists($k, $validated)
            ));
            $attrs = array_filter(
                $attrs,
                fn ($v, $k) => $v !== null || in_array($k, $keepNull, true),
                ARRAY_FILTER_USE_BOTH
            );

            // A provider may not reassign their venue to another account.
            if (AccessScope::ownerUserId(auth()->user()) !== null) {
                unset($attrs['owner_user_id']);
            }

            $venue->update($attrs);
            if (array_key_exists('hours', $validated)) {
                $this->syncDashboardHours($venue, $validated['hours'] ?? []);
            }

            DB::commit();

            $venue->load('schedules');

            return sendSuccess('Venue updated', [
                'location' => $this->mapDashboardVenue($venue->fresh()),
                'hours'    => $this->venueHourRows($venue),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardUpdate venue failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to update venue');
        }
    }

    public function dashboardDestroy($id)
    {
        $venue = $this->venueModel::find($id);
        if (! $venue) {
            return sendError('Venue not found', [], 404);
        }
        if ($err = $this->assertDashboardVenueAccess((int) $venue->id)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $venue->schedules()->delete();
            $venue->delete();
            DB::commit();

            return sendSuccess('Venue deleted');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardDestroy venue failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to delete venue');
        }
    }

    public function dashboardPatch($request, $id)
    {
        $venue = $this->venueModel::find($id);
        if (! $venue) {
            return sendError('Venue not found', [], 404);
        }
        if ($err = $this->assertDashboardVenueAccess((int) $venue->id)) {
            return $err;
        }

        $data = $request->validate([
            'status'             => 'sometimes|in:active,inactive',
            'eligibility_status' => 'sometimes|in:eligible,paused,ineligible',
            'admin_note'         => 'nullable|string',
            'previous_snapshot'  => 'nullable|array',
            'sort_order'         => 'sometimes|integer|min:0',
        ]);

        $venue->update($data);

        return sendSuccess('Venue patched', [
            'location' => $this->mapDashboardVenue($venue->fresh()),
        ]);
    }

    public function dashboardSort($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:venues,id',
            'updates.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->input('updates') as $update) {
            if ($err = $this->assertDashboardVenueAccess((int) $update['id'])) {
                return $err;
            }
        }

        DB::beginTransaction();
        try {
            foreach ($request->input('updates') as $update) {
                $this->venueModel::where('id', $update['id'])->update(['sort_order' => $update['sort_order']]);
            }
            DB::commit();

            return sendSuccess('Sort order updated');
        } catch (\Throwable $e) {
            DB::rollBack();

            return sendError('Failed to update sort order');
        }
    }

    public function applyHoursToListings($request, $id)
    {
        $venue = $this->venueModel::with('schedules')->find($id);
        if (! $venue) {
            return sendError('Venue not found', [], 404);
        }
        if ($err = $this->assertDashboardVenueAccess((int) $venue->id)) {
            return $err;
        }

        $data = $request->validate([
            'listing_ids'   => 'required|array|min:1',
            'listing_ids.*' => 'required|integer|exists:listings,id',
            'hours'         => 'nullable|array',
        ]);

        $hoursUi = $data['hours'] ?? null;
        if ($hoursUi === null) {
            // Rebuild the UI shape from the venue's own hours, merged — copying
            // 48 half-hour rows onto every listing would just spread the
            // fragmentation, and the window is the same either way.
            $byDay = [];
            foreach ($venue->schedules as $row) {
                $dow = $row->day_of_week;
                if ($dow === null) {
                    continue;
                }
                if (! isset($byDay[$dow])) {
                    $byDay[$dow] = ['day_of_week' => $dow, 'is_open' => false, 'periods' => []];
                }
                if ($row->is_open) {
                    $byDay[$dow]['is_open'] = true;
                    $byDay[$dow]['periods'][] = [
                        'open_time'  => $this->fmtHm($row->open_time) ?? '09:00',
                        'close_time' => $this->fmtHm($row->close_time) ?? '23:00',
                    ];
                }
            }
            foreach ($byDay as $dow => $day) {
                $byDay[$dow]['periods'] = HourPeriods::merge($day['periods']);
            }
            $hoursUi = array_values($byDay);
        }

        $listingIds = $data['listing_ids'];
        $owned = \App\Models\Listing::where('venue_id', $venue->id)->whereIn('id', $listingIds)->pluck('id')->all();
        if (count($owned) !== count($listingIds)) {
            return sendError('One or more listings do not belong to this venue.', [], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($owned as $listingId) {
                \App\Models\Schedule::where('listing_id', $listingId)->delete();
                foreach ($this->expandHoursToScheduleRows($hoursUi, (int) $venue->id, (int) $listingId) as $row) {
                    \App\Models\Schedule::create($row);
                }
            }
            DB::commit();

            return sendSuccess('Hours applied to listings', ['listing_ids' => $owned]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('applyHoursToListings failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to apply hours to listings');
        }
    }

    protected function validateDashboardVenue($request, bool $requireHours = true)
    {
        $rules = [
            'name'               => 'required|string|max:255',
            'city_id'            => 'required|integer|exists:cities,id',
            'country_id'         => 'nullable|integer|exists:countries,id',
            'state_id'           => 'nullable|integer|exists:states,id',
            'owner_user_id'      => 'nullable|integer|exists:users,id',
            'address'            => 'nullable|string|max:255',
            'phone'              => 'nullable|string|max:40',
            'description'        => 'nullable|string',
            'google_maps_url'    => 'nullable|string|max:2048',
            'maps_url'           => 'nullable|string|max:2048',
            'photos'             => 'nullable|array',
            'photos.*'           => 'nullable|string',
            'photo_url'          => 'nullable|string|max:1024',
            'status'             => 'nullable|in:active,inactive',
            'approval_status'    => 'nullable|in:pending,approved,rejected',
            'eligibility_status' => 'nullable|in:eligible,paused,ineligible',
            // Ranges, not just "numeric". The dashboard now lets a provider TYPE the
            // coordinates instead of only dragging the pin, so a transposed pair
            // (74.32, 31.48 — Lahore's numbers the wrong way round) or a stray digit
            // is a real possibility. An impossible point would be stored happily and
            // then silently poison the storefront's distance badge.
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
            'timezone_override'  => 'nullable|string|max:64',
            'currency_override'  => 'nullable|string|max:8',
            'min_advance_booking'=> 'nullable|integer|min:0',
            'max_bookings_per_slot' => 'nullable|integer|min:1',
            'hours'              => ($requireHours ? 'nullable' : 'nullable') . '|array',
            'hours.*.day_of_week'=> 'required_with:hours|integer|min:0|max:6',
            'hours.*.is_open'    => 'required_with:hours|boolean',
            'hours.*.periods'    => 'nullable|array',
            'hours.*.periods.*.open_time'  => 'nullable|string',
            'hours.*.periods.*.close_time' => 'nullable|string',
        ];

        return $request->validate($rules);
    }

    /**
     * Short Google Maps share links (maps.app.goo.gl/…) carry no coordinates;
     * the browser cannot follow their redirect cross-origin, so it is done here.
     */
    public function resolveMapsLink($request)
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = trim($validated['url']);
        if (! \App\Support\GoogleMapsLink::isAllowedUrl($url)) {
            return sendError('Paste a Google Maps link (google.com/maps or maps.app.goo.gl).', ['url' => ['Not a Google Maps link.']], 422);
        }

        $result = \App\Support\GoogleMapsLink::resolve($url);

        return sendSuccess('Maps link resolved', $result, 200, false);
    }

    protected function normalizeDashboardVenueAttrs(array $validated): array
    {
        $cityId = (int) $validated['city_id'];
        $city = \Nnjeim\World\Models\City::find($cityId);

        $mapsUrl = $validated['google_maps_url'] ?? $validated['maps_url'] ?? null;
        if ($mapsUrl === '') {
            $mapsUrl = null;
        }

        $photos = $validated['photos'] ?? [];
        if (! is_array($photos)) {
            $photos = [];
        }
        $photoUrl = $validated['photo_url'] ?? ($photos[0] ?? '');

        return [
            'name'                  => $validated['name'],
            'city_id'               => $cityId,
            'state_id'              => $validated['state_id'] ?? $city?->state_id,
            'country_id'            => $validated['country_id'] ?? $city?->country_id,
            'owner_user_id'         => $validated['owner_user_id'] ?? null,
            'address'               => $validated['address'] ?? '',
            'phone'                 => $validated['phone'] ?? '',
            'description'           => $validated['description'] ?? '',
            'maps_url'              => $mapsUrl,
            'photos'                => $photos,
            'photo_url'             => $photoUrl ?: '',
            'status'                => $validated['status'] ?? Venue::ACTIVE,
            'approval_status'       => $validated['approval_status'] ?? null,
            'eligibility_status'    => $validated['eligibility_status'] ?? null,
            'latitude'              => $validated['latitude'] ?? null,
            'longitude'             => $validated['longitude'] ?? null,
            'timezone_override'     => $validated['timezone_override'] ?? null,
            'currency_override'     => $validated['currency_override'] ?? null,
            'min_advance_booking'   => $validated['min_advance_booking'] ?? null,
            'max_bookings_per_slot' => $validated['max_bookings_per_slot'] ?? null,
        ];
    }

    /**
     * A venue's opening hours in the shape the dashboard reads, merged for
     * display. Venue hours are `schedules` rows with no listing_id.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function venueHourRows(Venue $venue): array
    {
        return HourPeriods::mergeDashboardRows(
            $venue->schedules
                ->map(fn ($row) => $row->toDashboardLocationHour())
                ->filter(fn ($r) => $r['day_of_week'] !== null)
                ->values()
                ->all()
        );
    }

    /**
     * Replace a venue's opening hours from the UI payload.
     *
     * Hours live in ONE place: `schedules` rows with a null listing_id, the same
     * table a listing's own hours live in. Deleting the venue-level rows and
     * rewriting them is the whole operation — there is no second table to keep
     * in step, and so no way for the two to disagree.
     */
    public function syncDashboardHours(Venue $venue, array $hoursUi): void
    {
        $venue->schedules()->whereNull('listing_id')->delete();

        if (! $hoursUi) {
            // Default 24/7 like LocationModal new venue.
            $hoursUi = [];
            for ($i = 0; $i < 7; $i++) {
                $hoursUi[] = [
                    'day_of_week' => $i,
                    'is_open'     => true,
                    'periods'     => [['open_time' => '00:00', 'close_time' => '00:00']],
                ];
            }
        }

        foreach ($hoursUi as $day) {
            $dow = (int) ($day['day_of_week'] ?? -1);
            $dayName = self::DAY_NAMES[$dow] ?? null;
            if (! $dayName) {
                continue;
            }
            $isOpen = (bool) ($day['is_open'] ?? false);

            if (! $isOpen) {
                $venue->schedules()->create([
                    'day'                   => $dayName,
                    'is_open'               => false,
                    'start_time'            => null,
                    'end_time'              => null,
                    'inherit_from_location' => true,
                ]);
                continue;
            }

            $periods = $day['periods'] ?? [];
            if (! $periods) {
                $periods = [['open_time' => '09:00', 'close_time' => '23:00']];
            }

            foreach ($periods as $p) {
                $open  = $this->normalizeHm($p['open_time'] ?? '09:00');
                $close = $this->normalizeHm($p['close_time'] ?? '23:00');
                $venue->schedules()->create([
                    'day'                   => $dayName,
                    'is_open'               => true,
                    'start_time'            => $open,
                    'end_time'              => $close,
                    'inherit_from_location' => true,
                ]);
            }
        }
    }

    protected function expandHoursToScheduleRows(array $hoursUi, int $venueId, int $listingId): array
    {
        $rows = [];
        foreach ($hoursUi as $day) {
            $dow = (int) ($day['day_of_week'] ?? -1);
            $dayName = self::DAY_NAMES[$dow] ?? null;
            if (! $dayName) {
                continue;
            }
            $isOpen = (bool) ($day['is_open'] ?? false);
            if (! $isOpen) {
                $rows[] = [
                    'venue_id'              => $venueId,
                    'listing_id'            => $listingId,
                    'day'                   => $dayName,
                    'is_open'               => false,
                    'start_time'            => null,
                    'end_time'              => null,
                    'inherit_from_location' => false,
                ];
                continue;
            }
            $periods = $day['periods'] ?? [['open_time' => '09:00', 'close_time' => '23:00']];
            foreach ($periods as $p) {
                $rows[] = [
                    'venue_id'              => $venueId,
                    'listing_id'            => $listingId,
                    'day'                   => $dayName,
                    'is_open'               => true,
                    'start_time'            => $this->normalizeHm($p['open_time'] ?? '09:00'),
                    'end_time'              => $this->normalizeHm($p['close_time'] ?? '23:00'),
                    'inherit_from_location' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * The name to show for whoever operates this venue.
     *
     * The business row first, because a venue can belong to a business that has
     * no owner user at all; the owner's own business name, then their name, as
     * fallbacks for rows that predate business_id.
     */
    protected function businessNameFor(Venue $v): ?string
    {
        if ($v->business_id) {
            $name = \App\Models\Business::where('id', $v->business_id)->value('name');
            if ($name) {
                return (string) $name;
            }
        }

        if (! $v->owner_user_id) {
            return null;
        }

        $owner = \App\Models\User::with('ownedBusiness')->find($v->owner_user_id);

        return $owner?->ownedBusiness?->name ?: ($owner?->name ?: null);
    }

    protected function mapDashboardVenue(Venue $v): array
    {
        $photos = $v->photos;
        if (! is_array($photos)) {
            $photos = [];
        }

        return [
            'id'                 => (string) $v->id,
            'name'               => $v->name,
            'owner_user_id'      => $v->owner_user_id ? (string) $v->owner_user_id : null,
            // The business this venue belongs to, and its name.
            //
            // Neither was returned, so the dashboard could only name a venue's
            // owner by looking the user id up in a list it happens to hold —
            // and after a transfer to a business whose owner is not in that
            // list (an ownerless business, say) it had nothing to show and
            // printed a dash. readmeAlamgir asks for business_id on venues for
            // exactly this reason.
            'business_id'        => $v->business_id ? (string) $v->business_id : null,
            'business_name'      => $this->businessNameFor($v),
            'operator_type'      => $v->operator_type,
            'city_id'            => $v->city_id ? (string) $v->city_id : null,
            'country_id'         => $v->country_id ? (string) $v->country_id : null,
            'state_id'           => $v->state_id ? (string) $v->state_id : null,
            'address'            => $v->address ?? '',
            'phone'              => $v->phone ?? '',
            'description'        => $v->description ?? '',
            'photos'             => $photos,
            'photo_url'          => $v->photo_url ?? ($photos[0] ?? ''),
            'google_maps_url'    => $v->maps_url,
            'status'             => $v->status,
            'approval_status'    => $v->approval_status ?? Venue::APPROVAL_APPROVED,
            'eligibility_status' => $v->eligibility_status ?? Venue::ELIGIBILITY_ELIGIBLE,
            'admin_note'         => $v->admin_note ?? '',
            'previous_snapshot'  => $v->previous_snapshot,
            'latitude'           => $v->latitude !== null ? (float) $v->latitude : null,
            'longitude'          => $v->longitude !== null ? (float) $v->longitude : null,
            'timezone_override'  => $v->timezone_override,
            'currency_override'  => $v->currency_override,
            'sort_order'         => (int) ($v->sort_order ?? 0),
            'submitted_at'       => $v->submitted_at?->toIso8601String(),
            'submitted_by_name'  => $v->submitted_by_name,
            'created_at'         => $v->created_at?->toIso8601String(),
        ];
    }

    protected function assertDashboardVenueAccess(int $venueId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
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
