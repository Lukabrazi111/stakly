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
     * M34 — lobby visibility. Any team-play listing is viewable to anyone
     * holding the URL — `is_public = false` hides the listing from the
     * marketplace + gives the owner a memorable invite-token URL to share,
     * but the access model is "URL = access" so the token redirect can land
     * non-participants on the page so they can JOIN. Non-team-play listings
     * have no lobby page (their pair lives in the chess detail view).
     *
     * Trade-off: someone enumerating sequential listing IDs can see private
     * lobbies. M34 known limitation; tighten later with a token-confers-cookie
     * pattern if abuse appears.
     */
    public function viewLobby(?User $user, Listing $listing): bool
    {
        return $listing->isTeamPlay();
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
