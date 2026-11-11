# Payment details, Stripe receipts and full refunds

Implemented 11 November 2026. This extends the Payments report; it does not run historical corrections or enable Stripe in the Railway workspace.

The [enforced release gate](ENFORCED_RELEASE_CHECKS.md) now blocks real refund attempts until signed release and Stripe acceptance evidence matches the deployed code/database. The platform role checks and all per-payment reversal guards remain mandatory after that gate passes.

## Interface and connected records

Checkout payments have one online entry and one remaining entry per collector/currency, with per-booking allocations; see [checkout payments](SHARED_ONLINE_PAYMENTS.md). The drawer shows the combined amount and links to the other phase. Old allocation IDs stay in the audit disclosure. Refund preflight covers the actual original full charge, never a filtered subtotal, and never changes the remaining payment. Offline remaining collection has a separate reviewed, atomic and replay-safe action for the receiving business.

- Payments has a **Last month** calendar preset, including year boundaries and leap years. Both breakdown cards start collapsed. The large receipt-total equation was removed; the disjoint calculation is unchanged.
- The detail drawer shows amount, recipient, status, collection date, creator/channel, saved rate and original receipt amount, followed by connected transactions. Connections load from the complete authorized booking, membership and Stripe payment history, independently of table pagination/filters.
- Inspect a connected transaction and return with **Back to payment**. Gross receipt rows are identified as original receipts, not additional earnings. Document links on allocation rows use only one explicitly resolved, authorized original receipt, including an unambiguous conserving legacy pair recognized by the report.
- Table and drawer show Stripe receipt actions. Refunded payments prefer the confirmed refund receipt. Links must be HTTPS on `stripe.com` or its subdomains; no fabricated URL from a charge/invoice ID. Missing processor links display unavailable.
- The full-history cache stores only public report/filter/total fields, not repeated source audit snapshots or nested allocation arrays. Original notes load in the detail endpoint. Classification and refund validation continue to read complete persisted evidence. Cache format is version 8; charge/intent indexes support connected lookups.
- Provider users can inspect their authorized transactions and receipts. A venue transfer never grants access to another operator's historical ledger or the current booking.

## Refund authorization and scope

Only platform identities with role **`admin` or `super_admin`** can preview, request or refresh a refund. Provider-scoped identities, active business memberships and `owner_user_id` identities are rejected even if also assigned an admin role. `country_admin`, `platform_staff`, provider/facility admins and ordinary staff cannot refund. Existing authentication, active-account, payment capability and ledger scope checks still apply. Buttons alone are not the authorization boundary.

Refunds are **full original Stripe charge refunds**. Selecting a fee allocation of 20 within a 100 online payment previews/refunds 100, not 20. If one charge settled multiple bookings, all affected payment records are shown in confirmation and updated together. Unrelated on-site receipts/pending balances remain unchanged. Refunding a payment does not cancel a booking or release its slot.

The server checks captured/paid Stripe state, currency and exact minor-unit conservation across the linked receipts and allocations. It rejects cash/card-at-venue, unpaid records, disputed charges, changed previews, prior processor refunds and ambiguous amount/link evidence. Separate provider transfers without durable transfer links are blocked. A destination charge requests `reverse_transfer`; a charge with an application fee requests `refund_application_fee`.

**Legacy provider-wallet guard:** old `booking_online` / `subscription_online` wallet credits have only booking/subscription links, not exact Stripe receipt links. Such payments are currently blocked before any refund request. Do not bypass this check: otherwise the customer could be refunded while the provider retains a withdrawable balance. Before supporting these records, persist exact receipt/charge/transfer and wallet-credit links; implement an audited, idempotent wallet/payout reversal for held, released and paid-out credits; reconcile legacy matches individually and test those paths. A booking ID alone is insufficient, especially for multiple charges and membership renewals. The existing wallet is not silently debited or zeroed.

## Durable operation and reporting

