<?php
namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\WalletTransaction;
use App\Support\Money;
use App\Support\PaymentRefundAccess;
use App\Support\PaymentReportRules;
use App\Support\StripeDocumentLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Full original Stripe payment refunds, never an allocation-sized refund. */
final class PaymentRefundService
{
    public function __construct(private StripeRefundGateway $gateway, private PaymentDetailService $details, private PaymentReportService $report) {}

    private function authorize($actor): void
    {
        abort_unless(PaymentRefundAccess::allows($actor), 403, 'Only Bookboll platform admins and superadmins can refund online payments.');
        abort_if(config('preview.enabled'), 409, 'Stripe refunds are disabled in this Railway workspace. No money will be moved.');
        app(\App\Support\PaymentReleaseGate::class)->assertReady('refunds');
    }

    private function charge(Payment $payment): array
    {
        abort_unless($payment->method === 'online' && ($payment->charge_id || $payment->payment_intent_id), 422, 'This record has no Stripe online payment to refund.');
        $account = $payment->financial_context['stripe_account_id'] ?? null;
        $charge = $this->gateway->charge($payment->charge_id, $payment->payment_intent_id, $account);
        abort_unless(($charge['paid'] ?? false) && ($charge['captured'] ?? false), 422, 'The Stripe payment has not been captured.');
        abort_if(!empty($charge['disputed']), 422, 'This payment has an open dispute. Resolve it in Stripe first.');
        // Separate transfers cannot be reversed safely without durable transfer links.
        abort_if(!empty($charge['transfer_group']) && empty($charge['transfer_data']['destination'])
            && ($payment->financial_context['operator'] ?? '') === 'provider', 422, 'This payment uses separate transfers. Review and reverse its transfers in Stripe before refunding.');
        return [$charge, $account];
    }

    /** All rows affected by this charge, including explicitly linked ledger children. */
    public function chargePayments(Payment $source, array $charge)
    {
        $intent = $charge['payment_intent'] ?? $source->payment_intent_id;
        if (is_array($intent)) $intent = $intent['id'];
        $payments = Payment::where(function ($q) use ($source, $charge, $intent) {
            $q->where('id', $source->id)->orWhere('charge_id', $charge['id']);
            if ($intent) $q->orWhere('payment_intent_id', $intent);
        })->with(['booking','subscription','ownedAllocations'])->get();
        $ids = $payments->modelKeys();
        $bookings = $payments->pluck('booking_id')->filter()->unique();
        $subscriptions = $payments->pluck('subscription_id')->filter()->unique();
        $siblings = $bookings->isEmpty() && $subscriptions->isEmpty() ? collect() : Payment::where(function ($q) use ($bookings, $subscriptions) {
            $q->whereIn('booking_id', $bookings)->orWhereIn('subscription_id', $subscriptions);
        })->with(['booking','subscription','ownedAllocations'])->get();
        foreach ($siblings->groupBy(fn ($p) => $p->booking_id ? 'b'.$p->booking_id : 's'.$p->subscription_id) as $group) {
            $booking = $group->first()->booking;
            $facts = $booking ? array_merge($booking->getAttributes(), ['financial_context'=>$booking->financial_context ?? [], 'creation_context'=>$booking->creation_context ?? []]) : [];
            // Also include an unambiguous conserving legacy pair. Do not leave its
            // stored child statuses paid after its original receipt is refunded.
            foreach (PaymentReportRules::rows($group->map(fn ($p) => $this->report->raw($p))->all(), $facts) as $row) {
                $links = $row['source_receipt_ids'] ?? [];
                if ($links && !array_diff(array_map('intval', $links), $ids)) {
                    $child = $group->firstWhere('id', (int)($row['source_payment_id'] ?? $row['id']));
                    if ($child) $payments->push($child);
                }
            }
        }
        return $payments->unique('id')->sortBy('id')->values();
    }

    private function assertWalletSafe($payments): void
    {
        $bookings = $payments->pluck('booking_id')->filter()->unique()->map(fn ($id) => (string)$id)->all();
        $subscriptions = $payments->map(fn ($p) => $p->subscription?->uuid)->filter()->unique()->all();
        if (!$bookings && !$subscriptions) return;
        // Legacy wallet credits lack a receipt/charge foreign key. Matching just a
        // booking (or recurring subscription) could reverse a different collection.
        $hasCredits = WalletTransaction::where('direction', 'credit')->where(function ($q) use ($bookings, $subscriptions) {
            $q->where(fn ($b) => $b->where('type','booking_online')->whereIn('source_id', $bookings))
                ->orWhere(fn ($s) => $s->where('type','subscription_online')->whereIn('source_id', $subscriptions));
        })->exists();
        abort_if($hasCredits, 409, 'This payment has legacy provider-wallet credits without an exact Stripe receipt link. Reconcile its wallet and payout reversal before issuing a refund. No refund has been submitted.');
    }

