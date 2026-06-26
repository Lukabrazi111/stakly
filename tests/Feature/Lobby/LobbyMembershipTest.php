<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\KickParticipantAction;
use App\Actions\Lobby\LeaveLobbyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P1 — JoinLobbyAction + LeaveLobbyAction + KickParticipantAction.
 */

function freshLobby(int $teamSize = 5, string $side = LobbyParticipant::SIDE_A): Listing
{
    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:lobby-create:{$creator->id}");

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
        'team_size' => $teamSize,
        'creator_side' => $side,
        'is_public' => true,
    ]);
}

function freshJoiner(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:joiner-deposit:{$user->id}");

    return $user;
}

describe('JoinLobbyAction', function () {
    it('soft-joins a user to the next open slot on the requested side', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
        expect($result->side)->toBe(LobbyParticipant::SIDE_B);
        expect($result->slot_index)->toBe(0);
        expect($result->is_ready)->toBeFalse();
        expect($result->stake_held_at)->toBeNull();
    });

    it('rejects with "not_team_play" for a 1v1 chess listing', function () {
        $listing = Listing::factory()->create();
        $joiner = freshJoiner();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_A);

        expect($result)->toBe('not_team_play');
    });

    it('rejects with "not_linked" when joiner has no FACEIT account', function () {
        $listing = freshLobby();
        $joiner = User::factory()->active()->create(); // no FACEIT

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBe('not_linked');
    });

    it('rejects with "already_in_lobby" when joiner is in another lobby', function () {
        $first = freshLobby();
        $joiner = freshJoiner();

        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);

        $second = freshLobby();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBe('already_in_lobby');
    });

    /*
     * M34 P7 follow-up — the single-lobby rule used to lock users out of new
     * lobbies forever once a match Settled / ManualReview'd / Cancelled,
     * because `lobby_state` stays `locked` after terminal match transitions.
     * `activeLobbyParticipation` now treats terminal match statuses as
     * released; pinning the three states + the still-active `Disputed` case.
     */
    it('allows joining a new lobby after the previous match settled', function () {
        $first = freshLobby(teamSize: 2);
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);
        $first->update(['lobby_state' => 'locked']);
        $first->gameMatch->update(['status' => MatchStatus::Settled]);

        $second = freshLobby(teamSize: 2);

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
    });

    it('allows joining a new lobby after the previous match was cancelled', function () {
        $first = freshLobby(teamSize: 2);
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);
        $first->update(['lobby_state' => 'locked']);
        $first->gameMatch->update(['status' => MatchStatus::Cancelled]);

        $second = freshLobby(teamSize: 2);

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
    });

    it('allows joining a new lobby while a previous match is in ManualReview', function () {
        $first = freshLobby(teamSize: 2);
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);
        $first->update(['lobby_state' => 'locked']);
        $first->gameMatch->update(['status' => MatchStatus::ManualReview]);

        $second = freshLobby(teamSize: 2);

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
    });

    it('still blocks while the previous match is Pending (locked, in 4h window)', function () {
        $first = freshLobby(teamSize: 2);
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);
        $first->update(['lobby_state' => 'locked']);
        $first->gameMatch->update(['status' => MatchStatus::Pending]);

        $second = freshLobby(teamSize: 2);

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBe('already_in_lobby');
    });

    it('still blocks while the previous match is Disputed', function () {
        $first = freshLobby(teamSize: 2);
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $first, LobbyParticipant::SIDE_B);
        $first->update(['lobby_state' => 'locked']);
        $first->gameMatch->update(['status' => MatchStatus::Disputed]);

        $second = freshLobby(teamSize: 2);

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $second, LobbyParticipant::SIDE_A);

        expect($result)->toBe('already_in_lobby');
    });

    it('rejects with "kick_cooldown" when joiner was kicked within 5 min', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();

        LobbyParticipant::factory()->for($listing)->for($joiner)->state([
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => 0,
            'kicked_at' => now()->subMinutes(2),
        ])->create();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBe('kick_cooldown');
    });

    it('allows rejoin after the 5-min cooldown expires', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();

        LobbyParticipant::factory()->for($listing)->for($joiner)->state([
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => 0,
            'kicked_at' => now()->subMinutes(10),
        ])->create();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
    });

    it('rejects with "skill_out_of_range" when rating sits below skill_min', function () {
        $creator = User::factory()->active()->withFaceit()->create();
        $listing = Listing::factory()->teamPlay()->for($creator)->state([
            'skill_min' => 2000,
            'skill_max' => 3000,
        ])->create();

        $joiner = User::factory()->active()->withFaceit(null, null, 1500)->create();

        $result = app(JoinLobbyAction::class)
            ->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBe('skill_out_of_range');
    });

    it('promotes lobby_state to ready_checking when max soft-joined is reached', function () {
        // Wingman 2v2 → max 4 participants. Creator (auto-joined slot 0 side A)
        // + 1 more on side A + 2 on side B = 4 = team_size × 2 → transition.
        $listing = freshLobby(teamSize: 2);

        app(JoinLobbyAction::class)
            ->handle(freshJoiner(), $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)
            ->handle(freshJoiner(), $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)
            ->handle(freshJoiner(), $listing, LobbyParticipant::SIDE_B);

        $listing->refresh();
        expect($listing->lobby_state)->toBe('ready_checking');
        expect($listing->lobby_ready_check_deadline)->not->toBeNull();
        expect((int) abs($listing->lobby_ready_check_deadline->diffInMinutes(now())))
            ->toBeGreaterThanOrEqual(4)
            ->toBeLessThanOrEqual(5);
    });
});

