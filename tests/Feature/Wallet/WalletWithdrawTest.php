<?php

use App\Http\Requests\Wallet\WithdrawRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;

/*
|--------------------------------------------------------------------------
| Wallet withdraw — form + v1 noop (8.4, 8.5)
|--------------------------------------------------------------------------
|
| The withdraw form validates fully (TRC20 regex, min 10, ≤ balance,
| decimal:0,2) so all 422 paths are exercisable today. The POST handler
| short-circuits in v1 — no ledger row, balance unchanged, just a flash
| notice that the launch worker will replace.
|
| Test addresses are real-shape TRC20 strings (T + 33 base58 chars). The
| validator does NOT verify the checksum byte — that lives in the
| pre-launch withdrawal worker.
|
*/

// Hand-crafted valid base58 TRC20 shape: T + 33 chars from the alphabet
// (no `0`, `O`, `I`, `l`). Doesn't decode to a real address (no checksum
// byte) — just satisfies WithdrawRequest::TRC20_REGEX.
const VALID_TRC20 = 'T123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
const INVALID_TRC20 = 'NotATronAddress';

test('form page exposes balance and minimum-withdrawal constant', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '200', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/wallet/withdraw');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('wallet/withdraw')
        // JSON round-trip loses the int/float distinction — assert with the
        // integer literal that JSON decoding actually produces.
        ->where('balance', 200)
        ->where('minWithdrawal', WithdrawRequest::MIN_WITHDRAWAL)
    );
});

test('bad TRC20 address fails validation', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [
            'address' => INVALID_TRC20,
            'amount' => 50,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['address']);
});

test('amount above the user balance fails validation', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '20', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 50,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('amount below the minimum-withdrawal floor fails validation', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 5,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('amount with more than 2 decimals fails validation', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => '50.123',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('missing fields fail validation', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['address', 'amount']);
});

test('valid submission redirects back, writes no ledger row, and leaves the balance unchanged', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $ledgerCountBefore = WalletTransaction::where('user_id', $user->id)->count();

    $response = $this->actingAs($user)
        ->from('/wallet/withdraw')
        ->post('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 50,
        ]);

    $response->assertRedirect('/wallet/withdraw');

    expect(WalletTransaction::where('user_id', $user->id)->count())->toBe($ledgerCountBefore);
    expect((string) $user->fresh()->usdt_balance)->toBe('100.000000');
});

test('valid submission flashes the launch-gated info toast', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    // Form-style `post()` (not `postJson`) so `back()` resolves to a real
    // RedirectResponse and the Inertia flash data persists through the
    // response cycle. `postJson` returns a JsonResponse which doesn't
    // travel through the same flash-preservation path.
    $this->actingAs($user)
        ->from('/wallet/withdraw')
        ->post('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 50,
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'info',
            'message' => 'Withdrawals will be enabled at launch — your balance is safe.',
        ]);
});
