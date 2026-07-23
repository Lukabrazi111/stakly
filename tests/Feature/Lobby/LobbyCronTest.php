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
use App\Models\User;
use App\Services\Wallet;
use Carbon\Carbon;

/*
 * M34 P1 — cron command wiring smoke tests.
 *
 * Per-action behavior is covered in LobbyLifecycleTest; these just check
 * that the artisan commands target the right listings and call the right
 * actions.
 */

function cronLobby(int $teamSize = 2): Listing
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:cron-create:{$creator->id}");

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

it('lobbies:sweep-ready-check-timeouts fires the action on past-deadline listings', function () {
    $listing = cronLobby();
    $teammateA = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($teammateA, '500', reference: "test:cron-fund:{$teammateA->id}");
    $oppB1 = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($oppB1, '500', reference: "test:cron-fund-b1:{$oppB1->id}");
    $oppB2 = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($oppB2, '500', reference: "test:cron-fund-b2:{$oppB2->id}");

    app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

    // Creator only Ready'd
    app(ToggleReadyAction::class)->handle($listing->user, $listing);

    $listing->refresh();
    $listing->update(['lobby_ready_check_deadline' => now()->subMinute()]);

    $this->artisan('lobbies:sweep-ready-check-timeouts')
        ->expectsOutputToContain('Reverted 1')
        ->assertExitCode(0);

    $listing->refresh();
    expect($listing->lobby_state)->toBe('recruiting');
});

it('lobbies:sweep-fill-timeouts cancels 24h-old recruiting listings', function () {
    Carbon::setTestNow(now()->subDays(2));
    $listing = cronLobby();
    Carbon::setTestNow();

    $this->artisan('lobbies:sweep-fill-timeouts')
        ->expectsOutputToContain('Cancelled 1')
        ->assertExitCode(0);

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Expired);
    expect($listing->gameMatch->fresh()->status)->toBe(MatchStatus::Cancelled);
});

it('lobbies:sweep-fill-timeouts skips listings within 24h', function () {
    $listing = cronLobby();

    $this->artisan('lobbies:sweep-fill-timeouts')
        ->expectsOutputToContain('Cancelled 0')
        ->assertExitCode(0);

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Open);
});
