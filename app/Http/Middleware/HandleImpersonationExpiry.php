<?php

namespace App\Http\Middleware;

use App\Models\AdminImpersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use STS\FilamentImpersonate\Facades\Impersonation;
use Symfony\Component\HttpFoundation\Response;

/**
 * M30 Phase 5 — caps any impersonation session at 30 minutes. Fires on every
 * `web` request; cheap no-op when no impersonation is active.
 *
 * Why a cap exists: limits exposure when an admin walks away from their
 * desk. `Impersonation::leave()` dispatches `LeaveImpersonation`, which the
 * `RecordImpersonationEnd` listener consumes to stamp `ended_at` on the
 * audit row — so expiry closes the trail automatically.
 *
 * The middleware is intentionally tolerant: if `Impersonation::leave()`
 * returns false (multi-guard edge cases) or the active row can't be found,
 * we still allow the request through. The original admin user is reattached
 * to the session by `leave()` itself; we only add the toast.
 */
class HandleImpersonationExpiry
{
    public const SESSION_MAX_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if (! Impersonation::isImpersonating()) {
            return $next($request);
        }

        $impersonator = Impersonation::getImpersonator();

        if ($impersonator === null) {
            return $next($request);
        }

        $activeRow = AdminImpersonation::query()
            ->where('admin_user_id', $impersonator->getAuthIdentifier())
            ->active()
            ->latest('started_at')
            ->first();

        if ($activeRow === null) {
            return $next($request);
        }

        if ($activeRow->started_at->diffInMinutes(now()) < self::SESSION_MAX_MINUTES) {
            return $next($request);
        }

        Impersonation::leave();

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => __('Impersonation session expired. You are back in your admin account.'),
        ]);

        return $next($request);
    }
}
