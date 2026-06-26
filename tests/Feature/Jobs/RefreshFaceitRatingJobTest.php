<?php

use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Provider\Exceptions\TransientProviderError;
use App\Services\Provider\FaceitProfileClient;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
});

function faceitRatingAccount(int $skillRating = 1500, string $guid = 'guid-a1'): LinkedAccount
{
    $user = User::factory()->withFaceit('alice', $guid, $skillRating, now()->subDays(2))->create();

    return $user->linkedAccounts()->firstOrFail();
}

function runRatingRefresh(LinkedAccount $account): void
{
    (new RefreshFaceitRatingJob($account))->handle(
        app(FaceitProfileClient::class),
        app(ProviderCircuitBreaker::class),
    );
}

it('updates skill_rating and synced_at on a successful fetch', function () {
    $account = faceitRatingAccount(1500, 'guid-win');
    Http::fake([
        'open.faceit.com/data/v4/players/guid-win' => Http::response([
            'player_id' => 'guid-win',
            'nickname' => 'alice',
            'games' => ['cs2' => ['faceit_elo' => 1875, 'skill_level' => 8]],
        ], 200),
    ]);

    runRatingRefresh($account);

    $account->refresh();
    expect($account->skill_rating)->toBe(1875)
        ->and($account->skill_rating_synced_at)->not->toBeNull();
});

it('keeps the last-known rating but bumps synced_at when the player has no CS2 ELO', function () {
    $account = faceitRatingAccount(1500, 'guid-nocs2');
    $before = $account->skill_rating_synced_at;
    Http::fake([
        'open.faceit.com/data/v4/players/guid-nocs2' => Http::response([
            'player_id' => 'guid-nocs2',
            'games' => [],
        ], 200),
    ]);

    runRatingRefresh($account);

    $account->refresh();
    expect($account->skill_rating)->toBe(1500)
        ->and($account->skill_rating_synced_at->gt($before))->toBeTrue();
});

it('keeps the last-known rating and does not mark synced when no api key is set', function () {
    config(['services.faceit.api_key' => null]);
    Http::preventStrayRequests();
    $account = faceitRatingAccount(1500, 'guid-nokey');
    $account->update(['skill_rating_synced_at' => null]);

    runRatingRefresh($account);

    $account->refresh();
    expect($account->skill_rating)->toBe(1500)
        ->and($account->skill_rating_synced_at)->toBeNull();
});

it('keeps the last-known rating on a 404 (identity gone)', function () {
    $account = faceitRatingAccount(1500, 'guid-404');
    Http::fake(['open.faceit.com/data/v4/players/guid-404' => Http::response([], 404)]);

    runRatingRefresh($account);

    $account->refresh();
    expect($account->skill_rating)->toBe(1500);
});

it('rethrows a transient 5xx error for retry and keeps the last-known rating', function () {
    $account = faceitRatingAccount(1500, 'guid-503');
    Http::fake(['open.faceit.com/data/v4/players/guid-503' => Http::response([], 503)]);

    expect(fn () => runRatingRefresh($account))->toThrow(TransientProviderError::class);

    $account->refresh();
    expect($account->skill_rating)->toBe(1500);
});

it('does not call the API or change anything when the breaker is open', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 6) as $ignored) {
        $breaker->recordFailure(LinkedAccountProvider::Faceit);
    }
    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();

    $account = faceitRatingAccount(1500, 'guid-open');
    Http::fake();
    Http::preventStrayRequests();

    runRatingRefresh($account);

    Http::assertNothingSent();
    $account->refresh();
    expect($account->skill_rating)->toBe(1500)
        ->and($account->skill_rating_synced_at->lt(now()->subHours(1)))->toBeTrue();
});

it('only touches the rating fields, leaving identity columns intact', function () {
    $account = faceitRatingAccount(1500, 'guid-scope');
    Http::fake([
        'open.faceit.com/data/v4/players/guid-scope' => Http::response([
            'player_id' => 'guid-scope',
            'games' => ['cs2' => ['faceit_elo' => 1600]],
        ], 200),
    ]);

    runRatingRefresh($account);

    $account->refresh();
    expect($account->username)->toBe('alice')
        ->and($account->provider_user_id)->toBe('guid-scope')
        ->and($account->skill_rating)->toBe(1600);
});
