<?php

namespace App\Broadcasting;

use App\Models\Listing;
use App\Models\User;

/**
 * Channel authorization for `lobby.{listing}` — the real-time roster/state
 * channel for the team-play lobby UI on `pages/listings/show.tsx`.
 *
 * Mirrors `ListingPolicy::viewLobby`: any authenticated user holding the URL
 * can subscribe to a team-play listing's lobby channel. Non-team-play (1v1
 * chess) listings have no lobby surface and reject the subscription.
 *
 * Private listings (`is_public = false`) are gated by URL knowledge, not
 * channel auth — same trade-off the HTTP page uses (documented in
 * `ListingPolicy::viewLobby`). If a future abuse pattern shows id-enumeration
 * against private lobbies, tighten here AND there together.
 */
class LobbyChannel
{
    public function join(User $user, Listing $listing): bool
    {
        return $listing->isTeamPlay();
    }
}