    private function facts($payments): array
    {
        return $payments->map(fn ($p) => ['id'=>$p->id, 'amount'=>$p->amount, 'currency'=>$p->currency, 'status'=>$p->status,
            'method'=>$p->method, 'type'=>$p->type, 'charge_id'=>$p->charge_id, 'payment_intent_id'=>$p->payment_intent_id,
            'financial_context'=>$p->financial_context, 'allocations'=>$p->ownedAllocations->toArray()])->all();
    }

    private function plan($actor, Payment $source): array
    {
        [$charge, $account] = $this->charge($source);
        $payments = $this->chargePayments($source, $charge);
        $this->assertWalletSafe($payments);
        $authorized = $this->report->authorizedQuery($actor)->whereIn('id', $payments->modelKeys())->count();
        abort_unless($authorized === $payments->count(), 403, 'This Stripe payment includes records outside your access.');
        $currency = strtoupper($charge['currency']);
        $sum = 0;
        foreach ($payments->groupBy(fn ($p) => $p->booking_id ? 'b'.$p->booking_id : ($p->subscription_id ? 's'.$p->subscription_id : 'p'.$p->id)) as $group) {
            $booking = $group->first()->booking;
            $facts = $booking ? array_merge($booking->getAttributes(), ['financial_context'=>$booking->financial_context ?? [], 'creation_context'=>$booking->creation_context ?? []]) : [];
            foreach (PaymentReportRules::rows($group->map(fn ($p) => $this->report->raw($p))->all(), $facts) as $row) {
                if ($row['row_kind'] === 'receipt') continue;
                abort_unless($row['currency'] === $currency && $row['method'] === 'online', 422, 'The linked records do not match this Stripe payment. Review them before refunding.');
                $sum += Money::toMinor($row['amount'], $currency);
            }
        }
        abort_unless($sum === (int)$charge['amount'], 422, 'The Stripe charge amount does not match all linked records. Review the payment before refunding.');
        $facts = $this->facts($payments);
        $fingerprint = hash('sha256', json_encode([$charge['id'], $account, $charge['amount'], $charge['amount_refunded'] ?? 0, $facts]));
        return compact('charge','account','payments','currency','fingerprint','facts');
    }

    public function preview($actor, int $id): array
    {
        $this->authorize($actor);
        $plan = $this->plan($actor, $this->details->find($actor, $id));
        $charge = $plan['charge'];
        abort_if(($charge['amount_refunded'] ?? 0) > 0, 409, 'Stripe already shows a refund. Refresh the refund status before continuing.');
        abort_if(!empty($charge['refunds']['data']), 409, 'This Stripe payment already has a refund request. Refresh its status first.');
        abort_if($plan['payments']->contains(fn ($p) => $p->status !== 'paid'), 422, 'Every connected payment must be paid before requesting a full refund.');
        return ['fingerprint'=>$plan['fingerprint'], 'charge_id'=>$charge['id'], 'currency'=>$plan['currency'],
            'amount'=>Money::toMajor($charge['amount'], $plan['currency']), 'amount_minor'=>$charge['amount'],
            'payments'=>$plan['payments']->map(fn ($p) => ['id'=>$p->id, 'payment_id'=>$p->payment_id, 'booking_ref'=>$p->booking?->booking_id,
                'amount'=>$p->amount, 'type'=>$p->type])->all(),
            'reverses_provider_transfer'=>!empty($charge['transfer_data']['destination']),
            'refunds_application_fee'=>!empty($charge['application_fee'])];
    }

