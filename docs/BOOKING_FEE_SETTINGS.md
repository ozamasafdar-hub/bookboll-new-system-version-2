# Booking fees — current policy, 25 November 2026

This supersedes the earlier Free/Premium website fee model and the half-marketplace rule. MySQL remains the database.

| Booking channel | Global default | Settings key | Plans |
| --- | --- | --- | --- |
| Bookboll marketplace | 15% | `facility` | All |
| Provider’s own website | 10% | `site` | All — no upgrade required |

Each channel resolves independently: city → country → global. Null/absent inherits; explicit zero is valid. Free, paid Premium, complimentary Premium, trial, past-due and expired plans receive the same website rate. Premium adds product features only. Annual plans and marketplace membership listing subscriptions remain separate charges.

## Rollout and safeguards

Run all migrations, including `2026_11_25_000001_unify_website_booking_fees.php` after the earlier Batch 4 migration. It sets the approved global 15%/10% defaults once and removes `site_free` and `site_premium`, preserving all old market settings in the audit marker `unified_website_booking_fees_20261125`. No booking/payment snapshots are rewritten.

Railway had no published geographic overrides at rollout. For another database:

- An explicit local `site` value wins.
- A sole old website override, or two matching old rates, becomes the shared local rate, including zero.
- Different local Free/Premium overrides stop and roll back migration. Review the amounts and set one explicit `market_settings.values.site` for that scope before retrying. Never silently choose a rate.
- Other settings, marketplace overrides, fixed deposits and rewards are retained.
- The marker makes reruns leave subsequent administrator edits alone. Code rollback does not automatically restore old prices; audit data supports deliberate restoration.

Old editor submissions with retired keys receive validation errors. The resolver/public endpoints never expose retired keys, even after a legacy import. Historical `commission_plan_eligible` snapshots remain; new bookings use false. The compatibility API flag `own_site_commission_discount_eligible` is always false. Neither flag controls new charges.

## Checkout and accounting

`MarketSettings` and `CommissionPolicy` are authoritative; the business plan never selects a website rate. A website booking priced at 100 at the default rate allocates 10 to Bookboll and 90 to the venue. Deposit checkout collects 10 online and leaves 90 on arrival. Full online checkout collects 100 and allocates 10/90 through the existing Stripe flow. Provider Stripe is required only for full online payment.

Marketplace listing-specific fees/fixed deposits retain priority. Staff-created bookings remain fee-free. Provider promotions reduce the price before percentage fees; platform promotions cannot exceed Bookboll’s available fee. Historical fee/share snapshots remain the settlement authority.

## Settings and presentation

Countries & cities exposes two booking fee controls with inherited sources, review and reset. Provider payment settings and upgrade modals show the same rates and promise no fee savings on Premium.

The landing diagram shows Bookboll customers → marketplace and your customers → standalone website powered by Bookboll, with live global fees and a local-rates link. Pricing shows identical booking fees on both plans. `/booking-fees` publishes enabled country/city rates with sources, pagination and scoped search. Anonymous responses contain only geography and two booking rates. Authenticated comparisons use the business’s accessible venues. Client-supplied plan/business/geography cannot determine checkout pricing.

## Required verification

Policy P28 and `scripts/check-payment-policy.mjs` require the new migration and its MySQL test in CI. Run `UnifiedWebsiteFeeMigrationTest`, `BookingFeePresentationTest`, `GeographySettingsTest`, `OwnSiteCommissionPolicyTest`, `DepositFollowsSurfaceAndCommissionTest`, `CommissionSnapshotAndLedgerTest`, `BookingPromotionFundingTest` and existing financial regressions.

Verify migration conflict rollback/audit/rerun safety; public API privacy; inheritance/zero/fractional rates; authorization; plan lifecycle parity; deposit/full payment catalog/charge/snapshot parity; promotion funding; historical records. Frontend tests cover rate selection, settings edits, missing data and asynchronous geography filtering. Check landing, pricing, upgrade and settings on desktop/mobile. Railway preview sends no real payments or messages.

### Checkout-to-reporting matrix

`BookingFeeReportingFlowTest` is required by P28, the policy script and release CI. It calls the customer checkout API with two slots, then uses the normal Stripe fulfillment and arrival-collection paths against disposable MySQL data. It covers marketplace/own website × Free/Premium × global/country/city/explicit-zero rates × deposit/full-online × provider/Bookboll venues. Settings and plan changes after checkout must preserve the original fee snapshot.

Assertions compare independent expected amounts with the captured Stripe charge and destination fee, saved booking shares, payment report and all analytics breakdowns. They check duplicate fulfillment, one checkout deposit plus one balance, provider isolation, pending-to-collected balances, separate owned-venue fee/revenue and no duplicate wallet credit. Only Stripe's HTTP transport, outbound notifications and PDF rendering are simulated; no real money is moved. These tests do not certify processor account activation or a live card charge.

### Provider full-online allocation repair — 26 November 2026

Direct Stripe Connect settlement correctly bypassed the old wallet, but also bypassed its reporting allocations. New provider full-online receipts now save platform and venue allocations in `payment_allocations` at creation, using the frozen **net fee** (after platform-funded discounts). These are parts of the same receipt, not additional payments, and inherit its pending/paid/refunded status. Legacy linked fee/share payment rows take precedence when present; they must never be counted alongside another split of their parent receipt.

Migration `2026_11_26_000001_record_provider_online_allocations.php` restores missing allocations only where the saved provider business, channel, currency, total and net fee prove the split. It skips existing allocations and legacy fee/share rows. It never looks up current rates or changes receipt amounts, statuses or ownership. Reruns are idempotent; rollback retains financial records. `ProviderOnlineAllocationTest` covers migration safety, platform-funded discounts, legacy receipt conservation, refund status and corruption detection. Both new suites are required in release CI.
