<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Per-user career total matches + win rate for the lobby slot cards (M34 P3.1)
 * + the match-page opponent stats. Batches across N user IDs so a 5v5 lobby (10
 * slots) does a fixed number of queries, not 10. Settled matches only.
 *
 * Participation + win detection come from {@see MatchParticipation} — creator
 * OR taker OR live lobby member, wins via the payout ledger — so a CS2 TEAM
 * player's matches + wins are counted correctly. (The original creator/taker +
 * `winner_user_id` approach showed 0 matches for non-creator/non-taker roster
 * members and missed non-slot-0 winners; M41 P7c fix.)
 *
 * Sister to {@see SellerTrust} (completion vs cancellation) — this is about
 * *winning* (W/L outcome). Counts across ALL games (career overall).
 */
class ParticipantStats
{
    /**
     * @param  array<int>|Collection<int, int>  $userIds
     * @return array<int, array{
     *     total_matches: int,
     *     wins: int,
     *     win_rate: int|null,
     * }>
     */
    public static function forBatch(array|Collection $userIds): array
    {
        $userIds = collect($userIds)->unique()->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $matches = MatchParticipation::settledByUser($userIds);
        $wins = MatchParticipation::wonListingIds($userIds);

        $result = [];

        foreach ($userIds as $userId) {
            $userMatches = $matches[$userId] ?? collect();
            $total = $userMatches->count();
            $winCount = $userMatches
                ->filter(fn (object $row): bool => isset($wins["{$userId}:{$row->listing_id}"]))
                ->count();

            $result[$userId] = [
                'total_matches' => $total,
                'wins' => $winCount,
                'win_rate' => $total > 0 ? (int) round(($winCount / $total) * 100) : null,
            ];
        }

        return $result;
    }
}
