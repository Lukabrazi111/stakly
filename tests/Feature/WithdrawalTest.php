<?php

use App\Enums\WalletTransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\AccountFrozenException;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\ProcessWithdrawal;
use App\Models\Listing;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Payments\Dto\GatewayPayoutStatus;
use App\Services\Payments\Dto\PayoutResult;
use App\Services\Payments\PaymentGateway;
use App\Services\Wallet;
use App\Services\Withdrawals;
use Illuminate\Support\Facades\Queue;

const VALID_ADDRESS = 'TQ5NMqJjW8sBSHfgWLKGdFhhWBnrjrxfnE';

beforeEach(function () {
    $this->platform = platformUser();
});

function fundedUser(string $balance = '500'): User
{
    $user = User::factory()->create();
    Wallet::deposit($user, $balance, reference: "test:deposit:{$user->id}");

    return $user->fresh();
}

/**
 * Swaps the bound gateway for one returning a fixed payout status, so the
 * async provider paths can be exercised without a real provider.
 */
function fakeGatewayReturning(GatewayPayoutStatus $status, ?string $txHash = null, ?string $networkFee = null): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createPayout')->andReturn(
        new PayoutResult(
            providerPayoutId: 'fake-payout-1',
            status: $status,
            txHash: $txHash,
            networkFee: $networkFee,
        )
    );

    app()->instance(PaymentGateway::class, $gateway);
}

/** The ledger invariant that must never break. */
function assertLedgerInvariant(User $user): void
{
    $balance = (string) $user->fresh()->usdt_balance;
    $ledgerSum = (string) $user->walletTransactions()->sum('amount');

    expect(bccomp($balance, $ledgerSum, 6))->toBe(0);
}

// ============================================================================
// request()
// ============================================================================

test('request debits the gross amount and links the ledger row', function () {
    Queue::fake();
    $user = fundedUser('500');

    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
    expect($withdrawal->amount)->toBe('100.000000');
    expect($withdrawal->platform_fee)->toBe('0.500000');
    expect($withdrawal->debit_transaction_id)->not->toBeNull();

    $debit = $withdrawal->debitTransaction;
    expect($debit->type)->toBe(WalletTransactionType::Withdrawal);
    expect($debit->amount)->toBe('-100.000000');
    expect($debit->reference_id)->toBe("wd:{$withdrawal->id}");

    expect(Wallet::balanceFor($user))->toBe('400.000000');
    assertLedgerInvariant($user);
});

test('request queues the payout job', function () {
    Queue::fake();
    $user = fundedUser();

    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    Queue::assertPushed(ProcessWithdrawal::class,
        fn (ProcessWithdrawal $job) => $job->withdrawal->id === $withdrawal->id);
});

test('request is refused for a frozen user and writes nothing', function () {
    Queue::fake();
    $user = fundedUser();
    $user->forceFill(['frozen_at' => now()])->save();

    expect(fn () => Withdrawals::request($user->fresh(), '100', VALID_ADDRESS))
        ->toThrow(AccountFrozenException::class);

    expect(Withdrawal::count())->toBe(0);
    expect(Wallet::balanceFor($user))->toBe('500.000000');
});

test('request is refused above the balance and leaves no orphan row', function () {
    Queue::fake();
    $user = fundedUser('50');

    expect(fn () => Withdrawals::request($user, '100', VALID_ADDRESS))
        ->toThrow(InsufficientBalanceException::class);

    expect(Withdrawal::count())->toBe(0);
    expect(Wallet::balanceFor($user))->toBe('50.000000');
});

test('request is refused against uncleared winnings even when the total balance covers it', function () {
    Queue::fake();
    $user = fundedUser('100');

    // 300 of winnings credited but still inside the insurance window.
    $listing = Listing::factory()->create();
    Wallet::payout($user, '300', $listing, clearsAt: now()->addHours(48));

    expect(Wallet::balanceFor($user))->toBe('400.000000');
    expect(Wallet::availableBalance($user))->toBe('100.000000');

    expect(fn () => Withdrawals::request($user->fresh(), '250', VALID_ADDRESS))
        ->toThrow(InsufficientBalanceException::class);

    expect(Withdrawal::count())->toBe(0);
});

test('request succeeds once the winnings clear', function () {
    Queue::fake();
    $user = fundedUser('100');
    $listing = Listing::factory()->create();
    Wallet::payout($user, '300', $listing, clearsAt: now()->addHours(48));

    $this->travelTo(now()->addHours(49));

    $withdrawal = Withdrawals::request($user->fresh(), '250', VALID_ADDRESS);

    expect($withdrawal->status)->toBe(WithdrawalStatus::Pending);
    expect(Wallet::balanceFor($user))->toBe('150.000000');
});

test('request refuses an amount that cannot cover the platform margin', function () {
    Queue::fake();
    $user = fundedUser();

    expect(fn () => Withdrawals::request($user, '0.25', VALID_ADDRESS))
        ->toThrow(InvalidArgumentException::class);
});

// ============================================================================
// send() — gateway status mapping
// ============================================================================

test('send completes through MockGateway and books the margin once', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    $sent = Withdrawals::send($withdrawal);

    expect($sent->status)->toBe(WithdrawalStatus::Completed);
    expect($sent->tx_hash)->toStartWith('MOCK-');
    expect($sent->network_fee)->toBe('1.500000');
    expect($sent->provider)->toBe('mock');

    expect($this->platform->fresh()->usdt_balance)->toBe('0.500000');

    // Re-sending must not double-book the margin.
    Withdrawals::send($sent->fresh());
    expect($this->platform->fresh()->usdt_balance)->toBe('0.500000');
});