describe('LeaveLobbyAction', function () {
    it('vacates a non-creator participant and returns "left"', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $result = app(LeaveLobbyAction::class)->handle($joiner, $listing);

        expect($result)->toBe('left');
        expect(LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->live()
            ->exists())->toBeFalse();
    });

    it('refunds a Ready non-creator on leave', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);
        Wallet::hold(
            user: $joiner,
            amount: '100',
            listing: $listing,
            description: 'Seed Ready hold.',
        );
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->update(['is_ready' => true, 'stake_held_at' => now()]);

        $balanceBefore = Wallet::balanceFor($joiner);

        app(LeaveLobbyAction::class)->handle($joiner, $listing);

        expect(Wallet::balanceFor($joiner))->toBe(bcadd($balanceBefore, '100', 6));
    });

    it('cancels the whole lobby when the creator leaves', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $result = app(LeaveLobbyAction::class)->handle($listing->user, $listing);

        expect($result)->toBe('creator_cancelled');

        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Cancelled);
        expect($listing->lobby_state)->toBe('cancelled');
        expect($listing->gameMatch->fresh()->status)->toBe(MatchStatus::Cancelled);
        expect(LobbyParticipant::query()->where('listing_id', $listing->id)->live()->count())->toBe(0);
    });

    it('refunds Ready participants when creator cancels', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);
        Wallet::hold(user: $joiner, amount: '100', listing: $listing, description: 'Seed Ready hold.');
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->update(['is_ready' => true, 'stake_held_at' => now()]);

        $balanceBefore = Wallet::balanceFor($joiner);

        app(LeaveLobbyAction::class)->handle($listing->user, $listing);

        expect(Wallet::balanceFor($joiner))->toBe(bcadd($balanceBefore, '100', 6));
    });

    it('rejects "not_in_lobby" when user has no live participant row', function () {
        $listing = freshLobby();
        $stranger = freshJoiner();

        $result = app(LeaveLobbyAction::class)->handle($stranger, $listing);

        expect($result)->toBe('not_in_lobby');
    });
});

describe('KickParticipantAction', function () {
    it('preserves the kicked row with kicked_at set (cooldown anchor)', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $result = app(KickParticipantAction::class)
            ->handle($listing->user, $listing, $joiner);

        expect($result)->toBe('kicked');

        $row = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->first();

        expect($row)->not->toBeNull();
        expect($row->kicked_at)->not->toBeNull();
        expect($row->is_ready)->toBeFalse();
    });

    it('refunds a Ready target on kick', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);
        Wallet::hold(user: $joiner, amount: '100', listing: $listing, description: 'Seed Ready hold.');
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->update(['is_ready' => true, 'stake_held_at' => now()]);

        $balanceBefore = Wallet::balanceFor($joiner);

        app(KickParticipantAction::class)->handle($listing->user, $listing, $joiner);

        expect(Wallet::balanceFor($joiner))->toBe(bcadd($balanceBefore, '100', 6));
    });

    it('rejects "not_owner" when a non-owner tries to kick', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $stranger = freshJoiner();

        $result = app(KickParticipantAction::class)
            ->handle($stranger, $listing, $joiner);

        expect($result)->toBe('not_owner');
    });

    it('rejects "cant_kick_self" when the owner targets themselves', function () {
        $listing = freshLobby();

        $result = app(KickParticipantAction::class)
            ->handle($listing->user, $listing, $listing->user);

        expect($result)->toBe('cant_kick_self');
    });

    it('frees the slot so a new joiner can claim it', function () {
        $listing = freshLobby();
        $joiner = freshJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        app(KickParticipantAction::class)->handle($listing->user, $listing, $joiner);

        $newJoiner = freshJoiner();
        $result = app(JoinLobbyAction::class)
            ->handle($newJoiner, $listing, LobbyParticipant::SIDE_B);

        expect($result)->toBeInstanceOf(LobbyParticipant::class);
        expect($result->slot_index)->toBe(0); // same slot the kicked user had
    });
});
