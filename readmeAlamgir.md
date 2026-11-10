# Payments rebuild — implementation and release checklist

Updated 10 November 2026. This document concerns the Payments page in the platform and provider dashboards. The separate Railway preview described below uses a reconciled copy of the repository dump. No migration, payment, payout or historical rewrite was performed on the existing hosted newbackend system.

## Isolated Railway preview (10 November)

The owner requested a new Railway project to see Payments analytics with the repository data. Project: **Bookboll payments preview**, environment **preview**, services **bookboll-admin** and private **payments-db** (MariaDB 10.11 with persistent storage). Admin URL: https://bookboll-admin-preview.up.railway.app/payments. This is a separate snapshot, not the existing newbackend service.

Deployment uses `deploy/payments-preview/Dockerfile`: build the real Vue admin, install the locked PHP dependencies, import the 4 November SQL once into `bookboll_preview`, apply only the two Payments migrations, then run `payments:prepare-preview`. The import refuses to overwrite a database containing data without a completed-import marker. The reconciliation command is restricted to `PAYMENTS_PREVIEW=true`, database name `bookboll_preview`, and a separate strong administrator password; it records a completion marker and does nothing on subsequent starts.

Owner-confirmed mapping, applied only to this preview:
- Keep venue IDs **1** Abu Hamor (Bright Future International School), **2** Abu Hamor MPH (Bright Future International School), and **3** Al Sadd (Tariq Bin Ziad Primary School) as Bookboll-operated.
- Preserve the existing external business assignments, including owner-linked businesses where business_id was previously empty.
- Create ownerless external businesses for IDs **7** Pickup Location - Adventure Sports, **11** Target Snooker & Billiard - Ain Khaled, and **14** Collab Connect.
- Restate historical ownership using this explicit owner confirmation. Preserve full before/after JSON for venues, bookings and payments in `preview_reconciliation_audit`; retain all **19,057 bookings and 22,391 original payments**. Additional internal allocations are not additional receipts.
- Recover previously renamed deposits from `venue_transfer_allocations`, so the original deposit becomes one platform fee rather than an unmatched fee or duplicate receipt.
- For eligible external historical arrival balances before **10 November 2026**, apply the owner's provider-direct cash assertion. Do not touch cancelled/refunded/disputed/failed/skipped records, or turn pending online payments into cash. The original payment/service date is a proxy when the true collection date is absent, explicitly labelled in financial_context; it is not independent evidence of the exact cash collection date.
- Staff-only imported payment options (`paid_cash`, `paid_cheque`, `paid_credit_card`, `paid_wire_transfer`) establish manual booking origin in the preview context; retain original imported origin in audit. Manual bookings have no platform fee.
- Persist own-venue internal splits only from actual retained deposits/arrival balances or stored booking quote amounts. Confirmed own receipts without a defensible split use `owned_unallocated`: included ONCE in combined Bookboll received, excluded from platform/venue sub-totals, shown as **split needs review**. Other unclassified receipts stay out of earnings.

Local rehearsal totals across all dates (QAR): Bookboll received **824,463.40**, platform fees **45,985.40** (external booking fees **23,490.80**, internal own-venue fees **22,494.60**), assigned own-venue revenue **552,933.00**, and confirmed own receipts awaiting split **225,545.00**. These components conserve the received total. Own pending is **155,649.90**, including **123,471.00** with unresolved split. There are **22,700 report allocation rows** and **6,014 rows requiring review**; allocation count differs from receipt count. PKR is reported separately. These are operational snapshot figures under the stated historical assumptions, not audited final revenue or profit.

Access: only a new dedicated preview administrator may sign in; copied user passwords and copied access tokens cannot access the app. API mutations are blocked except sign-in/sign-out. No live Stripe keys, mail delivery, queue worker or scheduler are configured. The database has no public endpoint. The snapshot file is outside the web root. Credentials are stored in Railway variables and a private local handoff file, never in this repository.

Transfer reconciliation now returns `reconciliation_version: 2`, individual payment changes (first 50), a paid-provider-receipts subtotal, and accurate historical contexts for v2 reporting. The UI refuses legacy reconciliation on older servers. Pending online payments are excluded from cash reconciliation; already-paid manual receipts remain entirely provider revenue. Preview staleness covers payment/booking facts; retries are bound to actor, venue and preview token. Historical corrections preserve original data and do not send new-payment notifications or generate payouts.

