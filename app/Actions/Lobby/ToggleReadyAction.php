<?php

namespace App\Actions\Lobby;

use App\Events\LobbyUpdated;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Toggles a participant's Ready state in a lobby. Per-player atomic — each
 * Ready click escrows YOUR stake, each un-Ready releases YOUR stake.
 *
 * Going Ready → `Wallet::hold` + flip flags + check if all 2×team_size are
 * now Ready → fire `LobbyLockAction` inline.
 *
 * Going un-Ready → `Wallet::release` + clear flags. Pre-lock only; can't
 * un-Ready a locked lobby (return 'locked' sentinel).
 *
 * Insufficient balance at Ready: maps `InsufficientBalanceException` to a
 * sentinel — controller shows "top up" hint, doesn't penalize the player.
 *
 * Sentinels:
 *   - `'readied'`           → flipped from soft-joined to Ready
 *   - `'unreadied'`         → flipped from Ready back to soft-joined
 *   - `'locked_now'`        → final Ready click locked the lobby
 *   - `'not_in_lobby'`      → user has no live participant row
 *   - `'locked'`            → lobby already locked (post-Ready)
 *   - `'insufficient_balance'` → user's balance dropped below stake_amount
 *                                between page load + click
 */
class ToggleReadyAction
{
    public function __construct(
        private readonly LobbyLockAction $lock,
    ) {}

    public function handle(User $user, Listing $listing): string
    {
        try {
            $sentinel = DB::transaction(function () use ($user, $listing) {
                $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

                if ($locked->lobby_state === 'locked') {
                    return 'locked';
                }

                $participant = LobbyParticipant::query()
                    ->where('listing_id', $locked->id)
                    ->where('user_id', $user->id)
                    ->live()
                    ->lockForUpdate()
                    ->first();

                if ($participant === null) {
                    return 'not_in_lobby';
                }

                if ($participant->is_ready) {
                    $this->unReady($locked, $participant);

                    return 'unreadied';
                }

                $this->markReady($locked, $participant);

                if ($this->allParticipantsReady($locked)) {
                    $this->lock->handle($locked);

                    return 'locked_now';
                }

                return 'readied';
            });
        } catch (InsufficientBalanceException) {
            return 'insufficient_balance';
        }

        if (in_array($sentinel, ['readied', 'unreadied', 'locked_now'], true)) {
            LobbyUpdated::dispatch($listing);
        }

        return $sentinel;
    }

    private function markReady(Listing $listing, LobbyParticipant $participant): void
    {
        // No reference_id on purpose — Ready is a repeatable toggle, so a
        // static per-(listing,user) reference would trip Wallet's idempotency
        // on a re-Ready (returns the prior row, holds nothing) and short the
        // pot. These rows group in the admin trail via related_listing_id.
        Wallet::hold(
            user: $participant->user,
            amount: (string) $listing->stake_amount,
            listing: $listing,
            description: 'Lobby Ready — stake escrowed.',
        );

        $participant->update([
            'is_ready' => true,
            'stake_held_at' => now(),
        ]);
    }

    private function unReady(Listing $listing, LobbyParticipant $participant): void
    {
        Wallet::release(
            user: $participant->user,
            amount: (string) $listing->stake_amount,
            listing: $listing,
            description: 'Lobby un-Ready — stake refunded.',
        );

        $participant->update([
            'is_ready' => false,
            'stake_held_at' => null,
        ]);
    }

    /**
     * All `team_size × 2` live participants are Ready. Triggers
     * `LobbyLockAction`. Tight query — count live + Ready rows and compare
     * against expected max.
     */
    private function allParticipantsReady(Listing $listing): bool
    {
        $required = $listing->team_size * 2;

        $readyCount = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->live()
            ->where('is_ready', true)
            ->count();

        return $readyCount >= $required;
    }
}
