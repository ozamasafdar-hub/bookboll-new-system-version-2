# Enforced payment release checks

Implemented 11 November 2026. This is an executable release gate, not a checklist that a dashboard user can dismiss. **The actual production migration runner and production rehearsal remain unfinished.** Their checks deliberately fail until implemented and evidenced.

## What is enforced

The policy now requires **19 rules**, including `P17_SHARED_ONLINE_PAYMENTS` and `P18_CHECKOUT_BALANCES`. Signed regression evidence that omits checkout-balance acceptance is rejected even if the previous 18 rules passed. The schema check also requires the booking checkout reference and collection audit table. See [checkout-payment acceptance](SHARED_ONLINE_PAYMENTS.md). Changed code, schema and policy invalidate approval for the previous build.

- `PaymentReleaseGate` reads the approved rule inventory. Missing policy, migrations or database identity fail closed.
- It independently checks persisted internal allocations against their original receipts: same currency, exact minor-unit total, valid/nonduplicate buckets, and zero platform allocation on confirmed staff bookings. This arithmetic check does not by itself prove all historical ownership, cash assertions or unallocated receipts are correct; the full reconciliation evidence is still mandatory.
- A release approval must be signed with an **operations-owned RSA private key held outside the application/server**. Only the public key is installed on the app. There is no signing endpoint, dashboard approval checkbox or `PAYMENTS_ENABLED=true` bypass.
- Approval binds the normalized backend source tree and composer lock, payment-policy hash, applied migration history, and actual database name/host/port plus a database UUID. A copied database on another host/name or a changed release invalidates old evidence. Protect database identity and migrations from manual edits; migrations alone cannot detect every arbitrary DDL edit.
- Each required check has a signed artifact checksum and a nonempty supporting `.evidence` file whose checksum is also verified. Editing, deleting, substituting or mismatching these files locks the release. A `passed: true` file without signed, matching supporting evidence cannot pass.
- The production runner must exist as `App\Services\ProductionPaymentReconciliation` with `plan`, `apply`, `verify`, `reverse`, and the policy inventory must accurately record implementation. No stub is supplied. Tests and signed reconciliation results must prove the implementation works, not merely that the methods exist.
- Refund checks are separate from general payment release. Refund role restrictions, full-charge conservation, idempotency, shared-charge scope and legacy-wallet guards still apply after release approval.

## Where a failure blocks work

1. **Production HTTP writes:** the unapproved application is read-only; login/logout and ordinary reads remain available. The legacy GET payment-success/status/cancel settlement endpoints are also guarded. This intentionally includes nonfinancial edits during cutover so writers cannot change the reviewed data through another dashboard form.
2. **Stripe SDK mutations:** a shared HTTP adapter blocks charge/checkout/transfer/refund writes from web, jobs and CLI. Refund service/gateway checks apply before processor calls too. No live key alone can unlock refunds.
3. **Workers:** queue looping pauses before reserving the next job while locked; a before-job check protects sync and already-reserved work. Restart long-running workers when replacing approval/evidence. Monitor retries/failures during cutover; the gate is not a durable event inbox.
4. **Scheduled financial commands:** wallet hold release, unpaid booking cancellation and unpaid game cleanup refuse to run while production is locked. Existing historical correction commands retain their separate workspace-only guards. New financial commands must use the gate too.
5. **Startup/release command:** `php artisan payments:release-check` returns exit code **1** when payment launch is blocked; `--scope=refunds` also requires refund evidence/configuration. Railway's existing startup uses `--allow-workspace`, which succeeds only with the real isolated `bookboll_preview` database, import marker and installed schema. It never enables processor writes.
6. **Admin visibility:** platform admin/superadmin Payments shows the server check results and a Recheck action. No dismiss, approve or override action exists. Providers cannot read release evidence/status or unlock refunds.

Signed Stripe webhooks received while production is locked get a retryable **503**, never a fake success. Use the rollout guide's durable event/retry strategy and a bounded maintenance window; Stripe retry retention is not unlimited. There is no endpoint that claims to migrate production or complete the gate automatically.

The only automated-test bypass requires both the PHPUnit/Pest console test runtime and the disposable `bookboll_test` database. Setting `APP_ENV=testing` for a web request does not bypass the gate. Railway keeps its existing no-real-payments restrictions and remains usable for labelled test records.

## Evidence contract for operations

