<?php
namespace App\Services;

use App\Models\OnesignalSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push delivery through OneSignal.
 *
 * There are TWO OneSignal apps — the admin (business) app and the customer
 * app — because Apple binds an APNs configuration to a single bundle id. A
 * push subscription id exists only inside the app that issued it, so every
 * device row carries `app`, and a send is split per app and dispatched with
 * that app's own app id and REST key. Rows written before the column existed
 * are admin-app rows: the admin app was the only client registering devices.
 */
class OneSignalService
{
    /** @var array<string, array{app_id: string, rest_api_key: string}> */
    protected array $apps = [];

    /**
     * What OneSignal said to the most recent dispatch — notification id,
     * recipient count, errors and HTTP status — so a caller that wants to show
     * the outcome (the admin panel's delivery test) can, without every send
     * having to return more than a bool.
     *
     * @var array{app: string, strategy: string, status: int|null, notification_id: string|null, recipients: int|null, errors: mixed, exception: string|null}|null
     */
    public ?array $lastResult = null;

    /** Whether this app has an app id and REST key configured. */
    public function isConfigured(string $app): bool
    {
        return $this->credentials($app) !== null;
    }

    public function __construct()
    {
        $configured = (array) config('services.onesignal.apps', []);

        // The legacy flat keys remain the admin app's, for deployments that have
        // not added the `apps` block yet.
        if (! isset($configured[OnesignalSubscription::APP_ADMIN])) {
            $configured[OnesignalSubscription::APP_ADMIN] = [
                'app_id'       => config('services.onesignal.app_id'),
                'rest_api_key' => config('services.onesignal.rest_api_key'),
            ];
        }

        foreach ($configured as $app => $creds) {
            $this->apps[$app] = [
                'app_id'       => trim((string) ($creds['app_id'] ?? '')),
                'rest_api_key' => trim((string) ($creds['rest_api_key'] ?? '')),
            ];
        }
    }

    /** Credentials for one app, or null when that app is not configured. */
    protected function credentials(string $app): ?array
    {
        $creds = $this->apps[$app] ?? null;

        return ($creds && $creds['app_id'] !== '' && $creds['rest_api_key'] !== '') ? $creds : null;
    }

    public function sendToUsers(array $userIds, string $title, string $message, array $data = []): bool
    {
        if (empty($userIds) || empty($this->apps)) {
            Log::warning('OneSignal: Missing configuration or user IDs', ['user_ids' => $userIds]);
            return false;
        }

        $subscriptions = OnesignalSubscription::whereIn('user_id', $userIds)
            ->active()
            ->get(['user_id', 'onesignal_id', 'app']);

        if ($subscriptions->isEmpty()) {
            Log::info('OneSignal: No active subscriptions found for users', ['user_ids' => $userIds]);
            return false;
        }

        // A recipient with no registered device used to vanish without a trace:
        // this only warned when EVERY recipient was missing, so one admin with a
        // device was enough to make a send look completely healthy while the
        // business owner silently received nothing. Name them instead.
        $missing = array_values(array_diff(
            array_map('intval', $userIds),
            $subscriptions->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all()
        ));

        if ($missing) {
            Log::warning('OneSignal: recipients have no active subscription — they will not be notified', [
                'user_ids_without_subscription' => $missing,
                'notified_user_count'           => count($userIds) - count($missing),
                'title'                         => $title,
            ]);
        }

        // One send per app: a customer-app subscription id is unknown to the
        // admin app and vice versa, so mixing them in one request drops half.
        $delivered = false;
        foreach ($subscriptions->groupBy(fn ($s) => $s->app ?: OnesignalSubscription::APP_ADMIN) as $app => $rows) {
            $delivered = $this->sendToPlayerIds($rows->pluck('onesignal_id')->all(), $title, $message, $data, (string) $app) || $delivered;
        }

        return $delivered;
    }

