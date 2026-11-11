<?php

use App\Support\PaymentReleaseGate;
use App\Support\ReleaseGuardedStripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Exercise production enforcement in the disposable test database, not the
// normal test-only bypass. No production runner is claimed by this fixture.
class EnforcedPaymentReleaseGate extends PaymentReleaseGate
{
    protected function isolatedTest(): bool { return false; }
}
class CompletedRunnerFixtureGate extends EnforcedPaymentReleaseGate
{
    protected function productionRunnerReady(array $policy): bool { return true; }
}

beforeEach(function () {
    config(['preview.enabled'=>false,'preview.workspace'=>false,'cache.default'=>'database',
        'services.stripe.secret'=>'sk_live_fixture_never_sent','services.stripe.webhook_secret'=>'whsec_fixture']);
    $this->evidencePath = storage_path('framework/testing/release-'.Str::uuid());
    mkdir($this->evidencePath, 0700, true);
    file_put_contents($this->evidencePath.'/openssl.cnf', "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
    $keyOptions = ['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$this->evidencePath.'/openssl.cnf'];
    $key = openssl_pkey_new($keyOptions);
    openssl_pkey_export($key, $this->releaseTestKey, null, $keyOptions);
    file_put_contents($this->evidencePath.'/public.pem', openssl_pkey_get_details($key)['key']);
    config(['payment_release.public_key_file'=>$this->evidencePath.'/public.pem',
        'payment_release.approval_file'=>$this->evidencePath.'/approval.json']);
    app()->instance(PaymentReleaseGate::class, new EnforcedPaymentReleaseGate);
});

afterEach(function () {
    foreach (glob($this->evidencePath.'/*') as $file) unlink($file);
    rmdir($this->evidencePath);
});

function signReleaseFixture(array $overrides = []): array
{
    $identity = (new PaymentReleaseGate)->identity();
    $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true);
    $plan = hash('sha256','isolated reviewed test plan');
    $artifacts = [];
    foreach (PaymentReleaseGate::HISTORY + PaymentReleaseGate::REFUNDS as $id => $label) {
        $proof = 'Isolated automated test fixture for '.$id;
        file_put_contents(test()->evidencePath.'/'.$id.'.evidence', $proof);
        $artifact = ['schema_version'=>1,'check'=>$id,'passed'=>true,'identity'=>$identity,'plan_sha256'=>$plan,
            'evidence_sha256'=>hash('sha256',$proof),
            'passed_rule_ids'=>array_column($policy['rules'],'id'),'original_receipts_preserved'=>true,
            'allocations_conserve'=>true,'unexpected_changes'=>0,'stripe_mode'=>'test'];
        $json = json_encode(array_replace($artifact, $overrides[$id] ?? []));
        file_put_contents(test()->evidencePath.'/'.$id.'.json', $json);
        $artifacts[$id] = hash('sha256',$json);
    }
    $payload = json_encode(['schema_version'=>1,'approved_by'=>'Isolated test approver','approved_at'=>gmdate('c'),
        'identity'=>$identity,'plan_sha256'=>$plan,'artifacts'=>$artifacts]);
    openssl_sign($payload, $signature, test()->releaseTestKey, OPENSSL_ALGO_SHA256);
    file_put_contents(test()->evidencePath.'/approval.json', json_encode(['payload'=>base64_encode($payload),'signature'=>base64_encode($signature)]));
    return $identity;
}

it('fails closed without approval and reports actual unfinished production work', function () {
    $status = (new EnforcedPaymentReleaseGate)->status();
    expect($status['production_ready'])->toBeFalse()->and($status['refunds_ready'])->toBeFalse();
    $checks = collect($status['checks'])->keyBy('id');
    expect($checks['schema']['passed'])->toBeTrue()->and($checks['policy']['passed'])->toBeTrue()
        ->and($checks['production_runner']['passed'])->toBeFalse()->and($checks['signed_approval']['passed'])->toBeFalse();
    $this->artisan('payments:release-check', ['--json'=>true])->assertExitCode(1);
    $this->artisan('payments:release-check', ['--allow-workspace'=>true])->assertExitCode(1);
});

it('requires the real production runner even when all approval artifacts are signed', function () {
    signReleaseFixture();
    $status = (new EnforcedPaymentReleaseGate)->status();
    expect($status['production_ready'])->toBeFalse()
        ->and(collect($status['checks'])->keyBy('id')['signed_approval']['passed'])->toBeTrue();
});

it('accepts matching verified evidence only when the runner is also complete', function () {
    signReleaseFixture();
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeTrue()->and($status['refunds_ready'])->toBeTrue();
});

it('blocks a release whose signed regression evidence omits shared online payment acceptance', function () {
    $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true);
    $oldRules = array_values(array_diff(array_column($policy['rules'],'id'), ['P17_SHARED_ONLINE_PAYMENTS']));
    signReleaseFixture(['financial_regression'=>['passed_rule_ids'=>$oldRules]]);
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeFalse()
        ->and(collect($status['checks'])->keyBy('id')['financial_regression']['passed'])->toBeFalse();
});

