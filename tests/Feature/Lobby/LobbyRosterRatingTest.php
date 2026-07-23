<?php

use App\Enums\Game;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/**
 * M41 P7c — the CS2 lobby roster carries the FACEIT level dial data
 * (`faceit_rating`) + recent W/L/D form, same as the marketplace cards.
 */
test('lobby roster carries FACEIT level dial data + recent W/L form per participant', function () {
    $creator = User::factory()->active()->withFaceit(null, null, 1900)->create(); // ELO 1900 → level 9
    $unrated = User::factory()->active()->create(); // no FACEIT link → unrated

    $listing = Listing::factory()->teamPlay(2, Game::Cs2)->for($creator)->create([
        'status' => ListingStatus::Open,
    ]);
    LobbyParticipant::factory()->create(['listing_id' => $listing->id, 'user_id' => $creator->id, 'side' => 'a', 'slot_index' => 0]);
    LobbyParticipant::factory()->create(['listing_id' => $listing->id, 'user_id' => $unrated->id, 'side' => 'a', 'slot_index' => 1]);

    // A prior settled CS2 win for the creator (payout = win) → recent_form ['W'].
    $prior = Listing::factory()->teamPlay(2, Game::Cs2)->for($creator)->create();
    $priorMatch = GameMatch::factory()->for($prior)->create([
        'status' => MatchStatus::Settled,
        'settled_at' => now()->subDay(),
        'winner_user_id' => $creator->id,
    ]);
    LobbyParticipant::factory()->create(['listing_id' => $prior->id, 'user_id' => $creator->id, 'side' => 'a', 'slot_index' => 0]);
    Wallet::payout($creator, '100', $prior, "match-payout:{$priorMatch->id}:player-{$creator->id}");

    $this->actingAs($creator)
        ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lobby.roster.a.0.faceit_rating.elo', 1900)
            ->where('lobby.roster.a.0.faceit_rating.level', 9)
            ->where('lobby.roster.a.0.faceit_rating.is_unrated', false)
            ->where('lobby.roster.a.0.recent_form', ['W'])
            ->where('lobby.roster.a.1.faceit_rating.level', null)
            ->where('lobby.roster.a.1.faceit_rating.is_unrated', true)
            ->where('lobby.roster.a.1.recent_form', [])
        );
});
