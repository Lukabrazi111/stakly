<?php

namespace App\Listeners;

use App\Models\AdminImpersonation;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\Events\EnterImpersonation;

/**
 * Writes the audit row when `stechstudio/filament-impersonate` dispatches
 * `EnterImpersonation`. The reason is stashed in session by
 * `ImpersonateUserAction::before()` and consumed (then forgotten) here so
 * it doesn't bleed across requests.
 */
class RecordImpersonationStart
{
    public function __construct(private Request $request) {}

    public function handle(EnterImpersonation $event): void
    {
        AdminImpersonation::create([
            'admin_user_id' => $event->impersonator->getAuthIdentifier(),
            'target_user_id' => $event->impersonated->getAuthIdentifier(),
            'reason' => session()->pull('impersonate.reason', ''),
            'started_at' => now(),
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 255),
        ]);
    }
}
