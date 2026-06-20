<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * M30 Phase 3 — admin-panel security gate. Admin role users must have 2FA
 * enrolled (`two_factor_confirmed_at IS NOT NULL`) to access `/admin/*`.
 * Hardens the admin surface against credential phishing now that admin can
 * impersonate any user, settle disputes, and reset other users' 2FA.
 *
 * Non-admin users fall through to Filament's own `canAccessPanel` 403.
 * Guests don't reach this middleware — `Filament\Http\Middleware\Authenticate`
 * runs first in the panel's `authMiddleware` chain.
 *
 * Redirect target is `/settings/security` (the user-facing 2FA enrollment
 * page). After enrolling, the admin manually navigates to `/admin`; no
 * intended-URL handoff yet.
 */
class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->hasRole('admin')
            && $user->two_factor_confirmed_at === null
        ) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Two-factor authentication is required for admin access. Enable it below to continue.'),
            ]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
