<?php

use App\Enums\ListingStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/**
 * Helper: create a verified user with a held listing whose expiry is overridable.
 */
function userWithExpiringListing(string $stake = '100', ?DateTimeInterface $expiresAt = null): array
{
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->open()->for($user)->state([
        'stake_amount' => $stake,
        'expires_at' => $expiresAt ?? now()->subMinute(),
    ])->create();

    Wallet::hold(
        user: $user,
        amount: $stake,
        listing: $listing,
        reference: "listing-create:{$listing->id}",
    );

    return [$user, $listing];
}

// ─── Happy path ───────────────────────────────────────────────────────────

test('expires an open listing past its expiry and refunds the stake', function () {
    [$user, $listing] = userWithExpiringListing(stake: '100');

    // Hold reduced balance to 400 before the command runs.
    expect((string) $user->fresh()->usdt_balance)->toBe('400.000000');

    $this->artisan('listings:expire')->assertSuccessful();

    expect($listing->fresh()->status)->toBe(ListingStatus::Expired);
    expect((string) $user->fresh()->usdt_balance)->toBe('500.000000');

    $release = WalletTransaction::query()
        ->where('reference_id', "listing-expire:{$listing->id}")
        ->firstOrFail();

    expect($release->type)->toBe(WalletTransactionType::EscrowRelease)
        ->and($release->amount)->toBe('100.000000')
        ->and($release->related_listing_id)->toBe($listing->id);
});

// ─── Skip paths: still-open, non-open status, taken ───────────────────────

test('leaves open listings whose expires_at is still in the future alone', function () {
    [$user, $listing] = userWithExpiringListing(
        stake: '100',
        expiresAt: now()->addHours(2),
    );

    $this->artisan('listings:expire')->assertSuccessful();

    expect($listing->fresh()->status)->toBe(ListingStatus::Open);
    expect((string) $user->fresh()->usdt_balance)->toBe('400.000000');
    expect(
        WalletTransaction::where('reference_id', "listing-expire:{$listing->id}")->count()
    )->toBe(0);
});

test('ignores listings whose status is not Open, even if expires_at is past', function (string $state) {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->{$state}()->for($user)->state([
        'stake_amount' => '100',
        'expires_at' => now()->subHour(),
    ])->create();

    $balanceBefore = (string) $user->fresh()->usdt_balance;

    $this->artisan('listings:expire')->assertSuccessful();

    expect((string) $user->fresh()->usdt_balance)->toBe($balanceBefore);
    expect($listing->fresh()->status->value)->toBe($state);
    expect(
        WalletTransaction::where('reference_id', "listing-expire:{$listing->id}")->count()
    )->toBe(0);
})->with(['cancelled', 'expired', 'taken']);

// ─── Idempotency ──────────────────────────────────────────────────────────

test('re-running on the same expired listing does not double-refund', function () {
    [$user, $listing] = userWithExpiringListing(stake: '100');

    $this->artisan('listings:expire')->assertSuccessful();
    expect((string) $user->fresh()->usdt_balance)->toBe('500.000000');

    // Force the listing back to Open so the SELECT picks it up again.
    // The inside-lock recheck protects against any wider weirdness; here we
    // verify the wallet idempotency reference holds even if the row resurfaces.
    $listing->update([
        'status' => ListingStatus::Open,
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('listings:expire')->assertSuccessful();

    // Balance unchanged — the second Wallet::release call returned the existing
    // row silently (no second credit).
    expect((string) $user->fresh()->usdt_balance)->toBe('500.000000');
    expect(
        WalletTransaction::where('reference_id', "listing-expire:{$listing->id}")->count()
    )->toBe(1);

    // Status flipped back to Expired on the second pass.
    expect($listing->fresh()->status)->toBe(ListingStatus::Expired);
});

// ─── Limit + multi-user batching ──────────────────────────────────────────

test('respects --limit flag — processes at most N rows per run', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '5000', reference: "test:deposit:{$user->id}");

    foreach (range(1, 5) as $i) {
        $listing = Listing::factory()->open()->for($user)->state([
            'stake_amount' => '100',
            'expires_at' => now()->subMinute(),
        ])->create();

        Wallet::hold(
            user: $user,
            amount: '100',
            listing: $listing,
            reference: "listing-create:{$listing->id}",
        );
    }

    $this->artisan('listings:expire', ['--limit' => 2])->assertSuccessful();

    expect(Listing::where('user_id', $user->id)->where('status', ListingStatus::Expired)->count())->toBe(2);
    expect(Listing::where('user_id', $user->id)->where('status', ListingStatus::Open)->count())->toBe(3);
});

test('handles multiple expired listings across multiple users in one run', function () {
    $alice = User::factory()->create();
    Wallet::deposit($alice, '500', reference: "test:deposit:{$alice->id}");

    $bob = User::factory()->create();
    Wallet::deposit($bob, '500', reference: "test:deposit:{$bob->id}");

    $aliceListing = Listing::factory()->open()->for($alice)->state([
        'stake_amount' => '100',
        'expires_at' => now()->subMinute(),
    ])->create();
    Wallet::hold(user: $alice, amount: '100', listing: $aliceListing, reference: "listing-create:{$aliceListing->id}");

    $bobListing = Listing::factory()->open()->for($bob)->state([
        'stake_amount' => '200',
        'expires_at' => now()->subMinute(),
    ])->create();
    Wallet::hold(user: $bob, amount: '200', listing: $bobListing, reference: "listing-create:{$bobListing->id}");

    $this->artisan('listings:expire')->assertSuccessful();

    expect((string) $alice->fresh()->usdt_balance)->toBe('500.000000');
    expect((string) $bob->fresh()->usdt_balance)->toBe('500.000000');
    expect($aliceListing->fresh()->status)->toBe(ListingStatus::Expired);
    expect($bobListing->fresh()->status)->toBe(ListingStatus::Expired);
});

// ─── Conservation invariant ───────────────────────────────────────────────

test('full create → hold → expire cycle nets to zero for the user', function () {
    [$user, $listing] = userWithExpiringListing(stake: '150');

    $this->artisan('listings:expire')->assertSuccessful();

    // Sum of all match-flow ledger rows for the user is zero (deposits excluded).
    $matchFlowSum = WalletTransaction::query()
        ->where('user_id', $user->id)
        ->whereIn('type', [
            WalletTransactionType::EscrowHold,
            WalletTransactionType::EscrowRelease,
        ])
        ->sum('amount');

    expect(bccomp((string) $matchFlowSum, '0', 6))->toBe(0);
    expect((string) $user->fresh()->usdt_balance)->toBe('500.000000');
});
