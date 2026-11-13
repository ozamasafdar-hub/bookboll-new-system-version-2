# Provider setup, calendar and Stripe connections — 13 November 2026

## Business onboarding

Team → Add business uses Business / Team access / Review. Creation makes one active, Free business and a branded website draft by default. Publishing is an explicit choice. No synthetic owner or shared password is created. Creation is idempotent.

Invite the owner first, then invite staff. Invitations accept only provider roles, enforce business/country scope and reject a second owner. Existing accounts use the audited assignment preview/commit flow, which strips platform privileges. A new invite gets a random inaccessible password; the email points to the existing Forgot password / verified email code flow. Preview never sends mail. Delivery failures are reported without creating duplicate users.

The business ID is the website/catalog boundary. Ownerless sites must not fall through to the marketplace catalog; legacy owner IDs are a fallback only for venues with no business ID. Ownership assignment must preserve that boundary. Owners can manage their brand; managers reach only their business's venues/bookings. There is no permission to make invited provider staff into platform staff.

## Calendar

Busy venue columns expand to maintain 184px per simultaneous booking. Each booking remains individually clickable; no striped aggregate blocks. Cards show customer, listing and time. Missing customer data is explicitly labelled. Overnight bookings add a day to their end time and extend the grid; clicking after midnight sets the next date. Customer names come from the dashboard booking serializer's eager-loaded user.

Calendar, booking details, table status filters and activity charts use the same paid-deposit confirmation rule as customer bookings. An old pending booking with a paid deposit is displayed as confirmed while its remaining payment stays pending. `recorded_booking_status` preserves the historical value; reading reports does not rewrite bookings or receipts. Refunded deposits do not confirm a booking, and cancelled/completed bookings keep their terminal status.

Calendar layout regression checks: closed bands, hover bands and hour lines must be absolutely positioned inside the time body. Special-day notices use a zero-height wrapper, and venue headers/time-gutter corners share one height; neither may shift a column's time origin. The initial scroll follows the first booking among selected venues after data loads, with explicit First booking / Go to time controls for the full 24-hour schedule. Stale date responses are ignored.

Before releasing calendar changes, inspect all venues together and a busy single venue in the browser: verify hour lines align with booking start/end times, closed shading stays behind bookings, customer/court/time labels are readable, and an 11pm–1am booking spans two hours into the next-day grid. Check 15/30/60-minute views, horizontal/vertical scrolling and week/month switching. Helper tests alone do not verify CSS positioning.

## Payment setup replaces Bookings wallet

`/payment-setup` is the provider connection page and platform oversight view. Legacy wallet URLs redirect here (retired membership wallet still redirects to memberships). Payments remains the transaction/reporting page. A legacy wallet marked active is **not proof of a Stripe connection**.

- Deposit + arrival requires no provider gateway. Bookboll keeps the deposit fee; the provider collects the balance.
- Full online needs the business owner's verified Stripe connection. Only that owner, with `manage_payouts`, can start or refresh it. Platform users supervise status without attaching somebody else's financial account.
- Stripe Standard OAuth uses a one-use, hashed 15-minute state bound to the actor and business. Callback rechecks actor access and Stripe mode; account IDs are unique across businesses. Account capability changes and deauthorization are handled through signed Stripe webhooks.
- Customer checkout, admin QR/payment links and public booking pay links use `ProviderStripeService::checkoutData`. Deposits have no provider transfer. Full/remaining online uses destination charges with the frozen per-booking net fee as `application_fee_amount`, and the provider's connected account as `transfer_data.destination`. Fees already collected as deposits are subtracted. Manual staff bookings have zero platform fee.
- One checkout can combine slots for one external business/currency; mixed recipients are rejected. Historical records lacking fee evidence cannot be charged full online until reviewed. Existing event hosting continues its deposit + arrival model for external courts.
- The checkout stores the expected destination, fee, amount and currency in `stripe_sessions.settlement`. Fulfilment verifies them against the actual PaymentIntent and validates the booking IDs before marking records paid. The verified destination is stamped onto booking financial context. No duplicate withdrawable provider-wallet credit is created for a destination charge. Own-operated venue accounting stays on Bookboll's existing internal allocation path.
- Refunds remain platform-admin only. Existing `StripeRefundGateway` reverses destination transfers and the application fee; provider staff cannot issue refunds.
- Bank payout timing is controlled by Stripe. Under destination charges Stripe processing fees debit the platform balance; this implementation does not silently subtract an invented extra processing fee from the provider allocation.

## Deployment and mandatory verification

MySQL migration: `2026_11_16_000001_create_business_payment_connections.php` creates business connections, one-use OAuth states and the checkout settlement JSON column. Normal Railway startup applies migrations. No PostgreSQL conversion is involved.

Configure the **platform's** `STRIPE_CONNECT_CLIENT_ID`, matching `STRIPE_SECRET_KEY`/`STRIPE_PUBLIC_KEY`, `STRIPE_WEBHOOK_SECRET`, `APP_URL` and `ADMIN_URL` through the hosting secret manager. Register `{APP_URL}/api/provider-stripe/callback` in Stripe's OAuth redirect URLs. Connect events must reach the existing signed Stripe webhook, including `account.updated` and `account.application.deauthorized`. Provider secrets are never pasted into Bookboll.

`STRIPE_CONNECT_ON_BEHALF_OF` defaults false. Enable it only after verifying the actual platform/provider countries and supported charge flow with Stripe; some cross-region combinations require it, others do not support it. Do not assume a QAR-priced venue proves its provider account is in a supported Stripe country. Unsupported businesses can use deposit + arrival.

The Railway test workspace keeps outbound Stripe and real email locked. Automated MySQL tests mock Stripe; they **do not constitute live processor evidence**. The existing mandatory `provider_connect_settlement` release check still requires an actual **Stripe test-mode** artifact before production payments unlock. Verify OAuth, restricted accounts, two-slot destination split, provider promo/fixed deposit/platform promo, balance after paid deposit, duplicate webhooks, disconnect, and refund/reversal in Stripe test mode. Do not approve this evidence based on a UI badge or an old wallet setting.

Release rule P27 and the schema gate require the connection tables and checkout settlement snapshots. Previous acceptance without P27 is rejected.

CI includes `BusinessSetupAccessTest`, `ProviderStripeConnectionTest`, ownership assignment, own-site methods/review and calendar layout regressions. Run the payment-release workflow before promoting this branch.

References: [Stripe Standard OAuth](https://docs.stripe.com/connect/oauth-standard-accounts), [destination charges](https://docs.stripe.com/connect/destination-charges), [refunds for destination charges](https://docs.stripe.com/connect/destination-charges#issue-refunds).
