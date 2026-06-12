<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Http\RedirectResponse;

/**
 * M34 P2 — invite-link entry point for private (`is_public = false`) team-play
 * lobbies. Public listings reach players via the marketplace; private
 * listings reach them via this controller using an opaque 32-char token.
 *
 * For P2 we resolve the token and redirect to the existing listing-detail
 * page — P3 will replace the redirect with a dedicated lobby UI at the same
 * URL.
 *
 * 404 (not 403) on missing / expired / closed lobbies — never reveal whether
 * a token "used to exist", just whether you can act on it right now.
 */
class LobbyController extends Controller
{
    public function show(string $token): RedirectResponse
    {
        $listing = Listing::query()
            ->where('invite_token', $token)
            ->first();

        if ($listing === null) {
            abort(404);
        }

        if ($listing->status !== ListingStatus::Open) {
            abort(404);
        }

        // Locked = match already started; cancelled / expired = terminal.
        // Recruiting + ready_checking are joinable.
        if (in_array($listing->lobby_state, ['locked', 'cancelled', 'expired'], true)) {
            abort(404);
        }

        return to_route('listings.show', $listing);
    }
}
