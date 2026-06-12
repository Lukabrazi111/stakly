<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use Illuminate\Support\Collection;

/**
 * Per-user "Overall · Last 20" stats for the M34 P3.1 lobby slot cards.
 * Batches one aggregation across N user IDs so a 5v5 lobby (10 slots) does
 * ONE query, not 10. Uses settled `game_matches` only — pending / disputed
 * / cancelled aren't included in the win-rate denominator.
 *
 * Sister to `App\Services\SellerTrust` (completion rate + lifetime settled).
 * Kept separate because trust is about *completion* (settled vs cancelled
 * cooperative), and these stats are about *winning* (W/L outcome).
 */
class ParticipantStats
{
    /** Recent-form sample size shown next to "Overall" on the slot card. */
    public const int LAST_N = 20;

    /**
     * @param  array<int>|Collection<int, int>  $userIds
     * @return array<int, array{
     *     total_matches: int,
     *     wins: int,
     *     win_rate: int|null,
     *     last_played: int,
     *     last_wins: int,
     *     last_win_rate: int|null,
     * }>
     */
    public static function forBatch(array|Collection $userIds): array
    {
        $userIds = collect($userIds)->unique()->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        // One query for every settled match where any user in the batch
        // participates (creator OR taker). PHP-side aggregation because
        // the same match row can count for two batch users (creator +
        // taker both in the same lobby).
        $rows = GameMatch::query()
            ->join('listings', 'listings.id', '=', 'game_matches.listing_id')
            ->where('game_matches.status', MatchStatus::Settled)
            ->where(function ($q) use ($userIds) {
                $q->whereIn('listings.user_id', $userIds)
                    ->orWhereIn('game_matches.taker_user_id', $userIds);
            })
            ->orderByDesc('game_matches.settled_at')
            ->orderByDesc('game_matches.id')
            ->get([
                'listings.user_id as creator_id',
                'game_matches.taker_user_id',
                'game_matches.winner_user_id',
                'game_matches.settled_at',
            ]);

        $result = [];

        foreach ($userIds as $userId) {
            $userMatches = $rows->filter(
                fn ($row) => (int) $row->creator_id === (int) $userId
                    || (int) $row->taker_user_id === (int) $userId,
            )->values();

            $total = $userMatches->count();
            $wins = $userMatches
                ->filter(fn ($row) => (int) $row->winner_user_id === (int) $userId)
                ->count();

            $last = $userMatches->take(self::LAST_N);
            $lastPlayed = $last->count();
            $lastWins = $last
                ->filter(fn ($row) => (int) $row->winner_user_id === (int) $userId)
                ->count();

            $result[$userId] = [
                'total_matches' => $total,
                'wins' => $wins,
                'win_rate' => $total > 0 ? (int) round(($wins / $total) * 100) : null,
                'last_played' => $lastPlayed,
                'last_wins' => $lastWins,
                'last_win_rate' => $lastPlayed > 0 ? (int) round(($lastWins / $lastPlayed) * 100) : null,
            ];
        }

        return $result;
    }
}
