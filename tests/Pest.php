<?php

use App\Models\User;
use App\Services\GameApi\GameApi;
use App\Services\GameApi\MockGameApi;
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
 * Resolve the bound `GameApi` singleton, asserting it's the mock driver.
 * Tests that exercise dispute resolution use this to call `forceWinner` /
 * `forceUnknown` without going through the container themselves.
 *
 * The singleton-binding in `AppServiceProvider` ensures forced state
 * persists across the controller call within the same test request.
 */
function mockGameApi(): MockGameApi
{
    $api = app(GameApi::class);

    if (! $api instanceof MockGameApi) {
        throw new RuntimeException('Expected MockGameApi singleton, got '.$api::class);
    }

    // Reset between calls — singleton means state from a prior test in the
    // same process could otherwise bleed in. Cheap belt-and-suspenders.
    $api->reset();

    return $api;
}
