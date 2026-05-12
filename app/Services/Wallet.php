<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Listing;
use App\Models\User;
use App\Models\WalletTransaction;
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
 *   5. For debits, refuses to push the balance below zero — throws
 *      InsufficientBalanceException and rolls back.
 *   6. Appends an immutable `wallet_transactions` row + updates `users.usdt_balance`
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

    public static function payout(
        User $winner,
        string $amount,
        Listing $listing,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        return self::record(
            user: $winner,
            type: WalletTransactionType::Payout,
            amount: $amount,
            listing: $listing,
            reference: $reference,
            description: $description,
        );
    }

    /**
     * Credits the platform user (`is_platform = true`). No User parameter —
     * exactly one platform user is seeded; resolving it here keeps callers
     * from having to fetch it themselves.
     */
    public static function fee(
        string $amount,
        Listing $listing,
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
     * Current balance for the user, fetched fresh from the DB.
     */
    public static function balanceFor(User $user): string
    {
        return $user->fresh()->usdt_balance;
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
    ): WalletTransaction {
        self::assertPositive($amount);

        return DB::transaction(function () use ($user, $type, $amount, $listing, $reference, $description) {
            $existing = self::findByReference($reference);

            if ($existing !== null) {
                return $existing;
            }

            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $isDebit = self::isDebit($type);
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
