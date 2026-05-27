<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-creator trust aggregates for the listings marketplace (M22 Phase 1).
 *
 * Batches the calculation across N user IDs so a paginated `/listings` page
 * does ONE aggregation query, not one per row. Mirrors the formula used by
 * `App\Http\Controllers\UserController::show` — same 3-free-cancellation
 * buffer on the 30-day rate, no buffer on lifetime. If the formula changes
 * here, change there too (and consider consolidating).
 *
 * Returns `['rate_30d' => int|null, 'settled_lifetime' => int]` per user.
 * - `rate_30d` is `null` when the user has no engaged matches in the last
 *   30 days (no headline rate to show).
 * - `settled_lifetime` is the all-time settled count — the denominator that
 *   tells "98%" from "98% of 47 matches" on the listing chip.
 */
class SellerTrust
{
    /** Free cancellations per 30-day window before the rate starts dropping. */
    public const int FREE_CANCELLATIONS_PER_PERIOD = 3;

    /**
     * Convenience wrapper for controllers: takes a Listing collection (or
     * paginator), batch-loads the trust aggregate for each unique creator,
     * and attaches the result as a transient `seller_trust` attribute on
     * each listing model. `ListingResource` reads that attribute.
     *
     * @param  iterable<Listing>  $listings
     */
    public static function attachTo(iterable $listings): void
    {
        // Don't `collect($listings)->pluck()` — for a `LengthAwarePaginator`,
        // Collection's `getArrayableItems` calls `toArray()` which yields the
        // paginator's WRAPPER shape (data / total / per_page / etc.), not the
        // listing items themselves. Iterate explicitly to be paginator- and
        // plain-collection-safe.
        $userIds = [];
        foreach ($listings as $listing) {
            $userIds[] = $listing->user_id;
        }

        $trust = self::forBatch(array_values(array_unique(array_filter($userIds))));

        foreach ($listings as $listing) {
            $listing->setAttribute(
                'seller_trust',
                $trust[$listing->user_id] ?? ['rate_30d' => null, 'settled_lifetime' => 0],
            );
        }
    }

    /**
     * @param  array<int>|Collection<int, int>  $userIds
     * @return array<int, array{rate_30d: int|null, settled_lifetime: int}>
     */
    public static function forBatch(array|Collection $userIds): array
    {
        $userIds = collect($userIds)->unique()->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $thirtyDaysAgo = now()->subDays(30);

        // ONE query: every match where any user in the batch is a
        // participant (creator via `listings.user_id` OR taker via
        // `game_matches.taker_user_id`). Aggregation happens in PHP because
        // the per-user attribution depends on which side the user was on,
        // and the same match row can count for two different users if both
        // are in the batch (rare but possible — creator + taker both have
        // listings on the page).
        $rows = GameMatch::query()
            ->join('listings', 'listings.id', '=', 'game_matches.listing_id')
            ->where(function ($q) use ($userIds) {
                $q->whereIn('listings.user_id', $userIds)
                    ->orWhereIn('game_matches.taker_user_id', $userIds);
            })
            ->get([
                'listings.user_id as creator_id',
                'game_matches.taker_user_id',
                'game_matches.status',
                'game_matches.settled_at',
                'game_matches.cancellation_requested_by',
                'game_matches.cancelled_at',
            ]);

        $result = [];

        foreach ($userIds as $userId) {
            [$settled30d, $settledLifetime, $cancellations30d] = self::tallyForUser($rows, $userId, $thirtyDaysAgo);

            $incomplete30d = max(0, $cancellations30d - self::FREE_CANCELLATIONS_PER_PERIOD);
            $denom30d = $settled30d + $incomplete30d;

            $result[$userId] = [
                'rate_30d' => $denom30d > 0
                    ? (int) round(($settled30d / $denom30d) * 100)
                    : null,
                'settled_lifetime' => $settledLifetime,
            ];
        }

        return $result;
    }

    /**
     * Tally settled + cancellation counts for a single user across the
     * already-fetched match row set. Returns [settled_30d, settled_lifetime,
     * cancellations_30d].
     *
     * @param  Collection<int, object>  $rows
     * @return array{0: int, 1: int, 2: int}
     */
    private static function tallyForUser(Collection $rows, int $userId, CarbonInterface $thirtyDaysAgo): array
    {
        $settled30d = 0;
        $settledLifetime = 0;
        $cancellations30d = 0;

        foreach ($rows as $row) {
            $isCreator = (int) $row->creator_id === $userId;
            $isTaker = (int) $row->taker_user_id === $userId;

            if (! $isCreator && ! $isTaker) {
                continue;
            }

            // `status` is cast to `MatchStatus` on the GameMatch model, so
            // comparing to the enum (not its value) is what the cast hands us.
            if ($row->status === MatchStatus::Settled) {
                $settledLifetime++;

                if ($row->settled_at !== null && Carbon::parse($row->settled_at)->greaterThanOrEqualTo($thirtyDaysAgo)) {
                    $settled30d++;
                }
            }

            if (
                $row->status === MatchStatus::Cancelled
                && (int) $row->cancellation_requested_by === $userId
                && $row->cancelled_at !== null
                && Carbon::parse($row->cancelled_at)->greaterThanOrEqualTo($thirtyDaysAgo)
            ) {
                $cancellations30d++;
            }
        }

        return [$settled30d, $settledLifetime, $cancellations30d];
    }
}
