<?php

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;

/*
 * M37 Phase 2 — the "busy sign". While a creator is mid-match for a game, their
 * same-game open listings drop off the public board / homepage / visitor
 * profile (all via `Listing::scopeOnPublicMarketplace`), so nobody clicks a
 * take the M37 concurrency guard would reject. Cross-game offers stay up, and
 * the owner still sees their own listings on their own profile (scopeOpen).
 */

test('a chess listing is hidden from the board while its creator is in a chess match', function () {
    $busy = User::factory()->active()->create();
    $free = User::factory()->active()->create();

    $hidden = Listing::factory()->open()->for($busy)->create();
    $shown = Listing::factory()->open()->for($free)->create();

    // Put the busy creator into a live chess match (as taker of another listing).
    GameMatch::factory()
        ->for(Listing::factory()->taken()->for(User::factory()))
        ->for($busy, 'taker')
        ->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->has('listings.data', 1)
        ->where('listings.data.0.id', $shown->id)
    );
});

test('a chess listing stays on the board while its creator is in a CS2 match (cross-game)', function () {
    $creator = User::factory()->active()->create();
    $listing = Listing::factory()->open()->for($creator)->create();

    // A live CS2 match for the creator must NOT hide their chess offer.
    $cs2Listing = Listing::factory()->teamPlay(2)->lobbyLocked()->for(User::factory())->create();
    GameMatch::factory()->for($cs2Listing)->create([
        'taker_user_id' => $cs2Listing->user_id,
        'status' => MatchStatus::Pending,
    ]);
    LobbyParticipant::factory()->sideB()->create([
        'listing_id' => $cs2Listing->id,
        'user_id' => $creator->id,
        'slot_index' => 0,
    ]);

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->has('listings.data', 1)
        ->where('listings.data.0.id', $listing->id)
    );
});

test('a hidden listing reappears once the creator\'s match resolves', function () {
    $busy = User::factory()->active()->create();
    $listing = Listing::factory()->open()->for($busy)->create();

    $match = GameMatch::factory()
        ->for(Listing::factory()->taken()->for(User::factory()))
        ->for($busy, 'taker')
        ->create();

    $this->get('/listings')->assertInertia(fn ($page) => $page->has('listings.data', 0));

    $match->update(['status' => MatchStatus::Settled]);

    $this->get('/listings')->assertInertia(fn ($page) => $page
        ->has('listings.data', 1)
        ->where('listings.data.0.id', $listing->id)
    );
});
