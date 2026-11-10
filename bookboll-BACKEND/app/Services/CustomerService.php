<?php
namespace App\Services;

use App\Mail\UserWelcomeEmail;
use App\Models\Booking;
use App\Models\BusinessCustomer;
use App\Models\CustomerLoyalty;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Preference;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\CustomerFilters;
use App\Support\CustomerTypes;
use App\Models\Venue;
use BookingPaymentStatus;
use BookingStatus;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PaymentStatus;

class CustomerService
{
    public function __construct(protected User $customerModel)
    {}

    public function list($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;
        $status           = $request->status ?? null;
        $tags             = $request->tag ?? null;

        $customers = $this->scopedCustomerQuery($request)
            ->withCount(['bookings'])
            ->with(['tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->addSelect([
                'loyalty_balance'  => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_due_amount' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', '!=', PaymentStatus::PAID);
                },

                'total_spent'      => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->when($status, function ($query, $status) {
                $query->where('status', 'LIKE', "%{$status}%");
            })
            ->latest('id')
            ->paginate($records_per_page);

        return sendSuccess('Customers fetched successfully', compact('customers'));
    }

    public function listOptimized($request)
    {
        // The admin mobile app sends per_page; the dashboard sends records_per_page.
        // Accept both, and clamp like dashboard() so a client cannot ask for the
        // whole base in one page.
        $perPage = min(max((int) ($request->input('records_per_page') ?? $request->input('per_page') ?? 15), 1), 250);
        $search  = trim($request->search ?? '');

        $query = $this->scopedCustomerQuery($request)
            ->active()
            ->select('users.id', 'users.name', 'users.email', 'users.phone');

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->paginate($perPage);

        return sendSuccess('Customers fetched successfully', compact('customers'));
    }

    public function updateTags($request, $id)
    {
        $customer = $this->scopedCustomerQuery($request)->findOrFail($id);

        $request->validate([
            'tags'   => 'required|array',
            'tags.*' => 'string|max:50|regex:/^[a-z0-9\s\-]+$/i',
        ]);

        $customer->syncTags($request->input('tags', []));
        $customer->load('tags');

        return sendSuccess('Customer tags updated successfully', compact('customer'));

    }

    public function updateStatus($request, $id)
    {
        $customer = $this->scopedCustomerQuery($request)->findOrFail($id);

        $request->validate(['status' => 'required|in:active,blocked']);

        $customer->update(['status' => $request->input('status')]);

        if ($customer->status == User::BLOCKED) {
            $customer->tokens()->where('name', 'bookboll-customer-login-api-token')->delete();
            $customer->blocking_reason = $request->reason;
            $customer->save();
        } else {
            $customer->blocking_reason = null;
            $customer->save();
        }

        $customer->load('tags');

        return sendSuccess('Customer status updated successfully', compact('customer'));
    }

    public function createCustomer($request)
    {
        // Gender is stored lowercase and validated against lowercase values, but
        // clients have sent it capitalised ("Male") and were refused with
        // "invalid gender" for a value that is perfectly clear. Normalise first.
        if (is_string($request->input('gender'))) {
            $request->merge(['gender' => strtolower(trim($request->input('gender'))) ?: null]);
        }

        $formData = $request->validate([
            'name'                         => 'nullable|string|max:100',
            // Not unique: a known email is handled below by linking the existing
            // account to the caller's business rather than refusing the request.
            'email'                        => 'required|email',
            'phone'                        => 'nullable|string|min:6|max:20',
            'password'                     => 'nullable|min:8',
            'status'                       => 'sometimes|in:' . $this->customerModel::ACTIVE . ',' . $this->customerModel::INACTIVE . ',' . $this->customerModel::BLOCKED,
            'date_of_birth'                => 'nullable|date|before:today',
            'gender'                       => 'nullable|string|in:male,female,other',
            'nationality'                  => 'nullable|string|max:100',
            'address'                      => 'nullable|string|max:255',
            'language'                     => 'nullable|string|max:50',
            'currency'                     => 'nullable|string|max:10',
            'notifications'                => 'nullable|array',
            'notifications.booking_emails' => 'nullable|boolean',
            'notifications.sms_reminders'  => 'nullable|boolean',
            'notifications.marketing'      => 'nullable|boolean',
            'favorite_sports'              => 'nullable|array',
            'favorite_sports.*'            => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $existingUser = $this->findByEmail($formData['email']);
            if ($existingUser) {
                // One account per person on the marketplace. A second business typing
                // in the same email used to get "already exists" and no way to reach
                // the customer; now the existing account is linked to the caller's
                // business and handed back untouched — it is the customer's account,
                // not the business's, so no password, profile or preference change
                // and no welcome email.
                return $this->adoptExistingCustomer($request, $existingUser, function (User $user) {
                    $user->load(['roles', 'preferences']);
                    $user->makeHidden('roles');

                    return ['user' => $user, 'roles' => $user->getRoleNames()];
                });
            } else {
                if (empty($formData['password'])) {
                    $formData['password'] = Str::random(12);
                }

                $user = $this->customerModel->create([
                    // name and phone validate as nullable, so the keys are simply
                    // absent when omitted -- reading them unguarded threw
                    // "Undefined array key" and the catch below turned it into a 400.
                    'name'          => $formData['name'] ?? null,
                    'email'         => $formData['email'],
                    'phone'         => $formData['phone'] ?? null,
                    'password'      => $formData['password'],
                    'gender'        => $formData['gender'] ?? null,
                    'address'       => $formData['address'] ?? null,
                    'nationality'   => $formData['nationality'] ?? null,
                    'date_of_birth' => $formData['date_of_birth'] ?? null,
                    'status'        => $formData['status'] ?? $this->customerModel::ACTIVE,
                ]);

                $user->assignRole('customer');

                if (! $user->preferences) {
                    Preference::create([
                        'user_id'         => $user->id,
                        'notifications'   => [
                            'booking_emails'   => true,
                            'sms_reminders'    => false,
                            'marketing_emails' => true,
                        ],
                        'favorite_sports' => [],
                        'language'        => 'english',
                        'currency'        => 'QAR',
                    ]);
                }
            }

            // Both branches above have settled on $user by here, and we are still in
            // the transaction, so the link lives or dies with the customer row.
            $this->linkToCallerBusiness($request, $user);

            if (isset($formData['language']) || isset($formData['currency']) || isset($formData['notifications']) || isset($formData['favorite_sports'])) {
                $preferences = $user->preferences ?? Preference::create(['user_id' => $user->id]);

                if (isset($formData['language'])) {
                    $preferences->language = $formData['language'];
                }

                if (isset($formData['currency'])) {
                    $preferences->currency = $formData['currency'];
                }

                if (isset($formData['notifications'])) {
                    $currentNotifications       = $preferences->notifications ?? [];
                    $preferences->notifications = array_merge($currentNotifications, $formData['notifications']);
                }

                if (isset($formData['favorite_sports'])) {
                    $preferences->favorite_sports = $formData['favorite_sports'];
                }

                $preferences->save();
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $formData['password']));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'preferences']);
            $user->makeHidden('roles');
            $roles = $user->getRoleNames();

