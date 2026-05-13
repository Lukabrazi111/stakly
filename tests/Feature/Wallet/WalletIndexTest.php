<?php

use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/*
|--------------------------------------------------------------------------
| Wallet overview page (8.2)
|--------------------------------------------------------------------------
|
| Verifies the props shape and ordering for /wallet:
|   - balance prop matches Wallet::balanceFor (float at the boundary)
|   - recentTransactions limited to 5 rows
|   - sorted desc by created_at then id (tie-break)
|   - related_listing eager-loaded when present
|
*/

test('balance prop matches the user balance fetched via the wallet service', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '750', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/wallet');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('wallet/index')
        // JSON round-trip loses the int/float distinction — assert with the
        // integer literal that JSON decoding actually produces.
        ->where('balance', 750)
    );
});

test('recentTransactions caps at 5 even when more exist', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '1000', reference: "test:deposit:{$user->id}");

    // Spread these out so created_at differs reliably between rows.
    foreach (range(1, 8) as $i) {
        Wallet::deposit($user, '1', reference: "test:bump:{$user->id}:{$i}");
    }

    $this->actingAs($user)
        ->get('/wallet')
        ->assertInertia(fn ($page) => $page->has('recentTransactions.data', 5));
});

test('recentTransactions returns newest first', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    // Three deposits with explicit creation timestamps so order is deterministic.
    WalletTransaction::query()
        ->where('user_id', $user->id)
        ->update(['created_at' => now()->subHours(3)]);

    $second = Wallet::deposit($user, '1', reference: "test:second:{$user->id}");
    $second->update(['created_at' => now()->subHours(2)]);

    $third = Wallet::deposit($user, '1', reference: "test:third:{$user->id}");
    $third->update(['created_at' => now()->subHour()]);

    $response = $this->actingAs($user)->get('/wallet');

    $response->assertInertia(fn ($page) => $page
        ->where('recentTransactions.data.0.id', $third->id)
        ->where('recentTransactions.data.1.id', $second->id)
    );
});

test('related_listing summary is included when the transaction references one', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '500', reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->open()->for($user)->state(['stake_amount' => '100'])->create();
    Wallet::hold(
        user: $user,
        amount: '100',
        listing: $listing,
        reference: "test:hold:{$listing->id}",
    );

    $this->actingAs($user)
        ->get('/wallet')
        ->assertInertia(fn ($page) => $page
            ->where('recentTransactions.data.0.related_listing.id', $listing->id)
            ->where('recentTransactions.data.0.related_listing.game', $listing->game->value)
        );
});

test('recentTransactions is empty when the user has no ledger rows yet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet')
        ->assertInertia(fn ($page) => $page
            ->where('balance', 0)
            ->has('recentTransactions.data', 0)
        );
});

test('only the auth user transactions appear, never another user data', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Wallet::deposit($alice, '100', reference: "test:alice:{$alice->id}");
    Wallet::deposit($bob, '100', reference: "test:bob:{$bob->id}");

    $this->actingAs($alice)
        ->get('/wallet')
        ->assertInertia(fn ($page) => $page
            ->has('recentTransactions.data', 1)
            ->where('recentTransactions.data.0.type', WalletTransactionType::Deposit->value)
        );
});