test('send maps a queued provider response to Sending and waits for the webhook', function () {
    Queue::fake();
    fakeGatewayReturning(GatewayPayoutStatus::Queued);

    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    $sent = Withdrawals::send($withdrawal);

    expect($sent->status)->toBe(WithdrawalStatus::Sending);
    expect($sent->provider_payout_id)->toBe('fake-payout-1');
    // Margin is NOT booked until the money actually leaves.
    expect($this->platform->fresh()->usdt_balance)->toBe('0.000000');
});

test('send maps a sending provider response to Sending', function () {
    Queue::fake();
    fakeGatewayReturning(GatewayPayoutStatus::Sending);

    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    expect(Withdrawals::send($withdrawal)->status)->toBe(WithdrawalStatus::Sending);
});

test('send maps a failed provider response to Failed and reverses the debit', function () {
    Queue::fake();
    fakeGatewayReturning(GatewayPayoutStatus::Failed);

    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    expect(Wallet::balanceFor($user))->toBe('400.000000');

    $sent = Withdrawals::send($withdrawal);

    expect($sent->status)->toBe(WithdrawalStatus::Failed);
    expect(Wallet::balanceFor($user))->toBe('500.000000');
    expect($this->platform->fresh()->usdt_balance)->toBe('0.000000');
    assertLedgerInvariant($user);
});

test('send is a no-op on an already-terminal withdrawal', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);
    Withdrawals::reject($withdrawal, 'nope', User::factory()->create());

    $result = Withdrawals::send($withdrawal->fresh());

    expect($result->status)->toBe(WithdrawalStatus::Rejected);
    expect(Wallet::balanceFor($user))->toBe('500.000000');
});

// ============================================================================
// reject() / markFailed()
// ============================================================================

test('reject credits back the exact gross and records the reviewer', function () {
    Queue::fake();
    $user = fundedUser();
    $admin = User::factory()->create();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    $rejected = Withdrawals::reject($withdrawal, 'Suspected collusion', $admin);

    expect($rejected->status)->toBe(WithdrawalStatus::Rejected);
    expect($rejected->rejected_reason)->toBe('Suspected collusion');
    expect($rejected->reviewed_by)->toBe($admin->id);
    expect(Wallet::balanceFor($user))->toBe('500.000000');

    $reversal = $user->walletTransactions()
        ->where('reference_id', "wd-reversal:{$withdrawal->id}")
        ->sole();
    expect($reversal->type)->toBe(WalletTransactionType::WithdrawalReversal);
    expect($reversal->amount)->toBe('100.000000');

    assertLedgerInvariant($user);
});

test('rejecting twice is idempotent and does not pay the user twice', function () {
    Queue::fake();
    $user = fundedUser();
    $admin = User::factory()->create();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    Withdrawals::reject($withdrawal, 'first', $admin);
    Withdrawals::reject($withdrawal->fresh(), 'second', $admin);

    expect(Wallet::balanceFor($user))->toBe('500.000000');
    expect($user->walletTransactions()
        ->where('type', WalletTransactionType::WithdrawalReversal)
        ->count())->toBe(1);
    assertLedgerInvariant($user);
});

test('reject is refused after the money already left', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);
    Withdrawals::send($withdrawal);

    $result = Withdrawals::reject($withdrawal->fresh(), 'too late', User::factory()->create());

    expect($result->status)->toBe(WithdrawalStatus::Completed);
    expect(Wallet::balanceFor($user))->toBe('400.000000');
});

test('a frozen user still gets their money back on rejection', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    $user->forceFill(['frozen_at' => now()])->save();

    Withdrawals::reject($withdrawal, 'frozen mid-flight', User::factory()->create());

    expect(Wallet::balanceFor($user))->toBe('500.000000');
});

test('markFailed reverses the debit and is idempotent', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    Withdrawals::markFailed($withdrawal, 'provider down');
    Withdrawals::markFailed($withdrawal->fresh(), 'provider down again');

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Failed);
    expect(Wallet::balanceFor($user))->toBe('500.000000');
    assertLedgerInvariant($user);
});

test('a completed withdrawal cannot be retroactively completed as reversed', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);
    Withdrawals::markFailed($withdrawal, 'provider down');

    expect(fn () => Withdrawals::markCompleted($withdrawal->fresh(), 'HASH', '1.5'))
        ->toThrow(InvalidArgumentException::class);
});

// ============================================================================
// Conservation.
// ============================================================================

test('money is conserved across request then reject', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);
    Withdrawals::reject($withdrawal, 'nope', User::factory()->create());

    expect(Wallet::balanceFor($user))->toBe('500.000000');
    expect($this->platform->fresh()->usdt_balance)->toBe('0.000000');
    assertLedgerInvariant($user);
});

test('money is conserved across request then send', function () {
    Queue::fake();
    $user = fundedUser();
    Withdrawals::send(Withdrawals::request($user, '100', VALID_ADDRESS));

    // User is down the full gross; the platform holds the margin; the
    // remaining 99.50 left custody toward the destination address.
    expect(Wallet::balanceFor($user))->toBe('400.000000');
    expect($this->platform->fresh()->usdt_balance)->toBe('0.500000');
    assertLedgerInvariant($user);
    assertLedgerInvariant($this->platform);
});

test('the net amount sent excludes the platform margin', function () {
    Queue::fake();
    $user = fundedUser();
    $withdrawal = Withdrawals::request($user, '100', VALID_ADDRESS);

    expect($withdrawal->netAmount())->toBe('99.500000');
});
