<?php

use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\GameApi\MockGameApi;
use App\Services\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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

        // M26 P4 locale-prefix routing — tests outside `Feature/I18n/` use
        // unprefixed literals (`/listings`, `/wallet`) which the TestCase
        // override auto-prefixes to `/en/...`. Mirror that here so `route()`
        // calls inside controllers + factories also generate /en URLs.
        URL::defaults(['locale' => 'en']);
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
