<?php

use App\Services\StripeService;
use Illuminate\Http\Request;

it('returns checkout to the canonical API origin behind a storefront proxy', function () {
    config(['app.url' => 'https://api.example.test/']);
    app()->instance('request', Request::create('https://provider.example.test/api/customer/payments/create-checkout-session'));
    $service = (new ReflectionClass(StripeService::class))->newInstanceWithoutConstructor();
    expect($service->checkoutReturnUrls())->toBe([
        'success_url' => 'https://api.example.test/payment/success/{CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://api.example.test/payment/cancel?session_id={CHECKOUT_SESSION_ID}',
    ]);
});
