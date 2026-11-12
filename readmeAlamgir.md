# Current membership model — 12 November 2026

**Authoritative change:** Memberships are now academy contact listings. [Full implementation, API, billing and rollout guide](docs/memberships/CONTACT_LISTINGS.md).

- Marketplace: **USD 20/month per listing**, configurable globally and by country/city, charged separately on every business plan.
- Provider website: membership listings require an active paid **Premium** plan. Premium does not include marketplace subscriptions.
- Customers contact the academy by phone and pay it directly. Customer enrolment, subscribers, check-in and membership-wallet operations are retired; history stays intact.
- Admin/provider listing management, listing invoices, geography rate editor, marketplace detail, branded-site eligibility, navigation and business pricing copy use this model.
- Deploy all three frontends with the MySQL migration and backend. Marketplace visibility requires a verified paid period; no grace entitlement is invented. Real listing payments stay disabled in the Railway test workspace.
- **Mandatory P23:** CI and runtime release checks now require 23 rules, Stripe test-mode listing billing evidence and verified retirement of existing customer Stripe renewals. Never simply copy a database and enable live billing. Existing processor subscriptions must be ended/verified separately; no real customer charge or cancellation was performed here.

Earlier membership commission, wallet, trial and operational instructions below are historical and superseded by this section. Historical booking/payment rules remain in effect.

## Country supervision and editing flow — 12 November 2026

- Global overview separates marketplace fees, website fees, creation services and rewards, each with a direct edit entry. Country rows compare venue/business counts, booking rates, Free/Premium website rates and explicit override counts. Automatic website values are illustrated with the market booking rate and labelled; the actual listing override still applies.
- Default directory view is **Onboarded markets** (countries with a venue OR registered/operating business). All countries remains available. `GET /admin/geography/countries?view=onboarded|all|overrides` filters before pagination in MySQL. The overrides view covers country fee/service overrides, including 0% and disabled values; it does not imply a rewards or city audit. Existing country jurisdiction restrictions apply to every filter.
- Country management separates Fees & services, Cities, and Rewards. City filters expose fee/service overrides and disabled cities. Availability is separate from service creation permissions. Bulk availability is in a dedicated menu with its existing explicit scope confirmation.
- Fee/service editing opens the selected section. Changes across sections remain in one draft; before/after review lists all edits. Reset affects the current section only. Save is disabled when unchanged. Close/Escape/backdrop protect dirty edits. Failed city loads show retry rather than an empty-success view. Rewards split earning/redemption from customer tiers and protect unsaved edits.
- Required checks: `GeographySettingsTest` covers server-side directory filters and inheritance; `marketSettings.test.js` covers zero/false, section resets, formula previews, changed-source detection and validation across sections. Existing fee/checkout tests remain mandatory. This redesign does not modify saved rates or historical payments.

## Provider website fees — 12 November 2026

Implemented and enforced in MySQL/MariaDB. **Countries & cities → Global defaults / Edit country settings / City Settings → Provider website fees** controls the Free and paid Premium rates for **own-website venue bookings**. Membership commissions and event-seat fees retain their separate settings.

- `MarketSettings::SITE_KEYS`: `site_free`, `site_premium`, numeric percentages from 0 through 100. Stored in the existing `market_settings.values` JSON; no new schema migration or historical recalculation. Resolution: city override → country override → global value. Country/city `null` removes the local override. Zero is a real fee, never treated as missing.
- Global `null` means **automatic**, preserving existing behavior until deliberately changed: Free follows the applicable marketplace/listing commission; paid Premium uses half that commission. A custom website percentage replaces this automatic calculation, including when the listing has an explicit marketplace rate. Global changes leave explicit country/city overrides intact. Marketplace fees do not change with the business plan.
- GET/PUT `/api/admin/geography/settings/{scope}/{id}` returns and accepts both fields alongside the six original settings. An older editor omitting these fields preserves their existing values. Global updates require superadmin; scoped country/city authorization remains enforced. Changes are transactional and audited. Keep geography, checkout and admin deployments together.
- `CommissionPolicy::effectiveRatePct()` is authoritative. `quote()` uses it for both deposit and full online payments; `PaymentService`, storefront/catalog quotes and settlement snapshots share the result. The deposit is the platform fee included in the booking price; full online payment splits that same fee from the total. Do not charge the fee twice. Payout connection does not change the rate. Staff-created bookings keep their existing no-platform-fee rules.
- Paid Premium requires an active paid subscription, outside a trial and before expiry. Free, trial, past-due, canceled and expired subscriptions use the Free rate. Resolve an explicit venue `business_id` first, then legacy owner fallback, so the actual business's plan is used.
- **Historical records stay frozen:** a settings edit changes new quotes only. Existing bookings/payments settle using their recorded effective rate, fee and merchant share. Never use the current market rate to re-split historical records. Existing historical reconciliation rules and the live-payment release gate remain in force.
- Admin reference data exposes resolved `website_fees` on countries/cities. Listing examples and plan messaging must use those values, preserving null as automatic and zero as explicit; do not hardcode a universal 50% saving. The listing preview shows the actual Premium rate before upgrade.
- Required CI checks now include `GeographySettingsTest`, `OwnSiteCommissionPolicyTest`, `DepositFollowsSurfaceAndCommissionTest`, `CommissionSnapshotAndLedgerTest` and admin `listingPayments.test.js`: inheritance/reset, explicit zero, invalid bounds, old-client compatibility, plan eligibility, explicit business ownership, real catalog/deposit consistency, full-payment splits, unchanged marketplace fee and immutable recorded settlement.

