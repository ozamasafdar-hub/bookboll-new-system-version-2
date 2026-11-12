<div class="space-y-6">
    @if ($statusMessage)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errorMessage }}</div>
    @endif

    {{-- ───────────── Inventory: which app, which platform ───────────── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        @foreach ($inventory as $appKey => $info)
            <button type="button"
                    wire:click="$set('app', '{{ $appKey }}')"
                    wire:key="inventory-{{ $appKey }}"
                    @class([
                        'rounded-lg border bg-white p-4 text-left shadow-sm transition hover:border-sidebar',
                        'border-sidebar ring-1 ring-sidebar' => $app === $appKey,
                        'border-gray-200' => $app !== $appKey,
                    ])>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ ucfirst($appKey) }} app</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ number_format($info['total']) }}
                            <span class="text-sm font-medium text-gray-500">active device{{ $info['total'] === 1 ? '' : 's' }}</span>
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $info['configured'] ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700' }}">
                        {{ $info['configured'] ? 'Keys configured' : 'No keys' }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($info['platforms'] as $p => $count)
                        @continue($p === 'unknown' && $count === 0)
                        <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-700">
                            {{ $platformLabels[$p] ?? 'Unknown platform' }}
                            <span class="font-bold tabular-nums">{{ number_format($count) }}</span>
                        </span>
                    @endforeach
                </div>
            </button>
        @endforeach
    </div>

    <form wire:submit="send" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ───────────── Left: who ───────────── --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="content-card space-y-5">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Who receives it</h3>
                    <p class="mt-0.5 text-xs text-gray-500">A device belongs to one app and one platform. Pick the app above, narrow by platform, then choose devices.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="push-app" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">OneSignal app</label>
                        <select id="push-app" wire:model.live="app" class="input-brand">
                            <option value="customer">Customer app</option>
                            <option value="admin">Admin (business) app</option>
                        </select>
                    </div>
                    <div>
                        <label for="push-platform" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Platform</label>
                        <select id="push-platform" wire:model.live="platform" class="input-brand">
                            <option value="">All platforms</option>
                            @foreach ($platformLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }} only</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <p class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Devices</p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        @foreach ([
                            'users' => ['label' => 'Chosen users', 'help' => 'Every device of the users you pick.'],
                            'all' => ['label' => 'Every device', 'help' => 'All active '.$scopeLabel.' subscriptions.'],
                            'ids' => ['label' => 'Subscription ids', 'help' => 'Raw ids from the OneSignal dashboard.'],
                        ] as $value => $meta)
                            <label wire:key="target-{{ $value }}"
                                   @class([
                                       'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition',
                                       'border-sidebar bg-sidebar/5 ring-1 ring-sidebar' => $target === $value,
                                       'border-gray-200 bg-white hover:bg-gray-50' => $target !== $value,
                                   ])>
                                <input type="radio" wire:model.live="target" value="{{ $value }}" class="mt-0.5 h-4 w-4 border-gray-300 text-sidebar focus:ring-sidebar" />
                                <span>
                                    <span class="block text-sm font-semibold text-gray-900">{{ $meta['label'] }}</span>
                                    <span class="block text-[11px] text-gray-500">{{ $meta['help'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if ($target === 'users')
                    <div>
                        <label for="push-user-search" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Users with a {{ $scopeLabel }} device</label>
                        <div class="relative">
                            <input type="text" id="push-user-search"
                                   wire:model.live.debounce.300ms="userSearch"
                                   placeholder="Search by name, email or user id…"
                                   autocomplete="off"
                                   class="input-brand" />

                            @if ($userMatches !== [])
                                <ul class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                                    @foreach ($userMatches as $match)
                                        <li wire:key="push-match-{{ $match['id'] }}">
                                            <button type="button" wire:click="addUser({{ $match['id'] }})"
                                                    class="flex w-full items-center gap-3 px-3 py-2 text-left transition hover:bg-gray-50">
                                                <span class="sessions-avatar">{{ strtoupper(substr($match['name'] !== '' ? $match['name'] : $match['email'], 0, 2)) }}</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-gray-900">{{ $match['name'] !== '' ? $match['name'] : '—' }}</span>
                                                    <span class="block truncate text-[11px] text-gray-500">{{ $match['email'] }} · #{{ $match['id'] }}</span>
                                                </span>
                                                <span class="shrink-0 rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700">{{ $match['devices'] }} device{{ $match['devices'] === 1 ? '' : 's' }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (mb_strlen(trim($userSearch)) >= 2)
                                <p class="mt-1 text-xs text-gray-500">No user with a {{ $scopeLabel }} device matches “{{ $userSearch }}”.</p>
                            @endif
                        </div>
                        @error('userSearch')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="addMyself"
                                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                                + Add me
                            </button>

                            @foreach ($selectedUsers as $user)
                                <span wire:key="push-user-{{ $user['id'] }}"
                                      class="inline-flex items-center gap-1.5 rounded-full border border-sidebar/30 bg-sidebar/10 py-1 pl-3 pr-1.5 text-xs font-medium text-sidebar">
                                    <span class="truncate" title="{{ $user['email'] }}">{{ $user['name'] !== '' ? $user['name'] : $user['email'] }}</span>
                                    <button type="button" wire:click="removeUser({{ $user['id'] }})" aria-label="Remove {{ $user['email'] }}"
                                            class="rounded-full p-0.5 transition hover:bg-sidebar hover:text-white">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </span>
                            @endforeach

                            @if ($selectedUsers !== [])
                                <button type="button" wire:click="clearUsers" class="text-xs font-medium text-gray-500 hover:text-gray-800">Clear</button>
                            @endif
                        </div>

                        @if ($usersWithoutDevice !== [])
                            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                No active {{ $scopeLabel }} device:
                                {{ collect($usersWithoutDevice)->map(fn ($u) => $u['name'] !== '' ? $u['name'] : $u['email'])->join(', ') }}.
                                They will not receive this push.
                            </div>
                        @endif
                    </div>
                @elseif ($target === 'ids')
                    <div>
                        <label for="push-raw-ids" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Subscription ids for the {{ $app }} app</label>
                        <textarea id="push-raw-ids" rows="4" wire:model.live.debounce.400ms="rawIds"
                                  placeholder="one id per line, or comma separated"
                                  class="input-brand font-mono text-sm"></textarea>
                        <p class="mt-1 text-[11px] text-gray-400">The push <em>subscription</em> id (OneSignal → Audience → Subscriptions), not the OneSignal user id. Platform filter does not apply here.</p>
                        @error('rawIds')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>

            {{-- ───────────── Preview of targets ───────────── --}}
            @if ($target !== 'ids')
                <div class="sessions-table-wrap">
                    <div class="flex flex-col gap-1 border-b border-gray-100 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Will be sent to</h3>
                            <p class="mt-0.5 text-xs text-gray-500">
                                @if ($previewTotal > 0)
                                    {{ number_format($previewTotal) }} {{ $scopeLabel }} device{{ $previewTotal === 1 ? '' : 's' }} across {{ number_format($previewUsers) }} user{{ $previewUsers === 1 ? '' : 's' }}
                                    @if ($previewTotal > $previewRows) · first {{ $previewRows }} shown @endif
                                @elseif ($target === 'users' && $selectedUsers === [])
                                    Pick a user above.
                                @else
                                    Nothing matches — no active {{ $scopeLabel }} devices.
                                @endif
                            </p>
                        </div>
                    </div>

                    @if ($preview && $preview->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="sessions-table">
                                <thead><tr><th>User</th><th>Platform</th><th>Device</th><th>Subscription id</th><th>Registered</th></tr></thead>
                                <tbody>
                                    @foreach ($preview as $sub)
                                        <tr wire:key="preview-{{ $sub->id }}">
                                            <td>
                                                <p class="text-sm font-medium text-gray-900">{{ $sub->user?->name ?: ($sub->user?->email ?? 'User #'.$sub->user_id) }}</p>
                                                <p class="text-[11px] text-gray-500">{{ $sub->user?->email ?? '—' }} · #{{ $sub->user_id }}</p>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700">
                                                    {{ $platformLabels[$sub->device_type] ?? ($sub->device_type ?: 'Unknown') }}
                                                </span>
                                            </td>
                                            <td class="text-xs text-gray-600">{{ $sub->device_info ?: '—' }}</td>
                                            <td class="font-mono text-[11px] text-gray-600">{{ $sub->onesignal_id }}</td>
                                            <td class="text-xs text-gray-500">{{ $sub->created_at?->diffForHumans() ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ───────────── Result of last send ───────────── --}}
            @if ($lastSend)
                <div class="sessions-table-wrap">
                    <div class="border-b border-gray-100 bg-white px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">Last send</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ number_format($lastSend['devices']) }} device{{ $lastSend['devices'] === 1 ? '' : 's' }}
                            @if ($lastSend['users'] > 0) across {{ number_format($lastSend['users']) }} user{{ $lastSend['users'] === 1 ? '' : 's' }} @endif
                            · {{ $lastSend['app'] }} app
                            @if ($lastSend['batches'] > 1) · {{ $lastSend['batches'] }} batches @endif
                        </p>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach ($lastSend['results'] as $i => $r)
                            <div class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-start sm:justify-between" wire:key="push-result-{{ $i }}">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $r['ok'] ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700' }}">
                                        {{ $r['ok'] ? 'Accepted' : 'Reached nobody' }}
                                    </span>
                                    <span class="text-xs text-gray-600">
                                        {{ $r['devices'] }} id{{ $r['devices'] === 1 ? '' : 's' }} sent
                                        @if ($r['recipients'] !== null) · OneSignal counted {{ $r['recipients'] }} recipient{{ $r['recipients'] === 1 ? '' : 's' }} @endif
                                        @if ($r['status'] !== null) · HTTP {{ $r['status'] }} @endif
                                    </span>
                                </div>
                                @if ($r['errors'] || $r['exception'])
                                    <p class="text-xs text-red-700">{{ $r['exception'] ?? $r['errors'] }}</p>
                                @endif
                            </div>
                        @endforeach
                        @if ($lastSend['notification_ids'] !== [])
                            <div class="px-5 py-3 text-xs text-gray-600">
                                Notification id{{ count($lastSend['notification_ids']) === 1 ? '' : 's' }}:
                                <span class="font-mono text-gray-800">{{ implode(', ', $lastSend['notification_ids']) }}</span>
                                — look it up under Delivery in the OneSignal dashboard.
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- ───────────── Right: what + send ───────────── --}}
        <div class="space-y-6">
            <div class="content-card space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Notification</h3>
                    <p class="mt-0.5 text-xs text-gray-500">The heading is always the app name; this title shows as the subtitle, exactly like a real notification.</p>
                </div>

                <div>
                    <label for="push-title" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Title</label>
                    <input type="text" id="push-title" wire:model="title" class="input-brand" />
                    @error('title')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="push-message" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Message</label>
                    <textarea id="push-message" rows="3" wire:model="message" class="input-brand"></textarea>
                    @error('message')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="push-type" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">data.type</label>
                    <input type="text" id="push-type" wire:model="type" class="input-brand font-mono text-sm" placeholder="test" />
                    <p class="mt-1 text-[11px] text-gray-400">What the app routes on when tapped — <span class="font-mono">test</span>, <span class="font-mono">booking_created</span>, <span class="font-mono">community_request</span>…</p>
                    @error('type')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="push-extra" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Extra data (JSON)</label>
                    <textarea id="push-extra" rows="2" wire:model="extraData" class="input-brand font-mono text-sm" placeholder='{"booking_id": 123}'></textarea>
                    @error('extraData')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            @php
                $sendDisabled = ! $inventory[$app]['configured']
                    || ($target === 'users' && $selectedUsers === [])
                    || ($target !== 'ids' && $previewTotal === 0)
                    || ($target === 'ids' && $previewTotal === 0);
            @endphp

            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    @if ($previewTotal > $confirmAbove)
                        wire:confirm="Send this push to {{ number_format($previewTotal) }} {{ $scopeLabel }} devices?"
                    @endif
                    @disabled($sendDisabled)
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-sidebar px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sidebar-dark disabled:cursor-not-allowed disabled:opacity-50">
                <span wire:loading.remove wire:target="send">
                    @if (! $inventory[$app]['configured'])
                        {{ ucfirst($app) }} app has no OneSignal keys
                    @elseif ($previewTotal > 0)
                        Send test push to {{ number_format($previewTotal) }} device{{ $previewTotal === 1 ? '' : 's' }}
                    @else
                        Send test push
                    @endif
                </span>
                <span wire:loading wire:target="send">Talking to OneSignal…</span>
            </button>

            <p class="text-center text-[11px] text-gray-400">
                Every send is recorded in <a href="{{ route('activities.index', ['log_name' => 'delivery_test']) }}" wire:navigate class="underline hover:text-gray-600">Activities</a>
                and <a href="{{ route('log-viewer.index') }}" class="underline hover:text-gray-600">the log</a>.
            </p>
        </div>
    </form>
</div>
