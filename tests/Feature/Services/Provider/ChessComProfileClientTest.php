<?php

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\ChessComProfileClient;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

function chessComProfileClient(): ChessComProfileClient
{
    return app(ChessComProfileClient::class);
}

// ─── Happy path ──────────────────────────────────────────────────────────────

test('200 → ProfileFetchResult with bio mapped from `location`', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([
            'username' => 'alice-chesscom',
            'location' => 'STAKLY-VERIFY-7Q9X',
        ], 200),
    ]);

    $result = chessComProfileClient()->fetchProfile('alice-chesscom');

    expect($result->username)->toBe('alice-chesscom')
        ->and($result->bioFieldValue)->toBe('STAKLY-VERIFY-7Q9X');
});

test('200 with missing `location` key → bioFieldValue null', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([
            'username' => 'alice-chesscom',
        ], 200),
    ]);

    $result = chessComProfileClient()->fetchProfile('alice-chesscom');

    expect($result->bioFieldValue)->toBeNull();
});

// ─── ProfileNotFoundException (separate hierarchy) ───────────────────────────

test('404 → ProfileNotFoundException (terminal user-doesnt-exist)', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 404),
    ]);

    expect(fn () => chessComProfileClient()->fetchProfile('ghost-user'))
        ->toThrow(ProfileNotFoundException::class);
});

// ─── ProviderError classification (M35 P1 upgrade) ───────────────────────────

test('429 → RateLimitedError with retryAt parsed from Retry-After', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    try {
        chessComProfileClient()->fetchProfile('alice-chesscom');
        $this->fail('Expected RateLimitedError was not thrown.');
    } catch (RateLimitedError $e) {
        expect($e->retryAt())->not->toBeNull()
            ->and($e->retryAt()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
    }
});

test('5xx → TransientProviderError', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 503),
    ]);

    expect(fn () => chessComProfileClient()->fetchProfile('alice-chesscom'))
        ->toThrow(TransientProviderError::class);
});

test('4xx-other (e.g. 401) → PermanentProviderError', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 401),
    ]);

    expect(fn () => chessComProfileClient()->fetchProfile('alice-chesscom'))
        ->toThrow(PermanentProviderError::class);
});

// ─── Circuit breaker integration ─────────────────────────────────────────────

test('consecutive 5xx failures eventually trip the chess.com circuit breaker', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 503),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);
    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();

    // ProviderCircuitBreaker thresholds: ≥5 attempts AND >50% failure rate.
    // Six consecutive 5xx satisfies both.
    foreach (range(1, 6) as $_) {
        try {
            chessComProfileClient()->fetchProfile('alice-chesscom');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeTrue();
});

test('consecutive 200 responses do NOT trip the breaker', function () {
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response(['username' => 'alice-chesscom'], 200),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        chessComProfileClient()->fetchProfile('alice-chesscom');
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
});

test('404 (ProfileNotFoundException) counts as breaker success (defined-answer)', function () {
    // 404 is "user doesn't exist" — a definitive answer from the API, not
    // a provider-health failure. The breaker treats it as success so a
    // burst of bad-username lookups doesn't trip it.
    Http::fake([
        'api.chess.com/pub/player/*' => Http::response([], 404),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        try {
            chessComProfileClient()->fetchProfile('ghost-user');
        } catch (ProfileNotFoundException) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
});

test('connection failure records a breaker failure', function () {
    // Emulate a connection-timeout. Six consecutive connection failures
    // must trip the breaker — same threshold as 5xx.
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 6) as $_) {
        try {
            chessComProfileClient()->fetchProfile('alice-chesscom');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeTrue();
});
