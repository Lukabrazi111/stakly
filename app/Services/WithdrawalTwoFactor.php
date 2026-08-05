<?php

namespace App\Services;

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * 2FA step-up on withdrawal (M9 Phase 0d).
 *
 * Closes the highest-severity money path in the product: account takeover into
 * a drain to an attacker's address. ON by default.
 *
 * STEP-UP, not a prerequisite — a fresh TOTP code is required on every
 * withdrawal, not merely "2FA is enabled on the account". Enrolment alone would
 * leave a hijacked live session able to drain freely, because that session
 * already cleared 2FA at login. The code is what proves someone holding the
 * device is present at withdrawal time.
 *
 * Lives at the HTTP boundary rather than inside `Withdrawals::request()`.
 * Freeze and KYC are DB state that must be read under the user row lock; a TOTP
 * code is a credential that only exists in a request context, and seeders or
 * admin-initiated withdrawals legitimately have none to present.
 *
 * Recovery codes are deliberately NOT accepted here. A lost device already
 * blocks login, so the real recovery path is recover-login → re-enrol →
 * withdraw. Honouring them at this step would widen the surface to the
 * credential most likely to be screenshotted, to solve a lockout that the login
 * flow already handles.
 */
final class WithdrawalTwoFactor
{
    public static function enabled(): bool
    {
        return (bool) config('stakly.withdrawal_require_2fa');
    }

    /**
     * Whether $user must present a code to withdraw.
     *
     * True even when the account has no 2FA set up — that case is a prompt to
     * enrol, not a bypass. Callers distinguish it via `hasEnrolled()`.
     */
    public static function required(User $user): bool
    {
        return self::enabled() && ! $user->is_platform;
    }

    public static function hasEnrolled(User $user): bool
    {
        return $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Verify a submitted TOTP code against the user's secret.
     *
     * Fortify's provider caches each accepted code for the length of its
     * window, so a code cannot be replayed — which matters more here than at
     * login, since an intercepted code would otherwise be worth a second
     * withdrawal.
     */
    public static function verify(User $user, ?string $code): bool
    {
        if (! self::hasEnrolled($user) || $code === null || $code === '') {
            return false;
        }

        return app(TwoFactorAuthenticationProvider::class)->verify(
            decrypt($user->two_factor_secret),
            $code,
        );
    }
}
