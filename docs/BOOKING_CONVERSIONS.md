# Booking conversion contract

Approved 28 November 2026: Purchase reports **only the amount paid online**. A QAR 100 booking with QAR 15 paid now and QAR 85 due on arrival reports value 15, currency QAR. A full online payment reports the complete amount Stripe charged, including the provider's share. This is advertising conversion value, not Bookboll revenue.

## Event lifecycle

- `InitiateCheckout` / GA4 `begin_checkout`: after the customer checkout API creates a valid Stripe session, immediately before leaving the storefront for Stripe. Server line-item amount and currency are authoritative. Failed validation, free checkout and cash bookings send no online checkout event.
- `Purchase` / GA4 `purchase`: the thank-you page verifies the returned checkout session through the authenticated API. Stripe must report paid/complete, settlement must be processed and every booking must belong to this customer with a paid status. Pending, failed, admin/pay-link, old unversioned, refunded and partially settled records yield no conversion.
- `Schedule` / GA4 `booking_confirmed`: server-confirmed customer bookings, including cash on arrival and free/covered deposits. No value or currency is sent: confirmation is not payment. One stable event identifies the sorted booking set.
- `ViewContent`: successfully loaded listing or informational membership details. `AddToCart`: a manually selected slot or extension after its hold succeeds, using the added amount in the venue currency. Failed holds, deselection and host-slot prefills do not create cart events.
- `Contact` and `FindLocation`: explicit contact/directions clicks. `Search`: a submitted non-people search, without free-text query data. `CompleteRegistration`: successful password signup within the customer storefront. These never manufacture purchases, membership enrollments or card-entry events.
- Session metadata records the customer channel and business at checkout. Marketplace events use only marketplace tags; provider website events use only that business's tags, regardless of Free/Premium plan. Dashboard previews emit no events.
- The payment-intent hash is the Purchase event ID and GA4 transaction ID. It is shared by every booking in that charge. The session hash identifies checkout. Never loop over individual booking rows to send Purchase.
- Browser memory plus bounded local storage suppress refresh/revisit duplicates; Web Locks serialize same-origin tabs where supported. Stable IDs are also passed to the destinations. These browser protections do not promise cross-device exactly-once delivery or bypass blocked scripts. This implementation does not add Meta Conversions API.
- No customer names, email addresses or phone numbers are added to conversion payloads.
- Initial page views are not repeated when Vue Router finishes resolving a direct URL such as a payment return. Subsequent navigation and returning via Back still record page views.
- No financial rows, fee calculations or historical allocations change. No migration or new tracking credentials are required.

## Mandatory regression checks

Release CI and `scripts/check-payment-policy.mjs` require:

```text
node --test tests/tracking.test.js tests/bookingConversionFlow.test.js
php vendor/bin/pest tests/Feature/BookingConversionTest.php tests/Feature/BookingFeeReportingFlowTest.php
```

Run Node from `bookboll-new-system/web` and PHP from `bookboll-BACKEND` using the isolated MySQL test database. The 64-case checkout matrix covers marketplace/site, Free/Premium, global/country/city/zero rates, deposit/full and owned/provider venues; it verifies the actual returned conversion, charge settlement and payment analytics. Additional checks cover currency units, unauthorized reads, invalid returns, pending payments, provider isolation and duplicate returns. Stripe transport and browser advertising transports are simulated: tests send no real purchases to Meta or Google.

The different-listing regression adds eight sequences (Free/Premium × marketplace/site × deposit/full). Each sequence uses ten listing configurations: badminton, two-hour padel/cricket, per-person safari, PKR courts including a below-minimum deposit, fixed deposits, equipment add-ons, provider promos and platform promos. Slot prices deliberately differ from advertised base prices. The same customer starts several checkouts and completes payment returns in reverse order, then revisits them. Assertions compare each event's listing IDs, slot count, currency and online value with its own saved paid receipts. Individually rounded booking receipts are summed; they are not replaced by a freshly calculated percentage of checkout GMV. Expired, rejected, below-minimum and fully discounted deposit checkouts cannot invent a Purchase.

