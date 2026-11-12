# Provider-managed memberships — 13 November 2026

This supersedes the earlier removal of all member management. Customers still discover listings and contact the provider to enrol offline. Providers can now manage those enrolments in the dashboard. Bookboll does not collect academy fees, auto-renew a customer's membership, refund a customer or operate their attendance.

## What providers can do

- Open **Customers → Membership subscribers**, or **Members** on a membership listing.
- Add a member with name, optional phone/email, guardian/emergency contact, start/end dates, agreed total fee and private notes. No Bookboll customer account, payment gateway, Stripe connection, marketplace subscription or Premium plan is required for this private register.
- Edit or renew dates; pause, cancel or reactivate with a reason. Expiry and future starts are calculated from dates. Pausing does not silently extend dates. When renewing, update the agreed cumulative fee if appropriate. The record's receipts stay cumulative; this is not automatic recurring billing.
- Mark present, absent or excused for a date and named session. The same member/date/session is one record and can be corrected. Future/out-of-term attendance is rejected. Paused/cancelled members cannot be marked present until reactivated.
- Record cash, a transfer received by the business, a business card-terminal payment or another offline method. This is a private receipt note, never a payment instruction. Retries are idempotent. Void an incorrect receipt with a reason; arrange any actual refund independently.
- See a member's balance/credit and audit history. Authorised staff can manage; viewers cannot write. Every record is scoped through the membership listing's permitted venues/business/jurisdiction.

## Listing information and visibility

The listing form supports ongoing access, courses/terms, lesson packs, camps and drop-ins, across gym, class, academy, club, coaching, camp and other categories. Record inclusions, duration/package size, age group, skill level, coach, access, requirements, pricing notes, how to join and the provider's cancellation/pause policy. These are public information, not automatic booking rules. Public information changes are staged for approval when applicable.

Marketplace advertising remains **USD 20/month per listing by default**, with global/country/city overrides, separate on every plan. Own-website visibility still requires paid Premium. These placement rules do not gate member management. There is no membership payout wallet or connected gateway requirement.

## MySQL implementation and API

Run `2026_11_13_000001_create_provider_membership_register.php` with normal Laravel migrations. It adds `class_programs.listing_details` JSON and independent `provider_members`, `provider_member_attendance`, `provider_member_payments`, and `provider_member_activity` tables. No legacy dummy subscribers are copied. No change to booking/payment ledger tables or their historical reconciliation rules.

- GET/POST `/api/admin/provider-members`: scoped paginated register / add member.
- GET/PUT `/api/admin/provider-members/{id}`: scoped detail / edit.
- POST `.../{id}/state`: active, paused or cancelled with reason.
- POST `.../{id}/attendance`: date, session, present/absent/excused and note.
- POST `.../{id}/payments`: unique request UUID, positive amount, receipt date, offline method, reference and note.
- POST `.../{id}/payments/{payment}/void`: reason required.

Reads require view_subscriptions or manage_subscriptions; all writes require manage_subscriptions. Member/payment foreign IDs are checked inside the caller's scope. Programme reassignment is not allowed; create another membership. Currency is frozen after receipts. Listings with member records cannot be moved to another venue.

Manual records must NEVER create Stripe requests, `payments`, wallet credits, platform fees, loyalty rewards, marketplace customer memberships or generated court reservations. They must NEVER be included in platform payment/GMV analytics. Public APIs expose listing information only, never member contacts/attendance/receipts.

The old Stripe-coupled enrolment/check-in/subscription APIs remain retired (410). Use the new register APIs, not the old endpoints. The fresh register replaces the dummy operational records; maintaining/importing those dummies is not a release requirement. Do not delete unrelated booking/payment records or listing advertising invoices. A production rollout still must verify there are no real legacy processor subscriptions left renewing; deleting local dummies cannot cancel a remote Stripe subscription.

## Required checks

`ProviderMemberTest.php` verifies gateway-free operation on Free with an unpublished listing, business isolation on every endpoint, read-only denial, attendance uniqueness/dates/status, lifecycle/renewal, receipt idempotency and voiding, exclusion from platform finances, currency/relationship constraints and public listing information. P24 is enforced by the repository rule checker and CI. The separately maintained native customer app needs only the information/contact surface; it must not restore online customer enrolment.
