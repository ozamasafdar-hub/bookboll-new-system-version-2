<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\User;
use App\Support\MobileAppReturn;
use App\Support\TenantOrigins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Coupon as StripeCoupon;
use Stripe\Stripe;
use App\Support\StripeCatalog;
use Stripe\StripeClient;

/**
 * Stripe-backed membership billing.
 *
 * Sign-up redirects to Stripe Checkout in subscription mode (Apple Pay / Google Pay
 * and 3-D Secure come with it, and no card data touches our servers). The trial and
 * every renewal are driven by Stripe; we only react to webhooks.
 *
 * A subscription row is created up front as `incomplete` and carries the enrolment
 * context; the webhook activates it. Rows without stripe_subscription_id predate
 * this and are never touched by billing.
 */
class MembershipBillingService
{
    private const INTERVAL = [
        'weekly'    => ['week', 1],
        'biweekly'  => ['week', 2],
        'monthly'   => ['month', 1],
        'quarterly' => ['month', 3],
        'yearly'    => ['year', 1],
    ];

    public function __construct(protected CustomerProgramService $programs) {}

    /**
     * The recurring line for a programme membership.
     *
     * One Product PER PROGRAMME, reused by everyone who joins it — not one per
     * subscriber, which is what inline price_data produced. A programme is a real
     * sellable thing with its own name, currency and interval, belonging to one
     * merchant, so it earns its own Product; a single platform-wide Product would
     * collapse every merchant's revenue into one line.
     *
     * Re-priced programmes mint a NEW Price under the SAME Product (Stripe Prices
     * are immutable), so existing members keep paying what they joined at until
     * they are deliberately migrated.
     *
     * Falls back to the old inline shape if the catalogue cannot be reached —
     * the amount charged is identical either way.
     *
     * @return array<string,mixed>
     */
    protected function programLineItem($program, float $amount, string $currency, string $unit, int $count): array
    {
        $cents = (int) round($amount * 100);

        $priceId = StripeCatalog::recurringPrice(
            new StripeClient((string) config('services.stripe.secret')),
            'program:'.$program->uuid,
            (string) $program->name,
            $cents,
            $currency,
            $unit,
            $count,
            ['program_uuid' => (string) $program->uuid]
        );

        if ($priceId) {
            return ['quantity' => 1, 'price' => $priceId];
        }

        return [
            'quantity'   => 1,
            'price_data' => [
                'currency'     => $currency,
                'unit_amount'  => $cents,
                'recurring'    => ['interval' => $unit, 'interval_count' => $count],
                'product_data' => ['name' => (string) $program->name],
            ],
        ];
    }

    /** Build the Checkout session for a membership sign-up. */
    public function createCheckoutSession(User $user, ClassProgram $program, array $payload): array
    {
        abort(410, 'Memberships are contact listings. Contact the academy directly.');
        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return ['error' => 'Payments are not configured. Please try again later.'];
        }

        if (! $user->uuid) {
            return ['error' => 'Please sign in to continue.'];
        }

        if (! $this->programs->isVisible($program, null)) {
            return ['error' => 'Program not found.'];
        }

        $capacity = (int) ($program->capacity ?: 0);
        if ($capacity > 0 && ClassSubscription::where('program_id', $program->id)->where('status', 'active')->count() >= $capacity) {
            return ['error' => 'This program is full.'];
        }

        $email = strtolower(trim((string) $user->email));
        if ($this->programs->hasLiveMembership($program->id, $user->uuid, $email)) {
            return ['error' => 'You’re already a member of this program — manage it in My Memberships.'];
        }

        $interval = (string) ($program->billing_interval ?: 'monthly');
        if (! isset(self::INTERVAL[$interval])) {
            return ['error' => 'This membership cannot be billed online.'];
        }

        $amount = round((float) ($program->monthly_price ?: 0), 2);
        if ($amount <= 0) {
            return ['error' => 'This membership has no price set.'];
        }

        $currency = strtolower((string) ($program->currency ?: ''));
        $joiningFee = max(0, round((float) ($program->joining_fee ?: 0), 2));
        $discount = min(max(0, round((float) ($payload['discount'] ?? 0), 2)), $amount);
        $loyalty = max(0, round((float) ($payload['loyalty_redeem'] ?? 0), 2));

