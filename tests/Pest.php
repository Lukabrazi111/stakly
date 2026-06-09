<?php

use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\GameApi\MockGameApi;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // No-op `@vite(...)` directives during tests so a missing
        // `public/build/manifest.json` (e.g. on CI where we skip the
        // frontend build to keep the pipeline fast) doesn't 500 every
        // Feature test that renders an Inertia/Blade response.
        $this->withoutVite();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Ensure the platform user exists in the test DB. Required by any test that
 * exercises `Wallet::fee(...)` — the platform rake credit recipient is looked
 * up via `User::query()->where('is_platform', true)->firstOrFail()`. The
 * production seeder creates this user, but `RefreshDatabase` doesn't run
 * seeders, so tests must seed it explicitly.
 *
 * Idempotent — safe to call multiple times within the same test (returns
 * the existing user on repeat calls).
 */
function platformUser(): User
{
    return User::query()
        ->where('is_platform', true)
        ->first()
        ?? User::factory()->create(['is_platform' => true]);
}

/**
 * A `Pending` match with both stakes already escrowed — the canonical
 * starting state for any test that exercises a downstream lifecycle
 * Action (settle, dispute, cancel, auto-fetch, timeout, etc.). Returns
 * `[$creator, $taker, $listing, $match]` so individual tests destructure
 * what they need.
 *
 * Stake is configurable; default `$100` matches the conventional fixture
 * used elsewhere (pot = 200, fee 10% = 20, winner payout = 180).
 *
 * Does NOT set up linked accounts — most action-level tests don't care,
 * and the few that do can `withLichess()` / `withChessCom()` directly on
 * the returned users + create a corresponding `MatchProviderSnapshot`.
 *
 * @return array{0: User, 1: User, 2: Listing, 3: GameMatch}
 */
function pendingMatch(string $stake = '100'): array
{
    platformUser();

    $creator = User::factory()->create();
    Wallet::deposit($creator, '500', reference: "test:deposit:creator:{$creator->id}");

    $taker = User::factory()->create();
    Wallet::deposit($taker, '500', reference: "test:deposit:taker:{$taker->id}");

    $listing = Listing::factory()->taken()->for($creator)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $creator,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );
    Wallet::hold(
        user: $taker,
        amount: $stake,
        listing: $listing,
        reference: "match-take:{$listing->id}",
    );

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $taker->id,
    ]);

    return [$creator, $taker, $listing, $match];
}

/**
 * Realistic Lichess game-export JSON fixture, trimmed to the fields
 * `LichessGameClient` parses. Captured from a real
 * `lichess.org/game/export/{id}` response. Used by the client tests AND
 * by the Phase 4 paste-path / auto-fetch job tests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lichessGameFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 'a1b2c3d4',
        'rated' => true,
        'variant' => 'standard',
        'speed' => 'blitz',
        'perf' => 'blitz',
        'createdAt' => 1_716_000_000_000,
        'lastMoveAt' => 1_716_000_180_000,
        'status' => 'mate',
        'winner' => 'white',
        'players' => [
            'white' => ['user' => ['name' => 'alice-lichess', 'id' => 'alice-lichess']],
            'black' => ['user' => ['name' => 'bob-lichess', 'id' => 'bob-lichess']],
        ],
    ], $overrides);
}

/**
 * Realistic chess.com monthly-archive game fixture, trimmed to the
 * fields `ChessComGameClient` parses. Captured from a real
 * `api.chess.com/pub/player/{user}/games/{YYYY}/{MM}` response. Wrap a
 * list of these in `['games' => [...]]` to mimic the full archive shape.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chessComGameFixture(array $overrides = []): array
{
    return array_merge([
        'url' => 'https://www.chess.com/game/live/12345678901',
        'time_control' => '180',
        'time_class' => 'blitz',
        'rules' => 'chess',
        'rated' => true,
        'start_time' => 1_716_000_000,
        'end_time' => 1_716_000_180,
        'white' => [
            'username' => 'alice-chesscom',
            'rating' => 1500,
            'result' => 'win',
        ],
        'black' => [
            'username' => 'bob-chesscom',
            'rating' => 1495,
            'result' => 'checkmated',
        ],
    ], $overrides);
}

/**
 * Wrap one or more `chessComGameFixture` payloads into the monthly
 * archive shape chess.com returns.
 *
 * @param  list<array<string, mixed>>  $games
 * @return array<string, mixed>
 */
