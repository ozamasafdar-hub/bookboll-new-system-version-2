# Applying Bookboll's payment rules to the actual live database

Updated 11 November 2026. Start with [the current developer handoff](../../readmeAlamgir.md) and [rules-v1.json](rules-v1.json). This guide is the required production implementation and release contract, not a statement that production migration is already available or approved for execution now.

## What exists, and what must be built

| Exists and can be reused after review | Still required before production |
|---|---|
| Creator evidence, frozen financial context, allocation and report services | A production runner accepting explicit source identity and historical cohorts, without preview-only assumptions |
| Guarded workspace dry-run/apply correction commands | One immutable plan covering ownership, creator evidence, historical cash assertions and fee allocations in dependency order |
| Transfer v3 fingerprints and before/after audit; correction audit tables | Full-batch audit, protected approval artifact, resumable checkpoints, verification and compensating reversal across all phases |
| Regression tests and successful Railway snapshot correction | Fresh production-copy rehearsal, restore/reversal rehearsal, live writer and test-mode processor verification |
| Current rule inventory and this checklist | Enforced CI/deployment checks tying the approved plan to the exact code, rules, schema and source database |

The rule inventory is **not consumed by the application**. The release checklist is **not yet an automated deployment block**. Do not describe either as implemented automation. Do not bypass the existing `bookboll_preview` guards: their services assume imported ownership context and some commands are intentionally preview-specific.

## 1. Freeze a release and identify the source

The release owner records the code commit, rule inventory hash/version, actual database identity and schema version, target environment, responsible operator and restore owner. Review every pending schema migration against this source. Deploy compatible schema additions separately from historical data changes. Keep the old application compatible or use maintenance until all required schema/writer changes are installed.

Take a restorable database backup including users, bookings, payments, snapshots, allocations, corrections, agreements and transfer audit. Back up referenced contracts/media and required operational configuration separately. Record backup time and a secure location; do not commit credentials, database exports or customer-level migration reports to Git. Confirm capacity for the backup, restore, new allocations, indexes, transaction logs and audits. A backup file alone is insufficient: rehearse restoring it.

Restore a fresh live copy to an isolated rehearsal database. Suppress charges, payouts, messages, notifications, schedulers and queue side effects; never let copied jobs connect to live processors. Preserve source identities for reconciliation while isolating access. Do not import Railway QA businesses, changed credentials or test bookings into production.

## 2. Map the historical policy before changing data

Inventory all source receipts and their siblings, not a capped UI page. Record coverage of creator, historical operator/business, channel, saved quote, payment status/type/method, currency, processor reference, refund/dispute and existing allocations. Record unresolved cases explicitly.

Map the three retained Bookboll venues and the external businesses using durable identity and corroborating names/addresses/business relationships. The IDs in the inventory refer to the 4 November dump; ID coincidence is not proof in another database. Preserve existing external assignments and ownerless businesses. Validate legal/business identity and historical effective dates; current venue ownership is not automatically historical ownership.

Define a **closed cohort** for each historical rule: source database, record identities, creation/service boundaries and exclusions. Keep a historical policy cutoff separate from the eventual go-live cutoff. Later records do not acquire an old historical exception merely because they existed before go-live.

- The 20% rule applies only to owner-approved historical customer-created own-venue receipts with missing fee evidence. It never replaces saved quotes or the separate deposit/arrival rule; manual bookings remain zero fee. The current service's preview ownership marker must become a verified production mapping, not be stamped onto all live bookings.
- The three confirmed retained deposits are exact-reference exceptions. Verify reference, booking identity, amount, currency, paid/online state, customer creation and provider operator together. Missing or conflicting matches become review items; never substitute another similar payment. Already-corrected exact matches are a reported no-op.
- The imported provider-direct arrival assertion used a pre-10-November-2026 cohort. Reuse only the reviewed, mapped historical cohort. It is not permission to mark all newly pending arrivals paid. Staff-added unpaid bookings remain pending.
- The two saved-quote cases are restoration examples, not an extra 20% fee on a later balance. Restore the exact original quote and keep the separately recorded balance as venue money when all sibling facts conserve.
- Preserve the three cancelled exceptions as review with zero received/pending contribution. Do not force review count to zero by changing their status.

The owner's existing approvals are recorded in the inventory. Ask for additional business clarification only when the fresh live data falls outside those approved scopes or contradicts the evidence. Do not re-ask already resolved rules.

## 3. Implement a pure plan, then apply that exact plan

Build a production orchestration layer around the existing services. The following are required capabilities, **not existing CLI commands**.

### Plan: read-only and deterministic

