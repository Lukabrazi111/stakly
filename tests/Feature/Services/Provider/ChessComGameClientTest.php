<?php

use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function chessComClient(): ChessComGameClient
{
    return app(ChessComGameClient::class);
}

// ─── fetchGame ──────────────────────────────────────────────────────────────

test('fetchGame returns parsed result when archive contains the URL', function () {
    $gameUrl = 'https://www.chess.com/game/live/12345678901';

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture(['url' => $gameUrl]),
            ]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame($gameUrl, 'alice-chesscom');

    expect($game)->not->toBeNull()
        ->and($game->url)->toBe($gameUrl)
        ->and($game->whiteUsername)->toBe('alice-chesscom')
        ->and($game->blackUsername)->toBe('bob-chesscom')
        ->and($game->winnerColor)->toBe('white')
        ->and($game->status)->toBe('checkmated')
        ->and($game->speed)->toBe('blitz')
        ->and($game->rated)->toBeTrue()
        ->and($game->isDecisive())->toBeTrue()
        ->and($game->winnerUsername())->toBe('alice-chesscom');
});

test('fetchGame returns null when archive returns empty', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame(
        'https://www.chess.com/game/live/99999999999',
        'alice-chesscom',
    );

    expect($game)->toBeNull();
});

test('fetchGame returns null on 404 archive (user has no games for that month)', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 404),
    ]);

    $game = chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    );

    expect($game)->toBeNull();
});

test('fetchGame throws TransientProviderError on 5xx', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 503),
    ]);

    expect(fn () => chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    ))->toThrow(TransientProviderError::class);
});

test('fetchGame throws RateLimitedError on 429', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429),
    ]);

    expect(fn () => chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    ))->toThrow(RateLimitedError::class);
});

test('fetchGame populates retryAt from Retry-After header on 429', function () {
    CarbonImmutable::setTestNow('2026-06-06T12:00:00Z');
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429, ['Retry-After' => '90']),
    ]);

    $error = null;
    try {
        chessComClient()->fetchGame(
            'https://www.chess.com/game/live/12345678901',
            'alice-chesscom',
        );
    } catch (RateLimitedError $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->retryAt()->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-06-06T12:01:30Z')->getTimestamp());
});

test('fetchGame throws PermanentProviderError on 4xx other than 429', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 403),
    ]);

    expect(fn () => chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    ))->toThrow(PermanentProviderError::class);
});

test('fetchGame parses a draw result correctly', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'white' => ['username' => 'alice-chesscom', 'result' => 'agreed'],
                    'black' => ['username' => 'bob-chesscom', 'result' => 'agreed'],
                ]),
            ]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    );

    expect($game->winnerColor)->toBeNull()
        ->and($game->isDecisive())->toBeFalse()
        ->and($game->isDraw())->toBeTrue()
        ->and($game->status)->toBe('agreed');
});

test('fetchGame classifies abandoned games via isAborted (M14 Slice 3b)', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'white' => ['username' => 'alice-chesscom', 'result' => 'abandoned'],
                    'black' => ['username' => 'bob-chesscom', 'result' => 'abandoned'],
                ]),
            ]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    );

    expect($game->winnerColor)->toBeNull()
        ->and($game->isDecisive())->toBeFalse()
        ->and($game->isDraw())->toBeFalse()
        ->and($game->isAborted())->toBeTrue()
        ->and($game->status)->toBe('abandoned');
});

// ─── start-time parsing (M46 P5 review — real API has no top-level start_time) ─

test('createdAt is the PGN start time, not end_time (live games carry start only in the PGN)', function () {
    // Real chess.com blitz games have NO top-level start_time — the start lives
    // in the PGN. If parseGame fell back to end_time, the M46 P2 started-after
    // guard would be a no-op. Start and end are deliberately far apart so a
    // fallback-to-end_time regression is unmissable.
    $start = CarbonImmutable::parse('2026-07-01T11:00:00Z');
    $end = CarbonImmutable::parse('2026-07-01T12:05:23Z');

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'start_time' => $start->timestamp, // fixture emits this into the PGN only
                    'end_time' => $end->timestamp,
                ]),
            ]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame('https://www.chess.com/game/live/12345678901', 'alice-chesscom');

    expect($game->createdAt->getTimestamp())->toBe($start->getTimestamp())
        ->and($game->endedAt->getTimestamp())->toBe($end->getTimestamp())
        ->and($game->createdAt->getTimestamp())->not->toBe($end->getTimestamp());
});

test('createdAt falls back to end_time when the PGN carries no start tags (defensive)', function () {
    $end = CarbonImmutable::parse('2026-07-01T12:05:23Z');

    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'end_time' => $end->timestamp,
                    'pgn' => "[Event \"Live Chess\"]\n\n1. e4 e5 1-0\n", // no UTCDate/StartTime
                ]),
            ]),
            200,
        ),
    ]);

    $game = chessComClient()->fetchGame('https://www.chess.com/game/live/12345678901', 'alice-chesscom');

    expect($game->createdAt->getTimestamp())->toBe($end->getTimestamp());
});

// ─── searchGamesBetween ─────────────────────────────────────────────────────

test('searchGamesBetween filters by opponent + since', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response(
            chessComArchiveFixture([
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/111',
                    'end_time' => CarbonImmutable::now()->subMinutes(30)->timestamp,
                ]),
                // Same opponent, but too old (before `since`) — should be filtered out.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/222',
                    'end_time' => CarbonImmutable::now()->subDays(10)->timestamp,
                ]),
                // Different opponent — should be filtered out.
                chessComGameFixture([
                    'url' => 'https://www.chess.com/game/live/333',
                    'end_time' => CarbonImmutable::now()->subMinutes(15)->timestamp,
                    'white' => ['username' => 'alice-chesscom', 'result' => 'win'],
                    'black' => ['username' => 'stranger', 'result' => 'checkmated'],
                ]),
            ]),
            200,
        ),
    ]);

    $games = chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toHaveCount(1)
        ->and($games[0]->url)->toBe('https://www.chess.com/game/live/111');
});

test('searchGamesBetween returns empty array when 404', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 404),
    ]);

    $games = chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toBe([]);
});

test('searchGamesBetween throws TransientProviderError on 5xx', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 500),
    ]);

    expect(fn () => chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    ))->toThrow(TransientProviderError::class);
});

test('searchGamesBetween throws RateLimitedError on 429', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 429),
    ]);

    expect(fn () => chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    ))->toThrow(RateLimitedError::class);
});

test('searchGamesBetween throws PermanentProviderError on 4xx other than 429', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 401),
    ]);

    expect(fn () => chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    ))->toThrow(PermanentProviderError::class);
});