Browser regressions separately verify distinct purchases for different listings and repeat purchases of the same listing, refresh deduplication, and switching provider websites without sending another business's receipt to the current pixel. One checkout currently contains slots from one listing; different listings create separate checkouts and must receive separate event IDs. Keep these tests in the required suites when changing checkout or tracking.

## Browser/Meta verification

Use the official [Meta Ads Data Advisor](https://developers.facebook.com/documentation/meta-pixel/support/meta-ads-data-advisor) extension, formerly Meta Pixel Helper. Install it in Chrome or Edge, pin it, then open the deployed marketplace or provider website in that browser. Basic pixel diagnostics do not require Facebook login. Do not use its automatic pixel setup to insert a second base tag.

1. Reload and check the expected pixel ID and PageView.
2. Start a permitted Stripe **test-mode** customer booking. Check one InitiateCheckout with the server amount/currency.
3. Finish with a Stripe test card in an environment configured for test payments. Check one Purchase for the online amount. For two slots, inspect one event containing `num_items: 2`.
4. Refresh the confirmation page: no new Purchase. Cancel an unpaid checkout: no Purchase.
5. Repeat for full payment and a provider's non-preview website with its own configured pixel. Confirm the marketplace pixel receives no provider-site conversion.
6. Confirm reception in Meta Events Manager Test Events when account access is available. Extension detection alone proves the browser event, not attribution or downstream reporting.

Railway's shared preview intentionally locks real money movement. Do not disable release protections or create real charges to test advertising. If the native browser/extension cannot be automated, install and inspect the extension manually; code, MySQL and deployment checks remain independent of it.

## Real Stripe / Meta verification — 28 November 2026

Actual browser checkouts used Stripe's Bookboll sandbox (`acct_1UOIFBRNzQvTDvNU`, `livemode: false`) and Meta pixel `1556219351378271`. The application ran locally against isolated MySQL because the shared Railway workspace deliberately blocks Stripe calls. Stripe transport and Meta browser delivery were real; email and WhatsApp were disabled. These checks do not prove a public webhook, ad attribution, provider Connect payouts, or every geographic/plan combination.

| Checkout | Booking total | Stripe paid | Meta InitiateCheckout | Meta Purchase |
| --- | ---: | ---: | --- | --- |
| Listing 1, deposit | QAR 100 | QAR 15 | Processed, 15 QAR at 19:35:06 | Processed, 15 QAR at 19:38:59 |
| Listing 2, full | QAR 125 | QAR 125 | Not observed in Test Events | Processed, 125 QAR at 19:45:42 |
| Listing 1, two deposits | QAR 200 | QAR 30 | Processed, 30 QAR at 19:52:13 | Not observed in Test Events |
| Listing 2, two full payments | QAR 250 | QAR 250 | Processed, 250 QAR at 20:02:44 | Processed, 250 QAR, `num_items: 2`, `content_ids: ["2"]` at 20:04:08 |

Times are Asia/Qatar. Refreshing the single-deposit and two-slot full-payment confirmations did not add a Purchase. A cancelled QAR 30 deposit checkout was verified expired/unpaid in Stripe, with cancelled bookings and no paid receipts. Unobserved events are not recorded as passing delivery tests.

Verified Stripe payment intents: `pi_3Ugoe1RNzQvTDvNU1HvJ7j88` (15), `pi_3UgokcRNzQvTDvNU1oozsOiJ` (125), `pi_3UgorZRNzQvTDvNU1VFQoJ0s` (30). The two-slot full checkout was `cs_test_a0evHW74MjDFwXTuUeZNc7uXowIyRMlOYYknhc7QfMbk2xbEdYc2zjOtRG`. Its Meta Purchase ID was `bb_purchase_ccfb837b44e12622d830b8469396ab84ea1221016007b3196f1750faac9a8802`; its checkout ID was `bb_checkout_3693d6f6e7bd8fd616a22c8bd634b6cb5aa0464dd9d98f38ceb8597fe5cdbe4b`.

### Unresolved external configuration: duplicate checkout rule

Meta also received a separate legacy `InitiateCheckout` with an `ob3_plugin-set_` ID, including **100 QAR** on the 15-QAR deposit test. The pixel's public configuration contains saved Event Setup Tool rules: `1632329111919607` matches “pay qar confirm”; `893114753102488` matches “proceed to checkout”; and `1110442281523541` derives Purchase from a `bookboll.com/thank-you?booking_ids=` URL. Existing extractors read page prices and contain fixed QAR currencies. These must be reviewed before claiming clean conversion counts across currencies or channels. Do not work around them by removing verified application events or changing checkout prices.

The user clarified that pixel **1556219351378271** serves live bookboll.com. Its **Automatic events setup** and **Track events automatically without code** settings were restored to **On**, and all **17 event controls** were verified On. Advanced matching and CAPI were left unchanged; no saved rules were deleted. Do not change this live dataset or use it for more QA. The earlier duplicate rule is documented evidence, not authorization to remove it.

## Preview isolation and the dedicated test pixel

The user supplied **1174577295029069** for further testing. Preview Docker builds use `VITE_TRACKING_ENV=test` and `VITE_TEST_META_PIXEL=1174577295029069`. Localhost, Railway and configured preview hosts suppress saved live Meta, GA4, GTM and custom-head tracking by default. Only the separately supplied test pixel may run; no duplicate pasted base snippet is needed. `VITE_TRACKING_ENV=off` suppresses everything. Production must use production build configuration and each storefront's saved settings, never the test override.

Page views and explicit Meta events use `trackSingle`; GA4 events use `send_to`. Unknown provider routes do not fall back to marketplace tags. Provider and marketplace catalog IDs remain the listing's stable numeric ID. The application sets documented `autoConfig: false` before each pixel's first initialization to avoid inferred conversions. This affects this app instance, not Events Manager account settings. Automatic detection can discover otherwise-uninstrumented interactions, so disabling it trades that discovery for explicit, testable events. It does not disable advanced matching or erase Event Setup Tool rules. Owners must coordinate any production rule/GTM cleanup separately; app code cannot guarantee that their external rules never duplicate events.

## Catalog feeds

`GET /api/marketplace/meta-catalog.csv` supplies marketplace inventory. Add `?brand_slug=BUSINESS` for that provider's published website, and optional `currency=QAR` (or another actual currency) for a currency-specific feed. `GET /api/marketplace/meta-catalog` supplies the public inventory check and actionable omissions used in My Website → Tracking.

Feeds contain customer-visible, approved, active bookable listings with a positive advertised starting price, known venue/country currency and public HTTPS photo. Hidden, staff-only, unapproved, paused and suspended inventory is excluded. Unknown provider slugs fail closed. Unpublished provider sites yield an empty replacement feed. Verified custom domains are used in production; preview links stay on preview. Memberships are information/contact listings and hosted community events are not sold as catalog products.

The provider connects this scheduled feed to their own Meta catalog and associates their own pixel in Commerce Manager. Saving a pixel ID does not create a catalog or grant catalog-management access. Successful CSV output is not proof of Meta ingestion, product-policy approval, image availability or matching performance: inspect those in the intended test catalog first. Availability means the listing can be booked; individual session availability and final price are selected on the site. Hosted-event publishing fees, provider subscription billing and offline membership enrollment are outside the booking Purchase contract and must never be guessed from URL/buttons.

Required checks: `tracking.test.js`, `trackingEnvironment.test.js`, `bookingConversionFlow.test.js`, `portalTracking.test.js`, `BookingConversionTest.php`, `BookingFeeReportingFlowTest.php` and `MetaCatalogTest.php`, enforced in payment release CI. MySQL is the integration database; simulated tests do not contact Meta or charge Stripe.
