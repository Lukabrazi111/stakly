<?php

namespace App\Actions\Lobby;

use App\Events\LobbyUpdated;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only kick. Refunds the target if they'd Ready'd, UPDATEs their row
 * to set `kicked_at = now()` (NOT deleted — the row is the 5-min same-listing
 * rejoin cooldown anchor). The partial unique indexes exempt kicked rows, so
 * the freed slot is immediately reclaimable by a new joiner.
 *
 * Re-evaluates `ready_checking` ↔ `recruiting` after the kick via
 * `LobbyReadyCheckAction` since the live participant count drops.
 *
 * Sentinels:
 *   - `'kicked'`            → target removed, refund posted if applicable
 *   - `'not_owner'`         → requester is not the listing creator
 *   - `'cant_kick_self'`    → target is the creator (owners can't kick themselves)
 *   - `'target_not_in_lobby'` → target has no live participant row
 *   - `'locked'`            → lobby has already locked (kick no-op post-Ready)
 */
class KickParticipantAction
{
    public function __construct(
        private readonly LobbyReadyCheckAction $readyCheck,
    ) {}

    public function handle(User $requester, Listing $listing, User $target): string
    {
        if ($requester->id !== $listing->user_id) {
            return 'not_owner';
        }

        if ($target->id === $listing->user_id) {
            return 'cant_kick_self';
        }

        $sentinel = DB::transaction(function () use ($listing, $target) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->lobby_state === 'locked') {
                return 'locked';
            }

            $participant = LobbyParticipant::query()
                ->where('listing_id', $locked->id)
                ->where('user_id', $target->id)
                ->live()
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                return 'target_not_in_lobby';
            }

            if ($participant->is_ready) {
                Wallet::release(
                    user: $target,
                    amount: (string) $locked->stake_amount,
                    listing: $locked,
                    description: 'Kicked from lobby — stake refunded.',
                );
            }

            $participant->update([
                'kicked_at' => now(),
                'is_ready' => false,
                'stake_held_at' => null,
            ]);

            return 'kicked';
        });

        if ($sentinel === 'kicked') {
            $this->readyCheck->handle($listing->fresh());
            LobbyUpdated::dispatch($listing, $target->id);
        }

        return $sentinel;
    }
}
