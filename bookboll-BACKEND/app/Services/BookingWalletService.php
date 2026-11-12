<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CommissionRate;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Support\PaymentRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Provider earnings from a FULL online booking payment.
 *
 * Records the split ledger a provider-owned venue's online money produces:
 *
 *   1. a @bookboll commission payment row
 *   2. a @<provider> earning payment row
 *   3. a wallet_transactions credit (on hold, or settled for an automatic rail)
 *   4. the provider_wallets balance change
 *
 * Only for PROVIDER-owned venues paying the full amount online — that is the money
 * that flows through Bookboll and must be paid out. A Bookboll-run venue (no owner)
 * and deposit/cash collections do not use this: under the deposit model the deposit
 * itself IS Bookboll's fee, so the later balance is entirely the provider's.
 *
 * Ported from the Supabase storefront's lib/bookingPayment.js + walletSetup.js +
 * walletLedger.js, which did this from the browser. It belongs on the server: the
 * amounts decide who gets paid.
 */
class BookingWalletService
{
    /** Fallback commission when neither the listing nor its country sets one. */
    public const COURT_COMMISSION_PCT = 15;

    public const DEFAULT_HOLD_DAYS = 7;

    public function __construct(protected WalletPayoutService $payouts)
    {}

    // ── Wallet predicates (walletSetup.js) ───────────────────────────────────

    public function normPurpose(?string $p): string
    {
        return $p === 'bookings' ? 'bookings' : 'subscription';
    }

    public function isWalletPaused(?ProviderWallet $w): bool
    {
        return (bool) ($w?->payout_paused);
    }

    /** Fully wired rail — unlocks online payments. */
    public function isWalletReady(?ProviderWallet $w): bool
    {
        return $w?->setup_status === 'active' && ! $this->isWalletPaused($w);
    }

    /**
     * Can EARN now. A rail that is still connecting ('pending') still accrues —
     * money is disbursed once it goes live, earning never pauses while connecting.
     */
    public function isWalletUsable(?ProviderWallet $w): bool
    {
        return in_array($w?->setup_status, ['active', 'pending'], true) && ! $this->isWalletPaused($w);
    }

    /** Stripe Connect splits at checkout → paid out per earning, no withdrawal. */
    public function isAutomaticPayout(?ProviderWallet $w): bool
    {
        return $w?->setup_method === 'stripe_connect';
    }

    /**
     * The CURRENT wallet for a country + purpose (the one new earnings bind to).
     *
     * @param  \Illuminate\Support\Collection<int, ProviderWallet>  $wallets
     */
    public function walletForCountry($wallets, $countryId, string $purpose = 'subscription'): ?ProviderWallet
    {
        if (! $countryId) {
            return null;
        }
        $want = $this->normPurpose($purpose);
        $match = fn (ProviderWallet $w) => (string) $w->country_id === (string) $countryId
            && $this->normPurpose($w->purpose) === $want;

        return $wallets->first(fn ($w) => $match($w) && $w->is_current !== false)
            ?: $wallets->first($match);
    }

    // ── Wallet resolution ────────────────────────────────────────────────────

