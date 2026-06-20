<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the banned-user rejection message + redirect
 * target across every guarded surface (`ListingController::create + store`,
 * `ProfileController::update`, `ChangeUsernameAction`, etc.). Keeps the
 * "Account suspended — see support page" copy consistent and updates land
 * in one place.
 */
class BanGuard
{
    public static function isBanned(?User $user): bool
    {
        return $user !== null && $user->banned_at !== null;
    }

    public static function rejectionMessage(): string
    {
        return __('Account suspended. If you believe this is a mistake, please reach out via our support page.');
    }

    /** Public route to the CMS Support page. M26 P2 seeds it; admin publishes before this ships in production. */
    public static function supportUrl(): string
    {
        return url('/en/support');
    }
}
