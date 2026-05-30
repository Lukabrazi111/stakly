<?php

namespace App\Broadcasting;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Channel authorization for the match chat. Only the two match participants
 * (listing creator + taker) can subscribe; mirrors `GameMatchPolicy::view`.
 *
 * Extracted to a named class rather than an inline closure so the auth logic
 * is directly testable without going through `/broadcasting/auth` HTTP plumbing
 * (the test broadcasting connection is `null`, whose broadcaster doesn't run
 * callbacks).
 */
class MatchChannel
{
    public function join(User $user, int $matchId): bool
    {
        $match = GameMatch::query()->with('listing:id,user_id')->find($matchId);

        if ($match === null) {
            return false;
        }

        return $user->id === $match->taker_user_id
            || $user->id === $match->listing->user_id;
    }
}
