<?php

use App\Services\Provider\ChessComGameClient;
use App\Services\Provider\Exceptions\ProviderUnavailableException;
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

test('fetchGame throws ProviderUnavailable on 5xx', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 503),
    ]);

    expect(fn () => chessComClient()->fetchGame(
        'https://www.chess.com/game/live/12345678901',
        'alice-chesscom',
    ))->toThrow(ProviderUnavailableException::class);
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

test('searchGamesBetween throws ProviderUnavailable on 5xx', function () {
    Http::fake([
        'api.chess.com/pub/player/*/games/*' => Http::response('', 500),
    ]);

    expect(fn () => chessComClient()->searchGamesBetween(
        'alice-chesscom',
        'bob-chesscom',
        CarbonImmutable::now()->subHour(),
    ))->toThrow(ProviderUnavailableException::class);
});
