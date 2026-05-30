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

    private const MATCH_HISTORY_LIMIT = 10;

    /**
     * Cancellations forgiven per rolling 30-day window before the completion
     * rate starts dropping. Mutual cancellation is the cooperative-exit
     * feature — penalizing users for using it as designed would misalign
     * incentives, so the first N per 30 days are "free." Lifetime has no
     * buffer.
     */
    private const FREE_CANCELLATIONS_PER_PERIOD = 3;

    /**
     * Public read-only profile page. The platform user (`is_platform = true`)
     * is hidden so the seeded `stakly-platform` account never surfaces.
     */
    public function show(Request $request, User $user): Response
    {
        abort_if($user->is_platform, 404);

        // Eager-load linked accounts so the backwards-compat accessors on
        // `User` (chess_com_username / lichess_username / etc.) read from
        // the loaded collection.
        $user->load('linkedAccounts');

        $isOwnProfile = $request->user()?->id === $user->id;

        $openListings = $user->listings()
            ->when(
                $isOwnProfile,
                // Owner sees their own Open listings regardless of active
                // mode — they need to see what's hidden to cancel from the
                // profile or flip Active Mode back on.
                fn ($q) => $q->open(),
                fn ($q) => $q->onPublicMarketplace(),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_LISTINGS_LIMIT)
            ->get();

        // Pre-set the `user` relation so `ListingResource` renders the
        // creator chip without re-querying (saves one IN-query).
        $openListings->each(fn (Listing $listing) => $listing->setRelation('user', $user));

        // Settled-only — exposing pending matches would leak "user X is
        // currently in a $500 match with Y" to the world. Disputed /
        // ManualReview also omitted — result isn't yet authoritative.
        $matchHistory = GameMatch::query()
            ->forParticipant($user->id)
            ->where('status', MatchStatus::Settled)
            ->with([
                'listing:id,user_id,game,stake_amount,platform,time_control,status',
                'listing.user:id,name,username',
                'taker:id,name,username',
                'winner:id,name,username',
                'providerSnapshots',
            ])
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->limit(self::MATCH_HISTORY_LIMIT)
            ->get();

        // Profile stats hero. One aggregation pass over settled matches,
        // joined to listings for the stake column. CASE counters produce
        // wins / draws / losses without a second query.
        //
        // Win-rate is owner-only by design — public win rate would invite
        // strong players to hunt weak ones, undercutting the safe-marketplace
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
            // Float at the JSON boundary. Internal money math stays BCMath.
            'total_volume' => (float) $aggregate->total_volume,
            'win_rate' => null,
        ];

        if ($isOwnProfile && $aggregate->total_matches > 0) {
            $decided = (int) $aggregate->wins + (int) $aggregate->losses;
            $stats['win_rate'] = [
                'wins' => (int) $aggregate->wins,
                'draws' => (int) $aggregate->draws,
                'losses' => (int) $aggregate->losses,
                // Decided matches only — draws don't count (chess.com /
                // Lichess convention). Null when no decided matches so the
                // FE can render '—' instead of a misleading 0%.
                'percentage' => $decided > 0
                    ? (int) round(((int) $aggregate->wins / $decided) * 100)
                    : null,
            ];
        }

        // Completion rate: "of engaged matches, how many got to Settled?"
        //
        //   Completed   — Settled regardless of path (auto-fetch / dispute
        //                 → API / admin-settled).
        //   Incomplete  — user-initiated cancellations beyond the 3-free
        //                 30d buffer. MR-in-flight + Disputed don't count
        //                 either way ("pending resolution").
        //
        // The 3-free buffer is 30-day only; lifetime has none. No status
        // filter on the FROM — every CASE branch requires settled, cancelled,
        // or `dispute_opened_at IS NOT NULL`, so pending matches are
        // excluded naturally.
        $thirtyDaysAgo = now()->subDays(30);

        $trustAggregate = GameMatch::query()
            ->forParticipant($user->id)
            ->selectRaw(
                "COUNT(CASE WHEN game_matches.status = 'settled' AND game_matches.settled_at >= ? THEN 1 END) AS settled_30d,
                 COUNT(CASE WHEN game_matches.cancellation_requested_by = ? AND game_matches.status = 'cancelled' AND game_matches.cancelled_at >= ? THEN 1 END) AS cancellations_30d,
                 COUNT(CASE WHEN game_matches.status = 'settled' THEN 1 END) AS settled_lifetime,
                 COUNT(CASE WHEN game_matches.cancellation_requested_by = ? AND game_matches.status = 'cancelled' THEN 1 END) AS cancellations_lifetime,
                 COUNT(CASE WHEN game_matches.dispute_opened_at IS NOT NULL THEN 1 END) AS disputes_lifetime",
                [$thirtyDaysAgo, $user->id, $thirtyDaysAgo, $user->id],
            )
            ->first();

        $settled30d = (int) $trustAggregate->settled_30d;
        $cancellations30d = (int) $trustAggregate->cancellations_30d;
        $settledLifetime = (int) $trustAggregate->settled_lifetime;
        $cancellationsLifetime = (int) $trustAggregate->cancellations_lifetime;

        $incomplete30d = max(0, $cancellations30d - self::FREE_CANCELLATIONS_PER_PERIOD);
        $incompleteLifetime = $cancellationsLifetime;

        $denom30d = $settled30d + $incomplete30d;
        $denomLifetime = $settledLifetime + $incompleteLifetime;

        $trust = [
            // Null when the window has no engaged matches — FE picks the
            // available window or hides.
            'rate_30d' => $denom30d > 0
                ? (int) round(($settled30d / $denom30d) * 100)
                : null,
            'rate_lifetime' => $denomLifetime > 0
                ? (int) round(($settledLifetime / $denomLifetime) * 100)
                : null,
            'settled_30d' => $settled30d,
            'settled_lifetime' => $settledLifetime,
            'cancellations_30d' => $cancellations30d,
            'cancellations_lifetime' => $cancellationsLifetime,
            'disputes_lifetime' => (int) $trustAggregate->disputes_lifetime,
        ];

        // Repeat-pair count ("you've played N matches against this user").
        // Settled-only. Joined to `listings` (creator side) so we can
        // pair-match in both directions in one query rather than two
        // whereHas subqueries.
        $repeatPairCount = 0;
        $viewer = $request->user();
        if ($viewer && $viewer->id !== $user->id) {
            // `game_matches.status` is qualified — JOIN to `listings`
            // brings two `status` columns into scope; unqualified `status`
            // is ambiguous to Postgres.
            $repeatPairCount = GameMatch::query()
                ->where('game_matches.status', MatchStatus::Settled)
                ->join('listings', 'listings.id', '=', 'game_matches.listing_id')
                ->where(function ($q) use ($user, $viewer) {
                    $q->where(function ($q) use ($user, $viewer) {
                        $q->where('game_matches.taker_user_id', $viewer->id)
                            ->where('listings.user_id', $user->id);
                    })->orWhere(function ($q) use ($user, $viewer) {
                        $q->where('game_matches.taker_user_id', $user->id)
                            ->where('listings.user_id', $viewer->id);
                    });
                })
                ->count();
        }

        return Inertia::render('users/show', [
            'user' => (new UserProfileResource($user))->resolve(),
            'stats' => $stats,
            'trust' => $trust,
            'repeat_pair_count' => $repeatPairCount,
            'openListings' => ListingResource::collection($openListings),
            'matchHistory' => GameMatchResource::collection($matchHistory),
            // Open Graph metadata. `url` is the load-bearing computed field
            // (absolute URL for the owner's share-link button); title /
            // description are derived here so the resource stays
            // self-contained. SSR ensures the tags reach crawlers in the
            // initial HTML.
            'og' => [
                'title' => "{$user->name} (@{$user->username})",
                'description' => $user->bio !== null && $user->bio !== ''
                    ? (string) str($user->bio)->limit(160)
                    : "View {$user->name}'s chess listings and match history on Stakly. "
                        .($stats['total_matches'] > 0
                            ? "{$stats['total_matches']} settled matches."
                            : 'Take a listing to start a match.'),
                'image' => asset('og-image.png'),
                'url' => route('users.show', $user),
                'type' => 'profile',
            ],
        ]);
    }
}
