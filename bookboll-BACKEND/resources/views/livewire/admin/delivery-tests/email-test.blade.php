<div class="space-y-6">
    @if ($statusMessage)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $statusMessage }}</div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errorMessage }}</div>
    @endif

    <form wire:submit="send" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ───────────── Left: recipients + message ───────────── --}}
        <div class="space-y-6 lg:col-span-2">
            <div class="content-card space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Recipients</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Existing users, typed-in addresses, or both. Each address gets its own message. Up to {{ $maxRecipients }} at once.
                        </p>
                    </div>
                    <span @class([
                        'shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                        'bg-sidebar/10 text-sidebar' => $recipientCount > 0,
                        'bg-gray-100 text-gray-500' => $recipientCount === 0,
                    ])>{{ $recipientCount }} recipient{{ $recipientCount === 1 ? '' : 's' }}</span>
                </div>

                <div>
                    <label for="email-user-search" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Existing users</label>
                    <div class="relative">
                        <input type="text" id="email-user-search"
                               wire:model.live.debounce.300ms="userSearch"
                               placeholder="Search by name, email or user id…"
                               autocomplete="off"
                               class="input-brand" />

                        @if ($userMatches !== [])
                            <ul class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                                @foreach ($userMatches as $match)
                                    <li wire:key="email-match-{{ $match['id'] }}">
                                        <button type="button" wire:click="addUser({{ $match['id'] }})"
                                                class="flex w-full items-center gap-3 px-3 py-2 text-left transition hover:bg-gray-50">
                                            <span class="sessions-avatar">{{ strtoupper(substr($match['name'] !== '' ? $match['name'] : $match['email'], 0, 2)) }}</span>
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium text-gray-900">{{ $match['name'] !== '' ? $match['name'] : '—' }}</span>
                                                <span class="block truncate text-[11px] text-gray-500">{{ $match['email'] }} · #{{ $match['id'] }}</span>
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($userSearch)) >= 2)
                            <p class="mt-1 text-xs text-gray-500">No users match “{{ $userSearch }}”.</p>
                        @endif
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="addMyself"
                                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                            + Send to me ({{ auth()->user()->email }})
                        </button>

                        @foreach ($selectedUsers as $user)
                            <span wire:key="email-user-{{ $user['id'] }}"
                                  class="inline-flex items-center gap-1.5 rounded-full border border-sidebar/30 bg-sidebar/10 py-1 pl-3 pr-1.5 text-xs font-medium text-sidebar">
                                <span class="truncate" title="{{ $user['email'] }}">{{ $user['name'] !== '' ? $user['name'] : $user['email'] }}</span>
                                <button type="button" wire:click="removeUser({{ $user['id'] }})" aria-label="Remove {{ $user['email'] }}"
                                        class="rounded-full p-0.5 transition hover:bg-sidebar hover:text-white">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </span>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="email-emails" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Other addresses</label>
                    <textarea id="email-emails" rows="3" wire:model.live.debounce.400ms="emails"
                              placeholder="one@example.com, two@example.com — commas, spaces or new lines"
                              class="input-brand font-mono text-sm"></textarea>
                    @error('emails')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>

                @if ($recipientCount > 0)
                    <div class="border-t border-gray-100 pt-3">
                        <button type="button" wire:click="clearRecipients" class="text-xs font-medium text-gray-500 hover:text-gray-800">Clear all recipients</button>
                    </div>
                @endif
            </div>

            <div class="content-card space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Message</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Wrapped in the standard Bookboll email template, with who sent it and through which mailer.</p>
                </div>

                <div>
                    <label for="email-subject" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Subject</label>
                    <input type="text" id="email-subject" wire:model="subject" class="input-brand" />
                    @error('subject')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="email-body" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Body</label>
                    <textarea id="email-body" rows="5" wire:model="body" class="input-brand"></textarea>
                    @error('body')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            @if ($results !== [])
                <div class="sessions-table-wrap">
                    <div class="border-b border-gray-100 bg-white px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">Last send</h3>
                        <p class="mt-0.5 text-xs text-gray-500">What the mail server said, per address. “Accepted” means handed over — delivery to the inbox is the receiving server's job.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="sessions-table">
                            <thead><tr><th>Address</th><th>Result</th><th>Detail</th></tr></thead>
                            <tbody>
                                @foreach ($results as $i => $row)
                                    <tr wire:key="email-result-{{ $i }}">
                                        <td class="font-mono text-xs text-gray-800">{{ $row['to'] }}</td>
                                        <td>
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $row['ok'] ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700' }}">
                                                {{ $row['ok'] ? 'Accepted' : 'Failed' }}
                                            </span>
                                        </td>
                                        <td class="text-xs text-gray-600">{{ $row['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ───────────── Right: sender + send button ───────────── --}}
        <div class="space-y-6">
            <div class="content-card space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">Send through</h3>
                    <p class="mt-0.5 text-xs text-gray-500">The platform mailer is what every booking email uses. Custom SMTP tries settings without changing anything.</p>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="$set('useCustomSmtp', false)"
                            @class([
                                'rounded-lg border px-3 py-2 text-sm font-semibold transition',
                                'border-sidebar bg-sidebar/10 text-sidebar' => ! $useCustomSmtp,
                                'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' => $useCustomSmtp,
                            ])>Platform mailer</button>
                    <button type="button" wire:click="$set('useCustomSmtp', true)"
                            @class([
                                'rounded-lg border px-3 py-2 text-sm font-semibold transition',
                                'border-sidebar bg-sidebar/10 text-sidebar' => $useCustomSmtp,
                                'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' => ! $useCustomSmtp,
                            ])>Custom SMTP</button>
                </div>

                @if (! $useCustomSmtp)
                    <dl class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-gray-50/60 text-xs">
                        @foreach ([
                            'Mailer' => $platformMailer['mailer'],
                            'Transport' => $platformMailer['transport'],
                            'Host' => $platformMailer['host'],
                            'Port' => $platformMailer['port'],
                            'Encryption' => $platformMailer['encryption'],
                            'Username' => $platformMailer['username'],
                            'Password' => $platformMailer['password'],
                            'From' => $platformMailer['from'],
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-3 px-3 py-2">
                                <dt class="shrink-0 font-medium text-gray-500">{{ $label }}</dt>
                                <dd class="break-all text-right font-mono text-gray-800">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="text-[11px] text-gray-400">Read from the running config. A changed <span class="font-mono">.env</span> needs <span class="font-mono">config:clear</span> before it shows here.</p>
                @else
                    <div class="space-y-3">
                        <div>
                            <label for="smtp-host" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Host</label>
                            <input type="text" id="smtp-host" wire:model="smtpHost" placeholder="smtp.example.com" class="input-brand font-mono text-sm" />
                            @error('smtpHost')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="smtp-port" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Port</label>
                                <input type="number" id="smtp-port" wire:model="smtpPort" min="1" max="65535" class="input-brand font-mono text-sm" />
                                @error('smtpPort')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="smtp-encryption" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Encryption</label>
                                <select id="smtp-encryption" wire:model="smtpEncryption" class="input-brand">
                                    <option value="tls">TLS / STARTTLS (587)</option>
                                    <option value="ssl">SSL / implicit TLS (465)</option>
                                    <option value="none">None (25)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="smtp-username" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Username</label>
                            <input type="text" id="smtp-username" wire:model="smtpUsername" autocomplete="off" class="input-brand font-mono text-sm" />
                        </div>
                        <div>
                            <label for="smtp-password" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">Password</label>
                            <input type="password" id="smtp-password" wire:model="smtpPassword" autocomplete="new-password" class="input-brand font-mono text-sm" />
                            <p class="mt-1 text-[11px] text-gray-400">Used for this send only — never stored.</p>
                        </div>
                        <div>
                            <label for="smtp-from-email" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">From address</label>
                            <input type="email" id="smtp-from-email" wire:model="fromEmail" class="input-brand font-mono text-sm" />
                            @error('fromEmail')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="smtp-from-name" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500">From name</label>
                            <input type="text" id="smtp-from-name" wire:model="fromName" class="input-brand" />
                        </div>
                    </div>
                @endif
            </div>

            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="send"
                    @disabled($recipientCount === 0)
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-sidebar px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-sidebar-dark disabled:cursor-not-allowed disabled:opacity-50">
                <span wire:loading.remove wire:target="send">
                    Send test email{{ $recipientCount > 1 ? 's ('.$recipientCount.')' : '' }}
                </span>
                <span wire:loading wire:target="send">Talking to the mail server…</span>
            </button>

            <p class="text-center text-[11px] text-gray-400">
                Every send is recorded in <a href="{{ route('activities.index', ['log_name' => 'delivery_test']) }}" wire:navigate class="underline hover:text-gray-600">Activities</a>.
            </p>
        </div>
    </form>
</div>
