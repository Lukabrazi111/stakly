<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Services\Provider\ChessComProfileClient;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\LichessProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Http;

/*
 * M41 P3b — the chess clients' rating-fetch contract: chess.com reads the
 * separate /stats endpoint, Lichess reads perfs off the same /api/user payload
 * the bio verify uses. The bio path must stay intact after the shared-request
 * refactor.
 */

it('chess.com fetchRatings hits the /stats endpoint and maps categories to time controls', function () {
    Http::fake([
        'api.chess.com/pub/player/bob/stats' => Http::response([
            'chess_blitz' => ['last' => ['rating' => 1950, 'rd' => 40]],
            'chess_daily' => ['last' => ['rating' => 1600, 'rd' => 50]],
        ], 200),
    ]);

    $ratings = app(ChessComProfileClient::class)->fetchRatings('bob');

    expect($ratings->ratings)->toHaveCount(1)
        ->and($ratings->ratings[0]->timeControl)->toBe(TimeControl::Blitz)
        ->and($ratings->ratings[0]->rating)->toBe(1950)
        ->and($ratings->ratings[0]->isProvisional)->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/pub/player/bob/stats'));
});

it('lichess fetchRatings reads perfs off /api/user', function () {
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'perfs' => ['rapid' => ['games' => 50, 'rating' => 1700, 'rd' => 60]],
        ], 200),
    ]);

    $ratings = app(LichessProfileClient::class)->fetchRatings('alice');

    expect($ratings->ratings)->toHaveCount(1)
        ->and($ratings->ratings[0]->timeControl)->toBe(TimeControl::Rapid)
        ->and($ratings->ratings[0]->rating)->toBe(1700);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/user/alice'));
});

it('chess.com fetchProfile still reads location after the shared-request refactor', function () {
    Http::fake([
        'api.chess.com/pub/player/bob' => Http::response([
            'username' => 'bob',
            'location' => 'verify-code-here',
        ], 200),
    ]);

    $result = app(ChessComProfileClient::class)->fetchProfile('bob');

    expect($result->username)->toBe('bob')
        ->and($result->bioFieldValue)->toBe('verify-code-here');
});

it('lichess fetchProfile still reads profile.bio after the shared-request refactor', function () {
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'username' => 'alice',
            'profile' => ['bio' => 'my verify code'],
        ], 200),
    ]);

    $result = app(LichessProfileClient::class)->fetchProfile('alice');

    expect($result->bioFieldValue)->toBe('my verify code');
});

// ─── non-object 200 body degrades gracefully (no TypeError) ────────────────

it('a non-object 200 body does not throw — fetchProfile degrades to a null bio', function (mixed $body) {
    Http::fake(['api.chess.com/pub/player/bob' => Http::response($body, 200)]);

    $result = app(ChessComProfileClient::class)->fetchProfile('bob');

    expect($result->username)->toBe('bob')
        ->and($result->bioFieldValue)->toBeNull();
})->with([
    'null body' => null,
    'string body' => 'upstream maintenance',
]);

it('a non-object 200 body does not throw — fetchRatings degrades to empty', function (mixed $body) {
    Http::fake(['lichess.org/api/user/alice' => Http::response($body, 200)]);

    $ratings = app(LichessProfileClient::class)->fetchRatings('alice');

    expect($ratings->ratings)->toBe([]);
})->with([
    'null body' => null,
    'string body' => 'maintenance',
]);

// ─── rating fetch is isolated from the settlement circuit breaker ──────────

it('rating-fetch failures do NOT record into the settlement breaker', function () {
    Http::fake(['api.chess.com/pub/player/bob/stats' => Http::response([], 503)]);
    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 8) as $ignored) {
        try {
            app(ChessComProfileClient::class)->fetchRatings('bob');
        } catch (TransientProviderError) {
            // expected — but it must not feed the breaker.
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeFalse();
});

it('bio-verify failures DO still record into the breaker (flag defaults true)', function () {
    Http::fake(['api.chess.com/pub/player/bob' => Http::response([], 503)]);
    $breaker = app(ProviderCircuitBreaker::class);

    foreach (range(1, 8) as $ignored) {
        try {
            app(ChessComProfileClient::class)->fetchProfile('bob');
        } catch (TransientProviderError) {
            // expected
        }
    }

    expect($breaker->isOpen(LinkedAccountProvider::ChessCom))->toBeTrue();
});
