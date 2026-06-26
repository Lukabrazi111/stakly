<?php

use App\Actions\GameMatch\TakeListingAction;
use App\Enums\LinkedAccountProvider;
use App\Enums\TimeControl;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;

/*
 * M41 P3b — the match-take snapshot records the per-time-control chess rating
 * for the listing's single time control (not the scalar skill_rating, which
 * chess never populates). Audit/history only.
 */

function seedChessRating(User $user, TimeControl $tc, int $rating): void
{
    $user->linkedAccounts()->firstOrFail()->ratings()->create([
        'time_control' => $tc->value,
        'rating' => $rating,
        'rd' => 50,
        'is_provisional' => false,
        'synced_at' => now(),
    ]);
}

it('snapshots the per-time-control rating for the listing time control', function () {
    $creator = User::factory()->active()->withLichess('alice')->create();
    Wallet::deposit($creator, '500', reference: "test:c:{$creator->id}");
    // Creator has both a blitz and a rapid rating; the listing is blitz.
    seedChessRating($creator, TimeControl::Blitz, 1850);
    seedChessRating($creator, TimeControl::Rapid, 1500);

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
        'time_control' => TimeControl::Blitz->value,
    ])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $taker = User::factory()->withLichess('bob')->create();
    Wallet::deposit($taker, '500', reference: "test:t:{$taker->id}");
    seedChessRating($taker, TimeControl::Blitz, 1700);

    app(TakeListingAction::class)->handle($taker, $listing->fresh());

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();

    $creatorSnap = $match->providerSnapshots()
        ->where('side', GameMatch::SIDE_CREATOR)
        ->where('provider', LinkedAccountProvider::Lichess)
        ->firstOrFail();
    $takerSnap = $match->providerSnapshots()
        ->where('side', GameMatch::SIDE_TAKER)
        ->where('provider', LinkedAccountProvider::Lichess)
        ->firstOrFail();

    // Blitz ratings, NOT the creator's rapid (1500).
    expect($creatorSnap->skill_rating_snapshot)->toBe(1850)
        ->and($takerSnap->skill_rating_snapshot)->toBe(1700);
});

it('snapshots null when the chess player has no rating for the listing time control', function () {
    $creator = User::factory()->active()->withLichess('alice')->create();
    Wallet::deposit($creator, '500', reference: "test:c2:{$creator->id}");
    // Creator only has a rapid rating; the listing is blitz → no blitz rating.
    seedChessRating($creator, TimeControl::Rapid, 1500);

    $listing = Listing::factory()->open()->forLichess()->for($creator)->state([
        'stake_amount' => '100',
        'time_control' => TimeControl::Blitz->value,
    ])->create();
    Wallet::hold(user: $creator, amount: '100', listing: $listing, reference: "listing-create:{$listing->id}");

    $taker = User::factory()->withLichess('bob')->create();
    Wallet::deposit($taker, '500', reference: "test:t2:{$taker->id}");

    app(TakeListingAction::class)->handle($taker, $listing->fresh());

    $match = GameMatch::query()->where('listing_id', $listing->id)->firstOrFail();
    $creatorSnap = $match->providerSnapshots()
        ->where('side', GameMatch::SIDE_CREATOR)
        ->where('provider', LinkedAccountProvider::Lichess)
        ->firstOrFail();

    expect($creatorSnap->skill_rating_snapshot)->toBeNull();
});
