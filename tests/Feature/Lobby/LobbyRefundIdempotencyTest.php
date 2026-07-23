<?php

use App\Actions\Listing\CreateTeamPlayListingAction;
use App\Actions\Lobby\JoinLobbyAction;
use App\Actions\Lobby\KickParticipantAction;
use App\Actions\Lobby\LeaveLobbyAction;
use App\Actions\Lobby\ToggleReadyAction;
use App\Enums\Game;
use App\Enums\LinkedAccountProvider;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * M34 (/cso H2) — the terminal lobby refund paths (kick, leave, creator-cancel)
 * carry a per-participation idempotency reference anchored on the LobbyParticipant
 * ROW id. The un-Ready toggle stays reference-less by design (repeatable toggle).
 *
 * The load-bearing case is rejoin-then-kick-again: a `{listing}:{user}` key would
 * idempotency-block the second refund and short the player their re-staked funds;
 * the row-id key refunds each kick independently.
 */
function refundPlayer(): User
{
    $user = User::factory()->active()->withFaceit()->create();
    Wallet::deposit($user, '10000', reference: "test:refund:{$user->id}");

    return $user;
}

/**
 * A full 2v2 CS2 lobby in `ready_checking` (creator + 3 joiners soft-joined, none
 * Ready yet). Stake is 100 USDT per Ready.
 *
 * @return array{listing: Listing, creator: User, teammate: User, opp1: User, opp2: User}
 */
function refundLobby(): array
{
    platformUser();

    $creator = refundPlayer();
    $listing = app(CreateTeamPlayListingAction::class)->handle($creator, [
        'game' => Game::Cs2->value,
        'platform' => LinkedAccountProvider::Faceit->value,
        'stake_amount' => '100',
        'time_control' => null,
        'region' => null,
        'language' => null,
        'duration_hours' => 24,
        'team_size' => 2,
        'creator_side' => LobbyParticipant::SIDE_A,
        'is_public' => true,
    ]);

    $teammate = refundPlayer();
    $opp1 = refundPlayer();
    $opp2 = refundPlayer();

    app(JoinLobbyAction::class)->handle($teammate, $listing, LobbyParticipant::SIDE_A);
    app(JoinLobbyAction::class)->handle($opp1, $listing, LobbyParticipant::SIDE_B);
    app(JoinLobbyAction::class)->handle($opp2, $listing, LobbyParticipant::SIDE_B);

    return compact('listing', 'creator', 'teammate', 'opp1', 'opp2');
}

function liveRow(int $listingId, int $userId): LobbyParticipant
{
    return LobbyParticipant::query()
        ->where('listing_id', $listingId)
        ->where('user_id', $userId)
        ->live()
        ->firstOrFail();
}

function balanceEquals(User $user, string $expected): void
{
    expect(bccomp(Wallet::balanceFor($user), $expected, 6))->toBe(0);
}

it('refunds a kicked Ready player with a row-id reference, idempotently', function () {
    ['listing' => $listing, 'creator' => $creator, 'teammate' => $teammate] = refundLobby();

    app(ToggleReadyAction::class)->handle($teammate, $listing);
    balanceEquals($teammate, '9900'); // 10000 − 100 held

    $row = liveRow($listing->id, $teammate->id);

    app(KickParticipantAction::class)->handle($creator, $listing, $teammate);

    balanceEquals($teammate, '10000'); // refunded
    $ref = "kick-refund:{$row->id}";
    expect(WalletTransaction::query()->where('reference_id', $ref)->count())->toBe(1);

    // Idempotency: a repeat release with the same reference is a silent no-op.
    $countBefore = WalletTransaction::query()->where('user_id', $teammate->id)->count();
    Wallet::release(user: $teammate, amount: '100', listing: $listing, reference: $ref);
    expect(WalletTransaction::query()->where('user_id', $teammate->id)->count())->toBe($countBefore);
    balanceEquals($teammate, '10000');
});

it('refunds a rejoining player on EACH kick — row-id anchor survives rejoin', function () {
    ['listing' => $listing, 'creator' => $creator, 'teammate' => $teammate] = refundLobby();

    // Cycle 1 — ready, kick, refund.
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    $rowA = liveRow($listing->id, $teammate->id);
    app(KickParticipantAction::class)->handle($creator, $listing, $teammate);

    // Age the kick past the 5-min rejoin cooldown so they can come back.
    LobbyParticipant::query()->where('id', $rowA->id)->update(['kicked_at' => now()->subMinutes(6)]);

    // Cycle 2 — rejoin as a NEW row, ready, kick again, refund again.
    app(JoinLobbyAction::class)->handle($teammate, $listing, LobbyParticipant::SIDE_A);
    $rowB = liveRow($listing->id, $teammate->id);
    expect($rowB->id)->not->toBe($rowA->id);

    app(ToggleReadyAction::class)->handle($teammate, $listing);
    app(KickParticipantAction::class)->handle($creator, $listing, $teammate);

    // Both refunds landed — a (listing,user) key would have blocked the 2nd.
    balanceEquals($teammate, '10000');
    expect(WalletTransaction::query()->where('reference_id', "kick-refund:{$rowA->id}")->count())->toBe(1);
    expect(WalletTransaction::query()->where('reference_id', "kick-refund:{$rowB->id}")->count())->toBe(1);
});

it('un-Ready refunds on every toggle cycle (reference-less by design)', function () {
    ['listing' => $listing, 'teammate' => $teammate] = refundLobby();

    // ready → un-ready → ready → un-ready. If un-Ready carried a static ref,
    // the 2nd cycle would idempotency-block and the balance would drift.
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    balanceEquals($teammate, '9900');
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    balanceEquals($teammate, '10000');
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    balanceEquals($teammate, '9900');
    app(ToggleReadyAction::class)->handle($teammate, $listing);
    balanceEquals($teammate, '10000');
});

it('refunds a leaving Ready player with a leave-refund reference', function () {
    ['listing' => $listing, 'teammate' => $teammate] = refundLobby();

    app(ToggleReadyAction::class)->handle($teammate, $listing);
    $row = liveRow($listing->id, $teammate->id);

    app(LeaveLobbyAction::class)->handle($teammate, $listing);

    balanceEquals($teammate, '10000');
    expect(WalletTransaction::query()->where('reference_id', "leave-refund:{$row->id}")->count())->toBe(1);
});

it('refunds every Ready player when the creator leaves (per-participant leave-refund refs)', function () {
    ['listing' => $listing, 'creator' => $creator, 'teammate' => $teammate, 'opp1' => $opp1] = refundLobby();

    app(ToggleReadyAction::class)->handle($teammate, $listing);
    app(ToggleReadyAction::class)->handle($opp1, $listing);
    $tRow = liveRow($listing->id, $teammate->id);
    $oRow = liveRow($listing->id, $opp1->id);

    app(LeaveLobbyAction::class)->handle($creator, $listing); // creator leaves → lobby cancels

    balanceEquals($teammate, '10000');
    balanceEquals($opp1, '10000');
    expect(WalletTransaction::query()->where('reference_id', "leave-refund:{$tRow->id}")->count())->toBe(1);
    expect(WalletTransaction::query()->where('reference_id', "leave-refund:{$oRow->id}")->count())->toBe(1);
});
