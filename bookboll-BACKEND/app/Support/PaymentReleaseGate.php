<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fail-closed production release verification. Never writes or approves evidence. */
class PaymentReleaseGate
{
    public const HISTORY = [
        'backup_restore'=>'Backup restored successfully',
        'reconciliation_plan'=>'Historical reconciliation plan',
        'plan_approval'=>'Owner approval of the exact plan',
        'reconciliation_apply'=>'Audited historical reconciliation',
        'ledger_verification'=>'Receipt and allocation totals verified',
        'reversal_rehearsal'=>'Recovery and replay tested',
        'financial_regression'=>'All payment rules tested',
        'writer_acceptance'=>'Booking and payment entry points tested',
        'membership_listing_billing'=>'Listing billing and visibility lifecycle tested in Stripe test mode',
        'legacy_membership_retirement'=>'Legacy customer membership renewals ended and verified',
    ];
    public const REFUNDS = [
        'stripe_test_mode'=>'Stripe refunds tested in test mode',
        'refund_webhooks'=>'Signed refund webhooks tested',
        'refund_recovery'=>'Refund retries and recovery tested',
    ];

    private ?array $result = null;

    /** Runtime bypass is confined to PHPUnit/Pest and its disposable database. */
    protected function isolatedTest(): bool
    {
        return app()->runningUnitTests() && app()->runningInConsole()
            && DB::connection()->getDatabaseName() === 'bookboll_test';
    }

    protected function productionRunnerReady(array $policy): bool
    {
        $runner = 'App\\Services\\ProductionPaymentReconciliation';
        return ($policy['status']['production_plan_apply_verify_reverse_runner_implemented'] ?? false) === true
            && class_exists($runner) && collect(['plan','apply','verify','reverse'])->every(fn ($method) => method_exists($runner, $method));
    }

    public function assertReady(string $scope = 'writes'): void
    {
        if ($this->isolatedTest()) return;
        // Preview saves do not need production evidence. Validate the actual
        // isolated database every time; a flag alone cannot unlock live writes.
        if ($scope === 'writes' && $this->workspace()) return;
        $status = $this->status();
        if ($scope === 'writes' && $status['workspace_ready']) return;
        $ready = $scope === 'refunds' ? $status['refunds_ready'] : $status['production_ready'];
        abort_unless($ready, 503, 'Payment release checks are incomplete. A platform administrator can view the blocking checks on Payments. No payment action was performed.');
    }

    private function workspace(): bool
    {
        return config('preview.enabled') && DB::connection()->getDatabaseName() === 'bookboll_preview'
            && Schema::hasTable('payment_release_identity') && Schema::hasTable('preview_import_marker')
            && DB::table('preview_import_marker')->exists();
    }

