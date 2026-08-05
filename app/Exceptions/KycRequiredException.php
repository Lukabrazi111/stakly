<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by App\Services\Withdrawals when a cash-out crosses the KYC volume
 * threshold and the account isn't verified (M9 Phase 0c).
 *
 * The backstop, not the user-facing path: `WithdrawRequest::after()` produces a
 * clean 422 for the foreseeable case, same split as `AccountFrozenException`.
 *
 * Gates withdrawals only. Deposits, staking, and settlement are untouched — an
 * unverified player can still play and be paid, they just can't take an
 * above-threshold amount off the platform.
 */
class KycRequiredException extends Exception
{
    public static function for(int $userId): self
    {
        return new self("User {$userId} must be verified before withdrawing this amount.");
    }
}
