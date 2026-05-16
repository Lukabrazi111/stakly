<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Resources\GameMatchResource;
use App\Http\Resources\ListingResource;
use App\Http\Resources\UserProfileResource;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const OPEN_LISTINGS_LIMIT = 5;

    /**
     * Match history shown on a public profile is capped at the most recent
     * N settled matches. Pagination through full history is deferred — if
     * usage data shows users want to scroll deep into someone's record, a
     * dedicated /users/{username}/matches page can be added.
     */
    private const MATCH_HISTORY_LIMIT = 10;

    /**
     * Public read-only profile page. Resolved by `username` via the
     * `User::getRouteKeyName()` override. No auth — anyone can view.
     *
     * The platform user (`is_platform = true`) is intentionally hidden so the
     * seeded `stakly-platform` account never surfaces as a "real player".
     * Route model binding catches unknown usernames automatically.
     *
     * Active-listings query splits by viewer identity: the profile owner
     * sees their Open + Paused listings (so they can resume Paused ones);
     * everyone else sees Open only (since Paused is hidden from the public
     * board, exposing it on the public profile would defeat the purpose).
     */
    public function show(Request $request, User $user): Response
    {
        abort_if($user->is_platform, 404);

        $isOwnProfile = $request->user()?->id === $user->id;

        $openListings = $user->listings()
            ->when($isOwnProfile, fn ($q) => $q->openOrPaused(), fn ($q) => $q->open())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_LISTINGS_LIMIT)
            ->get();

        // The creator of every listing here is the user we just resolved.
        // Pre-set the `user` relation so `ListingResource` renders the creator
        // chip without re-querying (saves one IN-query on a hot page).
        $openListings->each(fn (Listing $listing) => $listing->setRelation('user', $user));

        // Settled matches only on public profiles. Pending matches would
        // expose "user X is currently in a $500 match with Y" to the world,
        // which feels privacy-leaky for a feature that's just background
        // context for player reputation. Disputed/ManualReview are also
        // omitted — the result isn't yet authoritative.
        $matchHistory = GameMatch::query()
            ->forParticipant($user->id)
            ->where('status', MatchStatus::Settled)
            ->with([
                'listing:id,user_id,game,stake_amount,time_control,status',
                'listing.user:id,name,username',
                'taker:id,name,username',
                'winner:id,name,username',
            ])
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->limit(self::MATCH_HISTORY_LIMIT)
            ->get();

        $stats = [
            'open_listings' => $user->listings()->open()->count(),
            'total_listings' => $user->listings()->count(),
            'member_since' => $user->created_at->toIso8601String(),
        ];

        return Inertia::render('users/show', [
            'user' => (new UserProfileResource($user))->resolve(),
            'stats' => $stats,
            'openListings' => ListingResource::collection($openListings),
            'matchHistory' => GameMatchResource::collection($matchHistory),
        ]);
    }
}
