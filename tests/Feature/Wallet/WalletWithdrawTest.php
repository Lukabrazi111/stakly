<?php

use App\Enums\WithdrawalStatus;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Jobs\ProcessWithdrawal;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Wallet;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Wallet withdraw — form + endpoint (M9 Phase 0b)
|--------------------------------------------------------------------------
|
| The withdraw form validates fully (TRC20 regex, min, ≤ AVAILABLE balance,
| decimal:0,2). Since Phase 0b the POST handler is real: it debits the user
| through `Withdrawals::request` and queues the payout. There is no admin
| approval gate — the anti-abuse hold lives on the payout's insurance window
| (see PayoutClearanceTest), so anything withdrawable has already cleared.
|
| Test addresses are real-shape TRC20 strings (T + 33 base58 chars). The
| validator does NOT verify the checksum byte — that lives in the payout
| provider's own validation.
|
*/

// Hand-crafted valid base58 TRC20 shape: T + 33 chars from the alphabet
// (no `0`, `O`, `I`, `l`). Doesn't decode to a real address (no checksum
// byte) — just satisfies WithdrawRequest::TRC20_REGEX.
const VALID_TRC20 = 'T123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
const INVALID_TRC20 = 'NotATronAddress';

test('form page exposes balance and the configured minimum withdrawal', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '200', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)->get('/wallet/withdraw');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('wallet/withdraw')
        // JSON round-trip loses the int/float distinction — assert with the
        // integer literal that JSON decoding actually produces.
        ->where('balance', 200)
        // Compared numerically, not identically: a whole-number float survives
        // the JSON round-trip as an int, so `10.0` comes back as `10`.
        ->where('minWithdrawal', fn ($value) => (float) $value === (float) WithdrawRequest::minWithdrawal())
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

test('amount above the AVAILABLE balance fails validation even when the total covers it', function () {
    platformUser();
    $user = User::factory()->create();
    Wallet::deposit($user, '20', reference: "test:deposit:{$user->id}");

    $listing = Listing::factory()->create();
    Wallet::payout($user, '300', $listing, clearsAt: now()->addHours(48));

    $this->actingAs($user)
        ->postJson('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 100,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

test('valid submission debits the user and creates a pending withdrawal', function () {
    Queue::fake();
    platformUser();
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");

    $response = $this->actingAs($user)
        ->from('/wallet/withdraw')
        ->post('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 50,
        ]);

    $response->assertRedirect('/wallet/withdraw');

    $withdrawal = Withdrawal::where('user_id', $user->id)->sole();
    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
    expect($withdrawal->amount)->toBe('50.000000');
    expect($withdrawal->destination_address)->toBe(VALID_TRC20);

    expect((string) $user->fresh()->usdt_balance)->toBe('50.000000');

    // The ledger debit and the withdrawal row must agree.
    $debit = WalletTransaction::where('reference_id', "wd:{$withdrawal->id}")->sole();
    expect($debit->amount)->toBe('-50.000000');

    Queue::assertPushed(ProcessWithdrawal::class);
});

test('valid submission flashes a success toast', function () {
    Queue::fake();
    platformUser();
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
            'type' => 'success',
            'message' => 'Withdrawal requested — it is on its way.',
        ]);
});

test('a frozen user cannot withdraw', function () {
    Queue::fake();
    platformUser();
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: "test:deposit:{$user->id}");
    $user->forceFill(['frozen_at' => now()])->save();

    $this->actingAs($user->fresh())
        ->postJson('/wallet/withdraw', [
            'address' => VALID_TRC20,
            'amount' => 50,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);

    expect(Withdrawal::count())->toBe(0);
    expect((string) $user->fresh()->usdt_balance)->toBe('100.000000');
});

test('the withdrawals page lists the user own withdrawals only', function () {
    platformUser();
    $user = User::factory()->create();
    $other = User::factory()->create();

    Withdrawal::factory()->completed()->for($user)->create();
    Withdrawal::factory()->completed()->for($other)->create();

    $this->actingAs($user)
        ->get('/wallet/withdrawals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('wallet/withdrawals')
            ->has('withdrawals.data', 1)
        );
});

test('the withdrawal payload never leaks internal plumbing', function () {
    platformUser();
    $user = User::factory()->create();
    Withdrawal::factory()->completed()->for($user)->create();

    $this->actingAs($user)
        ->get('/wallet/withdrawals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('withdrawals.data.0', fn ($row) => $row
                ->missing('user_id')
                ->missing('debit_transaction_id')
                ->missing('provider_payout_id')
                ->missing('provider')
                ->missing('reviewed_by')
                ->etc()
            )
        );
});
