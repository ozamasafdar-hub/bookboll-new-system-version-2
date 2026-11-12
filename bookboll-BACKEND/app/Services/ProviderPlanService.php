<?php

namespace App\Services;

use App\Http\Middleware\RestrictApiOrigin;
use App\Models\Business;
use App\Models\BusinessDomainPurchase;
use App\Models\BusinessSubscription;
use App\Models\Promocode;
use App\Models\PromocodeRedemption;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\CommissionPolicy;
use App\Support\PremiumPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Coupon as StripeCoupon;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use App\Support\StripeCatalog;
use Stripe\StripeClient;

/**
 * The two things a PROVIDER actually pays Bookboll for:
 *
 *   1. Premium — a recurring subscription ($59/month or $588/year) with a free
 *      trial, unlocking banners, blog, promo codes, exports, white-label…
 *   2. The custom domain — a one-time $119 fee to point their own web address
 *      at their storefront.
 *
 * Both were simulated in the Supabase dashboard: the plan flipped a column and the
 * domain wrote itself with a "(simulated)" toast. Here Stripe is the source of
 * truth. Nothing is granted by the browser — the business row is only ever changed
 * from a Stripe session or subscription we have re-read from Stripe ourselves, so a
 * cancelled or abandoned checkout leaves no entitlement behind.
 *
 * Each of the two lives in its own table — business_subscriptions and
 * business_domain_purchases — because each is an entity with a lifecycle and a
 * history worth keeping, not a column on the business. The one exception is
 * `businesses.plan`, a cache of "is this business entitled right now", kept because
 * the storefront reads it on every white-label page load; applyEntitlement() is the
 * only thing that ever writes it.
 */
class ProviderPlanService
{
    /** Web addresses that may never be connected as a custom domain. */
    protected const BLOCKED_DOMAIN_SUFFIXES = ['bookboll.com', 'localhost'];

    protected const DOMAIN_RE = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    /* ─────────────────────────── Reading the plan ─────────────────────────── */

    public function state(Request $request)
    {
        $user = $request->user();

        // Platform roles administer the marketplace; they have no provider plan to
        // buy and are never gated, so they read as premium with nothing to bill.
        if (AccessScope::ownerUserId($user) === null) {
            return sendSuccess('Plan', $this->payload(null, true));
        }

        return sendSuccess('Plan', $this->payload(PremiumPlan::businessFor($user)));
    }

    protected function payload(?Business $b, bool $platform = false): array
    {
        $sub    = $b?->currentSubscription()->first();
        $domain = $b?->domainPurchases()->pending()->first();

        return [
            'plan'                  => $platform ? 'premium' : ($b?->plan ?: 'free'),
            'is_premium'            => $platform ? true : PremiumPlan::businessEntitled($b),
            'is_platform'           => $platform,
            'billing'               => $sub?->billing,
            'status'                => $sub?->status,
            'premium_since'         => optional($b?->premium_since)?->toIso8601String(),
            'trial_ends_at'         => optional($sub?->trial_ends_at)?->toIso8601String(),
            'renews_at'             => optional($sub?->current_period_end)?->toIso8601String(),
            'cancel_at_period_end'  => (bool) ($sub?->cancel_at_period_end),
            'has_subscription'      => (bool) ($sub?->stripe_subscription_id),
            // Whether this merchant's own-site bookings get the halved
            // commission. Deliberately NOT the same question as is_premium:
            // a legacy trial and a past-due plan both keep product features
            // but neither earns the discount, because neither has paid.
            // The dashboard prefers this explicit answer over inferring one.
            'own_site_commission_discount_eligible' => $platform
                ? false
                : CommissionPolicy::paidPlanEligible($b),
            'domain'                => [
                'domain'     => $b?->brand_custom_domain ?: '',
                'status'     => $b?->brand_domain_status ?: '',
                'paid_at'    => optional($b?->brand_domain_paid_at)?->toIso8601String(),
                'fee'        => PremiumPlan::DOMAIN_FEE,
                'pending'    => $domain?->domain ?: '',
            ],
            'pricing'               => [
                'monthly'          => PremiumPlan::PRICE_MONTHLY,
                'yearly_per_month' => PremiumPlan::YEARLY_PER_MONTH,
                'yearly_total'     => PremiumPlan::PRICE_YEARLY,
                'yearly_save_pct'  => PremiumPlan::YEARLY_SAVE_PCT,
                // What a NEW purchase gets: no free period. PremiumPlan::TRIAL_DAYS
                // is retained for reading subscriptions that still have one.
                'trial_days'       => 0,
                'monthly_available' => false,
                'currency'         => strtoupper(PremiumPlan::CURRENCY),
            ],
            'features'              => PremiumPlan::FEATURES,
        ];
    }

    /* ──────────────────────── Premium — start a plan ──────────────────────── */

