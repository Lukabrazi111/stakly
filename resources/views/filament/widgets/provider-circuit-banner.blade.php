@php
    /** @var array<int, array{name: string, openUntil: \Carbon\CarbonInterface|null, remainingSeconds: int}> $openProviders */
@endphp

<x-filament-widgets::widget>
    <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-950/40">
        <div class="flex items-start gap-3">
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="mt-0.5 h-6 w-6 shrink-0 text-danger-600 dark:text-danger-400"
            />

            <div class="flex-1">
                <h3 class="text-sm font-semibold text-danger-900 dark:text-danger-100">
                    Provider circuit open — auto-fetch paused
                </h3>

                <ul class="mt-2 space-y-1 text-sm text-danger-800 dark:text-danger-200">
                    @foreach ($openProviders as $row)
                        <li>
                            <span class="font-medium">{{ $row['name'] }}</span>
                            @if ($row['openUntil'])
                                — resumes at
                                <time datetime="{{ $row['openUntil']->toIso8601String() }}">
                                    {{ $row['openUntil']->format('H:i:s') }}
                                </time>
                                ({{ floor($row['remainingSeconds'] / 60) }}m {{ $row['remainingSeconds'] % 60 }}s remaining)
                            @endif
                        </li>
                    @endforeach
                </ul>

                <p class="mt-2 text-xs text-danger-700 dark:text-danger-300">
                    Error rate exceeded the threshold over the recent rolling window. Dispatch skips with
                    <code class="font-mono text-xs">circuit_open</code> reason until the cooldown expires.
                </p>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
