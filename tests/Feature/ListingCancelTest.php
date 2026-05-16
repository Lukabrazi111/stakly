<?php

use App\Enums\ListingStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * Helper: create a verified user with a real escrow held on a fresh listing —
 * exactly what `ListingController::store` would have produced. Returns
 * [user, listing] so the cancel test can assert against post-cancel state
 * without doing the create dance itself.
 */
function userWithHeldListing(string $deposit = '500', string $stake = '100'): array
{
    $user = User::factory()->create();
    Wallet::deposit($user, $deposit, reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->open()->for($user)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $user,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    return [$user, $listing];
}

// ─── Authorization (8.8) ──────────────────────────────────────────────────

test('a non-owner cannot cancel another user listing (403)', function () {
    [, $listing] = userWithHeldListing();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->deleteJson("/listings/{$listing->id}/cancel")
        ->assertForbidden();

    expect($listing->fresh()->status)->toBe(ListingStatus::Open);
});

test('guests cannot cancel anything (redirected to login)', function () {
    [, $listing] = userWithHeldListing();

    $this->delete("/listings/{$listing->id}/cancel")
        ->assertRedirect(route('login'));

    expect($listing->fresh()->status)->toBe(ListingStatus::Open);
});

// ─── Happy path (8.9) ─────────────────────────────────────────────────────

test('owner can cancel an open listing — status flips, refund row written, balance restored', function () {
    [$user, $listing] = userWithHeldListing(deposit: '500', stake: '100');

    // Sanity: hold reduced the balance to 400 before cancel.
    expect((string) $user->fresh()->usdt_balance)->toBe('400.000000');

    $response = $this->actingAs($user)->deleteJson("/listings/{$listing->id}/cancel");

    $response->assertRedirect(route('listings.mine'));

    expect($listing->fresh()->status)->toBe(ListingStatus::Cancelled);

    $release = WalletTransaction::query()
        ->where('user_id', $user->id)
        ->where('type', WalletTransactionType::EscrowRelease)
        ->where('reference_id', "listing-cancel:{$listing->id}")
        ->firstOrFail();

    expect($release->amount)->toBe('100.000000')
        ->and($release->related_listing_id)->toBe($listing->id);

    expect((string) $user->fresh()->usdt_balance)->toBe('500.000000');
});

test('successful cancel flashes a success toast with the refund amount', function () {
    [$user, $listing] = userWithHeldListing(deposit: '500', stake: '75');

    $response = $this->actingAs($user)->deleteJson("/listings/{$listing->id}/cancel");

    $response->assertInertiaFlash('toast', [
        'type' => 'success',
        'message' => 'Listing cancelled. $75.00 USDT refunded.',
    ]);
});

// ─── Cancel on non-open statuses (8.10) ───────────────────────────────────

test('owner cannot cancel a taken / expired / already-cancelled listing (403)', function (string $state) {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->{$state}()->for($user)->create();

    $this->actingAs($user)
        ->deleteJson("/listings/{$listing->id}/cancel")
        ->assertForbidden();
})->with(['taken', 'expired', 'cancelled']);

// ─── BCMath round-trip (8.11) ─────────────────────────────────────────────

test('cancel restores the balance exactly, with no float drift on awkward amounts', function () {
    // Stake at the column's full precision (decimal(12, 2)). `decimal:0,2`
    // validation pins user-supplied stakes to ≤ 2 decimals; any larger
    // precision would mismatch the column. Round-trip should be exact.
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $stake = '123.45';
    $listing = Listing::factory()->open()->for($user)->state([
        'stake_amount' => $stake,
    ])->create();

    Wallet::hold(
        user: $user,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    $balanceBeforeCancel = $user->fresh()->usdt_balance;

    $this->actingAs($user)->deleteJson("/listings/{$listing->id}/cancel")->assertRedirect();

    $balanceAfter = $user->fresh()->usdt_balance;

    // Exact BCMath equality — not a float compare.
    expect(bccomp($balanceAfter, '500.000000', 6))->toBe(0)
        ->and(bccomp($balanceAfter, $balanceBeforeCancel, 6))->toBe(1);
});
