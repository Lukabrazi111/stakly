<?php

use App\Enums\Game;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
 * M41 P2 — refresh-on-view: loading the marketplace queues a stale-gated FACEIT
 * rating refresh for the CS2 creators actually shown. Chess boards never do.
 */

beforeEach(function () {
    config(['services.faceit.api_key' => 'test-faceit-api-key']);
    Queue::fake();
});

it('queues a refresh for a stale CS2 creator when the board is viewed', function () {
    $creator = User::factory()->active()
        ->withFaceit('alice', 'guid-stale', 1500, now()->subDays(2))
        ->create();
    Listing::factory()->teamPlay(5, Game::Cs2)->for($creator)->create();

    $this->get('/listings?filter[game]=cs2')->assertOk();

    Queue::assertPushed(
        RefreshFaceitRatingJob::class,
        fn (RefreshFaceitRatingJob $job) => $job->linkedAccount->user_id === $creator->id,
    );
});

it('does not queue a refresh when the CS2 creator rating is fresh', function () {
    $creator = User::factory()->active()
        ->withFaceit('alice', 'guid-fresh', 1500, now()->subHour())
        ->create();
    Listing::factory()->teamPlay(5, Game::Cs2)->for($creator)->create();

    $this->get('/listings?filter[game]=cs2')->assertOk();

    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('does not queue a refresh when viewing the chess board', function () {
    $creator = User::factory()->active()
        ->withLichess('carol')
        ->withFaceit('carol-faceit', 'guid-chess', 1500, now()->subDays(2))
        ->create();
    Listing::factory()->forGame(Game::Chess)->for($creator)->create();

    $this->get('/listings?filter[game]=chess')->assertOk();

    Queue::assertNotPushed(RefreshFaceitRatingJob::class);
});

it('queues a refresh for the owner stale CS2 listing on the my-listings page', function () {
    $owner = User::factory()->active()
        ->withFaceit('dave', 'guid-mine', 1500, now()->subDays(2))
        ->create();
    Listing::factory()->teamPlay(5, Game::Cs2)->for($owner)->create();

    $this->actingAs($owner)->get('/listings/mine')->assertOk();

    Queue::assertPushed(
        RefreshFaceitRatingJob::class,
        fn (RefreshFaceitRatingJob $job) => $job->linkedAccount->user_id === $owner->id,
    );
});

it('queues a refresh for a stale CS2 creator when their profile is viewed', function () {
    $creator = User::factory()->active()
        ->withFaceit('erin', 'guid-profile', 1500, now()->subDays(2))
        ->create();
    Listing::factory()->teamPlay(5, Game::Cs2)->for($creator)->create();

    $this->get('/users/'.$creator->username)->assertOk();

    Queue::assertPushed(
        RefreshFaceitRatingJob::class,
        fn (RefreshFaceitRatingJob $job) => $job->linkedAccount->user_id === $creator->id,
    );
});

it('queues a refresh for a stale CS2 creator when the team-play lobby is viewed', function () {
    $creator = User::factory()->active()
        ->withFaceit('frank', 'guid-lobby', 1500, now()->subDays(2))
        ->create();
    $listing = Listing::factory()->teamPlay(5, Game::Cs2)->for($creator)->create();

    $this->actingAs($creator)->get('/listings/'.$listing->id)->assertOk();

    Queue::assertPushed(
        RefreshFaceitRatingJob::class,
        fn (RefreshFaceitRatingJob $job) => $job->linkedAccount->user_id === $creator->id,
    );
});