Latest validation: **70 database tests / 281 assertions** across report, transfer, recipient backfill, preview access, booking payment-method access, commission snapshots, deposits and loyalty funding; **65 standalone rule assertions**, **12 frontend tests**, and the admin production build passed. Local full-snapshot reconciliation, authenticated HTTP report generation and restart idempotency succeeded. Original receipts remain 22,391; 10,642 internal allocation records and 41,456 preview audit entries were created. Real Stripe charges and deployment to the existing newbackend host remain outside this preview.

## What is implemented

- `GET /api/admin/payments/dashboard?report_version=2` uses `PaymentReportService`, `PaymentReportRules`, `PaymentReportFilter` and `PaymentReportTotals`. Existing callers without the version parameter retain the old response.
- The dashboard requests v2 and renders separate platform/provider summaries, source breakdowns, custom dates, multi-select filters, recipient scopes, pagination, transaction details and filtered CSV export.
- A server without v2 still exposes its records for inspection. The frontend deliberately hides its unreliable earnings totals and disables allocation exports/collection shortcuts. Do not remove this guard to make numbers appear.
- New bookings and payments capture `financial_context` after the included migration. Own-venue receipts additionally persist individual `payment_allocations` rows for the platform and venue business units; these are not extra receipts or payouts. Snapshots record operator, business, channel and the quoted fee, when present. Settlement allocations link to source receipt IDs. Event rates are distinct from court commission rates.
- Booking and hosted-event online settlement now pass receipt IDs to the fee/share ledger writer. Owned venues bypass provider-wallet credit. Manual provider bookings use zero platform commission in the QR settlement path.
- Staff booking options explain skip, arrival and already-paid records; prepaid choices require the existing `accept_payments` capability. Card at the venue is distinct from an online Stripe payment.
- The report scans the complete authorized payment set in bounded batches. It does not sum the visible page or impose the old 5,000-booking analytics cap. The separate Analytics activity endpoint is not replaced by this payment report.

## Authoritative business rules

All percentages below are examples. Report the amount actually recorded, in its original currency. Never calculate historical earnings using today's country, city, venue, plan or commission settings.

| Situation | Individual records | Bookboll dashboard | Provider dashboard |
|---|---|---|---|
| External business, marketplace, deposit + arrival; sale 100, fee 15 | Bookboll fee 15 online; provider balance 85 pending/paid at venue | Fee 15 received; 100 sales delivered. The provider's 85 is not Bookboll pending or revenue | Fee 15 separately; provider received/pending 85 according to collection status |
| External business, own website, deposit + arrival; sale 100, fee 7.50 | Same split with actual own-site rate/fee | Own-website fee 7.50, not marketplace fee | Own 92.50 balance plus fee disclosure |
| External business, full online; sale 100, fee 15 | Gross receipt 100 linked to fee allocation 15 and provider allocation 85 | Bookboll received 15; sales delivered 100 | Online share received 85; fee 15 separate |
| Bookboll-operated venue, customer booking from marketplace/own website | Retained deposit is platform fee; its arrival balance is venue revenue. Full online/full arrival receipt splits into recorded internal fee plus venue share | Platform credited its fee; venue revenue shown AFTER that fee; combined Bookboll total counts the receipt once. Pending platform and venue shares separated | Internal business units, not an external payout |
| External provider's staff-entered booking | Full receipt/balance belongs to that provider; fee zero | No Bookboll earnings and not a marketplace-generated sale | Own manual-booking receipts and unpaid balances |
| Bookboll staff's booking at a Bookboll venue | Full receipt/balance belongs to Bookboll | Own on-site/online/pending according to recorded method and status | Not external provider income |
| Skip payment | No payment row | No earnings or pending amount inferred from booking price | No earnings or pending amount inferred |
| Hosted event seat fee | Separate Bookboll event-fee row, using actual captured fee; often 10% but not hardcoded | Event host fees, independent of the court booking | Not provider court income |

The operator of the facility determines ownership; the role of the staff member entering a booking does not. A Bookboll-operated facility may have a business entity. An external business may have no owner account yet. Do not equate either condition with ownership.

“Bookboll received” = paid provider booking fees + paid provider membership fees + paid event fees + paid owned internal fees + paid owned online venue share + paid owned on-site venue share. “Platform fees” includes external provider fees, own-venue internal fees and event fees. Own venue revenue excludes its internal fee. Manual staff bookings have zero internal fee, as confirmed by the user. These are collected receipts before landlord rent, landlord revenue share and other expenses, not accounting profit.