Install the public PEM verification key using `PAYMENT_RELEASE_PUBLIC_KEY_FILE`. Set `PAYMENT_RELEASE_APPROVAL_FILE` to a protected `approval.json` path outside public web storage. The app needs read access, not write access. Keep the private signing key and permission to replace the public key with Osama/the release owner, not with ordinary app administrators. Do not commit private keys, production evidence containing customer records, backups or credentials.

After compatible schema installation, run:

```sh
php artisan payments:release-check --json --identity
```

It prints the identity even while blocked and exits nonzero. That is expected until the genuine release work is complete. Use the exact identity object in every artifact. A fresh rehearsal runs against the verified copy; production verification must attest the **actual target identity**, not claim the copy is production. The production migration runner must validate signed plan authorization separately before applying a plan; this read-only gate is not that runner.

Required history artifact IDs:

`backup_restore`, `reconciliation_plan`, `plan_approval`, `reconciliation_apply`, `ledger_verification`, `reversal_rehearsal`, `financial_regression`, `writer_acceptance`.

Additional refund artifact IDs:

`stripe_test_mode`, `refund_webhooks`, `refund_recovery`.

For each ID, store `<id>.json` and `<id>.evidence` beside `approval.json`. The evidence file is the real tool/test/audit/approval report, not dummy text. JSON format:

```json
{
  "schema_version": 1,
  "check": "ledger_verification",
  "passed": true,
  "identity": {"code_sha256": "…", "policy_sha256": "…", "schema_sha256": "…", "database_sha256": "…"},
  "plan_sha256": "SHA256 of the exact reviewed historical plan",
  "evidence_sha256": "SHA256 of ledger_verification.evidence",
  "original_receipts_preserved": true,
  "allocations_conserve": true,
  "unexpected_changes": 0
}
```

`financial_regression.json` must additionally include `passed_rule_ids` containing **every exact rule ID** in the inventory, with no omissions or extra unknown IDs. Refund artifacts must contain `stripe_mode: "test"` and supporting processor/test evidence, never a live charge rehearsal. Production refund readiness also verifies live-key and webhook-secret configuration, without exposing the secrets. Partial refunds, separate-transfer reversals and old wallet credits remain blocked/unsupported as described in the refund workflow; evidence must not certify them as supported.

The release owner reviews those outputs and signs these exact UTF-8 payload bytes with RSA/SHA-256:

```json
{
  "schema_version": 1,
  "approved_by": "Release owner identity",
  "approved_at": "ISO-8601 timestamp",
  "identity": {"code_sha256": "…", "policy_sha256": "…", "schema_sha256": "…", "database_sha256": "…"},
  "plan_sha256": "…",
  "artifacts": {"backup_restore": "SHA256 of backup_restore.json", "reconciliation_plan": "…", "plan_approval": "…", "reconciliation_apply": "…", "ledger_verification": "…", "reversal_rehearsal": "…", "financial_regression": "…", "writer_acceptance": "…", "stripe_test_mode": "…", "refund_webhooks": "…", "refund_recovery": "…"}
}
```

`approval.json` contains only `{"payload":"BASE64 of signed bytes","signature":"BASE64 RSA signature"}`. Preserve those bytes exactly. Missing refund artifacts can keep refunds locked while the rest is approved. Identity comparison expects the key order exported by the command; copying its JSON object avoids reordering errors. Restart workers and rerun both strict commands after installing or revoking evidence. Remove the approval file to revoke the release; do not alter historical records to make a failed check disappear.

## Automated regression and deployment checks

`.github/workflows/payment-release-checks.yml` runs on pushes/PRs with a disposable MariaDB database and no live credentials. It validates all policy implementation/test references, payment and release-gate regressions, frontend tests and the admin build. The Railway image additionally runs the deterministic financial arithmetic suite during build and the workspace gate during startup. A failure stops that build/startup.

The GitHub workflow alone is **not** a required branch-protection rule. Repository owners should require `Payment release checks / payment-regressions` in the protected production branch and restrict changes to the workflow/gate and signing-key configuration. This change does not modify GitHub access controls or fabricate a passing production certificate.

Application checks prevent accidental omission and ordinary UI/API bypass. A person with unrestricted source, server, database and signing-key access can deliberately remove controls; no code inside a system can make itself unremovable by that system's owner. Keep approval keys and production deployment authority separate from routine development access if enforcement against deliberate changes is required.

Payment review actions also require **P19_PAYMENT_REVIEW_ACTIONS** and the review audit migration. See [mandatory review acceptance](PAYMENT_REVIEWS.md). Previous 18-rule evidence is insufficient.
