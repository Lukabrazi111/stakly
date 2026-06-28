<?php

namespace App\Services;

use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use App\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-user recent W/L/D form (last N settled matches) for the M41 P7 CS2 cards.
 * Batches across N user IDs so a paginated page runs a fixed number of queries,
 * not one per row. Sister to {@see SellerTrust} (completion rate) and
 * {@see ParticipantStats} (win rate).
 *
 * Win/loss is read from the LEDGER, not `game_matches.winner_user_id`: for TEAM
 * matches `winner_user_id` holds only the slot-0 winner (see
 * SettleTeamMatchAction), so a winning non-slot-0 player would look like a loss.
 * A player WON a match iff they received a `Wallet::payout` for it — uniform
 * across 1v1 + team. A DRAW is `winner_user_id IS NULL` on a settled match (both
 * refunded, no payout). Everything else they engaged in is a LOSS.
 *
 * Scoped to ONE game (CS2 by default) so the strip stays coherent with the
 * FACEIT level dial it sits beside. CS2 never draws, so 'D' won't appear in
 * practice — the chip supports it for future 1v1 reuse.
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

        $matches = self::participantMatches($userIds, $game);
        $wins = self::wonListingIds($userIds);

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

    /**
     * Settled `$game` matches each batch user engaged in — as creator, taker, OR
     * a live (non-kicked) lobby member, so team-play members who aren't the
     * creator/taker still count. Deduped by match per user (a creator who is also
     * a lobby member counts the match once).
     *
     * @param  Collection<int, int>  $userIds
     * @return array<int, Collection<int|string, object>>
     */
    private static function participantMatches(Collection $userIds, Game $game): array
    {
        $ids = $userIds->all();

        $creatorTaker = DB::table('game_matches as gm')
            ->join('listings as l', 'l.id', '=', 'gm.listing_id')
            ->where('gm.status', MatchStatus::Settled->value)
            ->where('l.game', $game->value)
            ->where(function ($q) use ($ids) {
                $q->whereIn('l.user_id', $ids)->orWhereIn('gm.taker_user_id', $ids);
            })
            ->get(['gm.id as match_id', 'gm.listing_id', 'gm.settled_at', 'gm.winner_user_id', 'l.user_id as creator_id', 'gm.taker_user_id']);

        $lobby = DB::table('lobby_participants as lp')
            ->join('game_matches as gm', 'gm.listing_id', '=', 'lp.listing_id')
            ->join('listings as l', 'l.id', '=', 'gm.listing_id')
            ->where('gm.status', MatchStatus::Settled->value)
            ->where('l.game', $game->value)
            ->whereNull('lp.kicked_at')
            ->whereIn('lp.user_id', $ids)
            ->get(['lp.user_id', 'gm.id as match_id', 'gm.listing_id', 'gm.settled_at', 'gm.winner_user_id']);

        /** @var array<int, Collection<int|string, object>> $byUser */
        $byUser = [];
        foreach ($ids as $userId) {
            $byUser[$userId] = collect();
        }

        $put = function (int $userId, object $row) use (&$byUser): void {
            if (isset($byUser[$userId])) {
                $byUser[$userId]->put($row->match_id, $row);
            }
        };

        foreach ($creatorTaker as $row) {
            $entry = (object) [
                'match_id' => $row->match_id,
                'listing_id' => $row->listing_id,
                'settled_at' => $row->settled_at,
                'winner_user_id' => $row->winner_user_id,
            ];
            $put((int) $row->creator_id, $entry);

            if ($row->taker_user_id !== null) {
                $put((int) $row->taker_user_id, $entry);
            }
        }

        foreach ($lobby as $row) {
            $put((int) $row->user_id, (object) [
                'match_id' => $row->match_id,
                'listing_id' => $row->listing_id,
                'settled_at' => $row->settled_at,
                'winner_user_id' => $row->winner_user_id,
            ]);
        }

        return $byUser;
    }

    /**
     * `"{user_id}:{listing_id}"` set of every listing a batch user received a
     * payout on — i.e. won. Team + 1v1 payouts both carry `related_listing_id`.
     *
     * @param  Collection<int, int>  $userIds
     * @return array<string, true>
     */
    private static function wonListingIds(Collection $userIds): array
    {
        $rows = DB::table('wallet_transactions')
            ->where('type', WalletTransactionType::Payout->value)
            ->whereIn('user_id', $userIds->all())
            ->whereNotNull('related_listing_id')
            ->get(['user_id', 'related_listing_id']);

        $set = [];
        foreach ($rows as $row) {
            $set["{$row->user_id}:{$row->related_listing_id}"] = true;
        }

        return $set;
    }
}
