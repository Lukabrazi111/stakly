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
     *
     * For `LobbyFilling` matches (M34), the participant set is the lobby
     * roster (`lobby_participants`), not just the chess-style
     * creator+taker pair — chat is available from day 1 of the lobby so
     * every soft-joined player must be able to read it.
     */
    public function view(User $user, GameMatch $match): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($match->status === MatchStatus::LobbyFilling) {
            return $this->isLobbyParticipant($user, $match);
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
     * bypassing the UI would otherwise let them self-cancel.
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

        return $match->cancellation_requested_by !== $user->id;
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
     * Triggers a `listing` query if not eager-loaded — controllers should
     * `with(['listing'])` when authorizing in a list context.
     */
    private function isParticipant(User $user, GameMatch $match): bool
    {
        return $user->id === $match->taker_user_id
            || $user->id === $match->listing->user_id;
    }

    /**
     * Live participant on the match's listing — kicked rows excluded. M34
     * lobby roster check; only meaningful while the match is in
     * `LobbyFilling`. After lock, the snapshot pipeline takes over and
     * `isParticipant` (extended via `match_provider_snapshots`) is the
     * source of truth.
     */
    private function isLobbyParticipant(User $user, GameMatch $match): bool
    {
        return LobbyParticipant::query()
            ->where('listing_id', $match->listing_id)
            ->where('user_id', $user->id)
            ->live()
            ->exists();
    }
}
