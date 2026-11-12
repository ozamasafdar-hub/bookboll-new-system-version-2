<div>
    <header class="border-b border-gray-200 bg-white">
        <div class="px-6 py-5 sm:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">Delivery Tests</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        Send a real email or push notification and see what the mail server or OneSignal said. Nothing here is queued.
                    </p>
                </div>
            </div>

            <nav class="mt-5 flex gap-1 border-b border-gray-200" aria-label="Delivery test channels">
                @foreach ([
                    'email' => ['label' => 'Email (SMTP)', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />'],
                    'push' => ['label' => 'Push (OneSignal)', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />'],
                ] as $key => $meta)
                    <button type="button"
                            wire:click="setTab('{{ $key }}')"
                            @class([
                                '-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                                'border-sidebar text-sidebar' => $tab === $key,
                                'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' => $tab !== $key,
                            ])
                            aria-current="{{ $tab === $key ? 'page' : 'false' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">{!! $meta['icon'] !!}</svg>
                        {{ $meta['label'] }}
                    </button>
                @endforeach
            </nav>
        </div>
    </header>

    <main class="px-4 py-6 sm:px-8 sm:py-8">
        @if ($tab === 'push')
            <livewire:admin.delivery-tests.push-test wire:key="delivery-tests-push" />
        @else
            <livewire:admin.delivery-tests.email-test wire:key="delivery-tests-email" />
        @endif
    </main>
</div>
