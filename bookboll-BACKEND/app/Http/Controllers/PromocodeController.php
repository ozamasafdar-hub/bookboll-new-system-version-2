<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Promocode;
use App\Services\PromocodeService;
use Illuminate\Http\Request;

class PromocodeController extends Controller
{
    public function __construct(protected PromocodeService $promocodeService)
    {}

    public function index(Request $request)
    {
        return $this->promocodeService->all($request);
    }

    public function options(Request $request)
    {
        return $this->promocodeService->options($request);
    }

    public function show($id)
    {
        return $this->promocodeService->show($id);
    }

    public function redemptions($id)
    {
        return $this->promocodeService->redemptions($id);
    }

    public function storeRedemption(Request $request, $id)
    {
        return $this->promocodeService->storeRedemption($request, $id);
    }

    public function customerIndex(Request $request)
    {
        return $this->promocodeService->customerPromocodes($request);
    }

    public function store(Request $request)
    {
        return $this->promocodeService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->promocodeService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->promocodeService->delete($id);
    }

    public function validate(Request $request)
    {
        return $this->promocodeService->validatePromocode($request);
    }

    public function apply(Request $request, Promocode $promocode)
    {
        return $this->promocodeService->applyPromocode($request, $promocode);
    }

    public function searchVenues(Request $request)
    {
        $venues = $this->promocodeService->getApplicableVenues($request);
        return sendSuccess('Venues found', compact('venues'));
    }

    public function searchListings(Request $request)
    {
        $listings = $this->promocodeService->getApplicableListings($request);
        return sendSuccess('Listings found', compact('listings'));
    }

    public function applyPromocode(Request $request)
    {
        $request->validate([
            'promocode'      => 'required|string',
            'order_amount'   => 'required|numeric|min:0',
            'user_id'        => 'nullable|exists:users,id',
            'payment_method' => 'nullable|string',
            'venue_id'       => 'nullable|exists:venues,id',
            'listing_id'     => 'nullable|exists:listings,id',
            'quantity' => 'nullable|integer|min:1|max:100',
            'surface' => 'nullable|in:marketplace,site',
            'applicable_to' => 'nullable|in:facility,provider_plan',
            'slots' => 'nullable|array|max:100',
            'slots.*.date' => 'required_with:slots|date',
            'slots.*.start_time' => 'required_with:slots|date_format:H:i',
            'slots.*.end_time' => 'required_with:slots|date_format:H:i',
            'slots.*.quantity' => 'nullable|integer|min:1|max:100',
            'add_ons' => 'nullable|array',
            'add_ons.*' => 'integer|exists:add_ons,id',
        ]);

        if ($request->applicable_to === 'provider_plan') return app(\App\Services\ProviderPlanService::class)->previewPromo($request);
        $listing = $request->listing_id ? \App\Models\Listing::find($request->listing_id) : null;
        if (!$listing) return sendError('Choose a listing before applying a booking promo.', [], 422);
        $grossAmounts = [(float) $request->order_amount];
        if ($request->filled('slots')) {
            $pricing = app(\App\Services\CustomerBookingQuoteService::class);
            $quote = $pricing->quoteSlots($listing, $pricing->normalizeCustomerSlots($listing, $request->only('slots')), $request->add_ons ?? []);
            $grossAmounts = array_column($quote['slots'], 'subtotal');
        } elseif ((int) $request->quantity > 1) {
            $grossAmounts = \App\Support\BookingPromotion::distribute((float) $request->order_amount, array_fill(0, (int) $request->quantity, 1));
        }
        $gross = round(array_sum($grossAmounts), 2);

        $promocode = Promocode::with('users')->where('code', strtoupper(trim($request->promocode)))->active()->first();

        if (! $promocode) {
            return sendError('Invalid or inactive promocode');
        }

        $user_id = auth('sanctum')->id();

        $isValid = $promocode->isValidForOrder(
            $gross,
            $user_id,
            $request->payment_method,
            $request->venue_id,
            $request->listing_id
        );

        if (! $isValid) {
            return sendError('Promocode is not applicable');
        }

        $split = \App\Support\BookingPromotion::breakdown($promocode, $listing, $grossAmounts, $request->surface ?? 'marketplace', $request->payment_method ?? 'online_payment');
        $discount = $split['discount'];

        return sendSuccess('Promocode applied successfully', [
            'promocode' => [
                'discount_amount' => $discount,
                'platform_funded_amount' => \App\Support\BookingPromotion::platformFunded($promocode) ? $discount : 0,
                'code'             => $promocode->code,
                'discount'         => $promocode->formatted_discount,
                'new_order_amount' => $gross - $discount,
                'platform_fee' => $split['fee'],
            ],
        ]);
    }

    public function remove($booking_id)
    {
        return $this->promocodeService->removePromocodeFromBooking($booking_id);
    }
}
