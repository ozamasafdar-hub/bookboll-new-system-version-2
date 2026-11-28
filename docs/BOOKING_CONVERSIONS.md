# Booking conversion contract

Approved 28 November 2026: Purchase reports **only the amount paid online**. A QAR 100 booking with QAR 15 paid now and QAR 85 due on arrival reports value 15, currency QAR. A full online payment reports the complete amount Stripe charged, including the provider's share. This is advertising conversion value, not Bookboll revenue.

## Event lifecycle

- `InitiateCheckout` / GA4 `begin_checkout`: after the customer checkout API creates a valid Stripe session, immediately before leaving the storefront for Stripe. Server line-item amount and currency are authoritative. Failed validation, free checkout and cash bookings send no online checkout event.
- `Purchase` / GA4 `purchase`: the thank-you page verifies the returned checkout session through the authenticated API. Stripe must report paid/complete, settlement must be processed and every booking must belong to this customer with a paid status. Pending, failed, admin/pay-link, old unversioned, refunded and partially settled records yield no conversion.
- Session metadata records the customer channel and business at checkout. Marketplace events use only marketplace tags; provider website events use only that business's tags, regardless of Free/Premium plan. Dashboard previews emit no events.
- The payment-intent hash is the Purchase event ID and GA4 transaction ID. It is shared by every booking in that charge. The session hash identifies checkout. Never loop over individual booking rows to send Purchase.
- Browser memory plus bounded local storage suppress refresh/revisit duplicates; Web Locks serialize same-origin tabs where supported. Stable IDs are also passed to the destinations. These browser protections do not promise cross-device exactly-once delivery or bypass blocked scripts. This implementation does not add Meta Conversions API.
- No customer names, email addresses or phone numbers are added to conversion payloads.
- No financial rows, fee calculations or historical allocations change. No migration or new tracking credentials are required.

## Mandatory regression checks

Release CI and `scripts/check-payment-policy.mjs` require:

```text
node --test tests/tracking.test.js tests/bookingConversionFlow.test.js
php vendor/bin/pest tests/Feature/BookingConversionTest.php tests/Feature/BookingFeeReportingFlowTest.php
```

Run Node from `bookboll-new-system/web` and PHP from `bookboll-BACKEND` using the isolated MySQL test database. The 64-case checkout matrix covers marketplace/site, Free/Premium, global/country/city/zero rates, deposit/full and owned/provider venues; it verifies the actual returned conversion, charge settlement and payment analytics. Additional checks cover currency units, unauthorized reads, invalid returns, pending payments, provider isolation and duplicate returns. Stripe transport and browser advertising transports are simulated: tests send no real purchases to Meta or Google.

## Browser/Meta verification

Use the official [Meta Ads Data Advisor](https://developers.facebook.com/documentation/meta-pixel/support/meta-ads-data-advisor) extension, formerly Meta Pixel Helper. Install it in Chrome or Edge, pin it, then open the deployed marketplace or provider website in that browser. Basic pixel diagnostics do not require Facebook login. Do not use its automatic pixel setup to insert a second base tag.

1. Reload and check the expected pixel ID and PageView.
2. Start a permitted Stripe **test-mode** customer booking. Check one InitiateCheckout with the server amount/currency.
3. Finish with a Stripe test card in an environment configured for test payments. Check one Purchase for the online amount. For two slots, inspect one event containing `num_items: 2`.
4. Refresh the confirmation page: no new Purchase. Cancel an unpaid checkout: no Purchase.
5. Repeat for full payment and a provider's non-preview website with its own configured pixel. Confirm the marketplace pixel receives no provider-site conversion.
6. Confirm reception in Meta Events Manager Test Events when account access is available. Extension detection alone proves the browser event, not attribution or downstream reporting.

Railway's shared preview intentionally locks real money movement. Do not disable release protections or create real charges to test advertising. If the native browser/extension cannot be automated, install and inspect the extension manually; code, MySQL and deployment checks remain independent of it.
