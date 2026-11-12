<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Listing;
use App\Models\Promocode;
use App\Models\PromocodeRedemption;
use App\Models\User;
use App\Models\Venue;
use App\Support\PremiumPlan;
use BookingPaymentMethod;
use BookingPaymentStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PaymentStatus;
use PaymentType;

class PromocodeService
{
    public function __construct(protected Promocode $promocodeModel, protected Booking $bookingModel) {}

    public function all($request)
    {
        $recordsPerPage = (int) ($request->records_per_page ?? $request->query('records_per_page', 25));
        if ($recordsPerPage < 1) {
            $recordsPerPage = 25;
        }
        if ($recordsPerPage > 250) {
            $recordsPerPage = 250;
        }

        $search    = $request->search ?? $request->query('search');
        $recipient = $request->recipient ?? $request->query('recipient');
        $status    = $request->status ?? $request->query('status');

        $query = $this->promocodeModel::with(['venues', 'listings', 'users'])
            ->nonReminderPromocodes()
            ->where('is_gift_card_promocode', false)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($recipient && in_array($recipient, ['bookboll', 'provider'], true)) {
            $query->where('recipient', $recipient);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $this->applyActorScope($query, $request->user());
        $this->applyGeoFilter($query, $request);

        $paginator = $query->paginate($recordsPerPage);

        // apps/admin sends dashboard=1. Old ADMIN expects a Laravel paginator on promocodes.
        if ($request->boolean('dashboard') || (string) $request->query('dashboard') === '1') {
            $codes = collect($paginator->items())->map(fn (Promocode $p) => $this->mapDashboard($p))->values();

            return sendSuccess('Promocodes fetched successfully', [
                'promocodes' => $codes,
                'pagination' => [
                    'current_page'     => $paginator->currentPage(),
                    'last_page'        => $paginator->lastPage(),
                    'records_per_page' => $paginator->perPage(),
                    'total'            => $paginator->total(),
                    'from'             => $paginator->firstItem(),
                    'to'               => $paginator->lastItem(),
                ],
            ]);
        }

        return sendSuccess('Promocodes fetched successfully', [
            'promocodes' => $paginator,
        ]);
    }

    public function options(Request $request)
    {
        $venueCols = $this->existingColumns('venues', ['id', 'name', 'status', 'city_id', 'country_id', 'created_by']);
        $venues = Venue::with('users')
            ->orderBy('name')
            ->get($venueCols);

        $locations = $venues->map(function (Venue $v) {
            return [
                'id'            => (string) $v->id,
                'name'          => $v->name,
                'status'        => $v->status,
                'city_id'       => $v->city_id ? (string) $v->city_id : null,
                'owner_user_id' => ($v->users->first()?->id ?? $v->created_by)
                    ? (string) ($v->users->first()?->id ?? $v->created_by)
                    : null,
            ];
        });

        $ownerByVenue = $locations->pluck('owner_user_id', 'id');

        $listingCols = $this->existingColumns('listings', ['id', 'name', 'venue_id', 'created_by']);
        $listings = Listing::orderBy('name')
            ->get($listingCols)
            ->map(function (Listing $l) use ($ownerByVenue) {
                $locationId = $l->venue_id ? (string) $l->venue_id : null;

                return [
                    'id'            => (string) $l->id,
                    'name'          => $l->name,
                    'location_id'   => $locationId,
                    'owner_user_id' => $ownerByVenue[$locationId] ?? ($l->created_by ? (string) $l->created_by : null),
                ];
            });

        $customers = User::role('customer')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'        => (string) $u->id,
                'full_name' => $u->name,
                'email'     => $u->email,
            ]);

        $cityIds = $venues->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : \Nnjeim\World\Models\City::whereIn('id', $cityIds)
            ->orderBy('name')
            ->get(['id', 'name', 'country_id']);

        $countryIds = $venues->pluck('country_id')
            ->merge($cities->pluck('country_id'))
            ->filter()
            ->unique()
            ->values();

