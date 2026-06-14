<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the user roster for a match — the single place that knows how
 * "participant" differs between 1v1 chess (creator + taker) and team-play
 * lobbies (live `lobby_participants` rows, kicked excluded).
 *
 * Used by notification fan-out across the GameMatch Actions (M34 P6) so
 * the participant query doesn't drift between Open / Request / Accept /
 * Reject paths.
 *
 * Returns `Eloquent\Collection` of `User` models so callers can pipe
 * directly into `Notification::send($users, $notification)`.
 */
class MatchParticipants
{
    /**
     * Every live participant on the match.
     *
     * @return Collection<int, User>
     */
    public static function all(GameMatch $match): Collection
    {
        $match->loadMissing('listing');

        if ($match->listing->isTeamPlay()) {
            return LobbyParticipant::query()
                ->where('listing_id', $match->listing_id)
                ->live()
                ->with('user')
                ->get()
                ->pluck('user');
        }

        $match->loadMissing(['listing.user', 'taker']);

        return new Collection(array_filter([$match->listing->user, $match->taker]));
    }

    /**
     * Every live participant NOT on the same side as `$user`. For 1v1
     * that's the single opponent; for team play it's the full opposing
     * roster. Returns an empty collection if `$user` isn't on the live
     * roster (defensive — fan-out callers want a clean "no recipients"
     * rather than a stranger leaking into the notification set).
     *
     * @return Collection<int, User>
     */
    public static function opposing(GameMatch $match, User $user): Collection
    {
        $match->loadMissing('listing');

        if ($match->listing->isTeamPlay()) {
            $userSide = LobbyParticipant::query()
                ->where('listing_id', $match->listing_id)
                ->where('user_id', $user->id)
                ->live()
                ->value('side');

            if ($userSide === null) {
                return new Collection;
            }

            return LobbyParticipant::query()
                ->where('listing_id', $match->listing_id)
                ->where('side', '!=', $userSide)
                ->live()
                ->with('user')
                ->get()
                ->pluck('user');
        }

        $match->loadMissing(['listing.user', 'taker']);

        if ($user->id === $match->listing->user_id) {
            return new Collection(array_filter([$match->taker]));
        }

        if ($user->id === $match->taker_user_id) {
            return new Collection(array_filter([$match->listing->user]));
        }

        return new Collection;
    }

    /**
     * Convenience: every participant except `$exclude`. Use when an Action
     * doesn't want to notify the user who triggered it (e.g. an accepter
     * doesn't need their own "accepted" notification).
     *
     * @return Collection<int, User>
     */
    public static function allExcept(GameMatch $match, User $exclude): Collection
    {
        return self::all($match)->reject(fn (User $u) => $u->id === $exclude->id)->values();
    }
}
