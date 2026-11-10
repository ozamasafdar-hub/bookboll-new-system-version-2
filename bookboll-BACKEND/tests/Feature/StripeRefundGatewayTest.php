<?php
use App\Services\StripeRefundGateway;
use Stripe\ApiRequestor;

it('sends a full idempotent Connect refund with the transfer and application fee reversed', function () {
    config(['preview.enabled'=>false,'services.stripe.secret'=>'sk_test_fake_no_network']);
    $http = new class implements \Stripe\HttpClient\ClientInterface {
        public array $requests = [];
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1') {
            $this->requests[] = compact('method','absUrl','headers','params');
            return [json_encode(['id'=>'re_test','object'=>'refund','amount'=>10000,'currency'=>'qar','charge'=>'ch_test','status'=>'succeeded']),200,[]];
        }
    };
    $original = ApiRequestor::httpClient();
    ApiRequestor::setHttpClient($http);
    try {
        (new StripeRefundGateway)->create(['id'=>'ch_test','amount'=>10000,'transfer_data'=>['destination'=>'acct_provider'],
            'application_fee'=>'fee_test'],'requested_by_customer','durable-operation-key','acct_context');
        $request = $http->requests[0];
        expect($request['method'])->toBe('post')->and($request['absUrl'])->toEndWith('/v1/refunds')
            ->and($request['params'])->toMatchArray(['charge'=>'ch_test','amount'=>10000,'reverse_transfer'=>true,'refund_application_fee'=>true])
            ->and(implode('\n',$request['headers']))->toContain('Idempotency-Key: durable-operation-key','Stripe-Account: acct_context');
        (new StripeRefundGateway)->create(['id'=>'ch_test','amount'=>2000],'duplicate','second-key',null);
        expect($http->requests[1]['params'])->not->toHaveKey('reverse_transfer')->not->toHaveKey('refund_application_fee');
    } finally { ApiRequestor::setHttpClient($original); }
});
