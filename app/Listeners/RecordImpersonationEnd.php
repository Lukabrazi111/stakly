<?php

namespace App\Listeners;

use App\Models\AdminImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

/**
 * Stamps `ended_at` on the impersonator's active audit row when the package
 * dispatches `LeaveImpersonation` — either from the manual leave route or
 * from the 30-min expiry middleware calling `Impersonation::leave()`.
 *
 * Edge case not closed by this listener: if an admin is force-logged-out
 * while impersonating (Login/Logout events bypass `LeaveImpersonation`),
 * the audit row stays open. The 30-min expiry semantics mean an open row
 * older than 30 min should be treated as "ended at started_at + 30 min."
 */
class RecordImpersonationEnd
{
    public function handle(LeaveImpersonation $event): void
    {
        AdminImpersonation::query()
            ->where('admin_user_id', $event->impersonator->getAuthIdentifier())
            ->active()
            ->latest('started_at')
            ->limit(1)
            ->update(['ended_at' => now()]);
    }
}