it('blocks evidence without checkout balance acceptance', function () {
    $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true);
    $oldRules = array_values(array_diff(array_column($policy['rules'],'id'), ['P18_CHECKOUT_BALANCES']));
    signReleaseFixture(['financial_regression'=>['passed_rule_ids'=>$oldRules]]);
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeFalse()
        ->and(collect($status['checks'])->keyBy('id')['financial_regression']['passed'])->toBeFalse();
});

it('blocks previous eighteen-rule evidence without audited payment review acceptance', function () {
    $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true);
    $oldRules = array_values(array_diff(array_column($policy['rules'],'id'), ['P19_PAYMENT_REVIEW_ACTIONS']));
    signReleaseFixture(['financial_regression'=>['passed_rule_ids'=>$oldRules]]);
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeFalse()
        ->and(collect($status['checks'])->keyBy('id')['financial_regression']['passed'])->toBeFalse();
});

it('blocks previous acceptance without superadmin bulk payment checks', function () {
    $policy = json_decode(file_get_contents(config('payment_release.policy_file')), true);
    $oldRules = array_values(array_diff(array_column($policy['rules'],'id'), ['P20_SUPERADMIN_BULK_ACTIONS']));
    signReleaseFixture(['financial_regression'=>['passed_rule_ids'=>$oldRules]]);
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeFalse()
        ->and(collect($status['checks'])->keyBy('id')['financial_regression']['passed'])->toBeFalse();
});

it('rejects a tampered signature or artifact without trusting checked boxes', function () {
    signReleaseFixture();
    file_put_contents($this->evidencePath.'/financial_regression.json', '{}');
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
    signReleaseFixture();
    $approval = json_decode(file_get_contents($this->evidencePath.'/approval.json'),true);
    $approval['payload'] = base64_encode(str_replace('Isolated test approver','Unapproved actor',base64_decode($approval['payload'])));
    file_put_contents($this->evidencePath.'/approval.json',json_encode($approval));
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
});

it('rejects signed evidence missing rules, inconsistent totals or a mismatched historical plan', function () {
    foreach ([['financial_regression'=>['passed_rule_ids'=>['P01_CREATOR_EVIDENCE']]],
        ['ledger_verification'=>['allocations_conserve'=>false]],
        ['ledger_verification'=>['unexpected_changes'=>1]],
        ['reconciliation_apply'=>['plan_sha256'=>str_repeat('f',64)]]] as $overrides) {
        signReleaseFixture($overrides);
        expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
    }
});

it('invalidates approval when the database identity or migration history changes', function () {
    signReleaseFixture();
    DB::table('payment_release_identity')->where('id',1)->update(['database_uuid'=>(string) Str::uuid()]);
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
    signReleaseFixture();
    DB::table('migrations')->insert(['migration'=>'unexpected_schema_change','batch'=>999]);
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
});

it('keeps refunds locked separately when their evidence or configuration is incomplete', function () {
    signReleaseFixture(['refund_webhooks'=>['passed'=>false]]);
    $status = (new CompletedRunnerFixtureGate)->status();
    expect($status['production_ready'])->toBeTrue()->and($status['refunds_ready'])->toBeFalse();
    signReleaseFixture(['stripe_test_mode'=>['stripe_mode'=>'live']]);
    expect((new CompletedRunnerFixtureGate)->status()['refunds_ready'])->toBeFalse();
    signReleaseFixture();
    config(['services.stripe.webhook_secret'=>'']);
    expect((new CompletedRunnerFixtureGate)->status()['refunds_ready'])->toBeFalse();
});

