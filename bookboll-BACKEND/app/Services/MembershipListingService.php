<?php
namespace App\Services;

use App\Models\ClassProgram;
use App\Models\MembershipListingInvoice;
use App\Models\MembershipListingSubscription;
use App\Support\AccessScope;
use App\Support\MembershipListingPolicy as Policy;
use App\Support\PaymentReleaseGate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class MembershipListingService
{
    public function index(Request $request)
    {
        $programs = Policy::scope(ClassProgram::with(['venue', 'listingSubscriptions.invoices']), $request->user())->orderByDesc('id')->get();
        $rows = $programs->map(fn ($p) => $this->map($p));
        return sendSuccess('Membership listings loaded', ['programs' => $rows, 'subscriptions' => [], 'sessions' => [], 'providers' => [],
            'billing_enabled' => !config('preview.enabled') && (bool) config('services.stripe.secret'),
            'billing_disabled_reason' => config('preview.enabled') ? 'Subscriptions are not available in this test workspace. No payment will be taken.' : 'Listing billing has not been enabled yet. Contact Bookboll for help.',
            'model' => 'contact_listing', 'currency' => 'USD',
        ]);
    }
    public function map(ClassProgram $p): array
    {
        $business = Policy::business($p);
        $sub = $p->listingSubscriptions->sortByDesc('id')->first();
        return array_merge($p->toArray(), [
            'location_id' => (string) ($p->venue_id ?: $p->location_id),
            'owner_user_id' => (string) ($p->mapped_owner_user_id ?: $p->owner_user_id),
            'business_name' => $business?->name ?: 'Bookboll', 'venue_name' => $p->venue?->name,
            'contact_phone' => Policy::phone($p), 'phone_ready' => Policy::phoneReady($p),
            'listing_monthly_price' => Policy::monthlyPrice($p), 'listing_currency' => 'USD',
            'website_entitled' => Policy::websiteEntitled($p),
            'marketplace_live' => Policy::visible($p),
            'website_live' => Policy::visible($p, (string) ($p->mapped_owner_user_id ?: $p->owner_user_id)),
            'listing_subscription' => $sub?->toArray(),
            'listing_invoices' => $p->listingSubscriptions->flatMap->invoices->sortByDesc('id')->values()->all(),
        ]);
    }
    public function program(Request $request, int $id): ClassProgram
    {
        return Policy::scope(ClassProgram::with(['venue', 'listingSubscriptions.invoices']), $request->user())->findOrFail($id);
    }
    protected function stripe(): StripeClient { return new StripeClient((string) config('services.stripe.secret')); }
    protected function guardMoney(): void
    {
        abort_if(config('preview.enabled'), 409, 'Real listing payments are disabled in this Railway test workspace.');
        app(PaymentReleaseGate::class)->assertReady('payments');
        abort_unless(config('services.stripe.secret'), 503, 'Listing payments are not configured.');
    }
    public function checkout(Request $request, int $id)
    {
        $program = $this->program($request, $id);
        abort_unless($program->venue_id && Policy::phoneReady($program) && !$program->is_draft && $program->status === 'active', 422, 'Save a complete listing with a venue and contact phone first.');
        abort_unless(($program->approval_status ?: 'approved') === 'approved' && !in_array($program->eligibility_status, ['paused', 'ineligible']), 422, 'This listing needs platform approval before subscribing.');
        $this->guardMoney();
        return \Illuminate\Support\Facades\Cache::lock('membership-listing-checkout:'.$program->id, 120)->block(10, function () use ($request, $program) {

            $sub = MembershipListingSubscription::where('program_id', $program->id)->whereNotIn('status', ['canceled', 'incomplete_expired'])->latest('id')->first();
            if ($sub && $sub->status !== 'incomplete') abort(409, 'This listing already has a subscription. Manage its renewal or payment instead.');
            if ($sub?->stripe_checkout_session_id) {
                $session = $this->stripe()->checkout->sessions->retrieve($sub->stripe_checkout_session_id);
                if ($session->status === 'open') return sendSuccess('Checkout ready', ['url' => $session->url]);
                if ($session->status === 'complete') abort(409, 'Payment is being confirmed. Refresh listing billing shortly.');
                $sub->update(['status' => 'incomplete_expired']);
                $sub = null;
            }
            $sub ??= MembershipListingSubscription::create(['program_id' => $program->id, 'business_id' => Policy::business($program)?->id,
                'payer_id' => $request->user()->id, 'amount' => Policy::monthlyPrice($program), 'currency' => 'USD', 'status' => 'incomplete']);
            $meta = ['source' => Policy::SOURCE, 'listing_subscription_id' => (string) $sub->id, 'program_id' => (string) $program->id];
            $base = rtrim((string) config('app.admin_url', env('ADMIN_URL')), '/');
            abort_unless(preg_match('#^https?://#', $base), 503, 'Admin return URL is not configured.');
            $session = $this->stripe()->checkout->sessions->create([
                'mode' => 'subscription', 'client_reference_id' => (string) $sub->id,
                'customer_email' => $request->user()->email,
                'success_url' => $base.'/memberships?billing=success', 'cancel_url' => $base.'/memberships?billing=cancelled',
                'metadata' => $meta, 'subscription_data' => ['metadata' => $meta],
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => (int) round($sub->amount * 100),
                    'recurring' => ['interval' => 'month'], 'product_data' => ['name' => 'Bookboll marketplace listing: '.$program->name]]]],
            ], ['idempotency_key' => 'membership-listing-checkout-'.$sub->id]);
            $sub->update(['stripe_checkout_session_id' => $session->id]);
            $program->update(['show_on_marketplace' => true]);
            return sendSuccess('Checkout ready', ['url' => $session->url]);
        });
    }
    public function billingAction(Request $request, int $id)
    {
        $program = $this->program($request, $id);
        $data = $request->validate(['action' => 'required|in:cancel,resume,portal']);
        $this->guardMoney();
        $sub = MembershipListingSubscription::where('program_id', $program->id)->whereNotNull('stripe_subscription_id')->latest('id')->firstOrFail();
        if ($data['action'] === 'portal') {
            // The Stripe customer belongs to the payer. Never expose another payer's portal.
            abort_unless((int) $sub->payer_id === (int) $request->user()->id, 403, 'Only the account that paid for this listing can open its billing portal.');
            $stripeSub = $this->stripe()->subscriptions->retrieve($sub->stripe_subscription_id);
            $base = rtrim((string) config('app.admin_url', env('ADMIN_URL')), '/');
            $portal = $this->stripe()->billingPortal->sessions->create(['customer' => $stripeSub->customer, 'return_url' => $base.'/memberships']);
            return sendSuccess('Billing portal ready', ['url' => $portal->url]);
        }
        abort_unless(in_array($sub->status, ['active', 'past_due']), 422, 'Only a current subscription can change its renewal.');
        $remote = $this->stripe()->subscriptions->update($sub->stripe_subscription_id, ['cancel_at_period_end' => $data['action'] === 'cancel']);
        $this->syncSubscription($sub, $remote, time());
        return sendSuccess('Listing renewal updated', ['subscription' => $sub->fresh()]);
    }
    /** Claims only this product's signed Stripe events. No browser return grants access. */
    public function webhook(object $event): bool
    {
        $o = $event->data->object;
        if ($event->type === 'charge.refunded') {
            $invoiceId = is_object($o->invoice ?? null) ? $o->invoice->id : ($o->invoice ?? null);
            $row = $invoiceId ? MembershipListingInvoice::where('stripe_invoice_id', $invoiceId)->first() : null;
            if (!$row && ($o->payment_intent ?? null)) $row = MembershipListingInvoice::where('stripe_payment_intent_id', is_object($o->payment_intent) ? $o->payment_intent->id : $o->payment_intent)->first();
            if (!$row) return false;
            $refunded = min((float) $row->amount_paid, $o->amount_refunded / 100);
            // Signed charge events carry the cumulative refund total; never move backwards.
            $row->update(['amount_refunded' => max((float) $row->amount_refunded, $refunded)]);
            if ($refunded >= (float) $row->amount_paid && $row->period_end) {
                $remaining = MembershipListingInvoice::where('listing_subscription_id', $row->listing_subscription_id)->where('status', 'paid')->whereColumn('amount_refunded', '<', 'amount_paid')->max('period_end');
                $row->subscription->update(['paid_until' => $remaining]);
            }
            return true;
        }
        if ($event->type === 'invoice_payment.paid') {
            $invoiceId = is_object($o->invoice) ? $o->invoice->id : $o->invoice;
            $o = $this->stripe()->invoices->retrieve($invoiceId, ['expand' => ['payments']]);
        }
        $type = $event->type;
        $meta = $o->metadata ?? null;
        if (($meta['source'] ?? null) !== Policy::SOURCE) $meta = $o->parent->subscription_details->metadata ?? $o->subscription_details->metadata ?? $meta;
        $sid = $o->subscription ?? $o->parent->subscription_details->subscription ?? null;
        if (str_starts_with($type, 'customer.subscription.')) $sid = $o->id;
        if (is_object($sid)) $sid = $sid->id;
        $sub = $sid ? MembershipListingSubscription::where('stripe_subscription_id', $sid)->first() : null;
        if (!$sub && ($meta['source'] ?? null) === Policy::SOURCE) $sub = MembershipListingSubscription::find($meta['listing_subscription_id'] ?? 0);
        if (!$sub) return ($meta['source'] ?? null) === Policy::SOURCE;
        if ($sid && !$sub->stripe_subscription_id) $sub->update(['stripe_subscription_id' => $sid]);
        if (str_starts_with($type, 'customer.subscription.')) {
            $this->syncSubscription($sub, $o, (int) $event->created);
        } elseif (in_array($type, ['invoice.paid', 'invoice.payment_succeeded', 'invoice_payment.paid', 'invoice.payment_failed', 'invoice.voided'], true)) {
            $this->recordInvoice($sub, $o);
        } elseif ($type === 'checkout.session.expired' && $sub->status === 'incomplete') {
            $sub->update(['status' => 'incomplete_expired']);
        } elseif (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && $sid) {
            $remote = $this->stripe()->subscriptions->retrieve($sid, ['expand' => ['latest_invoice']]);
            $this->syncSubscription($sub, $remote, (int) $event->created);
            if (is_object($remote->latest_invoice)) $this->recordInvoice($sub, $remote->latest_invoice);
        }
        return true;
    }
    public function syncSubscription(MembershipListingSubscription $sub, object $remote, int $at): void
    {
        DB::transaction(function () use ($sub, $remote, $at) {
            $sub = MembershipListingSubscription::whereKey($sub->id)->lockForUpdate()->firstOrFail();
            if ($at < $sub->last_event_at) return;
            $end = $remote->current_period_end ?? $remote->items->data[0]->current_period_end ?? null;
            $sub->update(['stripe_subscription_id' => $remote->id, 'status' => $remote->status,
                'cancel_at_period_end' => (bool) ($remote->cancel_at_period_end ?? false),
                'current_period_end' => $end ? Carbon::createFromTimestamp($end) : null, 'last_event_at' => $at]);
        });
    }
    public function recordInvoice(MembershipListingSubscription $sub, object $invoice): void
    {
        DB::transaction(function () use ($sub, $invoice) {
            $sub = MembershipListingSubscription::whereKey($sub->id)->lockForUpdate()->firstOrFail();
            abort_unless(strtoupper($invoice->currency) === $sub->currency, 422, 'Listing invoice currency mismatch.');
            $row = MembershipListingInvoice::firstOrNew(['stripe_invoice_id' => $invoice->id]);
            // A late failure must not undo a paid invoice; paid notifications may be duplicated.
            if ($row->exists && $row->status === 'paid' && $invoice->status !== 'paid') return;
            $line = collect($invoice->lines->data ?? [])->first(fn ($l) => isset($l->period->end));
            $start = $line?->period->start;
            $end = $line?->period->end;
            $intent = $invoice->payment_intent ?? $invoice->payments->data[0]->payment->payment_intent ?? null;
            if (is_object($intent)) $intent = $intent->id;
            $paid = $invoice->status === 'paid' && (int) $invoice->amount_paid >= (int) $invoice->amount_due && (int) $invoice->amount_due > 0;
            $row->fill(['listing_subscription_id' => $sub->id, 'stripe_payment_intent_id' => $intent ?: $row->stripe_payment_intent_id, 'status' => $paid ? 'paid' : $invoice->status,
                'amount_due' => $invoice->amount_due / 100, 'amount_paid' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency), 'paid_at' => $paid ? Carbon::createFromTimestamp($invoice->status_transitions->paid_at ?? $invoice->created) : null,
                'period_start' => $start ? Carbon::createFromTimestamp($start) : null, 'period_end' => $end ? Carbon::createFromTimestamp($end) : null,
                'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null])->save();
            if ($paid && (float) $row->amount_refunded < (float) $row->amount_paid && $end && (!$sub->paid_until || $end > $sub->paid_until->timestamp)) {
                $sub->update(['paid_until' => Carbon::createFromTimestamp($end), 'status' => in_array($sub->status, ['incomplete', 'past_due']) ? 'active' : $sub->status]);
            }
        });
    }
}