        Stripe::setApiKey($secret);

        try {
            $user->createOrGetStripeCustomer();
        } catch (\Throwable $e) {
            Log::error('Membership checkout: Stripe customer failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return ['error' => 'Could not start payment. Please try again.'];
        }

        $trialDays = $this->trialDays($program, $user);

        return DB::transaction(function () use ($user, $program, $payload, $amount, $currency, $interval, $joiningFee, $discount, $loyalty, $trialDays) {
            $sub = ClassSubscription::create([
                'program_id'       => $program->id,
                'customer_id'      => $user->uuid,
                'customer_name'    => $user->name ?: $user->email,
                'customer_email'   => strtolower((string) $user->email),
                'status'           => 'incomplete',
                'trial_status'     => $trialDays > 0 ? 'pending' : 'none',
                'billing_interval' => $interval,
                'monthly_amount'   => $amount,
                'joining_fee'      => $joiningFee,
                'discount_amount'  => $discount,
                'loyalty_redeem'   => $loyalty,
                'country_id'       => $program->venue?->country_id,
                'form_answers'     => $payload['answers'] ?? [],
                'stripe_customer_id' => $user->stripe_id,
            ]);

            try {
                $session = StripeCheckoutSession::create($this->sessionPayload(
                    $user, $program, $sub, $amount, $currency, $interval, $joiningFee, $discount + $loyalty, $trialDays
                ));
            } catch (\Throwable $e) {
                Log::error('Membership checkout failed', ['program' => $program->id, 'error' => $e->getMessage()]);
                throw $e;
            }

            $sub->update(['stripe_checkout_session_id' => $session->id]);

            return ['url' => $session->url, 'session_id' => $session->id, 'subscription_id' => $sub->uuid];
        });
    }

    /** @return array<string, mixed> */
    protected function sessionPayload(
        User $user,
        ClassProgram $program,
        ClassSubscription $sub,
        float $amount,
        string $currency,
        string $interval,
        float $joiningFee,
        float $discount,
        int $trialDays
    ): array {
        [$unit, $count] = self::INTERVAL[$interval];

        $payload = [
            'mode'                      => 'subscription',
            'customer'                  => $user->stripe_id,
            'client_reference_id'       => $sub->uuid,
            'payment_method_collection' => 'always',
            'success_url'               => $this->checkoutReturnUrl($program, '{CHECKOUT_SESSION_ID}'),
            'cancel_url'                => $this->checkoutReturnUrl($program, 'cancelled'),
            'metadata'                  => ['source' => 'membership', 'subscription_uuid' => $sub->uuid],
            'subscription_data'         => [
                'metadata' => [
                    'source'            => 'membership',
                    'subscription_uuid' => $sub->uuid,
                    'program_uuid'      => (string) $program->uuid,
                    'user_id'           => (string) $user->id,
                ],
            ],
            'line_items' => [$this->programLineItem($program, $amount, $currency, $unit, $count)],
        ];

        if ($trialDays > 0) {
            $payload['subscription_data']['trial_period_days'] = $trialDays;
        }

        // Without a trial the joining fee is a one-time line item charged at checkout.
        // With a trial it must wait for the first real invoice ("charged with your
        // first payment after the free trial"), so the webhook adds it as an invoice
        // item once the subscription exists.
        if ($joiningFee > 0 && $trialDays === 0) {
            $payload['line_items'][] = [
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round($joiningFee * 100),
                    'product_data' => ['name' => 'Joining fee'],
                ],
            ];
        }

        // Promo + loyalty are a single one-off discount on the first invoice.
        if ($discount > 0) {
            $coupon = StripeCoupon::create([
                'amount_off' => (int) round($discount * 100),
                'currency'   => $currency,
                'duration'   => 'once',
                'name'       => 'Sign-up discount',
            ]);
            $payload['discounts'] = [['coupon' => $coupon->id]];
        }