Provider arrival balances never create a debt from Bookboll to the provider. The provider collects those balances directly. A paid provider online allocation means customer payment income allocated to the provider, not proof that a Stripe bank payout completed.

## Record contract and historical accuracy

Migration: `bookboll-BACKEND/database/migrations/2026_11_10_000001_add_payment_financial_context.php` adds nullable JSON columns to bookings/payments. `2026_11_10_000002_create_payment_allocations_table.php` adds the durable own-venue platform/venue allocation rows. Apply both before deploying writers. Back up first. Rolling it back removes historical snapshot data; do not roll it back casually.

New `financial_context` fields: `version`, `operator` (`bookboll`/`provider`/`unknown`), `business_id`, `business_name`, `provider_recipient`, `channel` (`marketplace`/`site`/`admin`/`unknown`), `fee_rate`, `fee_minor`, `net_fee_minor` (after platform-funded loyalty), `total_minor`, `currency`, `payment_method`, `merchant_share_minor`, geography/venue IDs, and `recorded_at`. Memberships also carry `program_id`; event fees carry `event_fee_rate`. Settlement children carry `receipt_ids` identifying gross receipt payment IDs.

These fields are server-owned. Do not accept ownership, recipient, historical fee or receipt links from a browser request. Preserve snapshots on later venue transfers, plan changes and edits. Record audited corrections explicitly. Do not rewrite all previous records just because a venue is now assigned to a different business.

The report reconciles all sibling payments before applying status, date or recipient filters. For a conserving split, the gross receipt is a parent, not another earning. Default rows/totals contain the allocations. Children follow the parent receipt's full-refund status. A legacy pair without IDs is accepted only with exactly one unambiguous gross receipt, one fee and one share that sum to the receipt in the same currency. Direct membership allocations are supported without a redundant gross receipt.

Unclassifiable ownership, missing currency, invalid amount, impossible fee on a manually added provider booking, or a nonconserving/ambiguous split produces **Needs review**, excluded from monetary totals. Null recipient is never automatically Bookboll. Historical rate missing means “Not recorded”, never a guessed percent. An event fee on a free/skipped court reservation remains an independent event charge.

### Historical reconciliation required before calling totals complete

1. Export a read-only inventory grouped by operator snapshot coverage, channel coverage, raw type/method, null recipient, missing currencies, unmatched splits and cancelled/refunded records. Preserve IDs and original values.
2. Use authoritative historical ownership/transfer dates and transaction evidence. In the legacy arrangement confirmed by Osama, Bookboll kept external-provider online deposits; external providers took arrival money themselves. Bookboll staff collected owned-venue money. Do not infer all of this from payment method alone.
3. Produce a dry-run report of each proposed snapshot/recipient fix with old/new value and reason. Obtain review of the affected bookings before committing a batch. Use an idempotency key, audit log, checkpointed batches and a reversal record.
4. Old hosted-event arrival rows were incorrectly labelled `online`; the new writer fixes this. Review historical rows and use the stored booking's arrival plan/collection evidence before changing their method. Do not mark unpaid money paid to make reports balance.
5. Missing full-online allocation records must be reconstructed from processor settlement/application-fee evidence, never current commission settings. Leave unverifiable records in review.
6. Confirm business identity for subscriptions and ownerless businesses. New snapshot capture is implemented; historic subscription rows can still lack a named business or channel. Their explicit fee/share amounts remain visible, with unknown metadata honestly labelled.
7. Test transferred venues carefully. Current venue/program authorization and frozen business IDs restrict report visibility. A former provider's history after transferring its last venue needs a deliberate historical-access policy; do not broaden access automatically or expose another business's customer records.

## API / filtering

Response data contains `report_version: 2`, server-derived `perspective`, a `payments` paginator, `summary` array (one entry per currency), `review_count`, and `filter_options`.

Supported query filters: search; CSV multi-select status/method/category/channel/currency/source/location_id/business_id; scope all/bookboll/provider/review; inclusive from_date/to_date; amount_min/amount_max including zero; sort date/amount/status/method and sort_dir; page and records_per_page (1–250). Default date is paid_at, then date, then created_at. Unpaid rows use their recorded date. All totals and table rows use the same filters across the entire authorized set; changing filters resets page one. Currencies are never added together or silently converted.