            return sendSuccess("Customer registered successfully", compact('user', 'roles') + ['linked_existing' => false, 'already_linked' => false]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function getCustomersForExport($request)
    {
        $search    = $request->search ?? null;
        $status    = $request->status ?? null;
        $tags      = $request->tag ?? null;
        $exportAll = $request->export_all == 1 || $request->export_all === '1';

        $query = $this->scopedCustomerQuery($request)
            ->withCount(['bookings'])
            ->with(['tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->addSelect([
                'loyalty_balance'  => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_due_amount' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', '!=', PaymentStatus::PAID);
                },
                'total_spent'      => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->when($status, function ($query, $status) {
                $query->where('status', 'LIKE', "%{$status}%");
            })
            ->when($tags, function ($query, $tags) {
                $query->whereHas('tags', function ($q) use ($tags) {
                    $q->where('name', $tags);
                });
            });

        if (! $exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page             = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    /**
     * Admin app: full customer profile, loyalty summary, and booking counts by status.
     */
    public function getAdminProfile(string $id)
    {
        $customer = $this->scopedCustomerQuery()
            ->with(['tags:id,name,color'])
            ->find($id);

        if (! $customer) {
            return sendError('Customer not found', [], 404);
        }

        $balance = (int) LoyaltyPoint::balance($customer->id);
        $tier    = $customer->loyalty_tier;

        $loyaltyTier = $tier ? [
            'id'         => $tier->id,
            'name'       => $tier->name,
            'slug'       => $tier->slug,
            'color'      => $tier->color,
            'min_points' => $tier->min_points,
            'max_points' => $tier->max_points,
        ] : null;

        $rawStats = Booking::where('user_id', $customer->id)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $bookingStats = [
            'pending'   => (int) ($rawStats[BookingStatus::PENDING] ?? 0),
            'confirmed' => (int) ($rawStats[BookingStatus::CONFIRMED] ?? 0),
            'completed' => (int) ($rawStats[BookingStatus::COMPLETED] ?? 0),
            'cancelled' => (int) ($rawStats[BookingStatus::CANCELLED] ?? 0),
            'total'     => array_sum(array_map('intval', $rawStats)),
        ];

        $totalSpent = (float) DB::table('payments')
            ->where('user_id', $customer->id)
            ->where('status', PaymentStatus::PAID)
            ->sum('amount');

        $totalDue = (float) DB::table('payments')
            ->where('user_id', $customer->id)
            ->where('status', '!=', PaymentStatus::PAID)
            ->sum('amount');

        $statusLabelMap = [
            User::ACTIVE   => 'Active',
            User::INACTIVE => 'Inactive',
            User::BLOCKED  => 'Blocked',
            User::PENDING  => 'Pending',
        ];

        $dob = $customer->date_of_birth;
        if ($dob instanceof \Carbon\CarbonInterface) {
            $dob = $dob->format('Y-m-d');
        } elseif (is_string($dob) && $dob !== '') {
            try {
                $dob = \Carbon\Carbon::parse($dob)->format('Y-m-d');
            } catch (\Throwable) {
                // keep raw string
            }
        }

        $customerPayload = [
            'id'                => $customer->id,
            'name'              => $customer->name,
            'email'             => $customer->email,
            'phone'             => $customer->phone,
            'image'             => $customer->image,
            'status'            => $customer->status,
            'status_label'      => $statusLabelMap[$customer->status] ?? ucfirst((string) $customer->status),
            'blocking_reason'   => $customer->blocking_reason,
            'gender'            => $customer->gender,
            'address'           => $customer->address,
            'nationality'       => $customer->nationality,
            'date_of_birth'     => $dob,
            'created_at'        => $customer->created_at?->toIso8601String(),
            'updated_at'        => $customer->updated_at?->toIso8601String(),
            'tags'              => $customer->tags->map(fn ($t) => [
                'id'    => $t->id,
                'name'  => $t->name,
                'color' => $t->color,
            ])->values(),
        ];

        return sendSuccess('Customer profile fetched', [
            'customer'        => $customerPayload,
            'loyalty'         => [
                'points_balance'      => $balance,
                'tier'                => $loyaltyTier,
                'points_to_next_tier' => (int) $customer->points_to_next_tier,
                'loyalty_progress'    => (int) $customer->loyalty_progress,
            ],
            'booking_stats'   => $bookingStats,
            'financial'       => [
                'total_spent' => round($totalSpent, 2),
                'total_due'   => round($totalDue, 2),
            ],
        ]);
    }

    /**
     * Admin app: paginated bookings for a customer, optional status filter.
     */
    public function getAdminBookings(Request $request, string $id)
    {
        $exists = $this->scopedCustomerQuery($request)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            return sendError('Customer not found', [], 404);
        }

        $perPage = (int) ($request->input('records_per_page', 20));
        $perPage = min(max($perPage, 1), 100);

        $status = $request->input('status');

        $query = Booking::where('user_id', $id)
            ->with([
                'listing:id,name,calendar_color',
                'venue:id,name',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($status && $status !== 'all' && in_array($status, BookingStatus::list(), true)) {
            $query->where('status', $status);
        }

        $bookings = $query->paginate($perPage);

        return sendSuccess('Customer bookings fetched', compact('bookings'));
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();

            $customer = $this->scopedCustomerQuery()->find($id);

            // Out of the caller's business, or not a customer at all. findOrFail here
            // fell into the generic catch below, which answered 500 and echoed the
            // model class back to the client.
            if (! $customer) {
                DB::rollBack();
                return sendError('Customer not found', [], 404);
            }

            $userRoles        = $customer->getRoleNames();
            $hasMultipleRoles = $userRoles->count() > 1;

            $activeBookings = $customer->bookings()
                ->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED])
                ->count();

            if ($activeBookings > 0) {
                DB::rollBack();
                return sendError("Cannot delete customer with {$activeBookings} active booking(s). Please cancel or complete the bookings first.", [], 422);
            }

            if ($hasMultipleRoles) {
                $customer->removeRole('customer');

                $customer->onesignalSubscriptions()->delete();
                $customer->tags()->detach();

                DB::commit();

                Log::info("Customer role removed from user", [
                    'user_id'         => $id,
                    'remaining_roles' => $customer->getRoleNames()->toArray(),
                    'deleted_by'      => auth()->id(),
                ]);

                return sendSuccess('Customer access removed successfully. User still has other roles: ' . $customer->getRoleNames()->implode(', '));

            } else {
                $customer->tokens()->delete();
                $customer->onesignalSubscriptions()->delete();
                $customer->removeRole('customer');
                $customer->status = User::INACTIVE;
                $customer->save();

                DB::commit();

                Log::info("Customer soft deleted successfully", [
                    'customer_id' => $id,
                    'deleted_by'  => auth()->id(),
                ]);

                return sendSuccess('Customer deleted successfully');
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer deletion error: " . $e->getMessage(), [
                'customer_id' => $id,
                'exception'   => $e,
            ]);
            return sendError('Failed to delete customer: ' . $e->getMessage());
        }
    }