    /**
     * The wallet a payment in this country + purpose pays into, auto-creating a
     * local one when the provider has none there yet. The new wallet inherits the
     * payout method and bank details from an existing same-purpose wallet and
     * earns in the country's own currency, so a multi-country provider keeps each
     * country's money in its own currency.
     *
     * payout_paused is inherited too — an auto-created wallet must never silently
     * resurrect a method the super admin paused.
     */
    public function ensureCountryWallet(
        $ownerUserId,
        $countryId,
        ?string $fallbackCurrency = null,
        string $purpose = 'subscription'
    ): ?ProviderWallet {
        if (! $ownerUserId) {
            return null;
        }

        $want = $this->normPurpose($purpose);
        $wallets = ProviderWallet::where('owner_user_id', $ownerUserId)->get();

        $existing = $this->walletForCountry($wallets, $countryId, $want);
        if ($existing) {
            return $existing;
        }

        $samePurpose = $wallets->filter(fn (ProviderWallet $w) => $this->normPurpose($w->purpose) === $want)->values();
        $template = $samePurpose->first(fn ($w) => $w->is_current !== false && $this->isWalletUsable($w))
            ?: $samePurpose->first(fn ($w) => $this->isWalletUsable($w))
            ?: $samePurpose->first();

        // The new wallet earns in the country's own currency (world package keeps
        // that on a related row, not on `countries` itself).
        $currency = $fallbackCurrency ?: ($template?->currency ?: '');
        if ($countryId) {
            $code = Country::query()->with('currency')->find($countryId)?->currency?->code;
            if ($code) {
                $currency = $code;
            }
        }

        $seed = [
            'owner_user_id'      => $ownerUserId,
            'country_id'         => $countryId,
            'purpose'            => $want,
            'is_current'         => true,
            'status'             => 'current',
            'currency'           => $currency,
            'available_balance'  => 0,
            'pending_balance'    => 0,
            'lifetime_earnings'  => 0,
            'lifetime_withdrawn' => 0,
            'hold_days'          => self::DEFAULT_HOLD_DAYS,
        ];

        if ($template) {
            foreach ([
                'setup_method', 'setup_status', 'setup_completed_at', 'hold_days', 'payout_paused',
                'payout_bank_country', 'payout_bank_name', 'payout_account_holder', 'payout_iban',
                'payout_account_number', 'payout_routing_number', 'payout_swift', 'payout_account_type',
            ] as $field) {
                if ($template->{$field} !== null) {
                    $seed[$field] = $template->{$field};
                }
            }
        }

        try {
            return ProviderWallet::create($seed);
        } catch (\Throwable $e) {
            Log::error('Could not create provider wallet', [
                'owner_user_id' => $ownerUserId,
                'country_id'    => $countryId,
                'error'         => $e->getMessage(),
            ]);

            return $template;
        }
    }

