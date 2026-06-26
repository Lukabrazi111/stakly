<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\KickParticipantAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\Message;
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
        'time_control' => null,
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

describe('GET /listings/{id} (team-play lobby UI)', function () {
    it('renders the lobby UI for public listings to any visitor', function () {
        $listing = pageLobby();

        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('listings/show')
                ->where('lobby.id', $listing->id)
                ->where('lobby.team_size', 5)
                ->where('lobby.lobby_state', 'recruiting'),
            );
    });

    it('renders the page for the listing owner on a private listing', function () {
        $listing = pageLobby(isPublic: false);

        $this->actingAs($listing->user)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk();
    });

    it('renders for an unauthenticated visitor with the private URL (URL = access model)', function () {
        $listing = pageLobby(isPublic: false);

        // M34 P3.1 Slice B.1 — viewLobby relaxed so the invite-token flow
        // can land non-participants on the page so they can join. Known
        // trade-off: sequential ID enumeration exposes private lobbies.
        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk();
    });

    it('renders chess detail (not lobby UI) for team_size = 1 listings', function () {
        $listing = Listing::factory()->open()->create(); // team_size = 1

        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('listings/show')
                ->missing('lobby'),
            );
    });

    it('exposes the invite_token only to the listing owner', function () {
        $listing = pageLobby(isPublic: false);

        $this->actingAs($listing->user)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.invite_token', $listing->invite_token),
            );

        // A live participant (non-owner) does NOT see the token.
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);

        $this->actingAs($joiner)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.invite_token', null),
            );
    });
});

describe('GET /listings/{id} chat messages payload (team-play)', function () {
    it('exposes messages to the listing owner (auto-soft-joined participant)', function () {
        $listing = pageLobby();
        Message::factory()->create([
            'match_id' => $listing->gameMatch->id,
            'user_id' => $listing->user_id,
            'content' => 'Hello team',
        ]);

        $this->actingAs($listing->user)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('messages.data', 1));
    });

    it('exposes messages to a live non-owner participant', function () {
        $listing = pageLobby();
        $joiner = pageJoiner();
        app(JoinLobbyAction::class)->handle($joiner, $listing, LobbyParticipant::SIDE_B);
        Message::factory()->create([
            'match_id' => $listing->gameMatch->id,
            'user_id' => $listing->user_id,
        ]);

        $this->actingAs($joiner)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('messages.data', 1));
    });

    it('returns empty messages.data to an authed non-participant', function () {
        $listing = pageLobby();
        Message::factory()->create([
            'match_id' => $listing->gameMatch->id,
            'user_id' => $listing->user_id,
        ]);
        $stranger = User::factory()->active()->create();

        $this->actingAs($stranger)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('messages.data', []));
    });

    it('returns empty messages.data to an unauthenticated visitor', function () {
        $listing = pageLobby();
        Message::factory()->create([
            'match_id' => $listing->gameMatch->id,
            'user_id' => $listing->user_id,
        ]);

        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('messages.data', []));
    });

    it('returns empty messages.data to a kicked former participant', function () {
        $listing = pageLobby();
        $kicked = pageJoiner();
        app(JoinLobbyAction::class)->handle($kicked, $listing, LobbyParticipant::SIDE_B);
        Message::factory()->create([
            'match_id' => $listing->gameMatch->id,
            'user_id' => $listing->user_id,
        ]);
        app(KickParticipantAction::class)->handle($listing->user, $listing, $kicked);

        $this->actingAs($kicked)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('messages.data', []));
    });
});

describe('match_deadline_at on the lobby payload (M34 P7)', function () {
    it('is null while the lobby is still recruiting', function () {
        $listing = pageLobby();

        $this->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.lobby_state', 'recruiting')
                ->where('lobby.match_deadline_at', null),
            );
    });

    it('reflects match.created_at + match_confirmation_timeout_hours once the lobby is locked', function () {
        $listing = pageLobby(teamSize: 2);
        $creator = $listing->user;
        $teammate = pageJoiner();
        $oppB1 = pageJoiner();
        $oppB2 = pageJoiner();

        app(JoinLobbyAction::class)->handle($teammate, $listing, LobbyParticipant::SIDE_A);
        app(JoinLobbyAction::class)->handle($oppB1, $listing, LobbyParticipant::SIDE_B);
        app(JoinLobbyAction::class)->handle($oppB2, $listing, LobbyParticipant::SIDE_B);

        app(ToggleReadyAction::class)->handle($creator, $listing);
        app(ToggleReadyAction::class)->handle($teammate, $listing);
        app(ToggleReadyAction::class)->handle($oppB1, $listing);
        app(ToggleReadyAction::class)->handle($oppB2, $listing);

        $listing->refresh();
        expect($listing->lobby_state)->toBe('locked');

        $hours = (int) config('stakly.match_confirmation_timeout_hours');
        $expected = $listing->gameMatch->created_at->copy()->addHours($hours)->toIso8601String();

        $this->actingAs($creator)
            ->get(route('listings.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertInertia(fn ($page) => $page
                ->where('lobby.lobby_state', 'locked')
                ->where('lobby.match_deadline_at', $expected),
            );
    });
});

describe('GET /lobbies/{listing} (legacy URL — 301 redirect)', function () {
    it('301-redirects to /listings/{id} for any team-play listing', function () {
        $listing = pageLobby();

        $this->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertStatus(301)
            ->assertRedirect(route('listings.show', ['locale' => 'en', 'listing' => $listing]));
    });

    it('still 404s for non-team-play (chess) listings', function () {
        $listing = Listing::factory()->create(); // team_size = 1

        $this->get(route('lobbies.show', ['locale' => 'en', 'listing' => $listing]))
            ->assertNotFound();
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
