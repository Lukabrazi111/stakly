<?php

use App\Services\Provider\Exceptions\PermanentProviderError;
use App\Services\Provider\Exceptions\RateLimitedError;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\FaceitGameClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Default to a configured key so the graceful-null path doesn't short-
    // circuit every test. Individual tests can override / null it out.
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
});

// ─── fetchMatch — happy path ───────────────────────────────────────────────

test('fetchMatch returns a parsed result on success', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response(faceitMatchFixture(), 200),
    ]);

    $match = app(FaceitGameClient::class)->fetchMatch('1-abcd');

    expect($match)->not->toBeNull()
        ->and($match->id)->toBe('1-abcd1234-ef56-7890-ab12-cd34ef567890')
        ->and($match->game)->toBe('cs2')
        ->and($match->region)->toBe('EU')
        ->and($match->competitionType)->toBe('matchmaking')
        ->and($match->status)->toBe('FINISHED')
        ->and($match->winnerFaction)->toBe('faction1')
        ->and($match->faction1Roster)->toHaveCount(5)
        ->and($match->faction2Roster)->toHaveCount(5)
        ->and($match->faction1Roster[0]->playerId)->toBe('guid-a1')
        ->and($match->faction1Roster[0]->nickname)->toBe('alice-faceit')
        ->and($match->faction1Roster[0]->anticheatRequired)->toBeTrue()
        ->and($match->isFinished())->toBeTrue()
        ->and($match->isAntiCheatComplete())->toBeTrue()
        ->and($match->isDecisive())->toBeTrue()
        ->and($match->winnerRoster())->toHaveCount(5)
        ->and($match->winnerRoster()[0]->nickname)->toBe('alice-faceit');
});

// ─── fetchMatch — degraded paths ───────────────────────────────────────────

test('fetchMatch returns null on 404', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response('', 404),
    ]);

    expect(app(FaceitGameClient::class)->fetchMatch('missing-id'))->toBeNull();
});

test('fetchMatch returns null when no API key is configured', function () {
    config(['services.faceit.api_key' => null]);

    Http::fake();

    expect(app(FaceitGameClient::class)->fetchMatch('any-id'))->toBeNull();

    // No HTTP call should have been made — the graceful-null short-circuit
    // happens before the request goes out.
    Http::assertNothingSent();
});

// ─── fetchMatch — error classification ─────────────────────────────────────

test('fetchMatch throws TransientProviderError on 5xx', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response('', 503),
    ]);

    expect(fn () => app(FaceitGameClient::class)->fetchMatch('1-abc'))
        ->toThrow(TransientProviderError::class);
});

test('fetchMatch throws RateLimitedError on 429', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response('', 429),
    ]);

    expect(fn () => app(FaceitGameClient::class)->fetchMatch('1-abc'))
        ->toThrow(RateLimitedError::class);
});

test('fetchMatch populates retryAt from Retry-After header on 429', function () {
    CarbonImmutable::setTestNow('2026-06-09T12:00:00Z');
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response('', 429, ['Retry-After' => '60']),
    ]);

    $error = null;
    try {
        app(FaceitGameClient::class)->fetchMatch('1-abc');
    } catch (RateLimitedError $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull()
        ->and($error->retryAt()->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-06-09T12:01:00Z')->getTimestamp());
});

test('fetchMatch throws PermanentProviderError on 4xx other than 429', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response('', 403),
    ]);

    expect(fn () => app(FaceitGameClient::class)->fetchMatch('1-abc'))
        ->toThrow(PermanentProviderError::class);
});

test('fetchMatch throws PermanentProviderError on malformed JSON', function () {
    // `match_id` is the existence sentinel parseMatch requires.
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response(['game' => 'cs2'], 200),
    ]);

    expect(fn () => app(FaceitGameClient::class)->fetchMatch('1-abc'))
        ->toThrow(PermanentProviderError::class);
});

// ─── fetchMatch — auth header wired correctly ──────────────────────────────

test('fetchMatch sends Authorization Bearer with the configured API key', function () {
    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response(faceitMatchFixture(), 200),
    ]);

    app(FaceitGameClient::class)->fetchMatch('1-abc');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-faceit-api-key'));
});

// ─── FaceitMatchResult — anti-cheat gate semantics ─────────────────────────

