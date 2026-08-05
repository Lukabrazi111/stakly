<?php

use App\Models\User;
use App\Services\Wallet;
use App\Services\WithdrawalTwoFactor;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA step-up on withdrawal (M9 Phase 0d) — ON by default.
 *
 * Step-up rather than a prerequisite: a fresh code is required on EVERY
 * withdrawal, so a hijacked live session can't drain the balance even though it
 * already cleared 2FA at login.
 *
 * Enforced at the HTTP boundary, so `Withdrawals::request()` is untouched — a
 * TOTP code is a credential that only exists in a request context, and seeders
 * / admin-initiated withdrawals have none to present. These tests therefore go
 * through the route, not the service.
 */
const TFA_ADDRESS = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';

beforeEach(function () {
    $this->platform = platformUser();
    Queue::fake();
});

function withdrawer(bool $enrolled = true, string $balance = '5000'): User
{
    $attributes = [];

    if ($enrolled) {
        $attributes = [
            'two_factor_secret' => encrypt((new Google2FA)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['valid-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ];
    }

    $user = User::factory()->create($attributes);
    Wallet::deposit($user, $balance, reference: "test:tfa-deposit:{$user->id}");

    return $user->fresh();
}

function currentCodeFor(User $user): string
{
    return (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
}

function postWithdrawal(User $user, array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->post(
        route('wallet.withdraw.store', ['locale' => 'en']),
        array_merge([
            'amount' => '100',
            'address' => TFA_ADDRESS,
        ], $overrides),
    );
}

// ============================================================================
// Default posture.
// ============================================================================

test('the gate is on by default', function () {
    expect(config('stakly.withdrawal_require_2fa'))->toBeTrue();
    expect(WithdrawalTwoFactor::enabled())->toBeTrue();
});

test('a valid code lets the withdrawal through', function () {
    $user = withdrawer();

    postWithdrawal($user, ['two_factor_code' => currentCodeFor($user)])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->usdt_balance)->toBe('4900.000000');
    expect($user->withdrawals()->count())->toBe(1);
});

// ============================================================================
// Refusals — none may move money.
// ============================================================================

test('a missing code is refused', function () {
    $user = withdrawer();

    postWithdrawal($user)->assertSessionHasErrors('two_factor_code');

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
    expect($user->withdrawals()->count())->toBe(0);
});

test('a wrong code is refused', function () {
    $user = withdrawer();

    postWithdrawal($user, ['two_factor_code' => '000000'])
        ->assertSessionHasErrors('two_factor_code');

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
});

test('a replayed code is refused the second time', function () {
    $user = withdrawer();
    $code = currentCodeFor($user);

    postWithdrawal($user, ['two_factor_code' => $code])->assertSessionHasNoErrors();
    postWithdrawal($user->fresh(), ['two_factor_code' => $code])
        ->assertSessionHasErrors('two_factor_code');

    // Only the first withdrawal exists — the replay moved nothing.
    expect($user->fresh()->withdrawals()->count())->toBe(1);
    expect($user->fresh()->usdt_balance)->toBe('4900.000000');
});

test('a user without 2FA set up is told to enrol, not asked for a code', function () {
    $user = withdrawer(enrolled: false);

    postWithdrawal($user, ['two_factor_code' => '123456'])
        ->assertSessionHasErrors('two_factor_code');

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
    expect($user->withdrawals()->count())->toBe(0);
});

test('another users code does not work', function () {
    $user = withdrawer();
    $other = withdrawer();

    postWithdrawal($user, ['two_factor_code' => currentCodeFor($other)])
        ->assertSessionHasErrors('two_factor_code');

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
});

// ============================================================================
// The switch.
// ============================================================================

test('turning the gate off restores code-free withdrawals', function () {
    config(['stakly.withdrawal_require_2fa' => false]);
    $user = withdrawer(enrolled: false);

    postWithdrawal($user)->assertSessionHasNoErrors();

    expect($user->fresh()->usdt_balance)->toBe('4900.000000');
});

// ============================================================================
// Ordering — a one-shot code must not be burned on a doomed request.
// ============================================================================

test('an invalid amount is reported without consuming the code', function () {
    $user = withdrawer();
    $code = currentCodeFor($user);

    // Over balance: the amount rule fails, and the 2FA check must not run.
    postWithdrawal($user, ['amount' => '999999', 'two_factor_code' => $code])
        ->assertSessionHasErrors('amount');

    // The same code still works once the amount is fixed.
    postWithdrawal($user, ['amount' => '100', 'two_factor_code' => $code])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->usdt_balance)->toBe('4900.000000');
});

// ============================================================================
// Page props drive which form the player sees.
// ============================================================================

test('the withdraw page reports whether a code is needed', function () {
    $this->actingAs(withdrawer())
        ->get(route('wallet.withdraw', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('twoFactorRequired', true)
            ->where('twoFactorEnrolled', true),
        );
});

test('the withdraw page flags an unenrolled player', function () {
    $this->actingAs(withdrawer(enrolled: false))
        ->get(route('wallet.withdraw', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('twoFactorRequired', true)
            ->where('twoFactorEnrolled', false),
        );
});