    /**
     * Paginated roster for the apps/admin Customers page.
     * Old ADMIN still uses list() / GET /customers.
     */
    public function dashboard(Request $request)
    {
        $perPage = min(max((int) ($request->input('records_per_page') ?? $request->input('per_page') ?? 25), 1), 250);

        $query = $this->scopedCustomerQuery($request)
            ->with(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

        $search = trim((string) ($request->input('search') ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $statuses = $request->input('status');
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }
        if (is_array($statuses) && $statuses) {
            $query->whereIn('status', $statuses);
        }

        // Product types, applied BEFORE sorting, pagination, counts, export and
        // id selection — so the page, its total and a select-all all describe
        // the same set.
        $typeFilter = CustomerTypes::fromRequest($request);
        if (isset($typeFilter['error'])) {
            return sendError($typeFilter['error'], ['customer_types' => [$typeFilter['error']]], 422);
        }

        $scopeUser  = $request->user() ?? auth()->user();
        $venueIds   = AccessScope::allowedVenueIds($scopeUser);
        $programIds = $venueIds === null ? null : AccessScope::scopedProgramIds($scopeUser, $venueIds);

        CustomerTypes::applyFilter($query, $typeFilter['types'], $typeFilter['match'], $venueIds, $programIds);

        // Everything else the filter sheet offers. These were collected by the
        // client and read by nobody here, so picking a listing changed nothing.
        CustomerFilters::apply($query, $request, $venueIds, $programIds);

        $sort = (string) $request->input('sort', 'created_at');
        $dir  = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $this->applyDashboardSort($query, $sort, $dir, $venueIds);

        /*
         * Export asks for every matching row; the table asks for one page.
         *
         * Without this branch an export was simply a page: the client requested
         * 5,000 rows, records_per_page capped it at 250, and the CSV was built
         * from those 250 as though they were everything. readmeAlamgir: "do not
         * silently return a current page as a full export."
         */
        $isExport = $request->boolean('export');

        // "Select all matching" on the dashboard: the ids of every customer the
        // current filters reach, across every page. Ids only — the rows are
        // already on screen, and a bulk action wants the set, not the detail.
        if ($request->boolean('ids_only')) {
            $ids = (clone $query)->limit(self::EXPORT_MAX)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

            return sendSuccess('Customer ids fetched', ['ids' => $ids, 'total' => count($ids)]);
        }

        if ($isExport) {
            $exportRows = (clone $query)->limit(self::EXPORT_MAX + 1)->get();
            // One row over the cap proves there are more; report it rather than
            // handing back a short file that looks complete.
            $truncated = $exportRows->count() > self::EXPORT_MAX;
            $pageUsers = $truncated ? $exportRows->take(self::EXPORT_MAX) : $exportRows;
            $paginator = null;
        } else {
            $paginator = $query->paginate($perPage);
            $pageUsers = $paginator->getCollection();
            $truncated = false;
        }

        $pageIds = $pageUsers->pluck('id')->all();

        // Three queries for the whole page, not three per row. An empty array
        // is an authoritative "no qualifying activity", which is a different
        // claim from the field being absent.
        $typesByUser = CustomerTypes::forUsers($pageIds, $venueIds, $programIds);

        $mapped = $pageUsers->map(function (User $u) use ($typesByUser) {
            $row = $this->mapDashboardCustomer($u);
            $row['customer_types'] = $typesByUser[(int) $u->id] ?? [];
            // Derived from activity the viewer can see, and complete for that
            // scope — so the client knows it is not looking at a partial answer.
            $row['customer_types_complete'] = true;

            return $row;
        })->values();

        /*
         * In export mode $pageIds can hold twenty thousand entries, and an IN
         * clause that size is both slow and liable to hit a packet limit. The
         * same rows are selected with a subquery over the already-filtered
         * customer query instead, which the database can plan properly.
         */
        $customerIdSource = $isExport
            ? (clone $query)->reorder()->select('users.id')
            : ($pageIds ?: [0]);

        $bookings = Booking::query()
            ->whereIn('user_id', $customerIdSource)
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->orderByDesc('date')
            ->get(['id', 'user_id', 'listing_id', 'venue_id', 'date', 'total_price', 'status', 'payment_status', 'start_time', 'end_time', 'created_at', 'origin', 'created_by', 'creation_context'])
            ->map(fn (Booking $b) => $this->mapDashboardBooking($b))
            ->values();

        $spentByCustomer = [];
        foreach ($bookings as $b) {
            if (! $b['customer_id']) {
                continue;
            }
            $spentByCustomer[$b['customer_id']] = ($spentByCustomer[$b['customer_id']] ?? 0) + (float) $b['total'];
        }
        $mapped = $mapped->map(function ($c) use ($spentByCustomer) {
            $c['total_spent'] = round($spentByCustomer[$c['id']] ?? 0, 2);

            return $c;
        })->values();

        if ($paginator) {
            $paginator->setCollection($mapped);
        }

        $lookups = $request->boolean('include_lookups', true) ? $this->dashboardLookups() : [];
        $loyaltyKey = CustomerLoyalty::ownerColumn();

        // Echoed with the ACTUAL selection. A client that asked for a filter and
        // gets no echo knows the server ignored it, rather than showing an
        // unfiltered page as though it were filtered.
        $lookups['customer_type_filter'] = [
            'version' => CustomerTypes::FILTER_VERSION,
            'types'   => $typeFilter['types'],
            'match'   => $typeFilter['match'],
        ];
        $lookups['filters_applied'] = CustomerFilters::$applied;

        // Lookups below are built from $pageIds, which in export mode is EVERY
        // exported customer — so the CSV's aggregates are computed from the
        // same rows it lists rather than from whichever page was on screen.
        $lookups['customers'] = $paginator
            ? $paginator->toArray()
            : [
                'data'  => $mapped->all(),
                'total' => $mapped->count(),
                // Named so a client cannot mistake a capped export for a
                // complete one, and can narrow its filters instead.
                'truncated'  => $truncated,
                'export_max' => self::EXPORT_MAX,
            ];
        $lookups['bookings'] = $bookings;
        $lookups['customer_loyalty'] = CustomerLoyalty::query()
            ->whereIn($loyaltyKey, $customerIdSource)
            ->get()
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();
        $lookups['programs'] = $lookups['programs'] ?? [];
        $lookups['subscriptions'] = $lookups['subscriptions'] ?? [];
        $lookups['loyalty_programs'] = $lookups['loyalty_programs'] ?? $this->loyaltyProgramRows();
        $lookups['loyalty_tiers'] = $lookups['loyalty_tiers'] ?? $this->loyaltyTierRows();
        // The headline counts must agree with the rows below them.
        $lookups['kpis'] = [
            'total'      => $this->scopedCustomerQuery($request)->count(),
            'active'     => $this->scopedCustomerQuery($request)->where('status', User::ACTIVE)->count(),
            'activeSubs' => 0,
            'avgSpend'   => 0,
            'vip'        => 0,
        ];

        return sendSuccess('Customers fetched', $lookups);
    }

    public function crm($id)
    {
        $customer = $this->scopedCustomerQuery()
            ->with(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier'])
            ->find($id);

        if (! $customer) {
            return sendError('Customer not found', [], 404);
        }

        $lookups = $this->dashboardLookups();

        $bookings = Booking::query()
            ->where('user_id', $customer->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Booking $b) => $this->mapDashboardBooking($b))
            ->values();

        $loyalty = $customer->customerLoyalty
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();

        return sendSuccess('Customer fetched', array_merge($lookups, [
            'customer'          => $this->mapDashboardCustomer($customer),
            'bookings'          => $bookings,
            'customer_loyalty'  => $loyalty,
            'programs'          => [],
            // The detail modal grades a customer against their COUNTRY's ladder;
            // these were hardcoded empty, so it always fell back to a preset set
            // and no configured tier ever showed.
            'loyalty_programs'  => $this->loyaltyProgramRows(),
            'loyalty_tiers'     => $this->loyaltyTierRows(),
        ]));
    }

    /** Loyalty programs, one per country. */
    protected function loyaltyProgramRows()
    {
        return \App\Models\LoyaltyProgram::query()->orderBy('country_id')->get();
    }

    /** Tier ladders in the shape the dashboard grades against. */
    protected function loyaltyTierRows()
    {
        return \App\Models\LoyaltyTier::query()
            ->orderBy('order')
            ->get()
            ->map(fn ($t) => $t->toDashboardRow())
            ->values();
    }

    public function storeDashboard(Request $request)
    {
        $data = $this->validateDashboard($request);

        try {
            DB::beginTransaction();

            if (empty($data['password'])) {
                $data['password'] = Str::random(12);
            }

            $email = trim((string) ($data['email'] ?? ''));
            if ($email !== '' && ($existing = $this->findByEmail($email))) {
                // Same rule as createCustomer(): a known email means "link me to this
                // person", not "make another one". See adoptExistingCustomer().
                return $this->adoptExistingCustomer($request, $existing, function (User $user) {
                    $user->load(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

                    return ['customer' => $this->mapDashboardCustomer($user)];
                });
            }
            if ($email === '') {
                $email = 'c-' . Str::uuid() . '@placeholder.local';
            }

            $attributes = [
                'name'       => $data['full_name'] ?? $data['name'] ?? null,
                'email'      => $email,
                'phone'      => $data['phone'] ?? null,
                'password'   => $data['password'],
                'gender'     => ($data['gender'] ?? '') ?: null,
                'country_id' => ($data['country_id'] ?? '') ?: null,
                'status'     => $data['status'] ?? User::ACTIVE,
                'crm_tags'   => $data['tags'] ?? '',
            ];
            // Consent flags go in only when the form sent them. The dashboard's add
            // form does not, and `?? false` here was forcing every hand-added client
            // to opted-out — overriding the opt-in-on-creation default in
            // User::booted(), which only fills a flag that is absent. An explicit
            // false from a form that offers the boxes still wins.
            foreach (['marketing_email', 'marketing_sms', 'marketing_whatsapp'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $attributes[$flag] = (bool) $data[$flag];
                }
            }

            $user = $this->customerModel->create($attributes);

            $user->assignRole('customer');
            $this->linkToCallerBusiness($request, $user);
            $this->syncCrmTags($user, $data['tags'] ?? '');
            $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);

            Preference::create([
                'user_id'         => $user->id,
                'notifications'   => [
                    'booking_emails'   => true,
                    'sms_reminders'    => false,
                    'marketing_emails' => (bool) $user->marketing_email,
                ],
                'favorite_sports' => [],
                'language'        => 'english',
                'currency'        => '',
            ]);

            DB::commit();

            $user->load(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

            return sendSuccess('Customer created', [
                'customer' => $this->mapDashboardCustomer($user),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer create error: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Could not save customer.');
        }
    }

    public function updateDashboard(Request $request, $id)
    {
        $user = $this->scopedCustomerQuery($request)->findOrFail($id);
        $data = $this->validateDashboard($request, $user->id, true);

        try {
            DB::beginTransaction();

            $payload = [];
            if (array_key_exists('full_name', $data) || array_key_exists('name', $data)) {
                $payload['name'] = $data['full_name'] ?? $data['name'] ?? $user->name;
            }
            foreach (['email', 'phone', 'gender', 'country_id', 'status'] as $key) {
                if (array_key_exists($key, $data)) {
                    if ($key === 'email') {
                        $email = trim((string) $data['email']);
                        if ($email !== '') {
                            $payload['email'] = $email;
                        }
                        continue;
                    }
                    $payload[$key] = $data[$key] === '' ? null : $data[$key];
                }
            }
            foreach (['marketing_email', 'marketing_sms', 'marketing_whatsapp'] as $key) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = (bool) $data[$key];
                }
            }
            if (array_key_exists('tags', $data)) {
                $payload['crm_tags'] = $data['tags'];
            }

            if ($payload) {
                $user->update($payload);
            }

            if (array_key_exists('tags', $data)) {
                $this->syncCrmTags($user, $data['tags']);
            }

            if (array_key_exists('loyalty', $data)) {
                $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);
            }

            DB::commit();

            $user->load(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

            return sendSuccess('Customer updated', [
                'customer' => $this->mapDashboardCustomer($user),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer update error: ' . $e->getMessage(), ['customer_id' => $id, 'Exception' => $e]);
            return sendError('Could not save customer.');
        }
    }

    public function replaceLoyalty(Request $request, $id)
    {
        $user = $this->scopedCustomerQuery($request)->findOrFail($id);
        $data = $request->validate([
            'loyalty'                   => 'nullable|array',
            'loyalty.*.country_id'      => 'required',
            'loyalty.*.points'          => 'nullable|numeric|min:0',
            'loyalty.*.lifetime_points' => 'nullable|numeric|min:0',
        ]);

        $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);

        $rows = CustomerLoyalty::where(CustomerLoyalty::ownerColumn(), $user->id)
            ->get()
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();

        return sendSuccess('Loyalty updated', ['customer_loyalty' => $rows]);
    }

    public function upsertLoyalty(Request $request, $id)
    {
        $user = $this->scopedCustomerQuery($request)->findOrFail($id);
        $data = $request->validate([
            'country_id'      => 'required',
            'points'          => 'nullable|numeric|min:0',
            'lifetime_points' => 'nullable|numeric|min:0',
        ]);

        $pts  = max(0, (int) round((float) ($data['points'] ?? 0)));
        $life = max($pts, (int) round((float) ($data['lifetime_points'] ?? 0)));

        $row = CustomerLoyalty::updateOrCreate(
            [
                CustomerLoyalty::ownerColumn() => $user->id,
                'country_id'                   => (int) $data['country_id'],
            ],
            [
                'points'          => $pts,
                'lifetime_points' => $life,
            ]
        );

        return sendSuccess('Loyalty points updated', [
            'row' => $this->mapLoyaltyRow($row),
        ]);
    }

    public function deleteLoyalty($id, $countryId)
    {
        $user = $this->scopedCustomerQuery()->findOrFail($id);

        CustomerLoyalty::where(CustomerLoyalty::ownerColumn(), $user->id)
            ->where('country_id', $countryId)
            ->delete();

        return sendSuccess('Loyalty removed');
    }

    /** How many rows one export may pull, so a runaway export cannot exhaust memory. */
    private const EXPORT_MAX = 20000;

    /**
     * Order the customer table by the column whose header was clicked.
     *
     * Every column the table can sort by is sortable here, by the same figure the
     * cell shows: bookings and spend count only COMMERCIAL bookings (not
     * cancelled, not a membership session, not a provider's slot block, not a
     * payment-skipped placeholder) at venues the caller may see; points are the
     * customer's spendable balance across countries; tier follows lifetime
     * points, which is what the tier ladder is read from. Previously only the
     * name sorted and every other header silently fell back to newest-first.
     */
    protected function applyDashboardSort($query, string $sort, string $dir, $venueIds): void
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        if ($sort === 'full_name') {
            $query->orderBy('name', $dir)->orderBy('users.id', $dir);

            return;
        }

        $owner = CustomerLoyalty::ownerColumn();
        $loyalty = [
            'loyalty_points' => "(SELECT COALESCE(SUM(cl.points), 0) FROM customer_loyalty cl WHERE cl.{$owner} = users.id)",
            'loyalty_tier'   => "(SELECT COALESCE(MAX(cl.lifetime_points), 0) FROM customer_loyalty cl WHERE cl.{$owner} = users.id)",
        ];
        if (isset($loyalty[$sort])) {
            $query->orderByRaw("{$loyalty[$sort]} {$dir}, users.id {$dir}");

            return;
        }

        $bookingSorts = [
            'total_bookings' => 'COUNT(*)',
            'last_booking'   => 'MAX(b.date)',
            'total_spent'    => 'COALESCE(SUM(b.total_price), 0)',
        ];
        if (! isset($bookingSorts[$sort])) {
            $query->orderBy('users.created_at', $dir)->orderBy('users.id', $dir);

            return;
        }

        static $columns = null;
        $columns ??= [
            'origin'         => Schema::hasColumn('bookings', 'origin'),
            'record_purpose' => Schema::hasColumn('bookings', 'record_purpose'),
        ];

        // NULL-safe: a row with no status yet is a live booking, not a cancelled one.
        $where    = ['b.user_id = users.id', "(b.status IS NULL OR b.status NOT IN ('cancelled', 'canceled'))", '(b.payment_status IS NULL OR b.payment_status <> ?)'];
        $bindings = [BookingPaymentStatus::PAYMENT_SKIPPED];
        if ($columns['origin']) {
            $where[] = "b.origin <> 'class_program'";
        }
        if ($columns['record_purpose']) {
            $where[] = "(b.record_purpose IS NULL OR b.record_purpose <> 'provider_slot_block')";
        }
        if ($venueIds !== null) {
            $ids = array_values(array_map('intval', collect($venueIds)->all())) ?: [0];
            $where[]  = 'b.venue_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')';
            $bindings = array_merge($bindings, $ids);
        }

        $sub = '(SELECT '.$bookingSorts[$sort].' FROM bookings b WHERE '.implode(' AND ', $where).')';
        $query->orderByRaw("{$sub} {$dir}, users.id {$dir}", $bindings);
    }

    protected function customerQuery()
    {
        return $this->customerModel::whereHas('roles', function ($query) {
            $query->where('name', 'customer');
        });
    }

    /**
     * Customers the CALLER may see.
     *
     * A provider sees the people who have actually booked at their venues or joined
     * their programmes — never the whole marketplace. Platform roles see their
     * jurisdiction; a global admin sees everything. The rule itself lives in
     * AccessScope::customerIdsInScope() so this list and the marketing composer can
     * never drift apart.
     */
    protected function scopedCustomerQuery(?Request $request = null)
    {
        return AccessScope::applyCustomerScope(
            $this->customerQuery(),
            $request?->user() ?? auth()->user()
        );
    }

    /**
     * Find an account by email, case-insensitively. Emails are typed by hand on the
     * dashboard and in the app, so "Ali@x.com" and "ali@x.com" are the same person.
     */
    protected function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));

