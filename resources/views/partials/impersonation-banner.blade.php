@php
    use STS\FilamentImpersonate\Facades\Impersonation;

    $isImpersonating = Impersonation::isImpersonating();
    $target = $isImpersonating ? auth()->user() : null;
@endphp

@if ($isImpersonating && $target)
    <style>
        body {
            padding-top: 44px;
        }
    </style>
    <div
        role="alert"
        aria-live="polite"
        class="fixed inset-x-0 top-0 z-[100] flex h-11 items-center justify-center gap-3 border-b border-warning/40 bg-warning px-4 text-xs font-medium text-warning-foreground shadow-lg sm:text-sm"
    >
        <span class="truncate">
            {{ __('Viewing as') }}
            <strong class="font-display font-semibold">{{ '@' . $target->username }}</strong>
        </span>
        <a
            href="{{ route('filament-impersonate.leave') }}"
            class="inline-flex shrink-0 items-center rounded-full border border-warning-foreground/30 bg-background/20 px-3 py-1 text-xs font-semibold text-warning-foreground transition-colors hover:bg-background/40"
        >
            {{ __('Exit impersonation') }}
        </a>
    </div>
@endif
