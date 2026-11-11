# Payments and Analytics must agree

Updated 11 November 2026. Required rule: **P21_PAYMENT_ANALYTICS_PARITY**.

## Implementation

`GET /api/admin/payments/analytics` uses `PaymentReportService::dashboard(analyticsOnly: true)`. It shares the authorized, cached, classified allocation dataset, `PaymentReportFilter`, and `PaymentReportTotals` with Payments. `PaymentReportAnalytics` accumulates the same totals for each time period, channel, method and venue. It does not recalculate fees from current rates, sum booking totals, use the 5,000-booking activity sample, fetch Stripe, or change records.

The Analytics page keeps **Bookings & activity** as its default. **Payments & revenue** uses the new endpoint; opening `/dashboard?tab=payments` avoids the older activity dataset request. Both views have independent filters. The Payments link opens the full payment records page with its own filters. Monetary cards and recipient definitions are shared in `paymentMetrics.js`. The old provider Wallet Available card is removed from booking activity; booked value is explicitly distinct from received revenue.

## External businesses: three different measures

| Field | Meaning |
| --- | --- |
| `provider_total_sales` | Every classified external-business paid + pending allocation, including staff bookings, fees and provider shares once. Excludes refunded/cancelled/skipped/unresolved records. |
| `provider_received` | Paid amounts belonging to external businesses, including staff-added bookings. Excludes Bookboll fees. This is the external panel headline. |
| `provider_manual` | Paid staff-added business revenue. It is a **subset** of `provider_received`; never add it again. |
| `provider_pending` | Unpaid provider share. Not a debt owed by Bookboll. |
| `provider_sales` | Customer booking sales from marketplace and provider websites, including deposits and arrival balances. Deliberately excludes staff bookings; preserved under the explicit label “Sales from marketplace & websites”. |

Bookboll received remains paid platform fees + paid own-venue share after fees + confirmed own receipts awaiting a split, counted once. Staff-created venue bookings have no fee. Existing event host charges remain separate. Own revenue is **before landlord and other operating costs**, not profit.

## Dates, charts and scope

- Paid rows use the same recorded/inherited payment date as Payments. Pending rows use their recorded date. Custom dates include both endpoints. Last month uses calendar-month boundaries.
- Trends are daily up to 120 days and monthly for longer spans. Empty periods are zero-filled. Undated allocations remain in totals and are disclosed as excluded from the timeline.
- Refunds follow the current Payments status-based report: refunded amounts are excluded from sales/received. This is **not** a refund-date cash-flow report or settlement/bank-payout statement.
- Currency amounts remain separate. Charts and tables choose one currency; there is no implicit conversion or combined currency total.
- All money calculations use `PaymentReportTotals` and minor units. Each channel/method/venue breakdown must sum back to the matching currency summary. Timeline sums match except for explicitly disclosed undated amounts.
- Venue/listing/business/status/method/source/channel/currency/date filters run after sibling classification, before all aggregates. Listings follow selected venues. Frozen operator `bookboll` differs from a Bookboll fee on an external venue.
- Authorization is identical to Payments (`accept_payments` plus `AccessScope`), including provider history after transfers. Foreign filter IDs cannot expand access. No refund or edit privileges are added.
- Cache invalidation is shared with Payments. Refresh re-reads the same scoped source. Analytics returns no paginated payment/document payload and makes no processor calls.

## Required acceptance before production

Run `PaymentReportDashboardTest.php`, `PaymentReportCacheTest.php`, `PaymentReleaseGateTest.php`, the pure payment rules and admin payment tests. The GitHub payment workflow includes these suites. Runtime release policy and CI require **P21**; signed production evidence omitting it is rejected.

1. Compare Analytics and Payments summaries exactly for all time, last month, custom dates, paid/pending/refunded, method, channel, provider, own business, venue and listing filters.
2. Include own/customer internal splits, own/manual zero-fee receipts, external retained deposits, external full-online receipt/fee/share, external staff paid and pending, memberships/events, shared multi-slot checkouts and multiple currencies.
3. Confirm staff receipts increase external total sales and received, never platform fees; pending staff receipts increase sales/pending only. Confirm skipped/no-record bookings contribute nothing.
4. Reconcile every chart/table back to totals; verify no raw receipt plus allocations double count. Verify zero days/months and invalid/reversed date rejection.
5. Prove provider/country/venue-restricted access, frozen historical ownership, foreign-filter rejection, and missing-capability rejection.
6. After collection, review, allocation correction or refund, refresh both screens and compare again. Do not use changing screenshot totals as a hard-coded production target.

This release adds read-only reporting; no schema migration or historical payment rewrite is needed for these analytics changes.
