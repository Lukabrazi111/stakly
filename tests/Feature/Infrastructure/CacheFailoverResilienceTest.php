<?php

use App\Enums\LinkedAccountProvider;
use App\Models\Game;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\Support\ThrowingCacheStore;

/*
 * M38 P2 — settlement-path resilience. The default cache is a `redis → array`
 * failover store; if Redis is unreachable, reads/writes fall through to the
 * in-memory tail so nothing 500s and the circuit breaker reads fail-open.
 *
 * These pin that contract without a live broken Redis: a `throwing → array`
 * failover store stands in for "Redis down" (the throwing primary reproduces a
 * RedisException; `FailoverStore` catches `Throwable`).
 */

/**
 * Register a `throwing` cache store and point the default store at a
 * `throwing → array` failover — i.e. simulate Redis being unreachable.
 */
function simulateRedisOutage(): void
{
    Cache::extend('throwing', fn ($app) => $app->make('cache')->repository(new ThrowingCacheStore));

    config([
        'cache.stores.throwing' => ['driver' => 'throwing'],
        'cache.stores.outage_failover' => [
            'driver' => 'failover',
            'stores' => ['throwing', 'array'],
        ],
        'cache.default' => 'outage_failover',
    ]);
}

test('cached reads survive a Redis outage via the array fallback', function () {
    simulateRedisOutage();

    $captured = [];
    Event::listen(CacheFailedOver::class, function (CacheFailedOver $event) use (&$captured) {
        $captured[] = $event->storeName;
    });

    // Would throw on the `throwing` primary; failover drops to `array` instead.
    $value = Cache::remember('m38:probe', 60, fn () => 'computed-from-source');

    expect($value)->toBe('computed-from-source');
    expect($captured)->toContain('throwing');
});

test('the circuit breaker reads fail-open (closed) when the cache backend is down', function () {
    simulateRedisOutage();

    $breaker = app(ProviderCircuitBreaker::class);

    // A Redis read error must read as "not tripped" so settlement keeps flowing —
    // the providers' own 429s + our retries remain the backstop.
    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
    expect($breaker->openUntil(LinkedAccountProvider::ChessCom))->toBeNull();

    // Bookkeeping writes must not bubble a 500 into the settlement job; if they
    // did, the test errors here. The breaker still reads closed afterwards.
    $breaker->recordFailure(LinkedAccountProvider::ChessCom);
    $breaker->recordSuccess(LinkedAccountProvider::ChessCom);

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
});

test('the homepage does not 500 when the cache backend is unreachable', function () {
    Game::factory()->create();

    simulateRedisOutage();

    // HomeController caches the game catalog via `Cache::remember`; the failover
    // store recomputes from Postgres instead of throwing.
    $this->followingRedirects()
        ->get('/')
        ->assertOk();
});
