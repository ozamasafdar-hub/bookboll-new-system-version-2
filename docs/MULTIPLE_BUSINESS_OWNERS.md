# Batch 2 — multiple business owners

## Behavior

Superadmins and existing business owners can invite co-owners or promote their existing staff through Edit access. They can also demote, deactivate or remove another owner while at least one active owner remains. Managers, supervisors, staff and legacy platform admins cannot grant or manage ownership. Owners cannot remove their own access through these forms.

Every owner has full access to the same business, its venues, bookings, payment reports, website, plan and team. Ownership grants no platform access, superadmin password controls, complimentary-plan controls or platform refund rights. New owners must be active and cannot have selected-venue restrictions or custom permission overrides. Promoting a staff member clears their old restrictions. Existing-account transfers retain their reviewed preview, scope, stale-state checks and session revocation.

Both team views include all owners, including the signed-in owner. The superadmin directory shows the owner count. One owner is labelled **Primary contact**; this is a contact label, not greater authority. Existing owners remain primary when another owner is added. When the primary owner leaves or becomes inactive, the oldest active co-owner becomes primary automatically. The business phone remains separate from all personal contact details.

## MySQL contract and rollout

Run `php artisan migrate --force` before starting the updated API. Railway's existing startup command already does this. The additive migration `2026_11_21_000001_allow_multiple_business_owners.php`:

1. Adds and backfills `businesses.legacy_owner_id`, preserving the existing owner-keyed data identity.
2. Ensures legacy primary owners have a membership without replacing existing memberships.
3. Removes `business_memberships_one_active_owner` and its generated column. The unique `(business_id, user_id)` membership constraint remains.

`business_memberships` grants access. `businesses.owner_id` is the primary contact. `legacy_owner_id` is a stable compatibility key for older venues, promotions and settings; it is never reassigned when the primary contact changes. All co-owners resolve the same key on the server and in the dashboard. Adding a co-owner does not create another business or change any payment allocation, Stripe connection, subscription, venue ownership or reconciliation result.

The system still supports one active business per account. Accounts linked to another business must use the existing transfer/removal process; adding co-owners does not introduce multi-business switching. A historical data-key account cannot become the first owner of a second new business using the same key; use another first owner, then add that account to the existing business team.

Owner mutations lock the business row. Last-owner checks and primary selection use locking reads of memberships and users so concurrent requests cannot both remove the last two active owners. Inactive co-owners do not satisfy the last-active-owner requirement. Access changes revoke tokens and remembered sessions and retain account audit records. Removing access preserves the person's customer identity and booking history. Customer CRM status APIs cannot bypass team-access controls.

Rollback refuses to recreate the one-owner index if multiple owners exist or the primary contact differs from the historical data key. Never drop co-owners or rewrite financial history just to make a rollback succeed.

## Required verification

- `MultipleBusinessOwnersTest`: both invitation paths, public self-signup, superadmin business creation, promotions/demotions, primary replacement, unchanged payment rows, shared venue/settings scope, other-business denial, manager/platform-role denial, inactive-owner handling, last-owner safeguards, account-session revocation and migration rollback protection.
- Existing business lifecycle, assignment, setup, account security, phone, provider settings and legacy membership suites remain required. The former second-owner rejection cases now assert that an audited co-owner addition preserves the first owner.
- Payment-report, Stripe-connection, venue-transfer and commission suites check business identity remains stable.
- Frontend tests run the real Team component's invitation and promotion actions, verify managers cannot choose Owner, ensure old staff restrictions are cleared on promotion, group multiple owners once, and preserve the common business key after login.
- The GitHub release workflow includes the new ownership suite. Railway verification uses labelled QA accounts only; verify actual sign-in, both dashboards, simultaneous last-owner changes, scope and unchanged payment totals. Do not charge cards, connect real Stripe accounts or send invitations externally in the workspace.

Batch 3 (superadmin support login) remains separate.

## Team management usability (22 November 2026)

The superadmin directory has searchable, alphabetically sorted business summaries, ten businesses per page, and quick filters for missing active owners or valid business phone numbers. Open **Manage team** for owners and staff; primary contacts appear first, followed by co-owners and staff. Website, call and WhatsApp links stay visible. **Business settings & existing accounts** contains plan/contact controls and the reviewed account-transfer flow. Account security and removal are under each person's **More** menu. There is no misleading “Delete business” shortcut to an account-removal action.

Providers use compact role filters and the **Add owner or staff** form. Contact details precede role and venue permissions. The UI explains protected self/last-owner changes, keeps new owners active, and preserves failed invitation drafts with inline validation errors. Server authorization and MySQL ownership safeguards remain authoritative and unchanged. Loading, failed loads and empty filter results have separate states.

Regression checks cover owner ordering, last-active-owner guidance, directory filters/pagination, mixed-type venue IDs, full-access owners in venue filters and retained failed drafts. Verify desktop and narrow-screen forms, expanded team rows, action menus and both colour themes against the Railway build. No database migration is needed for this usability update.
