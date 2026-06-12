<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P3 — lobby page rendering, policy gates, and the HTTP endpoints
 * (join / leave / ready / kick).
 */

function pageLobby(int $teamSize = 5, bool $isPublic = true): Listing
{
    platformUser();

    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '10000', reference: "test:page-create:{$creator->id}");

    return app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => [],
        'duration_hours' => 24,
        'team_size' => $teamSize,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => $isPublic,
    ]);
}

function pageJoiner(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:page-fund:{$user->id}");

    return $user;
}

describe('GET /lobbies/{listing}', function () {
    it('renders the lobby page for public listings to any visitor', function () {
        $listing = pageLobby();

        $this->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lobby/show')
                ->where('lobby.id', $listing->id)
                ->where('lobby.team_size', 5)
                ->where('lobby.lobby_state', 'recruiting'),
            );
    });

    it('renders the page for a live participant on a private listing', function () {
        $listing = pageLobby(isPublic: false);

        $this->actingAs($listing->user)
            ->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk();
    });

    it('404s a stranger on a private listing', function () {
        $listing = pageLobby(isPublic: false);
        $stranger = User::factory()->active()->create();

        $this->actingAs($stranger)
            ->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertNotFound();
    });

    it('redirects locked / cancelled / expired lobbies to the listing detail', function () {
        $listing = pageLobby();
        $listing->update(['lobby_state' => 'cancelled', 'status' => ListingStatus::Cancelled]);

        $this->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertRedirect(route('listings.show', ['locale' => 'en', 'listing' => $listing]));
    });

    it('404s on non-team-play listings', function () {
        $listing = Listing::factory()->create(); // team_size = 1

        $this->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertNotFound();
    });

    it('exposes the invite_token only to the listing owner', function () {
        $listing = pageLobby(isPublic: false);
        $stranger = User::factory()->active()->create();

        // Stranger can't see the page at all — covered by the 404 test above.
        // Owner sees the token in the payload.
        $this->actingAs($listing->user)
            ->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.invite_token', $listing->invite_token),
            );

        // A live participant (non-owner) does NOT see the token.
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $this->actingAs($joiner)
            ->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.invite_token', null),
            );
    });
});

describe('POST /lobbies/{listing}/join', function () {
    it('soft-joins the viewer to the requested side', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();

        $this->actingAs($joiner)
            ->post(route('lobbies.join', ['locale' => 'en', 'listing' => $listing]), [
                'side' => LobbyParticipant::SIDE_B,
            ])
            ->assertRedirect();

        expect(LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->live()
            ->exists())->toBeTrue();
    });

    it('422s when side is missing or invalid', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();

        $this->actingAs($joiner)
            ->post(route('lobbies.join', ['locale' => 'en', 'listing' => $listing]), [
                'side' => 'c',
            ])
            ->assertSessionHasErrors('side');
    });

    it('requires authentication', function () {
        $listing = pageLobby();

        $this->post(route('lobbies.join', ['locale' => 'en', 'listing' => $listing]), [
            'side' => LobbyParticipant::SIDE_B,
        ])
            ->assertRedirect(); // → login
    });
});

describe('POST /lobbies/{listing}/leave', function () {
    it('vacates the viewer\'s slot', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $this->actingAs($joiner)
            ->post(route('lobbies.leave', ['locale' => 'en', 'listing' => $listing]))
            ->assertRedirect();

        expect(LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->live()
            ->exists())->toBeFalse();
    });

    it('cancels the lobby when the creator leaves', function () {
        $listing = pageLobby();

        $this->actingAs($listing->user)
            ->post(route('lobbies.leave', ['locale' => 'en', 'listing' => $listing]))
            ->assertRedirect();

        $listing->refresh();
        expect($listing->status)->toBe(ListingStatus::Cancelled);
        expect($listing->lobby_state)->toBe('cancelled');
    });

    it('403s a non-participant', function () {
        $listing = pageLobby();
        $stranger = pageJoiner();

        $this->actingAs($stranger)
            ->post(route('lobbies.leave', ['locale' => 'en', 'listing' => $listing]))
            ->assertForbidden();
    });
});

describe('POST /lobbies/{listing}/ready', function () {
    it('escrows the viewer\'s stake when toggling to Ready', function () {
        $listing = pageLobby();
        $balanceBefore = Wallet::balanceFor($listing->user);

        $this->actingAs($listing->user)
            ->post(route('lobbies.ready', ['locale' => 'en', 'listing' => $listing]))
            ->assertRedirect();

        expect(Wallet::balanceFor($listing->user))
            ->toBe(bcsub($balanceBefore, '100', 6));
    });

    it('403s a non-participant', function () {
        $listing = pageLobby();
        $stranger = pageJoiner();

        $this->actingAs($stranger)
            ->post(route('lobbies.ready', ['locale' => 'en', 'listing' => $listing]))
            ->assertForbidden();
    });
});

describe('DELETE /lobbies/{listing}/participants/{user}', function () {
    it('lets the owner kick another participant', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $this->actingAs($listing->user)
            ->delete(route('lobbies.kick', [
                'locale' => 'en',
                'listing' => $listing,
                'user' => $joiner,
            ]))
            ->assertRedirect();

        $row = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $joiner->id)
            ->first();
        expect($row->kicked_at)->not->toBeNull();
    });

    it('403s a non-owner kick attempt', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $stranger = pageJoiner();

        $this->actingAs($stranger)
            ->delete(route('lobbies.kick', [
                'locale' => 'en',
                'listing' => $listing,
                'user' => $joiner,
            ]))
            ->assertForbidden();
    });
});
