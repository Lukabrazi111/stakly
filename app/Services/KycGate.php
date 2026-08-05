<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\User;

/**
 * Decides whether a withdrawal needs a verified identity (M9 Phase 0c).
 *
 * OFF BY DEFAULT. Nothing in the current provider model requires KYC:
 * NOWPayments asks crypto-only merchants for KYB/KYC only when a transaction is
 * flagged suspicious, and imposes nothing on our end users while deposits use
 * permanent per-user addresses. This exists so that turning verification on is a
 * config flip at one choke point rather than a refactor of the money path.
 *
 * A TIERED VOLUME gate, not an all-users wall — below the threshold nothing is
 * asked. Verification is admin-driven (no document-upload flow exists), so a
 * blanket gate would brick cash-out for every player the moment it was enabled.
 *
 * Mirrors `PayoutClearance`: static, config-driven, no state of its own.
 */
final class KycGate
{
    private const SCALE = 6;

    public static function enabled(): bool
    {
        return (bool) config('stakly.kyc_enabled');
    }

    /**
     * Whether $user must be verified before withdrawing $amount.
     *
     * Callers hold the user row lock (see `Withdrawals::request`), so the
     * volume read below can't race a concurrent request.
     */
    public static function requiresVerification(User $user, string $amount): bool
    {
        // Null-safe on purpose: an absent status must fall through to "gated",
        // never to "cleared". Defaults make null unreachable today; this keeps
        // the failure direction safe if that ever stops being true.
        if (! self::enabled() || $user->kyc_status?->satisfiesGate() === true) {
            return false;
        }

        $threshold = (string) config('stakly.kyc_threshold', '0');

        // A zero/negative threshold means "verify everyone" — the gate applies
        // to any amount rather than degrading to "never".
        if (bccomp($threshold, '0', self::SCALE) <= 0) {
            return true;
        }

        $projected = bcadd(self::lifetimeWithdrawn($user), $amount, self::SCALE);

        return bccomp($projected, $threshold, self::SCALE) > 0;
    }

    /**
     * Withdrawal volume that counts toward the threshold.
     *
     * Includes Pending and Sending, not just Completed: counting only settled
     * withdrawals would let a player split one large cash-out into several
     * concurrent requests and stay under the line on each. Rejected and Failed
     * are excluded — those were credited back, so they moved no money out.
     */
    public static function lifetimeWithdrawn(User $user): string
    {
        $total = $user->withdrawals()
            ->whereIn('status', [
                WithdrawalStatus::Pending,
                WithdrawalStatus::Sending,
                WithdrawalStatus::Completed,
            ])
            ->pluck('amount')
            // Summed in PHP rather than SQL SUM() to avoid a float round-trip
            // on money, same as `Wallet::unclearedBalance`.
            ->reduce(fn (string $carry, $amount): string => bcadd($carry, (string) $amount, self::SCALE), '0');

        // Normalize scale so an empty tally reads '0.000000' like every other
        // money string, rather than a bare '0' the reduce never touched.
        return bcadd($total, '0', self::SCALE);
    }
}
