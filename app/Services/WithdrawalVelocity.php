<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Rolling 24-hour withdrawal ceiling (M9 Phase 0f).
 *
 * The backstop for exploits nobody predicted. Freeze, KYC, the 2FA step-up and
 * the new-address cooldown each block a KNOWN attack; this bounds the worst-case
 * loss from an unknown one, so a single compromised account can only ever cost
 * the daily limit however the compromise happened.
 *
 * Rolling, not calendar-day: a midnight reset would let an attacker take the
 * full limit at 23:59 and again at 00:01.
 *
 * Callers hold the user row lock (see `Withdrawals::request`), so the tally
 * can't be raced by concurrent requests.
 */
final class WithdrawalVelocity
{
    private const SCALE = 6;

    private const WINDOW_HOURS = 24;

    public static function limit(): string
    {
        return (string) config('stakly.withdrawal_daily_limit', '0');
    }

    public static function enabled(): bool
    {
        return bccomp(self::limit(), '0', self::SCALE) > 0;
    }

    /**
     * Whether withdrawing $amount now would breach the rolling ceiling.
     */
    public static function exceedsDailyLimit(User $user, string $amount): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $projected = bcadd(self::withdrawnLast24h($user), $amount, self::SCALE);

        return bccomp($projected, self::limit(), self::SCALE) > 0;
    }

    /**
     * Headroom left in the current window, floored at zero.
     */
    public static function remainingToday(User $user): string
    {
        if (! self::enabled()) {
            return self::limit();
        }

        $remaining = bcsub(self::limit(), self::withdrawnLast24h($user), self::SCALE);

        return bccomp($remaining, '0', self::SCALE) > 0 ? $remaining : '0.000000';
    }

    /**
     * Withdrawal volume inside the rolling window.
     *
     * Counts Pending and Sending alongside Completed: money already on its way
     * out still consumes the ceiling, and excluding it would let a burst of
     * concurrent requests each see a full allowance. Rejected and Failed are
     * excluded — those were credited back.
     */
    public static function withdrawnLast24h(User $user): string
    {
        $total = $user->withdrawals()
            ->where('created_at', '>=', CarbonImmutable::now()->subHours(self::WINDOW_HOURS))
            ->whereIn('status', [
                WithdrawalStatus::Pending,
                WithdrawalStatus::Sending,
                WithdrawalStatus::Completed,
            ])
            ->pluck('amount')
            // Summed in PHP, not SQL, to avoid a float round-trip on money.
            ->reduce(fn (string $carry, $amount): string => bcadd($carry, (string) $amount, self::SCALE), '0');

        return bcadd($total, '0', self::SCALE);
    }
}
