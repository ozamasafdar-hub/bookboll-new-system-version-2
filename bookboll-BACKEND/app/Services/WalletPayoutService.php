<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\Payment;
use App\Models\ProviderWallet;
use App\Support\AdminAlerts;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;

/**
 * Admin provider payout rails — wallets, KYC, withdrawals, ledger.
 */
class WalletPayoutService
{
    /**
     * The id columns here are char(36) — they held Supabase UUIDs before the port,
     * and the wallet rows still store ids as strings. The dashboard now sources
     * country ids from Laravel (nnjeim/world), where they are INTEGERS, so a
     * request would fail a `string` rule on a value that is perfectly valid.
     *
     * Normalising to strings before validation keeps storage consistent and lets
     * either form through.
     *
     * @param  string[]  $keys        scalar id fields
     * @param  string[]  $arrayKeys   fields holding a list of ids
     */
    protected function stringifyIds($request, array $keys, array $arrayKeys = []): void
    {
        $patch = [];

        foreach ($keys as $k) {
            $v = $request->input($k);
            if (is_int($v) || is_float($v)) {
                $patch[$k] = (string) $v;
            }
        }

        foreach ($arrayKeys as $k) {
            $v = $request->input($k);
            if (is_array($v)) {
                $patch[$k] = array_values(array_map(
                    fn ($x) => is_scalar($x) ? (string) $x : $x,
                    $v
                ));
            }
        }

        if ($patch) {
            $request->merge($patch);
        }
    }

    public function dashboard($request)
    {
        $wallets = ProviderWallet::query()->where('purpose', 'bookings')->orderByDesc('updated_at')->get()->map(fn ($w) => $this->mapWallet($w))->values()->all();

        $withdrawals = WithdrawalRequest::query()
            ->orderByDesc('requested_at')
            ->limit(500)
            ->get()
            ->map(fn ($w) => $this->mapWithdrawal($w))
            ->values()
            ->all();

        $transactions = WalletTransaction::query()
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get()
            ->map(fn ($t) => $this->mapTransaction($t))
            ->values()
            ->all();

        return sendSuccess('Wallet payouts dashboard fetched', [
            'wallets'               => $wallets,
            'withdrawals'           => $withdrawals,
            'transactions'          => $transactions,
            'active_subs_by_wallet' => $this->activeSubsByWallet(),
        ]);
    }