Row fields include actual amount/currency, payment_type, recipient_kind/label, category, operator, channel, fee_rate, payment_date, row_kind, review_reason, snapshot_recorded, booking/customer/venue labels, and safe Stripe document URLs. Category names are listed in `PaymentReportRules::CATEGORIES` and frontend `paymentReport.js`.

Pagination retains the sorted window through the requested page, and classification currently scans authorized payments each request. Before high-volume rollout, profile this on staging and move the same semantics to indexed/materialized allocations or an aggregate query with consistent snapshots. Do not optimize by fetching 5,000 records or filtering only one page. Long CSV exports need a repeatable-read server export or queued snapshot to protect against edits between pages; the current frontend rejects count changes/duplicate rows and never downloads a partial failed export.

## Release gates and remaining backend work

- Run the Laravel integration suite on an isolated MySQL `bookboll_test` database. Local validation now includes the payment report, allocation persistence, historical recipient, permission, commission and loyalty database tests. No database migrations, charges, refunds or bank payouts were executed against the hosted system.
- Confirm actual Stripe Connect transfer/application-fee behavior with Stripe test mode. Existing wallet `withdrawn/completed` flags alone do not prove funds reached a connected account. This change does not introduce a new Stripe Connect integration or move funds.
- Verify all payment creation paths (web, mobile, QR, hosted events, memberships, staff booking and transfer reconciliation) write the same recipient/context and receipt links. Ownerless external businesses currently cannot use the old owner-user-keyed wallet credit path: require a valid connected business account before offering full online payment, or route to reconciliation; never retain their gross as Bookboll earnings.
- For legacy bookings lacking commission snapshots, settlement still has an older current-rate fallback in existing services. Replace that fallback with audited stored quote/processor evidence or explicit review before charging/allocating old balances. The new reporting service never recalculates a rate.
- Implement auditable **partial** refund/reversal allocations. This report handles full-refund statuses but is not a period cashflow ledger for refund-date reporting, chargebacks, processor fees or historical as-of balances. Do not advertise it as one. Add explicit adjustment records and tests before enabling those totals.
- Reconcile membership manual-charge legacy paths and automatic renewal jobs with processor evidence. An existing `paid` row is treated as recorded paid, not independently verified with Stripe on each report request.
- Existing booking detail/due/reprice code still has raw sums of `payments.amount`; audit those separately to exclude allocation children and event fees from the court balance. The Payments v2 report avoids the double-count but does not replace every legacy financial endpoint.
- Existing Analytics activity needs server aggregates for complete totals beyond its loaded booking cap. Reuse the payment rules for Payments earnings, and keep sales value, customer activity, landlord costs and receipt totals distinct.

## Acceptance examples and verification

Local validation on 10 November: **50 database integration tests passed** across `PaymentReportDashboardTest` (9), `PaymentRecipientBackfillTest`, `BookingPaymentMethodsAccessTest`, `CommissionSnapshotAndLedgerTest`, `LoyaltyVenueBookingFundingTest`, and `DepositFollowsSurfaceAndCommissionTest`. The standalone report suite passed **65 assertions**; all **5 frontend helper tests** passed; the admin production build passed with existing bundle-size/import warnings. Browser QA verified the saved internal fee, original receipt and paired venue allocation using clearly labelled synthetic data. These checks do not certify hosted deployment, historical reconciliation, Stripe Connect transfers, or every event/mobile/membership creation path.

Run from `bookboll-BACKEND`: `php tests/payment-report-rules.php`. It uses no database/network. Cases cover marketplace/own-site deposit, full split conservation, refund inheritance, ambiguous splits, owned online/on-site/pending, provider manual income, skipped records, event fees, direct membership allocations, frozen ownership/rates, mixed currencies, date boundaries, zero amount filters and recipient/source filters.

Run from `bookboll-new-system/admin`: `node --test tests/paymentReport.test.mjs` then `npm run build`.

On an isolated test database with Composer dependencies installed, run `php artisan test --filter=PaymentReportDashboardTest` and `php artisan test --filter=PaymentRecipientBackfillTest`. These integration tests now run locally using PHP 8.4, the locked Composer dependencies and a separate MariaDB instance on loopback port 3308. Never point the test suite at the hosted database. Payment date sorting is performed after receipt reconciliation so late allocation rows appear under the original receipt date.

