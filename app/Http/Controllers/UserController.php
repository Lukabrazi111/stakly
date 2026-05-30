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
     * Cancellations forgiven per rolling 30-day window before the completion
     * rate starts dropping. Mutual cancellation is the cooperative-exit
     * feature — penalizing users for using it as designed would misalign
     * incentives, so the first N per 30 days are "free." Beyond N, each
     * one pulls the 30-day rate down. Lifetime has no buffer.
     */
    private const FREE_CANCELLATIONS_PER_PERIOD = 3;

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

        // M18 Phase 3 Slice B — completion rate (Bybit-inspired). A single
        // composite metric: "of your engaged matches, how many got to
        // Settled?" Higher = better.
        //
        // Definitions:
        //   Completed   — match reached Settled status, regardless of path
        //                 (clean auto-fetch / dispute → API / admin-settled).
        //   Incomplete  — user-initiated cancellations beyond the 3-free
        //                 buffer. MR-in-flight + currently-Disputed matches
        //                 don't count either way (they're "pending
        //                 resolution"; admin always settles MR eventually).
        //
        // The 3-free buffer applies to the 30-day window only. Lifetime has
        // no buffer — every cancellation counts (the unvarnished track
        // record).
        //
        // No status filter on the FROM: `disputes_lifetime` (raw modal
        // signal) counts matches where `dispute_opened_at` was ever set,
        // regardless of final status. Pending matches are excluded
        // naturally — every CASE branch requires status = settled or
        // cancelled, or dispute_opened_at IS NOT NULL.
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
            // Per-window completion rate. Null when the window has no
            // engaged matches — FE picks the available window or hides.
            'rate_30d' => $denom30d > 0
                ? (int) round(($settled30d / $denom30d) * 100)
                : null,
            'rate_lifetime' => $denomLifetime > 0
                ? (int) round(($settledLifetime / $denomLifetime) * 100)
                : null,
            // Raw counts feed both the chip (settled_lifetime as the
            // experience signal) and the Data Overview tiles below the
            // hero (settled / cancelled / disputed breakdown).
            'settled_30d' => $settled30d,
            'settled_lifetime' => $settledLifetime,
            'cancellations_30d' => $cancellations30d,
            'cancellations_lifetime' => $cancellationsLifetime,
            'disputes_lifetime' => (int) $trustAggregate->disputes_lifetime,
        ];

        // M19 Phase 3 — repeat-pair count. Surfaces "you've played N matches
        // against this user" to authenticated visitors. Settled-only (the
        // only authoritative "we played" signal — pending/cancelled/disputed
        // don't count). Skipped entirely on own-profile and guest views.
        //
        // One SQL: join to listings (the creator side) so we can pair-match
        // in both directions with a single query rather than two whereHas
        // subqueries.
        $repeatPairCount = 0;
        $viewer = $request->user();
        if ($viewer && $viewer->id !== $user->id) {
            // `game_matches.status` is qualified — JOIN to `listings`
            // brings two `status` columns into scope (matches + listings),
            // unqualified `status` is ambiguous to Postgres.
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
            // M19 Phase 5 — Open Graph metadata for link previews. M26 P3
            // followup wired this through the shared `PageMeta` React
            // component, which renders these into Inertia's `<Head>` with
            // head-key dedup against the blade defaults in
            // `resources/views/app.blade.php`. SSR ensures the tags reach
            // crawlers (Discord, Telegram, Twitter) in the initial HTML.
            //
            // `image` points at `og-image.png` (1200×630) which the blade
            // template also uses as its default — same brand image, no
            // override needed for now. Per-profile generated cards (avatar +
            // handle + completion stat on a Stakly-branded background)
            // would be a follow-up if profile link sharing becomes a
            // notable traffic source.
            //
            // `url` is the only field that genuinely needs server-side
            // computation (absolute URL for the owner's share-link button
            // in `OwnerAccountSection`); title + description are derived
            // here so the resource shape stays self-contained.
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
