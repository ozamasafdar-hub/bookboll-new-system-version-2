<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\LoyaltyCheckoutQuote;
use App\Support\CommissionPolicy;
use App\Support\LoyaltyCountry;
use App\Support\LoyaltyRedemption;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * How many points this exact checkout may spend — computed by the server, on
 * the server's own prices.
 *
 * Issuing a quote spends nothing. It creates no booking, debits no points and
 * reserves none: it is a priced offer, bound to the account, country, currency
 * and the normalised inputs it was priced from, with an expiry. Points are
 * reserved when payment is actually attempted.
 *
 * Prices are re-derived here. Anything the browser sends about money is
 * ignored, because the funding cap is Bookboll's own share and a client that
 * could inflate that could hand itself a larger discount.
 */
class LoyaltyCheckoutQuoteService
{
    /** Long enough to pay, short enough that prices cannot drift far. */
    public const TTL_MINUTES = 10;

    /**
     * Kinds the server can price.
     *
     * Each one is priced through the service that CHARGES it, never through a
     * copy of its arithmetic here: a reward quote that disagrees with the
     * charge either shorts the customer or gives away money nobody earned.
     */
    public const KINDS = ['listing', 'membership', 'host_game', 'host_tournament'];

    /** The hosted-event kinds, which share one pricing path. */
    public const HOST_KINDS = ['host_game', 'host_tournament'];

    public function quote(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Not authenticated.', [], 401);
        }

        $data = $request->validate([
            'kind'                 => ['required', 'string', Rule::in(self::KINDS)],
            'country_id'           => 'required|integer|exists:countries,id',
            'currency'             => 'required|string|size:3',
            'checkout'             => 'required|array',
            // Required for a venue booking; a membership names a program and a
            // hosted event may have no court at all (an own-spot event).
            'checkout.listing_id'  => 'nullable|integer|exists:listings,id',
            'checkout.program_id'  => 'nullable|integer|exists:class_programs,id',
            'checkout.slots' => 'nullable|array',
            'checkout.selection_by_date' => 'nullable|array',
            'checkout.addons' => 'nullable|array',
            'checkout.add_ons' => 'nullable|array',
            'checkout.players' => 'nullable|integer|min:1',
            'checkout.payment_method' => 'nullable|string|max:64',
            'checkout.surface'     => 'nullable|string|in:marketplace,site',
            'checkout.game_date'   => 'nullable|date',
            'checkout.start_time'  => 'nullable|string|max:8',
            'checkout.end_time'    => 'nullable|string|max:8',
            'checkout.max_players' => 'nullable|integer|min:0',
            'checkout.price_per_person' => 'nullable|numeric|min:0',
            'checkout.community_id' => 'nullable|integer',
            'checkout.promocode'   => 'nullable|string|max:64',
        ], [
            'kind.in' => 'Rewards are not available for this kind of checkout yet.',
        ]);

        $countryId = (int) $data['country_id'];
        $currency = strtoupper($data['currency']);

        [$basis, $error, $listing] = $this->basisFor($user, $data['kind'], $data['checkout'], $currency);
        if ($error !== null) {
            return $error;
        }

        $program = LoyaltyCountry::program($countryId);
        if (! $program) {
            return $this->unavailable($countryId, $currency, 'Rewards are not available in this country.');
        }

        $policy = [
            'total_minor'   => Money::toMinor($basis['order_total'], $currency),
            'due_now_minor' => Money::toMinor($basis['due_now'], $currency),
            'fee_minor'     => Money::toMinor($basis['share']['commission'], $currency),
            'event_fee_minor'   => Money::toMinor($basis['share']['event_fee'], $currency),
            'booking_fee_minor' => Money::toMinor($basis['share']['booking_fee'], $currency),
            'effective_rate_pct' => $basis['rate_pct'] ?? 0.0,
            'channel'       => $basis['channel'] ?? $data['kind'],
        ];

        $balance = LoyaltyCountry::balance($user->id, $countryId);

        $offer = LoyaltyRedemption::quote(
            // Counted once each. A venue booking's booking fee is already inside
            // its commission, and a hosted event's seats fee is separate money.
            [
                'commission'  => $policy['fee_minor'],
                'event_fee'   => $policy['event_fee_minor'],
                'booking_fee' => $policy['booking_fee_minor'],
            ],
            $policy['total_minor'],
            $policy['due_now_minor'],
            $balance['balance'],
            (float) $program->redeem_rate,
            (int) $program->min_redeem,
            $program->max_redeem_pct === null ? null : (float) $program->max_redeem_pct,
            $currency
        );

        if ($offer['points'] <= 0) {
            return $this->unavailable($countryId, $currency, $offer['reason']);
        }

