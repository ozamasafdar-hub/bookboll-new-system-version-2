# Business and team lifecycle audit

Started 17 November 2026; resumed 21 November after the user's usage reset. This audit uses the existing single-owner model. The user's later multiple-owner request is queued separately in [the batch plan](BUSINESS_MANAGEMENT_BATCHES.md).

## Defects fixed

- Provider directory APIs returned other businesses' staff, companies and venue choices. Reads now enforce the business/country boundary on the server, including membership-only accounts.
- Managers with `manage_team` were rejected by owner-only team endpoints; their dashboard silently displayed an empty team. Authorized managers now use the same scoped team endpoints, and load errors are visible.
- Selected-venue managers could grant other venues or clear pins to give whole-business access. Grant validation checks both the actor's access and the destination business.
- Existing inactive or staff accounts could enter the public business-signup path. Those attempts are rejected; a legitimate existing customer can become an owner with one canonical membership and old sessions revoked.
- Inviting an existing customer ignored selected-venue pins and custom permission overrides. Those now persist while preserving the customer's password.
- Staff transfers left direct platform permissions and remembered sessions behind. Transfer now clears permissions, tokens and remembered/database sessions, checks role rank and existing ownership, and invalidates stale reviews when access changes.
- Removing business staff deleted the customer's account. Removal now revokes business membership/access and sessions while preserving their account/history. Owners require the ownership workflow.

## Automated checks

`BusinessLifecycleTest.php` covers signup rejection/success, directory isolation, venue grant restrictions, owner/rank transfer protection, direct-permission/session cleanup, safe team removal, existing-customer invitations, four provider staff roles, and both creation-to-venue-transfer paths.

The broader focused suite passed 128 tests / 687 assertions before the final existing-customer invitation case was added. The final affected suites then passed 52 tests / 307 assertions. Admin UI tests passed 17/17 and the production build passed. All database tests use a disposable MySQL database; no PostgreSQL dependency or migration is introduced. These lifecycle and email-verification tests are included in the mandatory GitHub workflow.

## Railway fixtures and observed results

Only newly authorized QA fixtures were changed. Credentials remain outside the repository.

| Path | Business | Private venue | Observed reconciliation |
| --- | --- | --- | --- |
| Public OTP self-signup | QA Self Transfer 17 Nov (25) | QA Lifecycle 17 Nov - self (24), listing 68 | QAR 30 paid, QAR 70 pending, no platform fee, skipped booking has no payment |
| Superadmin creation UI + owner invitation | QA Admin Transfer 17 Nov (24) | QA Lifecycle 17 Nov - adminOwner (25), listing 69 | Same result |

Each venue transfer used reviewed `legacy_deposit_fee` reconciliation with `keep_pending`; retry returned the original transfer. The other business could not read the transferred venue's payment records. The local MySQL creation-path tests additionally verify a QAR 20 retained online deposit plus QAR 80 pending arrival balance, without moving money or duplicating receipts.

Owner, manager, supervisor, staff and viewer QA accounts were created through the applicable APIs for each business. A dedicated QA platform-staff account is reserved for the account-transfer verification. Live post-deployment verification and browser evidence are recorded below when complete.

## Boundaries

Real payments and external messages remain disabled in the Railway workspace. OTP/invitation email delivery is simulated there; production delivery is not proven by these tests. These checks do not claim to exhaustively test every page or replace the existing payment live-use gates. New QA listings remain private. No existing person or operational venue was transferred.
