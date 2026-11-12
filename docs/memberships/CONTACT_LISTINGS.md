# Membership contact listings — 12 November 2026

This replaces the customer membership operations model. Earlier instructions about membership commissions, enrolment, trials, wallets, subscribers and check-in describe historical behavior only.

## Product and money rules

| Placement | Eligibility | What the provider pays Bookboll |
|---|---|---|
| Bookboll marketplace | Approved, active listing with a public academy phone and a confirmed paid listing period | USD 20/month **per listing** by default, on Free and Premium alike |
| Provider's branded website | Active, paid, unexpired Premium business plan; active listing and phone | Included in Premium; marketplace exposure is separate |

Customers call the academy to arrange enrolment, attendance and academy payments. Advertised academy prices and schedules are information only. They do not create customer subscriptions, payment records, sessions or court reservations.

The global `membership_listing_monthly` value defaults to 20. City override → country override → global. All listing subscription prices are **USD**, independently of booking currency. Null inherits; values validate from USD 1 to 100,000. Existing subscriptions retain the amount they accepted; edits apply to new subscriptions. Changing the global rate does not reprice existing Stripe subscriptions.

Listing subscription income belongs entirely to Bookboll. It appears in **Membership listings → Listing payments**, with invoice links, paid dates, amounts, refunds and date filters. It is separate from booking GMV, provider share, booking commissions and historical academy-customer payments. Do not count it again in the booking ledger.

No grace period was specified. Existing listings and history are preserved, but unpaid marketplace listings become hidden; they need approval, contact details and a paid period. Existing own-site listings need active paid Premium. Hiding a listing does not cancel billing: the UI explicitly directs providers to stop renewal. Cancellation keeps the verified paid period available. Expired periods hide automatically on reads, without relying on a scheduler. A fully refunded invoice no longer grants access; partial refunds remain recorded and reduce received totals.

## Implementation and deployment

- MySQL/MariaDB migration: `2026_11_12_000003_create_membership_listing_billing`. Adds contact/schedule fields, `membership_listing_subscriptions` and `membership_listing_invoices`. It does **not** delete or relabel historical subscriptions, wallets or payments. Deploy backend/admin/marketplace/business bundles together; Railway startup runs migrations.
- `MembershipListingPolicy` is the shared authority for catalog, public detail, branded site, SEO and geographic-directory visibility. Backend checks the actual paid Premium period, not a cached plan label or frontend checkbox. AccessScope restricts provider/jurisdiction queries and mutations.
- `ProgramSaveService` retains the approval workflow. Phone and schedule changes are reviewed alongside public copy/photos. Listings cannot move venues while billing is active. Archive retains records; cancel billing before archiving.
- New `GET /api/admin/membership-listings` returns scoped listings, placement eligibility, local prices and listing invoices. `POST /api/admin/membership-listings/{id}/checkout` quotes the rate on the server. `POST .../{id}/billing` accepts `cancel`, `resume`, or `portal`. View requires listing access; writes require manage-listings capability plus business/jurisdiction scope. Only the original payer can open its Stripe billing portal.
- Stripe Checkout uses a monthly subscription and metadata `source=membership_listing`, `listing_subscription_id`, `program_id`. The local incomplete record survives a failed request. A per-listing cache lock, stable Stripe idempotency key and reused open Checkout prevent double subscriptions during retries. Checkout success redirects never grant access.
- Existing Stripe webhook signature verification routes this product to `MembershipListingService` before the historical customer-membership handler. Subscribe the endpoint to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_succeeded`, `invoice.payment_failed`, `invoice.voided`, `invoice_payment.paid` and `charge.refunded`. The latter invoice-payment event supports newer Stripe invoice PaymentIntent linkage. Maintain existing events for other payment products.
- Paid invoice ID is unique. Processing uses transactional row locks; repeated paid events do not duplicate revenue, stale failures cannot undo payment, and repeated paid events cannot restore a fully refunded period. Subscription events keep ordering timestamps. Refund events retain cumulative refunds and recalculate the paid entitlement.
- Uses existing Stripe secret, webhook secret and configured `ADMIN_URL`. Enable Stripe's customer billing portal for invoice/payment-method access. Pricing is stored at checkout; do not trust browser-supplied prices. Never use a provider Connect wallet to collect this Bookboll subscription.
- Railway preview deliberately blocks real checkout and renewal mutations. Automated tests use a mock Stripe client and signed fixture events; no real charge was made. Production remains gated on verified evidence below.

## Retiring customer operations safely

Old customer enrolment, subscriber mutation and check-in APIs return **410** with the contact-listing explanation. The billing service also refuses new customer membership checkout. Membership-wallet mutations are blocked even when callers supply only an old wallet ID. Historical records remain available for reconciliation. Navigation and old page routes lead to membership listings; generated membership sessions no longer block calendars.

Before production, inventory **every legacy customer Stripe subscription** in `class_subscriptions`, verify its actual Stripe status, end future renewals with the appropriate customer communication/settlement process, and preserve evidence and all paid history. Deploying code does not cancel a subscription already scheduled at Stripe. Do not mark local rows canceled without processor verification. This work is intentionally not performed against real accounts by this preview update.

Mobile/API clients must replace enrol/enroll, subscription, trial and attendance flows with the public `contact_phone`, `schedule_description`, advertised price and a call action. They must handle 410 on retired endpoints and never continue charging through a cached old flow. The marketplace web client is updated here; a separately shipped native app requires its own release.

## Mandatory acceptance and release evidence

P23 is part of the versioned payment policy, static policy check, required GitHub workflow and runtime `PaymentReleaseGate`. A production release needs:

1. Signed `membership_listing_billing` evidence with `stripe_mode=test`, tied to this exact code/schema/policy/database identity. Rehearse Checkout retry, paid invoice, duplicate webhook, failed renewal, cancellation, resume, expiry, partial/full refund and portal permissions through a Stripe test account.
2. Signed `legacy_membership_retirement` evidence, plus an enforced database check that no non-terminal legacy customer Stripe subscriptions remain. Verify Stripe itself, not only local status.
3. MySQL tests: `MembershipListingTest`, `GeographySettingsTest`, `PaymentReleaseGateTest`. Cover local-price inheritance/reset, immutable subscribed price, tenant CRUD isolation, phone validation, no customer/session/wallet creation, separate Premium eligibility, direct public URL denial, paid-period expiry, webhook replay/refunds and retired wallet-ID bypasses.
4. Admin `marketSettings.test.js`, `featureSearch.test.js`, policy validation, and all three frontend builds. Existing booking/payment safety tests remain required.

Local verification: 53 MySQL tests / 263 assertions passed for the three suites above; 10 targeted frontend tests passed. Actual Stripe test-account lifecycle rehearsal remains required before live billing. See [Stripe subscription webhooks](https://docs.stripe.com/billing/subscriptions/webhooks) and [invoice-payment linkage](https://docs.stripe.com/api/invoice-payment/object).
