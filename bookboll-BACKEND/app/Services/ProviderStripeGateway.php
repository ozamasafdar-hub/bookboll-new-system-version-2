<?php
namespace App\Services;
use Stripe\{StripeClient, OAuth};
class ProviderStripeGateway {
    public function account(string $id): array {
        return (new StripeClient(config('services.stripe.secret')))->accounts->retrieve($id)->toArray();
    }
    public function exchange(string $code): array {
        return OAuth::token(['grant_type'=>'authorization_code','code'=>$code], ['api_key'=>config('services.stripe.secret')])->toArray();
    }
}
