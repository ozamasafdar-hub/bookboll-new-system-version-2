# Bookboll payments — current developer handoff

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

Search, multi-select status/method/category/channel/currency/source/location/business, recipient scope, inclusive custom dates, amount bounds including zero, sorting and pagination must apply consistently to the whole authorized set. Resolve sibling receipts/allocations before filtering. The Payments report is uncapped by the separate Analytics activity page's 5,000 loaded-booking limitation. Report totals are not sums of the visible page.

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