        return $email === '' ? null : $this->customerModel->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    /**
     * A business adds a customer whose account already exists.
     *
     * Called inside the caller's transaction; commits (or rolls back) it. The
     * account is linked to the caller's business (business_customer, source 'added')
     * and returned as-is: no new password, no profile overwrite, no welcome email —
     * the account is the customer's, not the business's. A non-customer account
     * (staff, say) gains the customer role so it can be booked for. A link that
     * already exists changes nothing and comes back with the same success shape,
     * so a client can treat both as "this person is in my clients".
     *
     * A global admin has no business to link to; for them an existing customer is
     * still a duplicate, because nothing would happen.
     *
     * @param  callable(User): array  $payload  how the caller serialises the customer
     */
    protected function adoptExistingCustomer($request, User $user, callable $payload): \Illuminate\Http\JsonResponse
    {
        $ownerId     = AccessScope::ownerUserId($request?->user() ?? auth()->user());
        $wasCustomer = $user->hasRole('customer');

        if ($wasCustomer && $ownerId === null) {
            DB::rollBack();

            return sendError('Customer with this email already exists');
        }

        if (! $wasCustomer) {
            $user->assignRole('customer');

            if (! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }
        }

        $alreadyLinked = $ownerId !== null && BusinessCustomer::query()
            ->where('owner_user_id', $ownerId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $alreadyLinked) {
            $this->linkToCallerBusiness($request, $user);
        }

        DB::commit();

        return sendSuccess(
            $alreadyLinked ? 'Customer is already in your clients' : 'Existing customer added to your clients',
            $payload($user->fresh()) + ['linked_existing' => true, 'already_linked' => $alreadyLinked]
        );
    }