    public function checkout(Request $request)
    {
        $data = $request->validate([
            // Annual only. Premium is sold as one offer — US$588/year, paid when
            // subscribing — so a monthly request is refused rather than quietly
            // upgraded to yearly, which would charge twelve times what was asked
            // for. The dashboard already sends 'yearly' even if an older caller
            // passes monthly; this is the server half of that.
            'billing'     => 'required|string|in:yearly',
            'promo_code'  => 'nullable|string|max:64',
            'return_path' => 'nullable|string|max:200',
        ], [
            'billing.in' => 'Premium is billed annually. Monthly subscriptions are no longer sold.',
        ]);

        [$business, $owner, $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }

        if (PremiumPlan::businessEntitled($business)) {
            return sendError('This business is already on the Premium plan.', [], 409);
        }

        $billing = $data['billing'];
        $amount  = PremiumPlan::billedAmount($billing);

        // The discount is recomputed here from the stored code — never taken from
        // the browser, which would let a provider name their own price.
        $promo = null;
        if (! empty($data['promo_code'])) {
            $promo = $this->validatePlanPromo($data['promo_code'], (int) $business->owner_id, (string) $owner->email, $amount);
            if (! $promo['ok']) {
                return sendError($promo['reason'], ['promo_code' => [$promo['reason']]], 422);
            }
        }

        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured. Please contact support.', [], 503);
        }

        try {
            $owner->createOrGetStripeCustomer();
            $session = StripeCheckoutSession::create($this->planSessionPayload(
                $business, $owner, $billing, $promo, $this->returnPath($data['return_path'] ?? null, '/')
            ));
        } catch (\Throwable $e) {
            Log::error('Provider plan checkout failed', ['business' => $business->id, 'error' => $e->getMessage()]);

            return sendError('Could not start the upgrade. Please try again.', [], 502);
        }

        // The subscription exists from the moment the checkout is opened, so an
        // abandoned one reads as `incomplete` rather than leaving no trace. Pressing
        // upgrade twice retires the first attempt instead of stacking dead rows —
        // `incomplete_expired` is Stripe's own word for a checkout that never landed.
        $business->subscriptions()->where('status', 'incomplete')
            ->update(['status' => 'incomplete_expired', 'ended_at' => now()]);

        BusinessSubscription::create([
            'business_id'                => $business->id,
            'plan'                       => 'premium',
            'billing'                    => $billing,
            'status'                     => 'incomplete',
            'amount'                     => $amount,
            'currency'                   => strtoupper(PremiumPlan::CURRENCY),
            'stripe_customer_id'         => $owner->stripe_id,
            'stripe_checkout_session_id' => $session->id,
            'promocode_id'               => $promo['code']->id ?? null,
            'discount_amount'            => $promo['discount'] ?? 0,
        ]);

