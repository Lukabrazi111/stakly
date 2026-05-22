<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // Override `usdt_balance` to a float at the JSON boundary —
                // Eloquent's `decimal:6` cast serializes to a string by default.
                // Same float-at-the-boundary convention as `ListingResource`,
                // so the frontend never deals with BCMath strings.
                //
                // `is_active_mode` is also explicitly shared so the Active
                // Mode toggle on `/listings/mine` and the marketplace banner
                // always read the current state without an extra fetch.
                'user' => $user ? [
                    ...$user->toArray(),
                    'usdt_balance' => (float) $user->usdt_balance,
                    'is_active_mode' => (bool) $user->is_active_mode,
                    // M8 Phase 5 Slice A take-gate + create-gate (permissive
                    // "any chess provider" check). Slice B uses
                    // `linked_platforms` below for per-listing-platform UI.
                    'has_chess_link' => $user->hasVerifiedChessLink(),
                    // M8 Phase 5 Slice B — the verified chess providers the
                    // user has linked. Frontend reads this to render
                    // platform-specific Take button copy on the listing
                    // detail page and to show/hide the create-form platform
                    // picker. Listed in the order the LinkedAccountProvider
                    // enum defines.
                    'linked_platforms' => array_values(array_filter([
                        $user->chess_com_verified_at !== null ? 'chess_com' : null,
                        $user->lichess_verified_at !== null ? 'lichess' : null,
                    ])),
                ] : null,
            ],
            'status' => fn () => $request->session()->get('status'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
