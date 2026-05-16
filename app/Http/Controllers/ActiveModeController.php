<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Single endpoint for the global Active Mode toggle on `/listings/mine`.
 * See milestones.md M6 Phase 6.5 — the user's mental model is "I'm
 * online/offline as a player." Inactive hides every Open listing of theirs
 * from the marketplace + public profile views (via
 * `Listing::scopeOnPublicMarketplace`). Active reverses it.
 *
 * As of Phase 6.5, this toggle is the *only* visibility control — per-listing
 * pause/resume was removed in favor of one global switch. Listings stay in
 * `Open` status the whole time; the user's `is_active_mode` flag is what
 * gates their appearance on public surfaces.
 */
class ActiveModeController extends Controller
{
    /**
     * Update the auth user's `is_active_mode`. Body: `{ active: bool }`.
     *
     * Idempotent — submitting the current state is a silent no-op (no
     * flash, no DB write).
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        abort_if($user->is_platform, 403);

        $active = $request->boolean('active');

        $result = DB::transaction(function () use ($user, $active) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            // No-op short-circuit — repeat submissions are silent.
            if ($locked->is_active_mode === $active) {
                return 'unchanged';
            }

            $locked->is_active_mode = $active;
            $locked->save();

            return $active ? 'activated' : 'deactivated';
        });

        Inertia::flash('toast', match ($result) {
            'activated' => [
                'type' => 'success',
                'message' => __('Active Mode on. Your listings are back on the marketplace.'),
            ],
            'deactivated' => [
                'type' => 'info',
                'message' => __('Active Mode off. Your listings are hidden from the marketplace.'),
            ],
            default => null,
        });

        return back();
    }
}
