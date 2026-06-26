<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
 * M41 P1 — creating a CS2 (FACEIT) listing nudges the creator's cached rating
 * fresh. CS2 is team-only, so the trigger lives in CreateTeamPlayListingAction.
 */

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    Queue::fake();
});

function cs2ListingData(array $overrides = []): array
{
    return array_merge([
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => [],
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ], $overrides);
}

it('queues a FACEIT rating refresh when a CS2 listing is created with a stale creator rating', function () {
    $creator = User::factory()->active()->withFaceit('alice', 'guid-stale', 1500, now()->subDays(2))->create();

    app(CreateTeamPlayListingAction::class)->handle($creator, cs2ListingData());

    Queue::assertPushed(
        RefreshFaceitRatingJob::class,
        fn (RefreshFaceitRatingJob $job) => $job->linkedAccount->user_id === $creator->id,
    );
});

it('does not queue a refresh when the creator rating is already fresh', function () {
    $creator = User::factory()->active()->withFaceit('alice', 'guid-fresh', 1500, now()->subHour())->create();

    app(CreateTeamPlayListingAction::class)->handle($creator, cs2ListingData());

    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});
