<?php

namespace App\Livewire\Admin\DeliveryTests;

use App\Livewire\Admin\Concerns\RequiresSuperAdmin;
use App\Models\OnesignalSubscription;
use App\Models\User;
use App\Services\OneSignalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Send a real OneSignal push and show who it went to.
 *
 * A device belongs to exactly one OneSignal app (customer or admin) and one
 * platform (ios, android, web), so the form starts there: pick the app, then
 * optionally narrow to a platform. Within that, three ways to choose devices:
 *
 *  - every active subscription of that app (and platform);
 *  - the devices of chosen users, found by name or email;
 *  - raw subscription ids pasted from the OneSignal dashboard, for a device
 *    that is not (yet) tied to a user row.
 *
 * Delivery goes through OneSignalService::sendToPlayerIds — the same per-app
 * credentials, targeting and logging a real notification uses — so a green
 * result here means the production path works. The targets are listed before
 * the send, and the notification id OneSignal returned is shown after it.
 */
class PushTest extends Component
{
    use RequiresSuperAdmin;

    public const PLATFORMS = ['ios', 'android', 'web'];

    public const TARGETS = ['all', 'users', 'ids'];

    /** OneSignal accepts this many subscription ids in one request. */
    public const ONESIGNAL_BATCH = 2000;

    /** Above this many devices the button asks for a second click. */
    public const CONFIRM_ABOVE = 5;

    /** How many target rows the preview table lists before it just counts. */
    public const PREVIEW_ROWS = 50;

    public string $app = OnesignalSubscription::APP_CUSTOMER;

    /** '' for every platform, else one of PLATFORMS. */
    public string $platform = '';

    public string $target = 'users';

    public string $userSearch = '';

    /** user id => ['id', 'name', 'email'] */
    public array $selectedUsers = [];

    /** Raw subscription ids, one per line or comma separated. */
    public string $rawIds = '';

    public string $title = 'Bookboll test';

    public string $message = 'Test push from the admin panel';

    /** data.type — what the mobile app routes on when the notification is tapped. */
    public string $type = 'test';

    /** Extra data as a JSON object, merged into the payload. */
    public string $extraData = '';

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    /** @var array{app: string, devices: int, users: int, batches: int, delivered: int, notification_ids: array<int, string>, results: array<int, array<string, mixed>>}|null */
    public ?array $lastSend = null;

    public function updatedApp(string $value): void
    {
        if (! in_array($value, OnesignalSubscription::APPS, true)) {
            $this->app = OnesignalSubscription::APP_CUSTOMER;
        }

        // A subscription id lives inside the app that issued it, so a user chosen
        // for their customer-app devices means nothing under the admin app.
        $this->selectedUsers = [];
        $this->lastSend = null;
    }

    public function updatedPlatform(string $value): void
    {
        if ($value !== '' && ! in_array($value, self::PLATFORMS, true)) {
            $this->platform = '';
        }

        $this->lastSend = null;
    }

    public function updatedTarget(string $value): void
    {
        if (! in_array($value, self::TARGETS, true)) {
            $this->target = 'users';
        }

        $this->lastSend = null;
    }

    /* ---------------------------------------------------------------------
     | Users
     --------------------------------------------------------------------- */

    public function addUser(int $userId): void
    {
        $user = User::query()->find($userId, ['id', 'name', 'email']);

        if ($user === null) {
            return;
        }

        $this->selectedUsers[$user->id] = [
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];

        $this->userSearch = '';
        $this->target = 'users';
    }

    public function removeUser(int $userId): void
    {
        unset($this->selectedUsers[$userId]);
    }

    public function addMyself(): void
    {
        $this->addUser((int) Auth::id());
    }

    public function clearUsers(): void
    {
        $this->selectedUsers = [];
    }