    /**
     * Record that this customer belongs to the caller's business.
     *
     * Without this a client added by hand has no booking and no subscription, so the
     * scope above cannot see them and the business loses the customer it just created.
     * A platform or global admin has no business of their own, so nothing is linked —
     * BusinessCustomer::link() ignores a null owner.
     */
    protected function linkToCallerBusiness($request, User $customer): void
    {
        BusinessCustomer::link(
            AccessScope::ownerUserId($request?->user() ?? auth()->user()),
            (int) $customer->id,
            BusinessCustomer::SOURCE_ADDED
        );
    }

    protected function validateDashboard(Request $request, $ignoreId = null, bool $partial = false)
    {
        // On create a known email is not a validation failure: storeDashboard()
        // links the existing account to the caller's business instead. On update
        // the email still must not collide with another account.
        $emailRule = ($partial ? 'sometimes|' : '') . 'nullable|email'
            . ($ignoreId ? '|unique:users,email,' . $ignoreId : '');

        // Gender is stored lowercase and validated against lowercase values, but
        // clients have sent it capitalised ("Male") and were refused with
        // "invalid gender" for a value that is perfectly clear. Normalise first.
        if (is_string($request->input('gender'))) {
            $request->merge(['gender' => strtolower(trim($request->input('gender'))) ?: null]);
        }

        return $request->validate([
            'full_name'                 => ($partial ? 'sometimes|' : 'required|') . 'string|max:100',
            'name'                      => 'sometimes|nullable|string|max:100',
            'email'                     => $emailRule,
            'phone'                     => 'nullable|string|max:20',
            'password'                  => 'nullable|min:8',
            'gender'                    => 'nullable|string|in:male,female,other',
            'country_id'                => 'nullable',
            'status'                    => 'sometimes|in:' . User::ACTIVE . ',' . User::INACTIVE . ',' . User::BLOCKED,
            'tags'                      => 'nullable|string',
            'marketing_email'           => 'sometimes|boolean',
            'marketing_sms'             => 'sometimes|boolean',
            'marketing_whatsapp'        => 'sometimes|boolean',
            'loyalty'                   => 'nullable|array',
            'loyalty.*.country_id'      => 'required',
            'loyalty.*.points'          => 'nullable|numeric|min:0',
            'loyalty.*.lifetime_points' => 'nullable|numeric|min:0',
        ]);
    }

