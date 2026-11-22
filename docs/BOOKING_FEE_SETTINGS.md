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

CI runs MySQL/MariaDB integration tests in `GeographySettingsTest`, `OwnSiteCommissionPolicyTest`, `DepositFollowsSurfaceAndCommissionTest`, `CommissionSnapshotAndLedgerTest`, `BookingPromotionFundingTest`, `AccountManagementTest`, and `OwnSiteReviewTest`, alongside the full existing financial regression suite. The policy manifest requires P28. Do not omit these when copying this environment to production.

Validate numeric limits and explicit 0, inheritance/reset at every level, country-admin jurisdiction and provider denial, Premium eligibility, deposits and full online checkout, fixed deposits/promos, catalog quote parity, snapshot preservation, and migration idempotency. Browser verification must cover global/country/city editors, review/discard and small-screen layout. Railway preview never sends real payments or messages.
