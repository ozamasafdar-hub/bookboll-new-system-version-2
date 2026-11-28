<?php
namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** Shared review workspace: no real charges or messages, even with copied keys. */
final class WorkspaceIntegrations
{
    public static function disableOutbound(): void
    {
        Event::listen(MessageSending::class, fn () => false);
        Http::globalRequestMiddleware(function ($request) {
            if (!in_array(strtoupper($request->getMethod()), ['GET','HEAD'], true)) {
                throw new \RuntimeException('External payments and outbound messages are disabled in the Railway workspace.');
            }
            return $request;
        });
        \Stripe\ApiRequestor::setHttpClient(new WorkspaceStripeClient(new \Stripe\HttpClient\CurlClient()));
    }
}