    public function refund($actor, int $id, string $fingerprint, string $reason): array
    {
        $this->authorize($actor);
        $source = $this->details->find($actor, $id);
        $plan = $this->plan($actor, $source);
        $charge = $plan['charge'];
        return Cache::lock('stripe-refund:'.$charge['id'], 120)->block(5, function () use ($actor, $source, $plan, $charge, $fingerprint, $reason) {
            if ($existing = PaymentRefund::where('charge_id', $charge['id'])->first()) {
                return $this->details->refundSummary($existing);
            }
            abort_unless(hash_equals($plan['fingerprint'], $fingerprint), 409, 'This payment changed. Review the refund amount again.');
            abort_if(($charge['amount_refunded'] ?? 0) > 0 || !empty($charge['refunds']['data']), 409, 'Stripe already has a refund for this payment. Refresh its status.');
            abort_if($plan['payments']->contains(fn ($p) => $p->status !== 'paid'), 409, 'A connected payment is no longer paid.');
            $refund = DB::transaction(function () use ($actor, $source, $plan, $charge, $reason) {
                $locked = Payment::whereIn('id', $plan['payments']->modelKeys())->with('ownedAllocations')->orderBy('id')->lockForUpdate()->get();
                abort_unless($this->facts($locked) === $plan['facts'], 409, 'The connected transactions changed. Review the refund again.');
                $refund = PaymentRefund::create(['charge_id'=>$charge['id'], 'stripe_account_id'=>$plan['account'], 'idempotency_key'=>(string)Str::uuid(),
                    'requested_by'=>$actor->id, 'source_payment_id'=>$source->id, 'amount_minor'=>$charge['amount'], 'currency'=>$plan['currency'],
                    'reason'=>$reason, 'payment_ids'=>$locked->modelKeys(), 'before'=>$locked->map->getAttributes()->all(), 'status'=>'submitting']);
                DB::table('payments')->whereIn('id', $locked->modelKeys())->update(['payment_refund_id'=>$refund->id]);
                return $refund;
            });
            try {
                $result = $this->gateway->create($charge, $reason, $refund->idempotency_key, $plan['account']);
                $this->record($refund, $result, $charge);
            } catch (\Throwable $e) {
                // A timeout may mean Stripe accepted it. Never claim failure or auto-create another refund.
                PaymentRefund::whereKey($refund->id)->where('status','!=','succeeded')->update(['status'=>'unknown']);
                report($e);
            }
            return $this->details->refundSummary($refund->fresh());
        });
    }

    public function refresh($actor, int $id): array
    {
        $this->authorize($actor);
        $source = $this->details->find($actor, $id);
        $refund = $source->paymentRefund;
        if ($refund) $this->sync($refund);
        return ['refund'=>$refund ? $this->details->refundSummary($refund->fresh()) : null];
    }

    public function sync(PaymentRefund $refund, ?array $charge = null): void
    {
        $charge ??= $this->gateway->charge($refund->charge_id, null, $refund->stripe_account_id);
        $result = collect($charge['refunds']['data'] ?? [])->first(fn ($r) => ($r['id'] ?? '') === $refund->stripe_refund_id
            || ($r['metadata']['bookboll_refund_key'] ?? '') === $refund->idempotency_key);
        if ($result) $this->record($refund, $result, $charge);
    }

    private function record(PaymentRefund $refund, array $result, array $charge): void
    {
        abort_unless(($result['charge'] ?? null) === $refund->charge_id && (int)($result['amount'] ?? 0) === (int)$refund->amount_minor
            && strtoupper($result['currency'] ?? '') === $refund->currency, 409, 'Stripe refund does not match the requested payment.');
        DB::transaction(function () use ($refund, $result, $charge) {
            $locked = PaymentRefund::lockForUpdate()->findOrFail($refund->id);
            // A late pending event cannot undo a successful refund.
            if ($locked->status === 'succeeded' && $result['status'] !== 'succeeded') return;
            $status = $result['status'] ?? 'pending';
            $url = StripeDocumentLink::sanitize($charge['receipt_url'] ?? null);
            $locked->update(['stripe_refund_id'=>$result['id'], 'status'=>$status, 'processor'=>array_intersect_key($result, array_flip([
                'id','status','amount','currency','charge','created','failure_reason','pending_reason','receipt_number','transfer_reversal','source_transfer_reversal'])),
                'receipt_url'=>$status === 'succeeded' ? $url : null, 'synced_at'=>now()]);
            if ($status !== 'succeeded') return;
            foreach (Payment::whereIn('id', $locked->payment_ids)->lockForUpdate()->get() as $payment) {
                Payment::withoutTimestamps(fn () => $payment->forceFill(['status'=>'refunded',
                    'stripe_receipt_url'=>$url ?: $payment->stripe_receipt_url])->saveQuietly());
            }
        });
    }

    public function webhook(\Stripe\Event $event): void
    {
        $object = $event->data->object;
        $chargeId = $event->type === 'charge.refunded' ? $object->id : $object->charge;
        if (!$chargeId) return;
        $refund = PaymentRefund::where('charge_id', $chargeId)->first();
        if (!$refund || ($event->account ?? null) !== $refund->stripe_account_id) return;
        // Read current processor state to handle delayed or out-of-order events.
        $this->sync($refund);
    }
}
