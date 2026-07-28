<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by App\Services\Wallet when a debit operation (Withdrawal or
 * EscrowHold) is attempted against a frozen account.
 *
 * A freeze is a money-level block, not a product-access ban: credits
 * (Deposit / EscrowRelease / Payout / Fee) still succeed so in-flight matches
 * can settle and refunds can land while the account is under review.
 */
class AccountFrozenException extends Exception
{
    public static function for(int $userId): self
    {
        return new self("User {$userId} is frozen; debit operations are blocked.");
    }
}
