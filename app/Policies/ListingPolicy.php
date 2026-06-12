<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;

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
     * M34 — lobby visibility. Public listings are anyone-readable (browse);
     * private listings are participant-only. Non-team-play listings have no
     * lobby page (their pair lives in `/listings/{id}` already).
     */
    public function viewLobby(?User $user, Listing $listing): bool
    {
        if (! $listing->isTeamPlay()) {
            return false;
        }

        if ($listing->is_public) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->isLiveLobbyParticipant($user, $listing);
    }

    public function joinLobby(User $user, Listing $listing): bool
    {
        return $listing->isTeamPlay();
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
}
