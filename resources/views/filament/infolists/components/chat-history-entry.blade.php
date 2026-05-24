@php
    $messages = $entry->getMessages();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($messages->isEmpty())
        <p class="fi-color-gray-500 text-sm italic">No messages exchanged in this match.</p>
    @else
        <div class="space-y-3">
            @foreach ($messages as $message)
                @php
                    $isSystem = $message->type === \App\Enums\MessageType::System;
                    $author = $isSystem ? 'System' : ($message->user?->username ?? '—');
                    $attachments = is_array($message->attachments_json) ? $message->attachments_json : [];
                    $attachmentUrl = $isSystem ? null : $entry->attachmentUrl($message);
                @endphp

                <div @class([
                    'rounded-lg border p-3',
                    'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950' => $isSystem,
                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => ! $isSystem,
                ])>
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="font-semibold">
                            {{ $author }}
                            @if ($isSystem)
                                <span class="ml-1 rounded bg-blue-200 px-1.5 py-0.5 text-blue-900 dark:bg-blue-800 dark:text-blue-100">system</span>
                            @endif
                        </span>
                        <span class="opacity-60" title="{{ $message->created_at->toDateTimeString() }}">
                            {{ $message->created_at->format('M j, H:i') }}
                        </span>
                    </div>

                    @if ($message->content)
                        <div class="whitespace-pre-wrap break-words text-sm">{{ $message->content }}</div>
                    @endif

                    @if ($attachmentUrl)
                        <div class="mt-2">
                            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener">
                                <img
                                    src="{{ $attachmentUrl }}"
                                    alt="Chat attachment"
                                    class="max-h-64 rounded border border-gray-300 dark:border-gray-700"
                                    loading="lazy"
                                />
                            </a>
                        </div>
                    @endif

                    @if (count($attachments) > 0)
                        <div class="mt-2 space-y-2">
                            @foreach ($attachments as $att)
                                @php
                                    $type = $att['type'] ?? null;
                                @endphp

                                @if ($type === 'game_card')
                                    <div class="rounded border border-amber-300 bg-amber-50 p-2 text-xs dark:border-amber-700 dark:bg-amber-950">
                                        <div class="flex items-center justify-between">
                                            <span class="font-semibold">
                                                {{ strtoupper($att['provider'] ?? 'GAME') }} card
                                                @if ($att['verified'] ?? false)
                                                    <span class="ml-1 rounded bg-green-600 px-1.5 text-white">verified</span>
                                                @else
                                                    <span class="ml-1 rounded bg-gray-500 px-1.5 text-white">unverified</span>
                                                @endif
                                            </span>
                                            <span class="opacity-60">{{ $att['source'] ?? '—' }}</span>
                                        </div>
                                        <div class="mt-1">
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
                                            <a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="text-blue-600 underline dark:text-blue-400">{{ $att['url'] }}</a>
                                        @endif
                                    </div>
                                @elseif ($type === 'link')
                                    <div class="rounded border border-gray-300 bg-gray-50 p-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                        @if (! empty($att['title']))
                                            <div class="font-semibold">{{ $att['title'] }}</div>
                                        @endif
                                        @if (! empty($att['description']))
                                            <div class="opacity-75">{{ \Illuminate\Support\Str::limit($att['description'], 120) }}</div>
                                        @endif
                                        @if (! empty($att['url']))
                                            <a href="{{ $att['url'] }}" target="_blank" rel="noopener" class="text-blue-600 underline dark:text-blue-400">{{ $att['url'] }}</a>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
