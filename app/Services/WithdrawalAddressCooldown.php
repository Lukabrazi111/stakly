<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Delays the first withdrawal to a never-used address (M9 Phase 0e).
 *
 * Closes the gap the 2FA step-up leaves open. A TOTP code proves someone
 * holding the device is present, but a phished or coerced code still sends
 * funds wherever the request says. The delay plus the notification turns an
 * instant, irreversible drain into a window where the real owner can react.
 *
 * HELD, not blocked: the withdrawal is accepted and the balance debited
 * immediately, so it can't be spent twice — only the payout waits. Blocking
 * outright would fail every legitimate first withdrawal instead.
 *
 * Mirrors `PayoutClearance`: static, config-driven, `0` disables.
 */
final class WithdrawalAddressCooldown
{
    public static function hours(): int
    {
        return (int) config('stakly.withdrawal_address_cooldown_hours');
    }

    /**
     * When this payout may be sent, or null to send immediately.
     */
    public static function holdUntil(User $user, string $address): ?CarbonImmutable
    {
        $hours = self::hours();

        if ($hours <= 0 || self::isKnownAddress($user, $address)) {
            return null;
        }

        return CarbonImmutable::now()->addHours($hours);
    }

    /**
     * An address is trusted once the player has a withdrawal to it that wasn't
     * reversed AND is no longer inside its own hold window.
     *
     * The hold clause is what stops the obvious bypass: without it, a 1 USDT
     * decoy to the attacker's address would immediately mark that address
     * "known" while still sitting in its own cooldown, and the next request
     * could drain the balance instantly. A still-held row proves nothing —
     * nobody has had the chance to object to it yet.
     *
     * Rejected and Failed never count: that money came back, so it says
     * nothing about the destination.
     */
    public static function isKnownAddress(User $user, string $address): bool
    {
        return $user->withdrawals()
            ->where('destination_address', $address)
            ->whereIn('status', [
                WithdrawalStatus::Pending,
                WithdrawalStatus::Sending,
                WithdrawalStatus::Completed,
            ])
            ->where(fn ($query) => $query
                ->whereNull('hold_until')
                ->orWhere('hold_until', '<=', CarbonImmutable::now()),
            )
            ->exists();
    }
}
