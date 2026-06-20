<?php

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\LichessProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

function lichessProfileClient(): LichessProfileClient
{
    return app(LichessProfileClient::class);
}

// ─── Happy path ──────────────────────────────────────────────────────────────

test('200 → ProfileFetchResult with bio mapped from `profile.bio`', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([
            'username' => 'alice-lichess',
            'profile' => ['bio' => 'STAKLY-VERIFY-7Q9X'],
        ], 200),
    ]);

    $result = lichessProfileClient()->fetchProfile('alice-lichess');

    expect($result->username)->toBe('alice-lichess')
        ->and($result->bioFieldValue)->toBe('STAKLY-VERIFY-7Q9X');
});

test('200 with missing `profile` block → bioFieldValue null', function () {
    // Lichess omits the entire `profile` object when the user hasn't
    // filled in anything; defensive chained access surfaces null.
    Http::fake([
        'lichess.org/api/user/*' => Http::response([
            'username' => 'alice-lichess',
        ], 200),
    ]);

    $result = lichessProfileClient()->fetchProfile('alice-lichess');

    expect($result->bioFieldValue)->toBeNull();
});

// ─── ProfileNotFoundException (separate hierarchy) ───────────────────────────

test('404 → ProfileNotFoundException (terminal user-doesnt-exist)', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 404),
    ]);

    expect(fn () => lichessProfileClient()->fetchProfile('ghost-user'))
        ->toThrow(ProfileNotFoundException::class);
});

// ─── ProviderError classification (M35 P2 upgrade) ───────────────────────────

test('429 → RateLimitedError with retryAt parsed from Retry-After', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    try {
        lichessProfileClient()->fetchProfile('alice-lichess');
        $this->fail('Expected RateLimitedError was not thrown.');
    } catch (RateLimitedError $e) {
        expect($e->retryAt())->not->toBeNull()
            ->and($e->retryAt()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
    }
});

test('5xx → TransientProviderError', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 503),
    ]);

    expect(fn () => lichessProfileClient()->fetchProfile('alice-lichess'))
        ->toThrow(TransientProviderError::class);
});

test('4xx-other (e.g. 401) → PermanentProviderError', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 401),
    ]);

    expect(fn () => lichessProfileClient()->fetchProfile('alice-lichess'))
        ->toThrow(PermanentProviderError::class);
});

// ─── Circuit breaker integration ─────────────────────────────────────────────

test('consecutive 5xx failures eventually trip the Lichess circuit breaker', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 503),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);
    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();

    foreach (range(1, 6) as $_) {
        try {
            lichessProfileClient()->fetchProfile('alice-lichess');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();
});

test('consecutive 200 responses do NOT trip the breaker', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response(['username' => 'alice-lichess'], 200),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        lichessProfileClient()->fetchProfile('alice-lichess');
    }

    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('404 (ProfileNotFoundException) counts as breaker success (defined-answer)', function () {
    Http::fake([
        'lichess.org/api/user/*' => Http::response([], 404),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        try {
            lichessProfileClient()->fetchProfile('ghost-user');
        } catch (ProfileNotFoundException) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeFalse();
});

test('connection failure records a breaker failure', function () {
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 6) as $_) {
        try {
            lichessProfileClient()->fetchProfile('alice-lichess');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();
});