## Countries, cities and rewards — 12 November 2026

Implemented in MySQL/MariaDB, with `GeographySettingsTest.php` in the required GitHub workflow. Deploy backend and admin together. Run migrations `2026_11_12_000001_create_market_settings` and `2026_11_12_000002_snapshot_booking_rewards` before accepting traffic (Railway startup runs migrations).

- **Inheritance:** `MarketSettings` is the common resolver: global → country → city. Explicit listing commissions and existing membership agreements remain higher-priority agreements. Global defaults require platform superadmin. Country admins can only edit their jurisdiction. Local `null` means inherit; **0% and false are real overrides**. GET/PUT `/api/admin/geography/settings/{global|country|city}/{id}` (global ID 0) accepts `values` containing all six fields: `facility`, `program`, `event`, `module_listings`, `module_classes`, `module_matchmaking`. All rates validate 0–100. Writes are transactional and audited.
- Existing country fees and city switches are preserved as explicit overrides, including disabled services. They are labelled as custom in the editor. Use **Use parent defaults for all** to intentionally reset a market; changing global settings does not silently replace its overrides. New cities inherit automatically. Module switches govern creation of new listings/programs/events, not removal of existing published records. Country/city availability is a separate control.
- **Real consumers:** customer booking and event court quotes, event seat rates, new membership program defaults and admin reference data use the resolver. Removed the hidden 20% membership floor and falsey-zero fallbacks. Provider users cannot lower their own program fee. Paid own-website plan discounts continue through `CommissionPolicy`. Saved booking/payment fee snapshots are never rewritten by a settings change.
- **Country directory:** counts are computed and sorted before pagination. Default sort is venue count descending, then business count descending, then name. Venues include registered active/inactive/draft records, with city fallback for missing country. Businesses count distinct registered businesses or businesses with venues in that country (explicit business link, legacy owner fallback). A business operating in multiple countries appears once in each applicable country. These are onboarded records, not marketplace-only counts.
- **Rewards choice confirmed by user:** replace fixed listing points with country/global earn rate. New eligible marketplace venue bookings snapshot `bookings.loyalty_policy`: country, rate, tier multiplier, expiry months, eligibility and net-booking-value basis. Award `floor(net total_price × earn_rate × multiplier)` on confirmation. Net total excludes discounts, including redeemed points. Staff/manual bookings, provider-site bookings, skipped-payment blocks, membership-session placeholders and hosted-event court reservations do not earn this venue-booking reward. Event activity bonuses are separate existing behavior.
- Rewards policies: `loyalty_programs.country_id = null` is the global policy. A country with no row or `inherits_global = true` inherits; an explicit disabled country policy stays disabled. Global tiers have `program_id = null`; countries with their own policy use their own active tier ladder. Tier selection uses that country's lifetime earned points. Retired tiers remain for historical references. Changing policy affects new bookings only; historical booking promises and point balances are preserved.
- **Expiry now enforced:** newly awarded booking rewards carry `loyalty_points.expires_at` from the booking's policy snapshot, measured from credit time. Zero months = no expiry. Historical credits without an expiry remain non-expiring. Country balance spends earliest-expiring lots first, expires only their unspent portion, and retains lifetime earnings for tiers. Checkout quotes and redemption enforcement use this same spendable balance. Customer ledger includes `expires_at`. Existing event/manual/import credits retain their existing non-expiring behavior.
- Acceptance: global/country/city save and inherit; 0%/false/reset; rate bounds; cross-country authorization; list counts/order; explicit listing rates; event creation guard; low membership rates; country rewards disabled/reset; inherited earning; tier multiplier; immutable reward promise; earliest-expiry balance without double-subtracting prior spending. Related deposit, fee-snapshot, country-ledger, redemption and geography soft-delete tests must continue to pass. No real Stripe charge is needed for these checks.

