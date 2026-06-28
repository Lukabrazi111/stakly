<?php

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P2 — invite links + StoreListingRequest extensions + marketplace
 * filter + LobbyController routing.
 */

function teamPlayPayload(array $overrides = []): array
{
    return array_merge([
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => null,
        'duration_hours' => 24,
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ], $overrides);
}

function chessPayload(array $overrides = []): array
{
    return array_merge([
        'game' => Game::Chess->value,
        'platform' => LinkedAccountProvider::ChessCom->value,
        'stake_amount' => '50',
        'time_control' => 'blitz',
        'duration_hours' => 24,
    ], $overrides);
}

describe('StoreListingRequest team-play validation', function () {
    it('accepts a valid team-play payload', function () {
        platformUser();
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-valid:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), teamPlayPayload())
            ->assertSessionHasNoErrors();
    });

    it('rejects team_size > 1 without creator_side', function () {
        platformUser();
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-noside:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), teamPlayPayload(['creator_side' => null]))
            ->assertSessionHasErrors('creator_side');
    });

    it('rejects an invalid creator_side', function () {
        platformUser();
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-badside:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), teamPlayPayload(['creator_side' => 'c']))
            ->assertSessionHasErrors('creator_side');
    });

    it('rejects team_size 5 for chess', function () {
        platformUser();
        $user = User::factory()->active()->withChessCom()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-chess5:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), chessPayload([
                'team_size' => 5,
                'creator_side' => LobbyParticipant::SIDE_A,
            ]))
            ->assertSessionHasErrors('team_size');
    });

    it('defaults team_size to 1 when missing (existing chess flow)', function () {
        platformUser();
        $user = User::factory()->active()->withChessCom()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-default:{$user->id}");

        $payload = chessPayload();
        unset($payload['team_size']);

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), $payload)
            ->assertSessionHasNoErrors();

        $listing = Listing::query()->where('user_id', $user->id)->first();
        expect($listing->team_size)->toBe(1);
        expect($listing->is_public)->toBeTrue();
    });
});

describe('ListingController::store branching', function () {
    it('routes team_size > 1 to the team-play action (creates LobbyFilling match)', function () {
        platformUser();
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-branch:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), teamPlayPayload())
            ->assertRedirect();

        $listing = Listing::query()->where('user_id', $user->id)->first();
        expect($listing)->not->toBeNull();
        expect($listing->team_size)->toBe(5);
        expect($listing->lobby_state)->toBe('recruiting');
        expect($listing->gameMatch)->not->toBeNull();

        // No escrow at creation (the team-play action defers stake to Ready click)
        expect(Wallet::balanceFor($user))->toBe('1000.000000');
    });

    it('routes team_size = 1 to the existing chess action (escrows stake)', function () {
        platformUser();
        $user = User::factory()->active()->withChessCom()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-chess:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), chessPayload())
            ->assertRedirect();

        $listing = Listing::query()->where('user_id', $user->id)->first();
        expect($listing->team_size)->toBe(1);
        expect($listing->lobby_state)->toBeNull();

        // Chess flow escrows at creation
        expect(Wallet::balanceFor($user))->toBe('950.000000');
    });

    it('generates a 32-char invite_token for private team-play listings', function () {
        platformUser();
        $user = User::factory()->active()->withFaceit()->create();
        Wallet::deposit($user, '1000', reference: "test:p2-priv:{$user->id}");

        $this->actingAs($user)
            ->post(route('listings.store', ['locale' => 'en']), teamPlayPayload(['is_public' => false]));

        $listing = Listing::query()->where('user_id', $user->id)->first();
        expect($listing->is_public)->toBeFalse();
        expect($listing->invite_token)->toBeString();
        expect(strlen($listing->invite_token))->toBe(32);
    });
});

describe('marketplace hides private listings', function () {
    it('public listings appear in /listings', function () {
        $public = Listing::factory()->teamPlay()->create([
            'status' => ListingStatus::Open,
            'is_public' => true,
        ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('listings/index')
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->contains(fn ($l) => $l['id'] === $public->id)),
            );
    });

    it('private listings do NOT appear in /listings', function () {
        $private = Listing::factory()->teamPlay()->private()->create([
            'status' => ListingStatus::Open,
        ]);

        $this->get(route('listings.index', ['locale' => 'en']).'?filter[game]=cs2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('listings.data', fn ($listings) => collect($listings)
                    ->doesntContain(fn ($l) => $l['id'] === $private->id)),
            );
    });

    it('private listings are NOT reachable via a guessed direct /listings/{id} URL (M34 P5)', function () {
        $private = Listing::factory()->teamPlay()->private()->create([
            'status' => ListingStatus::Open,
        ]);

        // A non-invited visitor (no creator / participant / invite pass) 404s,
        // so enumerating sequential ids reveals nothing.
        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $private]))
            ->assertNotFound();
    });
});

describe('LobbyController::showByToken — /lobbies/{token}', function () {
    it('302-redirects a valid token to the canonical /listings/{id} URL', function () {
        $user = User::factory()->active()->withFaceit()->create();
        $listing = Listing::factory()
            ->teamPlay()
            ->private()
            ->for($user)
            ->create(['status' => ListingStatus::Open]);
        GameMatch::factory()->create([
            'listing_id' => $listing->id,
            'taker_user_id' => $user->id,
            'status' => MatchStatus::LobbyFilling,
        ]);

        $visitor = User::factory()->active()->create();

        $this->actingAs($visitor)
            ->get(route('lobbies.invite', ['locale' => 'en', 'token' => $listing->invite_token]))
            ->assertStatus(302)
            ->assertRedirect(route('listings.show', ['locale' => 'en', 'listing' => $listing]));
    });

    it('404s when token does not exist', function () {
        $visitor = User::factory()->active()->create();

        $this->actingAs($visitor)
            ->get(route('lobbies.invite', ['locale' => 'en', 'token' => str_repeat('a', 32)]))
            ->assertNotFound();
    });

    it('404s when listing is cancelled', function () {
        $listing = Listing::factory()->teamPlay()->private()->create([
            'status' => ListingStatus::Cancelled,
            'lobby_state' => 'cancelled',
        ]);

        $visitor = User::factory()->active()->create();

        $this->actingAs($visitor)
            ->get(route('lobbies.invite', ['locale' => 'en', 'token' => $listing->invite_token]))
            ->assertNotFound();
    });

    it('404s when lobby_state is locked', function () {
        $listing = Listing::factory()->teamPlay()->private()->create([
            'status' => ListingStatus::Taken,
            'lobby_state' => 'locked',
        ]);

        $visitor = User::factory()->active()->create();

        $this->actingAs($visitor)
            ->get(route('lobbies.invite', ['locale' => 'en', 'token' => $listing->invite_token]))
            ->assertNotFound();
    });

    it('404s on malformed token (wrong length / chars)', function () {
        $visitor = User::factory()->active()->create();

        $this->actingAs($visitor)
            ->get(route('lobbies.invite', ['locale' => 'en', 'token' => 'too-short']))
            ->assertNotFound();
    });
});