    /**
     * Push to a set of device ids that all belong to ONE OneSignal app.
     *
     * `onesignal_subscriptions.onesignal_id` holds whatever the mobile SDK
     * handed us, and the current app builds hand us the push SUBSCRIPTION id
     * (`OneSignal.User.pushSubscription.id`) — not the OneSignal USER id.
     *
     * Those are different values, and `include_aliases.onesignal_id` resolves
     * USER ids only. Sending subscription ids that way matches nobody, and
     * OneSignal reports it as HTTP 200 with an empty `id` and
     * "All included players are not subscribed" — which the old success check
     * read as a successful send. So target subscription ids first, which is
     * what we actually store, and fall back to aliases for legacy rows written
     * by older app builds.
     *
     * @param  string  $app  'admin' or 'customer' — which app issued the ids
     */
    public function sendToPlayerIds(array $playerIds, string $title, string $message, array $data = [], string $app = OnesignalSubscription::APP_ADMIN): bool
    {
        $playerIds = array_values(array_unique(array_filter(
            is_array($playerIds) ? $playerIds : [$playerIds]
        )));

        if (empty($playerIds)) {
            return false;
        }

        $creds = $this->credentials($app);
        if (! $creds) {
            // Named loudly: devices registered under an app with no keys would
            // otherwise fail silently on every send.
            Log::warning('OneSignal: no credentials configured for app — devices not notified', [
                'app'          => $app,
                'device_count' => count($playerIds),
                'title'        => $title,
            ]);

            return false;
        }

        if ($this->dispatch($creds, $app, ['include_subscription_ids' => $playerIds], $title, $message, $data)) {
            return true;
        }

        // Nothing resolved as a subscription — the rows may predate the current
        // app build and hold OneSignal user ids instead.
        return $this->dispatch(
            $creds,
            $app,
            ['include_aliases' => ['onesignal_id' => $playerIds]],
            $title,
            $message,
            $data,
            true
        );
    }

    /**
     * One attempt at the notifications endpoint.
     *
     * @param  array   $creds      the app's app_id + rest_api_key
     * @param  string  $app        which app, for the log line
     * @param  array   $targeting  the recipient selector to merge into the payload
     * @param  bool    $isFallback whether this is the retry, so the log says which
     */
    protected function dispatch(array $creds, string $app, array $targeting, string $title, string $message, array $data, bool $isFallback = false): bool
    {
        $strategy = $isFallback ? 'include_aliases.onesignal_id' : 'include_subscription_ids';

        try {
            $payload = array_merge([
                'app_id'         => $creds['app_id'],
                'headings'       => ['en' => config('app.name', 'Bookboll')],
                'subtitle'       => ['en' => $title],
                'contents'       => ['en' => $message],
                'target_channel' => 'push',
                'data'           => $data,
            ], $targeting);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $creds['rest_api_key'],
                'Content-Type'  => 'application/json',
            ])->post('https://api.onesignal.com/notifications', $payload);

            $body = $response->json();

            // OneSignal answers 200 even when it reached nobody: the giveaway is
            // an empty `id` plus an `errors` entry. `isset($body['id'])` is true
            // for an empty string, which is how a silent total failure used to
            // log as a success.
            $notificationId = is_array($body) ? ($body['id'] ?? null) : null;
            $delivered      = $response->successful() && is_string($notificationId) && $notificationId !== '';

            $this->lastResult = [
                'app'             => $app,
                'strategy'        => $strategy,
                'status'          => $response->status(),
                'notification_id' => $delivered ? $notificationId : null,
                'recipients'      => is_array($body) && isset($body['recipients']) ? (int) $body['recipients'] : null,
                'errors'          => is_array($body) ? ($body['errors'] ?? null) : null,
                'exception'       => null,
            ];

            if ($delivered) {
                Log::info('OneSignal notification sent', [
                    'app'             => $app,
                    'strategy'        => $strategy,
                    'notification_id' => $notificationId,
                    'recipients'      => $body['recipients'] ?? null,
                    'device_count'    => count($targeting['include_subscription_ids'] ?? $targeting['include_aliases']['onesignal_id'] ?? []),
                    'title'           => $title,
                ]);

                return true;
            }

            $log = $isFallback ? 'error' : 'info';

            Log::{$log}('OneSignal notification reached nobody', [
                'app'      => $app,
                'strategy' => $strategy,
                'status'   => $response->status(),
                'errors'   => is_array($body) ? ($body['errors'] ?? null) : null,
                'title'    => $title,
                'message'  => $message,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->lastResult = [
                'app'             => $app,
                'strategy'        => $strategy,
                'status'          => null,
                'notification_id' => null,
                'recipients'      => null,
                'errors'          => null,
                'exception'       => $e->getMessage(),
            ];

            Log::error('OneSignal notification exception', [
                'app'      => $app,
                'strategy' => $strategy,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function sendToRoles($roles, string $title, string $message, array $data = []): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        $userIds = User::whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        })->active()->pluck('id')->toArray();

        return $this->sendToUsers($userIds, $title, $message, $data);
    }
}