    /**
     * Users who actually have a device in this app (and platform). Searching
     * all users would offer people the push can never reach.
     *
     * @return array<int, array{id: int, name: string, email: string, devices: int}>
     */
    private function userMatches(): array
    {
        $term = trim($this->userSearch);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $users = User::query()
            ->whereKeyNot(array_keys($this->selectedUsers) ?: [0])
            ->whereHas('onesignalSubscriptions', fn (Builder $s) => $this->scopeSubscriptions($s))
            ->where(function (Builder $q) use ($like, $term) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->when(ctype_digit($term), fn (Builder $q) => $q->orWhere('id', (int) $term));
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);

        if ($users->isEmpty()) {
            return [];
        }

        $deviceCounts = $this->scopeSubscriptions(OnesignalSubscription::query())
            ->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, COUNT(*) AS c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        return $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => (string) $u->name,
            'email' => (string) $u->email,
            'devices' => (int) ($deviceCounts[$u->id] ?? 0),
        ])->all();
    }

    /* ---------------------------------------------------------------------
     | Targets
     --------------------------------------------------------------------- */

    /** Active subscriptions of the chosen app, narrowed to the chosen platform. */
    private function scopeSubscriptions(Builder $query): Builder
    {
        $query->where('is_active', true)->where(function (Builder $q) {
            // Rows written before the column existed are admin-app rows.
            $q->where('app', $this->app);
            if ($this->app === OnesignalSubscription::APP_ADMIN) {
                $q->orWhereNull('app');
            }
        });

        if ($this->platform !== '') {
            $query->where('device_type', $this->platform);
        }

        return $query;
    }

    /** The device rows the send would go to, for the two table-backed modes. */
    private function targetQuery(): ?Builder
    {
        if ($this->target === 'ids') {
            return null;
        }

        $query = $this->scopeSubscriptions(OnesignalSubscription::query())->with('user:id,name,email');

        if ($this->target === 'users') {
            $ids = array_keys($this->selectedUsers);
            $query->whereIn('user_id', $ids ?: [0]);
        }

        return $query;
    }

    /** @return array<int, string> */
    private function parsedRawIds(): array
    {
        $parts = preg_split('/[\s,;]+/', $this->rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map('trim', $parts)));
    }

    /** Selected users who have no device in this app/platform, so the form can say so. */
    private function usersWithoutDevice(): array
    {
        if ($this->target !== 'users' || $this->selectedUsers === []) {
            return [];
        }

        $with = $this->scopeSubscriptions(OnesignalSubscription::query())
            ->whereIn('user_id', array_keys($this->selectedUsers))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return array_values(array_filter($this->selectedUsers, fn (array $u) => ! in_array((int) $u['id'], $with, true)));
    }

    /* ---------------------------------------------------------------------
     | Sending
     --------------------------------------------------------------------- */

    public function send(OneSignalService $oneSignal): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;
        $this->lastSend = null;

        $this->validate([
            'app' => 'required|in:'.implode(',', OnesignalSubscription::APPS),
            'platform' => 'nullable|in:'.implode(',', self::PLATFORMS),
            'target' => 'required|in:'.implode(',', self::TARGETS),
            'title' => 'required|string|max:120',
            'message' => 'required|string|max:500',
            'type' => 'required|string|max:60|regex:/^[a-z0-9_\-.]+$/i',
            'extraData' => 'nullable|string|max:4000',
            'rawIds' => 'nullable|string|max:20000',
        ], [
            'type.regex' => 'Use letters, digits, dots, dashes or underscores only.',
        ]);

        if (! $oneSignal->isConfigured($this->app)) {
            $this->errorMessage = "The {$this->app} OneSignal app has no app id or REST key configured, so nothing can be sent to its devices.";

            return;
        }

        $data = ['type' => $this->type];
        if (trim($this->extraData) !== '') {
            $extra = json_decode($this->extraData, true);
            if (! is_array($extra) || array_is_list($extra)) {
                throw ValidationException::withMessages(['extraData' => 'Extra data must be a JSON object, e.g. {"booking_id": 123}.']);
            }
            $data = array_merge($extra, $data);
        }

        if ($this->target === 'ids') {
            $deviceIds = $this->parsedRawIds();
            if ($deviceIds === []) {
                throw ValidationException::withMessages(['rawIds' => 'Paste at least one subscription id.']);
            }
            $userCount = 0;
        } else {
            if ($this->target === 'users' && $this->selectedUsers === []) {
                throw ValidationException::withMessages(['userSearch' => 'Pick at least one user.']);
            }

            $rows = $this->targetQuery()->get(['user_id', 'onesignal_id']);
            $deviceIds = $rows->pluck('onesignal_id')->filter()->unique()->values()->all();
            $userCount = $rows->pluck('user_id')->unique()->count();

            if ($deviceIds === []) {
                $this->errorMessage = $this->target === 'users'
                    ? 'None of the chosen users has an active '.$this->describeScope().' device, so there is nothing to send to.'
                    : 'There are no active '.$this->describeScope().' devices registered.';

                return;
            }
        }

        $batches = array_chunk($deviceIds, self::ONESIGNAL_BATCH);
        $delivered = 0;
        $notificationIds = [];
        $results = [];

        foreach ($batches as $batch) {
            $ok = $oneSignal->sendToPlayerIds($batch, $this->title, $this->message, $data, $this->app);
            $result = $oneSignal->lastResult ?? [];

            $delivered += $ok ? count($batch) : 0;
            if ($ok && ! empty($result['notification_id'])) {
                $notificationIds[] = (string) $result['notification_id'];
            }

            $results[] = [
                'devices' => count($batch),
                'ok' => $ok,
                'status' => $result['status'] ?? null,
                'recipients' => $result['recipients'] ?? null,
                'errors' => $this->flattenErrors($result['errors'] ?? null),
                'exception' => $result['exception'] ?? null,
            ];
        }

        $this->lastSend = [
            'app' => $this->app,
            'devices' => count($deviceIds),
            'users' => $userCount,
            'batches' => count($batches),
            'delivered' => $delivered,
            'notification_ids' => $notificationIds,
            'results' => $results,
        ];

        activity('delivery_test')
            ->causedBy(Auth::user())
            ->withProperties([
                'channel' => 'push',
                'app' => $this->app,
                'platform' => $this->platform !== '' ? $this->platform : 'all',
                'target' => $this->target,
                'user_ids' => array_keys($this->selectedUsers),
                'device_count' => count($deviceIds),
                'title' => $this->title,
                'message' => $this->message,
                'data' => $data,
                'delivered' => $delivered > 0,
                'notification_ids' => $notificationIds,
            ])
            ->log(($delivered > 0 ? 'Sent' : 'Failed to send')." a test push to {$this->describeScope()} — ".count($deviceIds).' device(s)');

        if ($delivered === count($deviceIds)) {
            $this->statusMessage = 'OneSignal accepted the notification for '.count($deviceIds).' '.$this->describeScope().' device'.(count($deviceIds) === 1 ? '' : 's').'.';
        } elseif ($delivered > 0) {
            $this->errorMessage = "OneSignal accepted {$delivered} of ".count($deviceIds).' devices — see the batch results below.';
        } else {
            $this->errorMessage = 'OneSignal reached nobody. The ids may be unsubscribed, belong to the other app, or the REST key may be wrong — details below and in the log.';
        }
    }

    private function flattenErrors(mixed $errors): ?string
    {
        if ($errors === null || $errors === []) {
            return null;
        }

        if (is_string($errors)) {
            return $errors;
        }

        $flat = [];
        foreach ((array) $errors as $key => $value) {
            if (is_array($value)) {
                $flat[] = (is_string($key) ? $key.': ' : '').implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
            } else {
                $flat[] = (is_string($key) ? $key.': ' : '').(is_scalar($value) ? (string) $value : json_encode($value));
            }
        }

        return implode(' · ', $flat);
    }

    private function describeScope(): string
    {
        return $this->app.'-app'.($this->platform !== '' ? ' '.$this->platformLabel($this->platform) : '');
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'ios' => 'iOS',
            'android' => 'Android',
            'web' => 'Web',
            default => ucfirst($platform),
        };
    }

    /* ---------------------------------------------------------------------
     | Overview
     --------------------------------------------------------------------- */

    /**
     * Active device counts per app and platform — the "do we even have iOS
     * customers registered?" answer, and the thing that makes the platform
     * filter mean something.
     *
     * @return array<string, array{configured: bool, total: int, platforms: array<string, int>}>
     */
    private function inventory(OneSignalService $oneSignal): array
    {
        $rows = OnesignalSubscription::query()
            ->where('is_active', true)
            ->selectRaw("COALESCE(app, 'admin') AS app, COALESCE(device_type, 'unknown') AS device_type, COUNT(*) AS c")
            ->groupBy('app', 'device_type')
            ->get();

        $inventory = [];
        foreach (OnesignalSubscription::APPS as $app) {
            $inventory[$app] = [
                'configured' => $oneSignal->isConfigured($app),
                'total' => 0,
                'platforms' => array_fill_keys([...self::PLATFORMS, 'unknown'], 0),
            ];
        }

        foreach ($rows as $row) {
            $app = (string) $row->app;
            if (! isset($inventory[$app])) {
                continue;
            }
            $platform = in_array($row->device_type, self::PLATFORMS, true) ? $row->device_type : 'unknown';
            $inventory[$app]['platforms'][$platform] += (int) $row->c;
            $inventory[$app]['total'] += (int) $row->c;
        }

        return $inventory;
    }

    public function render(OneSignalService $oneSignal)
    {
        $preview = null;
        $previewTotal = 0;
        $previewUsers = 0;

        if ($this->target === 'ids') {
            $previewTotal = count($this->parsedRawIds());
        } elseif ($this->target === 'all' || $this->selectedUsers !== []) {
            $query = $this->targetQuery();
            $previewTotal = (clone $query)->count();
            $previewUsers = (clone $query)->distinct()->count('user_id');
            $preview = $query->orderBy('user_id')->orderBy('id')->limit(self::PREVIEW_ROWS)->get();
        }

        return view('livewire.admin.delivery-tests.push-test', [
            'inventory' => $this->inventory($oneSignal),
            'userMatches' => $this->userMatches(),
            'usersWithoutDevice' => $this->usersWithoutDevice(),
            'preview' => $preview,
            'previewTotal' => $previewTotal,
            'previewUsers' => $previewUsers,
            'previewRows' => self::PREVIEW_ROWS,
            'confirmAbove' => self::CONFIRM_ABOVE,
            'platformLabels' => array_combine(self::PLATFORMS, array_map(fn ($p) => $this->platformLabel($p), self::PLATFORMS)),
            'scopeLabel' => $this->describeScope(),
        ]);
    }
}
