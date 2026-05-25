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

        // Eager-load linked accounts so the backwards-compat accessors on
        // `User` (chess_com_username / lichess_username / etc.) read from
        // the loaded collection — `UserProfileResource` reads each one.
        $user->load('linkedAccounts');

        $isOwnProfile = $request->user()?->id === $user->id;

        $openListings = $user->listings()
            ->when(
                $isOwnProfile,
                // Owner sees their own Open listings regardless of their
                // active mode — they need to see what's hidden so they can
                // cancel from the profile or flip Active Mode back on.
                fn ($q) => $q->open(),
                // Visitors only see listings that are actually takeable —
                // status=Open AND owner.is_active_mode=true.
                fn ($q) => $q->onPublicMarketplace(),
            )
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
                'listing:id,user_id,game,stake_amount,platform,time_control,status',
                'listing.user:id,name,username',
                'taker:id,name,username',
                'winner:id,name,username',
                // GameMatchResource exposes snapshotted usernames per
                // listing.platform — eager-load to avoid N+1 on the
                // history list.
                'providerSnapshots',
            ])
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->limit(self::MATCH_HISTORY_LIMIT)
            ->get();

        // M18 Phase 2 — profile stats hero. One aggregation pass over the
        // user's settled matches, joined to listings for the stake column.
        // `forParticipant` handles "creator OR taker" via subquery; combined
        // with the explicit join, PG resolves it cleanly. CASE counters
        // produce wins / draws / losses without a second query.
        //
        // Public viewers get total_matches + total_volume only. Owner gets
        // those + win_rate. The win-rate-is-owner-only gate is a design
        // call (M18 milestone) — public win rate would invite strong
        // players to hunt weak ones, undercutting the safe-marketplace
        // identity. Chess.com / Lichess rating is the public skill signal.
        $aggregate = GameMatch::query()
            ->forParticipant($user->id)
            ->where('game_matches.status', MatchStatus::Settled)
            ->join('listings', 'listings.id', '=', 'game_matches.listing_id')
            ->selectRaw(
                'COUNT(*) AS total_matches,
                 COALESCE(SUM(listings.stake_amount), 0) AS total_volume,
                 COUNT(CASE WHEN game_matches.winner_user_id = ? THEN 1 END) AS wins,
                 COUNT(CASE WHEN game_matches.winner_user_id IS NULL THEN 1 END) AS draws,
                 COUNT(CASE WHEN game_matches.winner_user_id IS NOT NULL AND game_matches.winner_user_id <> ? THEN 1 END) AS losses',
                [$user->id, $user->id],
            )
            ->first();

        $stats = [
            'member_since' => $user->created_at->toIso8601String(),
            'total_matches' => (int) $aggregate->total_matches,
            // Float at the JSON boundary — same convention as
            // `ListingResource::toArray` for `stake_amount`. Internal money
            // math stays BCMath; this is a read-only display value.
            'total_volume' => (float) $aggregate->total_volume,
            'win_rate' => null,
        ];

        if ($isOwnProfile && $aggregate->total_matches > 0) {
            $decided = (int) $aggregate->wins + (int) $aggregate->losses;
            $stats['win_rate'] = [
                'wins' => (int) $aggregate->wins,
                'draws' => (int) $aggregate->draws,
                'losses' => (int) $aggregate->losses,
                // Percentage from decided matches only — draws don't count
                // toward win rate. Industry convention (chess.com,
                // Lichess). Null when no decided matches so the FE can
                // render '—' instead of a misleading 0%.
                'percentage' => $decided > 0
                    ? (int) round(((int) $aggregate->wins / $decided) * 100)
                    : null,
            ];
        }

        return Inertia::render('users/show', [
            'user' => (new UserProfileResource($user))->resolve(),
            'stats' => $stats,
            'openListings' => ListingResource::collection($openListings),
            'matchHistory' => GameMatchResource::collection($matchHistory),
        ]);
    }
}
