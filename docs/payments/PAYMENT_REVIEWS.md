# Payment review actions

Updated 11 November 2026. Required rule: **P19_PAYMENT_REVIEW_ACTIONS**.

## User flow

Payments → Needs review → **Review payment**. The details panel shows the original status, booking status, collection date, processor reference and connected transactions. Choose an eligible action, enter the evidence/reason, then **Preview action**. The preview shows the resulting allocation and the exact changes to Bookboll received, platform fees and provider received. Only **Confirm action** saves it.

Available actions depend on saved facts:

| Action | Eligibility and effect |
| --- | --- |
| Confirm no money received | A single cancelled/uncollected record with no saved paid date, collector, Stripe charge/intent/document, refund or allocation. Keeps the original record, marks its report category “Cancelled · no money received”, excludes it from all monetary totals and removes it from Needs review. A cancelled booking alone never proves a paid receipt was uncollected. |
| Confirm retained online deposit | An unsplit paid full-online external-provider receipt with recorded customer provenance and historical business. The administrator confirms the entire amount was actually retained as Bookboll’s booking fee. Original receipt type/amount/status are preserved. |
| Record fee and provider share | Same paid external-customer eligibility. Enter the exact fee amount, not today’s percentage. Two report allocations conserve the original receipt in integer minor units. Provider income is attribution, not evidence of a Stripe transfer or bank payout. |
| Keep open with a note | Record missing evidence without resolving the payment or adding it to earnings. Use for unknown ownership, invalid source amounts, skipped-booking conflicts or inconsistent linked receipts; those require source-data correction. |
| Reopen this review | Remove the current report decision. Keep every earlier decision in history. The original classification and its reporting effects return. |

No bulk “mark paid”, arbitrary recipient change, status edit, charge, refund, Stripe transfer or deletion is performed by these actions. Staff bookings never acquire a platform fee through this form. Review notes do not bypass the release gate.

## Backend contract and preservation

- `POST /api/admin/payments/{id}/review-preview`: `action`, trimmed `reason` (10–1500 characters), `fee_amount` only for `provider_split`. Returns fingerprint, before/after allocations, per-currency effects and whether the record stays open.
- `POST /api/admin/payments/{id}/review`: same fields plus the exact preview fingerprint. Platform `admin`/`super_admin` only, and never a provider-associated identity, even with an admin role. Authorized ledger scope is checked for the selected receipt and all siblings.
- Apply migration `2026_11_15_000001_create_payment_review_decisions.php`. Append-only `payment_review_decisions` stores actor, action, reason, unique fingerprint, full source evidence, outcome and timestamp. The current decision is referenced in `payments.financial_context.review_decision`; original financial fields remain untouched. Booking history records the action.
- Preview identity binds actor, receipt, action, fee, reason, previous review and the source/booking/sibling evidence. Save locks the booking and sibling payments, repeats eligibility/fingerprint checks, writes audit and decision atomically, and invalidates report caches only after commit. Duplicate requests reuse the saved operation.
- Source changes invalidate the reviewed attribution automatically; original classification then applies. A still-unclassified record reappears in Needs review. This also protects changed refund/collection evidence. Admin history indicates stale decisions. Recheck the record after refund or source reconciliation rather than assuming old attribution remains valid.
- Report/list/detail/CSV use the same classifier. Review decisions do not enter provider-visible audit history. Providers see their authorized financial allocations, without resolution authority or private review notes.
- The three historically flagged cancelled Adventure Sports references are **not automatically approved**. The administrator must review evidence and confirm each. Their stored amounts must never become received revenue just to empty the queue.

## Mandatory acceptance before live deployment

GitHub CI runs `PaymentReviewTest.php`: admin/provider permission boundaries, immutable receipts, duplicate replay, stale plans, reopening, notes remaining unresolved, collection evidence blocking cancellation closure, original amount conservation, exact fee precision, provider reports, skipped/staff protection and release-gate enforcement. Frontend tests verify the review entry point and evidence form.

The policy and release gate require P19 and the audit table; signed evidence omitting P19 cannot unlock this release. Rehearse on the fresh live-database copy, include these append-only decisions in backup/restore and migration plans, and verify changed evidence reopens classification without erasing earlier decisions. The production reconciliation runner and signed release evidence remain separate requirements.
