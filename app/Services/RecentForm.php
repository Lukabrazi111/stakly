<?php

namespace App\Services;

use App\Enums\Game;
use App\Models\Listing;
use Illuminate\Support\Collection;

/**
 * Per-user recent W/L/D form (last N settled matches) for the M41 P7 CS2 cards.
 * Batches across N user IDs so a paginated page runs a fixed number of queries,
 * not one per row. Shares match participation + ledger-win detection with
 * {@see ParticipantStats} via {@see MatchParticipation}.
 *
 * Win/loss is read from the LEDGER (a payout = a win), so team wins resolve
 * correctly even though `winner_user_id` only holds the slot-0 winner. A DRAW is
 * `winner_user_id IS NULL` on a settled match. Scoped to ONE game (CS2 by
 * default) so the strip stays coherent with the FACEIT level dial it sits
 * beside. CS2 never draws, so 'D' won't appear in practice — the chip supports
 * it for future 1v1 reuse.
 */
class RecentForm
{
    public const int LIMIT = 5;

    public const string WIN = 'W';

    public const string LOSS = 'L';

    public const string DRAW = 'D';

    /**
     * Attach a transient `recent_form` (list of 'W'|'L'|'D', newest first) to
     * each listing's creator slot. Only listings for `$game` contribute a user
     * id; others get an empty form.
     *
     * @param  iterable<Listing>  $listings
     */
    public static function attachTo(iterable $listings, Game $game = Game::Cs2): void
    {
        // Iterate explicitly (paginator-safe — see SellerTrust::attachTo).
        $userIds = [];
        foreach ($listings as $listing) {
            if ($listing->game === $game) {
                $userIds[] = $listing->user_id;
            }
        }

        $forms = self::forBatch(array_values(array_unique(array_filter($userIds))), $game);

        foreach ($listings as $listing) {
            $listing->setAttribute('recent_form', $forms[$listing->user_id] ?? []);
        }
    }

    /**
     * @param  array<int>|Collection<int, int>  $userIds
     * @return array<int, list<string>> user_id => ['W','L','D',...] newest-first, ≤ LIMIT
     */
    public static function forBatch(array|Collection $userIds, Game $game = Game::Cs2): array
    {
        $userIds = collect($userIds)->unique()->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $matches = MatchParticipation::settledByUser($userIds, $game);
        $wins = MatchParticipation::wonListingIds($userIds);

        $result = [];

        foreach ($userIds as $userId) {
            $recent = ($matches[$userId] ?? collect())
                ->sortByDesc('settled_at')
                ->take(self::LIMIT);

            $result[$userId] = $recent
                ->map(fn (object $row): string => match (true) {
                    isset($wins["{$userId}:{$row->listing_id}"]) => self::WIN,
                    $row->winner_user_id === null => self::DRAW,
                    default => self::LOSS,
                })
                ->values()
                ->all();
        }

        return $result;
    }
}
