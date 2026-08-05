<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Exceptions\AccountFrozenException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single source of truth for every money state change on Stakly.
 *
 * Every operation:
 *   1. Validates the input amount is a positive string (otherwise InvalidArgumentException).
 *   2. Opens a DB transaction with `lockForUpdate` on the user row.
 *   3. Idempotency: if `reference_id` is set and a matching row already exists,
 *      returns it untouched (no balance change, no duplicate ledger row).
 *   4. Computes the signed amount from the WalletTransactionType (debits → negative,
 *      credits → positive).
 *   5. For debits, refuses a frozen account — throws AccountFrozenException.
 *   6. For debits, refuses to push the balance below zero — throws
 *      InsufficientBalanceException and rolls back.
 *   7. Appends an immutable `wallet_transactions` row + updates `users.usdt_balance`
 *      atomically. `balance_after` snapshots the post-operation balance.
 *
 * NEVER mutate `users.usdt_balance` outside this service. The invariant
 * `users.usdt_balance == SUM(wallet_transactions.amount)` for every user
 * depends on every change flowing through here.
 *
 * All amounts are BCMath strings — never PHP floats. The caller passes a
 * positive string (`'100.000000'` or `'100'`); the service handles the sign.
 */
class Wallet
{
    /**
     * BCMath scale matching Tron USDT precision + the `decimal(18, 6)` columns.
     */
    private const SCALE = 6;

