# Batch 3 — Log in as business

Authorized policy: superadmin may make routine business edits. Payments, refunds, gateway/integration changes, passwords, roles and account moves stay in the normal superadmin workspace. No owner password is revealed or changed.

## Using it

In **Team & Users → Provider businesses**, choose **Log in as business** on the business row. Review the named business and open its dashboard. A persistent banner identifies the support session, its remaining time and **Return to superadmin**. Returning reloads the directory with the original administrator login. Refreshing keeps the current support session; other tabs retain their original session.

An active owner is required. Active/approved businesses and pending businesses being onboarded can be supported. Suspended/rejected businesses cannot. The session uses the primary active owner when eligible, otherwise another active co-owner. It never changes the business data key, owner assignment or revenue recipient.

## Server contract — keep these checks

- MySQL migration `2026_11_22_000001_create_business_support_sessions.php` stores actor, effective owner, business, source token, support token, expiry and end reason. It preserves audit identifiers after tokens are removed.
- `POST /api/admin/businesses/{business}/support-session` requires a real platform `super_admin`, a normal Sanctum login and an eligible owner. It creates a separate token with only the `business-support` ability, expiring in at most 30 minutes.
- Global `BusinessSupportAccess` middleware checks every bearer use, including legacy and web routes. Never move it inside only the admin route group. It validates the originating superadmin token/account/role, effective owner membership and business status on every request. Orphan tokens fail closed. Cookies cannot override the effective business identity.
- The explicit write allowlist admits website/branding edits, dashboard listing/venue edits and unpaid/skipped staff booking creation. Existing booking edits exclude price/payment-method changes and cancellation. Cancellation may refund or rewrite financial records and is handled outside support. Every other write, public token exchange, personal chat/broadcast access and legacy GET checkout/delivery side effect is denied. The normal business scope/capability checks still run.
- `POST /api/business-support/exit` revokes only the support token. Revoking the source login or either person's access invalidates support too. A scheduled minute task closes expired sessions; expiry is enforced on requests even without the scheduler.
- `business.support.started`, `.action`, `.blocked` and `.ended` audit events identify the real superadmin, effective owner, business and session. Actions record method/path/status, never request bodies or credentials. Model activity and new booking `created_by` attribute the real superadmin. Staff bookings remain `manual_admin` with no platform fee.
- In Railway workspace mode, a validated support session uses the real superadmin's workspace permission. Copied owners' login permission remains disabled and their credentials remain unchanged.

## Browser contract

`bb_admin_token` remains in local storage. The separate support token and display context live in this tab's `sessionStorage` only. Full document reloads clear account-specific caches on entry/return. Corrupt support storage never falls back to the administrator token. A support 401 returns to the original session; it does not delete that session. Late responses from an old bearer cannot sign out the newly active account. Signing out during support returns to superadmin. A change to the original login in another tab ends this tab's support context. Real-time personal channels are disabled during support.

Entry/exit requests time out after 20 seconds and permit retry after a lost connection. A failed exit keeps the business context until the server confirms revocation or rejects the expired token. A reload can recover a request interrupted by deployment. `apiSessionBoundary.test.js` verifies timeout/error handling, stale-response protection and the bearer actually sent; it is also required in CI.

## Required verification

CI runs `BusinessSupportSessionTest.php` against MySQL, plus the ownership/account/preview regression suites and frontend `businessSupport.test.js` / `sessionExpiry.test.js`. Coverage includes role rejection, scoped reads/writes, actual staff booking creation, audit attribution, source/owner/membership/business revocation, expiry, cookie precedence, copied-account isolation, forbidden financial/security paths and independent original login.

Local verification on 22 Nov 2026: 64 MySQL tests / 405 assertions passed; 31 focused frontend tests passed; production admin build passed. Deployed verification is recorded in the completion entry in `readmeAlamgir.md`.

Railway verification: QA self-signup business 28 and superadmin-created business 29 both passed scoped reads, reversible private-venue note edits/restoration, financial/security denials, original superadmin login retention and exit/revocation. Provider accounts cannot start support. Start/action/blocked/end audit events identify the real superadmin. No existing business was modified; no money or messages were sent. Browser checks covered entering business 28, refresh, the separate superadmin tab, scoped bookings and returning to the directory. GitHub release run `45045296180` passed for feature commit `1c146f7`.

## Batch 4 is separate

Do not change existing transaction rates as part of support sessions. Countries/cities UX and the new 10%/15%/15% defaults are queued in `BUSINESS_MANAGEMENT_BATCHES.md`.
