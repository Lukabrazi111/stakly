<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Support\LobbyInvitePass;

class ListingPolicy
{
    /**
     * Only the creator can cancel, and only while Open. `taken` is
     * load-bearing here: cancelling a taken listing would have to claw back
     * from the opponent — that's the match dispute path, not listing-cancel.
     */
    public function cancel(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && $listing->status === ListingStatus::Open;
    }

    /**
     * Lobby visibility (M34 P5 — private-access hardening). Only team-play
     * listings have a lobby page. PUBLIC lobbies are marketplace-listed, so
     * URL = access (guests included). PRIVATE lobbies (`is_public = false`) are
     * gated — see {@see self::canAccessPrivateLobby()}. Denials 404 at the
     * caller so a private lobby's existence never leaks.
     */
    public function viewLobby(?User $user, Listing $listing): bool
    {
        if (! $listing->isTeamPlay()) {
            return false;
        }

        if ($listing->is_public) {
            return true;
        }

        return $this->canAccessPrivateLobby($user, $listing);
    }

    /**
     * Same access rule as {@see self::viewLobby()} — joining is the money/slot
     * action, so it's gated independently of the page render (defends a direct
     * POST). Public lobbies accept any authenticated user.
     */
    public function joinLobby(User $user, Listing $listing): bool
    {
        if (! $listing->isTeamPlay()) {
            return false;
        }

        if ($listing->is_public) {
            return true;
        }

        return $this->canAccessPrivateLobby($user, $listing);
    }

    /**
     * Private-lobby access (M34 P5). Login is required to view (no guests). The
     * creator and current participants always pass; anyone who opened the
     * invite link carries a per-session pass ({@see LobbyInvitePass}). A kicked
     * player is barred for good — a kick ejects, it's not a 5-min timeout
     * (voluntary leave deletes the row, so leavers keep access).
     */
    private function canAccessPrivateLobby(?User $user, Listing $listing): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->id === $listing->user_id) {
            return true;
        }

        if ($this->hasBeenKicked($user, $listing)) {
            return false;
        }

        return $this->isLiveLobbyParticipant($user, $listing)
            || LobbyInvitePass::holds($listing);
    }

    public function leaveLobby(User $user, Listing $listing): bool
    {
        return $this->isLiveLobbyParticipant($user, $listing);
    }

    public function toggleReady(User $user, Listing $listing): bool
    {
        return $this->isLiveLobbyParticipant($user, $listing);
    }

    public function kickFromLobby(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id;
    }

    private function isLiveLobbyParticipant(User $user, Listing $listing): bool
    {
        return LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->live()
            ->exists();
    }

    /**
     * Was this user kicked from this listing's lobby? A kick stamps `kicked_at`
     * (the row stays as audit); a voluntary leave DELETES the row, so this is
     * true only for genuinely-kicked players. Used to bar re-entry to a private
     * lobby (M34 P5) — permanent, not the 5-min same-listing rejoin cooldown
     * the public-lobby JoinLobbyAction still applies.
     */
    private function hasBeenKicked(User $user, Listing $listing): bool
    {
        return LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->whereNotNull('kicked_at')
            ->exists();
    }
}
