<?php

use App\Enums\LinkedAccountProvider;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\ProfileNotFoundException;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\FaceitProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    Cache::flush();
    Http::preventStrayRequests();
});

function faceitProfileClient(): FaceitProfileClient
{
    return app(FaceitProfileClient::class);
}

// ─── Happy path ──────────────────────────────────────────────────────────────

test('200 → FaceitProfile populated from the player JSON', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([
            'player_id' => 'guid-a1',
            'nickname' => 'alice-faceit',
            'games' => [
                'cs2' => ['faceit_elo' => 1500, 'skill_level' => 7],
            ],
        ], 200),
    ]);

    $profile = faceitProfileClient()->fetchPlayer('guid-a1');

    expect($profile?->playerId)->toBe('guid-a1')
        ->and($profile?->nickname)->toBe('alice-faceit')
        ->and($profile?->cs2Elo)->toBe(1500)
        ->and($profile?->cs2SkillLevel)->toBe(7);
});

test('200 with missing CS2 block → ELO + skill level null', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([
            'player_id' => 'guid-a1',
            'nickname' => 'alice-faceit',
            'games' => ['dota2' => ['some_field' => 'value']],
        ], 200),
    ]);

    $profile = faceitProfileClient()->fetchPlayer('guid-a1');

    expect($profile?->cs2Elo)->toBeNull()
        ->and($profile?->cs2SkillLevel)->toBeNull();
});

// ─── Graceful null (missing API key — dev path) ──────────────────────────────

test('missing API key → returns null without making a request (and without touching breaker)', function () {
    config(['services.faceit.api_key' => null]);

    $breaker = app(ProviderCircuitBreaker::class);
    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();

    $result = faceitProfileClient()->fetchPlayer('guid-a1');
    expect($result)->toBeNull();

    // No HTTP call should have been made (Http::preventStrayRequests in beforeEach would catch it).
    // Breaker should be untouched — no API call means no health signal.
    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();
});

// ─── ProfileNotFoundException (separate hierarchy) ───────────────────────────

test('404 → ProfileNotFoundException (terminal player-doesnt-exist)', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 404),
    ]);

    expect(fn () => faceitProfileClient()->fetchPlayer('ghost-player'))
        ->toThrow(ProfileNotFoundException::class);
});

// ─── ProviderError classification (M35 P3 upgrade) ───────────────────────────

test('429 → RateLimitedError with retryAt parsed from Retry-After', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    try {
        faceitProfileClient()->fetchPlayer('guid-a1');
        $this->fail('Expected RateLimitedError was not thrown.');
    } catch (RateLimitedError $e) {
        expect($e->retryAt())->not->toBeNull()
            ->and($e->retryAt()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
    }
});

test('5xx → TransientProviderError', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 503),
    ]);

    expect(fn () => faceitProfileClient()->fetchPlayer('guid-a1'))
        ->toThrow(TransientProviderError::class);
});

test('4xx-other (e.g. 401) → PermanentProviderError', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 401),
    ]);

    expect(fn () => faceitProfileClient()->fetchPlayer('guid-a1'))
        ->toThrow(PermanentProviderError::class);
});

// ─── Circuit breaker integration ─────────────────────────────────────────────

test('consecutive 5xx failures eventually trip the FACEIT circuit breaker', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 503),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);
    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();

    foreach (range(1, 6) as $_) {
        try {
            faceitProfileClient()->fetchPlayer('guid-a1');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();
});

test('consecutive 200 responses do NOT trip the breaker', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([
            'player_id' => 'guid-a1', 'nickname' => 'alice',
        ], 200),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        faceitProfileClient()->fetchPlayer('guid-a1');
    }

    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();
});

test('404 (ProfileNotFoundException) counts as breaker success (defined-answer)', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([], 404),
    ]);

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 10) as $_) {
        try {
            faceitProfileClient()->fetchPlayer('ghost-player');
        } catch (ProfileNotFoundException) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeFalse();
});

test('connection failure records a breaker failure', function () {
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 6) as $_) {
        try {
            faceitProfileClient()->fetchPlayer('guid-a1');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();
});

// ─── Auth header ─────────────────────────────────────────────────────────────

test('sends Authorization: Bearer {api_key}', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*' => Http::response([
            'player_id' => 'guid-a1', 'nickname' => 'alice',
        ], 200),
    ]);

    faceitProfileClient()->fetchPlayer('guid-a1');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-faceit-api-key'));
});
