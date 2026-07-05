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

/*
 * M36 Phase 3 — the live badge. When a match notification lands, the frontend
 * fires `router.reload({ only: ['auth'] })` to re-pull the count without a
 * navigation. These pin the two server-side guarantees that reload depends on:
 * the count rides the global shared `auth` (so the reload works from ANY page),
 * and it's recomputed per request (so it reflects the latest state, not a cache).
 */

test('active_matches_count is shared on every page, not just /matches', function () {
    $alice = m36AliceWithEveryStatus();

    // The badge reload fires wherever the user is when a match notification
    // arrives, so the count must ride global shared `auth`, not the /matches
    // controller. /wallet is a different Inertia component entirely.
    $this->actingAs($alice)
        ->get('/wallet')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.active_matches_count', 3)
        );
});

test('active_matches_count drops once a match leaves the in-progress set', function () {
    $alice = m36AliceWithEveryStatus();

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->where('auth.user.active_matches_count', 3));

    // A settling match is exactly what a `match_settled` broadcast signals; the
    // next `auth` fetch must reflect the smaller count. Status-only flip — this
    // pins the count query's freshness, not settlement money.
    GameMatch::query()
        ->where('status', MatchStatus::Pending)
        ->firstOrFail()
        ->update(['status' => MatchStatus::Settled]);

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->where('auth.user.active_matches_count', 2));
});

/*
 * M37 — `in_flight_games` shared prop: the distinct games the viewer is
 * currently mid-match in. Gates the Take button (one active match per game).
 */

test('in_flight_games lists the games the user is currently mid-match in', function () {
    $alice = m36AliceWithEveryStatus(); // 3 in-progress chess matches + 2 terminal

    $this->actingAs($alice)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.in_flight_games', ['chess'])
        );
});

test('in_flight_games spans every game the user is mid-match in (chess + CS2)', function () {
    $user = User::factory()->create();

    // A live chess match (as taker).
    GameMatch::factory()
        ->for(Listing::factory()->taken()->for(User::factory()))
        ->for($user, 'taker')
        ->create();

    // A live CS2 match (as a side-B roster member).
    $cs2Listing = Listing::factory()->teamPlay(2)->lobbyLocked()->for(User::factory())->create();
    GameMatch::factory()->for($cs2Listing)->create([
        'taker_user_id' => $cs2Listing->user_id,
        'status' => MatchStatus::Pending,
    ]);
    LobbyParticipant::factory()->sideB()->create([
        'listing_id' => $cs2Listing->id,
        'user_id' => $user->id,
        'slot_index' => 0,
    ]);

    $this->actingAs($user)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.active_matches_count', 2)
            ->has('auth.user.in_flight_games', 2)
        );
});

/*
 * M44 — recruiting-lobby findability. A team-play lobby the user is a LIVE
 * participant in (pre-lock, LobbyFilling) surfaces in the "In Progress" Matches
 * view + counts toward the badge, so a joiner who navigated away can get back.
 * It must NOT touch the take-gate (`in_flight_games`), and left/kicked players
 * must not see it.
 */

/**
 * A recruiting 2v2 CS2 lobby: the creator on side A slot 0, an optional
 * `$joiner` on side B slot 0, plus the pre-lock LobbyFilling match. Returns the
 * listing. Both participants are live (no kick), so live_participant_count is
 * 1 (creator only) or 2 (with joiner).
 */
function m44RecruitingLobby(User $creator, ?User $joiner = null): Listing
{
    $listing = Listing::factory()->teamPlay(2)->for($creator)->create();
    GameMatch::factory()->for($listing)->create([
        'taker_user_id' => $creator->id,
        'status' => MatchStatus::LobbyFilling,
    ]);
    LobbyParticipant::factory()->sideA()->create([
        'listing_id' => $listing->id,
        'user_id' => $creator->id,
        'slot_index' => 0,
    ]);
    if ($joiner !== null) {
        LobbyParticipant::factory()->sideB()->create([
            'listing_id' => $listing->id,
            'user_id' => $joiner->id,
            'slot_index' => 0,
        ]);
    }

    return $listing;
}