# Bookboll payments — current developer handoff

**Customer deposit confirmation (12 November):** A successfully paid deposit confirms the reservation immediately; the arrival balance stays pending. `initialBookingStatus()` and `autoConfirmBookingIfPaid()` enforce this for new payments. Customer booking responses expose `customer_status` and `status_label` for historical pending rows with paid deposit evidence; web/mobile clients must use this customer-facing status instead of the old raw `status`. Cancelled/completed bookings are never revived, and refunded/pending deposit rows do not count as successful deposits. My Bookings and checkout return use the same mapper and show the saved `due_amount` as due on arrival. No fee calculations or historical payment writes are involved, and no database migration is required (existing MySQL/MariaDB). Mandatory CI tests: `StripeMultiBookingSettlementTest.php` (settlement, remaining balance, replay, historical API projection, terminal/refund guards) and web `customerBookingPresentation.test.js` (customer labels and balances). Deploy backend and marketplace together; provider websites use the same marketplace bundle.

**Booking activity and records (P22):** [Required implementation and acceptance](docs/payments/BOOKING_ACTIVITY.md). Bookings and Analytics → **Bookings & activity** now share one complete, server-filtered query, with last month/custom dates, reservation date vs date booked, business/venue/listing filters, immutable staff/customer channels, full exports and repeat-booking metrics. Slot blocks, cancellations and refunds are distinguished from booking value; walk-ins do not inflate retention. Receipt amounts reuse the payment ledger rules. Provider business and listing access are enforced on reports, exports and CRM. Deploy both backend and admin frontend; no historical writes are needed. CI and runtime release evidence now require **22 rules**, including P22. Earlier counts of 21 below are superseded by this addition.

**Payment analytics (P21):** [Required implementation and acceptance](docs/payments/PAYMENT_ANALYTICS.md). Analytics → **Payments & revenue** now uses the same full authorized payment ledger, classification, filters and totals as Payments. External businesses received includes paid staff-added bookings; all external sales, staff collections and customer sales are separate labelled measures. Every chart/table must reconcile per currency. Runtime/CI checks now require **21 rules**, including P21, and current signed policy evidence. This is a read-only report change; do not rewrite historical payments to match a screenshot.

**Superadmin selected-payment actions (P20):** [Implementation and mandatory acceptance](docs/payments/BULK_PAYMENT_ACTIONS.md). Only platform superadmins can select payments, including **all filtered transactions across every page**, then preview offline collections or allocation corrections within the existing business. **Mark as paid must require Cash, Card terminal, Cheque or Bank transfer**, with nothing preselected. The chosen method must match the confirmed preview and be saved in the audit. Large selections preview all batches, save with progress, and stop on errors; completed batches remain saved and retries are idempotent. Apply the bulk audit migration. Keep audit history, enforce server authorization, conserve original receipts and require current evidence before saving. Stripe payments cannot be manually marked paid; staff bookings never gain fees. Policy/CI/runtime release checks require all **21 rules**, including the updated P20 and P21 and current policy checksum. No existing records are automatically changed by this release.

**Total sales and payment filters (11 November):** The first platform KPI is **Total sales · GMV** (`summary[].total_sales`): paid + pending reconciled payment allocations across every recipient, including external businesses and staff bookings. It includes fees plus venue/provider shares once, never the full receipt again. It excludes cancelled/refunded/skipped/unresolved records; the review notice discloses excluded records. It is not Bookboll revenue or profit. Existing received/fee/venue KPIs are unchanged. P13 and P15 now require these checks; changing the policy checksum invalidates older release evidence.

**Payment review actions:** [Required P19 workflow and acceptance](docs/payments/PAYMENT_REVIEWS.md). Platform admins can preview, confirm, annotate and reopen eligible review records. Apply the review-audit migration; preserve original receipts and enforce P19 in signed release evidence.

Updated 11 November 2026. This is the current entry point for the Payments rebuild and historical reconciliation. The previous README is preserved in the [historical implementation log](docs/payments/implementation-history-through-2026-11-11.md); its earlier rules, totals and preview restrictions may be superseded by later corrections. Do not use that log as a production deployment script.