        return $payload;
    }

    /**
     * Trial length in days — 0 when there's no trial or the provider's intro offer
     * is already used.
     *
     * A program offers either N free days or N free sessions. Stripe trials are
     * time-based, so a session trial is converted at the program's own cadence
     * (1 free session at 2/week ≈ 4 days), which covers those sessions without
     * giving away a longer window than intended.
     */
    protected function trialDays(ClassProgram $program, User $user): int
    {
        if (! $program->free_trial_enabled) {
            return 0;
        }

        $days = (int) ($program->free_trial_days ?: 0);

        if ($days <= 0) {
            $sessions = (int) ($program->free_trial_sessions ?: 0);
            $perWeek = max(1, (int) ($program->sessions_per_week ?: 1));
            $days = $sessions > 0 ? (int) ceil($sessions * 7 / $perWeek) : 0;
        }

        if ($days <= 0) {
            return 0;
        }

        $owner = $program->owner_user_id ?: ($program->mapped_owner_user_id ? (string) $program->mapped_owner_user_id : null);
        if ($owner && $this->programs->hasUsedIntroOffer($user->email, null, $owner)) {
            return 0;
        }

        return $days;
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /** Checkout finished: link the Stripe subscription and open the membership. */
    public function checkoutCompleted(object $session): void
    {
        $sub = $this->subscriptionFor($session->client_reference_id ?? ($session->metadata->subscription_uuid ?? null));
        if (! $sub || $sub->stripe_subscription_id) {
            return;
        }

        $stripeSub = $this->retrieveSubscription($session->subscription ?? null);
        if (! $stripeSub) {
            return;
        }

        $trialEnd = $stripeSub->trial_end ? now()->setTimestamp($stripeSub->trial_end) : null;

        $sub->update([
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_customer_id'     => (string) ($session->customer ?? $sub->stripe_customer_id),
            'stripe_status'          => $stripeSub->status,
            'status'                 => 'active',
            'trial_status'           => $stripeSub->status === 'trialing' ? 'in_trial' : 'none',
            'trial_ends_at'          => $trialEnd,
            'started_at'             => now(),
            'current_period_end'     => $this->periodEnd($stripeSub),
            'next_billing_at'        => $trialEnd ?: $this->periodEnd($stripeSub),
        ]);

        if ($trialEnd && (float) $sub->joining_fee > 0) {
            $this->queueJoiningFee($sub, $stripeSub->id);
        }
    }

    /** Put the joining fee on the invoice that closes the trial. */
    protected function queueJoiningFee(ClassSubscription $sub, string $stripeSubId): void
    {
        try {
            \Stripe\InvoiceItem::create([
                'customer'     => $sub->stripe_customer_id,
                'subscription' => $stripeSubId,
                'amount'       => (int) round((float) $sub->joining_fee * 100),
                'currency'     => strtolower((string) ($sub->program?->currency ?: '')),
                'description'  => 'Joining fee',
            ]);
        } catch (\Throwable $e) {
            Log::error('Joining fee could not be queued', ['sub' => $sub->id, 'error' => $e->getMessage()]);
        }
    }

    /** A paid invoice: record the money, credit the provider, spend loyalty once. */
    public function invoicePaid(object $invoice): void
    {
        $sub = $this->subscriptionByStripeId($this->invoiceSubscriptionId($invoice));
        if (! $sub) {
            return;
        }

        // Stripe retries webhooks — the invoice id is the payment reference, so an
        // already-recorded invoice is a replay and must not charge or debit again.
        $invoiceId = (string) ($invoice->id ?? '');
        if ($invoiceId !== '' && Payment::where('payment_id', $invoiceId)->exists()) {
            return;
        }

        $gross = round(((int) ($invoice->amount_paid ?? 0)) / 100, 2);
        if ($gross <= 0) {
            $this->syncPeriod($sub, $invoice);

            return;
        }

        $isFirst = ! $sub->first_charge_at;

        DB::transaction(function () use ($sub, $invoice, $gross, $isFirst) {
            $loyalty = $isFirst ? $this->spendLoyalty($sub, $this->invoiceDiscount($invoice)) : 0;

            // Hand over the currency Stripe actually charged — the ledger records
            // the money that moved, not what the program was configured with.
            $this->programs->recordSubscriptionCharge(
                $sub, $gross, $loyalty, (string) ($invoice->id ?? ''),
                strtoupper((string) ($invoice->currency ?? '')) ?: null,
                // Stripe's own hosted invoice page — captured here while the
                // webhook object is in hand, so Payments can offer the document
                // without calling Stripe once per listed row.
                is_string($invoice->hosted_invoice_url ?? null) ? $invoice->hosted_invoice_url : null
            );

            $sub->update([
                'status'            => 'active',
                'stripe_status'     => 'active',
                // A membership sold without a trial has nothing to convert from.
                'trial_status'      => $sub->trial_status === 'none' ? 'none' : 'converted',
                'payment_failed_at' => null,
                'first_charge_at'   => $sub->first_charge_at ?: now(),
            ]);
            $this->syncPeriod($sub, $invoice);
        });
    }

    /**
     * Basil emits `invoice_payment.paid` alongside `invoice.payment_succeeded`. Its
     * payload is a thin payment record, so the invoice itself has to be fetched
     * before the money can be booked.
     */
    public function invoicePaymentPaid(object $invoicePayment): void
    {
        $invoiceId = $invoicePayment->invoice ?? null;
        if (is_object($invoiceId)) {
            $invoiceId = $invoiceId->id ?? null;
        }

        if (! is_string($invoiceId) || $invoiceId === '') {
            return;
        }

        // Cheap guard before spending an API call on a replay we would discard.
        if (Payment::where('payment_id', $invoiceId)->exists()) {
            return;
        }

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return;
        }

        try {
            Stripe::setApiKey($secret);
            $this->invoicePaid(\Stripe\Invoice::retrieve($invoiceId));
        } catch (\Throwable $e) {
            Log::error('Stripe invoice retrieve failed', ['id' => $invoiceId, 'error' => $e->getMessage()]);
        }
    }

    public function invoiceFailed(object $invoice): void
    {
        $sub = $this->subscriptionByStripeId($this->invoiceSubscriptionId($invoice));
        $sub?->update([
            'status'            => 'past_due',
            'stripe_status'     => 'past_due',
            'payment_failed_at' => now(),
        ]);
    }

    /** Status/period changes: trial ending, pauses, provider-side edits. */
    public function subscriptionUpdated(object $stripeSub): void
    {
        $sub = $this->subscriptionByStripeId($stripeSub->id ?? null);
        if (! $sub) {
            return;
        }

        $status = (string) ($stripeSub->status ?? '');

        // Setting cancel_at_period_end fires this very event with the status still
        // `active`/`trialing`, so mapping that straight to 'active' used to undo the
        // customer's cancellation the instant they made it — the membership kept
        // showing as live with a Cancel button that appeared to do nothing.
        // A subscription winding down keeps whatever status it already has.
        $windingDown = (bool) ($stripeSub->cancel_at_period_end ?? false);

        $sub->update([
            'stripe_status'      => $status,
            'status'             => $windingDown
                ? $sub->status
                : (in_array($status, ['active', 'trialing'], true) ? 'active' : ($status === 'past_due' ? 'past_due' : $sub->status)),
            // Same reasoning: don't re-open a trial the member has already cancelled.
            'trial_status'       => ($status === 'trialing' && ! $windingDown) ? 'in_trial' : $sub->trial_status,
            'trial_ends_at'      => ($stripeSub->trial_end ?? null) ? now()->setTimestamp($stripeSub->trial_end) : $sub->trial_ends_at,
            'current_period_end' => $this->periodEnd($stripeSub) ?: $sub->current_period_end,
            'next_billing_at'    => $this->periodEnd($stripeSub) ?: $sub->next_billing_at,
        ]);
    }

    public function subscriptionDeleted(object $stripeSub): void
    {
        $sub = $this->subscriptionByStripeId($stripeSub->id ?? null);
        if (! $sub) {
            return;
        }

        // Safety net for cancellations that did not come through cancel() —
        // the Stripe dashboard, a failed-payment cancel, or an older client.
        $this->dropPendingJoiningFee($sub);

        $inTrial = $sub->trial_ends_at && $sub->trial_ends_at->isFuture();
        $sub->update([
            'status'                    => 'cancelled',
            'stripe_status'             => 'canceled',
            'trial_status'              => $inTrial ? 'cancelled_in_trial' : $sub->trial_status,
            'cancelled_at'              => now(),
            'cancelled_during_trial_at' => $inTrial ? now() : $sub->cancelled_during_trial_at,
        ]);
    }

    /** Abandoned checkout — drop the placeholder row. */
    public function checkoutExpired(object $session): void
    {
        ClassSubscription::where('stripe_checkout_session_id', $session->id ?? '')
            ->where('status', 'incomplete')
            ->whereNull('stripe_subscription_id')
            ->delete();
    }

    /** Customer cancels: stop at the end of the paid period, keep access until then. */
    public function cancel(ClassSubscription $sub): array
    {
        if (! $sub->stripe_subscription_id) {
            $sub->update(array_merge(
                ['status' => 'cancelled', 'cancelled_at' => now()],
                $this->trialCancelPatch($sub)
            ));

            return ['ok' => true];
        }

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return ['error' => 'Payments are not configured.'];
        }

        Stripe::setApiKey($secret);

        // A member still inside a free trial has paid nothing, so there is no
        // paid period to see out — end it now, which is also what the customer
        // is promised ("your membership pass will be removed"). A member who has
        // paid keeps the period they bought and stops renewing after it.
        $inTrial = $sub->trial_status === 'in_trial' && ! $sub->first_charge_at;

        try {
            // Before anything else: a joining fee that was queued for an invoice
            // that will now never be raised must not outlive the subscription.
            $this->dropPendingJoiningFee($sub);

            if ($inTrial) {
                // Immediate cancel. This supersedes any cancel_at_period_end a
                // previous attempt may have set, so there is nothing to undo first.
                \Stripe\Subscription::retrieve($sub->stripe_subscription_id)->cancel();
            } else {
                \Stripe\Subscription::update($sub->stripe_subscription_id, ['cancel_at_period_end' => true]);
            }
        } catch (\Throwable $e) {
            // Already gone at Stripe (cancelled elsewhere, or the id is stale) is
            // not a failure — the member asked to stop, and refusing here would
            // strand them on a membership they cannot get rid of.
            if ($this->stripeSubscriptionIsGone($e)) {
                $sub->update(array_merge(
                    ['status' => 'cancelled', 'cancel_reason' => 'customer', 'cancelled_at' => now()],
                    $this->trialCancelPatch($sub)
                ));

                return ['ok' => true];
            }

            Log::error('Membership cancel failed', ['sub' => $sub->id, 'error' => $e->getMessage()]);

            return ['error' => 'Could not cancel right now. Please try again.'];
        }

        if ($inTrial) {
            $sub->update(array_merge(
                ['status' => 'cancelled', 'stripe_status' => 'canceled', 'cancel_reason' => 'customer', 'cancelled_at' => now()],
                $this->trialCancelPatch($sub)
            ));

            return ['ok' => true];
        }

        // Scheduled, not ended: `cancelled_at` set while `status` is still active
        // is how the rest of the app reads "cancels at the end of this period".
        // The status itself only flips when Stripe sends customer.subscription.deleted.
        $sub->update(['cancel_reason' => 'customer', 'cancelled_at' => now()]);

        return ['ok' => true];
    }

    /**
     * Undo a cancellation that has been SCHEDULED but not yet taken effect —
     * the member changed their mind before the period they paid for ran out.
     *
     * Only the winding-down state is reversible. A cancellation that already
     * completed is terminal at Stripe (a `canceled` subscription cannot be
     * revived), and a trial cancel completes immediately, so both have to go
     * through a fresh sign-up instead. The caller enforces that; this is the
     * Stripe half.
     *
     * The joining fee is the subtle part. cancel() DELETED the pending invoice
     * item so a cancelled member would not be billed it later — resuming has to
     * put it back, or the member keeps the membership and never pays the fee
     * they agreed to. It is only re-queued when the trial has still not ended
     * and nothing has been charged yet, which is exactly when the original
     * queueJoiningFee() would have run.
     */
    public function resume(ClassSubscription $sub): array
    {
        if (! $sub->stripe_subscription_id) {
            $sub->update(['cancelled_at' => null, 'cancel_reason' => null]);

            return ['ok' => true];
        }

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return ['error' => 'Payments are not configured.'];
        }

        Stripe::setApiKey($secret);

        try {
            $stripeSub = \Stripe\Subscription::update(
                $sub->stripe_subscription_id,
                ['cancel_at_period_end' => false]
            );
        } catch (\Throwable $e) {
            // Already gone means the period ran out (or it was cancelled at
            // Stripe directly) while the member was deciding. There is nothing
            // to resume, and saying so is better than a generic failure.
            if ($this->stripeSubscriptionIsGone($e)) {
                $sub->update(['status' => 'cancelled', 'cancelled_at' => $sub->cancelled_at ?: now()]);

                return ['error' => 'This membership has already ended. Please join again.'];
            }

            Log::error('Membership resume failed', ['sub' => $sub->id, 'error' => $e->getMessage()]);

            return ['error' => 'Could not resume right now. Please try again.'];
        }

        $trialEnd = ($stripeSub->trial_end ?? null) ? now()->setTimestamp($stripeSub->trial_end) : null;
        if ($trialEnd && $trialEnd->isFuture() && ! $sub->first_charge_at && (float) $sub->joining_fee > 0) {
            $this->requeueJoiningFee($sub);
        }

        $sub->update([
            'cancelled_at'   => null,
            'cancel_reason'  => null,
            'stripe_status'  => (string) ($stripeSub->status ?? $sub->stripe_status),
            'trial_status'   => ($stripeSub->status ?? '') === 'trialing' ? 'in_trial' : $sub->trial_status,
            'next_billing_at' => $this->periodEnd($stripeSub) ?: $sub->next_billing_at,
        ]);

        return ['ok' => true];
    }

    /**
     * Re-queue the joining fee, but only if cancel() really did remove it.
     *
     * dropPendingJoiningFee() swallows its own errors by design, so the delete
     * may have failed and left the item in place. Creating a second one would
     * bill the member the fee twice — so count what is actually pending for
     * this subscription first and do nothing if a fee is already waiting.
     */
    protected function requeueJoiningFee(ClassSubscription $sub): void
    {
        if (! $sub->stripe_customer_id) {
            return;
        }

        try {
            $items = \Stripe\InvoiceItem::all([
                'customer' => $sub->stripe_customer_id,
                'pending'  => true,
                'limit'    => 100,
            ]);

            foreach ($items->autoPagingIterator() as $item) {
                $itemSub = $item->subscription ?? null;
                if (is_object($itemSub)) {
                    $itemSub = $itemSub->id ?? null;
                }
                if ($itemSub && $itemSub === $sub->stripe_subscription_id) {
                    return; // a fee is already pending — leave it alone
                }
            }
        } catch (\Throwable $e) {
            // Can't prove it is absent, so don't risk a duplicate charge.
            Log::error('Could not check pending joining fee on resume', [
                'sub'   => $sub->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->queueJoiningFee($sub, $sub->stripe_subscription_id);
    }

    /**
     * Remove a joining fee that was queued but never invoiced.
     *
     * queueJoiningFee() parks the fee on the Stripe CUSTOMER as a pending invoice
     * item so it rides the invoice that closes the trial. If the member cancels
     * during the trial that item is orphaned — it is not deleted with the
     * subscription. Stripe then sweeps it onto the customer's next invoice, or
     * finalises it into a standalone invoice whose total becomes customer-balance
     * debt that is added to whatever they are billed next.
     *
     * That is exactly how a member who cancelled a trial was still charged its
     * joining fee weeks later, on top of a different membership.
     *
     * Pending items can be deleted; invoiced ones cannot, so an already-billed fee
     * is left alone (it is real money that needs a refund, not a delete).
     */
    protected function dropPendingJoiningFee(ClassSubscription $sub): void
    {
        if (! $sub->stripe_customer_id) {
            return;
        }

        try {
            $items = \Stripe\InvoiceItem::all([
                'customer' => $sub->stripe_customer_id,
                'pending'  => true,
                'limit'    => 100,
            ]);

            foreach ($items->autoPagingIterator() as $item) {
                $itemSub = $item->subscription ?? null;
                if (is_object($itemSub)) {
                    $itemSub = $itemSub->id ?? null;
                }

                // Only this subscription's fee. A pending item with no subscription
                // is not ours to judge, so it is left untouched.
                if (! $itemSub || $itemSub !== $sub->stripe_subscription_id) {
                    continue;
                }

                \Stripe\InvoiceItem::retrieve($item->id)->delete();
                Log::info('Dropped un-invoiced joining fee for a cancelled membership', [
                    'sub'  => $sub->id,
                    'item' => $item->id,
                ]);
            }
        } catch (\Throwable $e) {
            // Never block a cancellation on this, but make it findable: a survivor
            // here is money the member will be wrongly charged later.
            Log::error('Could not drop pending joining fee on cancel', [
                'sub'   => $sub->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Trial bookkeeping applied whenever a cancellation ends a trial outright. */
    protected function trialCancelPatch(ClassSubscription $sub): array
    {
        $inTrial = $sub->trial_status === 'in_trial'
            || ($sub->trial_ends_at && $sub->trial_ends_at->isFuture());

        return $inTrial
            ? ['trial_status' => 'cancelled_in_trial', 'cancelled_during_trial_at' => now()]
            : [];
    }

    /** True when Stripe says the subscription no longer exists / is already cancelled. */
    protected function stripeSubscriptionIsGone(\Throwable $e): bool
    {
        if ($e instanceof \Stripe\Exception\InvalidRequestException) {
            $msg = strtolower((string) $e->getMessage());

            return str_contains($msg, 'no such subscription')
                || str_contains($msg, 'already canceled')
                || str_contains($msg, 'already cancelled');
        }

        return false;
    }

    /**
     * State for the page the customer lands on after Checkout. The webhook is the
     * source of truth, so this reports "processing" until it has run.
     */
    public function checkoutState(User $user, string $sessionId)
    {
        $sub = ClassSubscription::with('program')
            ->where('stripe_checkout_session_id', $sessionId)
            ->where('customer_id', $user->uuid)
            ->first();

        if (! $sub) {
            return sendError('Sign-up not found.', [], 404);
        }

        return sendSuccess('Checkout state', [
            'status'        => $sub->stripe_subscription_id ? 'ready' : 'processing',
            'subscription'  => $this->programs->mapSubscriptionPublic($sub, $sub->program),
            'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
            'program_id'    => $sub->program?->uuid,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Storefront URL the customer returns to after Checkout.
     *
     * The site they are subscribing on, not the marketplace: a member joining a program
     * from a merchant's white-label storefront must come back to that storefront, where
     * the program page and their session actually are. FRONTEND_URL is the fallback for
     * callers with no browser origin.
     */
    /**
     * Where Stripe sends the member afterwards.
     *
     * A browser goes straight back to the storefront's program page, which reads
     * `?checkout=`. The mobile app cannot be a Stripe return URL (Stripe insists on
     * http(s)), so its checkout bounces through /payment/app-redirect on this
     * backend, which reopens the app at the same screen with the same query.
     * `$checkout` is either Stripe's `{CHECKOUT_SESSION_ID}` placeholder or the
     * literal `cancelled`; Stripe substitutes the placeholder wherever it appears.
     */
    protected function checkoutReturnUrl($program, string $checkout): string
    {
        if (MobileAppReturn::targetFromRequest(request()) === MobileAppReturn::TARGET_APP) {
            return url('/payment/app-redirect')
                .'?to='.rawurlencode("memberships/{$program->uuid}")
                .'&checkout='.$checkout;
        }

        return $this->storefront("/programs/{$program->uuid}?checkout={$checkout}");
    }

    protected function storefront(string $path): string
    {
        $base = TenantOrigins::storefrontOriginFromRequest(request())
            ?? (string) config('app.frontend_url');

        return rtrim($base, '/').$path;
    }

    protected function subscriptionFor(?string $uuid): ?ClassSubscription
    {
        return $uuid ? ClassSubscription::where('uuid', $uuid)->first() : null;
    }

    protected function subscriptionByStripeId(?string $id): ?ClassSubscription
    {
        return $id ? ClassSubscription::where('stripe_subscription_id', $id)->first() : null;
    }

    protected function retrieveSubscription(?string $id): ?object
    {
        $secret = trim((string) config('services.stripe.secret'));
        if (! $id || $secret === '') {
            return null;
        }

        try {
            Stripe::setApiKey($secret);

            return \Stripe\Subscription::retrieve($id);
        } catch (\Throwable $e) {
            Log::error('Stripe subscription retrieve failed', ['id' => $id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The subscription an invoice belongs to.
     *
     * Stripe's 2025-08-27.basil API dropped the top-level `subscription` field and
     * moved it under `parent.subscription_details`. Webhooks arrive in the account's
     * API version while our SDK calls pin an older one, so both shapes are live at
     * once and both must be read.
     */
    protected function invoiceSubscriptionId(object $invoice): ?string
    {
        $id = $invoice->parent->subscription_details->subscription
            ?? $invoice->subscription
            ?? null;

        // Expanded payloads give the object rather than the id.
        if (is_object($id)) {
            $id = $id->id ?? null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * End of the current billing period.
     *
     * Basil moved `current_period_end` off the subscription and onto each item; a
     * membership has exactly one item, so the first one is the period we bill on.
     */
    protected function periodEnd(object $stripeSub): ?\Illuminate\Support\Carbon
    {
        $end = $stripeSub->current_period_end
            ?? ($stripeSub->items->data[0]->current_period_end ?? null);

        return $end ? now()->setTimestamp($end) : null;
    }

    /**
     * Advance the period from the invoice that just paid.
     *
     * Only the recurring line carries the billing period — one-off lines such as the
     * joining fee sit at a single instant and would drag the period backwards, so the
     * subscription line has to be picked out rather than assumed to be first.
     */
    protected function syncPeriod(ClassSubscription $sub, object $invoice): void
    {
        $end = null;

        foreach (($invoice->lines->data ?? []) as $line) {
            $isSubscriptionLine = ($line->parent->type ?? null) === 'subscription_item_details'
                || ($line->type ?? null) === 'subscription'
                || ! empty($line->subscription);

            if ($isSubscriptionLine && ($line->period->end ?? null)) {
                $end = max($end, (int) $line->period->end);
            }
        }

        if (! $end) {
            return;
        }

        // Never move the period backwards — a replayed or out-of-order webhook must
        // not reopen a period the customer has already been billed past.
        if ($sub->current_period_end && $sub->current_period_end->timestamp >= $end) {
            return;
        }

        $sub->update([
            'current_period_end' => now()->setTimestamp($end),
            'next_billing_at'    => now()->setTimestamp($end),
        ]);
    }

    /** Discount Stripe actually applied to this invoice, in major units. */
    protected function invoiceDiscount(object $invoice): float
    {
        $total = 0;
        foreach (($invoice->total_discount_amounts ?? []) as $d) {
            $total += (int) ($d->amount ?? 0);
        }

        return round($total / 100, 2);
    }

    /**
     * Spend the points redeemed at sign-up. Never spends more than the discount the
     * invoice actually carried, so a coupon that failed to apply can't cost points.
     * The promo discount is subtracted first — the rest is the loyalty portion.
     */
    protected function spendLoyalty(ClassSubscription $sub, float $invoiceDiscount): float
    {
        $amount = min((float) $sub->loyalty_redeem, max(0, $invoiceDiscount - (float) $sub->discount_amount));
        $user = User::where('uuid', $sub->customer_id)->first();
        if (! $user || $amount <= 0) {
            return 0;
        }

        $cfg = LoyaltyProgram::where('country_id', $sub->country_id)->first();
        $rate = (int) ($cfg->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit = (float) ($cfg->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;

        $points = min((int) ceil($amount / $unit * $rate), (int) (LoyaltyPoint::balance($user->id) ?? 0));
        if ($points <= 0) {
            return 0;
        }

        LoyaltyPoint::create([
            'user_id' => $user->id,
            'type'    => 'debit',
            'points'  => $points,
            'reason'  => 'Redeemed on membership sign-up',
        ]);
        $user->updateLoyaltyTier();

        return round(min($amount, $points / $rate * $unit), 2);
    }
}