test('a joiner sees the recruiting lobby in the In Progress view, with fill + state (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    $listing = m44RecruitingLobby($creator, $dave);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1)
            ->where('matches.data.0.status', MatchStatus::LobbyFilling->value)
            ->where('matches.data.0.listing.id', $listing->id)
            ->where('matches.data.0.listing.team_size', 2)
            ->where('matches.data.0.listing.live_participant_count', 2)
            ->where('matches.data.0.listing.lobby_state', 'recruiting')
        );
});

test('the creator also sees their own recruiting lobby in Matches (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    m44RecruitingLobby($creator, $dave);

    $this->actingAs($creator)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->has('matches.data', 1));
});

test('a recruiting lobby you are not in is not visible (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    $carol = User::factory()->create();
    m44RecruitingLobby($creator, $dave);

    $this->actingAs($carol)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->has('matches.data', 0));
});

test('a kicked player no longer sees the recruiting lobby (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    $listing = m44RecruitingLobby($creator);
    LobbyParticipant::factory()->sideB()->kicked()->create([
        'listing_id' => $listing->id,
        'user_id' => $dave->id,
        'slot_index' => 0,
    ]);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->has('matches.data', 0));
});

test('active_matches_count includes a recruiting lobby you are live in (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    m44RecruitingLobby($creator, $dave);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page->where('auth.user.active_matches_count', 1));
});

test('in_flight_games EXCLUDES a recruiting lobby — the take-gate is unaffected (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    m44RecruitingLobby($creator, $dave); // CS2 recruiting lobby

    // Dave is in a CS2 recruiting lobby, but that must NOT gate him from taking
    // any listing — only LOCKED matches do. So in_flight_games stays empty even
    // though active_matches_count is 1.
    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.active_matches_count', 1)
            ->where('auth.user.in_flight_games', [])
        );
});

test('recruiting lobbies pin above locked matches in the In Progress view (M44)', function () {
    $user = User::factory()->create();

    // A locked (Pending) chess match — the NEWER row.
    $lockedMatch = GameMatch::factory()
        ->for(Listing::factory()->taken()->for(User::factory()))
        ->for($user, 'taker')
        ->create(['created_at' => now()]);

    // A recruiting lobby the user joined — created EARLIER, but pinned first.
    $lobbyOwner = User::factory()->create();
    $lobbyListing = Listing::factory()->teamPlay(2)->for($lobbyOwner)->create();
    $lobbyMatch = GameMatch::factory()->for($lobbyListing)->create([
        'taker_user_id' => $lobbyOwner->id,
        'status' => MatchStatus::LobbyFilling,
        'created_at' => now()->subHours(3),
    ]);
    LobbyParticipant::factory()->sideA()->create([
        'listing_id' => $lobbyListing->id, 'user_id' => $lobbyOwner->id, 'slot_index' => 0,
    ]);
    LobbyParticipant::factory()->sideB()->create([
        'listing_id' => $lobbyListing->id, 'user_id' => $user->id, 'slot_index' => 0,
    ]);

    $this->actingAs($user)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 2)
            ->where('matches.data.0.id', $lobbyMatch->id)   // recruiting pinned first
            ->where('matches.data.1.id', $lockedMatch->id)
        );
});

test('once the lobby locks it shows as a normal match, not duplicated (M44)', function () {
    $creator = User::factory()->create();
    $dave = User::factory()->create();
    $listing = m44RecruitingLobby($creator, $dave);

    // Lock: the match flips to Pending, the lobby to locked.
    GameMatch::query()->where('listing_id', $listing->id)
        ->update(['status' => MatchStatus::Pending]);
    $listing->update(['lobby_state' => 'locked']);

    $this->actingAs($dave)
        ->get('/matches')
        ->assertInertia(fn ($page) => $page
            ->has('matches.data', 1) // still one row, not doubled
            ->where('matches.data.0.status', MatchStatus::Pending->value)
        );
});
