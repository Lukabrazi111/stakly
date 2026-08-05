<?php

use App\Enums\WalletTransactionType;
use App\Exceptions\AccountFrozenException;
use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;

/**
 * A freeze is a MONEY-level block (M9 Phase 0b), not a product-access ban:
 * debits are refused, credits still flow. That asymmetry is deliberate — a
 * frozen user's opponent must still be able to get paid or refunded, so
 * freezing one account can't strand another's escrowed money.
 */
beforeEach(function () {
    $this->platform = User::factory()->create([
        'is_platform' => true,
        'email' => 'platform@stakly.internal',
        'name' => 'Stakly Platform',
    ]);
});

function frozenUser(string $balance = '100'): User
{
    $user = User::factory()->create();
    Wallet::deposit($user, $balance);
    $user->forceFill(['frozen_at' => now(), 'frozen_reason' => 'Suspected collusion'])->save();

    return $user->fresh();
}

// ============================================================================
// Debits are blocked.
// ============================================================================

test('withdraw throws for a frozen user and writes nothing', function () {
    $user = frozenUser();
    $ledgerCountBefore = $user->walletTransactions()->count();

    expect(fn () => Wallet::withdraw($user, '10'))
        ->toThrow(AccountFrozenException::class);

    expect($user->fresh()->usdt_balance)->toBe('100.000000');
    expect($user->walletTransactions()->count())->toBe($ledgerCountBefore);
});

test('hold throws for a frozen user and writes nothing', function () {
    $user = frozenUser();
    $listing = Listing::factory()->create();
    $ledgerCountBefore = $user->walletTransactions()->count();

    expect(fn () => Wallet::hold($user, '25', $listing))
        ->toThrow(AccountFrozenException::class);

    expect($user->fresh()->usdt_balance)->toBe('100.000000');
    expect($user->walletTransactions()->count())->toBe($ledgerCountBefore);
});

// ============================================================================
// Credits still flow — in-flight matches settle, refunds land.
// ============================================================================

test('deposit still succeeds for a frozen user', function () {
    $user = frozenUser();

    $tx = Wallet::deposit($user, '50');

    expect($tx->type)->toBe(WalletTransactionType::Deposit);
    expect($user->fresh()->usdt_balance)->toBe('150.000000');
});

test('release still succeeds for a frozen user so an in-flight refund can land', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create();
    Wallet::deposit($user, '100');
    Wallet::hold($user, '25', $listing);

    $user->forceFill(['frozen_at' => now()])->save();

    $tx = Wallet::release($user->fresh(), '25', $listing);

    expect($tx->type)->toBe(WalletTransactionType::EscrowRelease);
    expect($user->fresh()->usdt_balance)->toBe('100.000000');
});

test('payout still succeeds for a frozen user so an in-flight match can settle', function () {
    $user = frozenUser();
    $listing = Listing::factory()->create();

    $tx = Wallet::payout($user, '180', $listing);

    expect($tx->type)->toBe(WalletTransactionType::Payout);
    expect($user->fresh()->usdt_balance)->toBe('280.000000');
});

test('fee to the platform user still succeeds while a player is frozen', function () {
    frozenUser();
    $listing = Listing::factory()->create();

    $tx = Wallet::fee('20', $listing);

    expect($tx->type)->toBe(WalletTransactionType::Fee);
    expect($this->platform->fresh()->usdt_balance)->toBe('20.000000');
});

// ============================================================================
// Edge cases.
// ============================================================================

test('unfreezing restores debit ability', function () {
    $user = frozenUser();

    $user->forceFill(['frozen_at' => null, 'frozen_reason' => null])->save();

    $tx = Wallet::withdraw($user->fresh(), '10');

    expect($tx->type)->toBe(WalletTransactionType::Withdrawal);
    expect($user->fresh()->usdt_balance)->toBe('90.000000');
});

test('replaying an already-recorded debit stays a no-op instead of throwing', function () {
    $user = User::factory()->create();
    Wallet::deposit($user, '100');
    $original = Wallet::withdraw($user, '40', reference: 'wd:1');

    $user->forceFill(['frozen_at' => now()])->save();

    // The reference-replay check runs before the freeze guard: a retried job
    // for work that already happened must not start throwing after a freeze.
    $replay = Wallet::withdraw($user->fresh(), '40', reference: 'wd:1');

    expect($replay->id)->toBe($original->id);
    expect($user->fresh()->usdt_balance)->toBe('60.000000');
});

test('a frozen user with insufficient balance reports the freeze, not the balance', function () {
    $user = frozenUser('5');

    expect(fn () => Wallet::withdraw($user, '999'))
        ->toThrow(AccountFrozenException::class);
});
