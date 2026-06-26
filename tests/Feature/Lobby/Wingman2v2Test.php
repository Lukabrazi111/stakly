<?php

use App\Actions\GameMatch\SettleTeamMatchAction;
use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/*
 * M34 P5 — CS2 2v2 Wingman end-to-end via the production actions. Pins
 * the team_size-agnostic settlement path (`SettleTeamMatchAction` already
 * generalized in P4) against the smaller team size that Wingman enables.
 */

beforeEach(function () {
    config(['stakly.platform_fee_rate' => '0.10']);
});

function wingmanPlayer(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '500', reference: "test:wingman-fund:{$user->id}");

    return $user;
}

it('drives a 2v2 lobby through Create → Join × 3 → Ready × 4 → SettleTeamMatchAction', function () {
    platformUser();

    $creator = wingmanPlayer();
    $teammate = wingmanPlayer();
    $opp1 = wingmanPlayer();
    $opp2 = wingmanPlayer();

    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => null,
        'duration_hours' => 24,
        'team_size' => 2,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    expect($listing)->toBeInstanceOf(Listing::class);
    expect($listing->team_size)->toBe(2);
    expect($listing->lobby_state)->toBe('recruiting');

    app(JoinLobbyAction::class)->handle($teammate, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($opp1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($opp2, $listing, LobbyParticipant::SIDE_B);

    $listing->refresh();
    expect($listing->lobby_state)->toBe('ready_checking');

    app(ToggleReadyAction::class)->handle($creator, $listing);
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    app(ToggleReadyAction::class)->handle($opp1, $listing);
    app(ToggleReadyAction::class)->handle($opp2, $listing);

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Taken);
    expect($listing->lobby_state)->toBe('locked');

    $match = $listing->gameMatch->fresh();
    expect($match->status)->toBe(MatchStatus::Pending);

    app(SettleTeamMatchAction::class)->handle($match, [$creator, $teammate]);

    $match->refresh();
    expect($match->status)->toBe(MatchStatus::Settled);

    // Money math at team_size=2, stake=100, fee_rate=0.10:
    //   pot       = 100 × 2 × 2 = 400
    //   fee       = 40
    //   winnings  = 360
    //   perPlayer = 180 (exact; no remainder at scale 6)
    $payouts = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->where('type', WalletTransactionType::Payout)
        ->get();

    expect($payouts)->toHaveCount(2);
    foreach ($payouts as $payout) {
        expect($payout->amount)->toBe('180.000000');
    }

    $fees = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->where('type', WalletTransactionType::Fee)
        ->get();
    expect($fees)->toHaveCount(1)
        ->and($fees->first()->amount)->toBe('40.000000');

    $totalPayouts = $payouts->reduce(
        fn (string $sum, $p) => bcadd($sum, $p->amount, 6),
        '0',
    );
    $sum = bcadd($totalPayouts, $fees->first()->amount, 6);
    expect(bccomp($sum, bcmul('100', '4', 6), 6))->toBe(0);
});

it('credits the correct 2 user IDs (Team A wins)', function () {
    platformUser();

    $creator = wingmanPlayer();
    $teammate = wingmanPlayer();
    $opp1 = wingmanPlayer();
    $opp2 = wingmanPlayer();

    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => null,
        'duration_hours' => 24,
        'team_size' => 2,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    app(JoinLobbyAction::class)->handle($teammate, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($opp1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($opp2, $listing, LobbyParticipant::SIDE_B);

    foreach ([$creator, $teammate, $opp1, $opp2] as $user) {
        app(ToggleReadyAction::class)->handle($user, $listing);
    }

    $match = $listing->gameMatch->fresh();
    app(SettleTeamMatchAction::class)->handle($match, [$creator, $teammate]);

    $payoutUserIds = WalletTransaction::query()
        ->where('related_listing_id', $listing->id)
        ->where('type', WalletTransactionType::Payout)
        ->pluck('user_id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();

    $expected = collect([$creator->id, $teammate->id])->sort()->values()->all();

    expect($payoutUserIds)->toBe($expected);
});
