# Superadmin account and plan management

Implemented in the existing Laravel/MySQL application, 16 November 2026. Deploy backend and admin frontend together. Railway runs migrations before serving the new version.

## Management controls

- **My Team → Platform team:** add a platform account (defaults to Platform staff), inspect role guidance, filter by role/status/sign-in history, and edit country/venue access and permissions.
- **My Team → Provider businesses:** open or preview the correct branded website, copy its link, see published/draft and Free/Premium status, expand its team, edit access, and open Account security.
- **Account security:** platform superadmins can send a reset code by email, set a new password (12+ characters with letters and numbers, confirmed), or revoke all sign-in tokens and server sessions. Changes are audited. Own-password changes use profile settings. Customer-only accounts are excluded.
- Reset emails are simulated in the Railway workspace. Outside preview, the normal admin reset-code flow sends the email. Requesting a reset does not change the password; completing it invalidates sessions. Direct password changes also invalidate outstanding reset codes.
- New team accounts receive a random unshared credential and an email explaining password setup. Adding team access to an existing customer preserves their password and signs out previous sessions. No universal default password is used.
- Deactivating a user stops admin and customer sign-in and revokes sessions. Provider account editors cannot use the shared admin API to change another business’s team. Editing access revokes sessions, preserves submitted permission/country/venue scope, and logs the action. A user cannot remove their own role/access, and the last active superadmin cannot be removed. Only platform superadmins can grant Superadmin.
- Direct account edits cannot silently move someone between businesses, promote a business user into the platform, or replace an owner. Use the reviewed business assignment flow for platform-to-business moves. Legacy repair code still supports membership reconciliation; it is not a public account-move shortcut.

## Complimentary business plans

Migration `2026_11_16_000002_add_business_plan_overrides` adds nullable `plan_override`, the acting admin ID and timestamp to `businesses`. It changes no existing plans. Manual plan changes are in `audit_logs` with before/after and reason.

**Manage plan** supports complimentary Premium, manual Free, and removal of the manual override (return to normal billing). A complimentary grant has no expiry until changed by a superadmin. All business team members inherit it. Premium includes its configured own-website booking rate (city → country → global); marketplace booking fees and per-listing membership subscriptions remain independent. No Stripe charge, subscription or revenue/payment record is created.

`CommissionPolicy::paidPlanEligible()` explicitly recognizes a complimentary Premium grant. Billing sync/webhooks reload the override under a MySQL row lock before updating `businesses.plan`, so a refresh cannot erase the grant. Free disables Premium features without deleting saved content; white-label removal is reset on downgrade. Providers see that their plan is managed by Bookboll. Remove the override to allow normal self-service checkout again.

An active, trialing, past-due, unpaid, paused or unfinished Stripe subscription/checkout blocks a new manual override. Resolve billing through its billing workflow first; this feature does not cancel real subscriptions or silently continue charging a complimentary account. A stale plan review returns HTTP 409.

## Required checks

`tests/Feature/AccountManagementTest.php` runs in `.github/workflows/payment-release-checks.yml` on MySQL and covers server-side role refusals, provider/platform password changes, weak passwords, session/reset-token invalidation, preview versus real mail, manual Premium/Free and fee eligibility, Stripe conflicts, stale reviews, sync preservation, custom-permission round trips, membership-only role changes, deactivation, self-lockout and secure account creation.

Keep the existing BusinessSetupAccess, BusinessAssignment, LegacyStaffMembership, OwnerlessBusiness, OwnSiteCommissionPolicy and AdminPortalPasswordReset suites. The legacy account-move test now checks that direct API moves are refused and separately verifies the repair bridge, rather than relying on a bypass of the reviewed assignment flow.

UI validation must cover both tabs, filters, account security and plan dialogs, business website links, and the edit-access country/venue and custom-permission controls. Test credential and role changes in isolated automated fixtures; do not reset real accounts just to exercise the UI. This release does not alter any existing account password or grant an actual business Premium automatically.
