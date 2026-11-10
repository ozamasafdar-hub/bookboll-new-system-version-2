<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Venue;
use App\Models\VenueOperatingAgreement;
use App\Support\AccessScope;
use App\Support\LegacyReconciliation as Legacy;
use App\Support\Money;
use App\Support\PaymentRecipient;
use App\Support\TransferPaymentContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Moving a venue to a business, with an optional reconciliation of its history.
 *
 * Two very different things behind one flow.
 *
 * The MOVE is ordinary: the venue changes hands from a date, and everything
 * already recorded stays exactly as it is.
 *
 * The RECONCILIATION restates what collected money meant — a paid deposit was
 * Bookboll's fee, an unpaid arrival balance was cash the provider took
 * directly. That is a reviewed human assertion about the past, so it happens
 * only when an admin has seen the counts and says so, it creates no receipt,
 * payable, wallet credit or payout, and the original rows survive it.
 *
 * Preview then commit, and the commit trusts nothing: the token, the row set
 * and the sums are all re-derived inside the transaction, and anything that
 * moved since the review is a 409.
 */
class VenueTransferService
{
    public const CONTRACT_VERSION = 1;
    public const PREVIEW_TTL_MINUTES = 20;

    /* ─────────────────────────────── options ─────────────────────────────── */

    public function options(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }

