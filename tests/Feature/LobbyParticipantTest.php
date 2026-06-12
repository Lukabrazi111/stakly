<?php

use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\MatchProviderSnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
 * M34 Phase 0 — schema + model surface for team play + lobbies.
 *
 * Covers:
 *   - MatchStatus::LobbyFilling case present (pure additive enum).
 *   - Listing helpers (`isTeamPlay`, `lobbyParticipants`, `lobbyOwner`).
 *   - LobbyParticipant model relations, casts, factory states, scopes.
 *   - Partial unique indexes (`WHERE kicked_at IS NULL`) on
 *     (listing_id, side, slot_index) and (listing_id, user_id).
 */

it('exposes LobbyFilling as a MatchStatus case', function () {
    expect(MatchStatus::LobbyFilling->value)->toBe('lobby_filling');
    expect(MatchStatus::tryFrom('lobby_filling'))->toBe(MatchStatus::LobbyFilling);
});

describe('Listing team-play helpers', function () {
    it('isTeamPlay returns true when team_size > 1', function () {
        $listing = Listing::factory()->teamPlay()->create();

        expect($listing->isTeamPlay())->toBeTrue();
        expect($listing->team_size)->toBe(5);
    });

    it('isTeamPlay returns false for default 1v1 listings', function () {
        $listing = Listing::factory()->create();

        expect($listing->isTeamPlay())->toBeFalse();
        expect($listing->team_size)->toBe(1);
    });

    it('hydrates the lobbyParticipants hasMany relation', function () {
        $listing = Listing::factory()->teamPlay()->create();

        foreach (range(0, 2) as $slot) {
            LobbyParticipant::factory()->for($listing)->state([
                'side' => LobbyParticipant::SIDE_A,
                'slot_index' => $slot,
            ])->create();
        }

        expect($listing->lobbyParticipants)->toHaveCount(3);
        expect($listing->lobbyParticipants->first())->toBeInstanceOf(LobbyParticipant::class);
    });

    it('lobbyOwner accessor returns the listing creator', function () {
        $creator = User::factory()->create();
        $listing = Listing::factory()->teamPlay()->for($creator)->create();

        expect($listing->lobbyOwner)->not->toBeNull();
        expect($listing->lobbyOwner->id)->toBe($creator->id);
    });

    it('casts lobby + invite columns', function () {
        $listing = Listing::factory()->teamPlay()->private()->create();

        expect($listing->is_public)->toBeFalse();
        expect($listing->invite_token)->toBeString();
        expect(strlen($listing->invite_token))->toBe(32);
        expect($listing->lobby_state)->toBe('recruiting');
    });
});

describe('LobbyParticipant factory states', function () {
    it('default is a soft-joined participant on side A slot 0', function () {
        $participant = LobbyParticipant::factory()->create();

        expect($participant->side)->toBe(LobbyParticipant::SIDE_A);
        expect($participant->slot_index)->toBe(0);
        expect($participant->is_ready)->toBeFalse();
        expect($participant->stake_held_at)->toBeNull();
        expect($participant->kicked_at)->toBeNull();
    });

    it('ready() sets is_ready true and stamps stake_held_at', function () {
        $participant = LobbyParticipant::factory()->ready()->create();

        expect($participant->is_ready)->toBeTrue();
        expect($participant->stake_held_at)->not->toBeNull();
    });

    it('kicked() stamps kicked_at and clears Ready state', function () {
        $participant = LobbyParticipant::factory()->ready()->kicked()->create();

        expect($participant->kicked_at)->not->toBeNull();
        expect($participant->is_ready)->toBeFalse();
        expect($participant->stake_held_at)->toBeNull();
    });
});

describe('LobbyParticipant scopes', function () {
    it('live() excludes kicked rows', function () {
        $listing = Listing::factory()->teamPlay()->create();
        LobbyParticipant::factory()->for($listing)->state(['slot_index' => 0])->create();
        LobbyParticipant::factory()->for($listing)->state(['slot_index' => 1])->kicked()->create();

        expect(LobbyParticipant::query()->live()->count())->toBe(1);
    });

    it('withinKickCooldown returns kicked rows newer than 5 minutes', function () {
        $listing = Listing::factory()->teamPlay()->create();

        LobbyParticipant::factory()->for($listing)->state([
            'slot_index' => 0,
            'kicked_at' => now()->subMinutes(2),
        ])->create();

        LobbyParticipant::factory()->for($listing)->state([
            'slot_index' => 1,
            'kicked_at' => now()->subMinutes(10),
        ])->create();

        expect(LobbyParticipant::query()->withinKickCooldown()->count())->toBe(1);
    });
});

describe('partial unique indexes on lobby_participants', function () {
    it('blocks two live participants from claiming the same slot', function () {
        $listing = Listing::factory()->teamPlay()->create();

        LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->create();

        expect(fn () => LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->create())->toThrow(QueryException::class);
    });

    it('blocks the same user from having two live rows on one listing', function () {
        $listing = Listing::factory()->teamPlay()->create();
        $user = User::factory()->create();

        LobbyParticipant::factory()->for($listing)->for($user)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->create();

        expect(fn () => LobbyParticipant::factory()->for($listing)->for($user)->state([
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => 0,
        ])->create())->toThrow(QueryException::class);
    });

    it('allows a new live row on a slot previously held by a kicked row', function () {
        $listing = Listing::factory()->teamPlay()->create();

        LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->kicked()->create();

        $fresh = LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->create();

        expect($fresh->kicked_at)->toBeNull();
        expect(LobbyParticipant::query()->live()->where('listing_id', $listing->id)->count())->toBe(1);
    });

    it('allows a kicked user to create a fresh live row on the same listing', function () {
        $listing = Listing::factory()->teamPlay()->create();
        $user = User::factory()->create();

        LobbyParticipant::factory()->for($listing)->for($user)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->kicked()->create();

        $fresh = LobbyParticipant::factory()->for($listing)->for($user)->state([
            'side' => LobbyParticipant::SIDE_B,
            'slot_index' => 0,
        ])->create();

        expect($fresh->kicked_at)->toBeNull();
        expect($fresh->user_id)->toBe($user->id);
    });

    it('allows multiple kicked rows on the same slot (partial index is exempt)', function () {
        $listing = Listing::factory()->teamPlay()->create();

        LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->kicked()->create();

        LobbyParticipant::factory()->for($listing)->state([
            'side' => LobbyParticipant::SIDE_A,
            'slot_index' => 0,
        ])->kicked()->create();

        expect(LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->whereNotNull('kicked_at')
            ->count())->toBe(2);
    });
});

describe('match_provider_snapshots.slot_index', function () {
    it('accepts a slot_index value alongside the existing columns', function () {
        $snapshot = MatchProviderSnapshot::factory()->create([
            'slot_index' => 3,
        ]);

        expect($snapshot->slot_index)->toBe(3);
    });

    it('defaults to 0 when not supplied (1v1 chess back-compat)', function () {
        $snapshot = MatchProviderSnapshot::factory()->create();

        expect($snapshot->fresh()->slot_index)->toBe(0);
    });
});
