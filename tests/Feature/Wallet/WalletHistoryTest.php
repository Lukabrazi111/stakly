<?php

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/*
|--------------------------------------------------------------------------
| Wallet history page (8.6)
|--------------------------------------------------------------------------
|
| Verifies:
|   - pagination at 20 per page
|   - `?filter[type]=` filters by transaction type
|   - invalid filter type silently redirects to clean URL (no 422 wall)
|   - `?page=999` returns an empty page, not 404
|   - the auth user only sees their own ledger rows
|   - rows are ordered newest first
|
*/

test('pagination caps at 20 per page', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '10000', reference: "test:deposit:{$user->id}");

    // 25 small bumps means 26 rows total (the initial deposit + 25 bumps).
    foreach (range(1, 25) as $i) {
        Wallet::deposit($user, '1', reference: "test:bump:{$user->id}:{$i}");
    }

    $this->actingAs($user)
        ->get('/wallet/history')
        ->assertInertia(fn ($page) => $page
            ->component('wallet/history')
            ->has('transactions.data', 20)
            ->where('transactions.meta.total', 26)
        );
});

test('?filter[type] scopes to that transaction type only', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit-1:{$user->id}");
    Wallet::deposit($user, '50', reference: "test:deposit-2:{$user->id}");

    // Make one withdrawal so we have a non-deposit row in the ledger.
    Wallet::withdraw($user, '10', reference: "test:withdraw:{$user->id}");

    $this->actingAs($user)
        ->get('/wallet/history?filter[type]=deposit')
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 2)
            ->where('transactions.data.0.type', WalletTransactionType::Deposit->value)
            ->where('transactions.data.1.type', WalletTransactionType::Deposit->value)
        );
});

test('an unknown ?filter[type] redirects to a clean history URL', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet/history?filter[type]=lottery_winnings')
        ->assertRedirect('/wallet/history');
});

test('?page beyond the last page renders an empty page, not 404', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '10', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->get('/wallet/history?page=999')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transactions.data', 0));
});

test('the auth user only ever sees their own ledger rows', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Wallet::deposit($alice, '100', reference: "test:alice:{$alice->id}");
    Wallet::deposit($bob, '100', reference: "test:bob:{$bob->id}");
    Wallet::deposit($bob, '50', reference: "test:bob:{$bob->id}:2");

    $this->actingAs($alice)
        ->get('/wallet/history')
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.meta.total', 1)
        );
});

test('rows are returned newest first', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit-1:{$user->id}");

    WalletTransaction::query()
        ->where('user_id', $user->id)
        ->update(['created_at' => now()->subHours(2)]);

    $newer = Wallet::deposit($user, '50', reference: "test:deposit-2:{$user->id}");
    $newer->update(['created_at' => now()->subHour()]);

    $this->actingAs($user)
        ->get('/wallet/history')
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $newer->id)
        );
});

test('types prop carries all enum cases for filter-chip rendering', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/wallet/history')
        ->assertInertia(fn ($page) => $page
            ->has('types', 6)
        );
});
