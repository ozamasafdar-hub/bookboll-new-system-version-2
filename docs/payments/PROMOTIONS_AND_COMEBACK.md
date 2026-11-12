# Promotion funding and first-visit comeback offers

Owner-confirmed rules, 13 November 2026. This document supersedes any earlier instruction that kept the original percentage fee unchanged on a **provider** discount.

## Financial examples (QAR)

| Price 100, platform rate 20% | Customer pays | Bookboll fee | Venue revenue |
| --- | ---: | ---: | ---: |
| No promotion | 100 | 20 | 80 |
| Provider 10% promotion | 90 | 18 | 72 |
| Bookboll 10% promotion / comeback | 90 | 10 | 80 |
| Bookboll 10% promotion, fee only 5% | 95 | 0 | 95 |
| Fixed deposit 25, provider 10% promotion | 90 | 25 | 65 |
| Fixed deposit 5, Bookboll 10% promotion | 95 | 0 | 95 |

- A provider promotion discounts the price first; calculate the configured percentage on that discounted price. Both marketplace and own-site bookings use their applicable rate. Rates are snapshotted when booked.
- A configured marketplace fixed deposit remains Bookboll's fixed fee for deposit bookings. Provider discounts cannot consume that fixed amount; their maximum is the remaining venue share. Own-site deposits continue using the configured own-site percentage.
- Bookboll promotions and comeback offers reduce only Bookboll's available share. Never take the excess from the provider, create negative fees, or promise more than the fee budget. Rewards can consume only the fee left after the promo.
- For deposits, collect the net fee online; leave the venue remainder on arrival. For full-online bookings, the charged total equals net platform fee plus venue remainder. Own-operated customer bookings retain the same internal business-unit split. Staff bookings still have zero platform fee and remain venue revenue.
- Persist `platform_promo_discount` and `promotion_snapshot` alongside existing commission/loyalty snapshots. Reconciliation and reports subtract platform-funded discounts exactly once. Do not recalculate historical bookings using today's rate or today's promo rules.
- Price and distribute per slot, using integer cents and largest remainders. Never divide a discount equally across unequal-price slots. The checkout charge must equal the sum of its payment records.
- Public promo validation uses the authenticated customer, actual venue/listing and server slot pricing. A client `user_id`, fee or discount cannot authorize a private code or alter a charge. Final checkout validates again under a transaction and promo lock.
- Retroactive promo changes are limited to uncollected manual bookings with a single offline payment. Collected/issued online transactions cannot be rewritten by this shortcut.

## Comeback lifecycle

The existing `send-booking-reminders` command now runs every minute; the old seven-days-inactive campaign has been replaced, not duplicated.

1. Find the customer's first **marketplace** session at each venue. Adjacent slots form one session. Exclude staff, provider website, skipped-payment blocks, cancelled bookings, academy enrolments and hosted-event court bookings.
2. After the final slot ends in the venue timezone, issue one customer-bound, venue-bound, single-use code. Default is up to 10%, valid exactly seven days from session end. Only marketplace redemption is allowed.
3. Read `comeback_enabled` and `comeback_discount_pct` through global → country → city inheritance. Settings live in Countries & Cities → Comeback offer.
4. Respect separate email and WhatsApp marketing preferences at issuance and again at delivery. No channel opt-in means no marketing send to that channel.
5. `booking_comeback_offers` uniquely identifies the user/venue offer and records channel delivery states. Atomic pending-to-sending claims prevent normal duplicate sends. A failed/uncertain send is `needs_review`, never automatically resent. A crashed `sending` record needs manual delivery-log review too.
6. Old sessions beyond a 24-hour catch-up window never generate a historical marketing blast. Disabled/no-consent first visits are recorded as skipped, not sent retroactively when settings change.
7. Railway preview records `preview` delivery states and makes no external send. Codes may be exercised only using labelled QA data.

### Deployment and operational requirements

- Apply MySQL migration `2026_11_15_000001_add_comeback_promotions.php`. Deploy backend, admin and marketplace together. Maintain `schedule:work` (or cron `schedule:run` each minute), a queue worker and shared cache locks.
- Configure SMTP and `APP_FRONTEND_URL` to the marketplace domain. Links point to `/facilities/{venue_id}`.
- Configure the platform WhatsApp sender plus `WHATSAPP_COMEBACK_TEMPLATE` and optional `WHATSAPP_COMEBACK_LANGUAGE` (default `en_US`). The Meta-approved MARKETING template must use these BODY variables in order: **1 customer name, 2 venue name, 3 percentage, 4 code, 5 expiry, 6 venue URL**. Include the platform-fee cap in the approved body. Do not deploy a template with a conflicting offer.
- Inspect `booking_comeback_offers` for `needs_review`, stale `pending` or `sending`; review provider delivery records before any retry. `sent` means accepted by the channel, not proof of inbox receipt. Preview/mock tests are not live email or WhatsApp delivery evidence.
- Release policy P25/P26, CI, and signed runtime checks require financial promo tests and comeback tests. A new migration/code hash invalidates old release evidence. Never disable the gate to bypass a failed test.

## WhatsApp media

Customers → marketing WhatsApp composer supports JPEG/PNG photos up to 5 MB and MP4 video up to 16 MB. Meta requires supported video encoding (H.264 video/AAC audio); format/size checks do not transcode video. Preview preserves the actual aspect ratio, shows dimensions and supports video playback. A single template header can be image or video, not both. Queued sends preserve that media type.

Upload is authenticated and stores the original asset; Meta template submission still requires a configured sender, public media URL and template approval. Draft preview works without submitting a template or sending to customers. Supported limits: [AWS WhatsApp media documentation](https://docs.aws.amazon.com/social-messaging/latest/userguide/supported-media-types.html).

## Membership geography

Marketplace discovery follows the listing's actual venue, not any other venue owned by the same provider. Country is authoritative; city is a fallback only when country is missing. Orphan listings stay out of geographic discovery until mapped. Qatar retains its existing nationwide discovery scope. Do not silently convert a PKR price to QAR; correct incorrectly entered location/currency data explicitly.

## Required acceptance evidence before live use

1. Run MySQL promotion/rewards/snapshot suites on both surfaces and both payment methods: percentage provider discounts, fixed deposits, capped platform promos, unequal slots and rounding, owned/external venues, zero-deposit confirmation and pending arrival balances.
2. Stripe TEST-mode proof must show the customer's actual discounted charge, exact application/platform fee and exact amount transferred to the external provider's connected account, with webhook replay and refunds conserving totals. **A provider wallet/booking-earning ledger row is not proof of a Stripe transfer. The current booking checkout code does not establish this connected payout path; it must be implemented and verified before full-online external-provider payments go live.**
3. Use test recipients/transport for both marketing channels. Confirm first-session timing, country/city overrides, expiry, customer/venue restrictions, withdrawn consent, duplicate command/job execution and unavailable-template failure. Real customer messages and charges were not used for QA.

The enforced `promotion_funding`, `provider_connect_settlement` and `comeback_delivery` release artifacts must match the exact code/schema/database identity; Stripe settlement evidence must explicitly be test mode. Existing migration/reconciliation checks remain required.