## Before moving to the actual live database

**Migrate the approved rules onto the live records. Do not replace the live database with the corrected Railway snapshot.** Production has its own customers, new bookings, payments, credentials and processor events.

1. Follow the [production rollout and acceptance guide](docs/payments/PRODUCTION_ROLLOUT.md).
2. Use the versioned [rule inventory and exact historical exceptions](docs/payments/rules-v1.json) to check that every approved rule has an implementation and acceptance evidence.
3. Build and rehearse the production plan/apply/verify/reverse runner on a freshly restored copy of the live database. Compare row-level changes and per-currency totals before applying to production.
4. Apply only the reviewed plan, record every change, then verify totals, permissions, replay safety and new booking paths before reopening writes.

**Enforced release gate:** [Payment release checks](docs/payments/ENFORCED_RELEASE_CHECKS.md) now block production writes and processor mutations until externally signed evidence matches the code, policy, schema and database. Platform admins see the checks on Payments; there is no dismiss/approve button. Refund prerequisites are enforced separately. `php artisan payments:release-check` exits nonzero when blocked, and Railway startup runs the guarded workspace check.

**Still incomplete:** a portable production plan/apply/verify/reverse runner and fresh-live-copy rehearsal. These are explicit blockers, not completed checkboxes. The JSON inventory now supplies the gate's policy identity and required test coverage; it does not perform historical migration. Workspace correction commands remain restricted to the workspace database. Removing those guards is not a production implementation.

## Approved rules to preserve

**Checkout payments:** [implementation and mandatory acceptance](docs/payments/SHARED_ONLINE_PAYMENTS.md). A checkout with two slots now shows **one deposit payment and one remaining payment**: QAR 45 online (25 + 20 allocations), QAR 180 remaining (100 + 80 allocations). Use one checkout reference and one reference per phase; keep old per-slot IDs in the audit breakdown. Apply the new checkout-reference/collection-audit migration. New checkouts save a common reference; old ones require verified processor evidence. Remaining collection is one reviewed, atomic and replay-safe operation by the receiving business. Totals and CSV count allocations once. Enforced rules **P17_SHARED_ONLINE_PAYMENTS** and **P18_CHECKOUT_BALANCES** are mandatory; previous 18-rule release evidence cannot unlock this build.

Rates below describe historical policy, not a replacement for each booking's saved quote. Currency amounts must be calculated in integer minor units; never sum different currencies.

| Rule | Required treatment |
|---|---|
| Who created the booking | Use the recorded creator, creation history and entry point. The customer receiving a booking is not necessarily its creator. A later collector, current staff role or legacy `bookboll_web` label alone does not prove origin. Preserve uncertain origin for review. |
| External provider, customer deposit + arrival | The recorded online deposit is Bookboll's platform fee. The arrival balance belongs entirely to the provider. Provider cash/pending balances are neither Bookboll receipts nor a debt owed by Bookboll. Keep marketplace and provider-website channels distinct. |
| External provider, full online | Split the gross receipt into the actual saved Bookboll fee and provider share. Never count the gross again alongside those allocations. Allocation to a provider is not proof of a completed Stripe bank payout. |
| Own venue, customer booking | Separate the platform's internal fee from the venue's remaining revenue for online, deposit and full-arrival payment options. Deposit is platform fee and its arrival balance is venue revenue; full payment uses its saved quote. This is an internal split, not an external payout. |
| Own venue, missing historical customer split | Apply the owner-approved **20% platform / 80% venue** fallback only to the mapped historical customer cohort without a saved fee/split. Preserve existing quotes and deposit arrangements. Never use this as the rate for all new bookings. |
| Staff-entered bookings | **Zero platform fee.** An own-venue booking belongs entirely to Bookboll's venue business; an external-venue booking belongs entirely to that provider. Use the facility operator, not the staff member's employer, to decide the recipient. |
| Skipped payment | Availability block only. Do not infer receipts, earnings or pending money from its booking price. Existing posted money on a skipped booking is an exception to investigate, not silently delete. |
| Events and memberships | Event host/seat fees use their separately captured country-specific amount. Membership fees/shares use the saved business, channel and quote. Do not count an event fee as court revenue or each membership session as a new subscription payment. |
| Historical venue reassignment | Preserve existing external businesses. Keep both Bright Future venues and Al Sadd Bookboll-operated. Creating an external business without an owner account is valid. Snapshot IDs in the inventory must be matched to real production identities. |
| Historical arrival reconciliation | Only explicitly reviewed eligible customer arrival balances may be marked provider-direct cash. **Unpaid staff-added bookings stay pending.** Never mark pending online, cancelled, refunded, failed, disputed or skipped records as collected. |
| Landlord costs | Record fixed rent/revenue-share agreements and renewals with effective dates. Costs are separate from receipts and deducted once. External providers' arrival money is not a landlord obligation. |
| Unknowns | Unclassified receipts stay out of earnings. Confirmed own receipts with an unknown internal split count once in Bookboll received and remain separately disclosed as historical fee splits awaiting review. No guessed ownership or hidden balancing entries. |

