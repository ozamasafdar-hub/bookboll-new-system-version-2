# Checkout payments: one deposit and one remaining balance

Updated 11 November 2026. Required rules: **P17_SHARED_ONLINE_PAYMENTS** and **P18_CHECKOUT_BALANCES**. This supersedes the earlier design that left each slot's arrival balance as a separate table entry.

## What the user sees

For bookings `BKN_2026_11_00420` and `BKN_2026_11_00421`, show two payments under the same checkout reference:

| Checkout payment | Total | Booking allocations |
|---|---:|---|
| Online deposit | QAR 45 | QAR 25 + QAR 20 |
| Remaining payment | QAR 180 | QAR 100 + QAR 80 |

Each phase has one checkout payment reference. The old per-slot PAY IDs are **original allocation references**, preserved in an audit disclosure and CSV. They are not additional invoices or charges. The details drawer opens the combined amount and links directly to the other payment in that checkout.

Never count the QAR 45 or QAR 180 parent again on top of its children. Never present QAR 225 as an online charge. The source snapshot has pending arrival balances; previously approved legacy reconciliation marks eligible balances collected in Railway. Grouping itself changes no amounts, statuses or recipients and does not prove cash collection.

## Identity and schema

- Apply `2026_11_14_000001_add_booking_checkout_reference`: indexed nullable `bookings.checkout_reference` plus the `checkout_payment_collections` audit table.
- New customer checkouts stamp every created slot with one server-generated CHK reference inside the booking transaction. Clients cannot choose this reference. Full online, deposit and full-arrival checkouts use it. The phase reference remains stable when the processor identity arrives.
- Historical online allocations group by verified charge + currency. Intent-only records may join a unique known charge for that intent. Multiple attempts remain separate. A conserving fee/share allocation may inherit identity only from its single authorized gross receipt.
- Historical remaining balances join only when their booking has exactly one verified deposit checkout. Missing or ambiguous identity stays separate. Never guess from customer, timing, amount or receipt URL.
- Group remaining balances only for the same checkout, currency, collector/operator/business and customer. Different businesses must never receive a combined collect action.
- Preserve original payments, invoices, processor references and amounts. No destructive merge or new money-bearing parent is needed. These canonical checkout payments aggregate existing ledger allocations.

## Report, details and export

The UI requests `GET /api/admin/payments/dashboard?report_version=2&group_by=stripe` (the parameter name is retained for compatibility). Response entries can be `online_group` or `remaining_group`, with `checkout_reference`, `payment_id` (the phase reference), `bookings[].allocations`, `paid_amount` and `pending_amount`.

Scope records to the actor first. Apply allocation filters and compute summaries before grouping, then paginate groups. `payments.total` counts displayed entries; `allocation_total` counts matching ledger allocations. Filtered subtotals are labelled `partial`; provider amounts are explicitly scoped. Grouped and flat summaries must be identical. Group date sorting uses the latest included date and original receipt IDs to preserve same-day ordering.

A QAR 180 remaining payment with QAR 100 collected and QAR 80 pending shows **Partly collected**, both amounts, and only QAR 80 available to collect. Paid/refunded or other mixed states remain explicitly mixed; the whole total must not be reported as received.

CSV requests `group_by=allocation`, includes checkout and phase references, labels the old ID as the original allocation reference, and exports each allocation exactly once. Searching a checkout reference finds its linked payments. Details load all authorized siblings independently of the current table filter. Gross source receipts stay excluded from sums.

## Record an outstanding balance

Only the receiving business can record its offline balance: platform staff for Bookboll receipts, provider staff for their own business receipts, with `accept_payments` capability and current booking access.

1. `GET /api/admin/payments/{sourceId}/collection-preview?row_id=...` resolves the complete remaining group and returns the outstanding amount, currency, underlying receipt IDs and a fingerprint.
2. `POST /api/admin/payments/{sourceId}/collect-checkout` accepts that fingerprint, row_id and cash/card/cheque/wire_transfer.
3. Lock the related bookings and payments; recheck permission, current status, recipient, amount and fingerprint. Refuse online, cancelled, already-settled, changed or ambiguous records.
4. Reuse the booking collection logic in one outer transaction. Update all outstanding receipt allocations or none. Preserve already collected slots and fee allocations. Record the shared reference in booking history and one operation in `checkout_payment_collections`.
5. Replaying the same operation returns its recorded result without another collection. A new review is required after any balance change.

This action records money already received. It never charges Stripe, invents collection of pending records, discounts balances or triggers refunds.

## Receipts and refunds

A shared online payment has one Stripe receipt action and one platform-admin/superadmin refund action. Refund preflight still verifies full charge scope, amount, ownership and processor state. Refund the actual full processor charge, never a displayed subtotal. Arrival balances are unchanged. Providers never receive online refund permission. Railway does not execute real refunds.

## Mandatory automated and rollout checks

- Two entries (45 online, 180 remaining), pagination, shared checkout / distinct phase references, connected details and checkout search.
- Filter one child/status/date/amount: only matching allocations count, partial labels appear, flat/grouped summaries agree.
- Saved checkout references remain stable through Stripe confirmation; missing/ambiguous historical identities, different charges, currencies and collectors remain separate.
- Own-venue internal fees, external fee/share splits and original gross receipts conserve amounts without double counting.
- Provider and restricted-staff isolation applies to grouping, details, preview, collection, receipt and refund access.
- Full and partial offline collection, unchanged paid slots, stale/cancelled rejection, transactional rollback and idempotent replay with a saved audit record.
- CSV remains flat and complete; combined references, booking allocation disclosure, keyboard controls and dark/light styles work.
- Full-charge refund regression tests use fake/test processors only.

Tests: `PaymentReportDashboardTest.php`, `PaymentDetailsAndRefundTest.php`, `PaymentReleaseGateTest.php`, `paymentLedgerEntry.test.mjs`. GitHub CI runs these. The release gate requires **18 rules**, the checkout schema, and signed financial regression evidence covering P17 and P18. Previous 17-rule evidence is rejected. Changed code/schema/policy invalidates old signatures. The production migration runner and fresh-live-copy rehearsal remain mandatory independent blockers.