<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\AccessScope;
use App\Support\MobileAppReturn;
use App\Support\StripeMinimumCharge;
use App\Support\TenantOrigins;
use App\Models\Listing;
use App\Services\BookingWalletService;
use App\Models\Payment;
use App\Support\CommissionPolicy;
use App\Support\Money;
use App\Support\StripeDocumentLink;
use App\Models\Venue;
use Barryvdh\DomPDF\Facade\Pdf;
use BookingPaymentMethod;
use Illuminate\Validation\Rule;
use BookingPaymentStatus;
use BookingStatus;
use CompnayInfo;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class PaymentService
{
    public function __construct(
        protected Payment $paymentModel,
        protected Booking $bookingModel,
        protected StripeService $stripeService
    ) {}

    public function details($id)
    {
        try {
            $query = $this->paymentModel::with([
                'user:id,name,email,phone,stripe_id',
                'booking' => function ($q) {
                    $q->with([
                        'user:id,name,email,phone,stripe_id',
                        'venue:id,name,maps_url',
                        'listing:id,name,venue_id',
                        'addOns',
                        'promocode:id,code,discount,discount_type',
                        'payments' => function ($q) {
                            $q->orderBy('created_at', 'asc');
                        },
                        'history' => function ($q) {
                            $q->with('user:id,name')->orderByDesc('id');
                        },
                        'latestInvoice',
                    ]);
                },
            ]);

            $query = applyVenueFilterToPayments($query);

            $payment = $query->where('id', $id)->first();

            if (! $payment) {
                return sendError('Payment not found or you do not have access to it.', [], 404);
            }

            $stripe = $this->stripeService->forPayment($payment);

            return sendSuccess('Payment details fetched successfully', compact('payment', 'stripe'));
        } catch (Exception $e) {
            Log::error('Failed to fetch payment details: ' . $e->getMessage(), [
                'payment_id' => $id,
                'exception'  => $e,
            ]);
            return sendError('Failed to fetch payment details.');
        }
    }

    public function list($request)
    {
        $search           = $request->search ?? null;
        $method           = $request->method ?? null;
        $status           = $request->status ?? null;
        $to               = $request->to_date ?? null;
        $from             = $request->from_date ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $query = $this->paymentModel::with([
            'booking',
            'user:id,name,email',
            'booking.venue:id,name',
        ])
            ->latest()
            ->when($search, fn($query) => $this->applyPaymentSearch($query, $search))
            ->when($method, function ($query, $method) {
                $query->where('method', $method);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($from, fn($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($to, fn($q, $to) => $q->whereDate('date', '<=', $to));

        $query = applyVenueFilterToPayments($query);

        $statsQuery = clone $query;

        $stats = [
            'total_amount'   => $statsQuery->sum('amount'),
            'paid_amount'    => (clone $statsQuery)->where('status', 'paid')->sum('amount'),
            'pending_amount' => (clone $statsQuery)->where('status', 'pending')->sum('amount'),
        ];

        $payments = $query->paginate($records_per_page);

        return sendSuccess('Payments fetched successfully', compact('payments', 'stats'));
    }

    /**
     * Dashboard payments page — Laravel pagination (same pattern as audit-logs):
     * page + records_per_page, server filters, paginator + lookups for the page,
     * plus KPI stats over the filtered set (not just the current page).
     */
    public function dashboard(Request $request)
    {
        $recordsPerPage = (int) ($request->input('records_per_page') ?? $request->query('records_per_page', 25));
        if ($recordsPerPage < 1) {
            $recordsPerPage = 25;
        }
        if ($recordsPerPage > 250) {
            $recordsPerPage = 250;
        }

        $query = $this->paymentModel::query()
            ->select([
                'id',
                'payment_id',
                'booking_id',
                'user_id',
                'type',
                'amount',
                'currency',
                'recipient',
                'method',
                'status',
                'date',
                'paid_at',
                'notes',
                'source_type',
                'subscription_id',
                'marked_paid_by',
                // Needed by mapDashboardPayment — an explicit select that omits
                // these would serve every Stripe document link as null.
                'stripe_invoice_url',
                'stripe_receipt_url',
                'created_by',
                'created_at',
                'updated_at',
            ]);

        $query = applyVenueFilterToPayments($query);
        $this->applyDashboardFilters($query, $request);
        $this->applyDashboardSort($query, $request);

        $statsQuery = clone $query;
        $stats = $this->buildDashboardStats($statsQuery);

        $paginator = $query->paginate($recordsPerPage);
        $pagePayments = collect($paginator->items());

        $bookingIds = $pagePayments->pluck('booking_id')->filter()->unique()->values();
        // Sibling ledger rows for the detail drawer (deposit + remaining on same booking).
        $siblingPayments = $bookingIds->isEmpty()
            ? collect()
            : $this->paymentModel::query()
            ->select([
                'id',
                'payment_id',
                'booking_id',
                'user_id',
                'type',
                'amount',
                'currency',
                'recipient',
                'method',
                'status',
                'date',
                'paid_at',
                'notes',
                'source_type',
                'subscription_id',
                'marked_paid_by',
                // Needed by mapDashboardPayment — an explicit select that omits
                // these would serve every Stripe document link as null.
                'stripe_invoice_url',
                'stripe_receipt_url',
                'created_by',
                'created_at',
                'updated_at',
            ])
            ->whereIn('booking_id', $bookingIds)
            ->get();

        $allForLookups = $pagePayments->concat($siblingPayments)->unique('id');
        $lookupBookingIds = $allForLookups->pluck('booking_id')->filter()->unique()->values();

        $bookingsRaw = $lookupBookingIds->isEmpty()
            ? collect()
            : Booking::query()
            ->select([
                'id',
                'user_id',
                'venue_id',
                'listing_id',
                'date',
                'start_time',
                'end_time',
                'status',
                'payment_status',
                'total_price',
                'origin',
                'players',
                'created_at',
            ])
            ->whereIn('id', $lookupBookingIds)
            ->get()
            ->keyBy('id');

        $venueIds = $bookingsRaw->pluck('venue_id')->filter()->unique()->values();
        $venuesRaw = $venueIds->isEmpty()
            ? collect()
            : Venue::select(['id', 'name', 'city_id', 'country_id', 'owner_user_id', 'status', 'currency_override'])
            ->whereIn('id', $venueIds)
            ->get()
            ->keyBy('id');

        $ownerIds = $venuesRaw->pluck('owner_user_id')->filter()->unique()->values();
        $owners = $ownerIds->isEmpty()
            ? collect()
            : \App\Models\User::whereIn('id', $ownerIds)->get(['id', 'name', 'email'])->keyBy('id');

        $countryIdsForCurrency = $venuesRaw->pluck('country_id')->filter()->unique()->values();
        $currencyByCountry = $countryIdsForCurrency->isEmpty()
            ? collect()
            : \Nnjeim\World\Models\Country::with('currency')
            ->whereIn('id', $countryIdsForCurrency)
            ->get()
            ->mapWithKeys(fn($c) => [$c->id => $c->currency?->code ?: null]);

        $mapOne = function (Payment $p) use ($bookingsRaw, $venuesRaw, $owners, $currencyByCountry) {
            $booking = $p->booking_id ? $bookingsRaw->get($p->booking_id) : null;
            $venue = $booking?->venue_id ? $venuesRaw->get($booking->venue_id) : null;
            $owner = $venue?->owner_user_id ? $owners->get($venue->owner_user_id) : null;
            // The venue's own currency, then its country's. No hardcoded last
            // resort: a row with no currency anywhere shows the amount alone,
            // which is honest, rather than a code that was never charged.
            $currencyFallback = $venue?->currency_override
                ?: ($venue?->country_id ? ($currencyByCountry[$venue->country_id] ?? null) : null);

            return $this->mapDashboardPayment($p, $currencyFallback, $owner);
        };

        $mappedPage = $pagePayments->map($mapOne)->values();
        $mappedRelated = $siblingPayments->map($mapOne)->values();

        $bookings = $bookingsRaw->values()->map(fn(Booking $b) => $this->mapDashboardBooking($b))->values();

        $customerIds = $bookingsRaw->pluck('user_id')->filter()->unique()->values();
        $customers = $customerIds->isEmpty()
            ? collect()
            : \App\Models\User::whereIn('id', $customerIds)
            ->get(['id', 'name', 'email', 'phone'])
            ->map(fn($u) => [
                'id'        => (string) $u->id,
                'full_name' => $u->name,
                'email'     => $u->email,
                'phone'     => $u->phone,
            ])
            ->values();

        $listingIds = $bookingsRaw->pluck('listing_id')->filter()->unique()->values();
        $listings = $listingIds->isEmpty()
            ? collect()
            : Listing::whereIn('id', $listingIds)
            ->get(['id', 'name', 'venue_id', 'status'])
            ->map(fn(Listing $l) => [
                'id'          => (string) $l->id,
                'name'        => $l->name,
                'location_id' => $l->venue_id ? (string) $l->venue_id : null,
                'status'      => $l->status,
            ])
            ->values();

        // Filter dropdowns: all venues the actor can see (not just this page).
        $filterVenuesQuery = Venue::orderBy('name');
        $actor = $request->user();
        AccessScope::applyVenue($filterVenuesQuery, 'id', $actor);
        $filterVenues = $filterVenuesQuery
            ->get(['id', 'name', 'city_id', 'country_id', 'owner_user_id', 'status', 'currency_override']);

        $locations = $filterVenues->map(fn(Venue $v) => [
            'id'                => (string) $v->id,
            'name'              => $v->name,
            'city_id'           => $v->city_id ? (string) $v->city_id : null,
            'country_id'        => $v->country_id ? (string) $v->country_id : null,
            'owner_user_id'     => $v->owner_user_id ? (string) $v->owner_user_id : null,
            'status'            => $v->status,
            'currency_override' => $v->currency_override,
        ])->values();

        $cityIds = $filterVenues->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : \Nnjeim\World\Models\City::whereIn('id', $cityIds)
            ->orderBy('name')
            ->get(['id', 'name', 'country_id'])
            ->map(fn($c) => [
                'id'         => (string) $c->id,
                'name'       => $c->name,
                'country_id' => $c->country_id ? (string) $c->country_id : null,
            ])
            ->values();

        $countryIds = $filterVenues->pluck('country_id')->filter()
            ->merge($cities->pluck('country_id'))
            ->filter()
            ->unique()
            ->values();
        $countries = $countryIds->isEmpty()
            ? collect()
            : \Nnjeim\World\Models\Country::with('currency')
            ->whereIn('id', $countryIds)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'            => (string) $c->id,
                'name'          => $c->name,
                'iso_code'      => $c->iso2,
                'currency_code' => $c->currency?->code,
            ])
            ->values();

        $adminUsers = \App\Models\User::query()
            ->with('roles:id,name')
            ->whereHas('roles', fn($q) => $q->whereIn('name', [
                'super_admin',
                'admin',
                'country_admin',
                'provider_admin',
                'facility_admin',
                'platform_staff',
            ]))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn($u) => [
                'id'            => (string) $u->id,
                'full_name'     => $u->name,
                'business_name' => $u->name,
                'role'          => $u->roles->first()?->name,
                'owner_user_id' => null,
                'username'      => $u->email,
            ])
            ->values();

        $filterQuery = $this->paymentModel::query();
        $filterQuery = applyVenueFilterToPayments($filterQuery);

        $currencies = (clone $filterQuery)
            ->whereNotNull('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->filter()
            ->values();

        $recipients = (clone $filterQuery)
            ->whereNotNull('recipient')
            ->distinct()
            ->orderBy('recipient')
            ->pluck('recipient')
            ->filter()
            ->map(fn($r) => in_array($r, ['bookboll', '@bookboll'], true) ? '@bookboll' : $r)
            ->unique()
            ->values();

        $filterListings = Listing::query()
            ->when(
                $actor && ! $actor->hasAnyRole(['super_admin', 'admin']),
                function ($q) use ($actor) {
                    // AccessScope: ownership + assignment + jurisdiction, one authority.
                    $allowedVenueIds = AccessScope::allowedVenueIds($actor) ?? [];
                    $q->whereIn('venue_id', $allowedVenueIds);
                }
            )
            ->orderBy('name')
            ->get(['id', 'name', 'venue_id', 'status'])
            ->map(fn(Listing $l) => [
                'id'          => (string) $l->id,
                'name'        => $l->name,
                'location_id' => $l->venue_id ? (string) $l->venue_id : null,
                'status'      => $l->status,
            ])
            ->values();

        // Replace paginator items with mapped dashboard rows.
        $paginator->setCollection($mappedPage);

        return sendSuccess('Payments dashboard fetched', [
            'payments'         => $paginator,
            'related_payments' => $mappedRelated,
            'bookings'         => $bookings,
            'customers'        => $customers,
            'listings'         => $listings->merge($filterListings)->unique('id')->values(),
            'locations'        => $locations,
            'cities'           => $cities,
            'countries'        => $countries,
            'admin_users'      => $adminUsers,
            'subscriptions'    => [],
            'programs'         => [],
            'stats'            => $stats,
            'filter_options'   => [
                'currencies' => $currencies,
                'recipients' => $recipients,
            ],
        ]);
    }

    protected function applyDashboardFilters($query, Request $request): void
    {
        $asList = function ($value) {
            if (is_array($value)) {
                return array_values(array_filter(array_map('strval', $value), fn($v) => $v !== ''));
            }
            if ($value === null || $value === '') {
                return [];
            }

            return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn($v) => $v !== ''));
        };

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('payment_id', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%")
                    ->orWhere('booking_id', 'LIKE', "%{$search}%")
                    ->orWhereHas('booking.user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('booking.listing', function ($lq) use ($search) {
                        $lq->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('booking.venue', function ($vq) use ($search) {
                        $vq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        $statuses = $asList($request->input('status'));
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        $methods = $asList($request->input('method'));
        if ($methods) {
            $expanded = [];
            foreach ($methods as $m) {
                $expanded[] = $m;
                if ($m === 'card') {
                    $expanded[] = 'credit_card';
                }
            }
            $query->whereIn('method', array_unique($expanded));
        }

        $types = $asList($request->input('type') ?? $request->input('payment_type'));
        if ($types) {
            $expanded = [];
            foreach ($types as $t) {
                if ($t === 'commission') {
                    $expanded = array_merge($expanded, ['commission', 'booking_commission', 'subscription_commission']);
                } elseif ($t === 'earning') {
                    $expanded = array_merge($expanded, ['earning', 'booking_earning', 'subscription_earning']);
                } elseif ($t === 'full') {
                    $expanded = array_merge($expanded, ['full', 'booking']);
                } elseif ($t === 'remaining') {
                    $expanded = array_merge($expanded, ['remaining', 'balance']);
                } else {
                    $expanded[] = $t;
                }
            }
            $query->whereIn('type', array_unique($expanded));
        }

        $sourceTypes = $asList($request->input('source_type'));
        if ($sourceTypes) {
            $query->whereIn('source_type', $sourceTypes);
        }

        // Product kind: venue / event / subscription (maps to booking.origin or source_type).
        $sources = $asList($request->input('source'));
        if ($sources) {
            $query->where(function ($q) use ($sources) {
                foreach ($sources as $src) {
                    if ($src === 'subscription') {
                        $q->orWhere('source_type', 'subscription');
                    } elseif ($src === 'event') {
                        $q->orWhere(function ($qq) {
                            $qq->where(function ($x) {
                                $x->whereNull('source_type')->orWhere('source_type', 'booking');
                            })->whereHas('booking', fn($b) => $b->where('origin', 'customer_game'));
                        // An own-spot event books no court, so its fee row has no
                        // booking to read an origin from — it says so itself.
                        })->orWhere('source_type', 'hosted_game');
                    } elseif ($src === 'venue') {
                        $q->orWhere(function ($qq) {
                            $qq->where(function ($x) {
                                $x->whereNull('source_type')->orWhere('source_type', 'booking');
                            })->whereHas('booking', fn($b) => $b->where(function ($bo) {
                                $bo->whereNull('origin')
                                    ->orWhereNotIn('origin', ['customer_game', 'class_program']);
                            }));
                        });
                    }
                }
            });
        }

        $currencies = $asList($request->input('currency'));
        if ($currencies) {
            $query->whereIn('currency', $currencies);
        }

        $recipients = $asList($request->input('recipient'));
        if ($recipients) {
            $query->where(function ($q) use ($recipients) {
                foreach ($recipients as $r) {
                    if (in_array($r, ['@bookboll', 'bookboll'], true)) {
                        $q->orWhereIn('recipient', ['@bookboll', 'bookboll'])
                            ->orWhereNull('recipient');
                    } else {
                        $q->orWhere('recipient', $r);
                    }
                }
            });
        }

        $venueIds = $asList($request->input('venue_id') ?? $request->input('location_id'));
        if ($venueIds) {
            $query->whereHas('booking', fn($b) => $b->whereIn('venue_id', $venueIds));
        }

        $listingIds = $asList($request->input('listing_id'));
        if ($listingIds) {
            $query->whereHas('booking', fn($b) => $b->whereIn('listing_id', $listingIds));
        }

        $cityIds = $asList($request->input('city_id'));
        if ($cityIds) {
            $query->whereHas('booking.venue', fn($v) => $v->whereIn('city_id', $cityIds));
        }

        $countryIds = $asList($request->input('country_id'));
        if ($countryIds) {
            $query->whereHas('booking.venue', fn($v) => $v->whereIn('country_id', $countryIds));
        }

        if ($request->filled('amount_min')) {
            $query->where('amount', '>=', (float) $request->amount_min);
        }
        if ($request->filled('amount_max')) {
            $query->where('amount', '<=', (float) $request->amount_max);
        }

        $from = $request->input('from_date') ?? $request->input('date_from');
        $to = $request->input('to_date') ?? $request->input('date_to');
        if ($from) {
            $query->where(function ($q) use ($from) {
                $q->whereDate('paid_at', '>=', $from)
                    ->orWhere(function ($qq) use ($from) {
                        $qq->whereNull('paid_at')->whereDate('created_at', '>=', $from);
                    });
            });
        }
        if ($to) {
            $query->where(function ($q) use ($to) {
                $q->whereDate('paid_at', '<=', $to)
                    ->orWhere(function ($qq) use ($to) {
                        $qq->whereNull('paid_at')->whereDate('created_at', '<=', $to);
                    });
            });
        }
    }

    protected function applyDashboardSort($query, Request $request): void
    {
        $sort = (string) ($request->input('sort') ?? 'date');
        $dir = strtolower((string) ($request->input('sort_dir') ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'amount' => $query->orderBy('amount', $dir)->orderByDesc('id'),
            'status' => $query->orderBy('status', $dir)->orderByDesc('id'),
            'method' => $query->orderBy('method', $dir)->orderByDesc('id'),
            'recipient' => $query->orderBy('recipient', $dir)->orderByDesc('id'),
            default => $query->orderByRaw('COALESCE(paid_at, created_at) ' . $dir)->orderByDesc('id'),
        };
    }

    protected function buildDashboardStats($query): array
    {
        $clean = function ($q) {
            $clone = clone $q;
            $clone->getQuery()->columns = null;
            $clone->getQuery()->orders = null;
            $clone->getQuery()->groups = null;
            $clone->getQuery()->havings = null;
            $clone->getQuery()->unionOrders = null;

            return $clone;
        };

        $base = $clean($query);
        $paid = $clean($query)->where('status', 'paid');

        $byCurrency = (clone $paid)
            ->selectRaw("COALESCE(NULLIF(currency, ''), '?') as currency_code, SUM(amount) as total")
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $toBookboll = (clone $paid)
            ->where(function ($q) {
                $q->whereNull('recipient')
                    ->orWhereIn('recipient', ['bookboll', '@bookboll']);
            })
            ->selectRaw("COALESCE(NULLIF(currency, ''), '?') as currency_code, SUM(amount) as total")
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        $toProvider = (clone $paid)
            ->whereNotNull('recipient')
            ->whereNotIn('recipient', ['bookboll', '@bookboll'])
            ->selectRaw("COALESCE(NULLIF(currency, ''), '?') as currency_code, SUM(amount) as total")
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        // One line per currency. Two things this must not do: round (159.20 is not
        // 159), and print a currency nobody was charged — money whose currency is
        // missing is called out as unknown rather than quietly swelling the AED
        // total, because that hides a data problem instead of showing it.
        $fmt = function ($map) {
            $out = [];
            foreach ($map as $c => $v) {
                $label = ($c === '?' || $c === null || $c === '') ? 'Unknown currency' : $c;
                $out[] = $label . ' ' . number_format((float) $v, 2);
            }
            sort($out);

            return $out ?: [];
        };

        // What is still owed, per currency. A deposit booking carries its arrival
        // balance as its own `remaining` row, and an unpaid full payment carries
        // the whole price, so summing the outstanding rows covers both without a
        // special case. The count beside it is taken from the same rows, so the
        // card's amount and its "N pending payments" cannot disagree.
        $outstanding = $this->outstandingPayments($clean($query));

        $pendingByCurrency = (clone $outstanding)
            ->selectRaw("COALESCE(NULLIF(currency, ''), '?') as currency_code, SUM(amount) as total")
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code');

        return [
            'total'          => $fmt($byCurrency),
            'to_bookboll'    => $fmt($toBookboll),
            'to_provider'    => $fmt($toProvider),
            'pending'        => (int) (clone $outstanding)->count(),
            'pending_amount' => $fmt($pendingByCurrency),
        ];
    }

    /**
     * Payment rows that represent money still to collect.
     *
     * Deliberately narrower than "status is not paid":
     *   • `unpaid` counts as outstanding — the dashboard already shows those
     *     rows as pending, so the KPI has to agree with the table.
     *   • cancelled and refunded rows are not owed by anyone.
     *   • a cancelled booking's unpaid row is not collectable either, so it is
     *     not advertised as an uncollected amount.
     *   • provider slot blocks are a provider reserving their own court; they
     *     were never a marketplace sale and are not a receivable.
     *   • the split commission/earning ledger rows are an allocation of money
     *     already taken, so including them would count the same money twice.
     */
    protected function outstandingPayments($query)
    {
        return $query
            ->whereIn('status', [PaymentStatus::PENDING, PaymentStatus::UNPAID])
            ->whereIn('type', [PaymentType::FULL, PaymentType::DEPOSIT, PaymentType::REMAINING])
            ->where(function ($q) {
                $q->whereNull('booking_id')
                    ->orWhereHas('booking', function ($b) {
                        // payment_method and payment_status are both nullable, and
                        // legacy rows leave them blank. `NOT IN` is unknown for
                        // NULL, which would silently drop those bookings from the
                        // receivable, so each test keeps its NULL rows explicitly.
                        $b->where(fn ($w) => $w
                                ->whereNull('payment_method')
                                ->orWhere('payment_method', '!=', BookingPaymentMethod::PAYMENT_SKIPPED))
                            ->where(fn ($w) => $w
                                ->whereNull('payment_status')
                                ->orWhereNotIn('payment_status', [
                                    BookingPaymentStatus::PAYMENT_SKIPPED,
                                    BookingPaymentStatus::CANCELLED,
                                ]))
                            ->where('status', '!=', BookingStatus::CANCELLED);
                    });
            });
    }

    protected function mapDashboardPayment(Payment $p, ?string $currencyFallback = null, $owner = null): array
    {
        $method = (string) $p->method;
        if ($method === 'credit_card') {
            $method = 'card';
        }

        $currency = $p->currency ?: ($currencyFallback ?: '');

        $recipient = $p->recipient;
        if (! $recipient) {
            if (in_array($method, ['online', 'card'], true)) {
                $recipient = '@bookboll';
            } else {
                $recipient = $owner
                    ? ('@' . strtok((string) ($owner->email ?: $owner->name), '@'))
                    : 'provider';
            }
        }

        $paidAt = $p->paid_at;
        if (! $paidAt && $p->status === PaymentStatus::PAID) {
            $paidAt = $p->date ?: $p->created_at;
        }

        $status = in_array((string) $p->status, ['pending', 'unpaid'], true) ? 'pending' : (string) $p->status;

        return [
            'id'              => (string) $p->id,
            'payment_id'      => $p->payment_id,
            'booking_id'      => $p->booking_id ? (string) $p->booking_id : null,
            'subscription_id' => $p->subscription_id ? (string) $p->subscription_id : null,
            'source_type'     => $p->source_type ?: 'booking',
            'payment_type'    => $p->type ?: 'full',
            'method'          => $method,
            'amount'          => (float) $p->amount,
            'currency'        => $currency,
            'recipient'       => $recipient,
            'status'          => $status,
            // Stripe's own hosted invoice/receipt page, so staff can print or
            // download the document Stripe issued. Only for money Stripe
            // actually captured, and only ever a stripe.com https link —
            // anything else is served as null, which the dashboard shows as
            // "not available yet" rather than opening an unknown host.
            'stripe_invoice_url' => StripeDocumentLink::appliesTo($method, $status)
                ? StripeDocumentLink::sanitize($p->stripe_invoice_url)
                : null,
            'stripe_receipt_url' => StripeDocumentLink::appliesTo($method, $status)
                ? StripeDocumentLink::sanitize($p->stripe_receipt_url)
                : null,
            'paid_at'         => $paidAt ? \Carbon\Carbon::parse($paidAt)->toIso8601String() : null,
            'marked_paid_by'  => $p->marked_paid_by ? (string) $p->marked_paid_by : ($p->created_by ? (string) $p->created_by : null),
            'notes'           => $p->notes ?: '',
            'created_at'      => optional($p->created_at)?->toIso8601String(),
            'updated_at'      => optional($p->updated_at)?->toIso8601String(),
        ];
    }

    protected function mapDashboardBooking(Booking $b): array
    {
        return [
            'id'             => (string) $b->id,
            'customer_id'    => $b->user_id ? (string) $b->user_id : null,
            'listing_id'     => $b->listing_id ? (string) $b->listing_id : null,
            'location_id'    => $b->venue_id ? (string) $b->venue_id : null,
            'booking_date'   => $b->date,
            'start_time'     => $b->start_time,
            'end_time'       => $b->end_time,
            'players'        => $b->players,
            'total'          => (float) ($b->total_price ?? 0),
            'payment_status' => $b->payment_status,
            'booking_status' => $b->status,
            'origin'         => $b->origin,
            'created_at'     => optional($b->created_at)?->toIso8601String(),
        ];
    }

    public function getPaymentsForExport($request)
    {
        $search     = $request->search ?? null;
        $method     = $request->method ?? null;
        $status     = $request->status ?? null;
        $from_date  = $request->from_date ?? null;
        $to_date    = $request->to_date ?? null;
        $exportAll  = $request->export_all == 1 || $request->export_all === '1';

        $query = $this->paymentModel::with([
            'booking',
            'user:id,name,email',
            'booking.venue:id,name',
            'booking.listing:id,name',
        ])
            ->latest()
            ->when($search, fn($query) => $this->applyPaymentSearch($query, $search))
            ->when($method, function ($query, $method) {
                $query->where('method', $method);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                $query->where(function ($q) use ($from_date, $to_date) {
                    $q->whereBetween('date', [$from_date, $to_date])
                        ->orWhere(function ($subQ) use ($from_date, $to_date) {
                            $subQ->whereNull('date')
                                ->whereBetween('created_at', [$from_date, $to_date]);
                        });
                });
            })
            ->when($from_date && !$to_date, function ($query) use ($from_date) {
                $query->where(function ($q) use ($from_date) {
                    $q->where('date', '>=', $from_date)
                        ->orWhere(function ($subQ) use ($from_date) {
                            $subQ->whereNull('date')
                                ->whereDate('created_at', '>=', $from_date);
                        });
                });
            })
            ->when($to_date && !$from_date, function ($query) use ($to_date) {
                $query->where(function ($q) use ($to_date) {
                    $q->where('date', '<=', $to_date)
                        ->orWhere(function ($subQ) use ($to_date) {
                            $subQ->whereNull('date')
                                ->whereDate('created_at', '<=', $to_date);
                        });
                });
            });

        $query = applyVenueFilterToPayments($query);

        if (!$exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page             = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    public function getDashboardTotals(): array
    {
        $totalQuery = $this->paymentModel::newQuery();
        $paidQuery = $this->paymentModel::where('status', 'paid');

        $totalQuery = applyVenueFilterToPayments($totalQuery);
        $paidQuery = applyVenueFilterToPayments($paidQuery);

        $total   = $totalQuery->sum('amount');
        $paid    = $paidQuery->sum('amount');
        $pending = $total - $paid;

        return [
            'paid_amount'    => $paid,
            'total_amount'   => $total,
            'pending_amount' => $pending,
        ];
    }

    public function exportPaymentsCsv($filters = []): array
    {
        $query = $this->paymentModel::with(['booking.user', 'booking.venue'])
            ->search($filters['search'] ?? '')
            ->byMethod($filters['method'] ?? null)
            ->byStatus($filters['status'] ?? null)
            ->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

        $query = applyVenueFilterToPayments($query);

        $payments = $query->get()->map(fn($p) => [
            'Payment ID' => $p->payment_id,
            'Booking #'  => $p->booking->id,
            'Customer'   => $p->customer_name,
            'Venue'      => $p->venue_name,
            'Amount'     => $p->amount,
            'Method'     => ucfirst($p->method),
            'Status'     => ucfirst($p->status),
            'Date'       => $p->date->format('Y-m-d'),
        ]);

        return $payments->toArray();
    }

    public function downloadInvoice($id)
    {
        $payment = $this->paymentModel::with(['user', 'booking.venue'])->findOrFail($id);

        $data = [
            'payment' => $payment,
            'date'    => now()->format('Y-m-d'),
            'company' => [
                'name'    => CompnayInfo::NAME,
                'email'   => CompnayInfo::EMAIL,
                'phone'   => CompnayInfo::PHONE,
                'address' => CompnayInfo::ADDRESS,
            ],
        ];

        $pdf = Pdf::loadView('pdfs.payment-invoice', $data);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, "invoice-{$payment->id}.pdf");
    }

    public function generatePaymentLink($request)
    {
        $validated = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            'amount'                         => 'sometimes|numeric|min:0',
            'selected_due_bookings'          => 'sometimes|array',
            'selected_due_bookings.*.id'     => 'sometimes|exists:bookings,id',
            'selected_due_bookings.*.amount' => 'sometimes|numeric|min:0',
        ]);

        try {

            $booking = $this->bookingModel::with('user')->findOrFail($validated['booking_id']);
            $user    = $booking->user;

            $totalAmount = $validated['amount'] ?? $this->calculateTotalAmount($booking, $validated['selected_due_bookings'] ?? []);

            $dueBookings = $this->validateDueBookings($booking, $validated['selected_due_bookings'] ?? []);

            $metadata = [
                'user_id'        => $user->id,
                'booking_id'     => $booking->id,
                'total_amount'   => $totalAmount,
                'payment_method' => 'online_payment',
                'booking_number' => $booking->booking_id,
                'due_bookings'   => json_encode($dueBookings->pluck('id')),
                'source'         => 'admin', // Admin-generated payment link (for QR codes, etc.)
            ];

            Stripe::setApiKey(config('services.stripe.secret'));

            // Use Checkout Session instead of PaymentLink for better control and consistency

            $returnUrls = $this->stripeService->checkoutReturnUrls();

            $linkCurrency = strtolower($booking->currency ?: Booking::currencyForVenue($booking->venue_id));

            if (StripeMinimumCharge::isBelowFloor((float) $totalAmount, $linkCurrency)) {
                return sendError(StripeMinimumCharge::message((float) $totalAmount, $linkCurrency), [], 422);
            }

            $session = \Stripe\Checkout\Session::create([
                'customer' => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $returnUrls['success_url'],
                'cancel_url' => $returnUrls['cancel_url'],
                'metadata' => $metadata,
                'line_items' => [[
                    'price_data' => [
                        // What this booking was quoted in. Was hard-coded 'qar',
                        // which charged a Pakistani booking of PKR 50 as QAR 50.
                        'currency' => $linkCurrency,
                        'unit_amount' => $totalAmount * 100,
                        'product_data' => [
                            'name' => "Payment for Booking #{$booking->booking_id}",
                        ],
                    ],
                    'quantity' => 1,
                ]],
            ]);

            \App\Models\StripeSession::create([
                'session_id'  => $session->id,
                'booking_id'  => $booking->id,
                'booking_ids' => array_values(array_unique(array_filter(array_merge(
                    [$booking->id],
                    $dueBookings->pluck('id')->toArray()
                )))),
                'source'      => 'admin',
                'processed'   => false,
            ]);

            $paymentLink = (object) [
                'id' => $session->id,
                'url' => $session->url,
            ];

            return sendSuccess('Link generated successfully', [
                'link'               => $paymentLink->url,
                'total_amount'       => $totalAmount,
                'payment_link_id'    => $paymentLink->id,
                'due_bookings_count' => count($dueBookings),
            ]);
        } catch (Exception $e) {
            Log::error("Failed to generate payment link for booking #{$validated['booking_id']}: " . $e->getMessage(), [
                'request'   => $request->all(),
                'exception' => $e,
            ]);
            return sendError('Failed to generate payment link: ' . $e->getMessage());
        }
    }

    private function calculateTotalAmount($booking, $selectedDueBookings)
    {
        $pending = ($booking->payment_status === 'deposit_paid' ? $booking->payment_breakdown['remaining_amount'] : $booking->total_price) ?? 0;
        $dueSum  = 0;
        if ($selectedDueBookings) {
            foreach ($selectedDueBookings as $due) {
                $dueSum += $due['amount'] ?? 0;
            }
        }
        return $pending + $dueSum;
    }

    private function validateDueBookings($booking, $selectedDueBookings)
    {
        $dueIds = collect($selectedDueBookings ?? [])->pluck('id');

        if ($dueIds->isEmpty()) {
            return collect();
        }

        $allDueBookings = $this->bookingModel::whereIn('id', $dueIds)->get();

        $foundIds = $allDueBookings->pluck('id');
        $missingIds = $dueIds->diff($foundIds);

        if ($missingIds->isNotEmpty()) {
            throw new Exception("Bookings not found: " . $missingIds->implode(', '));
        }

        $errors = [];
        $validBookings = collect();

        foreach ($allDueBookings as $dueBooking) {
            $bookingErrors = [];

            if ($dueBooking->id == $booking->id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} is the same as the main booking";
            }

            if ($dueBooking->user_id !== $booking->user_id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} belongs to a different user (User ID: {$dueBooking->user_id}, Expected: {$booking->user_id})";
            }

            if ($dueBooking->venue_id !== $booking->venue_id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} belongs to a different venue (Venue ID: {$dueBooking->venue_id}, Expected: {$booking->venue_id})";
            }

            if (!in_array($dueBooking->payment_status, ['pending', 'deposit_paid'])) {
                $currentStatus = $dueBooking->payment_status ?? 'null';
                $bookingErrors[] = "Booking #{$dueBooking->id} has invalid payment status: '{$currentStatus}' (Expected: 'pending' or 'deposit_paid')";
            }

            if ($dueBooking->status === BookingStatus::CANCELLED) {
                $bookingErrors[] = "Booking #{$dueBooking->id} is cancelled";
            }

            if (!empty($bookingErrors)) {
                $errors[] = "Booking #{$dueBooking->id}: " . implode('; ', $bookingErrors);
            } else {
                $validBookings->push($dueBooking);
            }
        }

        if (!empty($errors)) {
            throw new Exception('Invalid selected due bookings. ' . implode(' | ', $errors));
        }

        return $validBookings;
    }

    public function confirmPayment($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            'total_amount'                   => 'required|numeric|min:0',
            'payment_method'                 => 'required|in:cash,wire,cheque',
            'selected_due_bookings'          => 'nullable|array',
            'selected_due_bookings.*.id'     => 'exists:bookings,id',
            'selected_due_bookings.*.amount' => 'numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $mainBookingId   = $formData['booking_id'];
            $dueBookingsData = collect($formData['selected_due_bookings'] ?? []);

            $mainBookingForValidation = $this->bookingModel::findOrFail($mainBookingId);

            $dueTotal = 0;

            foreach ($dueBookingsData as $due) {
                $dueBooking = $this->bookingModel::findOrFail($due['id']);
                if ($dueBooking->user_id !== $mainBookingForValidation->user_id) {
                    return sendError("Due booking ({$dueBooking->booking_id}) belongs to different user.");
                }
                $dueTotal += $due['amount'];
            }

            $mainAmount = $formData['total_amount'] - $dueTotal;
            if ($mainAmount < 0) {
                return sendError('Invalid amounts: main amount cannot be negative');
            }

            $paymentMethodMap = [
                'cash'   => PaymentMethod::CASH,
                'cheque' => PaymentMethod::CHEQUE,
                'wire'   => PaymentMethod::WIRE_TRANSFER,
            ];

            $method = $paymentMethodMap[$formData['payment_method']];

            $allBookingsData = collect([$mainBookingId => ['amount' => $mainAmount]]);
            foreach ($dueBookingsData as $due) {
                $allBookingsData[$due['id']] = ['amount' => $due['amount']];
            }

            $mainBooking = null;
            foreach ($allBookingsData as $bookingId => $data) {
                $booking = $this->bookingModel::lockForUpdate()->findOrFail($bookingId);

                $paymentAmount = $data['amount'];
                $isMain        = ($bookingId == $mainBookingId);
                if ($isMain) {
                    $mainBooking = $booking;
                }

                $hasDeposit = $booking->payments()
                    ->where('type', PaymentType::DEPOSIT)
                    ->where('status', PaymentStatus::PAID)
                    ->exists();

                $paymentType = $hasDeposit ? PaymentType::REMAINING : PaymentType::FULL;

                $pendingPayment = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
                    ->first();

                $notes = ($paymentType == PaymentType::REMAINING ? 'Remaining' : 'Full') . " payment for booking #{$booking->id}";

                if ($pendingPayment) {

                    $notes = str_replace('pending', 'paid via ' . strtoupper($formData['payment_method']), $pendingPayment->notes);

                    $pendingPayment->update([
                        'notes'  => $notes,
                        'method' => $method,
                        'status' => PaymentStatus::PAID,
                        'date'   => now()->format('Y-m-d'),
                    ]);
                }

                $bookingUpdates = [
                    'full_payment_received' => true,
                    'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                ];

                if ($isMain && $booking->payment_method === 'deposit_remaining_on_arrival') {
                    $paymentBreakdown                     = $booking->payment_breakdown ?? [];
                    $paymentBreakdown['remaining_method'] = $formData['payment_method'];
                    $bookingUpdates['payment_breakdown']  = $paymentBreakdown;
                }

                $booking->update($bookingUpdates);

                if ($mainBooking) {
                    $mainBooking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => 'payment_completed',
                        'description' => 'Payment completed via ' . strtoupper($formData['payment_method']),
                    ]);
                }

                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    autoConfirmBookingIfPaid($booking);
                }
            }

            DB::commit();

            $booking = $mainBooking->load([
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
                'user:id,name,email,phone',
            ]);

            return sendSuccess('Payment confirmed successfully.', compact('booking'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Confirm payment failed: ' . $e->getMessage(), ['trace' => $e]);
            return sendError('Payment confirmation failed. Please try again.');
        }
    }

    public function customerPaymentMethods()
    {
        try {
            $user           = auth()->user();
            $paymentMethods = $user->paymentMethods()->orderBy('created_at', 'desc')->get();

            return sendSuccess("Payment methods fetched successfully", compact('paymentMethods'));
        } catch (Exception $e) {
            Log::error('Get Payment Methods Error: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong");
        }
    }

    public function markPaymentAsReceived($request, $id)
    {
        $validated = $request->validate([
            'method'                    => 'required|in:cash,cheque,wire_transfer,credit_card,online',
            'auto_confirm_booking'      => 'nullable|boolean',
            'note'                      => 'nullable|string|max:1000',
            'stripe_charge_id'          => 'nullable|string|max:255',
            'stripe_payment_intent_id'  => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $payment = $this->paymentModel::with('booking')->lockForUpdate()->findOrFail($id);

            if ($payment->status === PaymentStatus::PAID) {
                DB::rollBack();
                return sendError('This payment is already marked as paid.');
            }

            $methodMap = [
                'cash'          => PaymentMethod::CASH,
                'cheque'        => PaymentMethod::CHEQUE,
                'wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                'credit_card'   => PaymentMethod::CREDIT_CARD,
                'online'        => PaymentMethod::ONLINE,
            ];

            $methodLabel = ucwords(str_replace('_', ' ', $validated['method']));
            $adminNote   = isset($validated['note']) ? trim($validated['note']) : '';

            $existingNotes = $payment->notes ?? "Payment for booking #{$payment->booking_id}";
            $updatedNotes  = str_replace('pending', "paid via {$methodLabel}", $existingNotes);
            if ($updatedNotes === $existingNotes) {
                $updatedNotes = $existingNotes . " — paid via {$methodLabel}";
            }
            if ($adminNote !== '') {
                $updatedNotes .= " | Admin note: {$adminNote}";
            }

            [$stripeChargeId, $stripePaymentIntentId, $stripeLinkError] = $this->resolveStripeIdsForMarkReceived(
                $validated['stripe_charge_id'] ?? null,
                $validated['stripe_payment_intent_id'] ?? null
            );

            if ($stripeLinkError) {
                DB::rollBack();

                return sendError($stripeLinkError);
            }

            $paymentUpdates = [
                'status' => PaymentStatus::PAID,
                'method' => $methodMap[$validated['method']],
                'date'   => now()->format('Y-m-d'),
                'notes'  => $updatedNotes,
            ];

            if ($stripeChargeId) {
                $paymentUpdates['charge_id'] = $stripeChargeId;
            }
            if ($stripePaymentIntentId) {
                $paymentUpdates['payment_intent_id'] = $stripePaymentIntentId;
            }

            if ($stripeChargeId || $stripePaymentIntentId) {
                $paymentUpdates['method'] = PaymentMethod::ONLINE;
                $stripeNote = 'Stripe linked by admin';
                if ($stripeChargeId) {
                    $stripeNote .= " | Charge: {$stripeChargeId}";
                }
                if ($stripePaymentIntentId) {
                    $stripeNote .= " | Payment intent: {$stripePaymentIntentId}";
                }
                $updatedNotes .= " | {$stripeNote}";
                $paymentUpdates['notes'] = $updatedNotes;
            }

            $payment->update($paymentUpdates);

            $booking = $payment->booking;
            if (! $booking) {
                DB::rollBack();
                return sendError('Booking not found for this payment.');
            }

            $booking = $this->bookingModel::lockForUpdate()->findOrFail($booking->id);

            $hasPendingPayments = $booking->payments()->where('status', PaymentStatus::PENDING)->exists();
            $totalPaid  = (float) $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
            $totalPrice = (float) $booking->total_price;
            $allPaid    = ! $hasPendingPayments && $totalPaid >= $totalPrice;

            $hasDepositPayment = $booking->payments()
                ->where('type', PaymentType::DEPOSIT)
                ->where('status', PaymentStatus::PAID)
                ->exists();

            if ($allPaid) {
                $finalPaymentStatus = BookingPaymentStatus::FULLY_PAID;
            } elseif ($hasDepositPayment) {
                $finalPaymentStatus = BookingPaymentStatus::DEPOSIT_PAID;
            } else {
                $finalPaymentStatus = $booking->payment_status;
            }

            $booking->update([
                'payment_status'        => $finalPaymentStatus,
                'full_payment_received' => $allPaid,
            ]);

            $autoConfirmRequested = (bool) ($validated['auto_confirm_booking'] ?? false);
            $bookingConfirmed     = false;

            if (
                $autoConfirmRequested
                && $allPaid
                && $booking->status === BookingStatus::PENDING
            ) {
                autoConfirmBookingIfPaid($booking);
                $booking->refresh();
                $bookingConfirmed = $booking->status === BookingStatus::CONFIRMED;
            }

            $authUser    = auth()->user();
            $description = "Payment #{$payment->payment_id} marked as received via {$methodLabel} by {$authUser->name}.";
            if ($bookingConfirmed) {
                $description .= ' Booking auto-confirmed.';
            }
            if ($adminNote !== '') {
                $description .= " Note: {$adminNote}";
            }
            if ($stripeChargeId || $stripePaymentIntentId) {
                $description .= ' Stripe record linked.';
                if ($stripeChargeId) {
                    $description .= " Charge: {$stripeChargeId}.";
                }
                if ($stripePaymentIntentId) {
                    $description .= " Payment intent: {$stripePaymentIntentId}.";
                }
            }

            $booking->history()->create([
                'user_id'     => $authUser->id,
                'action'      => 'payment_received',
                'description' => $description,
            ]);

            if ($allPaid) {
                regenerateBookingInvoice($booking);
            }

            DB::commit();

            notifyVenueStaffRefresh($booking->venue_id, [
                'event'             => 'payment_received',
                'payment_id'        => $payment->id,
                'booking_id'        => $booking->id,
                'venue_id'          => $booking->venue_id,
                'all_paid'          => $allPaid,
                'booking_confirmed' => $bookingConfirmed,
            ]);

            $booking->load([
                'payments',
                'venue:id,name',
                'listing:id,name',
                'user:id,name,email,phone',
            ]);

            return sendSuccess('Payment marked as received successfully.', [
                'payment'           => $payment->fresh(),
                'booking'           => $booking,
                'all_paid'          => $allPaid,
                'booking_confirmed' => $bookingConfirmed,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Mark payment as received failed: ' . $e->getMessage(), [
                'payment_id' => $id,
                'exception'  => $e,
            ]);
            return sendError('Failed to mark payment as received.');
        }
    }

    /**
     * The two answers that are not "we took the money".
     *
     * cash_on_arrival  — the customer pays at the venue. The arrangement is recorded
     *                    and the pending rows stay pending, because the money really
     *                    is still owed.
     * payment_skipped  — the booking is waived. The pending rows are CANCELLED so it
     *                    stops showing as in debt, and the booking reads as skipped.
     *
     * Runs inside the caller's transaction.
     *
     * @param  \Illuminate\Support\Collection  $bookingIds  main booking + any due ones
     */
    protected function recordUncollectedPayment($mainBooking, $bookingIds, string $method, $user)
    {
        $skipped = $method === BookingPaymentMethod::PAYMENT_SKIPPED;
        $touched = 0;

        foreach ($bookingIds as $bookingId) {
            $booking = $this->bookingModel::lockForUpdate()->findOrFail($bookingId);

            $pending = $booking->payments()->where('status', PaymentStatus::PENDING)->get();

            if ($pending->isEmpty()) {
                continue;
            }

            $touched++;

            if ($skipped) {
                foreach ($pending as $payment) {
                    $payment->update([
                        'status' => PaymentStatus::CANCELLED,
                        'notes'  => trim(($payment->notes ?? '') . ' — payment skipped'),
                    ]);
                }
            }

            $booking->update([
                'payment_method'        => $method,
                'payment_status'        => $skipped
                    ? BookingPaymentStatus::PAYMENT_SKIPPED
                    : BookingPaymentStatus::PENDING,
                // Neither answer means the money arrived.
                'full_payment_received' => false,
            ]);
        }

        if (! $touched) {
            DB::rollBack();

            return sendError('No pending payments found for the selected bookings.');
        }

        $label = str_replace('_', ' ', $method);

        $mainBooking->history()->create([
            'user_id'     => $user->id,
            'action'      => $skipped ? 'payment_skipped' : 'payment_on_arrival',
            'description' => $skipped
                ? 'Payment waived — marked as ' . $label . '.'
                : 'Payment to be collected on arrival (' . $label . ').',
        ]);

        DB::commit();

        $booking = $mainBooking->fresh([
            'addOns', 'history', 'payments', 'invoices', 'promocode', 'latestInvoice',
            'venue:id,name', 'listing:id,name', 'resourcesAllocated',
            'history.user:id,name', 'user:id,name,email,phone',
        ]);

        return sendSuccess(
            $skipped ? 'Payment skipped for this booking.' : 'Payment will be collected on arrival.',
            compact('booking')
        );
    }

    /**
     * Settle a booking's outstanding payments the way the front desk actually
     * handled them.
     *
     * `payment_status` is a misnomer kept for the clients already sending it: the
     * values are BookingPaymentMethod — HOW the money was handled, not a status.
     *
     * It used to accept five, while GET bookings/payment-methods offered seven. The
     * three it refused — online_payment, cash_on_arrival, payment_skipped — came
     * back as "The selected payment status is invalid", so a method the picker had
     * just listed could not be submitted.
     *
     * They are not all the same kind of answer, which is why widening the rule is
     * not enough on its own:
     *   • MONEY TAKEN  (cash/cheque/card/wire/online) — settle the pending rows.
     *   • NOT TAKEN    (cash_on_arrival)              — record the arrangement and
     *     leave the money owed; marking it paid would invent a payment.
     *   • NOT OWED     (payment_skipped)              — waive it: cancel the rows so
     *     the booking stops reading as in debt.
     */
    public function markBookingAsPaid($request)
    {
        $validated = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            // Exactly what GET bookings/payment-methods offers, derived from the same
            // constant rather than hand-listed — that drift is what made a method the
            // picker had just shown come back as "invalid". paid_wire is a legacy
            // alias with no constant and no enum slot; it is normalised below.
            'payment_status'                 => ['required', Rule::in(array_merge(
                array_diff(BookingPaymentMethod::list(), [BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL]),
                ['paid_wire']
            ))],
            'selected_due_bookings'          => 'nullable|array',
            'selected_due_bookings.*.id'     => 'exists:bookings,id',
            'selected_due_bookings.*.amount' => 'numeric|min:0',
        ]);

        // bookings.payment_method is an ENUM without a 'paid_wire' member, so this
        // alias validated and then failed on the UPDATE with a truncation error —
        // "Failed to update booking payments" and no clue why. Fold it into the
        // value the column actually holds.
        if ($validated['payment_status'] === 'paid_wire') {
            $validated['payment_status'] = BookingPaymentMethod::PAID_WIRE_TRANSFER;
        }

        try {
            DB::beginTransaction();

            $mainBookingId   = $validated['booking_id'];
            $dueBookingsData = collect($validated['selected_due_bookings'] ?? []);
            $user            = auth()->user();

            $mainBookingForValidation = $this->bookingModel::findOrFail($mainBookingId);

            foreach ($dueBookingsData as $due) {
                $dueBooking = $this->bookingModel::findOrFail($due['id']);
                if ($dueBooking->user_id !== $mainBookingForValidation->user_id) {
                    DB::rollBack();
                    return sendError("Due booking ({$dueBooking->booking_id}) belongs to different user.");
                }
            }

            $paymentMethodMap = [
                'paid_cash'          => PaymentMethod::CASH,
                'paid_cheque'        => PaymentMethod::CHEQUE,
                'paid_wire'          => PaymentMethod::WIRE_TRANSFER,
                'paid_wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                'paid_credit_card'   => PaymentMethod::CREDIT_CARD,
                'online_payment'     => PaymentMethod::ONLINE,
            ];

            // No money changed hands for these two, so they must not walk the
            // settle-the-pending-rows path below.
            if (! isset($paymentMethodMap[$validated['payment_status']])) {
                return $this->recordUncollectedPayment(
                    $mainBookingForValidation,
                    $allBookingIdsForUncollected = collect([$mainBookingId])->merge($dueBookingsData->pluck('id'))->unique(),
                    $validated['payment_status'],
                    $user
                );
            }

            $paymentMethod = $paymentMethodMap[$validated['payment_status']];
            $paymentMethodName = str_replace('paid_', '', str_replace('_', ' ', $validated['payment_status']));

            $allBookingIds = collect([$mainBookingId])->merge($dueBookingsData->pluck('id'))->unique();

            $mainBooking = null;
            $hasAnyPendingPayments = false;

            foreach ($allBookingIds as $bookingId) {
                $booking = $this->bookingModel::lockForUpdate()->findOrFail($bookingId);
                $isMain  = ($bookingId == $mainBookingId);

                if ($isMain) {
                    $mainBooking = $booking;
                }

                // Get ALL pending payments (deposit, remaining, full)
                $pendingPayments = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->get();

                if ($pendingPayments->isEmpty()) {
                    continue;
                }

                $hasAnyPendingPayments = true;

                // Mark all pending payments as paid
                foreach ($pendingPayments as $payment) {
                    $notes = $payment->notes ?? "Payment for booking #{$booking->booking_id}";
                    $notes = str_replace('pending', 'paid via ' . $paymentMethodName, $notes);
                    $notes = str_replace('Deposit payment pending', 'Deposit payment paid via ' . $paymentMethodName, $notes);
                    $notes = str_replace('Remaining payment pending', 'Remaining payment paid via ' . $paymentMethodName, $notes);

                    $payment->update([
                        'notes'  => $notes,
                        'method' => $paymentMethod,
                        'status' => PaymentStatus::PAID,
                        'date'   => now()->format('Y-m-d'),
                    ]);
                }

                // Determine final payment status based on payment types
                $hasDepositPayment = $booking->payments()
                    ->where('type', PaymentType::DEPOSIT)
                    ->where('status', PaymentStatus::PAID)
                    ->exists();

                $totalPaid  = (float) $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
                $totalPrice = (float) $booking->total_price;
                $amountDue  = max(0, round($totalPrice - $totalPaid, 2));

                if ($amountDue > 0 && ! $booking->is_free) {
                    $hasPendingShortfall = $booking->payments()
                        ->where('status', PaymentStatus::PENDING)
                        ->exists();

                    if (! $hasPendingShortfall) {
                        $booking->payments()->create([
                            'date'       => null,
                            'user_id'    => $booking->user_id,
                            'amount'     => $amountDue,
                            // Without this the row shows as "Unknown currency".
                            'currency'   => $booking->venue
                                ? ($booking->venue->currency_override ?: ($booking->venue->country?->currency?->code ?: ''))
                                : '',
                            'type'       => PaymentType::FULL,
                            'method'     => PaymentMethod::CASH,
                            'status'     => PaymentStatus::PENDING,
                            'notes'      => "Additional payment required for booking #{$booking->booking_id} (outstanding balance)",
                            'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                        ]);
                    }
                }

                $totalPaid  = (float) $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
                $amountDue  = max(0, round((float) $booking->total_price - $totalPaid, 2));

                $finalPaymentStatus = ($amountDue <= 0 && $totalPaid > 0)
                    ? BookingPaymentStatus::FULLY_PAID
                    : ($hasDepositPayment
                        ? BookingPaymentStatus::DEPOSIT_PAID
                        : BookingPaymentStatus::PENDING);

                $bookingUpdates = [
                    'full_payment_received' => ($finalPaymentStatus === BookingPaymentStatus::FULLY_PAID),
                    'payment_method'        => $validated['payment_status'],
                    'payment_status'        => $finalPaymentStatus,
                ];

                if ($isMain && $booking->payment_method === 'deposit_remaining_on_arrival') {
                    $paymentBreakdown                     = $booking->payment_breakdown ?? [];
                    $paymentBreakdown['remaining_method'] = str_replace('paid_', '', $validated['payment_status']);
                    $bookingUpdates['payment_breakdown']  = $paymentBreakdown;
                }

                $booking->update($bookingUpdates);

                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    autoConfirmBookingIfPaid($booking);
                    if ($isMain) {
                        regenerateBookingInvoice($booking);
                    }
                }
            }

            if (!$hasAnyPendingPayments) {
                DB::rollBack();
                return sendError('No pending payments found for the selected bookings.');
            }

            if ($mainBooking) {
                $mainBooking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => 'payment_completed',
                    'description' => 'All pending payments marked as paid via ' . $paymentMethodName . ($dueBookingsData->isNotEmpty() ? ' (including due bookings)' : ''),
                ]);
            }

            DB::commit();

            $booking = $mainBooking->load([
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'latestInvoice',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
                'user:id,name,email,phone',
            ]);

            return sendSuccess('Booking payments updated successfully.', compact('booking'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Mark booking as paid failed: ' . $e->getMessage(), [
                'booking_id'     => $validated['booking_id'] ?? null,
                'payment_status' => $validated['payment_status'] ?? null,
                'trace'          => $e,
            ]);
            return sendError('Failed to update booking payments. Please try again.');
        }
    }

    /**
     * What to take online now for a deposit booking.
     *
     * The deposit means different things on the two surfaces, and getting them
     * crossed charges the customer something other than the figure they were shown:
     *
     *   marketplace — the deposit IS Bookboll's platform fee, so it must track the
     *                 LIVE commission (listing override → country rate → default).
     *                 It used to read payment_options.deposit.percentage, which the
     *                 dashboard only writes when a listing is saved. Raising a
     *                 country's rate therefore changed nothing for listings already
     *                 live: Pakistan went to 50% while its listings kept collecting
     *                 the 15% snapshotted months earlier. The Countries page promises
     *                 the opposite in as many words — "facility bookings use this
     *                 rate live".
     *
     *   site        — the provider's own white-label site. Bookboll takes nothing
     *                 here, and the deposit is purely the provider's own no-show
     *                 protection (site_deposit_percentage). The storefront has always
     *                 QUOTED that number; this method is what finally charges it.
     *                 Until now the surface never reached the server, so an own-site
     *                 booking quoted the provider's 10% and billed the marketplace
     *                 percentage instead.
     *
     * A fixed deposit amount is surface-agnostic and still wins: it is charged per
     * booking, matching paymentSplit() in the storefront's booking.js.
     */
    protected function depositToCharge(Listing $listing, float $totalPrice, int $totalBookings, string $surface): float
    {
        $po      = is_array($listing->payment_options) ? $listing->payment_options : [];
        $deposit = is_array($po['deposit'] ?? null) ? $po['deposit'] : [];

        $isSite = $surface === CommissionPolicy::CHANNEL_SITE;

        // A fixed deposit is the provider's own no-show protection and still
        // wins on the MARKETPLACE. It must not win on the merchant's own site:
        // there the deposit is Bookboll's commission, so letting a stored amount
        // stand would let a merchant set their own fee.
        $amount = (float) ($deposit['amount'] ?? $po['deposit_amount'] ?? 0);
        if ($amount > 0 && ! $isSite) {
            return $amount * max(1, $totalBookings);
        }

        // Derived here, never read from the listing's stored site percentage —
        // that value is the preview the merchant was shown, and readmeAlamgir is
        // explicit that client-supplied commission percentages are not authority.
        $pct = $isSite
            ? CommissionPolicy::effectiveRatePct($listing, CommissionPolicy::CHANNEL_SITE)
            : app(BookingWalletService::class)->courtCommissionPct($listing);

        if ($pct <= 0) {
            return 0.0;
        }

        // Rounded in minor units, so a fractional rate lands on the real money.
        // This used to floor() in major units, which charged 7 on a 7.50 fee —
        // the merchant saw one number and the processor took another.
        $currency = CommissionPolicy::currencyFor($listing);
        $feeMinor = Money::percentOf(Money::toMinor($totalPrice, $currency), $pct);

        return Money::toMajor($feeMinor, $currency);
    }

    public function createCheckoutSession(array $validated, $user)
    {
        try {
            DB::beginTransaction();

            Stripe::setApiKey(config('services.stripe.secret'));


            $bookingService = app(\App\Services\BookingService::class);

            // prepareCustomerCheckout() decides capacity by counting. Take the
            // listing's booking mutex first, inside this transaction, so a booking
            // made elsewhere cannot land between that count and the insert below.
            $bookingService->lockListingForBooking($validated['listing_id'] ?? null);

            $prepared = $bookingService->prepareCustomerCheckout($validated, $user);
            if (! empty($prepared['errors'])) {
                DB::rollBack();

                // Same wording the cash path gives: paying online must not be the
                // reason a shopper is told only "Validation failed".
                return sendError(
                    $bookingService->customerCheckoutErrorMessage($prepared['errors']),
                    $prepared['errors'],
                    422
                );
            }

            $combinedSubtotal    = $prepared['combinedSubtotal'];
            $totalBookings       = $prepared['totalBookings'];
            $totalDiscount       = $prepared['totalDiscount'];
            $giftCardBalanceUsed = $prepared['giftCardBalanceUsed'];
            $listing             = $prepared['listing'];

            $totalPrice = round($combinedSubtotal - $totalDiscount - $giftCardBalanceUsed, 2);
            if ($totalPrice < 0) {
                $totalPrice = 0;
            }

            $paymentAmount = $totalPrice;
            $paymentType = 'full';

            if ($validated['payment_method'] === 'deposit_remaining_on_arrival') {
                // The deposit is Bookboll's share, so a rewards discount comes off
                // it: work the deposit out on the pre-reward price, then reduce it.
                // Mirrors BookingService::createSingleBooking, so the Stripe charge
                // and the deposit row it settles are the same number.
                $loyaltyDiscount = max(0.0, (float) ($prepared['loyaltyDiscount'] ?? 0) + (float) ($prepared['platformPromoDiscount'] ?? 0));
                $paymentAmount = max(0.0, round($this->depositToCharge(
                    $listing,
                    $totalPrice + $loyaltyDiscount,
                    $totalBookings,
                    (string) ($validated['surface'] ?? 'marketplace')
                ) - $loyaltyDiscount, 2));
                $paymentType = 'deposit';
            }

            if ($totalPrice <= 0) {
                $paymentAmount = 0;
            }

            $bookingRequest = new \Illuminate\Http\Request();
            $bookingRequest->merge(array_merge($validated, [
                'payment_method' => $validated['payment_method'],
                'payment_intent_id' => null,
                'user_id' => $user->id,
            ]));
            $bookingRequest->setUserResolver(function () use ($user) {
                return $user;
            });

            $bookingResult = $bookingService->createCustomerBooking($bookingRequest, $prepared);
            $bookingResultData = $bookingResult->getData();

            if (! $bookingResultData->success) {
                DB::rollBack();
                return $bookingResult;
            }

            $createdBookings = $bookingResult->getData()->data->bookings ?? [];
            if (empty($createdBookings)) {
                throw new Exception('No bookings were created');
            }

            $allBookingIds = collect($createdBookings)->pluck('id')->toArray();
            $mainBookingId = $allBookingIds[0] ?? null;
            if (!$mainBookingId) {
                throw new Exception('Failed to get main booking ID');
            }

            $paymentAmount = (float) Payment::whereIn('booking_id', $allBookingIds)->where('type', $paymentType === 'deposit' ? PaymentType::DEPOSIT : PaymentType::FULL)->sum('amount');
            if ($paymentAmount <= 0 && $paymentType === 'deposit' && $totalPrice > 0) {
                foreach ($allBookingIds as $id) {
                    Payment::where('booking_id', $id)->where('type', PaymentType::DEPOSIT)->update(['status' => PaymentStatus::PAID, 'date' => now()->toDateString()]);
                    $booking = Booking::findOrFail($id);
                    $booking->update(['payment_status' => BookingPaymentStatus::DEPOSIT_PAID, 'status' => BookingStatus::CONFIRMED, 'full_payment_received' => false]);
                    if ($booking->loyalty_quote_id && ($quote = \App\Models\LoyaltyCheckoutQuote::where('quote_id',$booking->loyalty_quote_id)->first())) app(LoyaltyRedemptionService::class)->consume($quote,$booking->id);
                    $booking->history()->create(['user_id'=>$user->id,'action'=>'payment_received','description'=>'Deposit covered by Bookboll discount; arrival balance remains pending.']);
                }
                DB::commit();
                return sendSuccess('Booking confirmed; the discounted deposit is covered. Pay the remaining balance at the venue.', ['bookings' => Booking::whereIn('id', $allBookingIds)->get(), 'free_booking' => true, 'remaining_on_arrival' => true]);
            }
            if ($paymentAmount <= 0) {
                $this->markBookingsAsFullyPaidFree($createdBookings, $totalPrice, $totalDiscount);
                DB::commit();
                return sendSuccess('Booking confirmed successfully', [
                    'free_booking' => true,
                    'bookings' => $createdBookings,
                    'url' => null,
                ]);
            }

            if ($validated['payment_method'] === 'online_payment') {
                Booking::whereIn('id', $allBookingIds)->update([
                    'payment_status' => BookingPaymentStatus::PENDING,
                    'payment_method' => BookingPaymentMethod::ONLINE_PAYMENT,
                ]);
            }

            $returnUrls = $this->stripeService->checkoutReturnUrls();

            $mainBooking = Booking::with(['venue:id,name', 'listing:id,name'])->find($mainBookingId);
            $venueName = $mainBooking?->venue?->name ?? $listing->venue?->name ?? 'Venue';
            $listingName = $mainBooking?->listing?->name ?? $listing->name ?? 'Facility';
            $bookingRef = $mainBooking?->booking_id ?? "BKN#{$mainBookingId}";

            $totalSlots = $totalBookings;

            // `currency` is selected because the checkout below charges in it;
            // without it the column is absent and the fallback silently decides.
            $allBookings = Booking::whereIn('id', $allBookingIds)->orderBy('id')->get(['id', 'booking_id', 'date', 'start_time', 'end_time', 'currency']);
            $slotLines = [];
            foreach ($allBookings as $b) {
                $d = \Carbon\Carbon::parse($b->date)->format('M j, Y');
                $startAmPm = $b->start_time ? \Carbon\Carbon::parse($b->start_time)->format('g:i A') : '';
                $endAmPm = $b->end_time ? \Carbon\Carbon::parse($b->end_time)->format('g:i A') : '';
                $t = trim($startAmPm . ($startAmPm && $endAmPm ? ' - ' : '') . $endAmPm);
                $slotLines[] = $t ? "{$d} {$t}" : $d;
            }
            $allSlotsSummary = implode('; ', $slotLines);

            $productName = $paymentType === 'deposit'
                ? "Deposit: {$listingName} @ {$venueName} ({$totalSlots} slots)"
                : "Payment: {$listingName} @ {$venueName} ({$totalSlots} slots)";

            $productDescription = trim(implode(' | ', array_filter([
                "Slots ({$totalSlots}): " . $allSlotsSummary,
                ! empty($validated['promocode'] ?? null) ? "Promo: {$validated['promocode']}" : null,
                "Subtotal: QAR " . number_format($combinedSubtotal, 2),
                $totalDiscount > 0 ? "Discount: -QAR " . number_format($totalDiscount, 2) : null,
                "Total: QAR " . number_format($totalPrice, 2),
                $paymentType === 'deposit' ? "Deposit due: QAR " . number_format($paymentAmount, 2) : null,
            ])));
            $productDescription = strlen($productDescription) > 500 ? substr($productDescription, 0, 497) . '...' : $productDescription;

            // Every booking in one checkout belongs to the same listing, so they
            // share a currency. Taken from the booking rather than guessed: it is
            // stamped at creation by Booking::currencyForVenue() and is what the
            // customer was actually quoted. This line was hard-coded 'qar'.
            $checkoutCurrency = strtolower(
                $allBookings->first()?->currency
                    ?: Booking::currencyForVenue($listing->venue_id ?? null)
            );

            // Refuse below Stripe's floor here rather than letting Stripe do it:
            // its refusal talks about cent conversions and reaches the customer
            // verbatim. Ours names the amount and the way out.
            if (StripeMinimumCharge::isBelowFloor((float) $paymentAmount, $checkoutCurrency)) {
                DB::rollBack();

                return sendError(StripeMinimumCharge::message((float) $paymentAmount, $checkoutCurrency), [], 422);
            }

            $sessionData = [
                'customer' => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $returnUrls['success_url'],
                'cancel_url' => $returnUrls['cancel_url'],
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'user_email' => $user->email ?? '',
                    'user_name' => $user->name ?? '',
                    'booking_id' => (string) $mainBookingId,
                    'booking_ref' => $bookingRef,
                    'all_booking_ids' => json_encode($allBookingIds),
                    'all_booking_refs' => $allBookings->pluck('booking_id')->filter()->implode(','),
                    'all_slots' => $allSlotsSummary,
                    'venue_id' => (string) ($listing->venue_id ?? ''),
                    'venue_name' => $venueName,
                    'listing_id' => (string) ($listing->id ?? ''),
                    'listing_name' => $listingName,
                    'payment_type' => $paymentType,
                    'payment_method' => $validated['payment_method'],
                    'payment_amount_qar' => (string) $paymentAmount,
                    'subtotal_qar' => (string) $combinedSubtotal,
                    'discount_qar' => (string) $totalDiscount,
                    'total_qar' => (string) $totalPrice,
                    'quantity' => (string) $totalSlots,
                    'promocode' => $validated['promocode'] ?? '',
                    'source' => 'customer',
                    // The storefront this booking was made on, so Stripe's return lands
                    // the customer back on the merchant's own site and not the marketplace.
                    'return_origin' => (string) TenantOrigins::storefrontOriginFromRequest(request()),
                    // Set when the checkout started in the mobile app, so the return
                    // reopens the app instead of the website — see MobileAppReturn.
                    MobileAppReturn::METADATA_KEY => MobileAppReturn::targetFromRequest(request()),
                ],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $checkoutCurrency,
                        'unit_amount' => (int) round($paymentAmount * 100),
                        'product_data' => [
                            'name' => $productName,
                            'description' => $productDescription,
                            'metadata' => [
                                'booking_ref' => $bookingRef,
                                'venue' => $venueName,
                                'listing' => $listingName,
                                'slot_count' => (string) $totalSlots,
                                'slots' => strlen($allSlotsSummary) <= 500 ? $allSlotsSummary : substr($allSlotsSummary, 0, 497) . '...',
                            ],
                        ],
                    ],
                    'quantity' => 1,
                ]],
            ];

            $user->createOrGetStripeCustomer();
            if (!$user->stripe_id) throw new Exception('Failed to create Stripe customer');
            $sessionData['customer'] = $user->stripe_id;
            $session = \Stripe\Checkout\Session::create($sessionData);

            \App\Models\StripeSession::create([
                'session_id'  => $session->id,
                'booking_id'  => $mainBookingId,
                'booking_ids' => $allBookingIds,
                'source'      => 'customer',
                'processed'   => false,
            ]);

            DB::commit();

            return sendSuccess('Checkout session created successfully', [
                'url' => $session->url,
                'session_id' => $session->id,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create checkout session error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'validated' => $validated,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function markBookingsAsFullyPaidFree(array $createdBookings, float $totalPrice, float $totalDiscount): void
    {
        foreach ($createdBookings as $bookingItem) {
            $bookingId = $bookingItem instanceof Booking
                ? $bookingItem->id
                : (is_array($bookingItem) ? ($bookingItem['id'] ?? null) : ($bookingItem->id ?? null));
            $booking = $bookingItem instanceof Booking ? $bookingItem : Booking::find($bookingId);
            if (!$booking) {
                continue;
            }

            $booking->update([
                'payment_status' => BookingPaymentStatus::FULLY_PAID,
                'full_payment_received' => true,
                'payment_breakdown' => [
                    'deposit_amount'   => 0,
                    'deposit_method'   => null,
                    'remaining_amount' => 0,
                    'remaining_method' => null,
                ],
            ]);

            $booking->payments()->where('status', PaymentStatus::PENDING)->update([
                'status' => PaymentStatus::PAID,
                'date' => now()->format('Y-m-d'),
                'notes' => 'Free booking - fully covered by promocode',
            ]);

            $booking->history()->create([
                'user_id' => $booking->user_id,
                'action' => 'payment_received',
                'description' => 'Booking fully covered by promocode – no payment required',
            ]);

            $user = $booking->user;
            if ($user) {
                createBookingInvoiceWithNotification($booking, $user);
            }
            autoConfirmBookingIfPaid($booking);
            if ($booking->loyalty_quote_id && ($quote = \App\Models\LoyaltyCheckoutQuote::where('quote_id',$booking->loyalty_quote_id)->first())) app(LoyaltyRedemptionService::class)->consume($quote,$booking->id);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [chargeId, paymentIntentId, errorMessage]
     */
    protected function resolveStripeIdsForMarkReceived(?string $chargeId, ?string $paymentIntentId): array
    {
        $chargeId        = $chargeId ? trim($chargeId) : null;
        $paymentIntentId = $paymentIntentId ? trim($paymentIntentId) : null;

        if (! $chargeId && ! $paymentIntentId) {
            return [null, null, null];
        }

        if ($chargeId && ! preg_match('/^(ch_|py_)/', $chargeId)) {
            return [null, null, 'Stripe Charge ID must start with ch_ or py_ (e.g. ch_3ABC...).'];
        }

        if ($paymentIntentId && ! str_starts_with($paymentIntentId, 'pi_')) {
            return [null, null, 'Stripe Payment Intent ID must start with pi_ (e.g. pi_3ABC...).'];
        }

        if ($chargeId && ! $paymentIntentId) {
            $secret = config('services.stripe.secret');
            if ($secret) {
                try {
                    Stripe::setApiKey($secret);
                    $charge = Charge::retrieve($chargeId);
                    $paymentIntentId = is_string($charge->payment_intent)
                        ? $charge->payment_intent
                        : ($charge->payment_intent->id ?? null);
                } catch (Exception $e) {
                    Log::warning('Could not resolve payment intent from Stripe charge', [
                        'charge_id' => $chargeId,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($paymentIntentId && ! $chargeId) {
            $secret = config('services.stripe.secret');
            if ($secret) {
                try {
                    Stripe::setApiKey($secret);
                    $intent   = PaymentIntent::retrieve($paymentIntentId);
                    $chargeId = is_string($intent->latest_charge)
                        ? $intent->latest_charge
                        : ($intent->latest_charge->id ?? null);
                } catch (Exception $e) {
                    Log::warning('Could not resolve charge from Stripe payment intent', [
                        'payment_intent_id' => $paymentIntentId,
                        'error'             => $e->getMessage(),
                    ]);
                }
            }
        }

        return [$chargeId, $paymentIntentId, null];
    }

    protected function applyPaymentSearch($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('type', 'LIKE', "%{$search}%")
                ->orWhere('method', 'LIKE', "%{$search}%")
                ->orWhere('amount', 'LIKE', "%{$search}%")
                ->orWhere('booking_id', 'LIKE', "%{$search}%")
                ->orWhere('payment_id', 'LIKE', "%{$search}%")
                ->orWhereHas('booking', function ($sub) use ($search) {
                    $sub->where('booking_id', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('booking.user', function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                })
                ->orWhereHas('booking.venue', function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%");
                });
        });
    }
}