        return sendSuccess('Transfer options', [
            'contract_version' => self::CONTRACT_VERSION,
            'reconciliation_version' => 2,
            'can_transfer'     => $this->canTransfer($actor),
            // Ownerless businesses are eligible destinations: an unclaimed
            // business is a valid operator, just one nobody has logged into.
            'businesses'       => Business::query()
                ->whereIn('status', [Business::STATUS_ACTIVE, Business::STATUS_PENDING])
                ->orderBy('name')
                ->get(['id', 'name', 'owner_id'])
                ->map(fn (Business $b) => [
                    'id'            => (string) $b->id,
                    'name'          => $b->name ?: '',
                    'owner_user_id' => $b->owner_id ? (string) $b->owner_id : null,
                ])->values()->all(),
        ]);
    }

    /* ─────────────────────────────── preview ─────────────────────────────── */

    public function preview(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }
        if (! $this->canTransfer($actor)) {
            return sendError('You cannot transfer venues.', [], 403);
        }

        $data = $request->validate([
            'business_id'    => 'required|integer|exists:businesses,id',
            'effective_from' => 'required|date_format:Y-m-d',
            'history_mode'   => ['required', Rule::in(['preserve', 'legacy_deposit_fee'])],
            'from_date'      => 'nullable|date_format:Y-m-d',
            'to_date'        => 'nullable|date_format:Y-m-d',
        ]);

        $destination = Business::find($data['business_id']);
        $assessment = $this->assess($venue, $destination, $data);

        $preview = DB::table('venue_transfer_previews')->insertGetId([
            'token'          => 'vtp_'.Str::random(56),
            'venue_id'       => $venue->id,
            'actor_user_id'  => $actor->id,
            'business_id'    => $destination->id,
            'effective_from' => $data['effective_from'],
            'history_mode'   => $data['history_mode'],
            'from_date'      => $data['from_date'] ?? null,
            'to_date'        => $data['to_date'] ?? null,
            'eligible_count' => $assessment['eligible_count'],
            'pending_arrival_count'    => $assessment['pending_arrival_count'],
            'excluded_count'           => $assessment['excluded_count'],
            'landlord_obligation_count' => $assessment['landlord_obligation_count'],
            'totals'       => json_encode($assessment['totals']),
            'warnings'     => json_encode($assessment['warnings']),
            'blockers'     => json_encode($assessment['blockers']),
            'payment_ids'  => json_encode($assessment['payment_ids']),
            'state_hash'   => $assessment['state_hash'],
            'expires_at'   => now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $row = DB::table('venue_transfer_previews')->find($preview);

        return sendSuccess('Transfer preview', [
            'contract_version' => self::CONTRACT_VERSION,
            'reconciliation_version' => 2,
            'preview_token'    => $row->token,
            'expires_at'       => \Carbon\Carbon::parse($row->expires_at)->utc()->toIso8601String(),
            'business_id'      => (string) $destination->id,
            'effective_from'   => $data['effective_from'],
            'history_mode'     => $data['history_mode'],
            'destination_name' => $destination->name ?: '',
            'eligible_count'          => $assessment['eligible_count'],
            'pending_arrival_count'   => $assessment['pending_arrival_count'],
            'excluded_count'          => $assessment['excluded_count'],
            'landlord_obligation_count' => $assessment['landlord_obligation_count'],
            'totals'   => $assessment['totals'],
            'warnings' => $assessment['warnings'],
            'blockers' => $assessment['blockers'],
            'payment_changes' => $assessment['payment_changes'],
        ]);
    }

    /**
     * What this transfer would do.
     *
     * In preserve mode the answer is deliberately empty of reclassification:
     * the counts and totals are zero and say so, because "nothing historical
     * changes" is the whole point of the default.
     */
    protected function assess(Venue $venue, ?Business $destination, array $data): array
    {
        $warnings = [];
        $blockers = [];
        $paymentIds = [];
        $totals = [];
        $eligible = 0;
        $pendingArrival = 0;
        $excluded = 0;
        $paymentChanges = [];

        if (! $destination) {
            $blockers[] = 'That business no longer exists.';
        } elseif (!in_array($destination->status, [Business::STATUS_ACTIVE, Business::STATUS_PENDING], true)) {
            $blockers[] = 'Choose an active or pending destination business.';
        }

        // Landlord obligations survive a transfer. It does not cancel rent and
        // it does not pay the landlord.
        $landlordCount = VenueOperatingAgreement::where('venue_id', $venue->id)
            ->whereIn('deal_type', ['fixed_rent', 'revenue_share'])->count();
        if ($landlordCount > 0) {
            $warnings[] = "{$landlordCount} landlord agreement(s) remain in force; this transfer neither cancels rent nor pays it.";
        }

        if ($data['history_mode'] === 'legacy_deposit_fee') {
            if (empty($data['from_date']) || empty($data['to_date'])) {
                $blockers[] = 'Choose the historical date range to reconcile.';
            } elseif ($data['to_date'] >= $data['effective_from']) {
                // The reconciled period is history; it must end before the
                // venue changes hands.
                $blockers[] = 'The historical range must end before the transfer date.';
            } elseif ($data['from_date'] > $data['to_date']) {
                $blockers[] = 'The historical range is inverted.';
            } else {
                [$eligible, $pendingArrival, $excluded, $totals, $paymentIds, $exclusionReasons, $paymentChanges]
                    = $this->scanHistory($venue, $data['from_date'], $data['to_date']);

                foreach ($exclusionReasons as $reason => $n) {
                    $warnings[] = "{$n} excluded — {$reason}";
                }

                if ($eligible === 0 && $pendingArrival === 0) {
                    $warnings[] = 'Nothing in this range can be reconciled.';
                }
                if ($pendingArrival > 0) {
                    $warnings[] = "{$pendingArrival} pending arrival balance(s) would be marked paid in cash to the destination — an assertion about money collected at the facility, not a payment taken now.";
                }
            }
        } else {
            $warnings[] = 'Preserve mode: no historical payment is reclassified and no earnings change.';
        }

        return [
            'eligible_count'          => $eligible,
            'pending_arrival_count'   => $pendingArrival,
            'excluded_count'          => $excluded,
            'landlord_obligation_count' => $landlordCount,
            'totals'      => $totals,
            'warnings'    => array_values($warnings),
            'blockers'    => array_values($blockers),
            'payment_ids' => $paymentIds,
            'payment_changes' => $paymentChanges,
            'state_hash'  => $this->stateHash($venue, $paymentIds),
        ];
    }

    /**
     * Walk the venue's payments in the range once, classifying each.
     *
     * Each booking's own payment allocations decide what it was. Nothing is
     * percentage-estimated from a missing record.
     *
     * @return array{0:int,1:int,2:int,3:array,4:array,5:array}
     */
    protected function scanHistory(Venue $venue, string $from, string $to): array
    {
        $bookings = Booking::query()
            ->where('venue_id', $venue->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy('id');

        if ($bookings->isEmpty()) {
            return [0, 0, 0, [], [], [], []];
        }

        $payments = Payment::query()
            ->whereIn('booking_id', $bookings->keys())
            ->get();

        $eligible = 0;
        $pendingArrival = 0;
        $excluded = 0;
        $ids = [];
        $reasons = [];
        $byCurrency = [];
        $changes = [];

        foreach ($payments as $p) {
            $booking = $bookings->get($p->booking_id);

            if ($reason = Legacy::exclusionFor($p, $booking)) {
                $excluded++;
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }

            $kind = Legacy::kindFor($p, $booking);
            if (! $kind) {
                $excluded++;
                $reason = $p->method === 'online' ? 'Online payment needs its own settlement evidence; never assumed cash.' : 'Not an eligible deposit or arrival receipt.';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }

            $currency = strtoupper((string) $p->currency);
            $minor = Money::toMinor((float) $p->amount, $currency);
            $byCurrency[$currency] ??= ['deposit_to_fee' => 0, 'provider_cash' => 0, 'provider_already_paid' => 0, 'earnings_delta' => 0];

            if ($kind === Legacy::KIND_DEPOSIT_TO_FEE) {
                $eligible++;
                $byCurrency[$currency]['deposit_to_fee'] += $minor;
                // Money Bookboll already held, now named correctly.
                $byCurrency[$currency]['earnings_delta'] += $minor;
            } elseif ($kind === Legacy::KIND_PROVIDER_CASH) {
                $pendingArrival++;
                // Never Bookboll's, so it does not move earnings at all.
                $byCurrency[$currency]['provider_cash'] += $minor;
            } else {
                $byCurrency[$currency]['provider_already_paid'] += $minor;
            }

            $ids[] = (int) $p->id;
            if (count($changes) < 50) $changes[] = [
                'payment_id'=>$p->id, 'booking_id'=>$booking->id, 'date'=>substr((string)$booking->date,0,10),
                'type'=>$p->type, 'method'=>$p->method, 'status'=>$p->status,
                'currency'=>$currency, 'amount'=>(float)$p->amount, 'action'=>$kind,
            ];
        }

        // Currencies are reported apart. Adding QAR to PKR produces a number
        // that describes nothing.
        $totals = [];
        foreach ($byCurrency as $code => $sums) {
            $totals[] = [
                'currency'       => $code,
                'deposit_to_fee' => Money::toMajor($sums['deposit_to_fee'], $code),
                'provider_cash'  => Money::toMajor($sums['provider_cash'], $code),
                'provider_already_paid' => Money::toMajor($sums['provider_already_paid'], $code),
                'earnings_delta' => Money::toMajor($sums['earnings_delta'], $code),
            ];
        }

        return [$eligible, $pendingArrival, $excluded, $totals, $ids, $reasons, $changes];
    }

    /* ──────────────────────────────── commit ─────────────────────────────── */

    public function transfer(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }
        if (! $this->canTransfer($actor)) {
            return sendError('You cannot transfer venues.', [], 403);
        }

        $data = $request->validate([
            'preview_token'   => 'required|string|max:80',
            'idempotency_key' => 'required|string|max:120',
            'acknowledge_legacy_cash' => 'nullable|boolean',
        ]);

        // A lost response, retried, returns the transfer it already made.
        $done = DB::table('venue_transfer_previews')
            ->where('idempotency_key', $data['idempotency_key'])
            ->whereNotNull('transfer_id')->first();
        if ($done) {
            if ((int)$done->venue_id !== $venueId || (int)$done->actor_user_id !== (int)$actor->id
                || $done->token !== $data['preview_token']) return sendError('That request key belongs to a different transfer.', [], 409);
            return $this->transferPayload((int) $done->transfer_id, $venue->fresh());
        }

        return DB::transaction(function () use ($data, $venue, $actor) {
            $venue = Venue::whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $preview = DB::table('venue_transfer_previews')
                ->where('token', $data['preview_token'])->lockForUpdate()->first();

            if (! $preview) {
                return sendError('That review could not be found. Please review the transfer again.', [], 409);
            }
            if ((int) $preview->venue_id !== (int) $venue->id
                || (int) $preview->actor_user_id !== (int) $actor->id) {
                return sendError('That review does not belong to this request.', [], 409);
            }
            if ($preview->consumed_at !== null) {
                return sendError('That review has already been used.', [], 409);
            }
            if (\Carbon\Carbon::parse($preview->expires_at)->isPast()) {
                return sendError('That review has expired. Please review the transfer again.', [], 409);
            }

            $destination = Business::whereKey($preview->business_id)->lockForUpdate()->first();
            if ($preview->history_mode === 'legacy_deposit_fee') {
                $bookings = Booking::where('venue_id',$venue->id)->whereBetween('date',[$preview->from_date,$preview->to_date])->lockForUpdate()->get();
                Payment::whereIn('booking_id',$bookings->modelKeys())->lockForUpdate()->get();
            }
            $recheck = $this->assess($venue, $destination, [
                'effective_from' => $preview->effective_from,
                'history_mode'   => $preview->history_mode,
                'from_date'      => $preview->from_date,
                'to_date'        => $preview->to_date,
            ]);

            if ($recheck['blockers']) {
                return sendError($recheck['blockers'][0], ['blockers' => $recheck['blockers']], 409);
            }
            // The row set and sums must be the ones reviewed. A balance that
            // moved since means the admin approved a different thing.
            if ($recheck['state_hash'] !== $preview->state_hash) {
                return sendError(
                    'This venue\'s payments changed since the review. Please review the transfer again.',
                    [], 409
                );
            }

            $legacy = $preview->history_mode === 'legacy_deposit_fee';
            $acknowledged = (bool) ($data['acknowledge_legacy_cash'] ?? false);

            // Marking somebody else's cash as collected is an assertion, and it
            // is never made on the strength of opening a form.
            if ($legacy && $recheck['pending_arrival_count'] > 0 && ! $acknowledged) {
                return sendError(
                    'Confirm the reviewed cash totals before reconciling pending arrival balances.',
                    ['acknowledge_legacy_cash' => ['Required to mark arrival balances as collected.']],
                    422
                );
            }

            $transferId = DB::table('venue_transfers')->insertGetId([
                'venue_id'           => $venue->id,
                'from_business_id'   => $venue->business_id,
                'from_owner_user_id' => $venue->owner_user_id,
                'to_business_id'     => $destination->id,
                'effective_from'     => $preview->effective_from,
                'history_mode'       => $preview->history_mode,
                'from_date'          => $preview->from_date,
                'to_date'            => $preview->to_date,
                'batch_id'           => 'vtb_'.Str::random(24),
                'acknowledged_legacy_cash' => $legacy && $acknowledged,
                'actor_user_id'      => $actor->id,
                // The date the assertion was made, kept apart from any service
                // date so nothing looks like a backdated cash receipt.
                'asserted_at'        => $legacy ? now() : null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $batch = DB::table('venue_transfers')->where('id', $transferId)->value('batch_id');

            if ($legacy) {
                $this->reconcile((array) json_decode($preview->payment_ids ?: '[]', true),
                    $transferId, $batch, $destination);
            }

            // The move itself.
            $venue->forceFill([
                'business_id'   => $destination->id,
                'owner_user_id' => $destination->owner_id,
                'operator_type' => 'provider',
            ])->save();

            DB::table('venue_transfer_previews')->where('id', $preview->id)->update([
                'consumed_at'     => now(),
                'idempotency_key' => $data['idempotency_key'],
                'transfer_id'     => $transferId,
                'updated_at'      => now(),
            ]);

            return $this->transferPayload((int) $transferId, $venue->fresh());
        });
    }

    /**
     * Restate what the reconciled payments meant, and record both states.
     *
     * Deliberately absent: any Bookboll receipt, provider payable, wallet
     * credit or payout. A deposit Bookboll already held is simply named as the
     * fee it was, and an arrival balance is closed as cash the provider
     * collected at the facility. No money moves here, because none did.
     *
     * @param  array<int,int>  $paymentIds
     */
    protected function reconcile(array $paymentIds, int $transferId, string $batch, Business $destination): void
    {
        if (! $paymentIds) {
            return;
        }

        $payments = Payment::whereIn('id', $paymentIds)->lockForUpdate()->get();

        foreach ($payments as $p) {
            $booking = Booking::findOrFail($p->booking_id);
            $kind = Legacy::kindFor($p, $booking);
            if (! $kind) {
                continue;
            }

            $before = $p->getAttributes();
            $before['booking_financial_context'] = $booking->financial_context;
            $before['internal_allocations'] = $p->ownedAllocations()->get()->toArray();
            $context = TransferPaymentContext::make($booking, $destination, $batch, $kind);
            $p->financial_context = $context;
            $booking->forceFill(['financial_context'=>array_merge($context,['reconciliation_kind'=>null])])->saveQuietly();

            if ($kind === Legacy::KIND_DEPOSIT_TO_FEE) {
                // The gross receipt, processor reference, date, currency and
                // actor are untouched — only what it is CALLED changes.
                $p->forceFill([
                    'type'      => \PaymentType::BOOKING_COMMISSION,
                    'recipient' => '@bookboll',
                    'reconciliation_batch_id' => $batch,
                ])->save();
            } elseif ($kind === Legacy::KIND_PROVIDER_CASH) {
                $p->forceFill([
                    'status' => \PaymentStatus::PAID,
                    'method' => \PaymentMethod::CASH,
                    // Who actually took it, said explicitly rather than implied
                    // by a recipient label. This id — not the label below — is
                    // what stops a second reconciliation claiming the same row.
                    'collection_method'       => Legacy::KIND_PROVIDER_CASH,
                    'collected_by_business_id' => $destination->id,
                    // And said in the label too, because the payments dashboard
                    // reads `recipient IS NULL` as Bookboll's own money. Correct
                    // while the venue was Bookboll's; wrong from this moment on,
                    // so leaving it blank would report the provider's cash as
                    // platform revenue for every venue that ever transfers.
                    'recipient'               => PaymentRecipient::forBusiness($destination),
                    'reconciliation_batch_id' => $batch,
                    'paid_at'                 => $p->paid_at ?: ($p->date ?: $booking->date),
                ])->save();
            } else {
                $p->forceFill([
                    'recipient'=>PaymentRecipient::forBusiness($destination),
                    'collection_method'=>Legacy::KIND_PROVIDER_RECEIPT,
                    'collected_by_business_id'=>$destination->id,
                    'reconciliation_batch_id'=>$batch,
                ])->save();
            }

            DB::table('venue_transfer_allocations')->insert([
                'transfer_id' => $transferId,
                'booking_id'  => $p->booking_id,
                'payment_id'  => $p->id,
                'kind'        => $kind,
                'currency'    => $p->currency,
                'amount_minor' => Money::toMinor((float) $p->amount, (string) $p->currency),
                'before'      => json_encode($before),
                'after'       => json_encode($p->getAttributes()),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    protected function transferPayload(int $transferId, Venue $venue)
    {
        $t = DB::table('venue_transfers')->find($transferId);

        return sendSuccess('Venue transferred', [
            'contract_version' => self::CONTRACT_VERSION,
            'transfer' => [
                'id'             => (string) $t->id,
                'batch_id'       => $t->batch_id,
                'effective_from' => $t->effective_from,
                'history_mode'   => $t->history_mode,
                'acknowledged_legacy_cash' => (bool) $t->acknowledged_legacy_cash,
            ],
            'venue' => [
                'id'            => (string) $venue->id,
                'business_id'   => $venue->business_id ? (string) $venue->business_id : null,
                'owner_user_id' => $venue->owner_user_id ? (string) $venue->owner_user_id : null,
                'operator_type' => $venue->operator_type,
            ],
        ]);
    }

    /* ─────────────────────────────── shared ──────────────────────────────── */

    /** Binds the review to the exact rows and sums it was built from. */
    protected function stateHash(Venue $venue, array $paymentIds): string
    {
        sort($paymentIds);

        $fingerprint = Payment::whereIn('id', $paymentIds)
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => json_encode([$p->getAttributes(), $p->booking?->getAttributes()]))
            ->implode('|');

        return hash('sha256', json_encode([
            'venue'   => $venue->id,
            'owner'   => $venue->owner_user_id,
            'business' => $venue->business_id,
            'operator' => $venue->operator_type,
            'rows'    => $fingerprint,
        ]));
    }

    protected function canTransfer($actor): bool
    {
        return AccessScope::isGlobal($actor) || $actor->hasAnyCapability(['manage_locations']);
    }

    /** @return array{0: ?Venue, 1: ?\App\Models\User, 2: mixed} */
    protected function resolve(Request $request, int $venueId): array
    {
        $actor = $request->user();

        if (! $actor || AccessScope::isProviderScoped($actor)) {
            return [null, null, sendError('You do not have access to venue transfers.', [], 403)];
        }

        $venue = Venue::find($venueId);
        if (! $venue) {
            return [null, null, sendError('Venue not found.', [], 404)];
        }

        $allowed = AccessScope::allowedVenueIds($actor);
        if ($allowed !== null && !in_array((int)$venue->id, $allowed, true)) {
            return [null, null, sendError('This venue is outside your access.', [], 403)];
        }

        return [$venue, $actor, null];
    }
}
