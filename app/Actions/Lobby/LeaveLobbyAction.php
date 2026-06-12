<?php

namespace App\Actions\Lobby;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Voluntary pre-lock leave from a lobby. Two paths depending on who leaves:
 *
 *   - Non-creator leaves → just their slot vacates. If they were Ready'd,
 *     `Wallet::release` refunds their stake. Their row is deleted (NOT
 *     `kicked_at`-marked — voluntary leave doesn't lock them out from
 *     rejoining); the partial unique indexes' slot uniqueness lets a new
 *     joiner reuse the slot immediately.
 *
 *   - Creator leaves → the whole lobby cancels (listing.status = Cancelled,
 *     lobby_state = cancelled, match flips Cancelled). Every other Ready'd
 *     participant gets refunded. Soft-joined-but-not-Ready participants have
 *     no stake to refund — their rows just delete.
 *
 * Locked lobbies (post-`LobbyLockAction`) reject all leaves — leaving
 * post-lock is a forfeit, handled by the existing match dispute / settlement
 * pipeline, not by this action.
 *
 * Sentinels:
 *   - `'left'`              → non-creator successfully vacated their slot
 *   - `'creator_cancelled'` → creator left; whole lobby cancelled, all stakes refunded
 *   - `'not_in_lobby'`      → user has no live participant row on this listing
 *   - `'locked'`            → lobby has already locked (post-Ready, match is Pending)
 */
class LeaveLobbyAction
{
    public function __construct(
        private readonly LobbyReadyCheckAction $readyCheck,
    ) {}

    public function handle(User $user, Listing $listing): string
    {
        $sentinel = DB::transaction(function () use ($user, $listing) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->lobby_state === 'locked') {
                return 'locked';
            }

            $participant = LobbyParticipant::query()
                ->where('listing_id', $locked->id)
                ->where('user_id', $user->id)
                ->live()
                ->first();

            if ($participant === null) {
                return 'not_in_lobby';
            }

            $isCreator = $user->id === $locked->user_id;

            if ($isCreator) {
                $this->cancelLobby($locked);

                return 'creator_cancelled';
            }

            $this->vacateSingleSlot($locked, $participant);

            return 'left';
        });

        if ($sentinel === 'left') {
            // Soft-joined count may have dropped below max — let the
            // ready-check helper demote `ready_checking` → `recruiting`.
            $this->readyCheck->handle($listing->fresh());
        }

        return $sentinel;
    }

    /**
     * Non-creator voluntary leave. Refund if they staked, delete the row.
     * State transitions are delegated to `LobbyReadyCheckAction` after the
     * transaction commits.
     */
    private function vacateSingleSlot(Listing $listing, LobbyParticipant $participant): void
    {
        if ($participant->is_ready) {
            Wallet::release(
                user: $participant->user,
                amount: (string) $listing->stake_amount,
                listing: $listing,
                description: 'Lobby leave — Ready stake refunded.',
            );
        }

        $participant->delete();
    }

    /**
     * Creator left → lobby cancels. Refund every Ready'd participant, delete
     * all participant rows, flip listing + match to cancelled. Caller's
     * controller surfaces a "you cancelled this lobby" toast.
     */
    private function cancelLobby(Listing $listing): void
    {
        $participants = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->with('user')
            ->get();

        $stakeAmount = (string) $listing->stake_amount;

        foreach ($participants as $participant) {
            if ($participant->is_ready) {
                Wallet::release(
                    user: $participant->user,
                    amount: $stakeAmount,
                    listing: $listing,
                    description: 'Lobby creator left — Ready stake refunded.',
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

        // Cancel the paired LobbyFilling match so the auto-fetch + dispute
        // gates downstream see it as terminal, not "active but waiting."
        $listing->gameMatch()->update([
            'status' => MatchStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