        return sendSuccess('Checkout ready', [
            'url'        => $session->url,
            'session_id' => $session->id,
            'billing'    => $billing,
            'amount'     => $amount,
            'discount'   => $promo['discount'] ?? 0,
            'trial_days' => PremiumPlan::trialDays($billing),
        ]);
    }

    /**
     * The Premium line item.
     *
     * One Product ("Bookboll Premium") with one Price per interval, reused by every
     * business — not a fresh Product per checkout, which is what inline price_data
     * produced. See App\Support\StripeCatalog for why that matters on a
     * subscription.
     *
     * If the catalogue cannot be reached we fall back to the old inline shape: it
     * charges exactly the same amount, so a Stripe hiccup costs tidy bookkeeping,
     * never a sale.
     *
     * @return array<string,mixed>
     */
    protected function premiumLineItem(float $amount, bool $yearly): array
    {
        $interval = $yearly ? 'year' : 'month';
        $cents    = (int) round($amount * 100);

        $priceId = StripeCatalog::recurringPrice(
            new StripeClient((string) config('services.stripe.secret')),
            'premium',
            'Bookboll Premium',
            $cents,
            PremiumPlan::CURRENCY,
            $interval,
            1,
            ['plan' => 'premium']
        );

        if ($priceId) {
            return ['quantity' => 1, 'price' => $priceId];
        }

        return [
            'quantity'   => 1,
            'price_data' => [
                'currency'     => PremiumPlan::CURRENCY,
                'unit_amount'  => $cents,
                'recurring'    => ['interval' => $interval],
                'product_data' => [
                    'name'        => 'Bookboll Premium — '.($yearly ? 'Yearly' : 'Monthly'),
                    'description' => $yearly
                        ? 'Billed once a year. Includes blog, marketing, exports, promo codes, banners, widgets and white-label.'
                        : 'Billed monthly. Includes blog, marketing, exports, promo codes, banners, widgets and white-label.',
                ],
            ],
        ];
    }

    /** @param array{ok:bool,code:Promocode,discount:float}|null $promo */
    protected function planSessionPayload(Business $business, User $owner, string $billing, ?array $promo, string $returnPath): array
    {
        $yearly = PremiumPlan::billingIsYearly($billing);
        $amount = PremiumPlan::billedAmount($billing);

        $metadata = [
            'source'        => PremiumPlan::SOURCE_PLAN,
            'business_id'   => (string) $business->id,
            'owner_user_id' => (string) $business->owner_id,
            'billing'       => $billing,
            'promocode_id'  => (string) ($promo['code']->id ?? ''),
            'discount'      => (string) ($promo['discount'] ?? 0),
        ];

        $payload = [
            'mode'                      => 'subscription',
            'customer'                  => $owner->stripe_id,
            'client_reference_id'       => 'business:'.$business->id,
            'payment_method_collection' => 'always',
            'success_url'               => $this->adminUrl($returnPath, ['plan_checkout' => '{CHECKOUT_SESSION_ID}']),
            'cancel_url'                => $this->adminUrl($returnPath, ['plan_checkout' => 'cancelled']),
            'metadata'                  => $metadata,
            'subscription_data'         => [
                'metadata' => $metadata,
                // No trial_period_days, deliberately. Premium is paid when
                // subscribing, and a trial created by the SERVER cannot be
                // switched off by the browser — so the only way to guarantee
                // there is no free period is to never ask Stripe for one.
                // Subscriptions that already carry a trial are left exactly as
                // they are; this governs new checkouts only.
            ],
            'line_items' => [$this->premiumLineItem($amount, $yearly)],
        ];

        // A promo discounts the first billing period — a one-shot coupon, so the
        // plan renews at full price exactly as the upsell copy says.
        //
        // Stripe rejects `discounts` and `allow_promotion_codes` together, so the two
        // are exclusive here as well: our codes live in our own promocodes table and
        // are validated before we get this far, so Stripe's own promotion-code box is
        // only offered when we are not already applying one.
        if ($promo && $promo['discount'] > 0) {
            $coupon = StripeCoupon::create([
                'amount_off' => (int) round($promo['discount'] * 100),
                'currency'   => PremiumPlan::CURRENCY,
                'duration'   => 'once',
                'name'       => 'Promo '.$promo['code']->code,
                'metadata'   => ['source' => PremiumPlan::SOURCE_PLAN, 'promocode_id' => (string) $promo['code']->id],
            ]);
            $payload['discounts'] = [['coupon' => $coupon->id]];
        } else {
            $payload['allow_promotion_codes'] = false;
        }

        return $payload;
    }

    /* ──────────── Premium — reconcile when the provider comes back ────────── */

    /**
     * Stripe redirects the browser back before the webhook necessarily lands, so the
     * page asks us directly. Same fulfilment path as the webhook, so whichever wins
     * the race the result is identical.
     */
    public function confirmCheckout(Request $request, string $sessionId)
    {
        [$business, , $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }

        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured.', [], 503);
        }

        try {
            $session = StripeCheckoutSession::retrieve(['id' => $sessionId, 'expand' => ['subscription']]);
        } catch (\Throwable $e) {
            return sendError('That checkout could not be found.', [], 404);
        }

        if ((string) ($session->metadata['business_id'] ?? '') !== (string) $business->id) {
            return sendError('That checkout belongs to another account.', [], 403);
        }

        $this->fulfillPlanSession($session);

        return sendSuccess('Plan updated', $this->payload($business->fresh()));
    }

    /**
     * Turn a completed Premium checkout into an entitlement. Idempotent: the row is
     * found by its checkout session or by the Stripe subscription id, so a webhook and
     * a browser return that both arrive grant the plan once and record one redemption.
     */
    public function fulfillPlanSession(object $session, ?int $eventAt = null): void
    {
        $businessId = (int) ($session->metadata['business_id'] ?? 0);
        if (! $businessId) {
            return;
        }

        $stripeSub = $this->retrieveSubscription($session->subscription ?? null);
        if (! $stripeSub) {
            return;   // nothing to grant until Stripe has actually made the subscription
        }

        DB::transaction(function () use ($businessId, $session, $stripeSub, $eventAt) {
            $business = Business::whereKey($businessId)->lockForUpdate()->first();
            if (! $business) {
                return;
            }

            $sub = BusinessSubscription::where('stripe_subscription_id', $stripeSub->id)->first()
                ?: BusinessSubscription::where('stripe_checkout_session_id', $session->id)->first();

            if (! $this->isFresh($sub, $eventAt)) {
                return;   // a cancellation already landed; do not re-grant
            }

            // A checkout completed outside our own flow (a Stripe dashboard action,
            // say) still has to land somewhere.
            $alreadyFulfilled = (bool) $sub?->stripe_subscription_id;
            $sub ??= new BusinessSubscription([
                'business_id' => $business->id,
                'plan'        => 'premium',
                // Yearly is the only offer sold, so an absent metadata value
                // means yearly. Defaulting to monthly here would record a
                // US$588 charge as a US$59 monthly plan and then renew it
                // against the wrong amount.
                'billing'     => $session->metadata['billing'] ?? 'yearly',
                'amount'      => PremiumPlan::billedAmount($session->metadata['billing'] ?? 'yearly'),
                'currency'    => strtoupper(PremiumPlan::CURRENCY),
            ]);

            $sub->fill(array_merge($this->subscriptionFields($stripeSub), [
                'business_id'                => $business->id,
                'billing'                    => $session->metadata['billing'] ?? $sub->billing ?: 'monthly',
                'stripe_customer_id'         => (string) ($session->customer ?? $sub->stripe_customer_id),
                'stripe_checkout_session_id' => $session->id,
                'started_at'                 => $sub->started_at ?: now(),
            ], $this->stampEvent($eventAt)))->save();

            $this->applyEntitlement($business, $sub);

            if (! $alreadyFulfilled) {
                $this->recordPlanRedemption($business, $sub);
            }
        });
    }

    /** Keep our subscription row in step with whatever Stripe says. */
    public function syncSubscription(object $stripeSub, ?int $eventAt = null): void
    {
        if ((string) ($stripeSub->metadata['source'] ?? '') !== PremiumPlan::SOURCE_PLAN) {
            return;
        }

        $sub = $this->subscriptionFor($stripeSub);
        if (! $sub || ! $this->isFresh($sub, $eventAt)) {
            return;   // a newer event already decided this row
        }

        $sub->fill($this->subscriptionFields($stripeSub) + $this->stampEvent($eventAt))->save();
        $this->applyEntitlement($sub->business, $sub);
    }

    /** Stripe ended the subscription — the features go away with it. */
    public function endSubscription(object $stripeSub, ?int $eventAt = null): void
    {
        if ((string) ($stripeSub->metadata['source'] ?? '') !== PremiumPlan::SOURCE_PLAN) {
            return;
        }

        $sub = $this->subscriptionFor($stripeSub);
        if (! $sub || ! $this->isFresh($sub, $eventAt)) {
            return;
        }

        $sub->fill($this->stampEvent($eventAt) + [
            'status'               => 'canceled',
            'cancel_at_period_end' => false,
            'current_period_end'   => null,
            'trial_ends_at'        => null,
            'ended_at'             => $sub->ended_at ?: now(),
        ])->save();

        $this->applyEntitlement($sub->business, $sub);
    }

    /**
     * The ONLY writer of the entitlement cache on `businesses`.
     *
     * Everything else about a plan lives in business_subscriptions; `plan` is kept on
     * the business because the storefront and every gated request read it, and those
     * must not pay for a join. Keeping the write in one place is what stops the two
     * drifting apart.
     */
    protected function applyEntitlement(?Business $business, ?BusinessSubscription $sub): void
    {
        if (! $business) {
            return;
        }

        $entitled = $sub?->isEntitling() ?? false;

        $fields = ['plan' => $entitled ? 'premium' : 'free'];

        if ($entitled && ! $business->premium_since) {
            $fields['premium_since'] = now();
        }
        if (! $entitled) {
            // The white-label badge removal is a paid feature, so it must not keep
            // hiding once the plan lapses.
            $fields['brand_whitelabel'] = false;
        }

        $business->update($fields);
    }

    /** @return array<string, mixed> */
    /**
     * Write a Stripe subscription onto a business, exactly as the webhook would.
     *
     * Used by `plan:sync-from-stripe` to repair accounts whose webhook was missed,
     * so a repaired row and a webhook-built row are the same row — there is one
     * definition of what a plan is, not two that can drift.
     */
    public function syncSubscriptionFromStripe(Business $business, object $stripeSub): BusinessSubscription
    {
        $sub = BusinessSubscription::where('stripe_subscription_id', $stripeSub->id)->first()
            ?: new BusinessSubscription([
                'business_id' => $business->id,
                'plan'        => 'premium',
                // Same reasoning as the checkout path: yearly is the only
                // offer, so an unlabelled subscription is a yearly one.
                'billing'     => $stripeSub->metadata['billing'] ?? 'yearly',
                'amount'      => PremiumPlan::billedAmount($stripeSub->metadata['billing'] ?? 'yearly'),
                'currency'    => strtoupper(PremiumPlan::CURRENCY),
            ]);

        $sub->fill(array_merge($this->subscriptionFields($stripeSub), [
            'business_id'        => $business->id,
            'billing'            => $stripeSub->metadata['billing'] ?? ($sub->billing ?: 'monthly'),
            'stripe_customer_id' => (string) ($stripeSub->customer ?? $sub->stripe_customer_id),
            'started_at'         => $sub->started_at ?: now(),
        ]))->save();

        $this->applyEntitlement($business, $sub);

        return $sub;
    }

    protected function subscriptionFields(object $stripeSub): array
    {
        $status = (string) $stripeSub->status;

        return [
            'stripe_subscription_id' => $stripeSub->id,
            'status'                 => $status,
            'trial_ends_at'          => $stripeSub->trial_end ? now()->setTimestamp($stripeSub->trial_end) : null,
            'current_period_end'     => $this->periodEnd($stripeSub),
            'cancel_at_period_end'   => (bool) ($stripeSub->cancel_at_period_end ?? false),
            'ended_at'               => in_array($status, ['canceled', 'incomplete_expired'], true) ? now() : null,
        ];
    }

    /**
     * Should this event be applied, or has something newer already been?
     *
     * Stripe does not guarantee delivery order — an event GENERATED before a
     * cancellation can be DELIVERED after it, and retries make that more likely. We
     * therefore compare generation time, not arrival time. `$eventAt` is null for a
     * live API re-read (the browser returning from checkout), which reflects the state
     * as of now and so is never stale.
     */
    protected function isFresh(?BusinessSubscription $sub, ?int $eventAt): bool
    {
        if (! $sub || ! $sub->last_event_at || $eventAt === null) {
            return true;
        }

        // Equal timestamps still apply: two events in the same second are ordered by
        // arrival, which is the best information available.
        return $eventAt >= $sub->last_event_at->getTimestamp();
    }

    /**
     * Record which event decided this row, so an older one cannot undo it.
     *
     * A live API re-read (no event) deliberately stamps NOTHING. Stamping our own
     * clock there would let clock skew against Stripe's silently discard a genuine
     * later cancellation — the cure being worse than the disease.
     */
    protected function stampEvent(?int $eventAt): array
    {
        return $eventAt !== null ? ['last_event_at' => now()->setTimestamp($eventAt)] : [];
    }

    /** Our row for a Stripe subscription — by its id, else the business it names. */
    protected function subscriptionFor(object $stripeSub): ?BusinessSubscription
    {
        $byId = BusinessSubscription::where('stripe_subscription_id', $stripeSub->id)->first();
        if ($byId) {
            return $byId;
        }

        $businessId = (int) ($stripeSub->metadata['business_id'] ?? 0);

        return $businessId
            ? BusinessSubscription::where('business_id', $businessId)->latest('id')->first()
            : null;
    }

    /* ─────────────────────── Premium — cancel / resume ────────────────────── */

    public function cancel(Request $request)
    {
        [$business, , $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }
        $sub = $business->activeSubscription()->first();
        if (! $sub?->stripe_subscription_id) {
            return sendError('There is no active subscription to cancel.', [], 409);
        }
        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured.', [], 503);
        }

        try {
            // At period end, not immediately — they paid for the rest of the period.
            $stripeSub = StripeSubscription::update($sub->stripe_subscription_id, ['cancel_at_period_end' => true]);
        } catch (\Throwable $e) {
            Log::error('Provider plan cancel failed', ['business' => $business->id, 'error' => $e->getMessage()]);

            return sendError('Could not cancel the plan. Please try again.', [], 502);
        }

        $sub->fill($this->subscriptionFields($stripeSub))->save();

        return sendSuccess('Plan will end at the close of your current period', $this->payload($business->fresh()));
    }

    public function resume(Request $request)
    {
        [$business, , $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }
        $sub = $business->activeSubscription()->first();
        if (! $sub?->stripe_subscription_id || ! $sub->cancel_at_period_end) {
            return sendError('There is nothing to resume.', [], 409);
        }
        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured.', [], 503);
        }

        try {
            $stripeSub = StripeSubscription::update($sub->stripe_subscription_id, ['cancel_at_period_end' => false]);
        } catch (\Throwable $e) {
            Log::error('Provider plan resume failed', ['business' => $business->id, 'error' => $e->getMessage()]);

            return sendError('Could not resume the plan. Please try again.', [], 502);
        }

        $sub->fill($this->subscriptionFields($stripeSub))->save();

        return sendSuccess('Plan resumed', $this->payload($business->fresh()));
    }

    /* ──────────────────── Custom domain — the $119 fee ────────────────────── */

    public function domainCheckout(Request $request)
    {
        $data = $request->validate([
            'domain'      => 'required|string|max:191',
            'return_path' => 'nullable|string|max:200',
        ]);

        [$business, $owner, $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }

        $domain = $this->normalizeDomain($data['domain']);
        if ($domain === null) {
            return sendError('Enter a valid domain like book.myclub.com.', ['domain' => ['Enter a valid domain like book.myclub.com.']], 422);
        }
        if ($business->brand_custom_domain === $domain) {
            return sendError('That domain is already connected to this site.', [], 409);
        }
        if ($taken = $this->domainTaken($domain, $business->id)) {
            return sendError($taken, ['domain' => [$taken]], 422);
        }

        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured. Please contact support.', [], 503);
        }

        $returnPath = $this->returnPath($data['return_path'] ?? null, '/website-domain');

        try {
            $owner->createOrGetStripeCustomer();
            $session = StripeCheckoutSession::create([
                'mode'                => 'payment',
                'customer'            => $owner->stripe_id,
                'client_reference_id' => 'business:'.$business->id,
                'success_url'         => $this->adminUrl($returnPath, ['domain_checkout' => '{CHECKOUT_SESSION_ID}']),
                'cancel_url'          => $this->adminUrl($returnPath, ['domain_checkout' => 'cancelled']),
                'metadata'            => [
                    'source'        => PremiumPlan::SOURCE_DOMAIN,
                    'business_id'   => (string) $business->id,
                    'owner_user_id' => (string) $business->owner_id,
                    'domain'        => $domain,
                ],
                'line_items' => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => PremiumPlan::CURRENCY,
                        'unit_amount'  => PremiumPlan::DOMAIN_FEE * 100,
                        'product_data' => [
                            'name'        => 'Custom domain — '.$domain,
                            'description' => 'One-time setup fee to connect your own web address to your Bookboll site. No monthly cost.',
                        ],
                    ],
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::error('Domain fee checkout failed', ['business' => $business->id, 'error' => $e->getMessage()]);

            return sendError('Could not start the payment. Please try again.', [], 502);
        }

        // Remembered, not applied: the domain only lands on the site once Stripe
        // confirms the fee, so an abandoned checkout connects nothing. Any earlier
        // unfinished attempt is retired so only one purchase is ever outstanding.
        $business->domainPurchases()->pending()->update(['status' => BusinessDomainPurchase::STATUS_EXPIRED]);
        BusinessDomainPurchase::create([
            'business_id'                => $business->id,
            'domain'                     => $domain,
            'amount'                     => PremiumPlan::DOMAIN_FEE,
            'currency'                   => strtoupper(PremiumPlan::CURRENCY),
            'status'                     => BusinessDomainPurchase::STATUS_PENDING,
            'stripe_checkout_session_id' => $session->id,
        ]);

        return sendSuccess('Checkout ready', [
            'url'        => $session->url,
            'session_id' => $session->id,
            'domain'     => $domain,
            'amount'     => PremiumPlan::DOMAIN_FEE,
        ]);
    }

    public function confirmDomainCheckout(Request $request, string $sessionId)
    {
        [$business, , $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }
        if (! $this->stripeReady()) {
            return sendError('Online payments are not configured.', [], 503);
        }

        try {
            $session = StripeCheckoutSession::retrieve($sessionId);
        } catch (\Throwable $e) {
            return sendError('That checkout could not be found.', [], 404);
        }

        if ((string) ($session->metadata['business_id'] ?? '') !== (string) $business->id) {
            return sendError('That checkout belongs to another account.', [], 403);
        }

        if (($session->payment_status ?? '') !== 'paid') {
            return sendError('That payment has not completed.', ['status' => $session->payment_status ?? 'unpaid'], 402);
        }

        $this->fulfillDomainSession($session);

        $business = $business->fresh();

        return sendSuccess('Domain connected', [
            'domain'  => $business->brand_custom_domain,
            'status'  => $business->brand_domain_status,
            'paid_at' => optional($business->brand_domain_paid_at)?->toIso8601String(),
            'fee'     => PremiumPlan::DOMAIN_FEE,
        ]);
    }

    /** Idempotent — the purchase row flips to `paid` exactly once. */
    public function fulfillDomainSession(object $session): void
    {
        $businessId = (int) ($session->metadata['business_id'] ?? 0);
        $domain     = $this->normalizeDomain((string) ($session->metadata['domain'] ?? ''));
        if (! $businessId || $domain === null) {
            return;
        }

        DB::transaction(function () use ($businessId, $domain, $session) {
            $business = Business::whereKey($businessId)->lockForUpdate()->first();
            if (! $business) {
                return;
            }

            $purchase = BusinessDomainPurchase::where('stripe_checkout_session_id', $session->id)->first();
            if ($purchase?->status === BusinessDomainPurchase::STATUS_PAID) {
                return;   // already applied
            }

            // Re-checked at fulfilment: someone else may have connected this domain
            // while the provider was on the Stripe page.
            if ($this->domainTaken($domain, $business->id)) {
                Log::warning('Domain fee paid for a domain taken in the meantime', [
                    'business' => $businessId, 'domain' => $domain, 'session' => $session->id,
                ]);

                return;
            }

            $paidAt = now();
            $amount = ($session->amount_total ?? PremiumPlan::DOMAIN_FEE * 100) / 100;

            // A checkout completed outside our own flow still has to be recorded.
            $purchase ??= new BusinessDomainPurchase([
                'business_id'                => $business->id,
                'domain'                     => $domain,
                'currency'                   => strtoupper(PremiumPlan::CURRENCY),
                'stripe_checkout_session_id' => $session->id,
            ]);

            $purchase->fill([
                'status'                   => BusinessDomainPurchase::STATUS_PAID,
                'amount'                   => $amount,
                'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
                'paid_at'                  => $paidAt,
            ])->save();

            $business->update([
                'brand_custom_domain'  => $domain,
                'brand_domain_status'  => 'pending',   // waiting on the CNAME
                'brand_domain_paid_at' => $paidAt,
            ]);

            // The API origin allow-list caches connected domains; drop it so the new
            // one can call the API straight away rather than after the TTL.
            RestrictApiOrigin::forgetCustomDomains();
        });
    }

    /** The provider walked away from the domain checkout — retire the attempt. */
    public function abandonDomainSession(object $session): void
    {
        BusinessDomainPurchase::where('stripe_checkout_session_id', $session->id)
            ->where('status', BusinessDomainPurchase::STATUS_PENDING)
            ->update(['status' => BusinessDomainPurchase::STATUS_EXPIRED]);
    }

    /**
     * Take a paid domain live — for real. The dashboard simply wrote 'active'; here
     * we look the record up in DNS and only go live when it actually points at us,
     * so "Live" on the page means the site genuinely resolves.
     */
    public function verifyDomain(Request $request)
    {
        [$business, , $error] = $this->resolveBusiness($request);
        if ($error) {
            return $error;
        }

        $domain = (string) $business->brand_custom_domain;
        if ($domain === '') {
            return sendError('There is no domain to verify.', [], 409);
        }
        if (! $business->brand_domain_paid_at) {
            return sendError('This domain has not been paid for yet.', [], 402);
        }
        if ($business->brand_domain_status === 'active') {
            return sendSuccess('Domain is already live', ['status' => 'active', 'domain' => $domain]);
        }

        $target = $this->cnameTarget();
        $found  = $this->lookupCname($domain);

        if (! $this->cnameMatches($found, $target)) {
            return sendError(
                $found
                    ? "That domain currently points at {$found}. Update the CNAME to {$target} — DNS changes can take up to an hour."
                    : "No CNAME record found for {$domain} yet. Add it at your domain provider — DNS changes can take up to an hour.",
                ['domain_status' => 'pending', 'expected' => $target, 'found' => $found],
                422
            );
        }

        $business->update(['brand_domain_status' => 'active']);

        return sendSuccess('Domain verified', ['status' => 'active', 'domain' => $domain]);
    }

    /** Where a provider's custom domain must point. */
    protected function cnameTarget(): string
    {
        return rtrim((string) config('app.domain_cname_target', 'sites.bookboll.com'), '.');
    }

    /** The CNAME a domain currently resolves to, or null when there is none. */
    protected function lookupCname(string $domain): ?string
    {
        if (! function_exists('dns_get_record')) {
            return null;
        }

        try {
            $records = @dns_get_record($domain, DNS_CNAME);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($records ?: [] as $record) {
            if (! empty($record['target'])) {
                return rtrim(strtolower((string) $record['target']), '.');
            }
        }

        return null;
    }

    protected function cnameMatches(?string $found, string $target): bool
    {
        if (! $found) {
            return false;
        }
        $found  = rtrim(strtolower($found), '.');
        $target = rtrim(strtolower($target), '.');

        // An exact match, or a host under the same zone (a CDN may add a prefix).
        return $found === $target || str_ends_with($found, '.'.$target);
    }

    /* ────────────────────────────── Helpers ───────────────────────────────── */

    /**
     * The caller's own business, or an error response. Providers are pinned to their
     * own row; platform admins have no plan of their own to buy.
     *
     * @return array{0: ?Business, 1: ?User, 2: mixed}
     */
    protected function resolveBusiness(Request $request): array
    {
        $user    = $request->user();
        $ownerId = $user ? AccessScope::ownerUserId($user) : null;

        if ($ownerId === null) {
            return [null, null, sendError('Only a provider account has a plan to manage.', [], 403)];
        }

        $business = Business::forOwnerId($ownerId);
        if (! $business) {
            return [null, null, sendError('No business found for this account.', [], 404)];
        }

        $owner = User::find($ownerId);
        if (! $owner) {
            return [null, null, sendError('No business owner found for this account.', [], 404)];
        }

        return [$business, $owner, null];
    }

    /** Lower-cased, scheme- and path-free, or null when it is not a domain at all. */
    protected function normalizeDomain(string $raw): ?string
    {
        $d = strtolower(trim($raw));
        $d = preg_replace('#^[a-z]+://#', '', $d);
        $d = preg_replace('#[/?].*$#', '', (string) $d);
        $d = trim((string) $d, '.');

        if ($d === '' || ! preg_match(self::DOMAIN_RE, $d)) {
            return null;
        }

        return $d;
    }

    /** A reason string when the domain may not be used, or null when it is free. */
    protected function domainTaken(string $domain, int $businessId): ?string
    {
        foreach (self::BLOCKED_DOMAIN_SUFFIXES as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return 'That address belongs to Bookboll — use your own domain, or the free Bookboll web address.';
            }
        }

        $taken = Business::where('id', '!=', $businessId)
            ->where('brand_custom_domain', $domain)
            ->exists();

        return $taken ? 'That domain is already connected to another Bookboll site.' : null;
    }

    /**
     * Validate a provider-plan promo code against the stored row — the same rules the
     * dashboard shows, enforced where they count.
     *
     * @return array{ok:bool, reason?:string, code?:Promocode, discount?:float}
     */
    public function validatePlanPromo(string $rawCode, int $ownerUserId, string $email, float $amount): array
    {
        $code = strtoupper(trim($rawCode));
        if ($code === '') {
            return ['ok' => false, 'reason' => 'Enter a promo code.'];
        }

        $c = Promocode::whereRaw('UPPER(code) = ?', [$code])->first();
        if (! $c) {
            return ['ok' => false, 'reason' => 'Invalid promo code.'];
        }
        if ($c->status !== 'active') {
            return ['ok' => false, 'reason' => 'This code is no longer active.'];
        }

        $today = now()->toDateString();
        if ($c->valid_from && $today < $c->valid_from->toDateString()) {
            return ['ok' => false, 'reason' => 'This code isn’t active yet.'];
        }
        if ($c->expires_at && $today > $c->expires_at->toDateString()) {
            return ['ok' => false, 'reason' => 'This code has expired.'];
        }
        if ($c->usage_limit && (int) $c->used_count >= (int) $c->usage_limit) {
            return ['ok' => false, 'reason' => 'This code has reached its usage limit.'];
        }
        if (($c->applicable_to ?: 'all') !== 'provider_plan') {
            return ['ok' => false, 'reason' => 'This code can’t be used for a plan upgrade.'];
        }

        $owners = $this->idList($c->owner_user_ids, $c->owner_user_id);
        if ($owners && ! in_array($ownerUserId, $owners, true)) {
            return ['ok' => false, 'reason' => 'This code isn’t valid for this provider.'];
        }
        if ($c->min_order_amount && $amount < (float) $c->min_order_amount) {
            return ['ok' => false, 'reason' => 'This code needs a larger plan to apply.'];
        }

        $email    = strtolower(trim($email));
        $audience = $c->audience ?: 'all';
        if ($audience === 'specific') {
            $list = array_map(fn ($e) => strtolower((string) $e), is_array($c->target_emails) ? $c->target_emails : []);
            if (! $email || ! in_array($email, $list, true)) {
                return ['ok' => false, 'reason' => 'This code is reserved for selected customers.'];
            }
        }
        if ($email && ($audience === 'new' || (int) $c->per_customer_limit > 0)) {
            $used = PromocodeRedemption::where('promocode_id', $c->id)
                ->whereRaw('LOWER(customer_email) = ?', [$email])->count();
            if ($audience === 'new' && $used > 0) {
                return ['ok' => false, 'reason' => 'This code is for first-time customers only.'];
            }
            if ((int) $c->per_customer_limit > 0 && $used >= (int) $c->per_customer_limit) {
                return ['ok' => false, 'reason' => 'You’ve already used this code.'];
            }
        }

        $discount = $c->discount_type === 'percentage'
            ? $amount * ((float) $c->discount_value) / 100
            : (float) $c->discount_value;
        $discount = round(max(0, min($discount, $amount)), 2);

        if ($discount <= 0) {
            return ['ok' => false, 'reason' => 'This code gives no discount on this plan.'];
        }

        return ['ok' => true, 'code' => $c, 'discount' => $discount];
    }

    /** Recorded from the webhook, not the browser, so the count reflects real charges. */
    protected function recordPlanRedemption(Business $business, BusinessSubscription $sub): void
    {
        if (! $sub->promocode_id) {
            return;
        }

        $promo = Promocode::find($sub->promocode_id);
        if (! $promo) {
            return;
        }

        $owner = $business->owner;

        PromocodeRedemption::create([
            'promocode_id'    => $promo->id,
            'code'            => $promo->code,
            'customer_email'  => strtolower((string) ($owner->email ?? '')),
            'customer_name'   => $business->name ?: ($owner->name ?? ''),
            'source_type'     => PremiumPlan::SOURCE_PLAN,
            'source_id'       => (string) $business->owner_id,
            'order_amount'    => (float) $sub->amount,
            'discount_amount' => (float) $sub->discount_amount,
            'currency'        => $sub->currency ?: strtoupper(PremiumPlan::CURRENCY),
            'recipient'       => $promo->recipient ?: 'bookboll',
            'owner_user_id'   => $promo->owner_user_id,
            'country_id'      => $business->country_id,
            'redeemed_at'     => now(),
        ]);

        $promo->increment('used_count');
    }

    /** @return int[] */
    protected function idList($many, $single): array
    {
        $ids = is_array($many) ? $many : ($many ? [$many] : []);
        if (! $ids && $single) {
            $ids = [$single];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    protected function retrieveSubscription($subscription): ?object
    {
        if ($subscription instanceof \Stripe\Subscription) {
            return $subscription;
        }
        if (! is_string($subscription) || $subscription === '') {
            return null;
        }

        try {
            return StripeSubscription::retrieve($subscription);
        } catch (\Throwable $e) {
            Log::warning('Could not read provider plan subscription', ['id' => $subscription, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Stripe Basil moved the period end onto the items; read both shapes. */
    protected function periodEnd(object $stripeSub): ?\Illuminate\Support\Carbon
    {
        $end = $stripeSub->current_period_end ?? null;
        if (! $end) {
            $item = $stripeSub->items->data[0] ?? null;
            $end  = $item->current_period_end ?? null;
        }

        return $end ? now()->setTimestamp((int) $end) : null;
    }

    /** A path we control, never a URL from the request — no open redirect. */
    protected function returnPath(?string $path, string $fallback): string
    {
        $p = trim((string) $path);
        if ($p === '' || ! str_starts_with($p, '/') || str_starts_with($p, '//') || str_contains($p, '\\')) {
            return $fallback;
        }

        return $p;
    }

    protected function adminUrl(string $path, array $query = []): string
    {
        $base = rtrim((string) config('app.admin_url'), '/');
        $url  = $base.$path;

        if ($query) {
            // Built by hand: Stripe's {CHECKOUT_SESSION_ID} placeholder must not be
            // URL-encoded or Stripe will not substitute it.
            $parts = [];
            foreach ($query as $k => $v) {
                $parts[] = rawurlencode($k).'='.$v;
            }
            $url .= (str_contains($url, '?') ? '&' : '?').implode('&', $parts);
        }

        return $url;
    }

    protected function stripeReady(): bool
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            return false;
        }
        Stripe::setApiKey($secret);

        return true;
    }
}
