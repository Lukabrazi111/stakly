<?php

namespace App\Observers;

use App\Events\LobbyUpdated;
use App\Models\GameMatch;

/**
 * Bridges match-status changes into the lobby Reverb channel. When the
 * status (or cancellation-request fields) on a team-play match changes,
 * we re-fire `LobbyUpdated` so the lobby page's `LobbyRealtimeSync`
 * listener triggers a `router.reload({ only: ['lobby'] })`. That keeps
 * the lobby's `match_status` (Coord-pulse color), chat read-only
 * gating, and header countdown in sync without a manual refresh.
 *
 * Why a model observer rather than per-Action dispatches: every Action
 * that mutates match.status (Settle, Dispute, AcceptCancellation,
 * AdminSettle*, SettleDraw, ResolveMatchTimeout) would otherwise need
 * its own dispatch line — easy to forget, easy to drift.
 *
 * Non-team-play (chess 1v1) matches skip the broadcast — there's no
 * lobby channel to listen on, so the event would be wasted work. The
 * match page's own polling already covers chess.
 */
class GameMatchObserver
{
    public function updated(GameMatch $match): void
    {
        if (! $match->wasChanged('status')) {
            return;
        }

        $listing = $match->listing;

        if ($listing === null || ! $listing->isTeamPlay()) {
            return;
        }

        LobbyUpdated::dispatch($listing);
    }
}
