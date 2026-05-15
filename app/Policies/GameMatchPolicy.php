<?php

namespace App\Policies;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\User;

/**
 * Authorization for match actions. Auto-discovered by Laravel 11+ via the
 * `App\Policies\{Model}Policy` convention — no manual `Gate::policy(...)`
 * registration needed.
 *
 * The two participants (listing creator + taker) are the only ones who can
 * view, confirm, or dispute a match. Match URLs are private — sharing them
 * doesn't grant access.
 */
class GameMatchPolicy
{
    /**
     * Only the two participants can view a match. Non-participants get a 404
     * at the controller layer (not 403) to avoid leaking match existence.
     */
    public function view(User $user, GameMatch $match): bool
    {
        return $this->isParticipant($user, $match);
    }

    /**
     * Only participants can confirm an outcome, and only while the match is
     * still Pending. Disputed / Settled / ManualReview matches reject confirms.
     */
    public function confirm(User $user, GameMatch $match): bool
    {
        return $this->isParticipant($user, $match)
            && $match->status === MatchStatus::Pending;
    }

    /**
     * Only participants can open a dispute, and only while Pending. Once a
     * match is in Disputed / Settled / ManualReview, dispute is rejected.
     */
    public function openDispute(User $user, GameMatch $match): bool
    {
        return $this->isParticipant($user, $match)
            && $match->status === MatchStatus::Pending;
    }

    /**
     * Listing creator (via `match->listing->user_id`) or the taker
     * (`match->taker_user_id`) — anyone else fails participation.
     *
     * Note: this triggers a `listing` query if not eager-loaded. Controllers
     * should `with(['listing'])` when authorizing in a list context.
     */
    private function isParticipant(User $user, GameMatch $match): bool
    {
        return $user->id === $match->taker_user_id
            || $user->id === $match->listing->user_id;
    }
}
