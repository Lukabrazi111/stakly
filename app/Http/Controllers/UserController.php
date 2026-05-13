<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingResource;
use App\Http\Resources\UserProfileResource;
use App\Models\Listing;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const OPEN_LISTINGS_LIMIT = 5;

    /**
     * Public read-only profile page. Resolved by `username` via the
     * `User::getRouteKeyName()` override. No auth — anyone can view.
     *
     * The platform user (`is_platform = true`) is intentionally hidden so the
     * seeded `stakly-platform` account never surfaces as a "real player".
     * Route model binding catches unknown usernames automatically.
     */
    public function show(User $user): Response
    {
        abort_if($user->is_platform, 404);

        $openListings = $user->listings()
            ->open()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_LISTINGS_LIMIT)
            ->get();

        // The creator of every listing here is the user we just resolved.
        // Pre-set the `user` relation so `ListingResource` renders the creator
        // chip without re-querying (saves one IN-query on a hot page).
        $openListings->each(fn (Listing $listing) => $listing->setRelation('user', $user));

        $stats = [
            'open_listings' => $user->listings()->open()->count(),
            'total_listings' => $user->listings()->count(),
            'member_since' => $user->created_at->toIso8601String(),
        ];

        return Inertia::render('users/show', [
            'user' => (new UserProfileResource($user))->resolve(),
            'stats' => $stats,
            'openListings' => ListingResource::collection($openListings),
        ]);
    }
}
