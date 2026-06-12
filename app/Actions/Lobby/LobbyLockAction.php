<?php

namespace App\Actions\Lobby;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\MatchProviderSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Locks a fully-Ready'd lobby. Transitions:
 *   - listing.status: Open → Taken
 *   - listing.lobby_state: ready_checking → locked
 *   - match.status: LobbyFilling → Pending
 *   - snapshots populated from each live participant's linked accounts,
 *     carrying `slot_index` so settlement can map cards back to users with
 *     team identity preserved.
 *
 * Idempotent on the listing's `lobby_state = locked` check — re-entry from
 * a concurrent ToggleReady that also detected "all Ready" no-ops cleanly.
 *
 * Caller (typically `ToggleReadyAction`) must already hold a row lock on
 * the listing — this action assumes the surrounding transaction has gated
 * the all-Ready check. Without the outer lock, two concurrent Ready
 * clicks could both observe "all Ready" and both call this. The
 * `lobby_state` guard catches that anyway, but the lock prevents double
 * snapshot writes.
 */
class LobbyLockAction
{
    public function handle(Listing $listing): void
    {
        DB::transaction(function () use ($listing) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->lobby_state === 'locked') {
                return;
            }

            $locked->load(['gameMatch', 'lobbyParticipants.user.linkedAccounts']);

            $this->populateSnapshots($locked);
            $this->markListingTaken($locked);
            $this->promoteMatchToPending($locked);
        });
    }

    /**
     * One `match_provider_snapshots` row per (live participant, linked
     * account). `slot_index` lets `SettleFromCardAction` resolve "Team A
     * slot 2" back to a specific user. Mirrors `TakeListingAction::
     * snapshotProviderAccounts` but spans the full team-play roster.
     *
     * `side` here is 'a' or 'b' rather than chess's 'creator' / 'taker' —
     * the snapshot schema accepts varchar(8), so both shapes coexist on
     * the same table.
     */
    private function populateSnapshots(Listing $listing): void
    {
        $rows = [];
        $now = now();

        foreach ($listing->lobbyParticipants->whereNull('kicked_at') as $participant) {
            foreach ($participant->user->linkedAccounts as $link) {
                $rows[] = [
                    'match_id' => $listing->gameMatch->id,
                    'side' => $participant->side,
                    'slot_index' => $participant->slot_index,
                    'provider' => $link->provider->value,
                    'username' => $link->username,
                    'provider_user_id' => $link->provider_user_id,
                    'skill_rating_snapshot' => $link->skill_rating,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($rows) > 0) {
            MatchProviderSnapshot::insert($rows);
        }
    }

    private function markListingTaken(Listing $listing): void
    {
        $listing->update([
            'status' => ListingStatus::Taken,
            'lobby_state' => 'locked',
            'lobby_ready_check_deadline' => null,
        ]);
    }

    private function promoteMatchToPending(Listing $listing): void
    {
        $listing->gameMatch->update([
            'status' => MatchStatus::Pending,
        ]);
    }
}
