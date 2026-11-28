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

### Dedicated-pixel verification — 28 November 2026

Railway deployed `344efc8` successfully to marketplace, business and admin/API; GitHub payment regressions passed. In dataset **1174577295029069**, Meta Test Events processed the Railway listing's PageView and ViewContent at 20:48:32–33 Asia/Qatar. ViewContent contained `content_ids: ["2"]`, `value: 100`, `currency: QAR`; the public feed returned the same item with `100.00 QAR`, among 34 eligible listings. A fresh page load at 20:52:02–03 produced its own PageView and ViewContent. DOM inspection found only the dedicated test pixel's injected tracking script.

Anonymous holds are rejected by the Railway workspace middleware, so those selections correctly emitted no AddToCart. The slot UI now surfaces the actual rejection reason; slow responses after changing listings and overlapping clicks have dedicated regression coverage. This reception check is not a new Stripe Purchase test or a Meta catalog import test. The separate actual Stripe evidence above remains labelled by its original dataset and test time.

With QA customer 40091 signed in, Meta processed listing 2 AddToCart for **125 QAR** at 20:59:34. On the next day selection it received 100 QAR and the successfully held discounted extension **80 QAR** at 21:01:18/35. A server-rejected extension emitted nothing. Switching to listing 8 produced ViewContent **45 QAR**, `content_ids: ["8"]` at 21:02:47. All temporary selections were cleared; no booking or charge was created.

The same browser check exposed duplicate navigation PageViews from Meta's SDK history listener alongside Vue's explicit page views. Set `fbq.disablePushState = true` before init in all tracking copies; Vue remains responsible for one scoped page view per path. This is independent of `autoConfig` and does not change Events Manager settings. See Meta's [official tag template](https://github.com/meta/GoogleTagManager-WebTemplate-For-FacebookPixel/blob/main/template.tpl), which exposes the same history-tracking control. Keep the explicit Vue event; removing it would lose provider targeting.

After deploying `8bd7f04` to all three Railway services, a fresh browser load at **21:10:18 Asia/Qatar** sent one PageView and one ViewContent. Navigating through the listing link to listing 8 sent **one** PageView at **21:10:31**, then one ViewContent at **21:10:33** with `value: 45`, `currency: QAR`, `content_ids: ["8"]`. Meta marked them processed; the earlier duplicate did not recur. All 48 frontend tracking checks, all three builds and GitHub payment regressions passed for this revision.

### Actual Stripe payments against the dedicated pixel — 28 November 2026

The authorized temporary Stripe test keys were connected only to the loopback QA harness and disposable MySQL database. No Railway payment lock was disabled. The live dataset was not edited or used for these new tests. A local-host bug initially interpreted `127.0.0.1` as provider slug `127`; IP hosts now resolve as marketplace unless an explicit local provider is selected. Local IPs, IPv6 loopback and `*.localhost` also suppress copied production tracking IDs by default. Regression coverage checks both web and admin brand resolvers.

| Actual checkout | Stripe result | Dedicated pixel InitiateCheckout | Dedicated pixel Purchase |
| --- | --- | --- | --- |
| Listing 1: QAR 100 booking, QAR 15 deposit | Complete / paid / `livemode: false`; QAR 85 remains pending | Processed **15 QAR**, 21:29:43 | Processed **15 QAR**, 21:32:00 |
| Listing 2: two QAR 125 slots, full payment | Complete / paid / `livemode: false`; one QAR 250 charge, two paid booking allocations | Not observed; delivery remains unverified | Not observed; delivery remains unverified |

Times are Asia/Qatar; destination is **1174577295029069**. The first Purchase has `content_ids: ["1"]`; refreshing its confirmation at 21:32:26 produced a PageView without another Purchase. Meta's expanded event details confirmed both the checkout and Purchase values. Stripe's own payment detail also showed QAR 15.00 Succeeded.

Verified payment intents: `pi_3UgqPRRNzQvTDvNU0KbcXyVZ` (deposit) and `pi_3UgqV0RNzQvTDvNU05WFg7Th` (two-slot full payment). Deposit event IDs are `bb_checkout_86e47dc293150e8474219a856d7836796970b8476e1d31511e821ed88b013a78` and `bb_purchase_17aa1269f40b65290fbb25711b343ffa85167f93bd757bd3ce885328e9d16b44`.

The second checkout's server contract correctly contains `value: 250`, `currency: QAR`, `content_ids: ["2"]`, `num_items: 2`, with checkout ID `bb_checkout_2832131ce550c257e3561129ffec08a74585d0da28c0880af797deba4ea94743` and Purchase ID `bb_purchase_7f8ff12f08bae44ab4cc8e190423855db565f20e443aae51b6824316dc0ce069`. Its absence from Meta is not a passing delivery test and has no confirmed cause. Do not reset deduplication or manually replay paid events to make the test appear successful. Browser dispatch alone does not acknowledge Meta receipt; server-side delivery is not implemented.

Cleanup completed: the temporary credential file was removed, both local QA servers stopped, and the harness directory checked for additional copies of either test key. No additional copies were found. Existing Stripe account keys were not revoked. No test secret was added to Git or Railway. The redacted Stripe/database evidence is retained outside the repository; no public webhook or provider Connect payout was tested.