Planning must not save models, create administrators, invalidate caches, send events or modify source data. Prefer read-only database credentials and a consistent source snapshot. It produces a protected JSON artifact and a human-readable summary containing:

- Plan/batch ID, environment and source database identity, code SHA, policy version and SHA-256, schema version, snapshot/as-of time, reporting timezone and all cohort definitions.
- Every affected original receipt/booking/venue/business ID and stable reference; relevant original values and fingerprint of all dependencies, including sibling payments, allocations, creator evidence and ownership/quote evidence.
- For each proposed mutation: rule ID, evidence basis, old/new value, generated allocation links, reason, permitted status transition (if any), and intended recipient/channel. Record preservation and no-op decisions too.
- Counts and minor-unit totals before/after for each currency, operator, channel, recipient and paid/pending/refunded/cancelled/review bucket; exact changes attributable to each rule. Do not aggregate different currencies.
- Existing versus added allocation counts, raw receipt count/amount checks, unmatched records, ambiguous mappings, rounding/negative allocation failures, and the complete exception list.
- A checksum of the canonical plan and references to its regression and rehearsal results. Store approval outside editable application inputs with actor, time and checksum.

The summary must distinguish reclassification from newly asserted historical provider cash. The latter changes provider collection status only where approved; it is not a new Bookboll receipt or a new customer charge. New internal allocations are not extra payments.

Planning must compose phases against the proposed prior state. It cannot plan each phase independently from stale initial records and silently change its decisions at apply time.

### Apply: ordered, transactional and resumable

Use a new production entry point with explicit environment/plan identity, never preview startup. Reject a plan whose code, policy, source, schema, cohort or dependency fingerprints differ. Verify approval before any mutation. Do not add new rows to the plan automatically during apply.

Order the phases:

1. Capture original before-images and resolve creation evidence. Identify staff versus customer **before** deciding whether an unpaid balance can be marked provider cash.
2. Resolve ownership and create/reuse verified external business mappings. Freeze original context for excluded siblings before changing a booking/venue fallback.
3. Apply approved manual-origin corrections: zero platform fee; operator owns the venue amount; unpaid manual receipts stay pending.
4. Apply explicit ownership-history restatements and reviewed customer-arrival assertions with transfer-v3 exclusions. Preserve unrelated/unknown siblings and their original frozen operator.
5. Restore exact saved quotes/receipt links. Apply the scoped 20% missing-fee fallback and exact retained-deposit exceptions only after creator/ownership context is resolved.
6. Verify allocations and totals, then invalidate/warm the correct shared report cache after commit.

Do not copy the preview startup order blindly: it initially performs historical ownership preparation before the later creator corrections. Production must not mark a staff balance collected and subsequently lose the evidence that it was originally unpaid.

Use an exclusive migration lock and database transactions at a safe aggregate boundary: a booking's sibling receipts, context and allocations must not be half converted. Define stable ordering, bounded batches and durable checkpoints. Use unique operation keys based on policy/version, source identity, record and operation. Each mutation and its audit/checkpoint must commit atomically. Re-running a completed operation must be a verified no-op, not create more allocations.

Every change needs before/after state, source facts, rule ID/version, actor, plan checksum, batch ID and timestamp. Preserve audit when superseding allocations. Record derived dates as inferred, with their source; do not invent a real collection timestamp. Preserve original receipt amounts, currency, customer, processor IDs and dates except for any separately approved, explicitly listed correction. Status/type/recipient changes must be restricted to the reviewed operations, never a blanket update.

Suppress payment capture/refund/payout/notification side effects while applying bookkeeping corrections. This does not authorize suppressing or dropping real processor events: see cutover handling below.

### Verify and reverse

Verification must re-read persisted records, independently total raw receipts and active allocations, and compare them with the plan's expected result. It must not simply print the plan again.

Record completion only after all checks pass. On failure, keep the environment closed to financial writes, preserve the checkpoint and audit, and either resume safely or reverse the failed batch. A reversal is a new audited operation restoring prior context/allocations/status where the current values still match the batch's after-state. Reject conflicts with later writes; do not overwrite them. The reversal and its replay must be rehearsed.

Restoring the full backup is a cutover fallback **before reopening**. After new live activity resumes, restoring an old database would erase legitimate activity; use guarded compensating corrections or a deliberate recovery plan instead.

## 4. Acceptance evidence required before launch

Use the rule IDs in `rules-v1.json` as a checklist with test result and artifact links. A passing unit suite is necessary but does not replace full-copy reconciliation.

