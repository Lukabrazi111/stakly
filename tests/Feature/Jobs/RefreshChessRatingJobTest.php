<?php

use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Jobs\RefreshChessRatingJob;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Provider\ChessComProfileClient;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\LichessProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function lichessAccount(string $username = 'alice'): LinkedAccount
{
    return User::factory()->withLichess($username, now()->subDays(2))->create()
        ->linkedAccounts()->firstOrFail();
}

function chessComAccount(string $username = 'bob'): LinkedAccount
{
    return User::factory()->withChessCom($username, now()->subDays(2))->create()
        ->linkedAccounts()->firstOrFail();
}

function runChessRatingRefresh(LinkedAccount $account): void
{
    (new RefreshChessRatingJob($account))->handle(
        app(ChessComProfileClient::class),
        app(LichessProfileClient::class),
        app(ProviderCircuitBreaker::class),
    );
}

// ─── Lichess (perfs from /api/user) ───────────────────────────────────────

it('upserts per-time-control rows + bumps synced_at from Lichess perfs', function () {
    $account = lichessAccount('alice');
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'username' => 'alice',
            'perfs' => [
                'bullet' => ['games' => 500, 'rating' => 1900, 'rd' => 45, 'prog' => 5],
                'blitz' => ['games' => 1200, 'rating' => 1850, 'rd' => 50, 'prog' => 12],
                'rapid' => ['games' => 80, 'rating' => 1700, 'rd' => 60, 'prog' => -3],
                // Ignored: not a stakeable time control.
                'classical' => ['games' => 10, 'rating' => 1600, 'rd' => 70],
            ],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    $ratings = $account->ratings()->pluck('rating', 'time_control');
    expect($ratings)->toHaveCount(3)
        ->and((int) $ratings['bullet'])->toBe(1900)
        ->and((int) $ratings['blitz'])->toBe(1850)
        ->and((int) $ratings['rapid'])->toBe(1700);

    expect($account->fresh()->skill_rating_synced_at)->not->toBeNull();
});

it('flags a Lichess perf with prov=true as provisional', function () {
    $account = lichessAccount('alice');
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'perfs' => [
                'blitz' => ['games' => 4, 'rating' => 1500, 'rd' => 140, 'prov' => true],
            ],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    $blitz = $account->ratings()->where('time_control', TimeControl::Blitz->value)->firstOrFail();
    expect($blitz->is_provisional)->toBeTrue();
});

