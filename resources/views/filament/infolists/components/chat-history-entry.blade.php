@php
    $messages = $entry->getMessages();

    // Group consecutive messages from the same role+author into bundles so
    // we render one header per burst (Slack-style). The bundle key changes
    // whenever role OR author changes — system messages always break the
    // grouping since each one stands alone.
    $bundles = [];
    $current = null;
    foreach ($messages as $message) {
        $role = $entry->roleOf($message);
        $key = $role === 'system'
            ? 'system:'.$message->id
            : $role.':'.($message->user_id ?? 'null');

        if ($current === null || $current['key'] !== $key) {
            if ($current !== null) {
                $bundles[] = $current;
            }
            $current = [
                'key' => $key,
                'role' => $role,
                'author_name' => $message->user?->name,
                'author_username' => $message->user?->username,
                'messages' => [],
            ];
        }
        $current['messages'][] = $message;
    }
    if ($current !== null) {
        $bundles[] = $current;
    }
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if (empty($bundles))
        <div class="fi-color-gray-500 rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm dark:border-gray-700">
            No messages exchanged in this match.
        </div>
    @else
        <div class="space-y-4">
            @foreach ($bundles as $bundle)
                @php
                    $role = $bundle['role'];
                    $isSystem = $role === 'system';

                    // Role color is paired with a text label (rule:
                    // "don't convey information by color alone"). Border
                    // and label use the same color token.
                    $accent = match ($role) {
                        'creator' => 'border-l-cyan-500',
                        'taker' => 'border-l-rose-500',
                        'system' => 'border-l-gray-400 dark:border-l-gray-600',
                        default => 'border-l-gray-400',
                    };
                    $roleLabel = match ($role) {
                        'creator' => 'Creator',
                        'taker' => 'Taker',
                        'system' => 'System',
                        default => 'Unknown',
                    };
                    $roleBadgeClass = match ($role) {
                        'creator' => 'bg-cyan-100 text-cyan-900 dark:bg-cyan-900/50 dark:text-cyan-100',
                        'taker' => 'bg-rose-100 text-rose-900 dark:bg-rose-900/50 dark:text-rose-100',
                        'system' => 'bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                        default => 'bg-gray-200 text-gray-800',
                    };
                @endphp

                <div @class([
                    'rounded-lg border border-l-4 bg-white p-4 dark:bg-gray-900/50',
                    'border-gray-200 dark:border-gray-700/60',
                    $accent,
                ])>
                    {{-- Bundle header: role badge + author + first/last timestamps --}}
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                        <span @class([
                            'rounded-md px-1.5 py-0.5 font-semibold uppercase tracking-wide',
                            $roleBadgeClass,
                        ])>
                            {{ $roleLabel }}
                        </span>

                        @if (! $isSystem && $bundle['author_username'])
                            <span class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ $bundle['author_name'] }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                &commat;{{ $bundle['author_username'] }}
                            </span>
                        @endif

                        <span class="ml-auto text-gray-500 dark:text-gray-400" title="{{ $bundle['messages'][0]->created_at->toDateTimeString() }}">
                            {{ $bundle['messages'][0]->created_at->format('M j, H:i') }}
                            @if (count($bundle['messages']) > 1)
                                <span class="opacity-60">– {{ end($bundle['messages'])->created_at->format('H:i') }}</span>
                            @endif
                        </span>
                    </div>

                    {{-- Stacked messages within the bundle --}}
                    <div class="space-y-2">
                        @foreach ($bundle['messages'] as $message)
                            @php
                                $attachments = is_array($message->attachments_json) ? $message->attachments_json : [];
                                $attachmentUrl = $isSystem ? null : $entry->attachmentUrl($message);
                                $attachmentFullUrl = $isSystem ? null : $entry->attachmentUrl($message, thumb: false);
                            @endphp

                            @if ($message->content)
                                <div class="whitespace-pre-wrap break-words text-sm leading-relaxed text-gray-800 dark:text-gray-100">{{ $message->content }}</div>
                            @endif

                            @if ($attachmentUrl)
                                @if ($entry->attachmentIsImage($message))
                                    <a
                                        href="{{ $attachmentFullUrl }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="block max-w-md overflow-hidden rounded-md border border-gray-300 transition hover:border-primary-500 dark:border-gray-700"
                                    >
                                        <img
                                            src="{{ $attachmentUrl }}"
                                            alt="Chat attachment"
                                            class="max-h-72 w-full object-contain"
                                            loading="lazy"
                                        />
                                    </a>
                                @else
                                    <a
                                        href="{{ $attachmentFullUrl }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex max-w-md items-center gap-3 rounded-md border border-gray-300 bg-gray-50 px-3 py-2.5 transition hover:border-primary-500 dark:border-gray-700 dark:bg-gray-800/60"
                                    >
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">
                                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                            </svg>
                                        </span>
                                        <span class="flex min-w-0 flex-col">
                                            <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ $entry->attachmentName($message) ?? 'Attachment' }}
                                            </span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $entry->attachmentSizeLabel($message) }}
                                            </span>
                                        </span>
                                    </a>
                                @endif
                            @endif

                            @if (count($attachments) > 0)
                                <div class="space-y-2">
                                    @foreach ($attachments as $att)
                                        @php $type = $att['type'] ?? null; @endphp

                                        @if ($type === 'game_card')
                                            <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-950/40">
                                                <div class="mb-1 flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                                                        {{ strtoupper($att['provider'] ?? 'GAME') }} game
                                                    </span>
                                                    @if ($att['verified'] ?? false)
                                                        <span class="rounded bg-emerald-600 px-1.5 py-0.5 text-white">verified</span>
                                                    @else
                                                        <span class="rounded bg-gray-500 px-1.5 py-0.5 text-white">unverified</span>
                                                    @endif
                                                    <span class="ml-auto text-amber-700 dark:text-amber-300">
                                                        {{ $att['source'] ?? '—' }}
                                                    </span>
                                                </div>
                                                <div class="text-amber-900 dark:text-amber-100">
                                                    <strong>{{ $att['white_username'] ?? '?' }}</strong>
                                                    vs
                                                    <strong>{{ $att['black_username'] ?? '?' }}</strong>
                                                    @if (! empty($att['winner_username']))
                                                        → winner: <strong>{{ $att['winner_username'] }}</strong>
                                                    @elseif (($att['winner_color'] ?? null) === null && ! empty($att['status']))
                                                        → {{ $att['status'] }}
                                                    @endif
                                                </div>
                                                @if (! empty($att['url']))
                                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="mt-1 inline-block text-blue-600 underline dark:text-blue-400">
                                                        {{ $att['url'] }}
                                                    </a>
                                                @endif
                                            </div>
                                        @elseif ($type === 'link')
                                            <div class="rounded-md border border-gray-300 bg-gray-50 p-3 text-xs dark:border-gray-700 dark:bg-gray-800/60">
                                                @if (! empty($att['title']))
                                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $att['title'] }}</div>
                                                @endif
                                                @if (! empty($att['description']))
                                                    <div class="text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($att['description'], 140) }}</div>
                                                @endif
                                                @if (! empty($att['url']))
                                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="mt-1 inline-block text-blue-600 underline dark:text-blue-400">
                                                        {{ $att['url'] }}
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
