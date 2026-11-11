<?php
namespace App\Support;

/** Covers Stripe SDK calls from HTTP, jobs, scheduler and console entry points. */
class ReleaseGuardedStripeClient implements \Stripe\HttpClient\ClientInterface
{
    public function __construct(private \Stripe\HttpClient\ClientInterface $inner) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        if (!in_array(strtoupper($method), ['GET','HEAD'], true)) {
            $path = parse_url($absUrl, PHP_URL_PATH) ?: '';
            app(PaymentReleaseGate::class)->assertReady(str_contains($path, '/refunds') ? 'refunds' : 'processor');
        }
        return $this->inner->request($method, $absUrl, $headers, $params, $hasFile, $apiMode);
    }
}