function chessComArchiveFixture(array $games): array
{
    return ['games' => $games];
}

/**
 * Realistic FACEIT `GET /data/v4/players/{id}/history` response fixture —
 * paginated list of match references the auto-fetch job iterates. Each item
 * is the slim shape `searchPlayerMatches()` reads (just `match_id`); the job
 * calls `fetchMatch()` per ID for full details.
 *
 * @param  list<string>  $matchIds
 * @return array<string, mixed>
 */
function faceitHistoryFixture(array $matchIds = []): array
{
    return [
        'items' => array_map(
            fn (string $id) => [
                'match_id' => $id,
                'status' => 'FINISHED',
            ],
            $matchIds,
        ),
        'start' => 0,
        'end' => count($matchIds),
    ];
}

/**
 * Realistic FACEIT `GET /data/v4/matches/{match_id}` response fixture, trimmed
 * to the fields `FaceitGameClient` parses. Captured from the Phase 0 research
 * shape — 5v5 CS2 matchmaking, faction1 wins, both rosters AC-required.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function faceitMatchFixture(array $overrides = []): array
{
    return array_merge([
        'match_id' => '1-abcd1234-ef56-7890-ab12-cd34ef567890',
        'game' => 'cs2',
        'region' => 'EU',
        'competition_type' => 'matchmaking',
        'started_at' => 1_716_000_000,
        'finished_at' => 1_716_002_000,
        'status' => 'FINISHED',
        'results' => [
            'winner' => 'faction1',
            'score' => ['faction1' => 16, 'faction2' => 14],
        ],
        'teams' => [
            'faction1' => [
                'roster' => [
                    ['player_id' => 'guid-a1', 'nickname' => 'alice-faceit', 'anticheat_required' => true],
                    ['player_id' => 'guid-a2', 'nickname' => 'alice2', 'anticheat_required' => true],
                    ['player_id' => 'guid-a3', 'nickname' => 'alice3', 'anticheat_required' => true],
                    ['player_id' => 'guid-a4', 'nickname' => 'alice4', 'anticheat_required' => true],
                    ['player_id' => 'guid-a5', 'nickname' => 'alice5', 'anticheat_required' => true],
                ],
            ],
            'faction2' => [
                'roster' => [
                    ['player_id' => 'guid-b1', 'nickname' => 'bob-faceit', 'anticheat_required' => true],
                    ['player_id' => 'guid-b2', 'nickname' => 'bob2', 'anticheat_required' => true],
                    ['player_id' => 'guid-b3', 'nickname' => 'bob3', 'anticheat_required' => true],
                    ['player_id' => 'guid-b4', 'nickname' => 'bob4', 'anticheat_required' => true],
                    ['player_id' => 'guid-b5', 'nickname' => 'bob5', 'anticheat_required' => true],
                ],
            ],
        ],
    ], $overrides);
}

/**
 * Resolve the `MockGameApi` singleton directly. Tests that exercise
 * dispute resolution use this to call `forceWinner` / `forceUnknown`
 * without going through the public `GameApi` interface — which under the
 * default `chess` driver is wrapped by `ChessGameApi`. Both bindings
 * resolve to the same `MockGameApi` instance (see `AppServiceProvider::bindGameApi`),
 * so a forced winner applied here is honoured by the wrapper's fallback
 * path when no chess card exists.
 *
 * The singleton binding ensures forced state persists across the
 * controller call within the same test request.
 */
function mockGameApi(): MockGameApi
{
    $mock = app(MockGameApi::class);

    // Reset between calls — singleton means state from a prior test in the
    // same process could otherwise bleed in. Cheap belt-and-suspenders.
    $mock->reset();

    return $mock;
}
