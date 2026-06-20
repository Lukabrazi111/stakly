<?php

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\ProviderCircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function breaker(): ProviderCircuitBreaker
{
    return app(ProviderCircuitBreaker::class);
}

// ─── Fresh state ───────────────────────────────────────────────────────────

test('fresh state: isOpen returns false and openUntil returns null', function () {
    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
    expect(breaker()->openUntil(LinkedAccountProvider::Lichess))->toBeNull();
});

// ─── Trip conditions ──────────────────────────────────────────────────────

test('does not trip below the 5-attempt minimum', function () {
    foreach (range(1, 4) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('trips when 5 attempts are all failures (100% > 50%)', function () {
    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();
});

test('trips when 4 of 5 attempts fail (80% > 50%)', function () {
    breaker()->recordSuccess(LinkedAccountProvider::Lichess);
    foreach (range(1, 4) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();
});

test('does not trip at exactly 50% failure rate (50% is not > 50%)', function () {
    breaker()->recordSuccess(LinkedAccountProvider::Lichess);
    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    breaker()->recordSuccess(LinkedAccountProvider::Lichess);
    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    breaker()->recordSuccess(LinkedAccountProvider::Lichess);
    breaker()->recordFailure(LinkedAccountProvider::Lichess);

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('does not trip when error rate exactly at threshold without enough volume', function () {
    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    breaker()->recordSuccess(LinkedAccountProvider::Lichess);

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

// ─── Per-provider isolation ───────────────────────────────────────────────

test('tripping Lichess does not affect chess.com', function () {
    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();
    expect(breaker()->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
});

// ─── Cooldown semantics ──────────────────────────────────────────────────

test('openUntil returns a future timestamp while open', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    $openUntil = breaker()->openUntil(LinkedAccountProvider::Lichess);

    expect($openUntil)->not->toBeNull();
    expect($openUntil->getTimestamp())->toBe(
        CarbonImmutable::parse('2026-06-06T12:05:00Z')->getTimestamp(),
    );
});

test('cooldown expires: isOpen flips back to false after 5 minutes', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();

    CarbonImmutable::setTestNow('2026-06-06T12:05:01Z');

    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('attempts list is cleared on trip: cannot immediately re-trip after cooldown', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    // First trip
    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }
    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();

    // Move past cooldown
    CarbonImmutable::setTestNow('2026-06-06T12:05:01Z');
    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();

    // One failure shouldn't be enough to re-trip — the window was cleared.
    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    expect(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

// ─── Defensive no-op while open ──────────────────────────────────────────

test('recordFailure is a no-op while the circuit is open', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    $openUntilBefore = breaker()->openUntil(LinkedAccountProvider::Lichess);

    breaker()->recordFailure(LinkedAccountProvider::Lichess);
    breaker()->recordFailure(LinkedAccountProvider::Lichess);

    // openUntil unchanged — the new failures didn't extend or reset the window.
    expect(breaker()->openUntil(LinkedAccountProvider::Lichess)->getTimestamp())
        ->toBe($openUntilBefore->getTimestamp());
});

// ─── M15 P5 Item 3 — per-provider config overrides ─────────────────────────

test('per-provider min_attempts override: FACEIT trips at 3 attempts while Lichess still needs 5', function () {
    config(['services.faceit.circuit_breaker.min_attempts' => 3]);
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 3) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Faceit);
        breaker()->recordFailure(LinkedAccountProvider::Lichess);
    }

    // FACEIT trips at 3 failures (overridden min); Lichess still on the 5-default and stays closed.
    expect(breaker()->isOpen(LinkedAccountProvider::Faceit))->toBeTrue()
        ->and(breaker()->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('per-provider error_rate_threshold override: stricter threshold trips earlier', function () {
    // Drop FACEIT's tolerance to 0.3 — 30% failure rate is enough.
    config(['services.faceit.circuit_breaker.error_rate_threshold' => 0.3]);
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    // 2 failures + 3 successes = 40% failure rate. Trips at 0.3 threshold.
    foreach (range(1, 2) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Faceit);
    }
    foreach (range(1, 3) as $_) {
        breaker()->recordSuccess(LinkedAccountProvider::Faceit);
    }
    breaker()->recordFailure(LinkedAccountProvider::Faceit);

    expect(breaker()->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();
});

test('per-provider cooldown_seconds override: shorter cooldown re-opens earlier', function () {
    // Drop FACEIT cooldown to 60s; trip it.
    config(['services.faceit.circuit_breaker.cooldown_seconds' => 60]);
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 5) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::Faceit);
    }
    expect(breaker()->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();

    // 90s later — past the 60s override, still inside the 300s default.
    // FACEIT should be closed; Lichess (if it had been tripped) would not be.
    CarbonImmutable::setTestNow('2026-06-06T12:01:31Z');
    expect(breaker()->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();
});

test('defaults preserved when no per-provider config is set (backward compat)', function () {
    // No config overrides — behavior should match the M14 defaults
    // (5 attempts, 50% threshold, 300s cooldown, 600s window).
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');

    foreach (range(1, 4) as $_) {
        breaker()->recordFailure(LinkedAccountProvider::ChessCom);
    }
    // 4 failures < 5 minimum → still closed.
    expect(breaker()->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();

    breaker()->recordFailure(LinkedAccountProvider::ChessCom);
    // 5 failures, 100% > 50% → trips.
    expect(breaker()->isOpen(LinkedAccountProvider::ChessCom))->toBeTrue();
});
