# Batch 4: booking fee settings

Authorized 22 November 2026. MySQL remains the database. This policy supersedes the former automatic marketplace / half-marketplace website rates.

| Booking source | Global default |
| --- | --- |
| Bookboll marketplace | 15% |
| Free provider website | 15% |
| Eligible Premium provider website | 10% |

Each fee is independent. Resolve city override, then country override, then global. An absent/null override inherits; explicit zero is valid. Premium includes active paid subscriptions and superadmin-granted complimentary Premium. Trials, expired/past-due subscriptions and Free use the Free website rate.

The Countries & cities page shows these three fees together with their sources. Its editor previews the platform fee and venue share on a booking worth 100, reviews changes, and lets each country/city restore inheritance. Other global settings are collapsed below booking fees. Venue/business sorting remains available.

## Rollout

Run `2026_11_22_000002_set_independent_booking_fee_defaults.php`. It sets the three global values once and clears the explicitly approved Qatar/Pakistan marketplace **country** overrides, identified by ISO QA/PK. It preserves other country/city settings, listing-specific commissions, fixed deposits, events, rewards, membership listing prices and all historical bookings/payments.

The `settings` marker `booking_fee_defaults_20261122` contains the prior global values and removed country configuration. Re-running the migration leaves subsequent admin changes untouched. Code rollback does not automatically overwrite business pricing; use the settings editor for a deliberate reversal.

Marketplace listing-specific commissions/fixed deposits still take priority where configured. Website fees are independent of listing marketplace commissions. Staff-created bookings still have no platform fee. Provider promo percentage fees use the discounted price; platform-funded discounts cannot exceed Bookboll's available fee. Saved booking snapshots remain the settlement authority.

## Required release checks

### Pricing and upgrade presentation — 23 November 2026

The public `/booking-fees` information page is linked from the business header, landing page and pricing comparison. It shows current global defaults, enabled country rates and enabled city rates, each with its source. Country filters include countries whose only overrides are at city level. City search/custom-only filtering is scoped to the selected enabled country, paginated at 50 rows; zero is an explicit override, null and unrelated settings are not. Anonymous endpoints `/api/business/booking-fees/countries` and `/api/business/booking-fees/countries/{country}/cities` return only geographic names/IDs/codes and the three booking rates/sources. No internal settings, owners, contact information or business records are published. No additional migration or rate changes are required. Test these endpoints in `BookingFeePresentationTest` and asynchronous page filtering in business `tests/bookingFees.test.js`; both are mandatory in CI.

The business landing/pricing pages load current global rates from the public `GET /api/business/booking-fees` endpoint. No provider records or unrelated settings are exposed. The authenticated `GET /api/admin/plan/booking-fees` comparison returns the signed-in business's accessible venues and independently resolved rates/sources. A business without venues sees its country defaults. Never accept a client-supplied business, plan or country as checkout authority.

The upgrade modal shows Free/Premium website fees and the marketplace rate on every step, with a venue selector, source labels and a price-100 example. The annual plan charge is separate. Failed rate requests show a retry, not invented fallback pricing. Marketplace listing overrides/fixed deposits remain explicitly distinguished from geographic defaults. Full online receipts use connected Stripe payouts; deposits require no provider gateway.

`BookingFeePresentationTest` must pass on MySQL: published data scope, provider/venue isolation, and a 12-case Free/Premium × global/country/city × deposit/full-online matrix comparing the upgrade API, catalog, charge calculation and persisted booking/payment allocations. It also checks zero, decimals, ignored stale listing deposit values and preserved historical snapshots. `bookingFeeComparison.test.js` checks reactive venue selection, zero and missing-data handling; `annualPlan.test.js` keeps subscription billing annual. These tests run in the release workflow and are listed in policy P28. No additional migration is needed for this presentation change.

CI runs MySQL/MariaDB integration tests in `GeographySettingsTest`, `OwnSiteCommissionPolicyTest`, `DepositFollowsSurfaceAndCommissionTest`, `CommissionSnapshotAndLedgerTest`, `BookingPromotionFundingTest`, `AccountManagementTest`, and `OwnSiteReviewTest`, alongside the full existing financial regression suite. The policy manifest requires P28. Do not omit these when copying this environment to production.

Validate numeric limits and explicit 0, inheritance/reset at every level, country-admin jurisdiction and provider denial, Premium eligibility, deposits and full online checkout, fixed deposits/promos, catalog quote parity, snapshot preservation, and migration idempotency. Browser verification must cover global/country/city editors, review/discard and small-screen layout. Railway preview never sends real payments or messages.
