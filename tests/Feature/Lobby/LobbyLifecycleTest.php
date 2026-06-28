<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\LobbyFillTimeoutAction;
use App\Actions\Lobby\LobbyReadyCheckTimeoutAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P1 — LobbyReadyCheckTimeoutAction + LobbyFillTimeoutAction (cron actions).
 */

function lifecycleLobby(int $teamSize = 2): Listing
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:lc-create:{$creator->id}");

    return app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => null,
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => $teamSize,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);
}

function lifecyclePlayer(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:lc-fund:{$user->id}");

    return $user;
}

describe('LobbyReadyCheckTimeoutAction', function () {
    it('vacates non-Ready slots and reverts to recruiting when timer fires', function () {
        // Wingman 2v2: creator + 1 A + 2 B = 4 participants (max). Only the
        // creator readies up; the other 3 don't. Timer fires; non-Ready
        // get vacated, lobby goes back to recruiting.
        $listing = lifecycleLobby(teamSize: 2);
        $teammateA = lifecyclePlayer();
        $oppB1 = lifecyclePlayer();
        $oppB2 = lifecyclePlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        // Only creator reads up
        app(ToggleReadyAction::class)->handle($listing->user, $listing);

        // Force the deadline into the past so the action fires.
        $listing->refresh();
        $listing->update(['lobby_ready_check_deadline' => now()->subSecond()]);

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing);

        expect($result)->toBe('reverted');

        $listing->refresh();
        expect($listing->lobby_state)->toBe('recruiting');
        expect($listing->lobby_ready_check_deadline)->toBeNull();

        // Ready'd creator stays, non-Ready vacated
        $live = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->get();
        expect($live)->toHaveCount(1);
        expect($live->first()->user_id)->toBe($listing->user_id);
    });

    it('cancels the whole lobby when creator was non-Ready at timeout', function () {
        $listing = lifecycleLobby(teamSize: 2);
        $teammateA = lifecyclePlayer();
        $oppB1 = lifecyclePlayer();
        $oppB2 = lifecyclePlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        // Two non-creator participants Ready up; creator does NOT.
        app(ToggleReadyAction::class)->handle($teammateA, $listing);
        app(ToggleReadyAction::class)->handle($oppB1, $listing);

        $teammateBalanceBefore = Wallet::balanceFor($teammateA);
        $oppBalanceBefore = Wallet::balanceFor($oppB1);

        $listing->refresh();
        $listing->update(['lobby_ready_check_deadline' => now()->subSecond()]);

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing);

        expect($result)->toBe('cancelled');

        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Cancelled);
        expect($listing->lobby_state)->toBe('cancelled');
        expect($listing->gameMatch->fresh()->status)->toBe(MatchStatus::Cancelled);

        // Ready'd participants refunded
        expect(Wallet::balanceFor($teammateA))
            ->toBe(bcadd($teammateBalanceBefore, '100', 6));
        expect(Wallet::balanceFor($oppB1))
            ->toBe(bcadd($oppBalanceBefore, '100', 6));
    });

    it('no-ops when lobby is not in ready_checking state', function () {
        $listing = lifecycleLobby();

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing);

        expect($result)->toBe('noop');
    });

    it('no-ops when deadline is still in the future', function () {
        $listing = lifecycleLobby();
        $listing->update([
            'lobby_state' => 'ready_checking',
            'lobby_ready_check_deadline' => now()->addMinutes(3),
        ]);

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing);

        expect($result)->toBe('noop');
    });
});

describe('LobbyFillTimeoutAction', function () {
    it('cancels a recruiting listing and refunds Ready participants after 24h', function () {
        $listing = lifecycleLobby(teamSize: 5);
        app(ToggleReadyAction::class)->handle($listing->user, $listing);

        $balanceBefore = Wallet::balanceFor($listing->user);

        // Travel past 24h so the action's eligibility check passes.
        $result = app(LobbyFillTimeoutAction::class)->handle($listing);

        expect($result)->toBe('cancelled');

        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Expired);
        expect($listing->lobby_state)->toBe('expired');
        expect($listing->gameMatch->fresh()->status)->toBe(MatchStatus::Cancelled);

        // Creator's Ready stake refunded
        expect(Wallet::balanceFor($listing->user))
            ->toBe(bcadd($balanceBefore, '100', 6));

        // All participants vacated
        expect(LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->count())->toBe(0);
    });

    it('no-ops on already-locked listings', function () {
        $listing = lifecycleLobby();
        $listing->update(['lobby_state' => 'locked']);

        $result = app(LobbyFillTimeoutAction::class)->handle($listing);

        expect($result)->toBe('noop');
    });
});
