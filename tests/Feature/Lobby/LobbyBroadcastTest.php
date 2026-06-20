<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\KickParticipantAction;
use App\Actions\Lobby\LeaveLobbyAction;
use App\Actions\Lobby\LobbyFillTimeoutAction;
use App\Actions\Lobby\LobbyReadyCheckTimeoutAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Broadcasting\LobbyChannel;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\MatchStatus;
use App\Events\LobbyUpdated;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/*
 * M34 P3.2 — LobbyChannel auth + LobbyUpdated dispatch from every
 * roster/state-mutating lobby action. The frontend listens on
 * `private-lobby.{id}` and reloads the `lobby` prop on `.lobby.updated`;
 * these tests pin both the auth gate AND the dispatch boundary so a future
 * refactor can't silently drop a broadcast site.
 */

function broadcastLobby(int $teamSize = 2): Listing
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:bc-create:{$creator->id}");

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
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);
}

function broadcastPlayer(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:bc-fund:{$user->id}");

    return $user;
}

describe('LobbyChannel::join', function () {
    it('lets any authenticated user subscribe to a team-play lobby', function () {
        $listing = broadcastLobby();
        $stranger = User::factory()->active()->create();

        $channel = app(LobbyChannel::class);

        expect($channel->join($stranger, $listing))->toBeTrue();
        expect($channel->join($listing->user, $listing))->toBeTrue();
    });

    it('refuses subscriptions to non-team-play listings', function () {
        platformUser();

        $creator = User::factory()->active()->create();
        $listing = Listing::factory()->open()->for($creator)->create([
            'team_size' => 1,
        ]);

        $channel = app(LobbyChannel::class);

        expect($channel->join($creator, $listing))->toBeFalse();
    });
});

describe('LobbyUpdated event shape', function () {
    it('broadcasts on the private-lobby.{id} channel as lobby.updated', function () {
        $listing = broadcastLobby();
        $event = new LobbyUpdated($listing);

        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
        expect($channels[0]->name)->toBe("private-lobby.{$listing->id}");
        expect($event->broadcastAs())->toBe('lobby.updated');
        expect($event->broadcastWith())->toBe(['listing_id' => $listing->id]);
    });
});