it('skips Lichess time controls with zero games', function () {
    $account = lichessAccount('alice');
    Http::fake([
        'lichess.org/api/user/alice' => Http::response([
            'perfs' => [
                'bullet' => ['games' => 0, 'rating' => 1500, 'rd' => 500, 'prov' => true],
                'blitz' => ['games' => 300, 'rating' => 1820, 'rd' => 55],
            ],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    expect($account->ratings()->pluck('time_control')->map->value->all())->toBe(['blitz']);
});

// ─── chess.com (last from /stats) ─────────────────────────────────────────

it('upserts rows from the chess.com /stats endpoint', function () {
    $account = chessComAccount('bob');
    Http::fake([
        'api.chess.com/pub/player/bob/stats' => Http::response([
            'chess_bullet' => ['last' => ['rating' => 2000, 'rd' => 35]],
            'chess_blitz' => ['last' => ['rating' => 1950, 'rd' => 40]],
            'chess_rapid' => ['last' => ['rating' => 1800, 'rd' => 45]],
            // daily = correspondence; not one of our time controls.
            'chess_daily' => ['last' => ['rating' => 1600, 'rd' => 50]],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    $ratings = $account->ratings()->pluck('rating', 'time_control');
    expect($ratings)->toHaveCount(3)
        ->and((int) $ratings['bullet'])->toBe(2000)
        ->and((int) $ratings['blitz'])->toBe(1950)
        ->and((int) $ratings['rapid'])->toBe(1800);
});

it('flags a chess.com rating with rd over the threshold as provisional', function () {
    config(['services.chess_com.provisional_rd_threshold' => 110]);
    $account = chessComAccount('bob');
    Http::fake([
        'api.chess.com/pub/player/bob/stats' => Http::response([
            'chess_blitz' => ['last' => ['rating' => 1500, 'rd' => 130]],
            'chess_rapid' => ['last' => ['rating' => 1700, 'rd' => 60]],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    expect($account->ratings()->where('time_control', 'blitz')->value('is_provisional'))->toBeTrue()
        ->and($account->ratings()->where('time_control', 'rapid')->value('is_provisional'))->toBeFalse();
});

it('skips chess.com categories the player has never played', function () {
    $account = chessComAccount('bob');
    Http::fake([
        'api.chess.com/pub/player/bob/stats' => Http::response([
            'chess_blitz' => ['last' => ['rating' => 1950, 'rd' => 40]],
        ], 200),
    ]);

    runChessRatingRefresh($account);

    expect($account->ratings()->pluck('time_control')->map->value->all())->toBe(['blitz']);
});

// ─── never-null invariant ─────────────────────────────────────────────────

it('preserves existing rows + does not mark synced on a 404', function () {
    $account = lichessAccount('alice');
    $account->ratings()->create([
        'time_control' => TimeControl::Blitz->value,
        'rating' => 1800,
        'rd' => 50,
        'is_provisional' => false,
        'synced_at' => now()->subDays(2),
    ]);
    Http::fake(['lichess.org/api/user/alice' => Http::response([], 404)]);

    runChessRatingRefresh($account);

    // Row unchanged + synced_at NOT bumped (account was last synced ~2 days ago).
    expect((int) $account->ratings()->where('time_control', 'blitz')->value('rating'))->toBe(1800)
        ->and($account->fresh()->skill_rating_synced_at->lt(now()->subHours(1)))->toBeTrue();
});

it('does not call the API or change rows when the breaker is open', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 6) as $ignored) {
        $breaker->recordFailure(LinkedAccountProvider::Lichess);
    }
    expect($breaker->isOpen(LinkedAccountProvider::Lichess))->toBeTrue();

    $account = lichessAccount('alice');
    $account->ratings()->create([
        'time_control' => TimeControl::Blitz->value,
        'rating' => 1800,
        'is_provisional' => false,
        'synced_at' => now()->subDays(2),
    ]);
    Http::fake();
    Http::preventStrayRequests();

    runChessRatingRefresh($account);

    Http::assertNothingSent();
    expect((int) $account->ratings()->where('time_control', 'blitz')->value('rating'))->toBe(1800);
});

it('rethrows a transient 5xx so the job retries, preserving rows', function () {
    $account = lichessAccount('alice');
    Http::fake(['lichess.org/api/user/alice' => Http::response([], 503)]);

    expect(fn () => runChessRatingRefresh($account))->toThrow(TransientProviderError::class);
});

it('releases the job honoring Retry-After on a 429', function () {
    $this->freezeTime();
    $account = chessComAccount('bob');
    Http::fake([
        'api.chess.com/pub/player/bob/stats' => Http::response([], 429, ['Retry-After' => '30']),
    ]);

    $job = (new RefreshChessRatingJob($account))->withFakeQueueInteractions();
    $job->handle(
        app(ChessComProfileClient::class),
        app(LichessProfileClient::class),
        app(ProviderCircuitBreaker::class),
    );

    $job->assertReleased(30);
});

it('rethrows a 429 with no Retry-After so it retries via backoff', function () {
    $account = lichessAccount('alice');
    Http::fake(['lichess.org/api/user/alice' => Http::response([], 429)]);

    expect(fn () => runChessRatingRefresh($account))->toThrow(RateLimitedError::class);
});

it('permanently fails on a 403', function () {
    $account = chessComAccount('bob');
    Http::fake(['api.chess.com/pub/player/bob/stats' => Http::response([], 403)]);

    $job = (new RefreshChessRatingJob($account))->withFakeQueueInteractions();
    $job->handle(
        app(ChessComProfileClient::class),
        app(LichessProfileClient::class),
        app(ProviderCircuitBreaker::class),
    );

    $job->assertFailed();
});

// ─── throttle wiring ──────────────────────────────────────────────────────

test('the job uses the provider-specific rating limiter', function () {
    $lichess = (new RefreshChessRatingJob(lichessAccount('alice')))->middleware();
    $chessCom = (new RefreshChessRatingJob(chessComAccount('bob')))->middleware();

    expect($lichess)->toHaveCount(1)
        ->and($lichess[0])->toBeInstanceOf(RateLimited::class)
        ->and($chessCom[0])->toBeInstanceOf(RateLimited::class);
});

test('the chess rating limiters reflect their per-provider config', function () {
    config([
        'services.chess_com.rating_requests_per_minute' => 19,
        'services.lichess.rating_requests_per_minute' => 23,
    ]);

    $chessCom = RateLimiter::limiter('chess-com-rating-api')(new stdClass);
    $lichess = RateLimiter::limiter('lichess-rating-api')(new stdClass);

    expect($chessCom)->toBeInstanceOf(Limit::class)
        ->and($chessCom->maxAttempts)->toBe(19)
        ->and($lichess->maxAttempts)->toBe(23);
});
