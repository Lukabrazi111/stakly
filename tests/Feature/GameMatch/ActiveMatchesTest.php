<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;

/*
 * M36 — Active-matches quick access.
 *
 * Covers the "In Progress" definition + default view, the team-aware
 * participant scope (a 5v5 member who isn't creator/taker still sees + counts
 * their match), and the `auth.user.active_matches_count` shared prop.
 */

/**
 * Five 1v1 matches for Alice — one in each status. In Progress should surface
 * the 3 active ones (Pending / Disputed / ManualReview); the 2 terminal ones
 * (Settled / Cancelled) only appear in the All view.
 */
function m36AliceWithEveryStatus(): User
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $base = fn () => GameMatch::factory()
        ->for(Listing::factory()->taken()->for($alice))
        ->for($bob, 'taker');

    $base()->create();                   // Pending (factory default)
    $base()->disputed($alice)->create(); // Disputed
    $base()->manualReview()->create();   // ManualReview
    $base()->settled($alice)->create();  // Settled
    $base()->cancelled()->create();      // Cancelled

    return $alice;
}

test('MatchStatus::inProgress is Pending + Disputed + ManualReview', function () {
    expect(MatchStatus::inProgress())->toBe([
        MatchStatus::Pending,
        MatchStatus::Disputed,
        MatchStatus::ManualReview,
    ]);

    expect(MatchStatus::inProgressValues())->toBe(['pending', 'disputed', 'manual_review']);
});

test('the /matches index defaults to the In Progress view (3 active, terminals hidden)', function () {
    $alice = m36AliceWithEveryStatus();

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'in_progress')
            ->where('filters.status', null)
            ->has('matches.data', 3)
            ->where('matches.meta.total', 3)
        );
});

test('?view=all shows every status', function () {
    $alice = m36AliceWithEveryStatus();

    $this->actingAs($alice)
        ->get('/matches?view=all')
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'all')
            ->where('matches.meta.total', 5)
        );
});

test('?view=all&filter[status]=settled slices to the Settled match', function () {
    $alice = m36AliceWithEveryStatus();

    $this->actingAs($alice)
        ->get('/matches?view=all&filter[status]=settled')
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'all')
            ->where('filters.status', 'settled')
            ->has('matches.data', 1)
            ->where('matches.data.0.status', MatchStatus::Settled->value)
        );
});

test('a status chip is ignored in the In Progress view (no terminal leak)', function () {
    $alice = m36AliceWithEveryStatus();

    // No view=all → In Progress; a stray settled chip must not pull terminals in.
    $this->actingAs($alice)
        ->get('/matches?filter[status]=settled')
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'in_progress')
            ->where('filters.status', null)
            ->where('matches.meta.total', 3)
        );
});

test('a team member who is neither creator nor taker still sees their team match', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();

    $listing = Listing::factory()->teamPlay(2)->lobbyLocked()->for($creator)->create();
    $match = GameMatch::factory()->for($listing)->create([
        // M34: taker_user_id is the creator placeholder on team matches.
        'taker_user_id' => $creator->id,
        'status' => MatchStatus::Pending,
    ]);
    LobbyParticipant::factory()->sideB()->create([
        'listing_id' => $listing->id,
        'user_id' => $dave->id,
        'slot_index' => 0,
    ]);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.id', $match->id)
        );
});

test('a kicked team member does not see the team match', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();

    $listing = Listing::factory()->teamPlay(2)->lobbyLocked()->for($creator)->create();
    GameMatch::factory()->for($listing)->create([
        'taker_user_id' => $creator->id,
        'status' => MatchStatus::Pending,
    ]);
    LobbyParticipant::factory()->sideB()->kicked()->create([
        'listing_id' => $listing->id,
        'user_id' => $dave->id,
        'slot_index' => 0,
    ]);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->has('matches.data', 0));
});

test('active_matches_count shared prop counts only in-progress matches', function () {
    $alice = m36AliceWithEveryStatus();

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.active_matches_count', 3)
        );
});

test('active_matches_count includes a team member\'s locked match', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();

    $listing = Listing::factory()->teamPlay(2)->lobbyLocked()->for($creator)->create();
    GameMatch::factory()->for($listing)->create([
        'taker_user_id' => $creator->id,
        'status' => MatchStatus::Pending,
    ]);
    LobbyParticipant::factory()->sideB()->create([
        'listing_id' => $listing->id,
        'user_id' => $dave->id,
        'slot_index' => 0,
    ]);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.active_matches_count', 1)
        );
});
