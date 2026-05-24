<?php

use App\Console\Commands\LichessStreamCommand;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Jobs\AutoFetchLichessGameJob;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * M16 Phase 4 — coverage for `LichessStreamCommand::dispatchFromEvent`,
 * the pure decision logic that maps a parsed NDJSON event from Lichess
 * to a Pending GameMatch dispatch.
 *
 * The curl streaming loop itself is intentionally untested — testing
 * long-lived HTTP streams requires either a real Lichess connection or
 * a complex local HTTP fixture, neither of which pays for itself given
 * the Phase 2 cron + the existing AutoFetchLichessGameJob tests already
 * cover end-to-end settlement.
 */

/**
 * Build a Pending Lichess match with both sides snapshotted on the
 * Lichess provider. Mirrors what `TakeListingAction` produces in
 * production for a Lichess listing.
 *
 * @return array{0: GameMatch, 1: User, 2: User}
 */
function streamMatch(string $creatorHandle = 'alice-lichess', string $takerHandle = 'bob-lichess'): array
{
    $creator = User::factory()->active()->withLichess($creatorHandle)->create();
    $taker = User::factory()->withLichess($takerHandle)->create();

    $listing = Listing::factory()->forLichess()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    foreach ([
        GameMatch::SIDE_CREATOR => $creatorHandle,
        GameMatch::SIDE_TAKER => $takerHandle,
    ] as $side => $u) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'side' => $side,
            'provider' => LinkedAccountProvider::Lichess,
            'username' => $u,
        ]);
    }

    return [$match->fresh(), $creator, $taker];
}

function gameEndEvent(array $overrides = []): array
{
    return array_merge([
        'id' => 'abcdefgh',
        'status' => 30,
        'statusName' => 'mate',
        'rated' => true,
        'variant' => 'standard',
        'speed' => 'blitz',
        'perf' => 'blitz',
        'createdAt' => 1_716_000_000_000,
        'winner' => 'white',
        'players' => [
            'white' => ['userId' => 'alice-lichess', 'rating' => 1500],
            'black' => ['userId' => 'bob-lichess', 'rating' => 1495],
        ],
    ], $overrides);
}

// ─── Happy path: end-of-game dispatches AutoFetch for the matching match ──

test('mate event between snapshotted players dispatches AutoFetchLichessGameJob', function () {
    Queue::fake();

    [$match] = streamMatch();

    $result = app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent());

    expect($result?->id)->toBe($match->id);
    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

test('resign event dispatches', function () {
    Queue::fake();

    [$match] = streamMatch();

    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'status' => 31,
        'statusName' => 'resign',
        'winner' => 'black',
    ]));

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

test('draw event dispatches (winner_color null on AutoFetch + draws settle as draw)', function () {
    Queue::fake();

    [$match] = streamMatch();

    $event = gameEndEvent(['status' => 34, 'statusName' => 'draw']);
    unset($event['winner']);

    app(LichessStreamCommand::class)->dispatchFromEvent($event);

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

test('player-pair match is order-independent (white = taker, black = creator)', function () {
    Queue::fake();

    [$match] = streamMatch(creatorHandle: 'alice-lichess', takerHandle: 'bob-lichess');

    // Lichess assigned bob the white side this time.
    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'winner' => 'white',
        'players' => [
            'white' => ['userId' => 'bob-lichess', 'rating' => 1495],
            'black' => ['userId' => 'alice-lichess', 'rating' => 1500],
        ],
    ]));

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

test('player-pair lookup is case-insensitive', function () {
    Queue::fake();

    [$match] = streamMatch();

    // Stream-emitted userIds in uppercase (Lichess preserves user-chosen
    // case in `userId`; we lowercase both sides for the match).
    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'players' => [
            'white' => ['userId' => 'ALICE-LICHESS', 'rating' => 1500],
            'black' => ['userId' => 'BOB-LICHESS', 'rating' => 1495],
        ],
    ]));

    Queue::assertPushed(
        AutoFetchLichessGameJob::class,
        fn (AutoFetchLichessGameJob $job) => $job->match->id === $match->id,
    );
});

// ─── Skip cases ────────────────────────────────────────────────────────────

test('started event is skipped (game just began, no settlement signal)', function () {
    Queue::fake();

    streamMatch();

    $result = app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'status' => 20,
        'statusName' => 'started',
    ]));

    expect($result)->toBeNull();
    Queue::assertNothingPushed();
});

test('created event is skipped', function () {
    Queue::fake();

    streamMatch();

    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'status' => 10,
        'statusName' => 'created',
    ]));

    Queue::assertNothingPushed();
});

test('event with missing white userId is skipped', function () {
    Queue::fake();

    streamMatch();

    $event = gameEndEvent();
    unset($event['players']['white']['userId']);

    app(LichessStreamCommand::class)->dispatchFromEvent($event);

    Queue::assertNothingPushed();
});

test('event with no matching Pending match is skipped', function () {
    Queue::fake();

    streamMatch();

    // Two random Lichess users who aren't part of any Stakly Pending match.
    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent([
        'players' => [
            'white' => ['userId' => 'random-stranger-1', 'rating' => 1500],
            'black' => ['userId' => 'random-stranger-2', 'rating' => 1495],
        ],
    ]));

    Queue::assertNothingPushed();
});

test('event for a Settled match is skipped (status filter)', function () {
    Queue::fake();

    [$match] = streamMatch();
    $match->update(['status' => MatchStatus::Settled, 'settled_at' => now()]);

    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent());

    Queue::assertNothingPushed();
});

test('event for a Cancelled match is skipped', function () {
    Queue::fake();

    [$match] = streamMatch();
    $match->update(['status' => MatchStatus::Cancelled, 'cancelled_at' => now()]);

    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent());

    Queue::assertNothingPushed();
});

// ─── Cross-platform safety: Lichess game does NOT settle a chess.com match ─

test('Lichess game between two players with a chess.com match does NOT dispatch', function () {
    Queue::fake();

    // Both players have BOTH providers linked (common — players often
    // link both). The Stakly match is on a chess.com listing, with
    // BOTH provider snapshots written (per TakeListingAction's "snapshot
    // all verified providers" rule).
    $creator = User::factory()->active()
        ->withLichess('alice-lichess')
        ->withChessCom('alice-cc')
        ->create();
    $taker = User::factory()
        ->withLichess('bob-lichess')
        ->withChessCom('bob-cc')
        ->create();

    $listing = Listing::factory()->forChessCom()->taken()->for($creator)->create();
    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
        'status' => MatchStatus::Pending,
    ]);

    foreach ([
        [GameMatch::SIDE_CREATOR, LinkedAccountProvider::Lichess, 'alice-lichess'],
        [GameMatch::SIDE_CREATOR, LinkedAccountProvider::ChessCom, 'alice-cc'],
        [GameMatch::SIDE_TAKER, LinkedAccountProvider::Lichess, 'bob-lichess'],
        [GameMatch::SIDE_TAKER, LinkedAccountProvider::ChessCom, 'bob-cc'],
    ] as [$side, $provider, $username]) {
        MatchProviderSnapshot::create([
            'match_id' => $match->id,
            'side' => $side,
            'provider' => $provider,
            'username' => $username,
        ]);
    }

    // Lichess says alice + bob just finished a game on Lichess. But the
    // match is on chess.com — that game isn't the right settlement signal.
    app(LichessStreamCommand::class)->dispatchFromEvent(gameEndEvent());

    Queue::assertNothingPushed();
});
