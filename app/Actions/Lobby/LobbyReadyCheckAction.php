<?php

namespace App\Actions\Lobby;

use App\Models\Listing;
use App\Models\LobbyParticipant;

/**
 * Re-evaluates the `recruiting` ↔ `ready_checking` transition based on the
 * current live soft-joined count vs `team_size × 2`. Called from any action
 * that mutates `lobby_participants` (Join / Leave / Kick / ReadyTimeout) so
 * the state stays in sync with the roster.
 *
 * Idempotent: calling repeatedly with no roster change is a no-op.
 *
 * Two transitions:
 *   - `recruiting` + soft-joined count = `team_size × 2` → `ready_checking`,
 *     stamp `lobby_ready_check_deadline` 5 minutes out.
 *   - `ready_checking` + soft-joined count < `team_size × 2` → back to
 *     `recruiting`, clear `lobby_ready_check_deadline`. Already-Ready
 *     participants stay Ready'd (their stake stays escrowed).
 *
 * Does NOT touch `locked` / `cancelled` / `expired` states — those are
 * terminal-ish and own their own transitions.
 */
class LobbyReadyCheckAction
{
    /**
     * 5-minute deadline from CLAUDE.md / milestones.md M34 (locked decision).
     */
    private const READY_CHECK_TIMEOUT_MINUTES = 5;

    public function handle(Listing $listing): void
    {
        if (! $listing->isTeamPlay()) {
            return;
        }

        if (! in_array($listing->lobby_state, ['recruiting', 'ready_checking'], true)) {
            return;
        }

        $maxParticipants = $listing->team_size * 2;
        $liveCount = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->count();

        if ($listing->lobby_state === 'recruiting' && $liveCount >= $maxParticipants) {
            $listing->update([
                'lobby_state' => 'ready_checking',
                'lobby_ready_check_deadline' => now()->addMinutes(self::READY_CHECK_TIMEOUT_MINUTES),
            ]);

            return;
        }

        if ($listing->lobby_state === 'ready_checking' && $liveCount < $maxParticipants) {
            $listing->update([
                'lobby_state' => 'recruiting',
                'lobby_ready_check_deadline' => null,
            ]);
        }
    }
}
