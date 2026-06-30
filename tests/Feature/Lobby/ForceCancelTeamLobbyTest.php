<?php

use App\Actions\Lobby\Admin\ForceCancelTeamLobbyAction;
use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;

/**
 * M42 — admin force-cancel of an OPEN team lobby. Refunds every Ready'd
 * player's pooled escrow, vacates the roster, and cancels the listing + match.
 * The 1v1 `CancelListingAction` can't do this safely (it refunds only the
 * creator, often a stake never held for team play).
 *
 * @return array{0: Listing, 1: GameMatch, 2: User, 3: User}
 */
function openTeamLobby(string $stake = '50'): array
{
    platformUser();

    $creator = User::factory()->active()->create();
    $teammate = User::factory()->active()->create();

    foreach ([$creator, $teammate] as $user) {
        Wallet::deposit($user, '500', reference: "test:dep:{$user->id}");
    }

    $listing = Listing::factory()->teamPlay(2)->for($creator)->create([
        'status' => ListingStatus::Open,
        'lobby_state' => 'recruiting',
        'stake_amount' => $stake,
    ]);

    $match = GameMatch::factory()->create([
        'listing_id' => $listing->id,
        'taker_user_id' => $creator->id,
        'status' => MatchStatus::LobbyFilling,
    ]);

    foreach ([[$creator, 'a', 0], [$teammate, 'b', 0]] as [$user, $side, $slot]) {
        Wallet::hold($user, $stake, $listing, reference: "ready:{$listing->id}:{$user->id}");
        LobbyParticipant::factory()->create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'side' => $side,
            'slot_index' => $slot,
            'is_ready' => true,
            'stake_held_at' => now(),
        ]);
    }

    return [$listing, $match, $creator, $teammate];
}

test('force-cancel refunds every Ready player, vacates the roster, cancels listing + match', function () {
    [$listing, $match, $creator, $teammate] = openTeamLobby('50');

    $creatorHeld = Wallet::balanceFor($creator->fresh());
    $teammateHeld = Wallet::balanceFor($teammate->fresh());

    $result = app(ForceCancelTeamLobbyAction::class)->handle($listing);

    expect($result)->toBe('cancelled');
    expect($listing->fresh()->status)->toBe(ListingStatus::Cancelled);
    expect($listing->fresh()->lobby_state)->toBe('cancelled');
    expect($match->fresh()->status)->toBe(MatchStatus::Cancelled);

    // Both Ready players refunded their held stake.
    expect(Wallet::balanceFor($creator->fresh()))->toBe(bcadd($creatorHeld, '50', 6));
    expect(Wallet::balanceFor($teammate->fresh()))->toBe(bcadd($teammateHeld, '50', 6));

    // Roster vacated (no live participants remain).
    expect(LobbyParticipant::query()->where('listing_id', $listing->id)->live()->count())->toBe(0);
});

test('force-cancel is idempotent — a second call is a noop', function () {
    [$listing, , $creator] = openTeamLobby('50');

    expect(app(ForceCancelTeamLobbyAction::class)->handle($listing))->toBe('cancelled');

    $balanceAfterFirst = Wallet::balanceFor($creator->fresh());

    expect(app(ForceCancelTeamLobbyAction::class)->handle($listing->fresh()))->toBe('noop');
    // No second refund — balance unchanged by the noop.
    expect(Wallet::balanceFor($creator->fresh()))->toBe($balanceAfterFirst);
});

test('force-cancel is a noop on a 1v1 listing', function () {
    $solo = Listing::factory()->open()->create();

    expect(app(ForceCancelTeamLobbyAction::class)->handle($solo))->toBe('noop');
    expect($solo->fresh()->status)->toBe(ListingStatus::Open);
});
