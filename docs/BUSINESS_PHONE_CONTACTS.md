# Batch 1 — business phone contacts

Implemented 21 November 2026. Multiple owners and superadmin support login remain separate batches.

## Data and API contract

- `businesses.phone` is the business's main contact, never the owner's or a staff member's personal number. The existing MySQL migration `2026_10_19_000017_add_provider_settings_fields` supplies the column; this batch needs no new migration.
- Public `POST /api/business/register` and superadmin `POST /api/admin/businesses` require `phone`. Store a leading `+` and 8–15 digits. Formatted input and `00` international prefixes are normalized; letters, extensions, URLs, missing country codes and malformed numbers are rejected. This checks the format, not number ownership or WhatsApp registration.
- `GET /api/admin/businesses` returns and searches the business number. It never falls back to a person's phone. Existing missing/invalid numbers remain unchanged and are flagged for follow-up.
- Platform superadmins can update an owned or ownerless business through `PUT /api/admin/businesses/{id}/contact`. The business model's existing activity log records changes. Provider accounts cannot call this API.
- A business owner can edit their own contact in Settings → Business profile. Staff can view it but cannot edit it. Provider edits cannot target another business. Personal phone edits still operate on the separate user record.

## User experience

Signup and Add business include a country calling-code selector and required business phone. Add business also displays the phone in Review. Each superadmin business card has the number, Call, WhatsApp, Copy and Edit phone. Missing numbers show Phone missing and Add phone, including businesses without owners. The directory can search by the number.

Call uses `tel:+…`; WhatsApp uses `https://wa.me/…` with digits only and opens in a separate tab. Neither action sends an automatic message or changes notification settings. Unsupported or missing numbers never produce contact links.

## Required checks

Run the MySQL `BusinessPhoneTest`, `ProviderSettingsTest`, `BusinessLifecycleTest`, `BusinessEmailVerificationTest`, `BusinessSetupAccessTest`, `BusinessAssignmentTest` and `OwnerlessBusinessTest`. The focused run passed **106 tests / 569 assertions**.

Frontend checks include `businessPhone.test.js`, `businessSetup.test.js`, `businessCreation.test.js`, `businessTeam.test.js`, `providerSettings.test.js` and the web app's `businessSignup.test.js`. These cover required fields, payloads, failed saves preserving drafts, country defaults, international paste, link safety and identical phone controls across independently deployed apps. Admin and business production builds pass. The new checks and business build are in GitHub CI.

Railway verification uses labelled QA businesses only. Check both creation paths, reload persisted contacts, edit as the owner and superadmin, inspect link destinations, verify missing numbers, and verify cross-business / staff denial. Do not place calls or send messages as part of QA.