The optional isolated browser harness uses the real Vue page and the real pure PHP rules/filter/totals, with synthetic records and no live API. Set `PAYMENT_TEST_PHP` to your local PHP executable and run `node tests/payment-preview/server.mjs`. Open `http://127.0.0.1:5186/tests/payment-preview/index.html`. It is explicitly labelled QA and binds only loopback. It is not part of the normal application entrypoint.

Expected harness totals (QAR): platform Bookboll received **470**, platform fees **87.50**, owned venue revenue **382.50** (all on site in this fixture), owned pending **80**, sales sent to providers **300**. Provider received **335**, arrival received **0**, pending **85**, Bookboll fees **30**. The external own-website receipt yields fee **15** and provider online share **185**. A separate own-venue QAR 100 arrival receipt yields platform fee **7.50** and venue share **92.50**. This checks that the full receipt 200 is not counted again alongside its 15+185 allocations.

Before deployment, also verify authorization for super admin, country admin, platform staff, provider owner, pinned staff, ownerless-business staff and a user without accept/export capability; unauthorized business/venue IDs in filters must never broaden access. Test fresh booking saves for all six staff payment choices on the isolated database, webhook replay, transfer history, failed/empty API responses, date filters, export consistency and refunded receipts. Migration and deployment must be done by the backend owner against the intended environment after these gates pass.

## Own-venue business-unit allocation rules (confirmed 10 November)

- For customer bookings, deposit is platform fee, deposit-booking balance is venue revenue, and full online/full arrival is split using the saved fee amount. The payment method does not decide which business unit earns the fee.
- Manual Bookboll staff bookings remain 100% venue revenue. External manual bookings remain 100% external provider revenue. Skip still creates no receipt.
- Each internal allocation stores payment_id, bucket (platform/venue), integer amount_minor, currency, fee_rate and basis. A unique payment/bucket key prevents duplicate rows. One original payment remains the collected receipt, so legacy booking balances do not count these internal allocations again.
- Allocation rows inherit payment status/date; an unpaid receipt contributes only to pending shares, and a full refund removes both shares from received totals. Replayed status changes do not create more allocations. A changed receipt amount/currency that no longer matches its saved allocations is flagged for review rather than silently reallocated.
- Hosted-event court payments follow the same internal split; the separate event host seat fee is not included in the court fee base. Loyalty is funded by the platform fee; preserve the venue share.
- Old own-venue receipts without allocations are NOT automatically split with today's rates. Reconcile the saved quote/deposit evidence and historical operator first, then persist audited allocations. Bulk reconciliation is limited to the isolated owner-authorized preview described above; none was run on the existing hosted system.

## Environment verified and what the developer needs to supply

The admin already uses https://newbackend.bookboll.com/api. A read-only unauthenticated request to the known dashboard endpoint returned HTTP 401, confirming reachability and authentication enforcement. API access allows use of deployed code; it does not deploy local PHP changes or run migrations.

The repository contains bookboll_live_with_yopmail_testing_data.sql, a 69 MB MariaDB dump generated 4 November 2026. It has been imported into a separate LOCAL bookboll_snapshot database, while tests use the separate bookboll_test database. Counts: 22,391 payments; 19,057 bookings; 1,306 bookings with recorded commission rate/fee; 6 currently Bookboll-operated venues and 12 external venues. These are snapshot counts, not live production counts. The complete v2 report was exercised read-only over all 22,391 records; all require historical allocation review under the new ownership/allocation contract. No current venue owner was used to rewrite historical ownership.

Nothing further is needed from the developer to run the local database tests. For hosted rollout, supply or perform:
1. Confirm whether newbackend is staging or live and whether the 4 November dump is the latest usable test snapshot.
2. Provide the deployment workflow/access (hosting/SSH or CI and deployed branch), or have the developer deploy these PHP changes and both migrations with a backup. The API URL alone is not deployment access.
3. Supply authoritative historical venue ownership/transfer dates and fee evidence for older bookings without snapshots. The dump also contains booking_commission rows but no booking_earning rows, so external full-online splits need their original receipt/allocation evidence reconciled before totals can be called complete.
4. Separately provide a Stripe TEST-mode setup/Connect test accounts for end-to-end processor verification; live charges are not needed for local allocation/report tests.
