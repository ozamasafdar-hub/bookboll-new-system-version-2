<?php
namespace App\Providers;

use App\Observers\ClassSubscriptionObserver;
use App\Observers\PaymentObserver;
use App\Support\GeoSoftDeletes;
use App\Support\TenantOrigins;
use App\Observers\BookingObserver;
use App\Models\ClassSubscription;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Pagination\Paginator;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useTailwind();

        // Carries the client address and device on every API session, which the
        // admin panel's session list reads and Sanctum's own model has no room
        // for. Must be registered before the first token is issued or read.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Booking::observe(BookingObserver::class);
        ClassSubscription::observe(ClassSubscriptionObserver::class);
        Payment::observe(PaymentObserver::class);
        \Illuminate\Support\Facades\DB::listen(\App\Support\PaymentReportCache::written(...));
        if (config('preview.workspace')) \App\Support\WorkspaceIntegrations::disableOutbound();

        Gate::define('viewLogViewer', function ($user = null) {
            return $user?->hasRole('super_admin') ?? false;
        });

        $key    = trim((string) config('services.stripe.key'));
        $secret = trim((string) config('services.stripe.secret'));

        if ($key !== '') {
            config(['cashier.key' => $key]);
        }

        if ($secret !== '') {
            config(['cashier.secret' => $secret]);
        }

        GeoSoftDeletes::register();

        $this->allowCorsFromProviderDomains();
        $this->nameFailedScheduledTasks();
    }

    /**
     * The scheduler reports a failed task as a bare exception — "signaled with
     * signal 9" says nothing about which command died. This fires just before
     * that report, so the log names the task next to the error.
     */
    private function nameFailedScheduledTasks(): void
    {
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            Log::error('Scheduled task failed', [
                'command' => $event->task->command,
                'error'   => $event->exception->getMessage(),
            ]);
        });
    }

    /**
     * Let storefronts on a provider's own domain call the API from a browser.
     *
     * config/cors.php can pattern-match *.bookboll.com but not the domains providers
     * buy and connect at runtime, so without this a paid custom domain is blocked by
     * CORS on every request — including the Google sign-in handshake, which is half
     * the reason the broker exists.
     *
     * Read from the same cached list as RestrictApiOrigin, so "allowed to call us" and
     * "allowed to be sent a session" cannot drift apart. Failure is swallowed on
     * purpose: a cache or database hiccup must not take down every CORS preflight, and
     * the configured origins still work.
     *
     * Only consulted for an Origin config/cors.php does not already answer. This runs
     * in boot(), before routing, so an unconditional lookup made every request — a
     * preflight, a webhook, a health check, a 404 — read the cache, and on shared
     * hosting that meant opening a MySQL connection and holding it for the life of
     * the request. Thirty of those is the whole connection allowance.
     */
    private function allowCorsFromProviderDomains(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $origin = trim((string) request()->headers->get('Origin'));

        // No Origin header is not a cross-origin call, so CORS answers nothing here.
        if ($origin === '' || $this->corsAlreadyAllows($origin)) {
            return;
        }

        try {
            $origins = array_map(fn ($domain) => 'https://'.$domain, TenantOrigins::customDomains());

            if ($origins !== []) {
                config(['cors.allowed_origins' => array_values(array_unique(
                    array_merge(config('cors.allowed_origins', []), $origins)
                ))]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether the statically configured CORS rules already cover this origin —
     * the marketplace, the dashboards, localhost, and every white-label
     * <slug>.bookboll.com storefront. Same two tests the CORS handler itself
     * applies, so an origin that passes here needs no custom-domain lookup.
     */
    private function corsAlreadyAllows(string $origin): bool
    {
        $allowed = config('cors.allowed_origins', []);

        if (in_array('*', $allowed, true)) {
            return true;
        }

        foreach ($allowed as $candidate) {
            if (strcasecmp($origin, (string) $candidate) === 0) {
                return true;
            }
        }

        foreach (config('cors.allowed_origins_patterns', []) as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