it('cannot pass with a signed checkbox but a missing or altered supporting report', function () {
    signReleaseFixture();
    unlink($this->evidencePath.'/reconciliation_apply.evidence');
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
    signReleaseFixture();
    file_put_contents($this->evidencePath.'/reconciliation_apply.evidence', 'Changed after approval');
    expect((new CompletedRunnerFixtureGate)->status()['production_ready'])->toBeFalse();
});

it('independently rejects stored allocation totals and fees on manual receipts', function () {
    $user = \App\Models\User::factory()->create();
    $payment = \App\Models\Payment::withoutEvents(fn () => \App\Models\Payment::create([
        'user_id'=>$user->id,'payment_id'=>'RELEASE-CHECK-FIXTURE','type'=>'full','method'=>'cash',
        'status'=>'paid','amount'=>100,'currency'=>'QAR','financial_context'=>['channel'=>'marketplace']]));
    $payment->ownedAllocations()->createMany([
        ['bucket'=>'platform','amount_minor'=>2000,'currency'=>'QAR','basis'=>'booking_quote'],
        ['bucket'=>'venue','amount_minor'=>8000,'currency'=>'QAR','basis'=>'booking_quote'],
    ]);
    expect(\App\Support\StoredPaymentAllocationCheck::inspect())->toBe(['checked_receipts'=>1,'invalid_receipts'=>0]);
    $payment->ownedAllocations()->where('bucket','venue')->update(['amount_minor'=>9000]);
    expect(\App\Support\StoredPaymentAllocationCheck::inspect()['invalid_receipts'])->toBe(1);
    $payment->ownedAllocations()->where('bucket','venue')->update(['amount_minor'=>8000]);
    $payment->forceFill(['financial_context'=>['channel'=>'admin']])->saveQuietly();
    expect(\App\Support\StoredPaymentAllocationCheck::inspect()['invalid_receipts'])->toBe(1);
});

it('refuses preview flags on a database that is not the imported workspace', function () {
    config(['preview.enabled'=>true,'preview.workspace'=>true]);
    $status = (new EnforcedPaymentReleaseGate)->status();
    expect($status['workspace_ready'])->toBeFalse()->and($status['production_ready'])->toBeFalse();
});

it('blocks writes and returns retryable webhook failure while reports and login remain reachable', function () {
    Route::middleware('api')->post('/api/release-test-write', fn () => response()->json(['mutated'=>true]));
    Route::middleware('api')->get('/api/release-test-read', fn () => response()->json(['read'=>true]));
    $this->postJson('/api/release-test-write')->assertStatus(503)->assertJsonPath('code','payment_release_blocked');
    $this->getJson('/api/release-test-read')->assertOk();
    $this->postJson('/api/login', [])->assertStatus(400)->assertJsonPath('message','Invalid credentials');
    $this->postJson('/stripe/bookboll-webhook', [])->assertStatus(503)->assertHeader('Retry-After','60');
});

it('stops processor calls before any network request including a direct SDK refund', function () {
    $inner = new class implements \Stripe\HttpClient\ClientInterface {
        public int $calls = 0;
        public function request($method,$absUrl,$headers,$params,$hasFile,$apiMode='v1') { $this->calls++; return ['{}',200,[]]; }
    };
    $client = new ReleaseGuardedStripeClient($inner);
    foreach (['/v1/refunds','/v1/payment_intents','/v1/transfers'] as $path) {
        try { $client->request('post','https://api.stripe.com'.$path,[],[],false); $this->fail('A blocked processor write ran.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { expect($e->getStatusCode())->toBe(503); }
    }
    expect($inner->calls)->toBe(0);
});

it('blocks financial scheduled commands until release verification', function () {
    try { $this->artisan('wallet:release-holds', ['--apply'=>true])->run(); $this->fail('An unapproved financial command ran.'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { expect($e->getStatusCode())->toBe(503); }
});
