<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use App\Notifications\TeamMatchStartedNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\Notification;

/*
 * M34 P1 — ToggleReadyAction + the all-Ready → LobbyLockAction transition.
 */

function newTeamPlay(int $teamSize, string $side = LobbyParticipant::SIDE_A): Listing
{
    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:tr-create:{$creator->id}");

    return app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => [],
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => $teamSize,
        'creator_side' => $side,
        'is_public' => true,
    ]);
}

function newFundedPlayer(string $balance = '10000'): User
{
    platformUser();

    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, $balance, reference: "test:tr-fund:{$user->id}");

    return $user;
}

it('Wallet::hold fires when a participant clicks Ready', function () {
    $listing = newTeamPlay(teamSize: 2);
    $balanceBefore = Wallet::balanceFor($listing->user);

    $result = app(ToggleReadyAction::class)->handle($listing->user, $listing);

    expect($result)->toBe('readied');
    expect(Wallet::balanceFor($listing->user))
        ->toBe(bcsub($balanceBefore, '100', 6));

    $participant = LobbyParticipant::query()
        ->where('listing_id', $listing->id)
        ->where('user_id', $listing->user_id)
        ->first();
    expect($participant->is_ready)->toBeTrue();
    expect($participant->stake_held_at)->not->toBeNull();
});

it('Wallet::release fires when a participant un-Readys', function () {
    $listing = newTeamPlay(teamSize: 2);

    app(ToggleReadyAction::class)->handle($listing->user, $listing);
    $balanceAfterReady = Wallet::balanceFor($listing->user);

    $result = app(ToggleReadyAction::class)->handle($listing->user, $listing);

    expect($result)->toBe('unreadied');
    expect(Wallet::balanceFor($listing->user))
        ->toBe(bcadd($balanceAfterReady, '100', 6));

    $participant = LobbyParticipant::query()
        ->where('listing_id', $listing->id)
        ->where('user_id', $listing->user_id)
        ->first();
    expect($participant->is_ready)->toBeFalse();
    expect($participant->stake_held_at)->toBeNull();
});

it('returns "insufficient_balance" when stake exceeds balance', function () {
    platformUser();
    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '50', reference: "test:tr-fund:{$creator->id}");

    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '500',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => [],
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 2,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    $result = app(ToggleReadyAction::class)->handle($creator, $listing);

    expect($result)->toBe('insufficient_balance');
    expect(Wallet::balanceFor($creator))->toBe('50.000000'); // no debit
});

it('returns "not_in_lobby" when the user is not a participant', function () {
    $listing = newTeamPlay(teamSize: 2);
    $stranger = newFundedPlayer();

    $result = app(ToggleReadyAction::class)->handle($stranger, $listing);

    expect($result)->toBe('not_in_lobby');
});

it('locks the lobby when the final Ready click brings everyone to Ready', function () {
    $listing = newTeamPlay(teamSize: 2);
    $creator = $listing->user;

    $teammateA = newFundedPlayer();
    $oppB1 = newFundedPlayer();
    $oppB2 = newFundedPlayer();

    app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

    // First three Ready → 'readied'
    app(ToggleReadyAction::class)->handle($creator, $listing);
    app(ToggleReadyAction::class)->handle($teammateA, $listing);
    app(ToggleReadyAction::class)->handle($oppB1, $listing);

    // Fourth Ready locks the lobby
    $result = app(ToggleReadyAction::class)->handle($oppB2, $listing);

    expect($result)->toBe('locked_now');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Taken);
    expect($listing->lobby_state)->toBe('locked');
    expect($listing->lobby_ready_check_deadline)->toBeNull();
    expect($listing->gameMatch->fresh()->status)->toBe(MatchStatus::Pending);
});

it('populates match_provider_snapshots with slot_index when locking', function () {
    $listing = newTeamPlay(teamSize: 2);
    $creator = $listing->user;
    $teammateA = newFundedPlayer();
    $oppB1 = newFundedPlayer();
    $oppB2 = newFundedPlayer();

    app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

    app(ToggleReadyAction::class)->handle($creator, $listing);
    app(ToggleReadyAction::class)->handle($teammateA, $listing);
    app(ToggleReadyAction::class)->handle($oppB1, $listing);
    app(ToggleReadyAction::class)->handle($oppB2, $listing);

    $listing->refresh();
    $snapshots = MatchProviderSnapshot::query()
        ->where('match_id', $listing->gameMatch->id)
        ->get();

    expect($snapshots)->toHaveCount(4);

    foreach ($snapshots as $snap) {
        expect($snap->slot_index)->not->toBeNull();
        expect($snap->slot_index)->toBeGreaterThanOrEqual(0);
        expect($snap->slot_index)->toBeLessThan(2);
    }

    // Both sides represented
    expect($snapshots->where('side', 'a')->count())->toBe(2);
    expect($snapshots->where('side', 'b')->count())->toBe(2);
});

it('rejects toggling Ready on an already-locked lobby', function () {
    $listing = newTeamPlay(teamSize: 2);
    $listing->update(['lobby_state' => 'locked']);

    $result = app(ToggleReadyAction::class)->handle($listing->user, $listing);

    expect($result)->toBe('locked');
});

/*
 * M34 P8 Slice C — fan out `TeamMatchStartedNotification` to every locked-in
 * participant once the lobby actually locks. Pre-lock ready toggles, mid-flow
 * un-readies, and re-runs on an already-locked listing must NOT re-broadcast.
 */
describe('TeamMatchStartedNotification dispatch', function () {
    it('notifies every locked-in participant when the final Ready locks the lobby', function () {
        $listing = newTeamPlay(teamSize: 2);
        $creator = $listing->user;
        $teammateA = newFundedPlayer();
        $oppB1 = newFundedPlayer();
        $oppB2 = newFundedPlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);
        app(ToggleReadyAction::class)->handle($creator, $listing);
        app(ToggleReadyAction::class)->handle($teammateA, $listing);
        app(ToggleReadyAction::class)->handle($oppB1, $listing);

        Notification::fake();

        // Last Ready locks the lobby and fans out.
        app(ToggleReadyAction::class)->handle($oppB2, $listing);

        Notification::assertSentTo($creator, TeamMatchStartedNotification::class);
        Notification::assertSentTo($teammateA, TeamMatchStartedNotification::class);
        Notification::assertSentTo($oppB1, TeamMatchStartedNotification::class);
        Notification::assertSentTo($oppB2, TeamMatchStartedNotification::class);
    });

    it('does not fan out on a non-final Ready click', function () {
        $listing = newTeamPlay(teamSize: 2);
        $creator = $listing->user;
        $teammateA = newFundedPlayer();
        $oppB1 = newFundedPlayer();
        $oppB2 = newFundedPlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        Notification::fake();

        // Only three Ready clicks — lobby stays in ready_checking, no lock.
        app(ToggleReadyAction::class)->handle($creator, $listing);
        app(ToggleReadyAction::class)->handle($teammateA, $listing);
        app(ToggleReadyAction::class)->handle($oppB1, $listing);

        Notification::assertNothingSent();
    });
});
