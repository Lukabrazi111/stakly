<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Decides when a match payout becomes withdrawable (M9 Phase 0b).
 *
 * Winnings are credited to the balance the moment a match settles, but they
 * sit inside an insurance window before they can leave the platform. The
 * threat is a provider (chess.com / Lichess / FACEIT) retroactively closing an
 * account for fair-play violations days-to-weeks after the games — the
 * chargeback-equivalent for this product. The acute case is already covered by
 * the auto-detection pipeline; this covers the retrospective one, where the
 * money would otherwise be long gone.
 *
 * Only payouts clear. Deposits are final on-chain and escrow releases are the
 * player's own stake coming back, so neither is at risk of reversal.
 *
 * Uncleared winnings can still be STAKED into new matches — they just can't be
 * withdrawn. That keeps the product playable, and it's safe because every
 * re-stake starts a fresh clearance clock on the resulting winnings: laundering
 * a cheated pot through an accomplice moves which account is waiting, never
 * shortens the wait.
 */
final class PayoutClearance
{
    /**
     * When this payout should clear, or null if it's immediately withdrawable.
     */
    public static function for(User $winner, string $amount): ?CarbonImmutable
    {
        if (! self::enabled()) {
            return null;
        }

        $hours = self::isElevatedRisk($winner, $amount)
            ? (int) config('stakly.withdrawal_insurance_elevated_hours')
            : (int) config('stakly.withdrawal_insurance_base_hours');

        if ($hours <= 0) {
            return null;
        }

        return CarbonImmutable::now()->addHours($hours);
    }

    public static function enabled(): bool
    {
        return (bool) config('stakly.withdrawal_insurance_enabled');
    }

    /**
     * Any single signal escalates the window. Deliberately coarse — these are
     * the signals available without a risk-scoring system, and they cover where
     * an unrecoverable retroactive ban is most likely to land.
     */
    private static function isElevatedRisk(User $winner, string $amount): bool
    {
        $risk = config('stakly.withdrawal_insurance_risk');

        $newAccountDays = (int) ($risk['new_account_days'] ?? 0);
        if ($newAccountDays > 0 && $winner->created_at !== null
            && $winner->created_at->gt(CarbonImmutable::now()->subDays($newAccountDays))) {
            return true;
        }

        $largePayout = (string) ($risk['large_payout_amount'] ?? '0');
        if (bccomp($largePayout, '0', 6) > 0 && bccomp($amount, $largePayout, 6) >= 0) {
            return true;
        }

        if (($risk['first_withdrawal'] ?? false) && ! self::hasCompletedWithdrawal($winner)) {
            return true;
        }

        return false;
    }

    /**
     * A player who has never successfully cashed out is unproven — no track
     * record, and the profile a bought/boosted account fits.
     */
    private static function hasCompletedWithdrawal(User $winner): bool
    {
        return $winner->withdrawals()
            ->where('status', WithdrawalStatus::Completed)
            ->exists();
    }
}