1. A read-only preflight returns the original charge amount, affected payment IDs and a fingerprint of saved payment/allocation facts plus processor state.
2. Confirmation repeats preflight and authorization, locks the charge operation and payment rows, and rejects stale facts. It writes `payment_refunds` with a unique charge, durable Stripe idempotency key, requesting actor, reason, original rows, currency and amount before calling Stripe.
3. The charge is submitted once. A timeout becomes **unknown**, not a failed refund to retry blindly. Repeated confirmation returns the stored operation. **Check status** and signed webhooks reconcile the current Stripe state without submitting another refund.
4. Only processor status **succeeded** marks every affected receipt/allocation `refunded`; paid amounts, original payment dates and original collection timestamps remain intact. Pending, failed, canceled and uncertain requests remain paid in received reporting. The separate refund record records request/confirmation time and Stripe evidence.
5. Full-refund allocations leave received totals together. No negative duplicate receipt is inserted and no original receipt is deleted. Report cache invalidates after commit.

Stripe's charge `receipt_url` is the hosted receipt which Stripe updates to show refunds. The confirmed operation records that URL, and the table/drawer label it **Stripe refund receipt**. No separate invented refund PDF/link is generated. See [Stripe receipts](https://docs.stripe.com/receipts) and [refund API](https://docs.stripe.com/api/refunds/create).

## Deployment and production requirements

Apply `bookboll-BACKEND/database/migrations/2026_11_12_000001_create_payment_refunds_table.php` before the new API: it creates the operation/audit table, nullable payment association, and charge/intent lookup indexes. Existing payment amounts/statuses are untouched by the migration. Deploy frontend and backend together.

Endpoints (under `/api/admin/payments/{id}`):

| Method / suffix | Purpose |
|---|---|
| GET `details?row_id=…` | Authorized selected ledger row and connections; use the source payment's numeric ID in the path and the displayed allocation ID in `row_id`. |
| GET `refund-preview` | Admin-only read-only processor preflight. |
| POST `refund` | `{fingerprint, reason}`; supported reasons: `requested_by_customer`, `duplicate`, `fraudulent`. Throttled. |
| POST `refund-refresh` | Admin-only refresh of the recorded operation; never creates another processor refund. Throttled. |

Configure the existing **signed** Stripe webhook to deliver `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed`, including the correct connected-account scope where applicable. The new handler reconciles only operations created by this workflow. It does not import unrelated Stripe-dashboard refunds automatically. Failed/uncertain operations need processor review; do not delete their audit or idempotency key to retry.

Real Stripe movement remains blocked by `PAYMENTS_PREVIEW` in the Railway workspace, in the authorization layer and processor adapter. Do not disable that guard merely to test the buttons. Before production enablement, use a separate **Stripe test-mode** environment and complete actual processor tests for deposit/full/own/event payments, multi-booking charges, connected transfers/application fees, receipts, pending/failure/timeouts, webhook signature/replay, and refund status refresh. Use shared cache/locks for multiple API replicas and test concurrent refunds against the durable unique operation constraint.

Not claimed as supported: partial refunds, already partially refunded charges, dispute/chargeback accounting, separate-transfer or legacy-wallet reversal without exact evidence, externally initiated refund imports, processor fees, or refund-date cashflow statements. The current received report is a status-based view of original collection dates; it is not an as-of historical cash ledger. Preserve the production cutover gates in `PRODUCTION_ROLLOUT.md`.

## Verification

- `PaymentDetailsAndRefundTest.php`: detail authorization, cross-business isolation, connected internal/external/legacy allocations, full/multi-booking refunds, on-site balance preservation, admin/provider permissions, pending/failure/timeout recovery, replayed webhooks, stale/amount/dispute checks, timestamps, wallet and Railway guards. Uses a fake processor and disposable database.
- `StripeRefundGatewayTest.php`: SDK request transport stub checks original full amount, durable idempotency header, account scope and Connect reversal parameters. No network call or real refund.
- Existing payment-report/cache, creator, transfer, historical fee/deposit and Stripe receipt/settlement suites remain regression gates. Pure financial rules and frontend date/receipt URL tests also apply.
- Visual QA: platform dark mode and provider light mode, connected navigation, no provider refund controls, collapsed summaries, receipt action states and Last month selection. Real processor execution remains pending the separate test-mode environment above.
