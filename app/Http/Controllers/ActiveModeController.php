<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Global Active Mode toggle — the *only* visibility control for a user's
 * Open listings. Inactive hides every Open listing of theirs from the
 * marketplace + public profile views (via `Listing::scopeOnPublicMarketplace`).
 * Listings stay in `Open` status; `is_active_mode` is what gates appearance.
 */
class ActiveModeController extends Controller
{
    /**
     * Idempotent — submitting the current state is a silent no-op.
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