        $countries = $countryIds->isEmpty()
            ? \Nnjeim\World\Models\Country::orderBy('name')->get(['id', 'name'])
            : \Nnjeim\World\Models\Country::whereIn('id', $countryIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $businesses = Business::with('owner:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn(Business $b) => [
                'id'            => $b->owner_id,
                'full_name'     => $b->owner?->name ?: $b->name,
                'username'      => $b->username,
                'email'         => $b->owner?->email,
                'country_id'    => $b->country_id,
                'role'          => 'provider_admin',
            ]);

        return sendSuccess('Promocode options fetched', [
            'locations'  => $locations,
            'listings'   => $listings,
            'programs'   => [],
            'customers'  => $customers,
            'countries'  => $countries,
            'cities'     => $cities,
            'businesses' => $businesses,
        ]);
    }

    public function show($id)
    {
        $promocode = $this->findForActor($id);
        if (! $promocode) {
            return sendError('Promocode not found', [], 404);
        }

        return sendSuccess('Promocode fetched', [
            'promocode' => $this->mapDashboard($promocode),
        ]);
    }

    public function redemptions($id)
    {
        $this->findForActor($id);
        $rows = PromocodeRedemption::where('promocode_id', $id)
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->get();

        return sendSuccess('Redemptions fetched', [
            'redemptions' => $rows,
        ]);
    }

    public function storeRedemption(Request $request, $id)
    {
        $promocode = $this->findForActor($id);
        $data = $request->validate([
            'customer_email'  => 'nullable|string|max:255',
            'customer_name'   => 'nullable|string|max:255',
            'source_type'     => 'nullable|string|max:40',
            'source_id'       => 'nullable|string|max:64',
            'order_amount'    => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'currency'        => 'nullable|string|max:10',
            'recipient'       => 'nullable|string|max:20',
            'owner_user_id'   => 'nullable|integer',
            'country_id'      => 'nullable|integer',
        ]);

        $row = PromocodeRedemption::create([
            'promocode_id'    => $promocode?->id,
            'code'            => $promocode?->code ?? '',
            'customer_email'  => strtolower(trim((string) ($data['customer_email'] ?? ''))),
            'customer_name'   => $data['customer_name'] ?? '',
            'source_type'     => $data['source_type'] ?? '',
            'source_id'       => $data['source_id'] ?? null,
            'order_amount'    => $data['order_amount'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'currency'        => $data['currency'] ?? '',
            'recipient'       => $data['recipient'] ?? ($promocode?->recipient ?? ''),
            'owner_user_id'   => $data['owner_user_id'] ?? $promocode?->owner_user_id,
            'country_id'      => $data['country_id'] ?? null,
            'redeemed_at'     => now(),
        ]);

        if ($promocode) {
            $promocode->increment('used_count');
        }

        return sendSuccess('Redemption recorded', ['redemption' => $row], 201);
    }

    public function customerPromocodes($request)
    {
        $user             = auth()->user();
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $query = $this->promocodeModel::with(['venues', 'listings'])
            ->active()
            ->where('is_gift_card_promocode', false)
            ->where(function ($q) use ($user) {
                $q->where(function ($subQ) {
                    $subQ->whereDoesntHave('users')
                        ->where('is_reminder_promocode', false);
                })
                    ->orWhereHas('users', function ($subQ) use ($user) {
                        $subQ->where('user_id', $user->id);
                    });
            })
            ->latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");
                });
            });

        $promocodes = $query->paginate($records_per_page);

        return sendSuccess('Promocodes fetched successfully', compact('promocodes'));
    }

    public function create($request)
    {
        // Promo codes are a Premium feature for providers (Promocodes.vue gates the
        // "New Promocode" button); platform roles are never gated.
        if (! PremiumPlan::entitled($request->user())) {
            return PremiumPlan::denied('promocodes');
        }

        // (There was a call to a normalizeDashboardInput() that does not exist, which
        // made every promo-code creation fatal. validateRequest() already maps the
        // dashboard's field names and stamps recipient / creator_role / owner_user_id —
        // which is why update() has always worked without it.)
        $validated = $this->validateRequest($request);

        try {
            DB::beginTransaction();

            $promocode = $this->promocodeModel::create($validated);

            $venueIds = $this->scopeIds($request->location_ids ?? $request->venues ?? []);
            $listingIds = $this->scopeIds($request->listing_ids ?? $request->listings ?? []);
            $promocode->venues()->sync($venueIds);
            $promocode->listings()->sync($listingIds);

            if ($request->has('users')) {
                $users = $request->users ?? [];
                if (empty($users)) {
                    $promocode->users()->detach();
                } else {
                    $syncData = [];
                    foreach ($users as $userId) {
                        $syncData[$userId] = ['usage_count' => 0];
                    }
                    $promocode->users()->sync($syncData);
                }
            }

            DB::commit();

            $promocode->load(['venues', 'listings', 'users']);

            return sendSuccess('Promocode created successfully', [
                'promocode' => $this->mapDashboard($promocode),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create promocode: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to create promocode: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $promocode = $this->findForActor($id);
        $validated = $this->validateRequest($request, true, $promocode->id);

        try {
            DB::beginTransaction();

            $promocode->update($validated);

            if ($request->has('venues') || $request->has('location_ids')) {
                $promocode->venues()->sync($this->scopeIds($request->location_ids ?? $request->venues ?? []));
            }

            if ($request->has('listings') || $request->has('listing_ids')) {
                $promocode->listings()->sync($this->scopeIds($request->listing_ids ?? $request->listings ?? []));
            }

            if ($request->has('users')) {
                $users = $request->users ?? [];
                if (empty($users)) {
                    $promocode->users()->detach();
                } else {
                    $syncData      = [];
                    foreach ($users as $userId) {
                        $existingPivot     = $promocode->users()->where('user_id', $userId)->first();
                        $syncData[$userId] = [
                            'usage_count' => $existingPivot?->pivot->usage_count ?? 0,
                        ];
                    }
                    $promocode->users()->sync($syncData);
                }
            }

            DB::commit();

            $promocode->load(['venues', 'listings', 'users']);

            return sendSuccess('Promocode updated successfully', [
                'promocode' => $this->mapDashboard($promocode),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update promocode: ' . $e->getMessage(), ['promocode_id' => $id, 'Exception' => $e]);
            return sendError('Failed to update promocode: ' . $e->getMessage());
        }
    }

    public function delete($id)
    {
        $promocode = $this->findForActor($id);
        $promocode->delete();
        return sendSuccess('Promocode deleted successfully');
    }

    public function validateForUse($code, $orderAmount, $userId = null, $paymentMethod = null, $venueId = null, $listingId = null)
    {
        $promocode = $this->promocodeModel::with('users')->where('code', $code)->first();

        if (! $promocode || ! $promocode->isValidForOrder($orderAmount, $userId, $paymentMethod, $venueId, $listingId)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired promocode. Please check the code, validity dates, minimum order amount, or usage limits.'],
            ]);
        }

        return $promocode;
    }

    public function getApplicableVenues(Request $request)
    {
        $search = $request->search ?? '';
        return Venue::when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))->limit(10)->get();
    }

    public function getApplicableListings(Request $request)
    {
        $search = $request->search ?? '';
        return Listing::when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))->limit(10)->get();
    }

    protected function validateRequest($request, bool $isUpdate = false, $promocodeId = null): array
    {
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->code))]);
        }

        $rules = [
            'code' => $isUpdate
                ? "sometimes|required|string|max:50|unique:promocodes,code,{$promocodeId}"
                : 'required|string|max:50|unique:promocodes,code',
            'status'              => 'required|in:active,inactive',
            'description'         => 'nullable|string|max:500',
            'discount_type'       => 'required|in:fixed,percentage',
            'discount_value'      => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->discount_type === 'percentage' && $value > 100) {
                        $fail('Percentage discount cannot exceed 100%');
                    }
                },
            ],
            'usage_limit'         => 'nullable|integer|min:0',
            'valid_from'          => 'nullable|date',
            'expires_at'          => 'nullable|date',
            'valid_to'            => 'nullable|date',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'min_order'           => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'per_user_limit'      => 'nullable|integer|min:0',
            'per_customer_limit'  => 'nullable|integer|min:0',
            'payment_methods'     => 'nullable|in:all,online_only',
            'venues'              => 'nullable|array',
            'venues.*'            => 'integer',
            'listings'            => 'nullable|array',
            'listings.*'          => 'integer',
            'users'               => 'nullable|array',
            'users.*'             => 'integer',
            'recipient'           => 'nullable|in:bookboll,provider',
            'creator_role'        => 'nullable|string|max:40',
            'owner_user_id'       => 'nullable|integer',
            'applicable_to'       => 'nullable|in:all,facility,program,provider_plan',
            'location_id'         => 'nullable|integer',
            'owner_user_ids'      => 'nullable|array',
            'owner_user_ids.*'    => 'integer',
            'location_ids'        => 'nullable|array',
            'location_ids.*'      => 'integer',
            'listing_ids'         => 'nullable|array',
            'listing_ids.*'       => 'integer',
            'program_ids'         => 'nullable|array',
            'audience'            => 'nullable|in:all,new,specific',
            'target_emails'       => 'nullable|array',
            'target_emails.*'     => 'nullable|string',
        ];

        $data = $request->validate($rules);

        $data['description'] = $data['description'] ?? '';
        $data['payment_methods'] = $data['payment_methods'] ?? 'all';

        if (array_key_exists('valid_to', $data) && ! array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $data['valid_to'] ?: null;
        }
        unset($data['valid_to']);

        if (array_key_exists('min_order', $data)) {
            $data['min_order_amount'] = $data['min_order'];
            unset($data['min_order']);
        }

        if (isset($data['usage_limit']) && (int) $data['usage_limit'] === 0) {
            $data['usage_limit'] = null;
        }

        $perCustomer = (int) ($data['per_customer_limit'] ?? 0);
        $data['per_customer_limit'] = $perCustomer;
        if (! isset($data['per_user_limit'])) {
            $data['per_user_limit'] = $perCustomer > 0 ? $perCustomer : null;
        }

        $locationIds = $this->scopeIds($data['location_ids'] ?? $data['venues'] ?? (!empty($data['location_id']) ? [$data['location_id']] : []));
        $listingIds  = $this->scopeIds($data['listing_ids'] ?? $data['listings'] ?? []);
        $ownerIds    = $this->scopeIds($data['owner_user_ids'] ?? $request->owner_user_ids ?? []);

        $data['location_ids']  = $locationIds;
        $data['listing_ids']   = $listingIds;
        $data['owner_user_ids'] = $ownerIds;
        $data['program_ids']   = $this->scopeIds($data['program_ids'] ?? []);
        $data['target_emails'] = array_values(array_unique(array_filter(array_map(
            fn($e) => strtolower(trim((string) $e)),
            $data['target_emails'] ?? []
        ))));
        $data['audience']      = $data['audience'] ?? 'all';
        $data['recipient']     = $data['recipient'] ?? 'bookboll';
        $data['creator_role']  = $data['creator_role'] ?? 'super_admin';
        $data['applicable_to'] = $data['applicable_to'] ?? 'all';
        $data['location_id']   = $data['location_id'] ?? ($locationIds[0] ?? null);
        $data['owner_user_id'] = $data['owner_user_id'] ?? ($ownerIds[0] ?? null);

        $actor = $request->user();
        $allowed = \App\Support\AccessScope::allowedVenueIds($actor);
        if ($allowed !== null) {
            foreach ($locationIds as $venueId) abort_unless(in_array((int)$venueId,array_map('intval',$allowed),true),403);
            abort_if(Listing::whereIn('id',$listingIds)->whereNotIn('venue_id',$allowed)->exists(),403);
        }
        if (\App\Support\AccessScope::isProviderScoped($actor)) {
            $owner = \App\Support\AccessScope::ownerUserId($actor);
            abort_unless($owner,422,'Assign a business owner before creating a provider promo.');
            $data['recipient']='provider'; $data['owner_user_id']=$owner; $data['owner_user_ids']=[$owner];
            $data['creator_role']=$actor->getRoleNames()->first();
        } else {
            $data['creator_role']=$actor?->getRoleNames()->first();
        }
        unset($data['venues'], $data['listings'], $data['users']);

        return $data;
    }

    private function findForActor($id): Promocode
    {
        $query = Promocode::query(); $this->applyActorScope($query,auth()->user());
        return $query->findOrFail($id);
    }

    public function validatePromocode($request)
    {
        $data=$request->validate(['code'=>'required|string','amount'=>'required|numeric|min:0','user_id'=>'required|exists:users,id','venue_id'=>'required|exists:venues,id','listing_id'=>'required|exists:listings,id','payment_method'=>'nullable|string','surface'=>'nullable|in:marketplace,site']);
        $allowed=\App\Support\AccessScope::allowedVenueIds($request->user());
        abort_if($allowed!==null && !in_array((int)$data['venue_id'],array_map('intval',$allowed),true),403);
        $promo=Promocode::where('code',strtoupper(trim($data['code'])))->active()->first();
        if (!$promo || !$promo->isValidForOrder($data['amount'],$data['user_id'],$data['payment_method']??null,$data['venue_id'],$data['listing_id'])) return $this->invalidResponse('Promocode is not applicable.');
        if ($promo->comeback_booking_id) return $this->invalidResponse('Comeback codes are redeemable by the customer on Bookboll marketplace.');
        return sendSuccess('Promocode validated',['valid'=>true,'promocode'=>$promo->toArray(),'discount_amount'=>$promo->applyDiscount($data['amount'])]);
    }

    private function invalidResponse($error)
    {
        return response()->json([
            'success' => false,
            'data'    => [
                'valid' => false,
                'error' => $error,
            ],
        ], 422);
    }

    private function parsePaymentMethods($paymentMethods)
    {
        if ($paymentMethods === 'all') {
            return ['pending_payment', 'online_payment'];
        }
        return explode(',', $paymentMethods);
    }

    public function applyPromocode($request, $promocode)
    {
        $data=$request->validate(['booking_id'=>'required|exists:bookings,id']);
        return $this->changeManualPromo((int)$data['booking_id'],$promocode);
    }
    public function removePromocodeFromBooking($bookingId) { return $this->changeManualPromo((int)$bookingId,null); }

    private function changeManualPromo(int $id, ?Promocode $promo)
    {
        return DB::transaction(function () use ($id,$promo) {
            $booking=Booking::with('listing')->lockForUpdate()->findOrFail($id);
            $allowed=\App\Support\AccessScope::allowedVenueIds(auth()->user());
            abort_if($allowed!==null && !in_array((int)$booking->venue_id,array_map('intval',$allowed),true),403);
            $payments=$booking->payments()->lockForUpdate()->get();
            if (\App\Support\PaymentReportRules::channel($booking->getAttributes())!=='admin' || $payments->contains(fn($p)=>$p->status!=='pending'||$p->method==='online')) {
                return sendError('Promos cannot change a checkout already issued or a collected payment. Cancel and rebook an unpaid online checkout with the correct code.',[],422);
            }
            if ($booking->loyalty_quote_id || $booking->gift_card_amount) return sendError('A booking with rewards or gift credit cannot be repriced here.',[],422);
            if ($payments->count()>1) return sendError('Use payment review for a booking with multiple pending allocations.',[],422);
            $gross=round((float)$booking->total_price+(float)$booking->discount_amount,2);
            $discount=0;
            if ($promo) {
                $promo=Promocode::whereKey($promo->id)->lockForUpdate()->firstOrFail();
                if ($booking->promocode_id) return sendError('Remove the current code before applying another.',[],422);
                if ($promo->recipient!=='provider' || $promo->comeback_booking_id || !$promo->isValidForOrder($gross,$booking->user_id,$booking->payment_method,$booking->venue_id,$booking->listing_id)) return sendError('This code is not applicable to a staff booking.',[],422);
                $discount=round((float)$promo->applyDiscount($gross),2);
                $promo->incrementUsage($booking->user_id);
            } elseif ($booking->promocode_id) {
                $old=Promocode::whereKey($booking->promocode_id)->lockForUpdate()->first(); $old?->decrementUsage($booking->user_id);
            }
            $total=$gross-$discount;
            $booking->update(['promocode_id'=>$promo?->id,'discount_amount'=>$discount,'total_price'=>$total,'platform_promo_discount'=>0,
                'commission_fee_minor'=>0,'merchant_share_minor'=>\App\Support\Money::toMinor($total,$booking->currency),
                'promotion_snapshot'=>$promo?['code'=>$promo->code,'recipient'=>'provider','discount'=>$discount,'platform_funded'=>0]:null]);
            $booking->forceFill(['financial_context'=>\App\Support\PaymentFinancialContext::forNewBooking($booking)])->save();
            foreach ($payments as $payment) {
                $payment->update(['amount'=>$total,'financial_context'=>$booking->financial_context]);
                $payment->ownedAllocations()->delete();
                $allocations=\App\Support\OwnedVenueAllocation::plan($payment->getAttributes(),$payment->financial_context??[]);
                if ($allocations) $payment->ownedAllocations()->createMany($allocations);
            }
            $booking->history()->create(['user_id'=>auth()->id(),'action'=>$promo?'promocode_applied':'promocode_removed','description'=>'Unpaid staff booking repriced; discount '.$discount.' '.$booking->currency.'. No platform fee.']);
            return sendSuccess('Booking updated',['booking'=>$booking->fresh(['payments','promocode'])]);
        });
    }

    protected function regenerateBookingInvoice(Booking $booking): void
    {
        try {
            $booking->loadMissing('user');
            $user = $booking->user;

            if (! $user) {
                return;
            }

            $existingInvoices = $user->invoices()->where('booking_id', $booking->id)->get();
            foreach ($existingInvoices as $invoice) {
                $invoice->status = 're_generated';
                $invoice->save();
            }

            $invoiceNumber = generateInvoiceId();
            $invoicePath   = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to regenerate invoice for booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }

    protected function mapDashboard(Promocode $p): array
    {
        $locationIds = $p->relationLoaded('venues') && $p->venues->isNotEmpty()
            ? $p->venues->pluck('id')->map(fn($id) => (int) $id)->values()->all()
            : $this->scopeIds($p->location_ids ?? []);
        $listingIds = $p->relationLoaded('listings') && $p->listings->isNotEmpty()
            ? $p->listings->pluck('id')->map(fn($id) => (int) $id)->values()->all()
            : $this->scopeIds($p->listing_ids ?? []);
        $ownerIds = $this->scopeIds($p->owner_user_ids ?? []);

        return [
            'id'                 => $p->id,
            'code'               => $p->code,
            'description'        => $p->description,
            'discount_type'      => $p->discount_type,
            'discount_value'     => (float) $p->discount_value,
            'min_order'          => (float) ($p->min_order_amount ?? 0),
            'usage_limit'        => (int) ($p->usage_limit ?? 0),
            'usage_count'        => (int) ($p->used_count ?? 0),
            'status'             => $p->status,
            'valid_from'         => optional($p->valid_from)->toDateString(),
            'valid_to'           => optional($p->expires_at)->toDateString(),
            'recipient'          => $p->recipient ?: 'bookboll',
            'creator_role'       => $p->creator_role ?: 'super_admin',
            'owner_user_id'      => $p->owner_user_id,
            'applicable_to'      => $p->applicable_to ?: 'all',
            'owner_user_ids'     => $ownerIds,
            'location_ids'       => $locationIds,
            'listing_ids'        => $listingIds,
            'program_ids'        => $this->scopeIds($p->program_ids ?? []),
            'location_id'        => $p->location_id ?: ($locationIds[0] ?? null),
            'audience'           => $p->audience ?: 'all',
            'target_emails'      => $p->target_emails ?? [],
            'per_customer_limit' => (int) ($p->per_customer_limit ?? 0),
            'created_at'         => optional($p->created_at)?->toIso8601String(),
        ];
    }

    protected function scopeIds($value): array
    {
        if (! is_array($value)) {
            return $value ? [(int) $value] : [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    protected function applyActorScope($query, $actor): void
    {
        if (! $actor) {
            return;
        }

        if ($actor->hasRole('super_admin')) {
            return;
        }

        if ($actor->hasRole('provider_admin') || $actor->owner_user_id) {
            $ownerId = $actor->hasRole('provider_admin') ? $actor->id : (int) $actor->owner_user_id;
            $query->where(function ($q) use ($ownerId) {
                $q->where('owner_user_id', $ownerId)
                    ->orWhereJsonContains('owner_user_ids', $ownerId)
                    ->orWhereJsonContains('owner_user_ids', (string) $ownerId);
            });

            return;
        }

        if ($actor->hasRole('country_admin')) {
            $allowed = collect($actor->assigned_country_ids ?? [])->filter()->map(fn($id) => (int) $id)->all();
            if ($actor->country_id) {
                $allowed[] = (int) $actor->country_id;
            }
            $allowed = array_values(array_unique($allowed));
            if (! $allowed) {
                $query->whereRaw('1 = 0');

                return;
            }

            $ownerIds = Business::whereIn('country_id', $allowed)->pluck('owner_id')->filter()->all();
            $venueIds = Venue::whereIn('country_id', $allowed)->pluck('id')->all();

            $query->where(function ($q) use ($ownerIds, $venueIds) {
                $q->where(function ($inner) {
                    $inner->whereNull('owner_user_id')
                        ->where(function ($empty) {
                            $empty->whereNull('owner_user_ids')
                                ->orWhereJsonLength('owner_user_ids', 0);
                        })
                        ->whereNull('location_id')
                        ->where(function ($emptyLoc) {
                            $emptyLoc->whereNull('location_ids')
                                ->orWhereJsonLength('location_ids', 0);
                        });
                });
                if ($ownerIds) {
                    $q->orWhereIn('owner_user_id', $ownerIds);
                    foreach ($ownerIds as $oid) {
                        $q->orWhereJsonContains('owner_user_ids', (int) $oid);
                    }
                }
                if ($venueIds) {
                    $q->orWhereIn('location_id', $venueIds)
                        ->orWhereHas('venues', fn($vq) => $vq->whereIn('venues.id', $venueIds));
                }
            });
        }
    }

    protected function applyGeoFilter($query, Request $request): void
    {
        $countryIds = $this->scopeIds($request->input('country_ids', $request->query('country_ids')));
        $cityIds    = $this->scopeIds($request->input('city_ids', $request->query('city_ids')));
        if (! $countryIds && ! $cityIds) {
            return;
        }

        $venueQuery = Venue::query();
        if ($countryIds) {
            $venueQuery->whereIn('country_id', $countryIds);
        }
        if ($cityIds) {
            $venueQuery->whereIn('city_id', $cityIds);
        }
        $venueIds = $venueQuery->pluck('id')->all();

        $query->where(function ($q) use ($venueIds) {
            $q->where(function ($global) {
                $global->whereNull('location_id')
                    ->where(function ($empty) {
                        $empty->whereNull('location_ids')
                            ->orWhereJsonLength('location_ids', 0);
                    })
                    ->whereDoesntHave('venues');
            });
            if ($venueIds) {
                $q->orWhereIn('location_id', $venueIds)
                    ->orWhereHas('venues', fn($vq) => $vq->whereIn('venues.id', $venueIds));
            }
        });
    }

    protected function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn ($col) => Schema::hasColumn($table, $col)));
    }
}
