<?php

namespace App\Broadcasting;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Channel authorization for `lobby.{listing}` — the real-time roster/state
 * channel for the team-play lobby UI on `pages/listings/show.tsx`.
 *
 * Delegates to `ListingPolicy::viewLobby` (M34 P5) so the live channel and the
 * HTTP page can never drift: public lobbies are open, private lobbies require
 * the creator / a live participant / an invite-token session pass, and kicked
 * players are barred. The broadcasting-auth request runs through `web`
 * middleware, so the session invite pass is readable here.
 */
class LobbyChannel
{
    public function join(User $user, Listing $listing): bool
    {
        return Gate::forUser($user)->allows('viewLobby', $listing);
    }
}
