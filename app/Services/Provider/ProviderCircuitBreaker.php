<?php

namespace App\Services\Provider;

use App\Enums\LinkedAccountProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * M14 Slice 2d — per-provider circuit breaker for the auto-fetch pipeline.
 *
 * Sliding-window error tracking: each attempt (success or failure) lands as
 * a `[timestamp, success]` row in cache with a window-length retention TTL.
 * When the in-window failure rate crosses the threshold (default >50% over
 * ≥ 5 attempts), the breaker trips open for a cooldown. While open,
 * `DispatchAutoFetchAction` short-circuits with an audit row reasoned
 * `circuit_open` — no provider traffic flows.
 *
 * After the cooldown expires, the attempt window is empty (cleared on trip)
 * so the breaker can't immediately re-trip on a stale signal. The minimum
 * attempt count must accumulate again before another trip is possible.
 *
 * M15 P5 Item 3 — thresholds are now per-provider, read from
 * `services.{provider}.circuit_breaker.*` with the class-level defaults as
 * fallback. Lets us tune FACEIT's tolerance distinctly from chess once
 * production telemetry shows their failure profiles diverge.
 */
class ProviderCircuitBreaker
{
    private const DEFAULT_WINDOW_SECONDS = 600;

    private const DEFAULT_MIN_ATTEMPTS = 5;

    private const DEFAULT_ERROR_RATE_THRESHOLD = 0.5;

    private const DEFAULT_COOLDOWN_SECONDS = 300;

    public function recordSuccess(LinkedAccountProvider $provider): void
    {
        $this->record($provider, true);
    }

    public function recordFailure(LinkedAccountProvider $provider): void
    {
        if ($this->isOpen($provider)) {
            // Defensive: callers gate on isOpen() before dispatching, but
            // skip the bookkeeping if a stale attempt lands here.
            return;
        }

        $this->record($provider, false);

        if ($this->shouldTrip($provider)) {
            $this->trip($provider);
        }
    }

    public function isOpen(LinkedAccountProvider $provider): bool
    {
        $openUntil = Cache::get($this->openUntilKey($provider));

        return is_int($openUntil) && $openUntil > now()->getTimestamp();
    }

    public function openUntil(LinkedAccountProvider $provider): ?CarbonInterface
    {
        $openUntil = Cache::get($this->openUntilKey($provider));

        if (! is_int($openUntil) || $openUntil <= now()->getTimestamp()) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($openUntil);
    }

    private function record(LinkedAccountProvider $provider, bool $success): void
    {
        $window = $this->windowSeconds($provider);
        $key = $this->attemptsKey($provider);
        $attempts = Cache::get($key, []);
        $now = now()->getTimestamp();
        $cutoff = $now - $window;

        $attempts = array_values(array_filter(
            $attempts,
            fn (array $a) => isset($a['ts']) && $a['ts'] >= $cutoff,
        ));

        $attempts[] = ['ts' => $now, 'success' => $success];

        Cache::put($key, $attempts, $window);
    }

    private function shouldTrip(LinkedAccountProvider $provider): bool
    {
        $attempts = Cache::get($this->attemptsKey($provider), []);

        if (count($attempts) < $this->minAttempts($provider)) {
            return false;
        }

        $failures = array_filter($attempts, fn (array $a) => ! ($a['success'] ?? false));

        return (count($failures) / count($attempts)) > $this->errorRateThreshold($provider);
    }

    private function trip(LinkedAccountProvider $provider): void
    {
        $cooldown = $this->cooldownSeconds($provider);

        Cache::put(
            $this->openUntilKey($provider),
            now()->getTimestamp() + $cooldown,
            $cooldown,
        );

        // Fresh window on resume — prevents immediate re-trip from stale signal.
        Cache::forget($this->attemptsKey($provider));
    }

    /**
     * Per-provider config lookup with class-level default fallback. Missing
     * config blocks just fall through to the original M14 defaults — no
     * behavior change unless a provider's config explicitly overrides.
     */
    private function windowSeconds(LinkedAccountProvider $provider): int
    {
        return (int) config(
            "services.{$provider->value}.circuit_breaker.window_seconds",
            self::DEFAULT_WINDOW_SECONDS,
        );
    }

    private function minAttempts(LinkedAccountProvider $provider): int
    {
        return (int) config(
            "services.{$provider->value}.circuit_breaker.min_attempts",
            self::DEFAULT_MIN_ATTEMPTS,
        );
    }

    private function errorRateThreshold(LinkedAccountProvider $provider): float
    {
        return (float) config(
            "services.{$provider->value}.circuit_breaker.error_rate_threshold",
            self::DEFAULT_ERROR_RATE_THRESHOLD,
        );
    }

    private function cooldownSeconds(LinkedAccountProvider $provider): int
    {
        return (int) config(
            "services.{$provider->value}.circuit_breaker.cooldown_seconds",
            self::DEFAULT_COOLDOWN_SECONDS,
        );
    }

    private function attemptsKey(LinkedAccountProvider $provider): string
    {
        return "provider_circuit:{$provider->value}:attempts";
    }

    private function openUntilKey(LinkedAccountProvider $provider): string
    {
        return "provider_circuit:{$provider->value}:open_until";
    }
}
