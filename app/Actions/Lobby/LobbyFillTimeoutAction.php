<?php

namespace App\Actions\Lobby;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * 24h listing-life timeout — the lobby never reached max soft-joined within
 * 24h of listing creation. Listing cancels, any Ready'd participants
 * refunded, match flipped Cancelled. Mirrors `ExpireListings` for the
 * non-team-play case.
 *
 * Fires regardless of current `lobby_state` (recruiting OR ready_checking) —
 * per the locked design, if 24h passes mid-`ready_checking` the listing
 * still cancels. The 24h is the LISTING's life, not just the recruiting
 * phase's.
 *
 * Sentinel returns:
 *   - `'cancelled'` → listing was eligible and got cancelled
 *   - `'noop'`      → listing is no longer in a cancellable state
 *                     (already locked / cancelled / expired)
 */
class LobbyFillTimeoutAction
{
    public function handle(Listing $listing): string
    {
        return DB::transaction(function () use ($listing) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if (! $locked->isTeamPlay()) {
                return 'noop';
            }

            if (! in_array($locked->lobby_state, ['recruiting', 'ready_checking'], true)) {
                return 'noop';
            }

            $locked->load('lobbyParticipants.user');

            $this->refundReadyParticipants($locked);
            $this->vacateAll($locked);
            $this->expireListing($locked);
            $this->cancelMatch($locked);

            return 'cancelled';
        });
    }

    private function refundReadyParticipants(Listing $listing): void
    {
        $stakeAmount = (string) $listing->stake_amount;

        foreach ($listing->lobbyParticipants as $participant) {
            if ($participant->kicked_at !== null) {
                continue;
            }

            if ($participant->is_ready) {
                Wallet::release(
                    user: $participant->user,
                    amount: $stakeAmount,
                    listing: $listing,
                    description: 'Lobby fill timed out (24h) — Ready stake refunded.',
                );
            }
        }
    }

    private function vacateAll(Listing $listing): void
    {
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->delete();
    }

    private function expireListing(Listing $listing): void
    {
        $listing->update([
            'status' => ListingStatus::Expired,
            'lobby_state' => 'expired',
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
