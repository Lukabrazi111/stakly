<?php

namespace App\Broadcasting;

use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;

/**
 * Channel authorization for the match chat. Mirrors `GameMatchPolicy::view`.
 *
 * 1v1 matches: only the two participants (listing creator + taker) can
 * subscribe.
 *
 * Team matches (M34): all live lobby participants can subscribe, in every
 * match status — chat works from day 1 of the lobby and stays accessible
 * through Pending / Disputed / Settled. Kicked rows (`kicked_at IS NOT NULL`)
 * are excluded.
 */
class MatchChannel
{
    public function join(User $user, int $matchId): bool
    {
        $match = GameMatch::query()
            ->with('listing:id,user_id,team_size')
            ->find($matchId);

        if ($match === null) {
            return false;
        }

        if ($match->listing->isTeamPlay()) {
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