    protected function syncCrmTags(User $user, ?string $encoded): void
    {
        $encoded = (string) $encoded;
        $user->crm_tags = $encoded;
        $user->save();

        $names = [];
        foreach (explode(',', $encoded) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '⛔')) {
                continue;
            }
            $names[] = $entry;
        }

        $user->syncTags($names);
    }

    protected function replaceLoyaltyRows($customerId, array $rows): void
    {
        $ownerCol = CustomerLoyalty::ownerColumn();
        CustomerLoyalty::where($ownerCol, $customerId)->delete();

        $byCountry = [];
        foreach ($rows as $r) {
            $cid = $r['country_id'] ?? null;
            if ($cid === null || $cid === '') {
                continue;
            }
            $pts  = max(0, (int) round((float) ($r['points'] ?? 0)));
            $life = max($pts, (int) round((float) ($r['lifetime_points'] ?? 0)));
            $now = now();
            $byCountry[(int) $cid] = [
                $ownerCol         => $customerId,
                'country_id'      => (int) $cid,
                'points'          => $pts,
                'lifetime_points' => $life,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if ($byCountry) {
            CustomerLoyalty::insert(array_values($byCountry));
        }
    }

    protected function dashboardLookups(): array
    {
        $venues = Venue::with('users')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'city_id', 'country_id', 'created_by']);

        $countryIds = $venues->pluck('country_id')->filter()->unique()->values();
        $currencyByCountry = $countryIds->isEmpty()
            ? collect()
            : Country::with('currency')->whereIn('id', $countryIds)->get()
                ->mapWithKeys(fn (Country $c) => [$c->id => $c->currency?->code ?: '']);

        $locations = $venues->map(function (Venue $v) use ($currencyByCountry) {
            $ownerId = $v->users->first()?->id ?? $v->created_by;

            return [
                'id'                => (string) $v->id,
                'name'              => $v->name,
                'status'            => $v->status,
                'city_id'           => $v->city_id ? (string) $v->city_id : null,
                'owner_user_id'     => $ownerId ? (string) $ownerId : null,
                'currency_override' => $currencyByCountry[$v->country_id] ?? null,
            ];
        })->values();

        $listings = Listing::with('sportsCategory:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'venue_id', 'calendar_color', 'sports_category_id'])
            ->map(function (Listing $l) {
                return [
                    'id'             => (string) $l->id,
                    'name'           => $l->name,
                    'location_id'    => $l->venue_id ? (string) $l->venue_id : null,
                    'booking_color'  => $l->calendar_color ?: '#64748b',
                    'sport_category' => $l->sportsCategory?->name,
                ];
            })->values();

        $cityIds = $venues->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : City::whereIn('id', $cityIds)
                ->orderBy('name')
                ->get(['id', 'name', 'country_id'])
                ->map(fn (City $c) => [
                    'id'         => (string) $c->id,
                    'name'       => $c->name,
                    'country_id' => $c->country_id ? (string) $c->country_id : null,
                    'status'     => 'active',
                ]);

        $countries = Country::with('currency')
            ->orderBy('name')
            ->get()
            ->filter(fn (Country $c) => (int) ($c->status ?? 1) === 1)
            ->map(function (Country $c) {
                return [
                    'id'            => (string) $c->id,
                    'name'          => $c->name,
                    'currency_code' => $c->currency?->code ?: '',
                    'status'        => 'active',
                ];
            })->values();

        return compact('locations', 'listings', 'cities', 'countries');
    }

    protected function mapDashboardCustomer(User $u): array
    {
        $u->setAppends([]);

        $loyaltyRows = $u->relationLoaded('customerLoyalty')
            ? $u->customerLoyalty
            : $u->customerLoyalty()->get();

        $loyaltyPoints = (int) $loyaltyRows->sum('points');

        $prefs  = $u->preferences;
        $notify = is_array($prefs?->notifications) ? $prefs->notifications : [];

        $tags = $u->crm_tags;
        if ($tags === null || $tags === '') {
            $tagNames = $u->relationLoaded('tags')
                ? $u->tags->pluck('name')->all()
                : [];
            $tags = implode(', ', $tagNames);
        }

        return [
            'id'                 => (string) $u->id,
            'full_name'          => $u->name,
            'email'              => str_ends_with((string) $u->email, '@placeholder.local') ? '' : $u->email,
            'phone'              => $u->phone,
            'gender'             => $u->gender,
            'country_id'         => $u->country_id ? (string) $u->country_id : null,
            'status'             => $u->status ?: User::ACTIVE,
            'tags'               => (string) $tags,
            'loyalty_tier'       => $u->loyaltyTier?->name ?: 'Rookie',
            'loyalty_points'     => $loyaltyPoints,
            'marketing_email'    => (bool) ($u->marketing_email ?? $notify['marketing_emails'] ?? $notify['marketing'] ?? false),
            'marketing_sms'      => (bool) ($u->marketing_sms ?? $notify['sms_reminders'] ?? false),
            'marketing_whatsapp' => (bool) ($u->marketing_whatsapp ?? false),
            'unsubscribe_token'  => substr(hash('sha256', 'unsub:' . $u->id . config('app.key')), 0, 32),
            'created_at'         => $u->created_at?->toIso8601String(),
            'updated_at'         => $u->updated_at?->toIso8601String(),
        ];
    }

    protected function mapDashboardBooking(Booking $b): array
    {
        $payment = (string) $b->payment_status;
        if ($payment === BookingPaymentStatus::FULLY_PAID) {
            $payment = 'paid';
        } elseif ($payment === BookingPaymentStatus::PAYMENT_SKIPPED) {
            $payment = 'skipped';
        }

        $date = $b->date;
        if ($date instanceof \Carbon\CarbonInterface) {
            $date = $date->format('Y-m-d');
        }

        return [
            'id'             => (string) $b->id,
            // The reference staff and customers actually quote (BKN_YYYY_MM_NNNNN).
            // Without it the dashboard invented one from the row id.
            'booking_id'     => $b->booking_id,
            'origin'         => $b->origin,
            'creation_context'=> $b->creation_context,
            'created_by'     => $b->created_by ? (string) $b->created_by : null,
            'customer_id'    => $b->user_id ? (string) $b->user_id : null,
            'listing_id'     => $b->listing_id ? (string) $b->listing_id : null,
            'location_id'    => $b->venue_id ? (string) $b->venue_id : null,
            'booking_date'   => $date,
            'total'          => (float) ($b->total_price ?? 0),
            'booking_status' => $b->status,
            'payment_status' => $payment,
            'start_time'     => $b->start_time,
            'end_time'       => $b->end_time,
            'created_at'     => $b->created_at?->toIso8601String(),
        ];
    }

    protected function mapLoyaltyRow(CustomerLoyalty $row): array
    {
        return [
            'id'              => (string) $row->id,
            'customer_id'     => (string) ($row->{CustomerLoyalty::ownerColumn()} ?? $row->customer_id ?? $row->user_id),
            'country_id'      => (string) $row->country_id,
            'points'          => (int) $row->points,
            'lifetime_points' => (int) $row->lifetime_points,
        ];
    }

}
