<?php

use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\RecentForm;
use App\Services\Wallet;

/**
 * Build a settled CS2 2v2: side A = $sideA (winners unless $draw), side B =
 * $sideB. Wins are recorded the real way — a Wallet payout per side-A player —
 * so the test exercises the LEDGER win-detection (not winner_user_id, which
 * only holds the slot-0 winner for team matches).
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 */
function settledCs2Match(array $sideA, array $sideB, bool $draw = false): GameMatch
{
    $listing = Listing::factory()->open()->teamPlay(2, Game::Cs2)->for($sideA[0])->create();

    $match = GameMatch::factory()->for($listing)->create([
        'status' => MatchStatus::Settled,
        'settled_at' => now(),
        // Team matches stamp only the slot-0 winner; null on a draw.
        'winner_user_id' => $draw ? null : $sideA[0]->id,
    ]);

    foreach ([[$sideA, LobbyParticipant::SIDE_A], [$sideB, LobbyParticipant::SIDE_B]] as [$team, $side]) {
        foreach ($team as $slot => $user) {
            LobbyParticipant::factory()->create([
                'listing_id' => $listing->id,
                'user_id' => $user->id,
                'side' => $side,
                'slot_index' => $slot,
            ]);
        }
    }

    if (! $draw) {
        foreach ($sideA as $winner) {
            Wallet::payout($winner, '100', $listing, "match-payout:{$match->id}:player-{$winner->id}");
        }
    }

    return $match;
}

test('CS2 team match: every winner is W, every loser is L (ledger, not slot-0 winner)', function () {
    $winners = User::factory()->count(2)->create();
    $losers = User::factory()->count(2)->create();

    settledCs2Match($winners->all(), $losers->all());

    $form = RecentForm::forBatch(
        collect([$winners, $losers])->flatten()->pluck('id'),
    );

    // The non-slot-0 winner ($winners[1]) must read W via its payout — it would
    // be L if we keyed on winner_user_id (which is only $winners[0]).
    expect($form[$winners[0]->id])->toBe(['W']);
    expect($form[$winners[1]->id])->toBe(['W']);
    expect($form[$losers[0]->id])->toBe(['L']);
    expect($form[$losers[1]->id])->toBe(['L']);
});

test('a drawn settled match (no winner, no payout) is D', function () {
    $teamA = User::factory()->count(2)->create();
    $teamB = User::factory()->count(2)->create();

    settledCs2Match($teamA->all(), $teamB->all(), draw: true);

    $form = RecentForm::forBatch(collect([$teamA, $teamB])->flatten()->pluck('id'));

    foreach (collect([$teamA, $teamB])->flatten() as $user) {
        expect($form[$user->id])->toBe(['D']);
    }
});

test('recent form is scoped to the game — a chess win does not show on the CS2 strip', function () {
    $player = User::factory()->create();

    // A chess 1v1 win for the player.
    $chessListing = Listing::factory()->open()->for($player)->create(['game' => Game::Chess]);
    GameMatch::factory()->for($chessListing)->settled($player)->create();
    Wallet::payout($player, '100', $chessListing, "match-payout:chess-{$chessListing->id}");

    expect(RecentForm::forBatch([$player->id], Game::Cs2))->toBe([$player->id => []]);
    expect(RecentForm::forBatch([$player->id], Game::Chess))->toBe([$player->id => ['W']]);
});

test('form is newest-first and capped at the last 5 matches', function () {
    $player = User::factory()->create();
    $opponent = User::factory()->create();

    // 6 settled CS2 matches, oldest → newest. Side A always wins (gets the
    // payouts), so the player wins when on side A. Even i → win.
    foreach (range(0, 5) as $i) {
        $teammate = User::factory()->create();
        $opponents = [$opponent, User::factory()->create()];

        $match = $i % 2 === 0
            ? settledCs2Match([$player, $teammate], $opponents)
            : settledCs2Match($opponents, [$player, $teammate]);

        $match->update(['settled_at' => now()->subDays(6 - $i)]);
    }

    $form = RecentForm::forBatch([$player->id])[$player->id];

    // 6 matches → capped at 5 (the oldest, i=0 win, drops off). Newest first:
    // i=5 (L), i=4 (W), i=3 (L), i=2 (W), i=1 (L).
    expect($form)->toBe(['L', 'W', 'L', 'W', 'L']);
});

test('attachTo populates recent_form on CS2 listings only', function () {
    $cs2Player = User::factory()->create();
    settledCs2Match([$cs2Player, User::factory()->create()], User::factory()->count(2)->create()->all());
    $cs2Listing = Listing::factory()->open()->teamPlay(2, Game::Cs2)->for($cs2Player)->create();

    $chessListing = Listing::factory()->open()->create(['game' => Game::Chess]);

    RecentForm::attachTo([$cs2Listing, $chessListing]);

    expect($cs2Listing->getAttribute('recent_form'))->toBe(['W']);
    expect($chessListing->getAttribute('recent_form'))->toBe([]);
});
