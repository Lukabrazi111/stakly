<?php

use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Tests for fee() + the conservation flow need the platform user to exist.
    // Cheap to seed in every test even when unused — keeps the test setup uniform.
    $this->platform = User::factory()->create([
        'is_platform' => true,
        'email' => 'platform@stakly.internal',
        'name' => 'Stakly Platform',
    ]);
});

// ============================================================================
// 3.1 — Happy paths: each method writes the right ledger row + updates balance.
// ============================================================================

test('deposit credits the user and writes a positive ledger row', function () {
    $user = User::factory()->create();

    $tx = Wallet::deposit($user, '100');

    expect($user->fresh()->usdt_balance)->toBe('100.000000');
    expect($tx->type)->toBe(WalletTransactionType::Deposit);
    expect($tx->amount)->toBe('100.000000');
    expect($tx->balance_after)->toBe('100.000000');
});

test('withdraw debits the user and writes a negative ledger row', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100');

    $tx = Wallet::withdraw($user, '40');

    expect($user->fresh()->usdt_balance)->toBe('60.000000');
    expect($tx->type)->toBe(WalletTransactionType::Withdrawal);
    expect($tx->amount)->toBe('-40.000000');
    expect($tx->balance_after)->toBe('60.000000');
});

test('hold debits the user and ties the ledger row to the listing', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100');
    $listing = Listing::factory()->create();

    $tx = Wallet::hold($user, '25', $listing);

    expect($user->fresh()->usdt_balance)->toBe('75.000000');
    expect($tx->type)->toBe(WalletTransactionType::EscrowHold);
    expect($tx->amount)->toBe('-25.000000');
    expect($tx->related_listing_id)->toBe($listing->id);
});

test('release credits the user back from escrow', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Wallet::deposit($user, '100');
    Wallet::hold($user, '25', $listing);

    $tx = Wallet::release($user, '25', $listing);

    expect($user->fresh()->usdt_balance)->toBe('100.000000');
    expect($tx->type)->toBe(WalletTransactionType::EscrowRelease);
    expect($tx->amount)->toBe('25.000000');
    expect($tx->related_listing_id)->toBe($listing->id);
});

test('payout credits the winner', function () {
    $winner = User::factory()->create();
    $listing = Listing::factory()->create();

    $tx = Wallet::payout($winner, '180', $listing);

    expect($winner->fresh()->usdt_balance)->toBe('180.000000');
    expect($tx->type)->toBe(WalletTransactionType::Payout);
    expect($tx->amount)->toBe('180.000000');
    expect($tx->related_listing_id)->toBe($listing->id);
});

test('fee credits the platform user', function () {
    $listing = Listing::factory()->create();

    $tx = Wallet::fee('20', $listing);

    expect($this->platform->fresh()->usdt_balance)->toBe('20.000000');
    expect($tx->user_id)->toBe($this->platform->id);
    expect($tx->type)->toBe(WalletTransactionType::Fee);
    expect($tx->amount)->toBe('20.000000');
});

test('balanceFor returns the current balance as a string', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '250');

    expect(Wallet::balanceFor($user))->toBe('250.000000');
});

// ============================================================================
// 3.2 — Insufficient balance: debits refuse to drive the balance negative,
//        no ledger row is written, and the user's balance is unchanged.
// ============================================================================

test('withdraw throws when balance would go negative', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '50');

    expect(fn () => Wallet::withdraw($user, '100'))
        ->toThrow(InsufficientBalanceException::class);

    expect($user->fresh()->usdt_balance)->toBe('50.000000');
    expect(
        $user->walletTransactions()
            ->where('type', WalletTransactionType::Withdrawal)
            ->count()
    )->toBe(0);
});

test('hold throws when balance would go negative', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Wallet::deposit($user, '10');

    expect(fn () => Wallet::hold($user, '20', $listing))
        ->toThrow(InsufficientBalanceException::class);

    expect($user->fresh()->usdt_balance)->toBe('10.000000');
    expect(
        $user->walletTransactions()
            ->where('type', WalletTransactionType::EscrowHold)
            ->count()
    )->toBe(0);
});

// ============================================================================
// 3.3 — Idempotency: same reference_id returns the existing row, no duplicate
//        ledger entry, no balance double-debit.
// ============================================================================

test('same reference_id twice returns the existing transaction', function () {
    $user = User::factory()->create();

    $first = Wallet::deposit($user, '100', reference: 'tx-abc-123');
    $second = Wallet::deposit($user, '100', reference: 'tx-abc-123');

    expect($second->id)->toBe($first->id);
    expect($user->fresh()->usdt_balance)->toBe('100.000000');
    expect(WalletTransaction::query()->where('reference_id', 'tx-abc-123')->count())->toBe(1);
});

test('replay with same reference but different amount still returns the original', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100', reference: 'op-001');

    // Second call with a different amount — service should ignore the new
    // amount and silently return the original row.
    $second = Wallet::deposit($user, '999', reference: 'op-001');

    expect($second->amount)->toBe('100.000000');
    expect($user->fresh()->usdt_balance)->toBe('100.000000');
});

