<?php

use App\Enums\WithdrawalStatus;
use App\Jobs\ProcessWithdrawal;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalHeldNotification;
use App\Services\Wallet;
use App\Services\WithdrawalAddressCooldown;
use App\Services\Withdrawals;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * New-address cooldown (M9 Phase 0e).
 *
 * Closes the gap the 2FA step-up leaves open: a code proves someone with the
 * device is present, but a phished or coerced code still sends funds wherever
 * the request says. The first payout to a given address waits, and the owner
 * gets told.
 *
 * HELD, not blocked — the debit happens immediately, only the send waits.
 */
const COOLDOWN_ADDRESS = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';
const OTHER_ADDRESS = 'TW9zL4rKpVxCn8dHqYbMfEjGa2sUtNvXhP';

beforeEach(function () {
    $this->platform = platformUser();
    Queue::fake();
    Notification::fake();
});

function cooldownUser(string $balance = '5000'): User
{
    $user = User::factory()->create();
    Wallet::deposit($user, $balance, reference: "test:cooldown-deposit:{$user->id}");

    return $user->fresh();
}

// ============================================================================
// The hold itself.
// ============================================================================

test('the first withdrawal to an address is held', function () {
    $user = cooldownUser();

    $withdrawal = Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    expect($withdrawal->hold_until)->not->toBeNull();
    expect($withdrawal->isHeld())->toBeTrue();
    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
});

test('a held withdrawal still debits immediately', function () {
    $user = cooldownUser();

    Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    // The balance moves now so it can't be spent twice; only the send waits.
    expect($user->fresh()->usdt_balance)->toBe('4900.000000');
});

test('the payout job is delayed to match the hold', function () {
    $user = cooldownUser();

    $withdrawal = Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    Queue::assertPushed(ProcessWithdrawal::class, function ($job) use ($withdrawal) {
        return $job->withdrawal->id === $withdrawal->id
            && $job->delay?->getTimestamp() === $withdrawal->hold_until?->getTimestamp();
    });
});

test('the owner is notified so they can react', function () {
    $user = cooldownUser();

    Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    Notification::assertSentTo($user, WithdrawalHeldNotification::class);
});

// ============================================================================
// A known address goes straight out.
// ============================================================================

test('a repeat withdrawal to the same address is not held', function () {
    $user = cooldownUser();

    // First one completes, which is what makes the address trusted.
    $first = Withdrawals::request($user, '100', COOLDOWN_ADDRESS);
    $first->forceFill([
        'status' => WithdrawalStatus::Completed,
        'hold_until' => now()->subHour(),
    ])->save();

    $second = Withdrawals::request($user->fresh(), '100', COOLDOWN_ADDRESS);

    expect($second->hold_until)->toBeNull();
    expect($second->isHeld())->toBeFalse();
});

test('a different address is held even once another is trusted', function () {
    $user = cooldownUser();

    Withdrawal::factory()->for($user)->create([
        'destination_address' => COOLDOWN_ADDRESS,
        'status' => WithdrawalStatus::Completed,
        'hold_until' => null,
    ]);

    expect(Withdrawals::request($user, '100', OTHER_ADDRESS)->hold_until)->not->toBeNull();
});

test('no notification fires when the address is already trusted', function () {
    $user = cooldownUser();

    Withdrawal::factory()->for($user)->create([
        'destination_address' => COOLDOWN_ADDRESS,
        'status' => WithdrawalStatus::Completed,
        'hold_until' => null,
    ]);

    Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    Notification::assertNothingSent();
});

// ============================================================================
// The bypass this is built to resist.
// ============================================================================

test('a still-held decoy does not make its address trusted', function () {
    $user = cooldownUser();

    // Attacker sends the smallest allowed amount to their own address to
    // "warm it up", then immediately tries to drain to the same place.
    $decoy = Withdrawals::request($user, '10', COOLDOWN_ADDRESS);
    expect($decoy->isHeld())->toBeTrue();

    $drain = Withdrawals::request($user->fresh(), '4000', COOLDOWN_ADDRESS);

    expect($drain->hold_until)->not->toBeNull();
    expect($drain->isHeld())->toBeTrue();
});

test('a reversed withdrawal never makes its address trusted', function () {
    $user = cooldownUser();

    Withdrawal::factory()->for($user)->create([
        'destination_address' => COOLDOWN_ADDRESS,
        'status' => WithdrawalStatus::Rejected,
        'hold_until' => null,
    ]);
    Withdrawal::factory()->for($user)->create([
        'destination_address' => COOLDOWN_ADDRESS,
        'status' => WithdrawalStatus::Failed,
        'hold_until' => null,
    ]);

    expect(WithdrawalAddressCooldown::isKnownAddress($user->fresh(), COOLDOWN_ADDRESS))
        ->toBeFalse();
});

test('another users history does not make an address trusted', function () {
    $user = cooldownUser();
    $stranger = cooldownUser();

    Withdrawal::factory()->for($stranger)->create([
        'destination_address' => COOLDOWN_ADDRESS,
        'status' => WithdrawalStatus::Completed,
        'hold_until' => null,
    ]);

    expect(WithdrawalAddressCooldown::isKnownAddress($user, COOLDOWN_ADDRESS))->toBeFalse();
});

// ============================================================================
// The switch, and interaction with admin action.
// ============================================================================

test('a cooldown of zero disables the hold', function () {
    config(['stakly.withdrawal_address_cooldown_hours' => 0]);
    $user = cooldownUser();

    expect(Withdrawals::request($user, '100', COOLDOWN_ADDRESS)->hold_until)->toBeNull();
    Notification::assertNothingSent();
});

test('a held withdrawal can still be rejected, crediting the full gross back', function () {
    $user = cooldownUser();
    $admin = User::factory()->create();

    $withdrawal = Withdrawals::request($user, '100', COOLDOWN_ADDRESS);
    expect($user->fresh()->usdt_balance)->toBe('4900.000000');

    Withdrawals::reject($withdrawal, 'Player reported it was not them.', $admin);

    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Rejected);
});

test('the delayed job is a no-op once the withdrawal was rejected', function () {
    $user = cooldownUser();
    $admin = User::factory()->create();

    $withdrawal = Withdrawals::request($user, '100', COOLDOWN_ADDRESS);
    Withdrawals::reject($withdrawal, 'Not the owner.', $admin);

    // The job fires when the hold elapses; `send()` bails on terminal statuses.
    Withdrawals::send($withdrawal->fresh());

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Rejected);
    expect($user->fresh()->usdt_balance)->toBe('5000.000000');
});

test('the ledger invariant holds across a held withdrawal', function () {
    $user = cooldownUser();

    Withdrawals::request($user, '100', COOLDOWN_ADDRESS);

    expect((string) $user->fresh()->usdt_balance)
        ->toBe(number_format((float) $user->walletTransactions()->sum('amount'), 6, '.', ''));
});