**Bookboll received = paid platform allocations + paid own-venue allocations AFTER platform fees + confirmed own receipts still awaiting a split.** These buckets are disjoint. An own-venue receipt of 100 split into 20 platform + 80 venue contributes 100, never 120. A staff-entered own receipt contributes 0 + 100. An external staff-entered receipt contributes 0 to Bookboll.

These are receipt totals before landlord and other expenses, not accounting profit. Online versus on-site describes the collection method; it does not replace recipient or creator evidence. Payment status controls received versus pending. Full refunds remove both allocations from received totals.

## Implementation map

Paths below are relative to this repository. The rule inventory provides test traceability and the exact three retained-deposit corrections.

| Responsibility | Implementation |
|---|---|
| Creator evidence and staff correction | `bookboll-BACKEND/app/Support/BookingProvenance.php`; `app/Services/BookingCreatorReconciliation.php` under the same backend |
| Frozen ownership, channel and quote | `bookboll-BACKEND/app/Support/PaymentFinancialContext.php` |
| Own-venue allocation persistence | `bookboll-BACKEND/app/Support/OwnedVenueAllocation.php` |
| Classification, disjoint totals, filters and cache | `bookboll-BACKEND/app/Support/PaymentReportRules.php`, `PaymentReportTotals.php`, `PaymentReportFilter.php`, `PaymentReportCache.php`; `app/Services/PaymentReportService.php` |
| Transfer preview/apply and exclusions | `bookboll-BACKEND/app/Services/VenueTransferService.php`; `app/Support/LegacyReconciliation.php` — reconciliation version **3**, distinct from the outer contract version |
| Historical missing fee / original quote restoration | `bookboll-BACKEND/app/Services/HistoricalOwnedFeeReconciliation.php` |
| Three owner-confirmed retained deposits | `bookboll-BACKEND/app/Services/HistoricalRetainedDepositReconciliation.php` |
| Dashboard and transfer presentation | `bookboll-new-system/admin/src/lib/paymentReport.js`; `src/components/VenueTransfer.vue` under the admin app |
| Connected details, Stripe receipts and full refund operations | `bookboll-BACKEND/app/Services/PaymentDetailService.php`, `PaymentRefundService.php`, `StripeRefundGateway.php`; `app/Support/PaymentRefundAccess.php`; admin `src/components/PaymentDetails.vue` |

Existing commands, run from `bookboll-BACKEND`, are `bookings:reconcile-creators`, `payments:reconcile-owned-history`, and `payments:reconcile-retained-deposits`. They default to analysis and require `--apply` for guarded workspace writes. Their existing preview-specific eligibility makes them insufficient as a complete production planner. `payments:prepare-preview` is **not** a dry run: it mutates copied data, creates a preview administrator and deletes copied access tokens. Never use it or the snapshot-import startup script on production.

Schema changes and data corrections are separate. Review all pending migrations against the actual server before deployment; relevant files include:

- `2026_10_19_000010_add_operator_identity_to_venues_table.php`
- `2026_10_19_000011_create_venue_operating_agreements_table.php`
- `2026_10_19_000012_create_venue_contract_documents_table.php`
- `2026_10_19_000013_create_venue_transfer_tables.php`
- `2026_10_19_000014_add_record_purpose_to_bookings_table.php`
- `2026_11_10_000001_add_payment_financial_context.php`
- `2026_11_10_000002_create_payment_allocations_table.php`
- `2026_11_10_000004_record_booking_creation_provenance.php`
- `2026_11_11_000001_create_payment_allocation_corrections_table.php`
- `2026_11_12_000001_create_payment_refunds_table.php`

These are in `bookboll-BACKEND/database/migrations`. The intervening workspace-access migration is preview infrastructure, not production authorization policy. Do not run `migrate:fresh`, import the SQL snapshot, replace users/tokens or drop audit tables during cutover. Schema migration alone does not reconcile historical data.

## API and report contract

