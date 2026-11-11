# Superadmin selected-payment changes (P20)

The Payments table lets a **platform super_admin only** select entries after filtering, keep selections across pages, and preview an action. The header checkbox selects this page. **Select all filtered transactions** resolves every match across all pages through the server's existing authorized report/filter logic. This is an exact selection snapshot, not a predicate that silently includes future records. Changing filters or refreshing clears selection. A checkout checkbox or all-filtered selection selects only matching allocation IDs; it never expands a filtered checkout into hidden bookings.

There is no 500-allocation limit on the overall selection. The client divides it into requests of at most 500 allocations and previews **every batch before any confirmation**. All selected payments of the same booking/subscription and every selected split receipt stay together to preserve sibling evidence. An individual booking/subscription with more than 500 matching allocations requires a narrower selection; it is never silently truncated. A 27,586-allocation selection is covered by the frontend regression. All-filtered selection itself is tested beyond 500 rows in the database suite.

The combined preview includes full counts, per-currency deltas, all before/after rows and exclusions, with 100-row browsing inside the preview. Saves are sequential and show progress. Each batch commits atomically; **the entire multi-batch operation is not one transaction**. On a failure, earlier successful batches remain saved, later batches stop, and retry reuses the original fingerprints. A lost response is safe to retry. Closing after a partial failure refreshes the ledger. Keep the page open during processing; there is no background worker. No new financial data is changed merely by selecting or previewing.

## Mark pending as paid

- Require an explicit method: **Cash, Card terminal, Cheque or Bank transfer**. Nothing is preselected. Record an actual offline collection for an active venue booking using that method, today's payment date, current collector and existing booking collection logic. Use separate selections for different methods.
- The chosen method is required server-side, included in the preview/fingerprint and shown in both confirmation and before/after audit. Changing it after preview fails with 409. Card is normalized to the existing `credit_card` storage value. The old method is preserved in the audit; receipt amounts and financial allocations stay unchanged.
- Already paid, online/Stripe-referenced, cancelled, skipped, refunded, unclassified or unsupported records are listed as excluded in the preview. They are not silently converted.
- Every allocation of an underlying receipt must be selected and conserve that receipt before its status can change. A filtered venue share cannot also collect an invisible platform fee.
- Multiple selected allocations of one receipt cause exactly one collection. The booking payment state and collection history are updated through the existing collection service. No new charge, refund or payout is initiated.

## Change allocation / recipient

The owner confirmed **existing business only**. Choose Bookboll platform fee or existing venue/business revenue. The server derives the correct category from original ownership, method, source and creation channel (owned platform/online/on-site; external booking/membership fee or online/arrival/manual share).

Staff-created bookings remain 100% venue revenue. Cross-business reassignment, refund-state changes and invented fee rates are not supported. The exact selected amount is reclassified; no receipt amount, currency, Stripe reference, original saved fee split or business assignment is overwritten. A corrected fee rate is shown as unrecorded, rather than keeping an inaccurate historical percentage.

Corrections live in `payments.financial_context.allocation_corrections[row_id]`, with audit ID and a hash of original allocation evidence. Apply them **after** saved splits and payment review decisions. Reassignment can be corrected back using another audited action. Changing financial evidence later makes the correction Needs review. A later legitimate payment status/date change preserves the allocation correction.

## API and audit

`GET /api/admin/payments/bulk-selection` accepts the same filters as the payment report, ignores pagination, and returns `rows: [{id, booking_id, subscription_id}]` and `allocation_count`. It is independently restricted to platform superadmins. It uses the same cached, invalidated report facts and the same predicate as the table, including checkout search, venue/listing, business, dates, scope and amounts. Fresh evidence is always loaded for preview/confirmation.

`POST /api/admin/payments/bulk-preview` and `POST /api/admin/payments/bulk-action` accept up to 500 exact allocation IDs per request:

```json
{"row_ids":["123:platform","124"],"action":"reassign","destination":"venue_revenue","reason":"Checked the original venue payment records."}
```

For `mark_paid`, omit destination and include `"method":"cash"`, `"card"`, `"cheque"` or `"wire_transfer"`. Online methods and missing/unknown methods are rejected. Confirmation must include the returned `fingerprint`. All three endpoints authorize platform-superadmin identity independently of UI and reject providers carrying a superadmin role. No request-supplied business, amount, status, fee or actor is trusted.

The preview returns exact before/after rows, exclusions and currency-separated changes to totals. Confirmation locks bookings, memberships and sibling payments, refreshes the evidence and checks the fingerprint. A stale preview fails; a duplicate confirmed fingerprint returns its existing audit ID. All changed rows and their audit entries **within one batch** commit in one transaction. Source facts and sibling classifications are loaded/grouped in bulk rather than issuing a query per selected receipt. The collection service's compact response avoids rebuilding the entire booking modal for every bulk receipt; it preserves collection, state, history and audit logic.

Migrate `2026_11_16_000001_create_payment_bulk_actions.php`. Preserve append-only `payment_bulk_actions` and `payment_bulk_action_items` through copies, backups and go-live. Payment details expose actor, reason, time and before/after history only to platform superadmins. Providers see corrected financial allocations within their existing ledger scope, not private audit evidence.

## Mandatory release checks

- `PaymentBulkTest.php`: role denial (including all-filtered selection), scope/privacy, stale data/methods, selection beyond 500, filter parity, partial split exclusion, all four collection methods and normalization, before/after audit, batch replay/failure, source changes and runtime gate.
- `paymentBulk.test.mjs`: checkout allocation selection, partial-filter selection, pagination, 27,586-row selection, deduplication, dependency-preserving batches and exact currency aggregation.
- P20 is mandatory in policy validation and signed regression evidence. The runtime payment release gate also checks the audit schema. Old nineteen-rule evidence cannot release this version.
- Railway is the isolated preview workspace. Do not disable production checks or reuse preview evidence for the future live database.
