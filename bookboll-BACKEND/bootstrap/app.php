<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [\App\Http\Middleware\PaymentsPreviewAccess::class]);
        $middleware->api(append: [\App\Http\Middleware\RequirePaymentRelease::class]);
        $middleware->web(append: [\App\Http\Middleware\RequirePaymentRelease::class]);
        $middleware->alias([
            'checkRoles' => \App\Http\Middleware\CheckRole::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            // Capability gate — the server-side twin of the dashboard's can(CAP.…).
            // Several capabilities mean "any of", as in PAGE_CAP.
            'capability' => \App\Http\Middleware\CheckCapability::class,
            // Payment routes that cannot honour a rewards quote yet refuse one
            // outright, rather than charging full price while the customer
            // believes their points were applied.
            'rewards.unsupported' => \App\Http\Middleware\RejectUnsupportedRewards::class,
        ]);

        // Response headers for every surface this origin serves — the JSON API
        // and the Livewire admin panel alike. Appended rather than prepended so
        // a controller that has set its own framing answer keeps it.
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
        $middleware->api(append: [\App\Http\Middleware\SecurityHeaders::class]);

        $middleware->preventRequestForgery(except: [
            'stripe/bookboll-webhook',
            'broadcasting/auth',
            'api/broadcasting/auth',
        ]);

        // $middleware->api(prepend: [
        //     \App\Http\Middleware\RestrictApiOrigin::class,
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An authentication or authorization failure is rendered here and
        // unwinds without the middleware's response phase, so those 401/403
        // answers — the admin area's included — went out with no security
        // headers at all. Same definition as the middleware, applied once more.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            return \App\Http\Middleware\SecurityHeaders::apply($request, $response);
        });

        $exceptions->report(function (Exception $e) {
            try {
                \Illuminate\Support\Facades\Mail::to('alamgir000009@gmail.com')
                    ->send(new \App\Mail\ExceptionNotificationEmail($e, [
                        'user_id' => auth()->id(),
                        'request_data' => request()->all(),
                    ]));
            } catch (\Exception $mailException) {
                \Illuminate\Support\Facades\Log::error('Failed to send exception notification email: ' . $mailException->getMessage());
            }
        });
    })->create();