    public function status(): array
    {
        if ($this->result !== null) return $this->result;
        $checks = [];
        $add = function (string $id, string $label, bool $passed, string $detail, string $scope = 'payments') use (&$checks): void {
            $checks[] = compact('id','label','passed','detail','scope');
        };
        $identity = [];
        $workspace = false;
        try {
            $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true, 512, JSON_THROW_ON_ERROR);
            $rules = array_column($policy['rules'], 'id');
            $add('policy', 'Approved business rules available', count($rules) >= 24 && in_array('P17_SHARED_ONLINE_PAYMENTS', $rules, true) && in_array('P18_CHECKOUT_BALANCES', $rules, true) && in_array('P19_PAYMENT_REVIEW_ACTIONS',$rules,true) && in_array('P20_SUPERADMIN_BULK_ACTIONS',$rules,true) && in_array('P21_PAYMENT_ANALYTICS_PARITY',$rules,true) && in_array('P22_BOOKING_ACTIVITY_PARITY',$rules,true) && in_array('P23_MEMBERSHIP_CONTACT_LISTINGS',$rules,true) && in_array('P24_MANUAL_PROVIDER_MEMBERS',$rules,true) && count($rules) === count(array_unique($rules)), 'The release must cover every rule, including payment analytics parity, in the versioned payment policy.');
            $missing = array_diff(array_map(fn ($p) => basename($p, '.php'), glob(database_path('migrations/*.php'))),
                Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : []);
            $schemaReady = !$missing && Schema::hasTable('payment_refunds') && Schema::hasColumn('payments', 'payment_refund_id')
                && Schema::hasTable('payment_allocations') && Schema::hasTable('payment_release_identity')
                && Schema::hasColumn('bookings','checkout_reference') && Schema::hasTable('checkout_payment_collections') && Schema::hasTable('payment_review_decisions')
                && Schema::hasTable('payment_bulk_actions') && Schema::hasTable('payment_bulk_action_items');
            $add('schema', 'Required database migrations installed', $schemaReady, $schemaReady ? 'Required payment tables and migrations are installed.' : 'Apply the missing schema migrations before release.');
            if ($schemaReady) $identity = $this->identity();
            $workspace = $this->workspace();
            if ($schemaReady) {
                $ledger = StoredPaymentAllocationCheck::inspect();
                $add('stored_allocations', 'Stored fee splits conserve their receipts', $ledger['invalid_receipts'] === 0,
                    $ledger['checked_receipts'].' receipts checked; '.$ledger['invalid_receipts'].' invalid. Checks totals, currency, duplicate buckets and zero fees on staff bookings.');
            }
            $add('environment', 'Production database selected', !config('preview.enabled') && DB::connection()->getDatabaseName() !== 'bookboll_preview',
                $workspace ? 'This is the isolated Railway workspace. Real Stripe payments remain disabled.' : 'A workspace database or preview flag cannot be used as a production release.');
            $runnerReady = $this->productionRunnerReady($policy);
            $add('production_runner', 'Production migration runner implemented', $runnerReady,
                'The production plan/apply/verify/reverse runner must be implemented and tested. Workspace correction commands cannot substitute for it.');
            $sharedCache = in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database','redis'], true);
            $add('shared_locks', 'Shared payment locks configured', $sharedCache, 'Use a shared database or Redis cache for payment and refund locks.');

            $approval = $this->approval();
            $signed = $approval !== null;
            $add('signed_approval', 'Release approval verified', $signed, 'An external release owner must sign the evidence. Dashboard users cannot approve or dismiss this check.');
            $matches = $signed && $identity && ($approval['identity'] ?? null) === $identity;
            $add('release_identity', 'Evidence matches this code and database', (bool) $matches, 'Code, rules, migration history and database identity must match the signed release exactly.');
            $plan = $approval['plan_sha256'] ?? '';
            $planValid = is_string($plan) && preg_match('/^[a-f0-9]{64}$/D', $plan) === 1;
            $add('plan_identity', 'Historical plan checksum recorded', $planValid, 'All historical evidence must refer to the same reviewed plan.');
            foreach (self::HISTORY + self::REFUNDS as $id => $label) {
                $scope = isset(self::REFUNDS[$id]) ? 'refunds' : 'payments';
                $artifact = $signed && $matches && $planValid ? $this->artifact($id, $approval) : null;
                $passed = $artifact !== null && ($artifact['passed'] ?? false) === true
                    && ($artifact['identity'] ?? null) === $identity && ($artifact['plan_sha256'] ?? null) === $plan;
                if ($passed && $id === 'financial_regression') {
                    $tested = $artifact['passed_rule_ids'] ?? [];
                    $passed = !array_diff($rules, $tested) && !array_diff($tested, $rules);
                }
                if ($passed && $id === 'ledger_verification') {
                    $passed = ($artifact['original_receipts_preserved'] ?? false) === true
                        && ($artifact['allocations_conserve'] ?? false) === true
                        && ($artifact['unexpected_changes'] ?? null) === 0;
                }
                if ($passed && ($scope === 'refunds' || $id === 'membership_listing_billing')) $passed = ($artifact['stripe_mode'] ?? '') === 'test';
                $add($id, $label, $passed, $passed ? 'Verified evidence matches this release.' : 'Missing, failed, changed or mismatched release evidence.', $scope);
            }
            $legacyCount = DB::table('class_subscriptions')->whereNotNull('stripe_subscription_id')->whereNotIn('status', ['cancelled','canceled','expired','ended'])->count();
            $add('legacy_membership_renewals', 'Legacy academy customer billing has ended', $legacyCount === 0, 'Verify that no legacy customer Stripe subscriptions can renew. Dummy subscriber records are not imported into the manual register.');
            $stripeReady = preg_match('/^(sk|rk)_live_/', trim((string) config('services.stripe.secret'))) === 1
                && str_starts_with(trim((string) config('services.stripe.webhook_secret')), 'whsec_');
            $add('stripe_configuration', 'Stripe and webhook signing configured', $stripeReady, 'Stripe credentials and the signed webhook endpoint are required. No credentials are shown here.', 'refunds');
        } catch (\Throwable $e) {
            // Fail closed without exposing paths, connections, keys or customer data.
            $add('inspection', 'Release checks can be read', false, 'The release policy, database or evidence could not be verified. Run payments:release-check on the server.');
        }
        $production = count($checks) > 10 && collect($checks)->where('scope','payments')->every(fn ($c) => $c['passed']);
        return $this->result = [
            'mode'=>$workspace ? 'workspace' : 'production',
            'workspace_ready'=>$workspace && collect($checks)->whereIn('id',['policy','schema'])->every(fn ($c) => $c['passed']),
            'production_ready'=>$production,
            'refunds_ready'=>$production && collect($checks)->every(fn ($c) => $c['passed']),
            'checks'=>$checks,
        ];
    }

    /** Safe to export for an operations-owned signed release; no secrets or PII. */
    public function identity(): array
    {
        $files = [];
        foreach (['app','bootstrap','config','routes','database/migrations'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains(str_replace('\\','/',$file->getPathname()), '/bootstrap/cache/')) continue;
                $path = str_replace('\\','/', substr($file->getPathname(), strlen(base_path()) + 1));
                $files[$path] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($file->getPathname())));
            }
        }
        $files['composer.lock'] = hash_file('sha256', base_path('composer.lock'));
        ksort($files);
        $connection = DB::connection();
        $uuid = DB::table('payment_release_identity')->where('id',1)->value('database_uuid');
        if (!is_string($uuid) || !\Illuminate\Support\Str::isUuid($uuid)) throw new \RuntimeException('Missing database identity.');
        $policy = file_get_contents(config('payment_release.policy_file'));
        return [
            'code_sha256'=>hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES)),
            'policy_sha256'=>hash('sha256', str_replace("\r\n", "\n", $policy)),
            'schema_sha256'=>hash('sha256', json_encode(DB::table('migrations')->orderBy('migration')->pluck('migration')->all())),
            'database_sha256'=>hash('sha256', json_encode([$connection->getConfig('host'), $connection->getConfig('port'),
                $connection->getDatabaseName(), $uuid])),
        ];
    }

    private function approval(): ?array
    {
        $keyFile = config('payment_release.public_key_file');
        $file = config('payment_release.approval_file');
        if (!$keyFile || !is_file($keyFile) || !is_file($file)) return null;
        $envelope = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $payload = base64_decode($envelope['payload'] ?? '', true);
        $signature = base64_decode($envelope['signature'] ?? '', true);
        if (!$payload || !$signature || openssl_verify($payload, $signature, file_get_contents($keyFile), OPENSSL_ALGO_SHA256) !== 1) return null;
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema_version'] ?? null) !== 1 || !is_string($data['approved_by'] ?? null) || trim($data['approved_by']) === '') return null;
        $approvedAt = strtotime($data['approved_at'] ?? '');
        if (!$approvedAt || $approvedAt > time() + 60) return null;
        return $data;
    }

    private function artifact(string $id, array $approval): ?array
    {
        $hash = $approval['artifacts'][$id] ?? null;
        $path = dirname(config('payment_release.approval_file')).'/'.$id.'.json';
        if (!is_string($hash) || !is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) return null;
        $artifact = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $evidence = dirname($path).'/'.$id.'.evidence';
        $evidenceHash = $artifact['evidence_sha256'] ?? null;
        if (!is_string($evidenceHash) || !is_file($evidence) || filesize($evidence) === 0
            || !hash_equals($evidenceHash, hash_file('sha256', $evidence))) return null;
        return ($artifact['schema_version'] ?? null) === 1 && ($artifact['check'] ?? null) === $id ? $artifact : null;
    }
}
