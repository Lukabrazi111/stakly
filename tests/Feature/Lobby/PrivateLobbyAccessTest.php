<?php

use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/**
 * M34 P5 — private lobby access hardening. A private team-play lobby
 * (`is_public = false`) must be reachable only by the creator, current
 * participants, and anyone who opened the invite link (per-session pass).
 * Enumerating the sequential `/listings/{id}` URL must 404, and a kicked
 * player can't come back via the link.
 */
function privateLobby(?User $creator = null): Listing
{
    $creator ??= User::factory()->active()->withFaceit()->create();

    return Listing::factory()->teamPlay()->private()->for($creator)->create();
}

function verifiedPlayer(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:private-lobby:{$user->id}");

    return $user;
}

it('404s a guest who guesses a private lobby URL', function () {
    $listing = privateLobby();

    $this->get("/listings/{$listing->id}")->assertNotFound();
});

it('404s an authenticated non-invited user on a private lobby', function () {
    $listing = privateLobby();
    $stranger = User::factory()->active()->create();

    $this->actingAs($stranger)->get("/listings/{$listing->id}")->assertNotFound();
});

it('lets the creator view their own private lobby', function () {
    $creator = User::factory()->active()->withFaceit()->create();
    $listing = privateLobby($creator);

    $this->actingAs($creator)->get("/listings/{$listing->id}")->assertOk();
});

it('lets a live participant view a private lobby', function () {
    $listing = privateLobby();
    $member = User::factory()->active()->create();
    LobbyParticipant::factory()->for($listing)->for($member)->state([
        'side' => LobbyParticipant::SIDE_B,
        'slot_index' => 0,
        'kicked_at' => null,
    ])->create();

    $this->actingAs($member)->get("/listings/{$listing->id}")->assertOk();
});

it('keeps PUBLIC team-play lobbies open to anyone, including guests', function () {
    $listing = Listing::factory()->teamPlay()->for(
        User::factory()->active()->withFaceit()->create()
    )->create();

    expect($listing->is_public)->toBeTrue();
    $this->get("/listings/{$listing->id}")->assertOk();
});

it('grants access through the invite link, then the canonical URL works', function () {
    $listing = privateLobby();
    $invitee = User::factory()->active()->create();

    // Without a pass → 404.
    $this->actingAs($invitee)->get("/listings/{$listing->id}")->assertNotFound();

    // Opening the (auth-gated) invite link records the session pass + redirects.
    $this->actingAs($invitee)
        ->get("/lobbies/{$listing->invite_token}")
        ->assertRedirect(route('listings.show', $listing));

    // Now the canonical URL authorizes them.
    $this->actingAs($invitee)->get("/listings/{$listing->id}")->assertOk();
});

it('funnels a GUEST who opens the invite link through login', function () {
    $listing = privateLobby();

    // The invite-token route is auth-gated, so a guest is sent to log in there
    // (and can never obtain a pass while logged out).
    $this->get("/lobbies/{$listing->invite_token}")->assertRedirect(route('login'));
});

it('blocks joining a private lobby without the invite pass', function () {
    $listing = privateLobby();
    $stranger = verifiedPlayer();

    $this->actingAs($stranger)
        ->post("/lobbies/{$listing->id}/join", ['side' => LobbyParticipant::SIDE_B])
        ->assertForbidden();

    expect(
        LobbyParticipant::where('listing_id', $listing->id)
            ->where('user_id', $stranger->id)
            ->exists()
    )->toBeFalse();
});

it('allows joining a private lobby after opening the invite link', function () {
    $listing = privateLobby();
    $invitee = verifiedPlayer();

    $this->actingAs($invitee)->get("/lobbies/{$listing->invite_token}"); // pass

    $this->actingAs($invitee)
        ->post("/lobbies/{$listing->id}/join", ['side' => LobbyParticipant::SIDE_B]);

    expect(
        LobbyParticipant::where('listing_id', $listing->id)
            ->where('user_id', $invitee->id)
            ->live()
            ->exists()
    )->toBeTrue();
});

it('bars a kicked player from re-entering a private lobby even with a pass', function () {
    $listing = privateLobby();
    $kicked = verifiedPlayer();

    // They were in, then kicked — a kick stamps `kicked_at` (row stays).
    LobbyParticipant::factory()->for($listing)->for($kicked)->state([
        'side' => LobbyParticipant::SIDE_B,
        'slot_index' => 0,
        'kicked_at' => now(),
    ])->create();

    // Even after re-opening the invite link (which grants a pass), the policy's
    // kicked check overrides it: no view, no rejoin.
    $this->actingAs($kicked)->get("/lobbies/{$listing->invite_token}");
    $this->actingAs($kicked)->get("/listings/{$listing->id}")->assertNotFound();
    $this->actingAs($kicked)
        ->post("/lobbies/{$listing->id}/join", ['side' => LobbyParticipant::SIDE_A])
        ->assertForbidden();
});

it('does not bar a player with no kicked_at row (e.g. one who voluntarily left)', function () {
    // Voluntary leave DELETES the participant row, so a returning leaver has no
    // `kicked_at` record — indistinguishable from a fresh invitee. With a pass
    // they can view again.
    $listing = privateLobby();
    $returner = User::factory()->active()->create();

    $this->actingAs($returner)->get("/lobbies/{$listing->invite_token}");
    $this->actingAs($returner)->get("/listings/{$listing->id}")->assertOk();
});
