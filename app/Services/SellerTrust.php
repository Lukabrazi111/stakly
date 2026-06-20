<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-creator trust aggregates for the listings marketplace. Batches one
 * aggregation across N user IDs so a paginated `/listings` page does ONE
 * query, not one per row. Mirrors the formula in `UserController::show`
 * (same 3-free-cancellation buffer on 30-day rate, none on lifetime) — keep
 * both in sync if the formula changes.
 */
class SellerTrust
{
    /** Free cancellations per 30-day window before the rate starts dropping. */
    public const int FREE_CANCELLATIONS_PER_PERIOD = 3;

    /**
     * @param  iterable<Listing>  $listings
     */
    public static function attachTo(iterable $listings): void
    {
        // Don't `collect($listings)->pluck()` — for a `LengthAwarePaginator`,
        // Collection's `getArrayableItems` calls `toArray()` which yields the
        // paginator's WRAPPER shape (data / total / per_page / etc.), not the
        // items themselves. Iterate explicitly to be paginator-safe.
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

        // ONE query for every match where any user in the batch participates
        // (creator OR taker). PHP-side aggregation because the same match row
        // can count for two batch users (creator + taker both on the page).
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
     * @param  Collection<int, object>  $rows
     * @return array{0: int, 1: int, 2: int} [settled_30d, settled_lifetime, cancellations_30d]
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
