<?php

namespace App\Broadcasting;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;

/**
 * Channel authorization for the match chat. Mirrors `GameMatchPolicy::view`.
 *
 * Post-Pending: only the two match participants (listing creator + taker)
 * can subscribe.
 *
 * `LobbyFilling` (M34): all live lobby participants can subscribe so chat
 * works from day 1 of the lobby. Once `LobbyLockAction` flips the match
 * to `Pending`, the regular creator/taker check applies (the lobby roster
 * is captured in `match_provider_snapshots` and the match is now treated
 * as a played match).
 */
class MatchChannel
{
    public function join(User $user, int $matchId): bool
    {
        $match = GameMatch::query()->with('listing:id,user_id')->find($matchId);

        if ($match === null) {
            return false;
        }

        if ($match->status === MatchStatus::LobbyFilling) {
            return LobbyParticipant::query()
                ->where('listing_id', $match->listing_id)
                ->where('user_id', $user->id)
                ->live()
                ->exists();
        }

        return $user->id === $match->taker_user_id
            || $user->id === $match->listing->user_id;
    }
}