// ============================================================================
// 3.4 — Concurrent hold race: in a single-threaded test runner we can't truly
//        run two transactions in parallel, but the practical outcome of
//        lockForUpdate is "second hold blocks then fails because the first
//        debited first." We assert that sequential holds totalling more than
//        the balance cause exactly one to fail.
// ============================================================================

test('two holds exceeding balance — first wins, second fails', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Wallet::deposit($user, '100');

    // First hold consumes 80 of 100, leaving 20.
    Wallet::hold($user, '80', $listing);

    // Second hold of 80 would need to debit 80, but only 20 is left.
    expect(fn () => Wallet::hold($user, '80', $listing))
        ->toThrow(InsufficientBalanceException::class);

    expect($user->fresh()->usdt_balance)->toBe('20.000000');
});

// ============================================================================
// 3.5 — Balance ↔ ledger invariant: for every user, after every operation,
//        users.usdt_balance == SUM(wallet_transactions.amount).
// ============================================================================

test('balance always equals SUM(amount) across many sequential operations', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Wallet::deposit($user, '1000');

    $sequence = [
        fn () => Wallet::deposit($user, '50'),
        fn () => Wallet::withdraw($user, '30'),
        fn () => Wallet::hold($user, '100', $listing),
        fn () => Wallet::release($user, '100', $listing),
        fn () => Wallet::deposit($user, '200'),
        fn () => Wallet::hold($user, '250', $listing),
        fn () => Wallet::release($user, '250', $listing),
        fn () => Wallet::withdraw($user, '100'),
    ];

    foreach ($sequence as $op) {
        $op();

        $balance = (string) $user->fresh()->usdt_balance;
        $ledgerSum = (string) $user->walletTransactions()->sum('amount');

        expect(bccomp($balance, $ledgerSum, 6))->toBe(0);
    }
});

// ============================================================================
// 3.6 — Immutability: wallet_transactions rows are never UPDATEd. The ledger
//        is append-only. Verified by capturing the query log and asserting
//        no UPDATE statement ever targets the wallet_transactions table.
// ============================================================================

test('wallet_transactions rows are never UPDATEd (append-only)', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();

    DB::enableQueryLog();

    Wallet::deposit($user, '100');
    Wallet::withdraw($user, '40');
    Wallet::hold($user, '25', $listing);
    Wallet::release($user, '25', $listing);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    foreach ($queries as $query) {
        $sql = strtolower($query['query']);

        if (str_starts_with($sql, 'update')) {
            // UPDATE on `users` (balance) is fine; UPDATE on wallet_transactions is not.
            expect($sql)->not->toContain('wallet_transactions');
        }
    }
});

// ============================================================================
// 3.7 — Conservation of money: a full match flow neither creates nor destroys
//        value. Sum of hold + release + payout + fee entries = 0.
// ============================================================================

test('conservation of money across a complete match flow', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $listing = Listing::factory()->for($alice)->create();

    // Both players deposit (money entering the system).
    Wallet::deposit($alice, '1000');
    Wallet::deposit($bob, '1000');

    // Both hold their stake — Alice creates, Bob takes.
    Wallet::hold($alice, '100', $listing);
    Wallet::hold($bob, '100', $listing);

    // Match resolves: Alice wins, takes $180; platform takes $20 fee. Bob's
    // hold is forfeit — never released.
    Wallet::payout($alice, '180', $listing);
    Wallet::fee('20', $listing);

    // Final balances:
    //   Alice:    1000 - 100 + 180 = 1080
    //   Bob:      1000 - 100       = 900
    //   Platform:                    20
    //   Sum:                         2000 (equal to Alice + Bob initial deposits)
    expect($alice->fresh()->usdt_balance)->toBe('1080.000000');
    expect($bob->fresh()->usdt_balance)->toBe('900.000000');
    expect($this->platform->fresh()->usdt_balance)->toBe('20.000000');

    // Conservation invariant: holds + releases + payouts + fees sum to 0.
    // (Deposits are excluded — they represent money entering the system.)
    $matchFlowSum = WalletTransaction::query()
        ->whereIn('type', [
            WalletTransactionType::EscrowHold,
            WalletTransactionType::EscrowRelease,
            WalletTransactionType::Payout,
            WalletTransactionType::Fee,
        ])
        ->sum('amount');

    expect(bccomp((string) $matchFlowSum, '0', 6))->toBe(0);
});

// ============================================================================
// 3.8 — Negative-input rejection: passing a negative or zero amount throws
//        InvalidArgumentException before any DB work happens.
// ============================================================================

test('negative amount throws InvalidArgumentException', function () {
    $user = User::factory()->create();

    expect(fn () => Wallet::deposit($user, '-50'))
        ->toThrow(InvalidArgumentException::class);

    expect($user->fresh()->usdt_balance)->toBe('0.000000');
    expect($user->walletTransactions()->count())->toBe(0);
});

test('zero amount throws InvalidArgumentException', function () {
    $user = User::factory()->create();

    expect(fn () => Wallet::deposit($user, '0'))
        ->toThrow(InvalidArgumentException::class);

    expect($user->walletTransactions()->count())->toBe(0);
});
