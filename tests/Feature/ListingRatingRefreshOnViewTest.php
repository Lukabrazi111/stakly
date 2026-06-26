<?php

use App\Enums\Game;
use App\Jobs\RefreshChessRatingJob;
use App\Jobs\RefreshFaceitRatingJob;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
 * M41 P2/P3b — refresh-on-view: loading a board queues a stale-gated rating
 * refresh for the creators actually shown — CS2 (FACEIT) and chess (per-TC),
 * each scoped to the listing's own platform.
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

it('queues a chess refresh for a stale chess creator when the board is viewed', function () {
    // withLichess leaves skill_rating_synced_at null → stale.
    $creator = User::factory()->active()->withLichess('carol')->create();
    Listing::factory()->forLichess()->for($creator)->create();

    $this->get('/listings?filter[game]=chess')->assertOk();

    Queue::assertPushed(
        RefreshChessRatingJob::class,
        fn (RefreshChessRatingJob $job) => $job->linkedAccount->user_id === $creator->id,
    );
});

it('does not queue a chess refresh when the chess creator rating is fresh', function () {
    $creator = User::factory()->active()->withLichess('carol', now()->subHour())->create();
    Listing::factory()->forLichess()->for($creator)->create();

    $this->get('/listings?filter[game]=chess')->assertOk();

    Queue::assertNotPushed(RefreshChessRatingJob::class);
});

it('does not refresh a chess creator FACEIT rating from the chess board', function () {
    // The chess listing is on Lichess; the creator's unrelated stale FACEIT
    // rating must NOT be touched — only the listing's-platform account is.
    $creator = User::factory()->active()
        ->withLichess('carol', now()->subHour())
        ->withFaceit('carol-faceit', 'guid-x', 1500, now()->subDays(2))
        ->create();
    Listing::factory()->forLichess()->for($creator)->create();

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