test('isDecisive is false when any player has anticheat_required = false', function () {
    $fixture = faceitMatchFixture();
    $fixture['teams']['faction2']['roster'][3]['anticheat_required'] = false;

    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    $match = app(FaceitGameClient::class)->fetchMatch('1-abc');

    expect($match)->not->toBeNull()
        ->and($match->isAntiCheatComplete())->toBeFalse()
        ->and($match->isDecisive())->toBeFalse()
        // Winner is still parsed — the caller (job) decides what to do
        // with an AC-incomplete match.
        ->and($match->winnerFaction)->toBe('faction1');
});

test('isDecisive is false for non-finished status', function () {
    $fixture = faceitMatchFixture([
        'status' => 'CANCELLED',
        'results' => ['winner' => null, 'score' => ['faction1' => 0, 'faction2' => 0]],
    ]);

    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    $match = app(FaceitGameClient::class)->fetchMatch('1-abc');

    expect($match)->not->toBeNull()
        ->and($match->isFinished())->toBeFalse()
        ->and($match->winnerFaction)->toBeNull()
        ->and($match->isDecisive())->toBeFalse()
        ->and($match->winnerRoster())->toBe([]);
});

test('winnerRoster returns faction2 when faction2 wins', function () {
    $fixture = faceitMatchFixture([
        'results' => ['winner' => 'faction2', 'score' => ['faction1' => 7, 'faction2' => 16]],
    ]);

    Http::fake([
        'open.faceit.com/data/v4/matches/*' => Http::response($fixture, 200),
    ]);

    $match = app(FaceitGameClient::class)->fetchMatch('1-abc');

    expect($match)->not->toBeNull()
        ->and($match->winnerRoster())->toHaveCount(5)
        ->and($match->winnerRoster()[0]->nickname)->toBe('bob-faceit');
});

// ─── searchPlayerMatches — happy path + filters ────────────────────────────

test('searchPlayerMatches returns the list of match IDs from the history response', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(
            faceitHistoryFixture(['1-aaa', '1-bbb', '1-ccc']),
            200,
        ),
    ]);

    $ids = app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    );

    expect($ids)->toBe(['1-aaa', '1-bbb', '1-ccc']);
});

test('searchPlayerMatches sends game + from + limit query params', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture([]), 200),
    ]);

    $since = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    app(FaceitGameClient::class)->searchPlayerMatches('guid-alice', $since, 'cs2', 25);

    Http::assertSent(function ($request) use ($since) {
        $url = $request->url();

        return str_contains($url, 'game=cs2')
            && str_contains($url, 'from='.$since->getTimestamp())
            && str_contains($url, 'limit=25');
    });
});

// ─── searchPlayerMatches — degraded paths ──────────────────────────────────

test('searchPlayerMatches returns empty list on 404', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 404),
    ]);

    expect(app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-missing',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toBe([]);
});

test('searchPlayerMatches returns empty list when no API key is configured', function () {
    config(['services.faceit.api_key' => null]);

    Http::fake();

    $ids = app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    );

    expect($ids)->toBe([]);
    Http::assertNothingSent();
});

test('searchPlayerMatches returns empty list when the items array is empty', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(faceitHistoryFixture([]), 200),
    ]);

    expect(app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toBe([]);
});

test('searchPlayerMatches skips items missing a match_id field', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response([
            'items' => [
                ['match_id' => '1-aaa', 'status' => 'FINISHED'],
                ['status' => 'FINISHED'],
                ['match_id' => '1-ccc', 'status' => 'FINISHED'],
            ],
            'start' => 0,
            'end' => 3,
        ], 200),
    ]);

    expect(app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toBe(['1-aaa', '1-ccc']);
});

// ─── searchPlayerMatches — error classification ────────────────────────────

test('searchPlayerMatches throws TransientProviderError on 5xx', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 503),
    ]);

    expect(fn () => app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(TransientProviderError::class);
});

test('searchPlayerMatches throws RateLimitedError on 429', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 429),
    ]);

    expect(fn () => app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(RateLimitedError::class);
});

test('searchPlayerMatches throws PermanentProviderError on 4xx other than 429', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response('', 403),
    ]);

    expect(fn () => app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(PermanentProviderError::class);
});

test('searchPlayerMatches throws PermanentProviderError on malformed JSON', function () {
    Http::fake([
        'open.faceit.com/data/v4/players/*/history*' => Http::response(['unexpected' => 'shape'], 200),
    ]);

    expect(fn () => app(FaceitGameClient::class)->searchPlayerMatches(
        'guid-alice',
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(PermanentProviderError::class);
});
