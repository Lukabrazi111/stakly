<?php

use App\Enums\Game;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\User;

/*
 * M41 P2 — ListingResource exposes the creator's verified FACEIT rating on CS2
 * listings (ELO + derived level + unrated flag); chess listings stay null.
 */

it('exposes the creator FACEIT rating on a CS2 listing', function () {
    $creator = User::factory()->withFaceit('alice', 'guid-a', 1500)->create();
    $listing = Listing::factory()->forGame(Game::Cs2)->for($creator)->create()
        ->load('user.linkedAccounts');

    $data = (new ListingResource($listing))->resolve();

    expect($data['creator']['faceit_rating'])->toBe([
        'elo' => 1500,
        'level' => 7,
        'is_unrated' => false,
    ]);
});

it('marks a CS2 creator with no CS2 ELO as unrated', function () {
    $creator = User::factory()->withFaceit('bob', 'guid-b')->create();
    $creator->linkedAccounts()->first()->update(['skill_rating' => null]);
    $listing = Listing::factory()->forGame(Game::Cs2)->for($creator)->create()
        ->load('user.linkedAccounts');

    $data = (new ListingResource($listing))->resolve();

    expect($data['creator']['faceit_rating'])->toBe([
        'elo' => null,
        'level' => null,
        'is_unrated' => true,
    ]);
});

it('marks a CS2 creator with no FACEIT link as unrated', function () {
    $creator = User::factory()->create();
    $listing = Listing::factory()->forGame(Game::Cs2)->for($creator)->create()
        ->load('user.linkedAccounts');

    $data = (new ListingResource($listing))->resolve();

    expect($data['creator']['faceit_rating'])->toBe([
        'elo' => null,
        'level' => null,
        'is_unrated' => true,
    ]);
});

it('does not expose a FACEIT rating on a chess listing', function () {
    $creator = User::factory()->withLichess('carol')->create();
    $listing = Listing::factory()->forGame(Game::Chess)->for($creator)->create()
        ->load('user.linkedAccounts');

    $data = (new ListingResource($listing))->resolve();

    expect($data['creator']['faceit_rating'])->toBeNull();
});
