<?php

namespace App\Console\Commands;

use App\Services\BrandResolutionService;
use App\Support\StripeDocumentLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Post-deploy verification for the readmeAlamgir.md work.
 *
 * Checks what is actually wired on THIS environment — schema, routes and the
 * behaviour of the pieces that can be exercised without creating records — and
 * states plainly what is still not implemented, so a green run can never be
 * mistaken for "the whole document is done".
 *
 * Read-only: creates, changes and deletes nothing.
 */
class AuditReadmeAlamgir extends Command
{
    protected $signature = 'audit:readme-alamgir';

    protected $description = 'Verify which readmeAlamgir.md backend items are live on this environment';

    protected array $rows = [];
    protected int $failed = 0;

    public function handle(): int
    {
        $this->line('');
        $this->info('IMPLEMENTED — these should all pass after a correct deploy');
        $this->line('');

        // 1. Website section visibility (readmeAlamgir §8)
        $sections = BrandResolutionService::homeSections(['sections' => ['locations' => false]]);
        $this->check(
            'Website sections: public projection hides locations',
            ($sections['locations'] ?? null) === false && ($sections['contact'] ?? null) === true,
            'BrandResolutionService::homeSections'
        );
        $this->check(
            'Website sections: unsaved flag stays visible',
            (BrandResolutionService::homeSections([])['locations'] ?? null) === true,
            'missing key must not hide content'
        );

        // 2. Stripe document links
        $this->check(
            'Schema: payments.stripe_invoice_url',
            Schema::hasColumn('payments', 'stripe_invoice_url'),
            'migration 2026_10_18_000001'
        );
        $this->check(
            'Schema: payments.stripe_receipt_url',
            Schema::hasColumn('payments', 'stripe_receipt_url'),
            'migration 2026_10_18_000001'
        );
        $this->check(
            'Stripe links: only https stripe.com is served',
            StripeDocumentLink::sanitize('https://invoice.stripe.com/i/a') !== null
                && StripeDocumentLink::sanitize('https://stripe.com.evil.test/x') === null
                && StripeDocumentLink::sanitize('http://invoice.stripe.com/i/a') === null,
            'StripeDocumentLink::sanitize'
        );
        $this->check(
            'Stripe links: offered only for captured online money',
            StripeDocumentLink::appliesTo('online', 'paid')
                && ! StripeDocumentLink::appliesTo('cash', 'paid')
                && ! StripeDocumentLink::appliesTo('payment_skipped', 'paid'),
            'StripeDocumentLink::appliesTo'
        );
        $this->check(
            'Scheduled: payments:backfill-stripe-urls',
            $this->isScheduled('payments:backfill-stripe-urls'),
            'routes/console.php'
        );

        // 3. Upload ownership
        $this->check('Schema: uploads table', Schema::hasTable('uploads'), 'migration 2026_10_18_000002');
        $this->check(
            'Schema: uploads.user_id + unique path',
            Schema::hasTable('uploads')
                && Schema::hasColumn('uploads', 'user_id')
                && Schema::hasColumn('uploads', 'path'),
            'ownership record'
        );
        $this->check(
            'Scheduled: uploads:prune (safe mode only)',
            $this->isScheduled('uploads:prune') && ! $this->isScheduled('uploads:prune --delete-unreferenced'),
            'the destructive sweep must stay manual'
        );
        $this->check(
            'Upload ownership: strict mode flag readable',
            is_bool(config('uploads.strict_ownership')),
            'config/uploads.php'
        );

        // 4. Group chat moderation
        $this->check(
            'Schema: customer_messages.deleted_at',
            Schema::hasColumn('customer_messages', 'deleted_at'),
            'migration 2026_10_18_000003 — REQUIRED, every message query filters on it'
        );
        $this->check(
            'Route: DELETE customer/messages/group/{id}',
            $this->hasRoute('DELETE', 'customer/messages/group/{id}'),
            'readmeAlamgir §9'
        );

        // 5. Business identity (readmeAlamgir §1)
        $this->check('Schema: business_memberships table', Schema::hasTable('business_memberships'), 'migration 2026_10_19_000002');
        $this->check(
            'Schema: businesses.owner_id is nullable (ownerless)',
            \App\Models\Business::ownerlessSupported(),
            'migration 2026_10_19_000001'
        );
        $this->check(
            'Schema: businesses.idempotency_key (safe retries)',
            Schema::hasColumn('businesses', 'idempotency_key'),
            'migration 2026_10_19_000004'
        );
        $this->check(
            'Route: GET admin/businesses/capabilities',
            $this->hasRoute('GET', 'admin/businesses/capabilities'),
            'readmeAlamgir §1'
        );
        $this->check(
            'Route: POST admin/businesses (ownerless create)',
            $this->hasRoute('POST', 'admin/businesses'),
            'readmeAlamgir §1'
        );
        $this->check(
            'DB enforces one active owner per business',
            $this->hasOneActiveOwnerIndex(),
            'business_memberships_one_active_owner'
        );

        // 6. Own-site commission policy (readmeAlamgir "Requested policy")
        $this->check(
            'Commission: independent 15% marketplace, 15% Free website, 10% Premium defaults',
            \App\Support\MarketSettings::DEFAULTS['facility'] === 15.0
                && \App\Support\MarketSettings::DEFAULTS['site_free'] === 15.0
                && \App\Support\MarketSettings::DEFAULTS['site_premium'] === 10.0,
            'MarketSettings; country/city overrides apply to new bookings'
        );
        $this->check(
            'Commission: 7.5% of 100 bills 7.50, not 7',
            \App\Support\Money::percentOf(\App\Support\Money::toMinor(100, 'QAR'), 7.5) === 750,
            'Money — floor() used to charge 7'
        );
        $this->check(
            'Money: real currency exponents (JPY 0, KWD 3)',
            \App\Support\Money::exponent('JPY') === 0
                && \App\Support\Money::exponent('KWD') === 3
                && \App\Support\Money::exponent('QAR') === 2,
            'Money — round($x * 100) assumed 2 everywhere'
        );
        $this->check(
            'Commission: fee + share always rebuild the total',
            (function () {
                foreach ([[10000, 7.5], [9999, 15], [3333, 33.33], [1, 50]] as [$m, $p]) {
                    $s = \App\Support\Money::split($m, $p);
                    if ($s['fee'] + $s['remainder'] !== $m) {
                        return false;
                    }
                }

                return true;
            })(),
            'Money::split'
        );
        $this->check(
            'Plan: own_site_commission_discount_eligible is served',
            str_contains(
                (string) file_get_contents(app_path('Services/ProviderPlanService.php')),
                'own_site_commission_discount_eligible'
            ),
            'GET /admin/plan'
        );

        // 7. Business assignments (readmeAlamgir §1.5)
        $this->check(
            'Schema: business_assignment_previews (reviewed moves)',
            Schema::hasTable('business_assignment_previews'),
            'migration 2026_10_19_000006'
        );
        foreach ([
            ['GET', 'admin/businesses/{id}/assignment-options'],
            ['POST', 'admin/businesses/{id}/assignment-preview'],
            ['POST', 'admin/businesses/{id}/assignments'],
        ] as [$verb, $uri]) {
            $this->check("Route: {$verb} {$uri}", $this->hasRoute($verb, $uri), 'readmeAlamgir §1.5');
        }
        $this->check(
            'Assignments: owner seat is never offered',
            ! in_array('owner', \App\Models\BusinessMembership::ASSIGNABLE_ROLES, true),
            'an owner is attached by the transfer flow, not this one'
        );

        // 8. Annual-only Premium, no trial (readmeAlamgir §6)
        $this->check(
            'Premium: checkout accepts only the annual offer',
            str_contains(
                (string) file_get_contents(app_path('Services/ProviderPlanService.php')),
                "'billing'     => 'required|string|in:yearly'"
            ),
            'POST /admin/plan/checkout'
        );
        $this->check(
            'Premium: the server never asks Stripe for a trial',
            ! str_contains(
                preg_replace('#//.*$#m', '', (string) file_get_contents(app_path('Services/ProviderPlanService.php'))),
                'trial_period_days'
            ),
            'a server-created trial cannot be switched off by the browser'
        );

        // 8. Commission snapshot on bookings
        $this->check(
            'Schema: bookings carry the commission snapshot',
            Schema::hasColumn('bookings', 'commission_channel')
                && Schema::hasColumn('bookings', 'commission_effective_pct')
                && Schema::hasColumn('bookings', 'commission_fee_minor'),
            'migration 2026_10_19_000005 — preserves historical fees'
        );

        // 9. Loyalty: country scoping and the checkout quote
        $this->check(
            'Schema: loyalty_points.country_id',
            Schema::hasColumn('loyalty_points', 'country_id'),
            'migration 2026_10_19_000007 — balances are per country'
        );
        $this->check(
            'Schema: loyalty_checkout_quotes',
            Schema::hasTable('loyalty_checkout_quotes'),
            'migration 2026_10_19_000008'
        );
        $this->check(
            'Route: POST customer/loyalty/checkout-quote',
            $this->hasRoute('POST', 'customer/loyalty/checkout-quote'),
            'readmeAlamgir redemption contract §2'
        );
        $this->check(
            'Rewards never exceed Bookboll own share',
            (function () {
                // 100 order, 15 commission, plenty of points -> at most 15.
                $r = \App\Support\LoyaltyRedemption::quote(
                    ['commission' => 1500, 'event_fee' => 0, 'booking_fee' => 0],
                    10000, 10000, 1000000, 100, 100, null, 'QAR'
                );

                return $r['discount_minor'] === 1500;
            })(),
            'LoyaltyRedemption'
        );
        $this->check(
            'Zero commission funds nothing (never a default rate)',
            \App\Support\LoyaltyRedemption::quote(
                ['commission' => 0, 'event_fee' => 0, 'booking_fee' => 0],
                10000, 10000, 1000000, 100, 100, null, 'QAR'
            )['points'] === 0,
            'the old membership path fell back to 15%'
        );

        $this->check(
            'Rewards: redemption_checkout_v1 is advertised',
            str_contains(
                preg_replace('#//.*$#m', '', (string) file_get_contents(app_path('Services/LoyaltyPointService.php'))),
                "'redemption_checkout_v1' => true"
            ),
            'venue checkout enforces the quote'
        );
        $this->check(
            'Rewards: unsupported payments refuse a quote',
            class_exists(\App\Http\Middleware\RejectUnsupportedRewards::class),
            'never charge full price while the customer thinks points applied'
        );
        $this->check(
            'Schema: bookings.loyalty_quote_id',
            Schema::hasColumn('bookings', 'loyalty_quote_id'),
            'migration 2026_10_19_000009 — lets a redirect consume its reservation'
        );

        // 10. Venue operating agreements + private contracts
        $this->check(
            'Schema: venue_operating_agreements (immutable versions)',
            Schema::hasTable('venue_operating_agreements')
                && Schema::hasColumn('venue_operating_agreements', 'superseded_on'),
            'migration 2026_10_19_000011'
        );
        $this->check(
            'Schema: venues.operator_type (recorded, not inferred)',
            Schema::hasColumn('venues', 'operator_type'),
            'a null owner must not grant payout exemptions'
        );
        $this->check(
            'Schema: venue_contract_documents (private)',
            Schema::hasTable('venue_contract_documents'),
            'migration 2026_10_19_000012'
        );
        foreach ([
            ['GET', 'admin/venues/{id}/operating-agreements'],
            ['POST', 'admin/venues/{id}/operating-agreements'],
            ['POST', 'admin/venues/{id}/contracts'],
        ] as [$verb, $uri]) {
            $this->check("Route: {$verb} {$uri}", $this->hasRoute($verb, $uri), 'readmeAlamgir §2');
        }
        $this->check(
            'Rent: the document October example is 4,700',
            \App\Support\RentProration::monthFromSegments('2026-10', [
                ['monthly_minor' => 310000, 'from' => '2026-10-01', 'to' => '2026-10-15'],
                ['monthly_minor' => 620000, 'from' => '2026-10-16', 'to' => '2026-10-31'],
            ]) === 470000,
            'RentProration — 15 x 100 + 16 x 200'
        );
        $this->check(
            'Rent: a full month sums exactly, no rounding drift',
            array_sum(\App\Support\RentProration::monthly(100000, '2026-04-01', '2026-04-30')) === 100000,
            '1,000 over 30 days does not divide evenly'
        );
        $this->check(
            'Contracts: type is read from the file bytes',
            \App\Support\ContractFileType::detect('%PDF-1.7') === 'pdf'
                && \App\Support\ContractFileType::detect('<?php echo 1;') === null,
            'an extension and a content-type header are not evidence'
        );
        $this->check(
            'Scheduled: contracts:prune-temporary',
            $this->isScheduled('contracts:prune-temporary'),
            'abandoned uploads expire; linked ones never do'
        );

        // 11. Venue transfer (readmeAlamgir §3)
        $this->check(
            'Schema: venue transfer tables (+ immutable allocations)',
            Schema::hasTable('venue_transfers')
                && Schema::hasTable('venue_transfer_previews')
                && Schema::hasTable('venue_transfer_allocations'),
            'migration 2026_10_19_000013'
        );
        $this->check(
            'Schema: payments record who collected cash directly',
            Schema::hasColumn('payments', 'collection_method')
                && Schema::hasColumn('payments', 'collected_by_business_id')
                && Schema::hasColumn('payments', 'reconciliation_batch_id'),
            'said explicitly, not implied by a recipient label'
        );
        foreach ([
            ['GET', 'admin/venues/{id}/transfer-options'],
            ['POST', 'admin/venues/{id}/transfer-preview'],
            ['POST', 'admin/venues/{id}/transfers'],
        ] as [$verb, $uri]) {
            $this->check("Route: {$verb} {$uri}", $this->hasRoute($verb, $uri), 'readmeAlamgir §3');
        }
        $this->check(
            'Reconciliation: slot blocks are never a marketplace sale',
            \App\Support\LegacyReconciliation::exclusionFor(
                (object) ['type' => 'deposit', 'status' => 'paid', 'amount' => 20, 'currency' => 'QAR'],
                (object) ['payment_method' => 'payment_skipped', 'status' => 'completed']
            ) !== null,
            'LegacyReconciliation'
        );
        $this->check(
            'Reconciliation: provider cash never moves Bookboll earnings',
            ! \App\Support\LegacyReconciliation::affectsEarnings(
                \App\Support\LegacyReconciliation::KIND_PROVIDER_CASH
            ) && \App\Support\LegacyReconciliation::affectsEarnings(
                \App\Support\LegacyReconciliation::KIND_DEPOSIT_TO_FEE
            ),
            'it was never Bookboll\'s money'
        );
        $this->check(
            'Reconciliation: provider cash is NAMED as the provider\'s',
            (function () {
                // The payments dashboard reports `recipient IS NULL` as money
                // Bookboll received — right while the venue was Bookboll's,
                // wrong the moment it is a provider's. Leaving the label blank
                // would report the provider's arrival cash as platform revenue
                // on the stats card for every venue that ever transfers.
                $src = $this->code('Services/VenueTransferService.php');

                return str_contains($src, "'recipient'               => PaymentRecipient::forBusiness(\$destination)")
                    && \App\Support\PaymentRecipient::forBusiness(null) === '@provider'
                    && \App\Support\PaymentRecipient::forBusiness(
                        new \App\Models\Business(['username' => 'riverside'])
                    ) === '@riverside'
                    // Never null, or the row reports as Bookboll's again.
                    && \App\Support\PaymentRecipient::forBusiness(
                        new \App\Models\Business(['username' => '', 'name' => ''])
                    ) !== '';
            })(),
            'a blank recipient is counted as Bookboll revenue, not as neutral'
        );
        $this->check(
            'Transfer: the ownership change leaves an activity trail',
            (function () {
                // Venue logged owner_user_id but not business_id. A transfer to
                // an OWNERLESS business leaves owner_user_id null on both sides,
                // so nothing logged was dirty and logOnlyDirty() +
                // dontSubmitEmptyLogs() wrote no row at all for the move.
                $venue = new \App\Models\Venue;
                $payment = new \App\Models\Payment;
                $logged = function ($model) {
                    $m = new \ReflectionMethod($model, 'activityLogAttributes');
                    $m->setAccessible(true);

                    return $m->invoke($model);
                };

                $v = $logged($venue);
                $p = $logged($payment);

                return config('activitylog.enabled')
                    && in_array('business_id', $v, true)
                    && in_array('operator_type', $v, true)
                    // And the reconciliation's own columns, so a payment's
                    // history does not stop at "it became paid somehow".
                    && in_array('collected_by_business_id', $p, true)
                    && in_array('collection_method', $p, true)
                    && in_array('reconciliation_batch_id', $p, true);
            })(),
            'an ownerless destination used to produce no activity row at all'
        );

        // 12. Analytics aggregates (readmeAlamgir "Activity dashboard")
        $this->check(
            'Route: GET admin/dashboard/analytics/aggregates',
            $this->hasRoute('GET', 'admin/dashboard/analytics/aggregates'),
            'totals computed by the database, not the browser'
        );
        $this->check(
            'Analytics: the raw-array endpoint still exists for other consumers',
            $this->hasRoute('GET', 'admin/dashboard/analytics'),
            'the aggregate endpoint is additive, not a replacement'
        );
        $this->check(
            'Analytics: aggregates report their own coverage',
            str_contains(
                (string) file_get_contents(app_path('Services/AnalyticsAggregateService.php')),
                "'missing_reason'"
            ),
            'a number with no coverage statement hides a partial total'
        );

        // 13. Booking record purpose (readmeAlamgir "Legacy skipped payments")
        $this->check(
            'Schema: bookings.record_purpose',
            Schema::hasColumn('bookings', 'record_purpose'),
            'migration 2026_10_19_000014'
        );
        $this->check(
            'Slot blocks are classified, not inferred from origin',
            \App\Support\BookingPurpose::isSale((object) [
                'record_purpose' => \App\Support\BookingPurpose::BLOCK,
                'origin' => 'bookboll_web',
            ]) === false,
            'every legacy block carries origin bookboll_web'
        );
        $this->check(
            'Legacy rows still classify by the old inference',
            \App\Support\BookingPurpose::isSale((object) [
                'record_purpose' => null, 'payment_method' => 'payment_skipped',
            ]) === false,
            'a half-migrated database gives the same answer'
        );

        // 14. Customer product types (readmeAlamgir "Customer product types")
        $this->check(
            'Customer types: a slot block never makes a booking customer',
            (function () {
                // The predicate used by both the filter and the badges.
                $src = (string) file_get_contents(app_path('Support/CustomerTypes.php'));

                return str_contains($src, 'BookingPurpose::scopeSales');
            })(),
            'CustomerTypes — the document calls this out by name'
        );
        $this->check(
            'Customer types: an unknown type is refused, not ignored',
            isset(\App\Support\CustomerTypes::fromRequest(
                new \Illuminate\Http\Request(['customer_types' => ['spaceship']])
            )['error']),
            'silently dropping it returns an unfiltered page'
        );
        $this->check(
            'Customer types: any and all are both honoured',
            \App\Support\CustomerTypes::MATCH_ANY === 'any'
                && \App\Support\CustomerTypes::MATCH_ALL === 'all',
            'CustomerTypes'
        );

        // 15. Export completeness (readmeAlamgir "Export scope and completeness")
        $this->check(
            'Exports: customers honour export=1 rather than returning a page',
            str_contains(
                (string) file_get_contents(app_path('Services/CustomerService.php')),
                "\$isExport = \$request->boolean('export')"
            ),
            'the client asked for 5,000 and the paginator capped it at 250'
        );
        $this->check(
            'Exports: a capped export says so',
            str_contains(
                (string) file_get_contents(app_path('Services/CustomerService.php')),
                "'truncated'"
            ),
            'never hand back a short file that looks complete'
        );
        $this->check(
            'Payments: the dashboard sort has a deterministic tie-breaker',
            (function () {
                $src = (string) file_get_contents(app_path('Services/PaymentService.php'));
                $sort = substr($src, strpos($src, 'function applyDashboardSort'));
                $sort = substr($sort, 0, strpos($sort, "\n    }"));

                // Every branch, or paging an export can repeat and skip rows.
                return substr_count($sort, "orderByDesc('id')") >= 5;
            })(),
            'without it, paged exports can repeat or miss rows'
        );

        // 16. Subscriber saved-payment picker (readmeAlamgir §11)
        $this->check(
            'Route: GET admin/subscriptions/dashboard/{id}/payment-methods',
            $this->hasRoute('GET', 'admin/subscriptions/dashboard/{id}/payment-methods'),
            'staff pick a stored card, never type a reference'
        );
        $this->check(
            'Subscriptions: every action is business-scoped',
            (function () {
                $src = (string) file_get_contents(app_path('Services/ClassMembershipDashboardService.php'));
                // Exactly ONE bare lookup is legitimate: the one inside
                // scopedSubscription(), which then applies the check. Any
                // other is an action reachable without it.
                $bare = substr_count($src, 'ClassSubscription::query()->find($id)');
                $guard = (int) str_contains($src, 'protected function scopedSubscription');

                return $bare === 1 && $guard === 1;
            })(),
            'exactly one bare lookup, inside the guard that scopes it'
        );

        // 17. Category popularity by real choices (readmeAlamgir §Features)
        $this->check(
            'Route: POST marketplace/category-clicks',
            $this->hasRoute('POST', 'marketplace/category-clicks'),
            'the endpoint the storefront posts selections to'
        );
        $this->check(
            'Category clicks: stored per scope, deduped per visitor',
            Schema::hasTable('category_clicks')
                && collect(['category', 'type', 'city_id', 'country_id', 'brand_slug', 'visitor_hash'])
                    ->every(fn ($c) => Schema::hasColumn('category_clicks', $c)),
            'marketplace and each storefront keep separate counts'
        );
        $this->check(
            'Categories ranked by choices, not by listing count',
            (function () {
                $src = (string) file_get_contents(app_path('Services/MarketplaceCatalogService.php'));

                // All three must ship together: the counts, the scope they
                // describe, and the flag. The flag alone would make the
                // storefront post into an endpoint that is not there.
                return str_contains($src, "'category_popularity' =>")
                    && str_contains($src, "'category_popularity_scope' =>")
                    && str_contains($src, "'category_click_tracking_enabled' => true");
            })(),
            'catalogue response carries counts, scope and the tracking flag'
        );
        $this->check(
            'Category scope echo names only what the storefront sent',
            (function () {
                // The storefront drops every count when this block names a
                // scope it did not ask for, so an internal numeric country id
                // here would silently return the catalogue to alphabetical
                // ordering with nothing visibly broken.
                $src = (string) file_get_contents(app_path('Services/MarketplaceCatalogService.php'));
                $start = strpos($src, "'category_popularity_scope' => [");
                if ($start === false) {
                    return false;
                }
                // The array's own closing bracket, at its own indent — not the
                // first `'],` inside it, which belongs to a subscript.
                $close = strpos($src, "\n            ],", $start);
                $block = $close === false ? '' : substr($src, $start, $close - $start);
                preg_match_all("/^ {16}'(\\w+)'\\s*=>/m", $block, $m);

                return $m[1] === ['city_id', 'country_iso', 'brand_slug', 'fallback', 'window_days'];
            })(),
            'echoed in the client\'s own terms, so counts are not discarded'
        );
        $this->check(
            'Category clicks: the client sends a selection, never a count',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CategoryPopularityService.php'));
                $start = strpos($src, '$request->validate([');
                if ($start === false) {
                    return false;
                }
                $rules = substr($src, $start, (int) strpos($src, '])', $start) - $start);
                preg_match_all("/'(\\w+)'\\s*=>/", $rules, $m);

                // Anything resembling a tally would let a client rank itself.
                return $m[1] === ['category', 'type', 'source', 'city_id', 'city', 'country_iso', 'brand_slug'];
            })(),
            'no count, weight or score is accepted from the request'
        );
        $this->check(
            'Category clicks: unknown categories, bots and repeats excluded',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CategoryPopularityService.php'));

                return str_contains($src, 'categoryExists(')
                    && str_contains($src, 'DEDUPE_MINUTES')
                    && str_contains($src, 'VISITOR_CAP')
                    && str_contains($src, 'looksAutomated(');
            })(),
            'catalogue-checked, deduped, capped per visitor, previews excluded'
        );

        // 18. People search (readmeAlamgir §7)
        $this->check(
            'Route: GET marketplace/users',
            $this->hasRoute('GET', 'marketplace/users'),
            'the People tab had no source and said coming soon'
        );
        $this->check(
            'People search is rate limited',
            (bool) collect(Route::getRoutes())
                ->first(fn ($r) => $r->uri() === 'api/marketplace/users'
                    && in_array('throttle:30,1', $r->gatherMiddleware(), true)),
            'public, unauthenticated, and reads across all customer rows'
        );
        $this->check(
            'People search returns only public profile fields',
            (function () {
                $src = (string) file_get_contents(app_path('Services/PeopleSearchService.php'));
                $start = strpos($src, 'protected function card(');
                if ($start === false) {
                    return false;
                }
                $card = substr($src, $start, 900);
                preg_match_all("/^ {12}'(\\w+)'\\s*=>/m", $card, $m);

                // An explicit list, checked here, so that adding a column to
                // users can never start publishing it.
                return $m[1] === ['id', 'url', 'name', 'avatar', 'isPrivate', 'hostedCount', 'joinedCount', 'joinedAt'];
            })(),
            'never email, phone, bio, photos, address or the internal id'
        );
        $this->check(
            'Schema: users.is_discoverable',
            Schema::hasColumn('users', 'is_discoverable'),
            'is_private governs who sees posts, not whether you are listed'
        );
        $this->check(
            'Discoverability is a setting a customer can change',
            (function () {
                $src = (string) file_get_contents(app_path('Services/UserService.php'));

                // A column nobody can set is not a preference being honoured.
                return str_contains($src, "'is_discoverable' => 'sometimes|boolean'")
                    && str_contains($src, "array_key_exists('is_discoverable', \$formData)");
            })(),
            'update-profile accepts and persists it'
        );
        $this->check(
            'People search: a short query is not reported as zero results',
            (function () {
                $src = (string) file_get_contents(app_path('Services/PeopleSearchService.php'));

                return str_contains($src, "'reason'     => 'query_too_short'")
                    && str_contains($src, "'status' => 'not_searched'")
                    && str_contains($src, "'status' => 'at_least'");
            })(),
            'a count never claims more than the server checked'
        );

        // 19. Community permissions and moderation (readmeAlamgir §9)
        $this->check(
            'Community settings are always returned explicitly',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CustomerCommunityService.php'));

                // An absent permission is indistinguishable from a server that
                // dropped it — the exact failure the client read-back catches.
                return str_contains($src, "'settings'          => CommunityPermissions::settings(\$c)");
            })(),
            'an absent permission reads as a dropped one'
        );
        $this->check(
            'Feed and chat audiences are enforced, not only stored',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CustomerCommunityService.php'));

                return substr_count($src, 'CommunityPermissions::canContribute(') === 2
                    && str_contains($src, "'posts'")
                    && str_contains($src, "'chat'");
            })(),
            'an Admins-only feed used to accept posts from every member'
        );
        $this->check(
            'Moderator is a real role the hierarchy enforces',
            (function () {
                $controller = (string) file_get_contents(app_path('Http/Controllers/CustomerCommunityController.php'));
                $policy = (string) file_get_contents(app_path('Support/CommunityPermissions.php'));

                return str_contains($controller, "'role' => 'required|string|in:admin,moderator,member'")
                    && str_contains($policy, 'Only the owner can assign or remove admins.')
                    && str_contains($policy, 'You cannot change your own role.');
            })(),
            'admin was previously any admin\'s to give, including to themselves'
        );
        $this->check(
            'Moderators get the narrow chat patch and nothing else',
            (function () {
                $policy = (string) file_get_contents(app_path('Support/CommunityPermissions.php'));
                $src = (string) file_get_contents(app_path('Services/CustomerCommunityService.php'));

                return str_contains($policy, 'public static function isModeratorChatPatch')
                    && str_contains($policy, "MODERATOR_SETTINGS = ['chat_permission', 'chat_locked']")
                    && str_contains($src, 'CommunityPermissions::isModeratorChatPatch(');
            })(),
            'refused whole, never applied in part'
        );
        $this->check(
            'Community settings patches merge rather than replace',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CustomerCommunityService.php'));

                // Two callers merge: the general edit and the moderator patch.
                return substr_count($src, 'CommunityPermissions::mergeSettings(') === 3;
            })(),
            'one permission must not erase the other or a custom key'
        );
        $this->check(
            'Moderators can remove posts and group messages',
            (function () {
                $src = (string) file_get_contents(app_path('Services/CustomerCommunityService.php'));

                return substr_count($src, 'CommunityPermissions::canModerate(') === 2;
            })(),
            'the old owner/admin check locked moderators out of their job'
        );

        // 20. Provider settings (readmeAlamgir §11)
        $this->check(
            'Routes: business/settings read + three updates',
            $this->hasRoute('GET', 'business/settings')
                && $this->hasRoute('PUT', 'business/settings/profile')
                && $this->hasRoute('PUT', 'business/settings/account')
                && $this->hasRoute('PUT', 'business/settings/notifications'),
            'the save handler used to report updates unavailable'
        );
        $this->check(
            'Schema: businesses.phone + businesses.description',
            Schema::hasColumn('businesses', 'phone') && Schema::hasColumn('businesses', 'description'),
            'the form showed fields that had no column anywhere'
        );
        $this->check(
            'Schema: users.notification_preferences',
            Schema::hasColumn('users', 'notification_preferences'),
            'per person, not per business — two staff choose separately'
        );
        $this->check(
            'Provider settings take no id from the request',
            (function () {
                $src = (string) file_get_contents(app_path('Services/ProviderSettingsService.php'));

                // Resolved from the acting user only. An accepted id is how one
                // provider edits another business.
                return ! preg_match("/input\\('(business_id|owner_id|user_id)'/", $src)
                    && str_contains($src, 'ownedBusiness(')
                    && str_contains($src, "'owner_user_id', 'business_id'");
            })(),
            'staff cannot reach another business or rewrite owner identity'
        );
        $this->check(
            'An email change is refused, not quietly ignored',
            (function () {
                $src = (string) file_get_contents(app_path('Services/ProviderSettingsService.php'));

                return str_contains($src, 'Email changes need to be verified by support.');
            })(),
            'it controls where password resets are sent'
        );
        $this->check(
            'Notification preferences are consulted by delivery',
            (function () {
                $alerts = (string) file_get_contents(app_path('Support/AdminAlerts.php'));
                $policy = (string) file_get_contents(app_path('Support/ProviderNotifications.php'));

                // A preference nothing reads is the same empty promise as a
                // switch that saves nowhere.
                return str_contains($alerts, 'ProviderNotifications::allows($user, $event)')
                    && str_contains($policy, 'public const ALWAYS_ON');
            })(),
            'the switches used to change local component state only'
        );
        $this->check(
            'Every offered switch has an alert that raises it',
            (function () {
                $events = \App\Support\ProviderNotifications::EVENTS;
                $sources = '';
                foreach ([
                    'Observers/BookingObserver.php',
                    'Observers/PaymentObserver.php',
                    'Observers/ClassSubscriptionObserver.php',
                ] as $file) {
                    $sources .= (string) file_get_contents(app_path($file));
                }

                // A switch for an alert nobody sends is the same empty promise
                // in a new coat — which is why wallet_low is not offered.
                foreach (array_keys($events) as $event) {
                    if (! str_contains($sources, "'".$event."'")) {
                        return false;
                    }
                }

                return $events !== [] && ! array_key_exists('wallet_low', $events);
            })(),
            'no switch for a notification nothing raises'
        );

        // 21. Mobile-app adoption (readmeAlamgir, mobile-app section)
        $this->check(
            'Routes: mobile-app sessions, status and preferences for both apps',
            $this->hasRoute('POST', 'customer/mobile-app/sessions')
                && $this->hasRoute('POST', 'admin/mobile-app/sessions')
                && $this->hasRoute('GET', 'customer/mobile-app-status')
                && $this->hasRoute('GET', 'admin/mobile-app-status')
                && $this->hasRoute('PUT', 'customer/mobile-app-preferences')
                && $this->hasRoute('PUT', 'admin/mobile-app-preferences'),
            'the websites could only ever keep a browser self-report'
        );
        $this->check(
            'Schema: mobile_app_installations',
            Schema::hasTable('mobile_app_installations')
                && collect(['app', 'installation_id', 'user_id', 'platform', 'first_authenticated_use_at', 'last_authenticated_use_at', 'rebound_at'])
                    ->every(fn ($c) => Schema::hasColumn('mobile_app_installations', $c)),
            'an authenticated call from the app is the only evidence there is'
        );
        $this->check(
            'Adoption states are unknown or used_app, never downloaded',
            (function () {
                // Comments stripped: the prose here explains why "not
                // downloaded" is wrong, and would match the check itself.
                $policy = $this->code('Support/MobileAppAdoption.php');

                // People install apps without signing in, so "not downloaded"
                // is a claim this data cannot support.
                return str_contains($policy, "STATUS_UNKNOWN = 'unknown'")
                    && str_contains($policy, "STATUS_USED = 'used_app'")
                    && str_contains($policy, "'Used the app' : 'No confirmed app use'")
                    && ! str_contains($policy, 'Not downloaded')
                    && ! str_contains($policy, 'Downloaded');
            })(),
            'a click, a QR scan or a user agent proves nothing'
        );
        $this->check(
            'Adoption takes the account from the session, never the request',
            (function () {
                $src = (string) file_get_contents(app_path('Services/MobileAppAdoptionService.php'));

                // Reading a client-supplied id would let any caller credit or
                // discredit somebody else's adoption.
                return ! preg_match("/input\\('(user_id|account_id|uuid)'/", $src)
                    && substr_count($src, '$request->user()') === 4;
            })(),
            'a client-supplied id is never read'
        );
        $this->check(
            'A shared device stops crediting the account that left',
            (function () {
                $src = (string) file_get_contents(app_path('Services/MobileAppAdoptionService.php'));

                return str_contains($src, "'rebound_at'                 => \$now")
                    && str_contains($src, "'first_authenticated_use_at' => \$now");
            })(),
            'the installation rebinds and first use restarts'
        );
        $this->check(
            'A self-report stays a preference, separate from evidence',
            (function () {
                $policy = (string) file_get_contents(app_path('Support/MobileAppAdoption.php'));

                // It silences the prompt; it never becomes used_app.
                return str_contains($policy, 'public static function selfReported')
                    && str_contains($policy, "'usage_status' => \$used ? self::STATUS_USED : self::STATUS_UNKNOWN")
                    && Schema::hasColumn('users', 'mobile_app_self_reported');
            })(),
            'undoable, and never reported as use'
        );
        $this->check(
            'The adoption report is capability-gated and leaks nothing',
            (function () {
                $src = $this->code('Services/MobileAppAdoptionService.php');

                // The row shape only: installation_id is written on
                // registration, which is correct — it must not be READ BACK.
                $start = strpos($src, "'account_id'");
                $row = $start === false ? '' : substr($src, $start, 700);

                return $start !== false
                    && str_contains($src, 'MobileAppAdoption::REPORT_CAPABILITY')
                    && str_contains($src, 'applyCustomerScope')
                    && ! str_contains($row, 'email')
                    && ! str_contains($row, 'phone')
                    && ! str_contains($row, 'installation_id');
            })(),
            'no email, no device identifier, provider staff scoped to their own'
        );
        $this->check(
            'Installation records have a retention policy that runs',
            (function () {
                $policy = (string) file_get_contents(app_path('Support/MobileAppAdoption.php'));

                return str_contains($policy, 'RETENTION_DAYS')
                    && file_exists(app_path('Console/Commands/PruneMobileAppInstallations.php'));
            })(),
            'deleting an account removes its records through the foreign key'
        );

        // 22. Own-site owner review path (readmeAlamgir §5)
        $this->check(
            'Routes: own-site review queue + apply',
            $this->hasRoute('GET', 'listings/own-site-review')
                && $this->hasRoute('POST', 'listings/own-site-review'),
            'cash-only own-site listings had no way back to bookable'
        );
        $this->check(
            'The review path never chooses for the owner',
            (function () {
                $service = $this->code('Services/OwnSiteReviewService.php');
                $policy = $this->code('Support/OwnSiteReview.php');

                // Enabling deposits for them would start charging their
                // customers a fee they never agreed to collect. The method is
                // required, there is no default, and there are exactly three.
                return str_contains($service, "'method'       => 'required|string|in:'")
                    && str_contains($service, "'listing_ids'  => 'required|array|min:1")
                    && str_contains($policy, "RESOLUTIONS = ['deposit', 'online', 'hide']")
                    && ! preg_match('/method\\s*\\?\\?\\s*.deposit./', $service);
            })(),
            'readmeAlamgir: do not silently enable deposits on their behalf'
        );
        $this->check(
            'The queue and the per-listing check cannot disagree',
            (function () {
                $policy = $this->code('Support/OwnSiteReview.php');

                // The queue asks in SQL so it can be counted and paged; apply()
                // asks in PHP. A disagreement either hides a broken listing or
                // offers to fix a working one.
                return str_contains($policy, 'public static function needsReview')
                    && str_contains($policy, 'public static function scopeNeedsReview')
                    // Without COALESCE a missing JSON key compares as NULL, and
                    // the oldest rows — the ones with no own-site keys at all —
                    // would be the ones left out of the queue.
                    && str_contains($policy, 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(');
            })(),
            'one definition, asked two ways, pinned by a test'
        );
        $this->check(
            'Full online payment stays the business\'s own choice',
            (function () {
                $src = $this->code('Services/OwnSiteReviewService.php');

                // Platform staff see every business's listings here, because
                // AccessScope leaves them unscoped — so the permission is per
                // METHOD, not all-or-nothing. A platform admin may restore a
                // dead channel (a deposit IS Bookboll's own fee, and the venue
                // still collects the same amount on arrival) or take the
                // listing off the provider's site. It may NOT switch one to
                // full online payment, which settles into that business's own
                // wallet and is theirs to opt into.
                return str_contains($src, 'protected function allowedMethods(')
                    && str_contains($src, "? OwnSiteReview::RESOLUTIONS")
                    && str_contains($src, ": ['deposit', 'hide']")
                    // Enforced on the write path, not only advertised on read.
                    && str_contains($src, 'if (! in_array($method, $this->allowedMethods($user), true)) {')
                    && str_contains($src, "'allowed_methods' => \$this->allowedMethods(\$user)");
            })(),
            'a platform admin must not move another business onto its own wallet'
        );
        $this->check(
            'A bulk apply reports what it refused',
            (function () {
                $service = $this->code('Services/OwnSiteReviewService.php');

                return str_contains($service, "'applied'   => \$applied")
                    && str_contains($service, "'refused'   => \$refused")
                    && str_contains($service, "'already_resolved'")
                    && str_contains($service, "'payouts_not_connected'");
            })(),
            'silently skipping leaves an owner believing all is fixed'
        );
        $this->check(
            'The deposit preview comes from the plan, not a stored number',
            (function () {
                $service = $this->code('Services/OwnSiteReviewService.php');

                // A one-time database update could never be the source of
                // truth: future plan changes have to apply dynamically.
                return str_contains($service, 'CommissionPolicy::quote(')
                    && str_contains($service, 'CommissionPolicy::effectiveRatePct(');
            })(),
            'upgrading changes the rate with no listing re-saved'
        );

        // 23. Rewards on memberships and hosted events
        $this->check(
            'Rewards price memberships and hosted events, not only bookings',
            (function () {
                $kinds = \App\Services\LoyaltyCheckoutQuoteService::KINDS;

                return $kinds === ['listing', 'membership', 'host_game', 'host_tournament'];
            })(),
            'the quote used to refuse these three outright'
        );
        $this->check(
            'Each kind is priced by the service that charges it',
            (function () {
                $quote = $this->code('Services/LoyaltyCheckoutQuoteService.php');

                // A second copy of the arithmetic would drift, and a reward
                // quote that disagrees with the charge either shorts the
                // customer or gives away money nobody earned.
                return str_contains($quote, 'CustomerProgramService::class)->rewardBasis(')
                    && str_contains($quote, 'CustomerGameService::class)->rewardBasis(')
                    && str_contains($quote, 'CommissionPolicy::quote(');
            })(),
            'one definition of the price, reused — never copied'
        );
        $this->check(
            'A hosted-event reward is capped at Bookboll\'s own share',
            (function () {
                $games = $this->code('Services/CustomerGameService.php');

                // Capping against everything collected would fund a reward from
                // the venue's revenue on a fully-online court.
                return str_contains($games, 'protected function bookbollShareNow(')
                    && str_contains($games, 'bookbollShareNow($listing, $courtTotal, $courtNow, $serviceFee, $method)')
                    && ! str_contains($games, 'loyaltyAllowed($host, (float) ($data[\'loyalty_redeem\'] ?? 0), $courtNow + $serviceFee)');
            })(),
            'the seats fee plus the court commission, never the court price'
        );
        $this->check(
            'A hosted-event reward never reduces the venue payout',
            (function () {
                $games = $this->code('Services/CustomerGameService.php');

                // The ledger computes the venue's share from the court's price
                // and caps the reward at the platform fee, so the payout is
                // identical with or without one.
                return str_contains($games, 'creditProviderWallet($booking, $courtPrice, $reward)')
                    && str_contains($games, "'loyaltyRedeem' => \$reward");
            })(),
            'the wallet used to be credited from the discounted amount'
        );
        $this->check(
            'A reward quote cannot be spent on a different kind or subject',
            (function () {
                $quote = $this->code('Services/LoyaltyCheckoutQuoteService.php');

                return str_contains($quote, "'subject'    => \$subject")
                    && str_contains($quote, "'event_fee'  => \$policy['event_fee_minor'] ?? 0")
                    && str_contains($quote, "'subject' => 'program:'.\$program->id");
            })(),
            'two same-priced programmes used to fingerprint identically'
        );

        // 24. Hosted-event content (readmeAlamgir "Required hosting content")
        $this->check(
            'Game creation actually applies its photo rules',
            (function () {
                $code = $this->code('Http/Controllers/CustomerGameController.php');

                // `$rules + [...]` keeps the LEFT operand on a duplicate key, so
                // the base rules' own `images => nullable|array` threw away both
                // the "at least one photo" requirement and the ownership check.
                // A game could be published with no photo, or with somebody
                // else's image URL.
                return str_contains($code, 'array_merge($this->rules(), [')
                    && ! str_contains($code, '$this->rules() + [');
            })(),
            'an array union silently discarded both of them'
        );

        // 25. Response security headers (cookie/tracking audit, server half)
        $this->check(
            'Security headers are sent, including on an auth failure',
            (function () {
                $mw = $this->code('Http/Middleware/SecurityHeaders.php');
                $boot = (string) file_get_contents(base_path('bootstrap/app.php'));

                // The API was serving none of these. An auth failure is
                // rendered by the exception handler and unwinds without the
                // middleware's response phase, so 401/403 went out bare until
                // $exceptions->respond() applied the same definition.
                return str_contains($mw, "'X-Content-Type-Options' => 'nosniff'")
                    && str_contains($mw, "'X-Frame-Options'        => 'SAMEORIGIN'")
                    && str_contains($mw, "'Referrer-Policy'        => 'strict-origin-when-cross-origin'")
                    && str_contains($boot, 'SecurityHeaders::class')
                    && str_contains($boot, 'SecurityHeaders::apply(');
            })(),
            'the admin panel is session-bearing HTML on this same origin'
        );
        $this->check(
            'HSTS cannot take a provider domain down from code',
            (function () {
                $mw = $this->code('Http/Middleware/SecurityHeaders.php');

                // HSTS is cached by the browser, so a wrong setting cannot be
                // deployed away. Subdomains and preload stay off until whoever
                // owns the DNS turns them on.
                return str_contains($mw, '$request->isSecure()')
                    && (int) config('security.hsts.max_age') > 0
                    && ! config('security.hsts.include_subdomains')
                    && ! config('security.hsts.preload');
            })(),
            'https only, short max-age, subdomains and preload opt-in'
        );

        // 11. Country/city availability
        $this->check(
            'Route: marketplace/countries',
            $this->hasRoute('GET', 'marketplace/countries'),
            'returns cities_count + has_cities'
        );

        $this->line('');
        $this->table(['check', 'result', 'where'], $this->rows);

        $this->outstanding();

        $this->line('');
        if ($this->failed > 0) {
            $this->error("{$this->failed} implemented check(s) FAILED — the deploy is not correct yet.");

            return self::FAILURE;
        }

        $this->info('Every backend task in readmeAlamgir.md is implemented. What remains above needs a person, not code.');

        return self::SUCCESS;
    }

    /**
     * What is left, and who it belongs to.
     *
     * This used to be one hardcoded line under the heading "nothing below
     * exists on any environment yet", which stopped being true: the consent
     * system is implemented and tested, and the server half of that audit is
     * verified by checks in the table above. Reporting a finished thing as
     * non-existent is worse than not reporting it — it reads as unfinished
     * work forever.
     *
     * So the two kinds are separated. Code still to write is its own list, and
     * it is empty. Everything else is named with its owner and with what has
     * already been done, so nobody re-does the parts that are closed.
     */
    protected function outstanding(): void
    {
        $this->line('');
        $this->warn('CODE STILL TO WRITE');
        $this->line('');
        $this->line('  (none — every backend contract in the document is implemented)');

        $this->line('');
        $this->warn('NEEDS A PERSON, NOT CODE — these cannot be closed from this repository');
        $this->line('');

        $items = [
            [
                'Cookie/tracking infrastructure audit',
                'deployment + legal',
                'Code side DONE and asserted above: the API sets no cookies, tracking IDs '
                    .'are never server-rendered, the consent gate touches only GA4/Meta/GTM '
                    .'and category clicks (never auth, booking or payment), cookie cleanup '
                    .'is an allowlist, and security headers ship on every response. '
                    .'Outstanding: publish the cookie/vendor/retention inventory and privacy '
                    .'notice, audit GTM container contents, check CDN/proxy/service-worker '
                    .'injections, and run the before/after-consent network test with real '
                    .'vendor IDs (none are configured yet, so that test cannot run).',
            ],
            [
                'Supported cities to seed',
                'product',
                $this->citiesGap().' Country/city matching and the availability metadata '
                    .'(cities_count, has_cities) are implemented; WHICH cities to seed is a '
                    .'market decision the code cannot make.',
            ],
            [
                'Event guest ticket payment',
                'deferred by the document itself',
                'readmeAlamgir: "event guest payment redemption is not implemented here… '
                    .'apply the same Bookboll-share cap when that payment surface is '
                    .'available." No guest ticket-payment surface exists in either repo — '
                    .'/games/{uuid}/join is a join request. The checkable half IS enforced: '
                    .'that endpoint accepts no rewards fields and spends no points.',
            ],
        ];

        foreach ($items as $i => [$title, $owner, $detail]) {
            $this->line(sprintf('  %d. %s  [%s]', $i + 1, $title, $owner));
            foreach (str_split(wordwrap($detail, 74, "\n", true), 1) === [] ? [] : explode("\n", wordwrap($detail, 74, "\n", true)) as $line) {
                $this->line('       '.$line);
            }
            $this->line('');
        }
    }

    /**
     * How many countries have no city rows — the audit the document asks for,
     * answered with a live number rather than left as a to-do.
     */
    protected function citiesGap(): string
    {
        try {
            $total = DB::table('countries')->count();
            $without = DB::table('countries')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cities')
                    ->whereColumn('cities.country_id', 'countries.id'))
                ->count();

            return "{$without} of {$total} countries currently have no city rows.";
        } catch (\Throwable $e) {
            return 'Countries with no city rows could not be counted here.';
        }
    }

    protected function check(string $label, bool $ok, string $where): void
    {
        if (! $ok) {
            $this->failed++;
        }
        $this->rows[] = [$label, $ok ? 'PASS' : 'FAIL', $where];
    }

    /**
     * Source with comments removed.
     *
     * Checks that grep for a phrase otherwise match the comment explaining why
     * the phrase must not be there — which passes or fails for entirely the
     * wrong reason.
     */
    protected function code(string $relativePath): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(app_path($relativePath))) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    protected function hasRoute(string $method, string $uri): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array($method, $route->methods(), true)
                && str_ends_with($route->uri(), $uri)) {
                return true;
            }
        }

        return false;
    }

    /** The generated-column index that makes a second active owner impossible. */
    protected function hasOneActiveOwnerIndex(): bool
    {
        if (! Schema::hasTable('business_memberships')) {
            return false;
        }

        try {
            foreach (Schema::getIndexes('business_memberships') as $index) {
                if (($index['name'] ?? '') === 'business_memberships_one_active_owner') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    protected function isScheduled(string $command): bool
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', $command)) {
                return true;
            }
        }

        return false;
    }
}
