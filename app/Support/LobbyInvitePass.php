<?php

namespace App\Support;

use App\Models\Listing;
use App\Policies\ListingPolicy;

/**
 * Per-session "invite pass" for private team-play lobbies (M34 P5).
 *
 * Opening a valid `/lobbies/{token}` link records the listing id here; the lobby
 * access gate ({@see ListingPolicy::viewLobby()} / `joinLobby`)
 * then treats the holder as invited. The token is the credential — without a
 * pass, the canonical `/listings/{id}` URL 404s for a private lobby, so
 * enumerating sequential ids reveals nothing.
 *
 * Session-scoped on purpose: the pass lives only as long as the browser session
 * and is never persisted. Holding a pass is NOT a blanket override — a kicked
 * player is still rejected by the policy's `kicked_at` check even while holding
 * one.
 */
final class LobbyInvitePass
{
    private const SESSION_KEY = 'lobby_invites';

    public static function grant(Listing $listing): void
    {
        $held = session(self::SESSION_KEY, []);

        if (! in_array($listing->id, $held, true)) {
            session()->push(self::SESSION_KEY, $listing->id);
        }
    }

    public static function holds(Listing $listing): bool
    {
        return in_array($listing->id, session(self::SESSION_KEY, []), true);
    }
}
