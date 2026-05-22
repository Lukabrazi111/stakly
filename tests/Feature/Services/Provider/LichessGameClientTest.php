<?php

use App\Services\Provider\Exceptions\ProviderUnavailableException;
use App\Services\Provider\LichessGameClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function client(): LichessGameClient
{
    return app(LichessGameClient::class);
}

// ─── fetchGame ──────────────────────────────────────────────────────────────

test('fetchGame returns a parsed result on success', function () {
    Http::fake([
        'lichess.org/game/export/*' => Http::response(lichessGameFixture(), 200),
    ]);

    $game = client()->fetchGame('a1b2c3d4');

    expect($game)->not->toBeNull()
        ->and($game->id)->toBe('a1b2c3d4')
        ->and($game->whiteUsername)->toBe('alice-lichess')
        ->and($game->blackUsername)->toBe('bob-lichess')
        ->and($game->winnerColor)->toBe('white')
        ->and($game->status)->toBe('mate')
        ->and($game->speed)->toBe('blitz')
        ->and($game->variant)->toBe('standard')
        ->and($game->rated)->toBeTrue()
        ->and($game->isDecisive())->toBeTrue()
        ->and($game->isDraw())->toBeFalse()
        ->and($game->winnerUsername())->toBe('alice-lichess');
});

test('fetchGame returns null on 404', function () {
    Http::fake([
        'lichess.org/game/export/*' => Http::response('', 404),
    ]);

    expect(client()->fetchGame('missing01'))->toBeNull();
});

test('fetchGame throws ProviderUnavailable on 5xx', function () {
    Http::fake([
        'lichess.org/game/export/*' => Http::response('', 503),
    ]);

    expect(fn () => client()->fetchGame('a1b2c3d4'))
        ->toThrow(ProviderUnavailableException::class);
});

test('fetchGame throws ProviderUnavailable on 429 rate limit', function () {
    Http::fake([
        'lichess.org/game/export/*' => Http::response('', 429),
    ]);

    expect(fn () => client()->fetchGame('a1b2c3d4'))
        ->toThrow(ProviderUnavailableException::class);
});

test('fetchGame throws ProviderUnavailable on malformed JSON', function () {
    Http::fake([
        'lichess.org/game/export/*' => Http::response(['oops' => 'no id field'], 200),
    ]);

    expect(fn () => client()->fetchGame('a1b2c3d4'))
        ->toThrow(ProviderUnavailableException::class);
});

test('fetchGame parses a draw correctly (winner key omitted)', function () {
    // Real Lichess responses omit the `winner` key entirely on draws —
    // not `winner: null`. Mirror that shape.
    $payload = lichessGameFixture(['status' => 'draw']);
    unset($payload['winner']);

    Http::fake([
        'lichess.org/game/export/*' => Http::response($payload, 200),
    ]);

    $game = client()->fetchGame('a1b2c3d4');

    expect($game->winnerColor)->toBeNull()
        ->and($game->isDecisive())->toBeFalse()
        ->and($game->isDraw())->toBeTrue()
        ->and($game->winnerUsername())->toBeNull();
});

test('fetchGame treats aborted games as neither decisive nor draw', function () {
    $payload = lichessGameFixture(['status' => 'aborted']);
    unset($payload['winner']);

    Http::fake([
        'lichess.org/game/export/*' => Http::response($payload, 200),
    ]);

    $game = client()->fetchGame('a1b2c3d4');

    expect($game->status)->toBe('aborted')
        ->and($game->isDecisive())->toBeFalse()
        ->and($game->isDraw())->toBeFalse()
        ->and($game->winnerUsername())->toBeNull();
});

test('fetchGame handles AI/bot opponents (no user.name) gracefully', function () {
    // Stockfish + anonymous-guest play omits the `players.{color}.user`
    // block. We surface '' so cross-check fails — no card for AI games.
    $payload = lichessGameFixture();
    $payload['players']['black'] = ['aiLevel' => 4];

    Http::fake([
        'lichess.org/game/export/*' => Http::response($payload, 200),
    ]);

    $game = client()->fetchGame('a1b2c3d4');

    expect($game->whiteUsername)->toBe('alice-lichess')
        ->and($game->blackUsername)->toBe('');
});

// ─── searchGamesBetween ─────────────────────────────────────────────────────

test('searchGamesBetween parses ndjson into multiple games', function () {
    $game1 = json_encode(lichessGameFixture(['id' => 'game0001']));
    $game2 = json_encode(lichessGameFixture(['id' => 'game0002', 'winner' => 'black']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response($game1."\n".$game2, 200),
    ]);

    $games = client()->searchGamesBetween(
        'alice-lichess',
        'bob-lichess',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toHaveCount(2)
        ->and($games[0]->id)->toBe('game0001')
        ->and($games[1]->id)->toBe('game0002')
        ->and($games[1]->winnerColor)->toBe('black')
        ->and($games[1]->winnerUsername())->toBe('bob-lichess');
});

test('searchGamesBetween returns empty array on empty body', function () {
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 200),
    ]);

    $games = client()->searchGamesBetween(
        'alice-lichess',
        'bob-lichess',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toBe([]);
});

test('searchGamesBetween returns empty array on 404 (user deactivated)', function () {
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 404),
    ]);

    $games = client()->searchGamesBetween(
        'gone-alice',
        'bob-lichess',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toBe([]);
});

test('searchGamesBetween throws ProviderUnavailable on 5xx', function () {
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 503),
    ]);

    expect(fn () => client()->searchGamesBetween(
        'alice-lichess',
        'bob-lichess',
        CarbonImmutable::now()->subHour(),
    ))->toThrow(ProviderUnavailableException::class);
});

test('searchGamesBetween skips malformed ndjson lines and keeps valid ones', function () {
    $good = json_encode(lichessGameFixture(['id' => 'goodgame']));

    Http::fake([
        'lichess.org/api/games/user/*' => Http::response(
            $good."\n{ not json\n".$good,
            200,
        ),
    ]);

    $games = client()->searchGamesBetween(
        'alice-lichess',
        'bob-lichess',
        CarbonImmutable::now()->subHour(),
    );

    expect($games)->toHaveCount(2)
        ->and($games[0]->id)->toBe('goodgame')
        ->and($games[1]->id)->toBe('goodgame');
});

test('searchGamesBetween passes since as epoch ms and vs as the opponent', function () {
    Http::fake([
        'lichess.org/api/games/user/*' => Http::response('', 200),
    ]);

    $since = CarbonImmutable::parse('2026-01-01T00:00:00Z');

    client()->searchGamesBetween(
        'alice-lichess',
        'bob-lichess',
        $since,
    );

    Http::assertSent(function ($request) use ($since) {
        return str_starts_with($request->url(), 'https://lichess.org/api/games/user/alice-lichess')
            && str_contains($request->url(), 'since='.$since->getTimestampMs())
            && str_contains($request->url(), 'vs=bob-lichess');
    });
});
