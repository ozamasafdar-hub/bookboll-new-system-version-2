# Shared online payments

Implemented 11 November 2026. Required policy: `P17_SHARED_ONLINE_PAYMENTS`.

## Interface and data contract

Payments requests `GET /api/admin/payments/dashboard?report_version=2&group_by=stripe`. One shared Stripe payment appears as one expandable entry. Each booking retains its own online allocation, recipient, status and detail action. Separate arrival balances appear in the breakdown as context, explicitly excluded from the online amount; those records remain individually listed in the ledger. The details drawer explains when an entry is a portion of a shared payment.

Example: bookings `BKN_2026_11_00420` and `BKN_2026_11_00421` share a QAR 45 charge, split QAR 25 and QAR 20. Their QAR 100 and QAR 80 arrival balances belong to the provider. Never present QAR 225 as the online receipt, or count the QAR 45 parent on top of its children. The source snapshot has pending arrival balances; reviewed legacy reconciliation marks eligible balances collected in the workspace. Sharing a Stripe charge does not prove cash collection.

- This is presentation only. No new payment, database merge, charge, settlement or reconciliation is performed. Preserve individual IDs, amounts, dates, statuses and processor references.
- Scope all rows to the actor first. Group online booking allocations by recorded charge ID and currency. An intent-only row may join the unique recorded charge for that intent. Multiple charge attempts remain separate; missing identifiers remain separate. Never guess from customer, timing, amount or receipt URL.
- Conserving fee/share children inherit processor identity only from their single authorized receipt. The classifier excludes the gross parent before grouping its allocations. Do not infer identity across ambiguous multi-receipt links.
- Apply allocation filters and calculate summaries first, then group before sorting/pagination. Group amount sorting uses the displayed subtotal; date sorting its latest included date, with original receipt IDs preserving same-day order. Different statuses display `Mixed statuses`. `payments.total` counts displayed entries and `payments.allocation_total` counts matching allocations. Summaries must match flat mode exactly.
- A filtered subset returns `partial: true`, only matching online children, and `Matching allocations only`. Provider views label amounts as visible to that business. Never claim a visible subtotal is the processor's full charge, nor expose another business's records, amounts or counts. Restricted staff/country scoping still applies.
- Context arrival balances can be outside filters but must be authorized and clearly excluded from the parent and summaries. They are also individually listed. Do not turn contextual balances into duplicate receipts.
- CSV explicitly requests `group_by=allocation`, exports each individual allocation once, and never exports synthetic parents. Existing clients omitting `group_by` retain flat pagination.
- Receipt/refund actions resolve through a real authorized child and existing server preflight. Only platform admins/superadmins can refund. Refund the full original processor charge, not just a displayed child's amount; arrival balances remain unchanged. Partial and provider group headers have no refund action. Child detail actions still require complete charge scope and preflight.
- Cache identity changes with processor enrichment. Preserve actor/business/venue scoping and committed-mutation invalidation. Never share a provider's grouped result with another provider.

## Mandatory acceptance

1. QAR 25 + QAR 20 shows one QAR 45 entry even with a one-entry page size. Arrival records remain QAR 100 and QAR 80.
2. Filter one payment ID, amount, date or status: only matching online allocations count; partial labels appear; flat/grouped summaries match.
3. Full online receipts with external fee/share children and saved own-venue allocations count once. Check currencies, rounding, refunds and mixed status.
4. Different charges, ambiguous intent attempts, missing processor references and different currencies do not collapse into one claimed charge.
5. Providers/restricted staff cannot discover other businesses' bookings, amounts or balances through grouping, details or refunds.
6. Full-charge refund preflight still checks permissions, processor amount, replay/idempotency and refund state. Use fake/test-mode processors only.
7. CSV remains complete and flat; collapse/expand, keyboard activation and dark/light styling work.

Tests: `PaymentReportDashboardTest.php`, `PaymentDetailsAndRefundTest.php`, `PaymentReleaseGateTest.php`, `paymentLedgerEntry.test.mjs`. GitHub's payment workflow runs them. Signed `financial_regression.passed_rule_ids` must include **P17_SHARED_ONLINE_PAYMENTS**; old 16-rule evidence cannot unlock this release. Production must still compare summed booking allocations with actual processor charges independently. UI grouping is not processor certification.
