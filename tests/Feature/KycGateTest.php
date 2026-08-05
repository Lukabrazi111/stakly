<?php

use App\Enums\KycStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\KycRequiredException;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\KycGate;
use App\Services\Wallet;
use App\Services\Withdrawals;
use Illuminate\Support\Facades\Queue;

/**
 * Optional identity verification (M9 Phase 0c).
 *
 * OFF BY DEFAULT — the first test is the important one: with the switch in its
 * shipped state, nothing about withdrawals changes. Everything below only
 * describes behaviour once an operator explicitly turns it on.
 *
 * It is a tiered VOLUME gate, not an all-users wall, because verification is
 * admin-driven and there is no self-serve flow — a blanket gate would brick
 * cash-out for every player the moment it was enabled.
 */
const KYC_ADDRESS = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';

beforeEach(function () {
    $this->platform = platformUser();
    Queue::fake();
    // Orthogonal gate (Phase 0d), on by default and covered in its own suite —
    // off here so a missing TOTP code can't be mistaken for a KYC refusal.
    config(['stakly.withdrawal_require_2fa' => false]);
});

function kycUser(string $balance = '5000', KycStatus $status = KycStatus::Unverified): User
{
    $user = User::factory()->create(['kyc_status' => $status]);
    Wallet::deposit($user, $balance, reference: "test:kyc-deposit:{$user->id}");

    return $user->fresh();
}

function enableKyc(string $threshold = '1000'): void
{
    config(['stakly.kyc_enabled' => true, 'stakly.kyc_threshold' => $threshold]);
}

// ============================================================================
// Default posture — the switch is off.
// ============================================================================

test('kyc is disabled by default', function () {
    expect(config('stakly.kyc_enabled'))->toBeFalse();
    expect(KycGate::enabled())->toBeFalse();
});

test('an unverified user withdraws freely while the gate is off', function () {
    $user = kycUser();

    expect(KycGate::requiresVerification($user, '4000'))->toBeFalse();

    $withdrawal = Withdrawals::request($user, '4000', KYC_ADDRESS);

    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
    expect($user->fresh()->usdt_balance)->toBe('1000.000000');
});

test('new users default to unverified', function () {
    expect(User::factory()->create()->kyc_status)->toBe(KycStatus::Unverified);
});

// ============================================================================
// Threshold behaviour once enabled.
// ============================================================================

test('below the threshold nothing is asked', function () {
    enableKyc('1000');

    expect(KycGate::requiresVerification(kycUser(), '999'))->toBeFalse();
});

test('crossing the threshold requires verification', function () {
    enableKyc('1000');

    expect(KycGate::requiresVerification(kycUser(), '1001'))->toBeTrue();
});

test('landing exactly on the threshold is allowed', function () {
    enableKyc('1000');

    expect(KycGate::requiresVerification(kycUser(), '1000'))->toBeFalse();
});

test('a verified account is never gated', function () {
    enableKyc('1000');
    $user = kycUser(status: KycStatus::Verified);

    expect(KycGate::requiresVerification($user, '5000'))->toBeFalse();

    Withdrawals::request($user, '4000', KYC_ADDRESS);

    expect($user->fresh()->usdt_balance)->toBe('1000.000000');
});

test('pending review does not satisfy the gate', function () {
    enableKyc('1000');

    expect(KycGate::requiresVerification(kycUser(status: KycStatus::Pending), '2000'))->toBeTrue();
})->with([KycStatus::Pending, KycStatus::Rejected]);

test('a zero threshold gates every withdrawal', function () {
    enableKyc('0');

    expect(KycGate::requiresVerification(kycUser(), '1'))->toBeTrue();
});

// ============================================================================
// Volume tally — the concurrency hole.
// ============================================================================

test('prior completed withdrawals count toward the threshold', function () {
    enableKyc('1000');
    $user = kycUser();

    Withdrawal::factory()->for($user)->create([
        'amount' => '900',
        'status' => WithdrawalStatus::Completed,
    ]);

    expect(KycGate::lifetimeWithdrawn($user->fresh()))->toBe('900.000000');
    expect(KycGate::requiresVerification($user->fresh(), '200'))->toBeTrue();
});

test('in-flight withdrawals count too, so a large cash-out cannot be split', function () {
    enableKyc('1000');
    $user = kycUser();

    // Two requests of 600 each are individually under the 1000 line. If only
    // Completed counted, both would pass and 1200 would leave unverified.
    Withdrawals::request($user, '600', KYC_ADDRESS);

    expect(fn () => Withdrawals::request($user->fresh(), '600', KYC_ADDRESS))
        ->toThrow(KycRequiredException::class);

    expect($user->fresh()->usdt_balance)->toBe('4400.000000');
});

test('reversed withdrawals do not count — the money came back', function () {
    enableKyc('1000');
    $user = kycUser();

    Withdrawal::factory()->for($user)->create([
        'amount' => '900',
        'status' => WithdrawalStatus::Rejected,
    ]);
    Withdrawal::factory()->for($user)->create([
        'amount' => '900',
        'status' => WithdrawalStatus::Failed,
    ]);

    expect(KycGate::lifetimeWithdrawn($user->fresh()))->toBe('0.000000');
    expect(KycGate::requiresVerification($user->fresh(), '500'))->toBeFalse();
});

// ============================================================================
// Enforcement + the money invariant.
// ============================================================================

test('the service refuses an over-threshold withdrawal and writes nothing', function () {
    enableKyc('1000');
    $user = kycUser();
    $ledgerCountBefore = $user->walletTransactions()->count();

    expect(fn () => Withdrawals::request($user, '2000', KYC_ADDRESS))
        ->toThrow(KycRequiredException::class);

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
    expect($user->walletTransactions()->count())->toBe($ledgerCountBefore);
    expect($user->withdrawals()->count())->toBe(0);
});

test('the ledger invariant survives a refused withdrawal', function () {
    enableKyc('1000');
    $user = kycUser();

    try {
        Withdrawals::request($user, '2000', KYC_ADDRESS);
    } catch (KycRequiredException) {
        // expected
    }

    expect((string) $user->fresh()->usdt_balance)
        ->toBe(number_format((float) $user->walletTransactions()->sum('amount'), 6, '.', ''));
});

test('verifying a blocked user unblocks the same withdrawal', function () {
    enableKyc('1000');
    $user = kycUser();

    expect(fn () => Withdrawals::request($user, '2000', KYC_ADDRESS))
        ->toThrow(KycRequiredException::class);

    $user->forceFill(['kyc_status' => KycStatus::Verified, 'kyc_verified_at' => now()])->save();

    $withdrawal = Withdrawals::request($user->fresh(), '2000', KYC_ADDRESS);

    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
    expect($user->fresh()->usdt_balance)->toBe('3000.000000');
});

// ============================================================================
// HTTP layer — a foreseeable refusal is a 422, never a 500.
// ============================================================================

test('the withdraw form returns a clean 422 rather than throwing', function () {
    enableKyc('1000');
    $user = kycUser();

    $this->actingAs($user)
        ->from(route('wallet.withdraw', ['locale' => 'en']))
        ->post(route('wallet.withdraw.store', ['locale' => 'en']), [
            'amount' => '2000',
            'address' => KYC_ADDRESS,
        ])
        ->assertSessionHasErrors('amount');

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
});

test('an under-threshold withdrawal still posts fine', function () {
    enableKyc('1000');
    $user = kycUser();

    $this->actingAs($user)
        ->post(route('wallet.withdraw.store', ['locale' => 'en']), [
            'amount' => '500',
            'address' => KYC_ADDRESS,
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->usdt_balance)->toBe('4500.000000');
});
