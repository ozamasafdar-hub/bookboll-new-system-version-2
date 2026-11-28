<?php
namespace App\Support;

/** Every SDK request, including copied gateway credentials, must use this sandbox's key. */
final class WorkspaceStripeClient implements \Stripe\HttpClient\ClientInterface
{
    public function __construct(private \Stripe\HttpClient\ClientInterface $inner) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $auth = array_values(array_filter($headers, fn ($header) => is_string($header) && str_starts_with(strtolower($header), 'authorization:')));
        if (!WorkspaceStripeSandbox::enabled() || parse_url($absUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($absUrl, PHP_URL_HOST) !== 'api.stripe.com'
            || count($auth) !== 1 || !hash_equals('Bearer '.config('services.stripe.secret'), trim(substr($auth[0], 14)))) {
            throw new \Stripe\Exception\ApiConnectionException('Only the configured Stripe sandbox is allowed in this Railway workspace. No live payment was made.');
        }
        $result = $this->inner->request($method, $absUrl, $headers, $params, $hasFile, $apiMode);
        $body = json_decode($result[0], true);
        if (($body['livemode'] ?? false) === true) {
            throw new \Stripe\Exception\ApiConnectionException('A live Stripe response is not allowed in the Railway workspace.');
        }
        return $result;
    }
}
