<?php

use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\User;

/*
 * M41 P4 — ListingResource exposes the creator's verified chess rating for the
 * listing's platform + time control; CS2 listings stay null. Provisional /
 * missing ratings surface as "Unrated" (rating null).
 */

function chessListingFor(User $creator, TimeControl $timeControl): Listing
{
    return Listing::factory()
        ->forLichess()
        ->for($creator)
        ->state(['time_control' => $timeControl->value])
        ->create()
        ->load('user.linkedAccounts.ratings');
}

it('exposes the creator rating for the listing platform + time control', function () {
    $creator = User::factory()->withLichess('alice')->create();
    $account = $creator->linkedAccounts()->firstOrFail();
    $account->ratings()->create([
        'time_control' => TimeControl::Blitz->value,
        'rating' => 1850,
        'rd' => 50,
        'is_provisional' => false,
        'synced_at' => now(),
    ]);
    // A rapid rating exists too — must NOT be picked for a blitz listing.
    $account->ratings()->create([
        'time_control' => TimeControl::Rapid->value,
        'rating' => 1500,
        'rd' => 50,
        'is_provisional' => false,
        'synced_at' => now(),
    ]);

    $data = (new ListingResource(chessListingFor($creator, TimeControl::Blitz)))->resolve();

    expect($data['creator']['chess_rating'])->toBe([
        'rating' => 1850,
        'is_provisional' => false,
        'is_unrated' => false,
    ]);
});

it('marks a chess creator with no rating for that time control as unrated', function () {
    $creator = User::factory()->withLichess('bob')->create();
    // Only a rapid rating; the listing is blitz.
    $creator->linkedAccounts()->firstOrFail()->ratings()->create([
        'time_control' => TimeControl::Rapid->value,
        'rating' => 1700,
        'rd' => 50,
        'is_provisional' => false,
        'synced_at' => now(),
    ]);

    $data = (new ListingResource(chessListingFor($creator, TimeControl::Blitz)))->resolve();

    expect($data['creator']['chess_rating'])->toBe([
        'rating' => null,
        'is_provisional' => false,
        'is_unrated' => true,
    ]);
});

it('shows a provisional rating with its number + the provisional flag (not hidden)', function () {
    $creator = User::factory()->withLichess('carol')->create();
    $creator->linkedAccounts()->firstOrFail()->ratings()->create([
        'time_control' => TimeControl::Blitz->value,
        'rating' => 1400,
        'rd' => 180,
        'is_provisional' => true,
        'synced_at' => now(),
    ]);

    $data = (new ListingResource(chessListingFor($creator, TimeControl::Blitz)))->resolve();

    expect($data['creator']['chess_rating'])->toBe([
        'rating' => 1400,
        'is_provisional' => true,
        'is_unrated' => false,
    ]);
});

it('reads the rating for the listing platform, not the creator other chess account', function () {
    // Creator linked on BOTH providers; the listing is on chess.com.
    $creator = User::factory()->withLichess('dave-lichess')->withChessCom('dave-cc')->create();
    $lichess = $creator->linkedAccounts->firstWhere('provider', LinkedAccountProvider::Lichess);
    $chessCom = $creator->linkedAccounts->firstWhere('provider', LinkedAccountProvider::ChessCom);
    $lichess->ratings()->create(['time_control' => TimeControl::Blitz->value, 'rating' => 2200, 'is_provisional' => false, 'synced_at' => now()]);
    $chessCom->ratings()->create(['time_control' => TimeControl::Blitz->value, 'rating' => 1300, 'is_provisional' => false, 'synced_at' => now()]);

    $listing = Listing::factory()->forChessCom()->for($creator)
        ->state(['time_control' => TimeControl::Blitz->value])
        ->create()
        ->load('user.linkedAccounts.ratings');

    $data = (new ListingResource($listing))->resolve();

    // chess.com rating (1300), not the Lichess 2200.
    expect($data['creator']['chess_rating']['rating'])->toBe(1300);
});

it('does not expose a chess rating on a CS2 listing', function () {
    $creator = User::factory()->withFaceit('erin', 'guid-e', 1500)->create();
    $listing = Listing::factory()->forGame(Game::Cs2)->for($creator)->create()
        ->load('user.linkedAccounts.ratings');

    $data = (new ListingResource($listing))->resolve();

    expect($data['creator']['chess_rating'])->toBeNull();
});
