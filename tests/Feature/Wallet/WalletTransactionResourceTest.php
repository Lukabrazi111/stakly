<?php

use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\Wallet;

/*
|--------------------------------------------------------------------------
| WalletTransactionResource — public contract (8.7)
|--------------------------------------------------------------------------
|
| The resource is the only path through which ledger rows reach the
| frontend. Two fields MUST NEVER appear in its payload:
|
|   - `reference_id`: idempotency keys ("listing-create:42") are internal
|     plumbing. Leaking them exposes our naming convention and the row's
|     causal origin.
|   - `user_id`: every endpoint serving this resource is scoped to the
|     auth user, so the FK is redundant; repeating it would invite
|     mistakes if a future generic ledger viewer reused the resource.
|
*/

test('resource payload exposes the whitelisted public fields', function () {
    $user = User::factory()->create();
    $txn = Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}", description: 'Test deposit');

    $array = (new WalletTransactionResource($txn))->toArray(request());

    expect($array)->toHaveKeys([
        'id', 'type', 'amount', 'balance_after',
        'related_listing_id', 'description', 'created_at',
    ]);

    expect($array['id'])->toBe($txn->id)
        ->and($array['type'])->toBe('deposit')
        ->and($array['amount'])->toBe(100.0)
        ->and($array['balance_after'])->toBe(100.0)
        ->and($array['related_listing_id'])->toBeNull()
        ->and($array['description'])->toBe('Test deposit');
});

test('reference_id never appears in the resource payload', function () {
    $user = User::factory()->create();
    $txn = Wallet::deposit($user, '50', reference: "secret-internal-reference:{$user->id}");

    $array = (new WalletTransactionResource($txn))->toArray(request());

    expect($array)->not->toHaveKey('reference_id');
});

test('user_id never appears in the resource payload', function () {
    $user = User::factory()->create();
    $txn = Wallet::deposit($user, '50', reference: "test:deposit:{$user->id}");

    $array = (new WalletTransactionResource($txn))->toArray(request());

    expect($array)->not->toHaveKey('user_id');
});

test('the same omissions hold when the resource is hit via an HTTP endpoint', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '50', reference: "secret-ref-:{$user->id}");

    $response = $this->actingAs($user)->get('/wallet/history');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->missing('transactions.data.0.reference_id')
        ->missing('transactions.data.0.user_id')
    );
});
