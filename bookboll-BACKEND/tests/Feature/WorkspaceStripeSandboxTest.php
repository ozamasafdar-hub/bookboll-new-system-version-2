<?php
use App\Http\Middleware\PaymentsPreviewAccess;
use App\Models\User;
use App\Support\{WorkspaceStripeSandbox, WorkspaceStripeClient};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

beforeEach(function () {
    config(['preview.enabled'=>true, 'preview.workspace'=>true, 'preview.stripe_sandbox'=>true,
        'app.url'=>'https://bookboll-admin-preview.up.railway.app',
        'services.stripe.secret'=>'sk_test_QaOnly', 'services.stripe.key'=>'pk_test_QaOnly']);
    $this->sandboxDb = Mockery::mock(\Illuminate\Database\Connection::class);
    $this->sandboxDb->shouldReceive('getDatabaseName')->andReturn('bookboll_preview')->byDefault();
    DB::shouldReceive('connection')->andReturn($this->sandboxDb);
});

it('routes Stripe payment returns through Laravel on Railway', function () {
    $nginx=file_get_contents(base_path('../deploy/payments-preview/nginx.conf'));
    expect($nginx)->toMatch('/location\s+\/payment\/\s*\{\s*try_files\s+\/nonexistent\s+@api;\s*\}/');
});

it('requires the preview flags, Railway host, preview database and a matching test key pair', function () {
    expect(WorkspaceStripeSandbox::enabled())->toBeTrue();
    foreach (['preview.enabled'=>false, 'preview.workspace'=>false, 'preview.stripe_sandbox'=>false,
        'app.url'=>'https://bookboll.com', 'services.stripe.secret'=>'sk_live_Copied', 'services.stripe.key'=>'pk_live_Copied'] as $key=>$invalid) {
        $original=config($key); config([$key=>$invalid]);
        expect(WorkspaceStripeSandbox::enabled())->toBeFalse();
        config([$key=>$original]);
    }
    $this->sandboxDb->shouldReceive('getDatabaseName')->andReturn('bookboll_live');
    expect(WorkspaceStripeSandbox::enabled())->toBeFalse();
});

it('sends only requests authenticated with the configured sandbox key', function () {
    $inner=Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
    $inner->shouldReceive('request')->once()->with('post','https://api.stripe.com/v1/checkout/sessions', ['Authorization: Bearer sk_test_QaOnly'], ['mode'=>'payment'], false, 'v1')
        ->andReturn(['{"id":"cs_test_Qa","livemode":false}',200,[]]);
    $result=(new WorkspaceStripeClient($inner))->request('post','https://api.stripe.com/v1/checkout/sessions', ['Authorization: Bearer sk_test_QaOnly'], ['mode'=>'payment'], false);
    expect(json_decode($result[0],true)['livemode'])->toBeFalse();
});

it('refuses copied live keys, other sandbox keys, other hosts and duplicate authorization before transport', function () {
    $inner=Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
    $inner->shouldNotReceive('request');
    $client=new WorkspaceStripeClient($inner);
    foreach ([
        ['https://api.stripe.com/v1/charges', ['Authorization: Bearer sk_live_Copied']],
        ['https://api.stripe.com/v1/charges', ['Authorization: Bearer sk_test_Other']],
        ['https://example.com/v1/charges', ['Authorization: Bearer sk_test_QaOnly']],
        ['http://api.stripe.com/v1/charges', ['Authorization: Bearer sk_test_QaOnly']],
        ['https://api.stripe.com/v1/charges', ['Authorization: Bearer sk_test_QaOnly','authorization: Bearer sk_live_Copied']],
        ['https://api.stripe.com/v1/charges', []],
    ] as [$url,$headers]) expect(fn()=>$client->request('get',$url,$headers,[],false))->toThrow(\Stripe\Exception\ApiConnectionException::class);
    config(['preview.stripe_sandbox'=>false]);
    expect(fn()=>$client->request('get','https://api.stripe.com/v1/charges',['Authorization: Bearer sk_test_QaOnly'],[],false))->toThrow(\Stripe\Exception\ApiConnectionException::class);
});

it('rejects an unexpected live Stripe response', function () {
    $inner=Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
    $inner->shouldReceive('request')->once()->andReturn(['{"livemode":true}',200,[]]);
    expect(fn()=>(new WorkspaceStripeClient($inner))->request('get','https://api.stripe.com/v1/charges',['Authorization: Bearer sk_test_QaOnly'],[],false))->toThrow(\Stripe\Exception\ApiConnectionException::class);
});

it('allows only customer checkout creation, test session status and abandonment', function () {
    foreach ([['POST','api/customer/payments/create-checkout-session'],['POST','api/customer/loyalty/checkout-quote'],['GET','api/customer/payments/checkout-session/cs_test_Qa'],['POST','api/customer/payments/checkout-session/cs_test_Qa/abandon']] as [$method,$path]) {
        expect(WorkspaceStripeSandbox::allowsBookingRoute(Request::create('/'.$path,$method)))->toBeTrue();
    }
    foreach ([['GET','api/customer/payments/create-checkout-session'],['GET','api/customer/payments/checkout-session/cs_live_Qa'],['POST','api/admin/plan/checkout'],['POST','api/customer/stripe/payouts/connect'],['POST','api/admin/notifications/email'],['POST','api/admin/payments/1/collect-checkout']] as [$method,$path]) {
        expect((bool)WorkspaceStripeSandbox::allowsBookingRoute(Request::create('/'.$path,$method)))->toBeFalse();
    }
});

it('still requires a workspace account and leaves unrelated integrations blocked', function () {
    $middleware=new PaymentsPreviewAccess();
    $request=Request::create('/api/customer/payments/create-checkout-session','POST');
    $next=fn()=>response()->json(['reached'=>true]);
    expect($middleware->handle($request,$next)->getStatusCode())->toBe(401);
    Auth::guard('sanctum')->setUser((new User())->forceFill(['email'=>'copied@example.com','workspace_access'=>false]));
    expect($middleware->handle($request,$next)->getStatusCode())->toBe(403);
    Auth::guard('sanctum')->setUser((new User())->forceFill(['email'=>'qa@example.com','workspace_access'=>true]));
    expect($middleware->handle($request,$next)->getStatusCode())->toBe(200);
    foreach (['/api/admin/notifications/email','/api/admin/plan/checkout','/api/customer/stripe/payouts/connect'] as $path) {
        expect($middleware->handle(Request::create($path,'POST'),$next)->getStatusCode())->toBe(409);
    }
});