describe('JoinLobbyAction', function () {
    it('dispatches LobbyUpdated on successful soft-join', function () {
        $listing = broadcastLobby();
        $joiner = broadcastPlayer();

        Event::fake([LobbyUpdated::class]);

        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        Event::assertDispatched(
            LobbyUpdated::class,
            fn (LobbyUpdated $e) => $e->listing->id === $listing->id,
        );
    });

    it('does not dispatch when the join is rejected', function () {
        $listing = broadcastLobby();
        // No FACEIT link → 'not_linked' sentinel.
        $unlinked = User::factory()->active()->create();

        Event::fake([LobbyUpdated::class]);

        $result = app(JoinLobbyAction::class)
            ->handle($unlinked, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBe('not_linked');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

describe('LeaveLobbyAction', function () {
    it('dispatches LobbyUpdated when a non-creator leaves', function () {
        $listing = broadcastLobby();
        $joiner = broadcastPlayer();
        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        Event::fake([LobbyUpdated::class]);

        $result = app(LeaveLobbyAction::class)->handle($joiner, $listing);

        expect($result)->toBe('left');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('dispatches LobbyUpdated when the creator cancels the lobby', function () {
        $listing = broadcastLobby();

        Event::fake([LobbyUpdated::class]);

        $result = app(LeaveLobbyAction::class)->handle($listing->user, $listing);

        expect($result)->toBe('creator_cancelled');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('does not dispatch when the user is not in the lobby', function () {
        $listing = broadcastLobby();
        $stranger = broadcastPlayer();

        Event::fake([LobbyUpdated::class]);

        $result = app(LeaveLobbyAction::class)->handle($stranger, $listing);

        expect($result)->toBe('not_in_lobby');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

describe('ToggleReadyAction', function () {
    it('dispatches LobbyUpdated on Ready', function () {
        $listing = broadcastLobby();

        Event::fake([LobbyUpdated::class]);

        $result = app(ToggleReadyAction::class)->handle($listing->user, $listing);

        expect($result)->toBe('readied');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('dispatches LobbyUpdated on un-Ready', function () {
        $listing = broadcastLobby();
        app(ToggleReadyAction::class)->handle($listing->user, $listing);

        Event::fake([LobbyUpdated::class]);

        $result = app(ToggleReadyAction::class)->handle($listing->user, $listing);

        expect($result)->toBe('unreadied');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('dispatches LobbyUpdated when the final Ready locks the lobby', function () {
        // Wingman 2v2: fill all 4 slots, ready all of them — last Ready
        // triggers LobbyLockAction.
        $listing = broadcastLobby(teamSize: 2);
        $teammateA = broadcastPlayer();
        $oppB1 = broadcastPlayer();
        $oppB2 = broadcastPlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        app(ToggleReadyAction::class)->handle($listing->user, $listing);
        app(ToggleReadyAction::class)->handle($teammateA, $listing);
        app(ToggleReadyAction::class)->handle($oppB1, $listing);

        Event::fake([LobbyUpdated::class]);

        $result = app(ToggleReadyAction::class)->handle($oppB2, $listing);

        expect($result)->toBe('locked_now');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('does not dispatch when the user is not in the lobby', function () {
        $listing = broadcastLobby();
        $stranger = broadcastPlayer();

        Event::fake([LobbyUpdated::class]);

        $result = app(ToggleReadyAction::class)->handle($stranger, $listing);

        expect($result)->toBe('not_in_lobby');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

describe('KickParticipantAction', function () {
    it('dispatches LobbyUpdated on a successful kick', function () {
        $listing = broadcastLobby();
        $joiner = broadcastPlayer();
        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        Event::fake([LobbyUpdated::class]);

        $result = app(KickParticipantAction::class)
            ->handle($listing->user, $listing, $joiner);

        expect($result)->toBe('kicked');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('does not dispatch when a non-owner attempts a kick', function () {
        $listing = broadcastLobby();
        $joiner = broadcastPlayer();
        app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        Event::fake([LobbyUpdated::class]);

        $result = app(KickParticipantAction::class)
            ->handle($joiner, $listing, $listing->user);

        expect($result)->toBe('not_owner');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

describe('LobbyReadyCheckTimeoutAction', function () {
    it('dispatches LobbyUpdated when the lobby reverts to recruiting', function () {
        $listing = broadcastLobby(teamSize: 2);
        $teammateA = broadcastPlayer();
        $oppB1 = broadcastPlayer();
        $oppB2 = broadcastPlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        app(ToggleReadyAction::class)->handle($listing->user, $listing);

        $listing->refresh();
        $listing->update(['lobby_ready_check_deadline' => now()->subSecond()]);

        Event::fake([LobbyUpdated::class]);

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing);

        expect($result)->toBe('reverted');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('does not dispatch on noop (deadline still in the future)', function () {
        $listing = broadcastLobby(teamSize: 2);
        $teammateA = broadcastPlayer();
        $oppB1 = broadcastPlayer();
        $oppB2 = broadcastPlayer();

        app(JoinLobbyAction::class)->handle($teammateA, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);
        // Lobby is now `ready_checking` with a future deadline.

        Event::fake([LobbyUpdated::class]);

        $result = app(LobbyReadyCheckTimeoutAction::class)->handle($listing->fresh());

        expect($result)->toBe('noop');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

describe('LobbyFillTimeoutAction', function () {
    it('dispatches LobbyUpdated when the listing expires', function () {
        $listing = broadcastLobby();

        Event::fake([LobbyUpdated::class]);

        $result = app(LobbyFillTimeoutAction::class)->handle($listing);

        expect($result)->toBe('cancelled');
        Event::assertDispatched(LobbyUpdated::class);
    });

    it('does not dispatch on noop (1v1 listing)', function () {
        platformUser();

        $creator = User::factory()->active()->create();
        $listing = Listing::factory()->open()->for($creator)->create([
            'team_size' => 1,
        ]);

        Event::fake([LobbyUpdated::class]);

        $result = app(LobbyFillTimeoutAction::class)->handle($listing);

        expect($result)->toBe('noop');
        Event::assertNotDispatched(LobbyUpdated::class);
    });
});

/*
 * M34 P7 follow-up — match-status changes on a team-play lobby fire
 * LobbyUpdated via `GameMatchObserver`, so the lobby page's Coord-pulse
 * color + chat read-only gate + header countdown refresh live without a
 * manual reload. 1v1 (chess) matches skip the broadcast — no lobby
 * channel listening.
 */
describe('GameMatchObserver', function () {
    it('dispatches LobbyUpdated when a team-play match status changes', function () {
        $listing = broadcastLobby();
        $match = $listing->gameMatch;
        $match->update(['status' => MatchStatus::Pending]);

        Event::fake([LobbyUpdated::class]);

        $match->update(['status' => MatchStatus::Settled]);

        Event::assertDispatched(
            LobbyUpdated::class,
            fn (LobbyUpdated $event) => $event->listing->is($listing),
        );
    });

    it('does not dispatch when only non-status fields change', function () {
        $listing = broadcastLobby();
        $match = $listing->gameMatch;
        $match->update(['status' => MatchStatus::Pending]);

        Event::fake([LobbyUpdated::class]);

        $match->update(['winner_user_id' => $listing->user_id]);

        Event::assertNotDispatched(LobbyUpdated::class);
    });

    it('does not dispatch for 1v1 (chess) match status changes', function () {
        $listing = Listing::factory()->open()->create(); // team_size = 1
        $match = GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => User::factory()->active()->create()->id,
            'status' => MatchStatus::Pending,
        ]);

        Event::fake([LobbyUpdated::class]);

        $match->update(['status' => MatchStatus::Settled]);

        Event::assertNotDispatched(LobbyUpdated::class);
    });
});
