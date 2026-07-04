<?php

namespace App\Services;

use App\Enums\Game;
use App\Enums\MatchStatus;
use App\Enums\WalletTransactionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared primitive for "which settled matches did these users engage in, and
 * which did they win?" — used by {@see RecentForm} (ordered W/L/D, game-scoped)
 * and {@see ParticipantStats} (career counts, all games).
 *
 * Participation is creator OR taker OR a live (non-kicked) lobby member, so
 * team-play roster members who aren't the creator/taker still count. Wins come
 * from the payout LEDGER, not `game_matches.winner_user_id`: team matches stamp
 * only the slot-0 winner (see SettleTeamMatchAction), so a non-slot-0 winner
 * would otherwise be missed. A user won a match iff they received a
 * `Wallet::payout` for it — uniform across 1v1 + team.
 */
class MatchParticipation
{
    /**
     * Settled matches each batch user engaged in, optionally scoped to one game.
     * Deduped by match per user (a creator who is also a lobby member counts the
     * match once).
     *
     * @param  Collection<int, int>  $userIds
     * @return array<int, Collection<int|string, object>> user_id => match rows
     *                                                    {match_id, listing_id, settled_at, winner_user_id}
     */
    public static function settledByUser(Collection $userIds, ?Game $game = null): array
    {
        $ids = $userIds->all();

        $creatorTaker = DB::table('game_matches as gm')
            ->join('listings as l', 'l.id', '=', 'gm.listing_id')
            ->where('gm.status', MatchStatus::Settled->value)
            ->when($game !== null, fn ($q) => $q->where('l.game', $game->value))
            ->where(function ($q) use ($ids) {
                $q->whereIn('l.user_id', $ids)->orWhereIn('gm.taker_user_id', $ids);
            })
            ->get(['gm.id as match_id', 'gm.listing_id', 'gm.settled_at', 'gm.winner_user_id', 'l.user_id as creator_id', 'gm.taker_user_id']);

        $lobby = DB::table('lobby_participants as lp')
            ->join('game_matches as gm', 'gm.listing_id', '=', 'lp.listing_id')
            ->join('listings as l', 'l.id', '=', 'gm.listing_id')
            ->where('gm.status', MatchStatus::Settled->value)
            ->when($game !== null, fn ($q) => $q->where('l.game', $game->value))
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
    public static function wonListingIds(Collection $userIds): array
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
