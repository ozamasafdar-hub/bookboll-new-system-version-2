<?php
namespace App\Services;

use Stripe\StripeClient;

/** Isolated processor adapter; integration tests replace this, never move real money. */
class StripeRefundGateway
{
    private function client(): StripeClient
    {
        abort_if(config('preview.enabled'), 409, 'Stripe refunds are disabled in this Railway workspace. No money will be moved.');
        app(\App\Support\PaymentReleaseGate::class)->assertReady('refunds');
        $secret = trim((string) config('services.stripe.secret'));
        abort_if($secret === '', 409, 'Stripe is not connected to this environment.');
        return new StripeClient($secret);
    }

    public function charge(?string $chargeId, ?string $intentId, ?string $account = null): array
    {
        $client = $this->client();
        $options = $account ? ['stripe_account'=>$account] : [];
        if (!$chargeId) {
            $intent = $client->paymentIntents->retrieve($intentId, ['expand'=>['latest_charge']], $options);
            $chargeId = is_string($intent->latest_charge) ? $intent->latest_charge : $intent->latest_charge?->id;
        }
        abort_unless($chargeId, 422, 'Stripe has no captured charge for this payment.');
        return $client->charges->retrieve($chargeId, ['expand'=>['refunds']], $options)->toArray();
    }

    public function create(array $charge, string $reason, string $key, ?string $account): array
    {
        $params = ['charge'=>$charge['id'], 'amount'=>$charge['amount'], 'reason'=>$reason,
            'metadata'=>['bookboll_refund_key'=>$key]];
        // Destination charges must reverse the provider transfer as well as the fee.
        if (!empty($charge['transfer_data']['destination'])) $params['reverse_transfer'] = true;
        if (!empty($charge['application_fee'])) $params['refund_application_fee'] = true;
        return $this->client()->refunds->create($params,
            array_filter(['idempotency_key'=>$key, 'stripe_account'=>$account]))->toArray();
    }
}