`GET /api/admin/payments/dashboard?report_version=2` returns server-derived perspective, paginated allocation rows, summaries per currency, review counts and filter options. Older responses without v2 must keep unreliable earnings summaries disabled in the frontend.

Search, multi-select status/method/category/channel/currency/source/location/listing/business, recipient scope, inclusive custom dates, amount bounds including zero, sorting and pagination must apply consistently to the whole authorized set. Resolve sibling receipts/allocations before filtering. The Payments report is uncapped by the separate Analytics activity page's 5,000 loaded-booking limitation. Report totals are not sums of the visible page.

- `listing_id` accepts comma-separated IDs (or an array), intersects `location_id`, and filters allocations before checkout grouping, summary and pagination. `filter_options.listings` contains `{value,label,venue_id,venue_name}` from the authorized ledger, independent of the active filters. The UI limits these to selected venues and removes incompatible listing selections; clearing venues preserves compatible listings. Provider requests cannot reveal foreign options or totals.
- Platform Business options start with `bookboll` / **Bookboll · own venues**. Match the historical `operator=bookboll`, not `recipient_kind=bookboll`: a fee from an external venue must not match this option. Multiple business choices form a union, e.g. `business_id=bookboll,7`. Keep frozen ownership after transfers.
- **On hold** and **Available for payout** are removed from the Payments filter. Wallet clearing (`wallet_transactions.status=on_hold`) is a separate process; do not delete or remap wallet records to implement this UI change. Payment filters retain Paid, Pending, Refunded and Cancelled.
- Cache namespace is now **v12**, so old cached rows without listing IDs are discarded. No historical payment amounts, fees, statuses or ownership are changed by this report update.

Historical operator/recipient, quote, currency and receipt links are server-owned snapshots. Current venue transfer, plan, commission or staff-role changes must not silently rewrite them. Provider historical ledger access must not expose another business's current booking details.

## Tests and remaining release work

**New payment details/refund workflow:** read [the refund implementation and deployment contract](docs/payments/REFUNDS_AND_PAYMENT_DETAILS.md). Full original Stripe refunds require platform `admin` / `super_admin`; provider identities never qualify. Confirmed refunds update all linked allocations together. Railway keeps real money movement disabled. Legacy provider-wallet credits without exact receipt/reversal links are explicitly blocked pending wallet reversal implementation; partial refunds and externally initiated refunds are not covered by this workflow.

Use a disposable `bookboll_test` database, never production, for Laravel integration tests. The inventory links the relevant tests for classification, creation evidence, saved quotes, transfers, permissions, report totals and historical corrections. Also run `php tests/payment-report-rules.php`, the admin tests and the admin production build. Archived test counts are evidence from earlier runs, not certification of a future production release.

Release work still required:

- Implement the production runner and release checks in the [rollout guide](docs/payments/PRODUCTION_ROLLOUT.md), including actual live identity/cohort mapping, complete audit, resume and compensating reversal.
- Exercise every live writer: marketplace web/mobile, provider website, admin, QR, events, subscription/manual membership charges and renewals. Preserve saved context and receipt links on later collection and webhook replay.
- Verify Stripe Connect in test mode, including actual fee/transfer behavior and ownerless-business eligibility. Railway's disabled integrations and wallet flags do not prove real payouts work.
- Replace any legacy settlement fallback to today's fee rates with saved evidence or explicit review. Audit remaining legacy due/reprice endpoints for raw receipt-plus-allocation double counting.
- Complete partial refund, refund-date adjustment, dispute/chargeback and processor-fee handling before claiming those reports are supported. Current full-refund handling is not a complete period cashflow or historical balance ledger.
- Validate provider/country/staff permission boundaries, consistent exports during concurrent writes, multi-replica shared cache/locking, and report invalidation after corrections. The separate Analytics activity endpoint still needs complete server-side aggregation.

## Railway reference only

The working environment is an isolated, editable snapshot with real charges, Connect and outbound messages disabled. Use [the admin workspace](https://bookboll-admin-preview.up.railway.app/payments) to review the interface. Its corrected totals and test records must not become hardcoded production expectations.

Last recorded QAR verification: Bookboll received **824,736.40** = platform fees **156,638.70** + own-venue revenue **668,097.70**. Own pending **155,649.90** = pending platform **19,785.40** + pending venue **135,864.50**. Three cancelled legacy receipts remain in review and contribute no received or pending earnings. These figures refer to that snapshot and verification time only. The full history, including capacity recovery and earlier superseded totals, remains in the archived log.
