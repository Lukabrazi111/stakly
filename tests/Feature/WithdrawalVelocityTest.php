<?php

use App\Enums\WithdrawalStatus;
use App\Exceptions\DailyLimitExceededException;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Wallet;
use App\Services\WithdrawalVelocity;
use App\Services\Withdrawals;
use Illuminate\Support\Facades\Queue;

/**
 * Rolling 24-hour withdrawal ceiling (M9 Phase 0f).
 *
 * The backstop for exploits nobody predicted: freeze, KYC, the 2FA step-up and
 * the new-address cooldown each block a KNOWN attack, this bounds the loss from
 * an unknown one.
 *
 * Rolling rather than calendar-day, so a drain can't straddle midnight for
 * double the limit.
 */
const VELOCITY_ADDRESS = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';

beforeEach(function () {
    $this->platform = platformUser();
    Queue::fake();
    // Orthogonal gates, each covered in its own suite.
    config([
        'stakly.withdrawal_require_2fa' => false,
        'stakly.withdrawal_address_cooldown_hours' => 0,
    ]);
});

function velocityUser(string $balance = '50000'): User
{
    $user = User::factory()->create();
    Wallet::deposit($user, $balance, reference: "test:velocity-deposit:{$user->id}");

    return $user->fresh();
}

function priorWithdrawal(User $user, string $amount, WithdrawalStatus $status, string $ago = '-1 hour'): Withdrawal
{
    return Withdrawal::factory()->for($user)->create([
        'amount' => $amount,
        'status' => $status,
        'destination_address' => VELOCITY_ADDRESS,
        'created_at' => new DateTimeImmutable($ago),
    ]);
}

// ============================================================================
// Defaults.
// ============================================================================

test('the cap is enabled by default at 5000', function () {
    expect(WithdrawalVelocity::enabled())->toBeTrue();
    expect(WithdrawalVelocity::limit())->toBe('5000');
});

test('a fresh account has the full allowance', function () {
    expect(WithdrawalVelocity::remainingToday(velocityUser()))->toBe('5000.000000');
});

// ============================================================================
// The ceiling.
// ============================================================================

test('a withdrawal under the ceiling goes through', function () {
    $user = velocityUser();

    Withdrawals::request($user, '4000', VELOCITY_ADDRESS);

    expect($user->fresh()->usdt_balance)->toBe('46000.000000');
});

test('a withdrawal over the ceiling is refused and writes nothing', function () {
    $user = velocityUser();
    $ledgerBefore = $user->walletTransactions()->count();

    expect(fn () => Withdrawals::request($user, '5001', VELOCITY_ADDRESS))
        ->toThrow(DailyLimitExceededException::class);

    expect($user->fresh()->usdt_balance)->toBe('50000.000000');
    expect($user->walletTransactions()->count())->toBe($ledgerBefore);
    expect($user->withdrawals()->count())->toBe(0);
});

test('landing exactly on the ceiling is allowed', function () {
    $user = velocityUser();

    Withdrawals::request($user, '5000', VELOCITY_ADDRESS);

    expect(WithdrawalVelocity::remainingToday($user->fresh()))->toBe('0.000000');
});

test('several withdrawals accumulate toward the same ceiling', function () {
    $user = velocityUser();

    Withdrawals::request($user, '3000', VELOCITY_ADDRESS);
    Withdrawals::request($user->fresh(), '1500', VELOCITY_ADDRESS);

    expect(WithdrawalVelocity::remainingToday($user->fresh()))->toBe('500.000000');

    expect(fn () => Withdrawals::request($user->fresh(), '600', VELOCITY_ADDRESS))
        ->toThrow(DailyLimitExceededException::class);
});

// ============================================================================
// The rolling window.
// ============================================================================

test('withdrawals older than 24h fall out of the window', function () {
    $user = velocityUser();
    priorWithdrawal($user, '4800', WithdrawalStatus::Completed, '-25 hours');

    expect(WithdrawalVelocity::withdrawnLast24h($user->fresh()))->toBe('0.000000');
    expect(WithdrawalVelocity::remainingToday($user->fresh()))->toBe('5000.000000');
});

