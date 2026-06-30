<?php

namespace App\Actions\Lobby\Admin;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Events\LobbyUpdated;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Admin force-cancel of an OPEN team-play lobby. Mirrors the creator-left
 * cancellation (`LeaveLobbyAction::cancelLobby`) but is triggered from the
 * Filament admin instead of a participant: refund every Ready'd player's
 * escrow, vacate the roster, and flip the listing + paired match to cancelled.
 *
 * The 1v1 `CancelListingAction` is unsafe for team play — it releases ONLY the
 * creator's stake (often never held, since team stakes commit per Ready click,
 * so it can credit a stake that was never escrowed), strands every other
 * Ready'd player's escrow, and leaves `lobby_state` + the paired match
 * inconsistent. This action refunds the whole roster and closes the lobby.
 *
 * Idempotent: per-player refunds are keyed `lobby-force-cancel:{listing}:
 * player-{user}`, so a double-submit returns the existing ledger row.
 *
 * Sentinels: `'cancelled'` (was eligible + closed) | `'noop'` (not an Open
 * team-play lobby — already locked / cancelled / 1v1).
 */
class ForceCancelTeamLobbyAction
{
    public function handle(Listing $listing): string
    {
        $sentinel = DB::transaction(function () use ($listing): string {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if (! $locked->isTeamPlay() || $locked->status !== ListingStatus::Open) {
                return 'noop';
            }

            $locked->load('lobbyParticipants.user');

            $this->refundReadyParticipants($locked);
            $this->vacateAll($locked);
            $this->cancelListing($locked);
            $this->cancelMatch($locked);

            return 'cancelled';
        });

        if ($sentinel === 'cancelled') {
            LobbyUpdated::dispatch($listing);
        }

        return $sentinel;
    }

    private function refundReadyParticipants(Listing $listing): void
    {
        $stakeAmount = (string) $listing->stake_amount;

        foreach ($listing->lobbyParticipants as $participant) {
            if ($participant->kicked_at !== null || ! $participant->is_ready) {
                continue;
            }

            Wallet::release(
                user: $participant->user,
                amount: $stakeAmount,
                listing: $listing,
                reference: "lobby-force-cancel:{$listing->id}:player-{$participant->user_id}",
                description: 'Lobby force-cancelled by admin — Ready stake refunded.',
            );
        }
    }

    private function vacateAll(Listing $listing): void
    {
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->delete();
    }

    private function cancelListing(Listing $listing): void
    {
        $listing->update([
            'status' => ListingStatus::Cancelled,
            'lobby_state' => 'cancelled',
            'lobby_ready_check_deadline' => null,
        ]);
    }

    private function cancelMatch(Listing $listing): void
    {
        $listing->gameMatch()->update([
            'status' => MatchStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
