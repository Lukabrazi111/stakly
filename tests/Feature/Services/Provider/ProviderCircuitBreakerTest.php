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
