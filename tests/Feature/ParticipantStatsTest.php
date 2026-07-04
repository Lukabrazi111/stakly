<?php

use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\ParticipantStats;
use App\Services\Wallet;

/**
 * M41 P7c — ParticipantStats is now team-aware (counts lobby roster members,
 * wins via the payout ledger). Regression guard for the old 1v1-shaped bug.
 */
test('counts team-play matches for non-creator/non-taker roster members (ledger wins)', function () {
    $creator = User::factory()->create();
    $teammate = User::factory()->create();   // side A winner, NOT creator/taker
    $opp1 = User::factory()->create();
    $opp2 = User::factory()->create();

    $listing = Listing::factory()->teamPlay(2, Game::Cs2)->for($creator)->create();
    $match = GameMatch::factory()->for($listing)->create([
        'status' => MatchStatus::Settled,
        'settled_at' => now(),
        'winner_user_id' => $creator->id, // slot-0 winner only
    ]);

    foreach ([[$creator, 'a', 0], [$teammate, 'a', 1], [$opp1, 'b', 0], [$opp2, 'b', 1]] as [$user, $side, $slot]) {
        LobbyParticipant::factory()->create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'side' => $side,
            'slot_index' => $slot,
        ]);
    }

    // Side A won — payout to both winners (the ledger record of the win).
    Wallet::payout($creator, '100', $listing, "match-payout:{$match->id}:player-{$creator->id}");
    Wallet::payout($teammate, '100', $listing, "match-payout:{$match->id}:player-{$teammate->id}");

    $stats = ParticipantStats::forBatch([$creator->id, $teammate->id, $opp1->id, $opp2->id]);

    // The teammate is neither creator nor taker — the old code reported 0
    // matches / null win-rate for them. Now: 1 match, 1 win.
    expect($stats[$teammate->id])->toBe(['total_matches' => 1, 'wins' => 1, 'win_rate' => 100]);
    expect($stats[$creator->id])->toBe(['total_matches' => 1, 'wins' => 1, 'win_rate' => 100]);
    expect($stats[$opp1->id])->toBe(['total_matches' => 1, 'wins' => 0, 'win_rate' => 0]);
    expect($stats[$opp2->id])->toBe(['total_matches' => 1, 'wins' => 0, 'win_rate' => 0]);
});

test('1v1 stats are unchanged (creator/taker, single winner)', function () {
    $winner = User::factory()->create();
    $loser = User::factory()->create();

    $listing = Listing::factory()->open()->for($winner)->create(['game' => Game::Chess]);
    $match = GameMatch::factory()->for($listing)->create([
        'status' => MatchStatus::Settled,
        'settled_at' => now(),
        'taker_user_id' => $loser->id,
        'winner_user_id' => $winner->id,
    ]);
    Wallet::payout($winner, '100', $listing, "match-payout:{$match->id}");

    $stats = ParticipantStats::forBatch([$winner->id, $loser->id]);

    expect($stats[$winner->id])->toBe(['total_matches' => 1, 'wins' => 1, 'win_rate' => 100]);
    expect($stats[$loser->id])->toBe(['total_matches' => 1, 'wins' => 0, 'win_rate' => 0]);
});
