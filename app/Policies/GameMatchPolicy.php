<?php

namespace App\Policies;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\LobbyParticipant;
use App\Models\User;

/**
 * Authorization for match actions. The two participants (creator + taker)
 * are the only ones who can view, request cancellation, or dispute. Match
 * URLs are private — sharing them doesn't grant access.
 */
class GameMatchPolicy
{
    /**
     * Cooldown after a player's cancellation request is rejected — prevents
     * spam-cancel-request as a coercion tactic ("cancel or I'll keep asking").
     * 30 min is short enough that an honest follow-up still works.
     */
    private const CANCEL_REQUEST_COOLDOWN_MINUTES = 30;

    /**
     * Admin bypass is scoped to `view` only — admins read the chat / stream
     * attachments from the dispute panel without being participants. Admins
     * resolve via Filament, not the player UI. Non-admin non-participants
     * get a 404 at the controller (not 403) to avoid leaking match existence.
     */
    public function view(User $user, GameMatch $match): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isParticipant($user, $match);
    }

    public function openDispute(User $user, GameMatch $match): bool
    {
        return $this->isParticipant($user, $match)
            && $match->status === MatchStatus::Pending;
    }

    /**
     * Blocks if an open request already exists (one in flight at a time) OR
     * if THIS user was rejected within the cooldown window (per-user — Bob's
     * cooldown doesn't gate Alice).
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
     * Blocks the requester from accepting their own request — direct POSTs
     * bypassing the UI would otherwise let them self-cancel. For team
     * matches (M34 P6), also blocks the requester's team-mates from
     * accepting on their team's behalf, preserving the mutual-cancellation
     * premise (one team requests, the *other* team accepts).
     */
    public function acceptCancellation(User $user, GameMatch $match): bool
    {
        return $this->canRespondToCancellation($user, $match);
    }

    public function rejectCancellation(User $user, GameMatch $match): bool
    {
        return $this->canRespondToCancellation($user, $match);
    }

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

        if ($match->cancellation_requested_by === $user->id) {
            return false;
        }

        if ($match->listing->isTeamPlay()) {
            return ! $this->onSameTeamAs($user, $match->cancellation_requested_by, $match);
        }

        return true;
    }

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
     * Team matches (M34) — participation is the live lobby roster
     * (`kicked_at IS NULL`). The roster is preserved as `lobby_participants`
     * past lock, so this single check works for `LobbyFilling`, `Pending`,
     * `Disputed`, `ManualReview`, `Settled`, and `Cancelled` alike.
     *
     * 1v1 matches — the classic creator + taker pair.
     *
     * Triggers a `listing` query if not eager-loaded — controllers should
     * `with(['listing'])` (column whitelist must include `team_size`) when
     * authorizing in a list context.
     */
    private function isParticipant(User $user, GameMatch $match): bool
    {
        if ($match->listing->isTeamPlay()) {
            return $this->isLiveLobbyParticipant($user, $match);
        }

        return $user->id === $match->taker_user_id
            || $user->id === $match->listing->user_id;
    }

    private function isLiveLobbyParticipant(User $user, GameMatch $match): bool
    {
        return LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('user_id', $user->id)
            ->live()
            ->exists();
    }

    /**
     * True if `$userId` and `$otherUserId` are both live participants on
     * the same side of the lobby. Used to block a team-mate of the
     * cancellation requester from accepting on the team's behalf.
     *
     * Returns false on any data anomaly (either user missing from the live
     * roster) — fails closed so the policy errs toward forbidding the
     * action rather than allowing a same-team accept by accident.
     */
    private function onSameTeamAs(User $user, ?int $otherUserId, GameMatch $match): bool
    {
        if ($otherUserId === null) {
            return false;
        }

        $userSide = $this->lobbySideOf($user->id, $match);
        $otherSide = $this->lobbySideOf($otherUserId, $match);

        if ($userSide === null || $otherSide === null) {
            return false;
        }

        return $userSide === $otherSide;
    }

    private function lobbySideOf(int $userId, GameMatch $match): ?string
    {
        return LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('user_id', $userId)
            ->live()
            ->value('side');
    }
}
