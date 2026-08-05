<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by App\Services\Withdrawals when a cash-out would breach the rolling
 * 24-hour ceiling (M9 Phase 0f).
 *
 * The backstop, not the user-facing path: `WithdrawRequest::after()` produces a
 * clean 422 for the foreseeable case, same split as `AccountFrozenException`
 * and `KycRequiredException`.
 */
class DailyLimitExceededException extends Exception
{
    public static function for(int $userId, string $remaining): self
    {
        return new self("User {$userId} has {$remaining} USDT of daily withdrawal allowance left.");
    }
}
