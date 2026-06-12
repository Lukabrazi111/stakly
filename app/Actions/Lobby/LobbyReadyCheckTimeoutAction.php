<?php

namespace App\Actions\Lobby;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Fires when a `ready_checking` listing's 5-minute deadline passes without
 * everyone having Ready'd. Per the M34 design:
 *
 *   - **Already-Ready participants stay Ready.** They're on time; not
 *     penalized. Their stake stays escrowed.
 *   - **Non-Ready participants get vacated.** Slots reopen for new joiners.
 *     No refund needed — they hadn't staked.
 *   - **Special case: the listing creator is among the non-Ready.** Creator
 *     leaving cancels the whole lobby (mirrors `LeaveLobbyAction`'s
 *     creator-leave path). Every Ready'd participant gets refunded; listing
 *     + match flip to Cancelled.
 *   - **Lobby reverts to `recruiting`** afterwards if it stays open. The
 *     ready-check cron is responsible for re-triggering this action when
 *     the soft-joined count climbs back to max.
 *
 * Returns a sentinel for telemetry:
 *   - `'cancelled'` → creator was non-Ready, whole lobby cancelled
 *   - `'reverted'`  → some non-Ready vacated, lobby back to recruiting
 *   - `'noop'`      → listing isn't `ready_checking`, or deadline hasn't passed
 *                     (defensive — cron should pre-filter)
 */
class LobbyReadyCheckTimeoutAction
{
    public function handle(Listing $listing): string
    {
        return DB::transaction(function () use ($listing) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->lobby_state !== 'ready_checking') {
                return 'noop';
            }

            if ($locked->lobby_ready_check_deadline === null
                || $locked->lobby_ready_check_deadline->isFuture()
            ) {
                return 'noop';
            }

            $locked->load('lobbyParticipants.user');

            $creatorIsNonReady = $locked->lobbyParticipants
                ->whereNull('kicked_at')
                ->where('user_id', $locked->user_id)
                ->where('is_ready', false)
                ->isNotEmpty();

            if ($creatorIsNonReady) {
                $this->cancelLobby($locked);

                return 'cancelled';
            }

            $this->vacateNonReady($locked);

            return 'reverted';
        });
    }

    /**
     * Creator was non-Ready when the timer fired → whole lobby cancels.
     * Refund any participant who was Ready'd.
     */
    private function cancelLobby(Listing $listing): void
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
                    description: 'Ready-check timed out — creator was non-Ready, refund.',
                );
            }
        }

        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->delete();

        $listing->update([
            'status' => ListingStatus::Cancelled,
            'lobby_state' => 'cancelled',
            'lobby_ready_check_deadline' => null,
        ]);

        $listing->gameMatch()->update([
            'status' => MatchStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Standard timeout — non-Ready participants vacated, lobby back to
     * recruiting. Ready'd participants keep their stake escrowed.
     */
    private function vacateNonReady(Listing $listing): void
    {
        LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->where('is_ready', false)
            ->delete();

        $listing->update([
            'lobby_state' => 'recruiting',
            'lobby_ready_check_deadline' => null,
        ]);
    }
}