    public function ownerReview(string $ownerUserId)
    {
        $programs = ClassProgram::query()
            ->where('owner_user_id', $ownerUserId)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'approval_status', 'monthly_price', 'currency', 'billing_interval'])
            ->map(fn ($p) => [
                'id'               => $p->id,
                'name'             => $p->name,
                'status'           => $p->status,
                'approval_status'  => $p->approval_status,
                'monthly_price'    => $p->monthly_price !== null ? (float) $p->monthly_price : null,
                'currency'         => $p->currency,
                'billing_interval' => $p->billing_interval,
            ])
            ->values()
            ->all();

        $programIds = array_column($programs, 'id');
        $memberCount = 0;
        if ($programIds !== []) {
            $memberCount = ClassSubscription::query()
                ->whereIn('program_id', $programIds)
                ->where(function ($q) {
                    $q->where('status', 'active')
                        ->orWhere('trial_status', 'in_trial');
                })
                ->count();
        }

        $earnings = Payment::query()
            ->where('type', 'subscription_earning')
            ->whereNotNull('subscription_id')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['subscription_id', 'amount'])
            ->map(fn ($p) => [
                'subscription_id' => (string) $p->subscription_id,
                'amount'          => (float) $p->amount,
            ])
            ->values()
            ->all();

        return sendSuccess('Owner wallet review fetched', [
            'programs'     => $programs,
            'member_count' => $memberCount,
            'earnings'     => $earnings,
        ]);
    }

    /**
     * @return array<string, int>
     */
    protected function activeSubsByWallet(): array
    {
        $map = [];
        $rows = ClassSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('wallet_id')
            ->get(['wallet_id']);

        foreach ($rows as $row) {
            $wid = (string) $row->wallet_id;
            if ($wid === '') {
                continue;
            }
            $map[$wid] = ($map[$wid] ?? 0) + 1;
        }

        return $map;
    }

    /** @var array<string,?string> owner key → numeric users.id, resolved once per request */
    protected array $ownerIdCache = [];

    /** Numeric users.id for a wallet's owner key, whichever form it was stored in. */
    protected function ownerNumericId($key): ?string
    {
        $key = (string) ($key ?? '');
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $this->ownerIdCache)) {
            return $this->ownerIdCache[$key];
        }

        $id = ctype_digit($key)
            ? \App\Models\User::whereKey((int) $key)->value('id')
            : \App\Models\User::where('uuid', $key)->value('id');

        return $this->ownerIdCache[$key] = $id !== null ? (string) $id : null;
    }

    public function listWallets($request)
    {
        $q = ProviderWallet::query()->where('purpose', 'bookings');
        if ($request->filled('owner_user_id')) {
            // Match whichever key shape the wallet was filed under.
            $key = (string) $request->query('owner_user_id');
            $user = ctype_digit($key)
                ? \App\Models\User::find((int) $key)
                : \App\Models\User::where('uuid', $key)->first();
            $keys = $user
                ? array_values(array_unique(array_filter([(string) $user->id, (string) $user->uuid, $key])))
                : [$key];
            $q->whereIn('owner_user_id', $keys);
        }
        if ($request->filled('country_id')) {
            $q->where('country_id', $request->query('country_id'));
        }
        if ($request->boolean('is_current')) {
            $q->where('is_current', true);
        }
        if ($request->filled('purpose')) {
            $q->where('purpose', $request->query('purpose'));
        }

        $rows = $q->orderByDesc('updated_at')->get()->map(fn ($w) => $this->mapWallet($w))->values()->all();

        return sendSuccess('Wallets fetched', ['wallets' => $rows]);
    }

    public function createWallet($request)
    {
        $this->stringifyIds($request, ['owner_user_id', 'country_id'], []);

        $data = $request->validate([
            'owner_user_id' => 'required|string|max:36',
            'country_id'    => 'nullable|string|max:36',
            'currency'      => 'nullable|string|max:16',
            'purpose'       => 'nullable|string|max:32',
            'is_current'    => 'nullable|boolean',
            'status'        => 'nullable|string|max:32',
        ]);

        // A provider has ONE current wallet per country per purpose. Creating blindly
        // is how a second one appeared: the dashboard compared a numeric countries.id
        // against this CHAR column, missed the wallet that already existed, and asked
        // for another. Hand back the existing row instead of minting a rival that
        // splits the balance.
        $purposeKey = $data['purpose'] ?? 'bookings';
        if (! empty($data['country_id'])) {
            $existing = ProviderWallet::query()
                ->where('owner_user_id', $data['owner_user_id'])
                ->where('country_id', (string) $data['country_id'])
                ->where('purpose', $purposeKey)
                ->where('is_current', true)
                ->first();

            if ($existing) {
                return sendSuccess('Wallet already exists', ['wallet' => $this->mapWallet($existing)]);
            }
        }

        $attrs = array_merge([
            'available_balance'  => 0,
            'pending_balance'    => 0,
            'lifetime_earnings'  => 0,
            'lifetime_withdrawn' => 0,
            'currency'           => $data['currency'] ?? '',
            'purpose'            => $purposeKey,
            'is_current'         => $data['is_current'] ?? true,
            'status'             => $data['status'] ?? 'current',
            'hold_days'          => 7,
        ], $data);

        // Allow full seed from FE when ensuring a country wallet with inherited setup.
        $extra = $request->except(array_keys($data));
        $fillable = (new ProviderWallet)->getFillable();
        foreach ($extra as $k => $v) {
            if (in_array($k, $fillable, true) && $k !== 'id') {
                $attrs[$k] = $v;
            }
        }

        $wallet = ProviderWallet::query()->create($attrs);

        return sendSuccess('Wallet created', ['wallet' => $this->mapWallet($wallet->fresh())]);
    }

    public function updateWallet($request, string $id)
    {
        $wallet = ProviderWallet::query()->find($id);
        if (! $wallet) {
            return sendError('Wallet not found.', [], 404);
        }

        $fillable = $wallet->getFillable();
        $patch = [];
        foreach ($request->all() as $k => $v) {
            if (in_array($k, $fillable, true) && $k !== 'id') {
                $patch[$k] = $v;
            }
        }
        if ($patch === []) {
            return sendError('No updatable fields.', [], 422);
        }

        $wallet->update($patch);

        return sendSuccess('Wallet updated', ['wallet' => $this->mapWallet($wallet->fresh())]);
    }

    public function deleteWallet(string $id)
    {
        $wallet = ProviderWallet::query()->find($id);
        if (! $wallet) {
            return sendError('Wallet not found.', [], 404);
        }
        $wallet->delete();

        return sendSuccess('Wallet deleted', []);
    }

    public function applyMethod($request)
    {
        $this->stringifyIds($request, ['owner_user_id'], ['country_ids']);

        $data = $request->validate([
            'owner_user_id' => 'required|string|max:36',
            'purpose'       => 'nullable|string|max:32',
            'country_ids'   => 'nullable|array',
            'country_ids.*' => 'string|max:36',
            'patch'         => 'required|array',
        ]);

        $purpose = $data['purpose'] ?? 'bookings';
        $patch = $data['patch'];
        $fillable = (new ProviderWallet)->getFillable();
        $clean = [];
        foreach ($patch as $k => $v) {
            if (in_array($k, $fillable, true) && $k !== 'id' && $k !== 'owner_user_id') {
                $clean[$k] = $v;
            }
        }

        $q = ProviderWallet::query()
            ->where('owner_user_id', $data['owner_user_id'])
            ->where('is_current', true)
            ->where('purpose', $purpose);

        $updated = 0;
        foreach ($q->get() as $w) {
            $w->update($clean);
            $updated++;
        }

        // Ensure wallets for requested countries that don't exist yet.
        // Dedupe first: country_id is a CHAR column, so a caller sending both 1 and
        // "1" for the same country would otherwise have two wallets created for it.
        $countryIds = array_values(array_unique(array_map(
            fn ($c) => (string) $c,
            array_filter($data['country_ids'] ?? [], fn ($c) => $c !== null && $c !== '')
        )));
        $created = 0;
        foreach ($countryIds as $cid) {
            $exists = ProviderWallet::query()
                ->where('owner_user_id', $data['owner_user_id'])
                ->where('country_id', $cid)
                ->where('purpose', $purpose)
                ->where('is_current', true)
                ->exists();
            if ($exists) {
                continue;
            }
            ProviderWallet::query()->create(array_merge([
                'owner_user_id'        => $data['owner_user_id'],
                'country_id'           => $cid,
                'purpose'              => $purpose,
                'is_current'           => true,
                'status'               => 'current',
                'currency'             => $data['currency'] ?? '',
                'available_balance'    => 0,
                'pending_balance'      => 0,
                'lifetime_earnings'    => 0,
                'lifetime_withdrawn'   => 0,
                'hold_days'            => 7,
            ], $clean));
            $created++;
        }

        $wallets = ProviderWallet::query()
            ->where('owner_user_id', $data['owner_user_id'])
            ->where('purpose', $purpose)
            ->get()
            ->map(fn ($w) => $this->mapWallet($w))
            ->values()
            ->all();

        return sendSuccess('Payout method applied', [
            'updated' => $updated,
            'created' => $created,
            'wallets' => $wallets,
        ]);
    }

    public function approveKyc($request, string $ownerUserId)
    {
        $now = now();
        $reviewedBy = $request->input('kyc_reviewed_by')
            ?: (optional($request->user())->id ? (string) $request->user()->id : null);

        $count = ProviderWallet::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('setup_status', 'pending_review')
            ->update([
                'setup_status'         => 'active',
                'setup_completed_at'   => $now,
                'kyc_reviewed_at'      => $now,
                'kyc_reviewed_by'      => $reviewedBy,
                'kyc_rejection_reason' => null,
                'updated_at'           => $now,
            ]);

        if ($count === 0) {
            return sendError('No pending payout setups for this owner.', [], 404);
        }

        AdminAlerts::toUser($this->ownerLaravelId($ownerUserId), 'wallet', 'ok',
            'Payout method approved — payouts are live',
            'Online money now flows to your wallet', '/wallet');

        return sendSuccess('Payout method approved', ['updated' => $count]);
    }

    public function rejectKyc($request, string $ownerUserId)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);
        $reason = trim((string) ($data['reason'] ?? ''))
            ?: 'Your details could not be verified — please review and resubmit.';
        $now = now();
        $reviewedBy = $request->input('kyc_reviewed_by')
            ?: (optional($request->user())->id ? (string) $request->user()->id : null);

        $count = ProviderWallet::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('setup_status', 'pending_review')
            ->update([
                'setup_status'         => 'rejected',
                'kyc_reviewed_at'      => $now,
                'kyc_reviewed_by'      => $reviewedBy,
                'kyc_rejection_reason' => $reason,
                'updated_at'           => $now,
            ]);

        if ($count === 0) {
            return sendError('No pending payout setups for this owner.', [], 404);
        }

        AdminAlerts::toUser($this->ownerLaravelId($ownerUserId), 'wallet', 'warn',
            'Payout method needs attention', $reason, '/wallet');

        return sendSuccess('Payout method rejected', [
            'updated' => $count,
            'reason'  => $reason,
        ]);
    }

    public function approveWithdrawal($request, string $id)
    {
        $wd = WithdrawalRequest::query()->find($id);
        if (! $wd) {
            return sendError('Withdrawal not found.', [], 404);
        }
        if (! in_array($wd->status, ['pending'], true)) {
            return sendError('Withdrawal is not pending.', [], 422);
        }

        $by = $request->input('processed_by')
            ?: (optional($request->user())->name ?: optional($request->user())->full_name ?: 'Admin');

        $wd->update([
            'status'       => 'approved',
            'approved_at'  => now(),
            'processed_by' => $by,
        ]);

        AdminAlerts::toUser($this->ownerLaravelId($wd->owner_user_id), 'wallet', 'ok',
            'Withdrawal approved — '.$wd->amount.' '.$wd->currency,
            'Approved and on its way to your bank', '/wallet');

        return sendSuccess('Withdrawal approved', ['withdrawal' => $this->mapWithdrawal($wd->fresh())]);
    }

    public function markWithdrawalPaid($request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $wd = WithdrawalRequest::query()->where('id', $id)->lockForUpdate()->first();
            if (! $wd) {
                return sendError('Withdrawal not found.', [], 404);
            }
            if (! in_array($wd->status, ['pending', 'approved'], true)) {
                return sendError('This request was already processed elsewhere.', [], 422);
            }

            $by = $request->input('processed_by')
                ?: (optional($request->user())->name ?: optional($request->user())->full_name ?: 'Admin');

            $wd->update([
                'status'       => 'paid',
                'processed_at' => now(),
                'processed_by' => $by,
            ]);

            $amount = (float) $wd->amount;
            $walletId = $wd->wallet_id;
            if ($walletId) {
                $this->applyDelta($walletId, [
                    'pending'   => -$amount,
                    'withdrawn' => $amount,
                ]);
            }

            WalletTransaction::query()->create([
                'owner_user_id'  => $wd->owner_user_id,
                'country_id'     => $wd->country_id,
                'wallet_id'      => $wd->wallet_id,
                'type'           => 'withdrawal',
                'direction'      => 'debit',
                'amount'         => $amount,
                'provider_share' => $amount,
                'platform_fee'   => 0,
                'source_id'      => $wd->id,
                'source_label'   => 'Withdrawal #' . strtoupper(substr((string) $wd->id, 0, 8)),
                'description'    => 'Withdrawal paid via ' . ($wd->payout_method ?: 'bank'),
                'status'         => 'completed',
                'currency'       => $wd->currency ?: 'AED',
            ]);

            return sendSuccess('Withdrawal marked paid', ['withdrawal' => $this->mapWithdrawal($wd->fresh())]);
        });
    }

    public function rejectWithdrawal($request, string $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $wd = WithdrawalRequest::query()->where('id', $id)->lockForUpdate()->first();
            if (! $wd) {
                return sendError('Withdrawal not found.', [], 404);
            }
            if (! in_array($wd->status, ['pending', 'approved'], true)) {
                return sendError('This request was already processed elsewhere.', [], 422);
            }

            $by = $request->input('processed_by')
                ?: (optional($request->user())->name ?: optional($request->user())->full_name ?: 'Admin');
            $reason = trim((string) ($data['reason'] ?? ''));

            $wd->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'processed_at'     => now(),
                'processed_by'     => $by,
            ]);

            $amount = (float) $wd->amount;
            if ($wd->wallet_id) {
                $this->applyDelta($wd->wallet_id, [
                    'pending'   => -$amount,
                    'available' => $amount,
                ]);
            }

            AdminAlerts::toUser($this->ownerLaravelId($wd->owner_user_id), 'wallet', 'warn',
                'Withdrawal rejected — '.$wd->amount.' '.$wd->currency,
                $wd->rejection_reason ?: 'Contact Bookboll for details', '/wallet');

            return sendSuccess('Withdrawal rejected', ['withdrawal' => $this->mapWithdrawal($wd->fresh())]);
        });
    }

    public function cancelWithdrawal($request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $wd = WithdrawalRequest::query()->where('id', $id)->lockForUpdate()->first();
            if (! $wd) {
                return sendError('Withdrawal not found.', [], 404);
            }
            if ($wd->status !== 'pending') {
                return sendError('Only pending withdrawals can be cancelled.', [], 422);
            }

            $wd->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'processed_by' => $request->input('processed_by')
                    ?: (optional($request->user())->name ?: 'Admin'),
            ]);

            $amount = (float) $wd->amount;
            if ($wd->wallet_id) {
                $this->applyDelta($wd->wallet_id, [
                    'pending'   => -$amount,
                    'available' => $amount,
                ]);
            }

            return sendSuccess('Withdrawal cancelled', ['withdrawal' => $this->mapWithdrawal($wd->fresh())]);
        });
    }

    /** Wallet rows key their owner by the legacy uuid or the numeric id. */
    protected function ownerLaravelId(?string $ownerUserId): ?int
    {
        if (! $ownerUserId) {
            return null;
        }
        if (is_numeric($ownerUserId)) {
            return (int) $ownerUserId;
        }

        return (int) \App\Models\User::where('uuid', $ownerUserId)->value('id') ?: null;
    }

    public function createWithdrawal($request)
    {
        $this->stringifyIds($request, ['owner_user_id', 'country_id', 'wallet_id'], []);

        $data = $request->validate([
            'owner_user_id'  => 'required|string|max:36',
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'nullable|string|max:16',
            'payout_method'  => 'nullable|string|max:64',
            'payout_details' => 'nullable|string',
            'country_id'     => 'nullable|string|max:36',
            'wallet_id'      => 'required|integer|exists:provider_wallets,id',
            'notes'          => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $wallet = ProviderWallet::query()->where('id', $data['wallet_id'])->lockForUpdate()->first();
            if (! $wallet) {
                return sendError('Wallet not found.', [], 404);
            }

            $amount = round((float) $data['amount'], 2);
            if ((float) $wallet->available_balance < $amount) {
                return sendError('Insufficient available balance.', [], 422);
            }

            $wd = WithdrawalRequest::query()->create([
                'owner_user_id'  => $data['owner_user_id'],
                'amount'         => $amount,
                'currency'       => $data['currency'] ?? ($wallet->currency ?: 'AED'),
                'payout_method'  => $data['payout_method'] ?? 'bank',
                'payout_details' => $data['payout_details'] ?? '',
                'status'         => 'pending',
                'country_id'     => $data['country_id'] ?? $wallet->country_id,
                'wallet_id'      => $wallet->id,
                'notes'          => $data['notes'] ?? null,
                'requested_at'   => now(),
            ]);

            $this->applyDelta($wallet->id, [
                'available' => -$amount,
                'pending'   => $amount,
            ]);

            AdminAlerts::toPlatform('wallet', 'info',
                'Withdrawal request — '.$wd->amount.' '.$wd->currency,
                'Review it in Approvals', '/approvals',
                is_numeric($wd->country_id) ? (int) $wd->country_id : null);

            return sendSuccess('Withdrawal requested', ['withdrawal' => $this->mapWithdrawal($wd->fresh())]);
        });
    }

    /**
     * Log an already-paid automatic payout (Stripe Connect) without touching balances.
     */
    public function createPaidWithdrawal($request)
    {
        $this->stringifyIds($request, ['owner_user_id', 'country_id', 'wallet_id'], []);

        $data = $request->validate([
            'owner_user_id'  => 'required|string|max:36',
            'amount'         => 'required|numeric|min:0.01',
            'currency'       => 'nullable|string|max:16',
            'payout_method'  => 'nullable|string|max:64',
            'payout_details' => 'nullable|string',
            'country_id'     => 'nullable|string|max:36',
            'wallet_id'      => 'nullable|integer|exists:provider_wallets,id',
            'notes'          => 'nullable|string',
        ]);

        $wd = WithdrawalRequest::query()->create([
            'owner_user_id'  => $data['owner_user_id'],
            'amount'         => round((float) $data['amount'], 2),
            'currency'       => $data['currency'] ?? 'AED',
            'payout_method'  => $data['payout_method'] ?? 'stripe_connect',
            'payout_details' => $data['payout_details'] ?? '',
            'status'         => 'paid',
            'country_id'     => $data['country_id'] ?? null,
            'wallet_id'      => $data['wallet_id'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'requested_at'   => now(),
            'processed_at'   => now(),
            'approved_at'    => now(),
            'processed_by'   => 'stripe_connect',
        ]);

        return sendSuccess('Paid withdrawal recorded', ['withdrawal' => $this->mapWithdrawal($wd)]);
    }

    public function applyWalletDelta($request)
    {
        $data = $request->validate([
            'wallet_id' => 'required|integer|exists:provider_wallets,id',
            'pending'   => 'nullable|numeric',
            'available' => 'nullable|numeric',
            'earned'    => 'nullable|numeric',
            'withdrawn' => 'nullable|numeric',
        ]);

        $wallet = $this->applyDelta($data['wallet_id'], [
            'pending'   => (float) ($data['pending'] ?? 0),
            'available' => (float) ($data['available'] ?? 0),
            'earned'    => (float) ($data['earned'] ?? 0),
            'withdrawn' => (float) ($data['withdrawn'] ?? 0),
        ]);

        if (! $wallet) {
            return sendError('Wallet not found.', [], 404);
        }

        return sendSuccess('Wallet delta applied', ['wallet' => $this->mapWallet($wallet)]);
    }

    public function createTransaction($request)
    {
        $this->stringifyIds($request, ['owner_user_id', 'country_id', 'wallet_id', 'source_id'], []);

        $data = $request->validate([
            'owner_user_id'       => 'required|string|max:36',
            'type'                => 'nullable|string|max:64',
            'direction'           => 'nullable|in:credit,debit',
            'amount'              => 'required|numeric',
            'provider_share'      => 'nullable|numeric',
            'platform_fee'        => 'nullable|numeric',
            'source_id'           => 'nullable|string|max:36',
            'source_label'        => 'nullable|string',
            'description'         => 'nullable|string',
            'status'              => 'nullable|string|max:32',
            'currency'            => 'nullable|string|max:16',
            'available_on'        => 'nullable|date',
            'hold_days_snapshot'  => 'nullable|integer',
            'released_at'         => 'nullable|date',
            'country_id'          => 'nullable|string|max:36',
            'wallet_id'           => 'nullable|integer|exists:provider_wallets,id',
        ]);

        $tx = WalletTransaction::query()->create([
            'owner_user_id'      => $data['owner_user_id'],
            'type'               => $data['type'] ?? 'booking_online',
            'direction'          => $data['direction'] ?? 'credit',
            'amount'             => $data['amount'],
            'provider_share'     => $data['provider_share'] ?? $data['amount'],
            'platform_fee'       => $data['platform_fee'] ?? 0,
            'source_id'          => $data['source_id'] ?? null,
            'source_label'       => $data['source_label'] ?? '',
            'description'        => $data['description'] ?? '',
            'status'             => $data['status'] ?? 'completed',
            'currency'           => $data['currency'] ?? 'AED',
            'available_on'       => $data['available_on'] ?? null,
            'hold_days_snapshot' => $data['hold_days_snapshot'] ?? null,
            'released_at'        => $data['released_at'] ?? null,
            'country_id'         => $data['country_id'] ?? null,
            'wallet_id'          => $data['wallet_id'] ?? null,
        ]);

        return sendSuccess('Transaction created', ['transaction' => $this->mapTransaction($tx)]);
    }

    public function updateTransaction($request, string $id)
    {
        $tx = WalletTransaction::query()->find($id);
        if (! $tx) {
            return sendError('Transaction not found.', [], 404);
        }

        // Optional claim: only flip on_hold → completed when still on hold
        if ($request->boolean('claim_on_hold') || ($request->input('status') === 'completed' && $tx->status === 'on_hold')) {
            if ($tx->status !== 'on_hold') {
                return sendError('This hold was already released.', [], 422);
            }
        }

        $fillable = $tx->getFillable();
        $patch = [];
        foreach ($request->all() as $k => $v) {
            if (in_array($k, $fillable, true) && $k !== 'id') {
                $patch[$k] = $v;
            }
        }
        if ($patch === []) {
            return sendError('No updatable fields.', [], 422);
        }

        if (($patch['status'] ?? null) === 'completed' && $tx->status === 'on_hold') {
            $updated = WalletTransaction::query()
                ->where('id', $id)
                ->where('status', 'on_hold')
                ->update($patch);
            if (! $updated) {
                return sendError('This hold was already released.', [], 422);
            }
            $tx = $tx->fresh();
        } else {
            $tx->update($patch);
        }

        return sendSuccess('Transaction updated', ['transaction' => $this->mapTransaction($tx->fresh())]);
    }

    public function listTransactions($request)
    {
        $q = WalletTransaction::query()->orderByDesc('created_at');
        if ($request->filled('wallet_id')) {
            $q->where('wallet_id', $request->query('wallet_id'));
        }
        if ($request->filled('owner_user_id')) {
            $q->where('owner_user_id', $request->query('owner_user_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        $limit = min(5000, max(1, (int) $request->query('limit', 500)));
        $rows = $q->limit($limit)->get()->map(fn ($t) => $this->mapTransaction($t))->values()->all();

        return sendSuccess('Transactions fetched', ['transactions' => $rows]);
    }

    public function listWithdrawals($request)
    {
        $q = WithdrawalRequest::query()->orderByDesc('requested_at');
        if ($request->filled('owner_user_id')) {
            $q->where('owner_user_id', $request->query('owner_user_id'));
        }
        if ($request->filled('status')) {
            $statuses = explode(',', (string) $request->query('status'));
            $q->whereIn('status', $statuses);
        }
        $limit = min(500, max(1, (int) $request->query('limit', 100)));
        $rows = $q->limit($limit)->get()->map(fn ($w) => $this->mapWithdrawal($w))->values()->all();

        return sendSuccess('Withdrawals fetched', ['withdrawals' => $rows]);
    }

    /**
     * @param  array{pending?:float,available?:float,earned?:float,withdrawn?:float}  $delta
     */
    public function applyDelta(string $walletId, array $delta): ?ProviderWallet
    {
        return DB::transaction(function () use ($walletId, $delta) {
            $w = ProviderWallet::query()->where('id', $walletId)->lockForUpdate()->first();
            if (! $w) {
                return null;
            }
            $pending = (float) ($delta['pending'] ?? 0);
            $available = (float) ($delta['available'] ?? 0);
            $earned = (float) ($delta['earned'] ?? 0);
            $withdrawn = (float) ($delta['withdrawn'] ?? 0);
            if ($pending === 0.0 && $available === 0.0 && $earned === 0.0 && $withdrawn === 0.0) {
                return $w;
            }

            $w->pending_balance = max(0, round((float) $w->pending_balance + $pending, 2));
            $w->available_balance = round((float) $w->available_balance + $available, 2);
            $w->lifetime_earnings = round((float) $w->lifetime_earnings + $earned, 2);
            $w->lifetime_withdrawn = round((float) $w->lifetime_withdrawn + $withdrawn, 2);
            $w->save();

            return $w->fresh();
        });
    }

    public function mapWallet(ProviderWallet $w): array
    {
        return [
            'id'                     => $w->id,
            'uuid'                   => $w->uuid,
            'owner_user_id'          => $w->owner_user_id,
            // The column is not one key: booking wallets hold users.id, membership
            // wallets and every legacy Supabase row hold users.uuid. Callers know a
            // provider by their numeric id, so a membership wallet keyed by uuid
            // matched nothing and the provider saw no wallets at all. Resolve it
            // once here so there is a single key to compare against.
            'owner_id'               => $this->ownerNumericId($w->owner_user_id),
            'available_balance'      => (float) $w->available_balance,
            'pending_balance'        => (float) $w->pending_balance,
            'lifetime_earnings'      => (float) $w->lifetime_earnings,
            'lifetime_withdrawn'     => (float) $w->lifetime_withdrawn,
            'currency'               => $w->currency,
            'hold_days'              => (int) $w->hold_days,
            'setup_method'           => $w->setup_method,
            'setup_status'           => $w->setup_status,
            'setup_completed_at'     => optional($w->setup_completed_at)?->toISOString(),
            'country_id'             => $w->country_id,
            'payout_bank_country'    => $w->payout_bank_country,
            'payout_bank_name'       => $w->payout_bank_name,
            'payout_account_holder'  => $w->payout_account_holder,
            // FE historically read payout_account_name — expose alias
            'payout_account_name'    => $w->payout_account_holder,
            'payout_iban'            => $w->payout_iban,
            'payout_account_number'  => $w->payout_account_number,
            'payout_routing_number'  => $w->payout_routing_number,
            'payout_swift'           => $w->payout_swift,
            'payout_account_type'    => $w->payout_account_type,
            'is_current'             => (bool) $w->is_current,
            'status'                 => $w->status,
            'purpose'                => $w->purpose,
            'kyc_business_name'      => $w->kyc_business_name,
            'kyc_registration_no'    => $w->kyc_registration_no,
            'kyc_address'            => $w->kyc_address,
            'kyc_contact'            => $w->kyc_contact,
            'kyc_doc_url'            => $w->kyc_doc_url,
            'kyc_submitted_at'       => optional($w->kyc_submitted_at)?->toISOString(),
            'kyc_reviewed_at'        => optional($w->kyc_reviewed_at)?->toISOString(),
            'kyc_reviewed_by'        => $w->kyc_reviewed_by,
            'kyc_rejection_reason'   => $w->kyc_rejection_reason,
            'payout_paused'          => (bool) $w->payout_paused,
            'payout_paused_at'       => optional($w->payout_paused_at)?->toISOString(),
            'payout_pause_reason'    => $w->payout_pause_reason,
            'created_at'             => optional($w->created_at)?->toISOString(),
            'updated_at'             => optional($w->updated_at)?->toISOString(),
        ];
    }

    public function mapWithdrawal(WithdrawalRequest $w): array
    {
        return [
            'id'               => $w->id,
            'uuid'             => $w->uuid,
            'owner_user_id'    => $w->owner_user_id,
            'amount'           => (float) $w->amount,
            'currency'         => $w->currency,
            'payout_method'    => $w->payout_method,
            'payout_details'   => $w->payout_details,
            'status'           => $w->status,
            'rejection_reason' => $w->rejection_reason,
            'requested_at'     => optional($w->requested_at)?->toISOString(),
            'processed_at'     => optional($w->processed_at)?->toISOString(),
            'processed_by'     => $w->processed_by,
            'approved_at'      => optional($w->approved_at)?->toISOString(),
            'cancelled_at'     => optional($w->cancelled_at)?->toISOString(),
            'notes'            => $w->notes,
            'country_id'       => $w->country_id,
            'wallet_id'        => $w->wallet_id,
            'created_at'       => optional($w->created_at)?->toISOString(),
            'updated_at'       => optional($w->updated_at)?->toISOString(),
        ];
    }

    public function mapTransaction(WalletTransaction $t): array
    {
        return [
            'id'                  => $t->id,
            'uuid'                => $t->uuid,
            'owner_user_id'       => $t->owner_user_id,
            'type'                => $t->type,
            'direction'           => $t->direction,
            'amount'              => (float) $t->amount,
            'provider_share'      => (float) $t->provider_share,
            'platform_fee'        => (float) $t->platform_fee,
            'source_id'           => $t->source_id,
            'source_label'        => $t->source_label,
            'description'         => $t->description,
            'status'              => $t->status,
            'currency'            => $t->currency,
            'available_on'        => optional($t->available_on)?->format('Y-m-d'),
            'hold_days_snapshot'  => $t->hold_days_snapshot,
            'released_at'         => optional($t->released_at)?->toISOString(),
            'country_id'          => $t->country_id,
            'wallet_id'           => $t->wallet_id,
            'created_at'          => optional($t->created_at)?->toISOString(),
            'updated_at'          => optional($t->updated_at)?->toISOString(),
        ];
    }
}