    public static function deposit(
        User $user,
        string $amount,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $user,
            type: WalletTransactionType::Deposit,
            amount: $amount,
            listing: null,
            reference: $reference,
            description: $description,
        );
    }

    public static function withdraw(
        User $user,
        string $amount,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $user,
            type: WalletTransactionType::Withdrawal,
            amount: $amount,
            listing: null,
            reference: $reference,
            description: $description,
        );
    }

    public static function hold(
        User $user,
        string $amount,
        Listing $listing,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $user,
            type: WalletTransactionType::EscrowHold,
            amount: $amount,
            listing: $listing,
            reference: $reference,
            description: $description,
        );
    }

    public static function release(
        User $user,
        string $amount,
        Listing $listing,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $user,
            type: WalletTransactionType::EscrowRelease,
            amount: $amount,
            listing: $listing,
            reference: $reference,
            description: $description,
        );
    }

    /**
     * `$clearsAt` defers WITHDRAWABILITY, not the credit — the balance moves
     * immediately, but the amount is excluded from `availableBalance()` until
     * the timestamp passes. Resolve it with `App\Services\PayoutClearance`.
     * Null means immediately withdrawable.
     */
    public static function payout(
        User $winner,
        string $amount,
        Listing $listing,
        ?string $reference = null,
        ?string $description = null,
        ?CarbonInterface $clearsAt = null,
    ): WalletTransaction {
        return self::record(
            user: $winner,
            type: WalletTransactionType::Payout,
            amount: $amount,
            listing: $listing,
            reference: $reference,
            description: $description,
            clearsAt: $clearsAt,
        );
    }

    /**
     * Credit-back for a rejected or failed withdrawal. Separate from
     * `deposit()` so reversals don't inflate deposit-volume reporting.
     */
    public static function reverseWithdrawal(
        User $user,
        string $amount,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $user,
            type: WalletTransactionType::WithdrawalReversal,
            amount: $amount,
            listing: null,
            reference: $reference,
            description: $description,
        );
    }

    /**
     * Credits the platform user (`is_platform = true`). No User parameter —
     * exactly one platform user is seeded; resolving it here keeps callers
     * from having to fetch it themselves.
     *
     * `$listing` is nullable because not every platform fee comes from a match:
     * the withdrawal margin (M9 Phase 0b) has no listing to point at.
     */
    public static function fee(
        string $amount,
        ?Listing $listing,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        $platform = User::query()->where('is_platform', true)->firstOrFail();

        return self::record(
            user: $platform,
            type: WalletTransactionType::Fee,
            amount: $amount,
            listing: $listing,
            reference: $reference,
            description: $description,
        );
    }

    /**
     * Current balance for the user, fetched fresh from the DB. This is the
     * TOTAL — including winnings still inside their insurance window. Use
     * `availableBalance()` for anything that lets money leave the platform.
     */
    public static function balanceFor(User $user): string
    {
        return $user->fresh()->usdt_balance;
    }

    /**
     * Balance the user may actually withdraw: total minus payouts that haven't
     * cleared yet (M9 Phase 0b). Staking is deliberately NOT gated on this —
     * uncleared winnings can be escrowed into new matches, they just can't
     * leave the platform.
     *
     * When insurance is disabled this short-circuits to the full balance, so
     * flipping the kill-switch also releases holds already stamped on existing
     * rows rather than stranding them behind a window nobody enforces.
     */
    public static function availableBalance(User $user): string
    {
        $balance = self::balanceFor($user);

        if (! PayoutClearance::enabled()) {
            return $balance;
        }

        return bcsub($balance, self::unclearedBalance($user), self::SCALE);
    }

    /**
     * Sum of the user's payouts still inside their insurance window.
     *
     * Summed in PHP with bcadd rather than SQL SUM() — the aggregate comes
     * back through a float on some drivers, and money never round-trips
     * through a float here.
     */
    public static function unclearedBalance(User $user): string
    {
        if (! PayoutClearance::enabled()) {
            return '0';
        }

        return $user->walletTransactions()
            ->uncleared()
            ->pluck('amount')
            ->reduce(
                fn (string $carry, $amount): string => bcadd($carry, (string) $amount, self::SCALE),
                '0',
            );
    }

    /**
     * When the user's next tranche of winnings unlocks, or null if nothing is
     * being held. Drives the "clearing" line in the wallet UI.
     */
    public static function nextClearanceAt(User $user): ?CarbonImmutable
    {
        if (! PayoutClearance::enabled()) {
            return null;
        }

        $earliest = $user->walletTransactions()->uncleared()->min('clears_at');

        return $earliest === null ? null : CarbonImmutable::parse($earliest);
    }

    /**
     * Single internal entry point — every public method funnels through here.
     */
    private static function record(
        User $user,
        WalletTransactionType $type,
        string $amount,
        ?Listing $listing,
        ?string $reference,
        ?string $description,
        ?CarbonInterface $clearsAt = null,
    ): WalletTransaction {
        self::assertPositive($amount);

        return DB::transaction(function () use ($user, $type, $amount, $listing, $reference, $description, $clearsAt) {
            $existing = self::findByReference($reference);

            if ($existing !== null) {
                return $existing;
            }

            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $isDebit = self::isDebit($type);

            // Checked on the LOCKED row, not the caller's instance, so a freeze
            // landing concurrently can't be raced past by an in-flight debit.
            // Deliberately after the reference-replay check above: replaying an
            // already-recorded operation stays a silent no-op even when frozen.
            if ($isDebit && $locked->isFrozen()) {
                throw AccountFrozenException::for($locked->id);
            }

            $signed = $isDebit ? '-'.$amount : $amount;
            $balanceAfter = bcadd($locked->usdt_balance, $signed, self::SCALE);

            if ($isDebit && bccomp($balanceAfter, '0', self::SCALE) < 0) {
                throw new InsufficientBalanceException(
                    "User {$locked->id} has insufficient balance ({$locked->usdt_balance}) for {$type->value} of {$amount}."
                );
            }

            $locked->usdt_balance = $balanceAfter;
            $locked->save();

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => $type,
                'amount' => $signed,
                'balance_after' => $balanceAfter,
                'related_listing_id' => $listing?->id,
                'reference_id' => $reference,
                'description' => $description,
                'clears_at' => $clearsAt,
            ]);
        });
    }

    private static function findByReference(?string $reference): ?WalletTransaction
    {
        if ($reference === null) {
            return null;
        }

        return WalletTransaction::query()->where('reference_id', $reference)->first();
    }

    /**
     * Amounts arrive as positive strings; sign direction is determined by
     * the WalletTransactionType, never by the caller.
     */
    private static function assertPositive(string $amount): void
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException(
                "Wallet amount must be positive; got {$amount}."
            );
        }
    }

    private static function isDebit(WalletTransactionType $type): bool
    {
        return match ($type) {
            WalletTransactionType::Withdrawal,
            WalletTransactionType::EscrowHold => true,
            default => false,
        };
    }
}