test('withdrawals inside the window still count', function () {
    $user = velocityUser();
    priorWithdrawal($user, '4800', WithdrawalStatus::Completed, '-23 hours');

    expect(WithdrawalVelocity::withdrawnLast24h($user->fresh()))->toBe('4800.000000');
    expect(fn () => Withdrawals::request($user->fresh(), '300', VELOCITY_ADDRESS))
        ->toThrow(DailyLimitExceededException::class);
});

test('in-flight withdrawals consume the allowance', function () {
    $user = velocityUser();
    priorWithdrawal($user, '3000', WithdrawalStatus::Pending);
    priorWithdrawal($user, '1000', WithdrawalStatus::Sending);

    expect(WithdrawalVelocity::withdrawnLast24h($user->fresh()))->toBe('4000.000000');
});

test('reversed withdrawals do not consume the allowance', function () {
    $user = velocityUser();
    priorWithdrawal($user, '4000', WithdrawalStatus::Rejected);
    priorWithdrawal($user, '4000', WithdrawalStatus::Failed);

    expect(WithdrawalVelocity::withdrawnLast24h($user->fresh()))->toBe('0.000000');
});

test('another users volume does not count', function () {
    $user = velocityUser();
    priorWithdrawal(velocityUser(), '4900', WithdrawalStatus::Completed);

    expect(WithdrawalVelocity::withdrawnLast24h($user))->toBe('0.000000');
});

// ============================================================================
// The switch, and the HTTP surface.
// ============================================================================

test('a limit of zero disables the cap', function () {
    config(['stakly.withdrawal_daily_limit' => '0']);
    $user = velocityUser();

    expect(WithdrawalVelocity::enabled())->toBeFalse();
    expect(WithdrawalVelocity::exceedsDailyLimit($user, '999999'))->toBeFalse();
});

test('the form returns a clean 422 rather than throwing', function () {
    $user = velocityUser();
    priorWithdrawal($user, '4900', WithdrawalStatus::Completed);

    $this->actingAs($user)
        ->post(route('wallet.withdraw.store', ['locale' => 'en']), [
            'amount' => '500',
            'address' => VELOCITY_ADDRESS,
        ])
        ->assertSessionHasErrors('amount');

    expect($user->fresh()->usdt_balance)->toBe('50000.000000');
});

test('the withdraw page ships the remaining allowance', function () {
    $user = velocityUser();
    priorWithdrawal($user, '2000', WithdrawalStatus::Completed);

    $this->actingAs($user)
        ->get(route('wallet.withdraw', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            // JSON-decoded, so a whole number arrives as int — compare loosely
            // rather than pinning the float form.
            ->where('dailyLimit', fn ($v) => (float) $v === 5000.0)
            ->where('dailyRemaining', fn ($v) => (float) $v === 3000.0),
        );
});

test('the page reports null for both when the cap is off', function () {
    config(['stakly.withdrawal_daily_limit' => '0']);

    $this->actingAs(velocityUser())
        ->get(route('wallet.withdraw', ['locale' => 'en']))
        ->assertInertia(fn ($page) => $page
            ->where('dailyLimit', null)
            ->where('dailyRemaining', null),
        );
});

// ============================================================================
// Service-level floor (0f.3) — not just the HTTP layer.
// ============================================================================

test('the service refuses a dust withdrawal even when called directly', function () {
    $user = velocityUser();

    // Above the 0.50 margin but below the 10 USDT minimum: previously this
    // only bounced at the HTTP layer, so a service call slipped through.
    expect(fn () => Withdrawals::request($user, '5', VELOCITY_ADDRESS))
        ->toThrow(InvalidArgumentException::class, 'below the 10 minimum');

    expect($user->fresh()->usdt_balance)->toBe('50000.000000');
});

test('the minimum itself is still allowed', function () {
    $user = velocityUser();

    Withdrawals::request($user, '10', VELOCITY_ADDRESS);

    expect($user->fresh()->usdt_balance)->toBe('49990.000000');
});
