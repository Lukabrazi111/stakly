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
     * Cooldown applied to a player after their cancellation request is
     * rejected — prevents spam-cancel-request as a coercion tactic
     * ("cancel or I'll keep asking"). 30 minutes is short enough that an
     * honest follow-up request after fresh context still works.
     */
    private const CANCEL_REQUEST_COOLDOWN_MINUTES = 30;

    /**
     * Only the two participants can view a match. Non-participants get a 404
     * at the controller layer (not 403) to avoid leaking match existence.
     */
    public function view(User $user, GameMatch $match): bool
    {
        return $this->isParticipant($user, $match);
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
     * Only participants can request mutual cancellation, and only while
     * Pending. Additionally blocks if:
     *
     *   - An open request already exists on the match (one in flight at a
     *     time — second request gets a "there's already a request open"
     *     toast at the controller).
     *   - THIS user previously requested and got rejected within the last
     *     30 minutes (per-user cooldown — Bob being rejected doesn't gate
     *     Alice from requesting).
     */
    public function requestCancellation(User $user, GameMatch $match): bool
    {
        if (! $this->isParticipant($user, $match)) {
            return false;
        }

        if ($match->status !== MatchStatus::Pending) {
            return false;
        }

        if ($match->cancellation_requested_at !== null) {
            return false;
        }

        return ! $this->isInCooldown($user, $match);
    }

    /**
     * Only participants who are NOT the requester can accept the pending
     * cancellation. Match must be Pending and an open request must exist.
     * Blocks the requester from accepting their own request (UI doesn't
     * show them the accept button, but a direct POST bypassing the UI
     * would otherwise let them self-cancel).
     */
    public function acceptCancellation(User $user, GameMatch $match): bool
    {
        return $this->canRespondToCancellation($user, $match);
    }

    /**
     * Symmetric with `acceptCancellation` — only the non-requester can
     * reject. Same Pending + open-request preconditions.
     */
    public function rejectCancellation(User $user, GameMatch $match): bool
    {
        return $this->canRespondToCancellation($user, $match);
    }

    /**
     * Shared predicate for accept / reject: participant, Pending status,
     * open request exists, and the user is the OTHER participant (not the
     * one who requested).
     */
    private function canRespondToCancellation(User $user, GameMatch $match): bool
    {
        if (! $this->isParticipant($user, $match)) {
            return false;
        }

        if ($match->status !== MatchStatus::Pending) {
            return false;
        }

        if ($match->cancellation_requested_at === null) {
            return false;
        }

        return $match->cancellation_requested_by !== $user->id;
    }

    /**
     * Per-user cooldown check. Returns true iff this user previously
     * requested cancellation and the rejection timestamp is still inside
     * the cooldown window. A different user being mid-cooldown does not
     * affect this user (cooldown is keyed on `cancellation_requested_by`).
     */
    private function isInCooldown(User $user, GameMatch $match): bool
    {
        if ($match->cancellation_requested_by !== $user->id) {
            return false;
        }

        if ($match->cancellation_rejected_at === null) {
            return false;
        }

        return $match->cancellation_rejected_at
            ->addMinutes(self::CANCEL_REQUEST_COOLDOWN_MINUTES)
            ->isFuture();
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
