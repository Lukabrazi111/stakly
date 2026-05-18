<?php

namespace App\Broadcasting;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Channel authorization for the match chat (M8 Phase 2).
 *
 * Wired in `routes/channels.php` as the handler for `match.{matchId}` —
 * Laravel calls the `join` method with the resolved user + matchId. Only the
 * two match participants (listing creator + taker) can subscribe. Mirrors
 * `GameMatchPolicy::view`.
 *
 * Admin reads via Filament dashboard (M12), not via channel subscription —
 * even an admin would have to be a participant to receive the live feed.
 *
 * Extracted to a named class rather than an inline closure so the auth logic
 * is directly testable without going through `/broadcasting/auth` HTTP
 * plumbing (the test broadcasting connection is `null`, whose broadcaster
 * doesn't run callbacks).
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
