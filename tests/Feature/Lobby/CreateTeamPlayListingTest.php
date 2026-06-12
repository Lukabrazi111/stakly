<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/*
 * M34 P1 — CreateTeamPlayListingAction.
 */

function teamPlayListingData(array $overrides = []): array
{
    return array_merge([
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'skill_min' => null,
        'skill_max' => null,
        'time_control' => [],
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 5,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ], $overrides);
}

it('creates a listing + LobbyFilling match + creator participant in one transaction', function () {
    $creator = User::factory()->active()->withFaceit()->create();

    $listing = app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData());

    expect($listing)->toBeInstanceOf(Listing::class);
    expect($listing->isTeamPlay())->toBeTrue();
    expect($listing->team_size)->toBe(5);
    expect($listing->status)->toBe(ListingStatus::Open);
    expect($listing->lobby_state)->toBe('recruiting');
    expect($listing->is_public)->toBeTrue();
    expect($listing->invite_token)->toBeNull();

    $match = $listing->gameMatch;
    expect($match)->not->toBeNull();
    expect($match->status)->toBe(MatchStatus::LobbyFilling);

    $participant = LobbyParticipant::query()
        ->where('listing_id', $listing->id)
        ->where('user_id', $creator->id)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->side)->toBe(LobbyParticipant::SIDE_A);
    expect($participant->slot_index)->toBe(0);
    expect($participant->is_ready)->toBeFalse();
    expect($participant->stake_held_at)->toBeNull();
});

it('does NOT escrow the creator at listing creation (stake commits at Ready, not Create)', function () {
    $creator = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($creator, '500', reference: "test:create:{$creator->id}");

    $balanceBefore = Wallet::balanceFor($creator);

    app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData(['stake_amount' => '100']));

    expect(Wallet::balanceFor($creator))->toBe($balanceBefore);
});

it('generates a 32-char invite_token for private listings', function () {
    $creator = User::factory()->active()->withFaceit()->create();

    $listing = app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData(['is_public' => false]));

    expect($listing->is_public)->toBeFalse();
    expect($listing->invite_token)->toBeString();
    expect(strlen($listing->invite_token))->toBe(32);
});

it('returns "not_linked" when the creator is not verified on the listing platform', function () {
    $creator = User::factory()->active()->create(); // no FACEIT link

    $result = app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData());

    expect($result)->toBe('not_linked');
    expect(Listing::query()->count())->toBe(0);
});

it('returns "already_in_lobby" when the creator is already in another active lobby', function () {
    $creator = User::factory()->active()->withFaceit()->create();

    app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData());

    $second = app(CreateTeamPlayListingAction::class)
        ->handle($creator, teamPlayListingData());

    expect($second)->toBe('already_in_lobby');
    expect(Listing::query()->where('user_id', $creator->id)->count())->toBe(1);
});
