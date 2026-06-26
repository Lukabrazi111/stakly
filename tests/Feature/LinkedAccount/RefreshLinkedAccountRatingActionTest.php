<?php

use App\Actions\LinkedAccount\RefreshLinkedAccountRatingAction;
use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\User;
use App\Services\Provider\ProviderCircuitBreaker;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    Queue::fake();
});

it('dispatches a refresh when the rating has never been synced', function () {
    $user = User::factory()->withFaceit('alice', 'guid-never', 1500)->create();
    $account = $user->linkedAccounts()->firstOrFail();
    $account->update(['skill_rating_synced_at' => null]);

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeTrue();
    Queue::assertPushed(RefreshFaceitRatingJob::class);
});

it('dispatches when the cached rating is older than the TTL', function () {
    $user = User::factory()->withFaceit('alice', 'guid-stale', 1500, now()->subHours(25))->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeTrue();
    Queue::assertPushed(RefreshFaceitRatingJob::class);
});

it('does not dispatch when the cached rating is still fresh', function () {
    $user = User::factory()->withFaceit('alice', 'guid-fresh', 1500, now()->subHour())->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('force-dispatches even when the rating is fresh', function () {
    $user = User::factory()->withFaceit('alice', 'guid-force', 1500, now()->subHour())->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account, force: true))->toBeTrue();
    Queue::assertPushed(RefreshFaceitRatingJob::class);
});

it('does not dispatch when no FACEIT api key is configured', function () {
    config(['services.faceit.api_key' => null]);
    $user = User::factory()->withFaceit('alice', 'guid-nokey', 1500, now()->subDays(2))->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('does not dispatch for a non-FACEIT (chess) account', function () {
    $user = User::factory()->withLichess('alice')->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account, force: true))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('does not dispatch when the FACEIT account has no provider_user_id', function () {
    $user = User::factory()->withFaceit('alice', 'guid-x', 1500, now()->subDays(2))->create();
    $account = $user->linkedAccounts()->firstOrFail();
    $account->update(['provider_user_id' => null]);

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('does not dispatch when the FACEIT circuit breaker is open', function () {
    $breaker = app(ProviderCircuitBreaker::class);
    foreach (range(1, 6) as $ignored) {
        $breaker->recordFailure(LinkedAccountProvider::Faceit);
    }
    expect($breaker->isOpen(LinkedAccountProvider::Faceit))->toBeTrue();

    $user = User::factory()->withFaceit('alice', 'guid-open', 1500, now()->subDays(2))->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('treats a rating synced just under the TTL boundary as fresh', function () {
    $user = User::factory()->withFaceit('alice', 'guid-under', 1500, now()->subHours(24)->addMinute())->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeFalse();
    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('treats a rating synced just past the TTL boundary as stale', function () {
    $user = User::factory()->withFaceit('alice', 'guid-over', 1500, now()->subHours(24)->subMinute())->create();
    $account = $user->linkedAccounts()->firstOrFail();

    expect(app(RefreshLinkedAccountRatingAction::class)->handle($account))->toBeTrue();
    Queue::assertPushed(RefreshFaceitRatingJob::class);
});
