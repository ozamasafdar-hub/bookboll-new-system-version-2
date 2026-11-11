# Booking records and activity (P22)

Bookings and Analytics → Bookings & activity share `Bookings.vue` and `GET /api/admin/bookings/dashboard`. `with_activity=1` includes full aggregates; `analytics_only=1` returns those aggregates without fetching a page of records. `BookingActivity` aggregates the same scoped, filtered SQL query used for the table. No browser sample or payment-row join determines a booking count.

## Definitions

- Matching records: every filtered booking record, including cancelled bookings and skipped-payment slot blocks. Membership calendar sessions are excluded; they remain in Calendar. Membership subscriptions and event participation remain accessible on their separate activity tab.
- Customer bookings: pending, confirmed or completed reservations, including staff-added bookings. Exclude cancelled, no-show, refunded, purpose-needs-review, skipped-payment and membership-session records. Pending does **not** mean collected or attended.
- Booking value: saved `bookings.total_price` once per eligible booking, grouped by its saved currency. Missing currencies remain unknown; never substitute today's venue currency. This differs deliberately from Payments GMV, which is based on classified paid + pending payment allocations.
- Booking customers: distinct identified customer IDs on eligible bookings. The shared `walkin@bookboll.local` account is not one returning customer. Frequency = identified bookings / those distinct customers. Repeat percentage = customers with more than one eligible booking / distinct customers. Both are period frequency measures, not lifetime cohort retention. Zero denominator is unavailable, not zero.
- Channel: immutable `creation_context.channel`, then the same explicit legacy fallbacks as `PaymentReportRules::channel`. Staff-added records are not relabelled marketplace because of the customer's identity or a later collector. Show the original creator separately from the customer.
- Booking status is the stored operational status. Show payment state independently; a historical pending booking with a paid deposit remains visible as such. This release does not silently confirm, cancel, collect or reconcile old records.
- Recorded receipt amounts reuse `BookingPaymentSummary` / `PaymentReportRules`, excluding gross parent receipts when fee/share children already account for them. No percentage is recalculated by activity reporting.

## Filters and access

All summaries and charts follow the same search, status, booking payment state, booking method, currency, purpose, channel, business, venue, listing, country and city filters. Periods include last month and inclusive custom dates. Date basis explicitly selects **reservation date** or **date booked**. Additional record-date bounds may intersect the main range. Reject invalid dates and reversed ranges.

Business means the venue's current operator. `bookboll` selects Bookboll own venues; numeric values are `businesses.id`, including businesses without owners. AccessScope enforces business/jurisdiction/venue pins, with listing pins applied to records, aggregates, exports, lookup options and CRM. Provider reports must also respect the frozen financial ownership boundary used by CRM after venue transfers. Booking activity is not permission to view a former operator's financial records.

Exports are explicit 1,000-row cursor batches through every matching record, with original creator, channel, purpose, saved currency and recorded receipt amount. There is no 20,000-row silent cutoff. A failed batch fails the export; it must not download an incomplete file. Export capability is enforced by the server. The browser hides stale rows/cards immediately on filter changes, errors or invalid ranges.

## Mandatory acceptance

1. Seed more than 5,000 bookings, spanning more than 400 days. Table total, aggregate coverage, channel/status totals and full CSV must reconcile. Eligible series and venue counts must reconcile to customer bookings. Long periods use monthly buckets without dropping history.
2. Add deposit + remaining + split allocations to one booking. It must still count as one booking, with one booking price. Payment analytics totals must remain unchanged.
3. Put a saved staff creation stamp on a legacy web-origin booking. Table, channel filter, export and activity charts must all say staff. Verify provider-site and unknown source independently.
4. Exercise each sorting field with status, dates and currency filters; joined customer/listing names must not produce ambiguous SQL columns.
5. Confirm an old PKR booking at a venue now configured for QAR remains PKR in the table and activity value. Never sum PKR with QAR.
6. Check custom-date boundaries and switching reservation date/date booked. Reject reversed or malformed ranges; do not retain old totals beneath an error.
7. Verify ownerless providers, provider staff with listing pins, foreign venue IDs, and old financial ownership after venue transfers. Direct API requests must enforce the same rules as the UI.
8. Confirm skipped-payment blocks, refunds, cancellations, membership sessions and unidentified walk-ins are handled as defined above. Three identified bookings by two people must give 1.5× and 50% repeat; adding a walk-in must not change that ratio.
9. Run `BookingActivityTest`, `BookingCrmCurrencyTest`, creator and payment report regressions, frontend booking export tests and the admin build. P22 is mandatory in the versioned policy and runtime release evidence.

No database migration or historical write is required by this reporting change. Deploy backend and frontend together. Existing live-money gates remain enforced; do not use screenshot totals as migration targets.
