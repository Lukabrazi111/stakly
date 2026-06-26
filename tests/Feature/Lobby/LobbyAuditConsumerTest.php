<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Broadcasting\MatchChannel;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P1 — audit of existing `Pending`-assuming consumers, extended to
 * recognise the new `LobbyFilling` status:
 *   - GameMatchPolicy::view
 *   - MatchChannel::join
 *   - User::usernameChangeBlockers
 *   - GameMatchController::show redirect
 */

function auditLobby(): Listing
{
    platformUser();
    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:audit:{$creator->id}");

    return app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => null,
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);
}

describe('GameMatchPolicy::view for LobbyFilling matches', function () {
    it('allows the listing creator to view a LobbyFilling match', function () {
        $listing = auditLobby();

        expect($listing->user->can('view', $listing->gameMatch))->toBeTrue();
    });

    it('allows a soft-joined lobby participant to view (chat access)', function () {
        $listing = auditLobby();
        $joiner = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($joiner, '500', reference: "test:audit-joiner:{$joiner->id}");

        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($joiner->can('view', $listing->gameMatch))->toBeTrue();
    });

    it('rejects a non-participant from viewing', function () {
        $listing = auditLobby();
        $stranger = User::factory()->active()->create();

        expect($stranger->can('view', $listing->gameMatch))->toBeFalse();
    });

    it('rejects a kicked participant from viewing', function () {
        $listing = auditLobby();
        $kicked = User::factory()->active()->withFaceit()->create();
        LobbyParticipant::factory()->for($listing)->for($kicked)->state([
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => 0,
            'kicked_at' => now()->subSecond(),
        ])->create();

        expect($kicked->can('view', $listing->gameMatch))->toBeFalse();
    });
});

describe('MatchChannel::join for LobbyFilling matches', function () {
    it('lets a soft-joined participant subscribe', function () {
        $listing = auditLobby();
        $joiner = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($joiner, '500', reference: "test:audit-ch:{$joiner->id}");

        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $channel = app(MatchChannel::class);
        expect($channel->join($joiner, $listing->gameMatch->id))->toBeTrue();
    });

    it('blocks a non-participant from subscribing', function () {
        $listing = auditLobby();
        $stranger = User::factory()->active()->create();

        $channel = app(MatchChannel::class);
        expect($channel->join($stranger, $listing->gameMatch->id))->toBeFalse();
    });
});

describe('User::usernameChangeBlockers — LobbyFilling counts as in-flight', function () {
    it('blocks the creator from renaming during LobbyFilling', function () {
        $listing = auditLobby();

        expect($listing->user->usernameChangeBlockers())
            ->toContain('in_flight_match');
    });

    it('blocks a non-creator lobby participant from renaming', function () {
        $listing = auditLobby();
        $joiner = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($joiner, '500', reference: "test:audit-name:{$joiner->id}");

        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($joiner->usernameChangeBlockers())
            ->toContain('in_flight_match');
    });
});

describe('GameMatchController::show redirect for LobbyFilling', function () {
    it('redirects LobbyFilling matches to /listings/{id}', function () {
        $listing = auditLobby();

        $response = $this->actingAs($listing->user)
            ->get(route('matches.show', ['match' => $listing->gameMatch]));

        $response->assertRedirect(route('listings.show', ['listing' => $listing]));
    });
});