    /**
     * An automatic (Stripe Connect) payout: the money was disbursed at checkout, so
     * log a PAID withdrawal per earning — nothing accrues for the provider to withdraw.
     */
    public function recordAutoPayout($ownerUserId, ProviderWallet $wallet, float $amount, ?string $currency, ?string $sourceLabel): void
    {
        if (! $ownerUserId || $amount <= 0) {
            return;
        }

        try {
            WithdrawalRequest::create([
                'owner_user_id'  => $ownerUserId,
                'country_id'     => $wallet->country_id,
                'wallet_id'      => $wallet->id,
                'amount'         => $amount,
                'currency'       => $currency ?: ($wallet->currency ?: ''),
                'payout_method'  => 'stripe_connect',
                'payout_details' => 'Automatic Stripe Connect payout (split at checkout)',
                'status'         => 'paid',
                'processed_at'   => now(),
                'notes'          => $sourceLabel ? 'Auto-payout · ' . $sourceLabel : 'Automatic payout',
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not record automatic payout', ['error' => $e->getMessage()]);
        }
    }

    // ── Commission ───────────────────────────────────────────────────────────

    /** Listing override → the venue's country rate → the platform default. */
    public function courtCommissionPct(?Listing $listing): float
    {
        if ($listing && $listing->commission_rate !== null && $listing->commission_rate !== '') {
            return (float) $listing->commission_rate;
        }
        return app(\App\Support\MarketSettings::class)->rate('facility',
            $listing ? $this->countryIdForVenue($listing->venue) : null, $listing?->venue?->city_id);

    }

    /**
     * Can this venue's merchant actually be paid for a full-online booking?
     *
     * Needs a bookings-purpose wallet that is set up, active, not paused, and
     * in the VENUE's country — money for a Qatar pitch cannot be settled
     * through a route established for Pakistan. readmeAlamgir asks for exactly
     * this: "Validate active payouts for full online payment in the correct
     * venue country."
     *
     * This decides whether full-online is OFFERED. It never changes the
     * commission: a connected payout does not waive Bookboll's fee, and the
     * deposit method deliberately does not consult this at all.
     */
    public function venueHasActivePayout(?Venue $venue): bool
    {
        $ownerId = $venue?->owner_user_id;
        if (! $ownerId) {
            return false;
        }

        $wallets = ProviderWallet::where('owner_user_id', $ownerId)->get();
        if ($wallets->isEmpty()) {
            return false;
        }

        // Wallets predating the purpose column serve every purpose.
        $migrated = $wallets->contains(fn ($w) => $w->purpose !== null);
        $usable = $migrated
            ? $wallets->filter(fn ($w) => ($w->purpose ?: 'subscription') === 'bookings')
            : $wallets;

        $venueCountryId = $this->countryIdForVenue($venue);

        return $usable->contains(function ($w) use ($venueCountryId) {
            if (! $w->setup_method || $w->setup_status !== 'active' || $w->payout_paused) {
                return false;
            }

            // A wallet with no country recorded predates country-scoped payouts
            // and is accepted, so this check cannot retire working routes.
            return ! $venueCountryId
                || ! $w->country_id
                || (int) $w->country_id === (int) $venueCountryId;
        });
    }

    public function countryIdForVenue(?Venue $venue)
    {
        if (! $venue) {
            return null;
        }
        if ($venue->country_id) {
            return $venue->country_id;
        }
        if ($venue->city_id) {
            return City::query()->where('id', $venue->city_id)->value('country_id');
        }

        return null;
    }

    // ── The ledger ───────────────────────────────────────────────────────────

    /**
     * Record a full online booking payment as a split ledger + wallet credit.
     *
     * @param  array{bookingId: int, ownerUserId: mixed, countryId?: mixed, total: float,
     *               currency?: string, ratePct: float, customerLabel?: string, loyaltyRedeem?: float}  $args
     * @return array|null the ledger summary, or null when nothing was recorded
     */
    public function recordBookingOnlinePayment(array $args): ?array
    {
        $ownerUserId = $args['ownerUserId'] ?? null;
        $gross = round((float) ($args['total'] ?? 0), 2);

        // A Bookboll-run venue has no provider to pay — nothing to record.
        if (! $ownerUserId || $gross <= 0) {
            return null;
        }

        $rate = max(0, min(100, (float) ($args['ratePct'] ?? 0)));
        $platformFee = round($gross * $rate / 100, 2);
        $providerShare = round($gross - $platformFee, 2);

        // Loyalty redemption is funded ENTIRELY by Bookboll's platform fee — the
        // provider's share is never touched, so cap it at the fee.
        $loyalty = max(0, min((float) ($args['loyaltyRedeem'] ?? 0), $platformFee));
        $netPlatformFee = round($platformFee - $loyalty, 2);
        $collected = round($gross - $loyalty, 2);

        $currency = ($args['currency'] ?? '') ?: '';
        $bookingId = $args['bookingId'] ?? null;
        $label = ($args['customerLabel'] ?? '') ?: 'customer';
        // The customer the money came from — payments.user_id is required.
        $userId = $args['userId'] ?? Booking::whereKey($bookingId)->value('user_id');

        $owner = User::find($ownerUserId);
        $business = $owner?->ownedBusiness;
        // One authority for the label, shared with the venue-transfer
        // reconciliation so the same business is not spelled two ways.
        $providerRecipient = PaymentRecipient::forBusiness($business, $owner);

        $countryId = ($args['countryId'] ?? null) ?: $owner?->country_id;

        $wallet = $this->ensureCountryWallet($ownerUserId, $countryId, $currency, 'bookings');
        if (! $wallet) {
            return null;
        }

        $holdDays = (int) ($wallet->hold_days ?: self::DEFAULT_HOLD_DAYS);
        $availableOn = now()->addDays($holdDays)->toDateString();
        $automatic = $this->isAutomaticPayout($wallet);
        $description = $loyalty > 0
            ? "Full online booking payment (− {$currency} {$loyalty} loyalty)"
            : 'Full online booking payment';

        $receiptContext = \Illuminate\Support\Facades\Schema::hasColumn('payments', 'financial_context')
            ? ['financial_context' => ['receipt_ids' => array_values($args['receiptIds'] ?? [])]] : [];

        return DB::transaction(function () use (
            $bookingId, $userId, $ownerUserId, $currency, $netPlatformFee, $providerShare, $collected,
            $providerRecipient, $description, $wallet, $automatic, $availableOn, $holdDays,
            $label, $countryId, $loyalty, $receiptContext
        ) {
            // 1. Bookboll's commission — paid immediately, reduced by any loyalty
            //    redemption (absorbed by the fee, not the provider's share).
            Payment::create($receiptContext + [
                'booking_id'  => $bookingId,
                'user_id'     => $userId,
                'payment_id'  => generatePaymentId((int) $bookingId, \PaymentType::BOOKING_COMMISSION),
                'source_type' => 'booking',
                'type'        => \PaymentType::BOOKING_COMMISSION,
                'method'      => \PaymentMethod::ONLINE,
                'amount'      => $netPlatformFee,
                'currency'    => $currency,
                'recipient'   => '@bookboll',
                'status'      => \PaymentStatus::PAID,
                'paid_at'     => now(),
                'date'        => now()->format('Y-m-d'),
                'notes'       => $description,
            ]);

            // 2. The provider's earning — paid immediately; the payout hold
            //    lifecycle is tracked on the wallet transaction, not here.
            Payment::create($receiptContext + [
                'booking_id'  => $bookingId,
                'user_id'     => $userId,
                'payment_id'  => generatePaymentId((int) $bookingId, \PaymentType::BOOKING_EARNING),
                'source_type' => 'booking',
                'type'        => \PaymentType::BOOKING_EARNING,
                'method'      => \PaymentMethod::ONLINE,
                'amount'      => $providerShare,
                'currency'    => $currency,
                'recipient'   => $providerRecipient,
                'status'      => \PaymentStatus::PAID,
                'paid_at'     => now(),
                'date'        => now()->format('Y-m-d'),
                'notes'       => $description,
            ]);

            // 3. The wallet credit. Automatic rails settle at once; everything else
            //    sits on hold until it clears.
            $walletTx = WalletTransaction::create([
                'owner_user_id'      => $ownerUserId,
                'type'               => 'booking_online',
                'direction'          => 'credit',
                'amount'             => $collected,
                'provider_share'     => $providerShare,
                'platform_fee'       => $netPlatformFee,
                'source_id'          => $bookingId ? (string) $bookingId : null,
                'source_label'       => 'Booking · ' . $label,
                'description'        => $automatic ? $description . ' — auto-paid via Stripe' : $description,
                'status'             => $automatic ? 'completed' : 'on_hold',
                'currency'           => $currency,
                'country_id'         => $wallet->country_id ?: $countryId,
                'wallet_id'          => $wallet->id,
                'available_on'       => $automatic ? null : $availableOn,
                'hold_days_snapshot' => $automatic ? 0 : $holdDays,
            ]);

            // 4. Settle. Automatic → log a paid payout, nothing accrues to withdraw.
            //    Manual → accrue to pending until the hold clears.
            if ($automatic) {
                $this->recordAutoPayout($ownerUserId, $wallet, $providerShare, $currency, 'Booking · ' . $label);
                $this->payouts->applyDelta((string) $wallet->id, ['earned' => $providerShare, 'withdrawn' => $providerShare]);
            } else {
                $this->payouts->applyDelta((string) $wallet->id, ['pending' => $providerShare, 'earned' => $providerShare]);
            }

            return [
                'wallet'          => $wallet->fresh(),
                'walletTx'        => $walletTx,
                'available_on'    => $availableOn,
                'holdDays'        => $holdDays,
                'platformFee'     => $netPlatformFee,
                'providerShare'   => $providerShare,
                'loyaltyRedeemed' => $loyalty,
                'automatic'       => $automatic,
            ];
        });
    }
}
