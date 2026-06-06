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
 * a `[timestamp, success]` row in cache with a 10-minute retention TTL. When
 * the in-window failure rate crosses the threshold (default >50% over ≥ 5
 * attempts), the breaker trips open for a 5-minute cooldown. While open,
 * `DispatchAutoFetchAction` short-circuits with an audit row reasoned
 * `circuit_open` — no provider traffic flows.
 *
 * After the cooldown expires, the attempt window is empty (cleared on trip)
 * so the breaker can't immediately re-trip on a stale signal. Five fresh
 * attempts must accumulate before another trip is possible.
 *
 * Thresholds are starting defaults — re-tune after the first month of real
 * telemetry per M14 Slice 2d milestone notes.
 */
class ProviderCircuitBreaker
{
    private const WINDOW_SECONDS = 600;

    private const MIN_ATTEMPTS = 5;

    private const ERROR_RATE_THRESHOLD = 0.5;

    private const COOLDOWN_SECONDS = 300;

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
        $key = $this->attemptsKey($provider);
        $attempts = Cache::get($key, []);
        $now = now()->getTimestamp();
        $cutoff = $now - self::WINDOW_SECONDS;

        $attempts = array_values(array_filter(
            $attempts,
            fn (array $a) => isset($a['ts']) && $a['ts'] >= $cutoff,
        ));

        $attempts[] = ['ts' => $now, 'success' => $success];

        Cache::put($key, $attempts, self::WINDOW_SECONDS);
    }

    private function shouldTrip(LinkedAccountProvider $provider): bool
    {
        $attempts = Cache::get($this->attemptsKey($provider), []);

        if (count($attempts) < self::MIN_ATTEMPTS) {
            return false;
        }

        $failures = array_filter($attempts, fn (array $a) => ! ($a['success'] ?? false));

        return (count($failures) / count($attempts)) > self::ERROR_RATE_THRESHOLD;
    }

    private function trip(LinkedAccountProvider $provider): void
    {
        Cache::put(
            $this->openUntilKey($provider),
            now()->getTimestamp() + self::COOLDOWN_SECONDS,
            self::COOLDOWN_SECONDS,
        );

        // Fresh window on resume — prevents immediate re-trip from stale signal.
        Cache::forget($this->attemptsKey($provider));
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