        $quote = LoyaltyCheckoutQuote::create([
            'quote_id'          => 'lq_'.Str::random(56),
            'user_id'           => $user->id,
            'country_id'        => $countryId,
            'currency'          => $currency,
            'kind'              => $data['kind'],
            'points'            => $offer['points'],
            'discount_minor'    => $offer['discount_minor'],
            'order_total_minor' => $policy['total_minor'],
            'due_now_minor'     => $policy['due_now_minor'],
            'commission_minor'  => $policy['fee_minor'],
            'event_fee_minor'   => $policy['event_fee_minor'],
            'booking_fee_minor' => $policy['booking_fee_minor'],
            'inputs_hash'       => self::inputsHash($listing, $policy, $countryId, $currency, $basis['subject'] ?? null),
            'expires_at'        => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return sendSuccess('Rewards quote', [
            'quote_id'           => $quote->quote_id,
            'kind'               => $quote->kind,
            'country_id'         => $countryId,
            'currency'           => $currency,
            'points'             => $quote->points,
            'discount'           => Money::toMajor($quote->discount_minor, $currency),
            // Explicitly zero rather than absent, so the client can show what
            // funded the reward instead of guessing.
            'bookboll_share'     => [
                'commission'  => Money::toMajor($policy['fee_minor'], $currency),
                'event_fee'   => Money::toMajor($policy['event_fee_minor'], $currency),
                'booking_fee' => Money::toMajor($policy['booking_fee_minor'], $currency),
            ],
            'order_total_before' => Money::toMajor($policy['total_minor'], $currency),
            'due_now_before'     => Money::toMajor($policy['due_now_minor'], $currency),
            'expires_at'         => $quote->expires_at->utc()->toIso8601String(),
        ]);
    }

    /**
     * Bookboll's share of this checkout, priced by whichever service charges it.
     *
     * Returns [$basis, $errorResponse, $listing]. The listing comes back so the
     * fingerprint can bind the quote to it; a membership and an own-spot event
     * have none, and bind to their own figures instead.
     *
     * @return array{0: ?array, 1: mixed, 2: ?Listing}
     */
    protected function basisFor($user, string $kind, array $checkout, string $currency): array
    {
        if ($kind === 'listing') {
            $listing = ! empty($checkout['listing_id']) ? Listing::with('venue')->find($checkout['listing_id']) : null;
            if (! $listing) {
                return [null, sendError('That booking could not be found.', ['checkout.listing_id' => ['Required for a venue booking.']], 422), null];
            }

            // The order's real currency decides, not the browser's. A mismatch
            // means the client is pricing something other than this listing.
            $listingCurrency = CommissionPolicy::currencyFor($listing);
            if ($listingCurrency !== '' && strtoupper($listingCurrency) !== $currency) {
                return [null, sendError('That currency does not match this booking.', [], 422), null];
            }

            $price = app(CustomerBookingQuoteService::class);
            if (!empty($checkout['selection_by_date'])) {
                $checkout['slots'] = [];
                foreach ($checkout['selection_by_date'] as $day => $items) foreach ($items as $slot) $checkout['slots'][] = ['date'=>$day,'start_time'=>$slot['start_time']??null,'end_time'=>$slot['end_time']??null,'quantity'=>max(1,(int)($checkout['players']??1))];
            }
            foreach ($checkout['slots'] ?? [] as $slot) {
                if (empty($slot['date']) || empty($slot['start_time']) || empty($slot['end_time'])) return [null,sendError('Select complete booking slots before applying rewards.',[],422),null];
            }
            if (empty($checkout['slots'])) return [null,sendError('Select booking slots before applying rewards.',[],422),null];
            $slots = $price->normalizeCustomerSlots($listing, $checkout);
            $addOns = $checkout['add_ons'] ?? array_column($checkout['addons'] ?? [], 'id');
            $quote = $price->quoteSlots($listing, $slots, $addOns);
            $gross = $quote['combined_subtotal']; $promo = null;
            if (!empty($checkout['promocode'])) {
                $promo = \App\Models\Promocode::where('code', strtoupper(trim($checkout['promocode'])))->active()->first();
                if (!$promo || !$promo->isValidForOrder($gross, $user->id, $checkout['payment_method'] ?? 'online_payment', $listing->venue_id, $listing->id)) return [null, sendError('Refresh your promo code before using rewards.', [], 422), null];
            }
            $promotion = \App\Support\BookingPromotion::breakdown($promo, $listing, array_column($quote['slots'], 'subtotal'), $checkout['surface'] ?? 'marketplace', $checkout['payment_method'] ?? 'online_payment');
            $discount = $promotion['discount'];
            if ($promo && $discount <= 0) return [null, sendError('This promo has no available discount for these slots.', [], 422), null];
            $totalMinor = Money::toMinor($gross - $discount, $currency);
            $method = ($checkout['payment_method'] ?? '') === 'deposit_remaining_on_arrival' ? 'deposit' : 'online';
            $channel = ($checkout['surface'] ?? 'marketplace') === CommissionPolicy::CHANNEL_SITE
                ? CommissionPolicy::CHANNEL_SITE
                : CommissionPolicy::CHANNEL_MARKETPLACE;

            $policy = CommissionPolicy::quote($listing, $channel, $totalMinor, $currency, $method);
            $fee = $promotion['fee'];
            $policy['fee_minor'] = Money::toMinor($fee, $currency);
            $policy['due_now_minor'] = $method === 'deposit' ? $policy['fee_minor'] : $totalMinor;

            return [[
                'currency'    => $currency,
                'order_total' => Money::toMajor($policy['total_minor'], $currency),
                'due_now'     => Money::toMajor($policy['due_now_minor'], $currency),
                // A venue booking's booking fee is already inside the
                // commission, and it has no event fee — counted once each.
                'share'       => ['commission' => Money::toMajor($policy['fee_minor'], $currency), 'event_fee' => 0.0, 'booking_fee' => 0.0],
                'rate_pct'    => $policy['effective_rate_pct'],
                'channel'     => $policy['channel'],
            ], null, $listing];
        }

        if ($kind === 'membership') {
            $program = ! empty($checkout['program_id']) ? ClassProgram::find($checkout['program_id']) : null;
            if (! $program) {
                return [null, sendError('That membership could not be found.', ['checkout.program_id' => ['Required for a membership.']], 422), null];
            }

            // The promocode is passed as a CODE, re-validated server side; the
            // discount the browser calculated is never read.
            $basis = app(CustomerProgramService::class)->rewardBasis(
                $program,
                is_string($checkout['promocode'] ?? null) ? $checkout['promocode'] : null,
                $user->id
            );
            if (! $basis) {
                return [null, sendError('That membership could not be priced.', [], 422), null];
            }
            if ($basis['currency'] !== '' && $basis['currency'] !== $currency) {
                return [null, sendError('That currency does not match this membership.', [], 422), null];
            }

            return [$basis + ['rate_pct' => 0.0, 'channel' => 'membership', 'subject' => 'program:'.$program->id], null, null];
        }

        // host_game and host_tournament. One pricing path: the difference is the
        // event's format, not how it is charged.
        $basis = app(CustomerGameService::class)->rewardBasis($user, $checkout);
        if (! $basis) {
            return [null, sendError('That court could not be found.', [], 422), null];
        }
        if ($basis['currency'] !== '' && $basis['currency'] !== $currency) {
            return [null, sendError('That currency does not match this event.', [], 422), null];
        }

        $listing = ! empty($checkout['listing_id']) ? Listing::with('venue')->find($checkout['listing_id']) : null;

        return [$basis + ['rate_pct' => 0.0, 'channel' => $kind], null, $listing];
    }

    /**
     * A priced answer of "nothing", which is different from an error: the
     * checkout continues at full price and the balance stays untouched.
     */
    protected function unavailable(int $countryId, string $currency, string $reason)
    {
        return sendSuccess('Rewards quote', [
            'quote_id'   => null,
            'country_id' => $countryId,
            'currency'   => $currency,
            'points'     => 0,
            'discount'   => 0,
            'reason'     => $reason,
        ]);
    }

    /** The server's own price for this order — never the browser's. */
    protected function orderTotal(Listing $listing, array $checkout): float
    {
        $slots = is_array($checkout['slots'] ?? null) ? $checkout['slots'] : [];
        $count = max(1, count($slots));

        return round((float) $listing->base_price * $count, 2);
    }

    /**
     * Fingerprint of everything the offer was priced from. Recomputed when the
     * quote is spent: a changed order no longer matches, so the customer is
     * asked to review rather than charged a stale amount.
     */
    public static function inputsHash(?Listing $listing, array $policy, int $countryId, string $currency, ?string $subject = null): string
    {
        return hash('sha256', json_encode([
            'listing'    => $listing?->id,
            // What is being bought when there is no listing — a membership
            // programme, say. Two same-priced programmes in one country would
            // otherwise fingerprint identically, and a quote for one could be
            // spent on the other.
            'subject'    => $subject,
            'country'    => $countryId,
            'currency'   => strtoupper($currency),
            'total'      => $policy['total_minor'],
            'due_now'    => $policy['due_now_minor'],
            'fee'        => $policy['fee_minor'],
            // Defaulted so a venue booking fingerprints exactly as it did
            // before these fields existed: its event and booking fees are zero,
            // and a quote in flight across the deploy stays valid.
            'event_fee'  => $policy['event_fee_minor'] ?? 0,
            'booking_fee' => $policy['booking_fee_minor'] ?? 0,
            'rate'       => $policy['effective_rate_pct'],
            // Set to the kind for a membership or hosted event, so a quote for
            // one kind can never be spent on another.
            'channel'    => $policy['channel'],
        ]));
    }
}