| Check | Required result |
|---|---|
| Conservation | Original receipt counts/amounts preserved; allocations conserve each relevant receipt in the same currency. Every permitted classification/status change appears in the audit. No orphan links or duplicate active allocation operations. |
| Own customer full payment 100 | Original recorded fee + venue remainder = 100 once. For an eligible missing historical quote: platform 20, venue 80. Paid and pending use the same split but distinct totals. |
| Own customer saved quote | Use the saved fee, even if it differs from 20% or today's rate. Original deposit/arrival split is not charged twice. |
| Manual payment 100 | Own venue: fee 0 / venue 100. External: fee 0 / provider 100. Unpaid staff bookings stay pending even during historical transfer. |
| External deposit | Actual paid deposit is Bookboll fee; arrival money stays provider-direct, excluded from Bookboll received/pending and liabilities. |
| External full online | Actual fee + provider allocation conserves gross; gross is not also earnings. Replayed settlement creates no duplicates. Missing evidence is review, except the three exact approved deposits. |
| Cancelled/refunded/skipped | No fabricated earnings/pending; full refund removes linked allocations. A skipped slot creates no payment. Investigate conflicting posted money without deleting it. |
| Transfer and permissions | Stale preview rejected after any sibling/context change. Existing provider keeps authorized frozen ledger access; new provider cannot see old operator finances. Country/staff scopes and accept/export capabilities remain enforced. |
| Filters and exports | Totals match rows for date/status/method/operator/channel/recipient/currency, including zero amount bounds and combined filters. Full authorized dataset, not loaded page or 5,000 cap. Consistent export snapshot. |
| Replay and recovery | Second apply changes zero completed operations; interrupted run resumes without double allocation; reversal conserves original records; stale or unapproved plan fails before writes. |
| Live writers | Web/mobile/site/admin/QR/events/membership creation, collection and replay preserve creator, channel, quote, recipient and receipt links. Manual booking by any authorized staff role has zero fee. |

Run Laravel regression tests against a disposable database configured for tests. Relevant suites are listed in the inventory; include the complete applicable integration suite, `php tests/payment-report-rules.php`, admin tests and production build. Keep evidence for the exact release SHA. Add production-runner tests for altered source identity, stale plans, partial failures, lock contention, replay, reversible status corrections and audit completeness; these runner tests do not exist yet.

Before enabling real payments, verify Stripe Connect in **test mode** with processor evidence, idempotent webhooks and correct provider payout eligibility. Do not use live charges for rehearsal. Existing wallet flags do not establish payout completion. Verify membership renewal/manual paths, any legacy current-rate settlement fallback, and remaining booking due/reprice endpoints. Implement and validate partial refunds, dispute/chargeback and refund-date adjustments before claiming support; otherwise keep the unsupported flows/report claims explicitly gated. Resolve these release gaps separately from historical correction.

## 5. Production cutover after editing is finished

1. Pin the tested release and approve the reviewed historical plan/mapping. Rehearsal is against a recent live copy; Railway totals are only a reference, never an expected production balance.
2. Arrange a short maintenance window for every financial writer: marketplace/mobile/site/admin, workers and scheduler. Stop new captures/checkouts. For payments already in flight, durably queue incoming processor events for replay or return retryable responses under a tested processor-retry strategy. Never acknowledge and discard them. Record event IDs and last processed checkpoints.
3. Take the final backup and source snapshot. Re-plan/verify the final delta since rehearsal with the exact historical eligibility and current event cutoff. Any materially changed records require a new reviewed checksum; elapsed time does not approve them.
4. Apply reviewed compatible schema and the exact approved reconciliation plan with locks/checkpoints. Do not change production accounts, reset passwords or enable preview access settings.
5. Verify per-currency before/after totals, original receipt invariants, unresolved exceptions, provider isolation and report performance. Replay queued processor events with idempotent writers, then reconcile any post-cutoff changes. Clear/warm shared caches and check both admin and provider views.
6. Mark the batch complete and reopen only the tested paths. Keep release/plan hashes, backup/restore instructions, audit references, validation output and unresolved exceptions together for handover. Monitor the first new bookings/settlements for duplicate allocations or lost creator evidence.

If a gate fails, keep writes closed until recovery is complete; do not bypass evidence checks to obtain a cleaner dashboard.

## Who does what, and what must be supplied later

Alamgir or the deployment owner implements the production runner/release gate and provides the actual source connection, deployment method, backup/restore facility and test-mode processor setup. Osama reviews the final business identity mapping, scoped historical assertions and the dry-run totals/remaining exceptions. Already confirmed fee/manual/deposit policies do not need to be remembered or re-decided from chat.

After each further project change, update the inventory and acceptance cases, create a new policy version when eligibility or accounting meaning changes, and rehearse again. Applied policy versions and audit evidence are immutable. Updating this documentation alone never changes historical payments.
