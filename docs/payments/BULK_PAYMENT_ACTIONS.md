# Superadmin selected-payment changes (P20)

The Payments table lets a **platform super_admin only** select entries after filtering, keep selections across pages, and preview an action. The header checkbox selects this page, not every matching record. Changing filters or refreshing clears selection. A checkout checkbox selects its currently visible allocation IDs; it never expands a filtered checkout into hidden bookings. Maximum: 500 allocation IDs per action.

## Mark pending as paid

- Record an actual offline collection for an active venue booking, using the receipt's saved cash/card/cheque/transfer method, today's payment date, current collector and booking collection logic.
- Already paid, online/Stripe-referenced, cancelled, skipped, refunded, unclassified or unsupported records are listed as excluded in the preview. They are not silently converted.
- Every allocation of an underlying receipt must be selected and conserve that receipt before its status can change. A filtered venue share cannot also collect an invisible platform fee.
- Multiple selected allocations of one receipt cause exactly one collection. The booking payment state and collection history are updated through the existing collection service. No new charge, refund or payout is initiated.

## Change allocation / recipient

The owner confirmed **existing business only**. Choose Bookboll platform fee or existing venue/business revenue. The server derives the correct category from original ownership, method, source and creation channel (owned platform/online/on-site; external booking/membership fee or online/arrival/manual share).

Staff-created bookings remain 100% venue revenue. Cross-business reassignment, refund-state changes and invented fee rates are not supported. The exact selected amount is reclassified; no receipt amount, currency, Stripe reference, original saved fee split or business assignment is overwritten. A corrected fee rate is shown as unrecorded, rather than keeping an inaccurate historical percentage.

Corrections live in `payments.financial_context.allocation_corrections[row_id]`, with audit ID and a hash of original allocation evidence. Apply them **after** saved splits and payment review decisions. Reassignment can be corrected back using another audited action. Changing financial evidence later makes the correction Needs review. A later legitimate payment status/date change preserves the allocation correction.

## API and audit

`POST /api/admin/payments/bulk-preview` and `POST /api/admin/payments/bulk-action` accept:

```json
{"row_ids":["123:platform","124"],"action":"reassign","destination":"venue_revenue","reason":"Checked the original venue payment records."}
```

For `mark_paid`, omit destination. Confirmation must include the returned `fingerprint`. Both endpoints authorize platform-superadmin identity independently of UI and reject providers carrying a superadmin role. No request-supplied business, amount, status, fee or actor is trusted.

The preview returns exact before/after rows, exclusions and currency-separated changes to totals. Confirmation locks bookings, memberships and sibling payments, refreshes the evidence and checks the fingerprint. A stale preview fails; a duplicate confirmed fingerprint returns its existing audit ID. All changed rows and their audit entries commit in one transaction.

Migrate `2026_11_16_000001_create_payment_bulk_actions.php`. Preserve append-only `payment_bulk_actions` and `payment_bulk_action_items` through copies, backups and go-live. Payment details expose actor, reason, time and before/after history only to platform superadmins. Providers see corrected financial allocations within their existing ledger scope, not private audit evidence.

## Mandatory release checks

- `PaymentBulkTest.php`: role denial, scope/privacy, stale data, exact selection, splits, audit/replay, collection, source changes and runtime gate.
- `paymentBulk.test.mjs`: checkout allocation selection, partial-filter selection, pagination, deduplication and batch limit.
- P20 is mandatory in policy validation and signed regression evidence. The runtime payment release gate also checks the audit schema. Old nineteen-rule evidence cannot release this version.
- Railway is the isolated preview workspace. Do not disable production checks or reuse preview evidence for the future live database.
