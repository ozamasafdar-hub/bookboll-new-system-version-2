# Business management batches — 21 November 2026

The user asked to finish the current lifecycle audit first and keep these new requests separate. Do not implement the next batch automatically as part of the audit. Complete, test and deploy one batch at a time for review.

## Current batch: finish the lifecycle audit

- Test self-signup and superadmin business creation, owner invitations and provider team roles.
- Test transfers of new private Bookboll venues into both kinds of business, historical reconciliation and platform-staff account transfer.
- Fix isolation, access, signup and session defects; retain MySQL and existing financial rules.
- Push the current branch, deploy Railway, verify the deployed API and user interface, and record evidence and limitations in `BUSINESS_LIFECYCLE_QA.md`.

## Batch 1: business phone and contact actions

Authorized 21 November 2026. Implementation and required checks are documented in [BUSINESS_PHONE_CONTACTS.md](BUSINESS_PHONE_CONTACTS.md). Batch 1 is deployed. Batch 2 is now authorized; Batch 3 remains queued.

- Collect a business phone number with a country calling-code selector in self-signup and superadmin creation. Store it on the business separately from staff personal numbers.
- Allow the business and authorized superadmin to edit it. Require valid contact details for newly completed onboarding; flag missing numbers on existing accounts for follow-up rather than fabricating data.
- Show the number in business directory/detail views with Copy, Call and WhatsApp actions. Generate normalized `tel:` and WhatsApp links.
- Test both creation paths, validation, edits, existing missing values, mobile/desktop actions and cross-business permissions. Opening WhatsApp must not automatically send a message.

## Batch 2: multiple business owners

Authorized 21 November 2026. Implementation and required checks: [MULTIPLE_BUSINESS_OWNERS.md](MULTIPLE_BUSINESS_OWNERS.md).

- Replace the current single-owner restriction deliberately; update the MySQL unique-owner constraint, invitations, role editing, staff transfers and provider-side team management together.
- Keep business ID as the data boundary. Adding another owner must not create another business, move venue revenue or change the payment recipient.
- Let superadmins and existing business owners invite and manage co-owners. Managers/staff cannot promote themselves to owner.
- Preserve at least one active owner. Make owner removal, ownership responsibility and any primary contact explicit; revoke sessions after access changes and retain the audit history.
- Test two or more owners through both creation paths; each must see the same listings, bookings, payments, website and team. Test last-owner protection, account recovery, business isolation and venue/account transfers.
- This batch supersedes the current single-owner behavior and its tests. Until it is implemented, the lifecycle audit records that behavior as the existing contract, not a multi-owner solution.

## Batch 3: superadmin “Log in as business”

- Add an easy action in the business directory and detail view for platform superadmins only.
- Use an explicit, short-lived support session scoped to the selected business, without revealing or resetting the owner's password.
- Show a persistent banner naming the business and an Exit action that returns to the original superadmin session.
- Audit start, end and actions with both the real superadmin and effective business identity. Clearly handle expiry, inactive businesses, revoked access and multiple browser tabs.
- Decide the access policy for money movement and account-security changes before implementation; do not silently inherit unrestricted owner powers.
- Test authorization, cross-business isolation, exit/expiry, browser refresh and audit attribution from both dashboard perspectives.

## Completion standard for each batch

Use labelled QA accounts and private venues, run meaningful MySQL/API regressions and browser checks, update developer instructions and CI, deploy the current Railway branch and verify the deployed result. Do not use real payments, send marketing messages or change existing customer/team accounts for QA.
