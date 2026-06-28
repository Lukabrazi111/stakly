<?php

namespace App\Actions\Lobby;

use App\Enums\ListingStatus;
use App\Events\LobbyUpdated;
use App\Models\Listing;
use App\Models\LobbyParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-join a team-play lobby. No stake commitment yet — that happens at
 * `ToggleReadyAction` time. Returns the created participant row on success,
 * or a sentinel string the controller maps to a flash toast.
 *
 * Sentinels:
 *   - `LobbyParticipant`     → success
 *   - `'not_team_play'`      → listing is 1v1 (`team_size = 1`); use the
 *                              `TakeListingAction` flow instead
 *   - `'listing_unavailable'` → listing is not Open / lobby is past
 *                               `recruiting`
 *   - `'not_linked'`         → user has no verified account on the listing's
 *                              platform
 *   - `'already_in_lobby'`   → user is already in another active team-play
 *                              lobby (global single-lobby rule)
 *   - `'kick_cooldown'`      → user was kicked from THIS listing within the
 *                              5-min cooldown window
 *   - `'no_open_slots'`      → no slot available on the requested side
 *
 * Slot race safety: the partial unique index on
 * `(listing_id, side, slot_index) WHERE kicked_at IS NULL` makes concurrent
 * claims of the same slot fail at the DB level. We pick the next open slot
 * inside a row-locked transaction.
 */
class JoinLobbyAction
{
    public function __construct(
        private readonly LobbyReadyCheckAction $readyCheck,
    ) {}

    public function handle(User $user, Listing $listing, string $side): LobbyParticipant|string
    {
        if (! $listing->isTeamPlay()) {
            return 'not_team_play';
        }

        if (! $user->isVerifiedOn($listing->platform)) {
            return 'not_linked';
        }

        if ($user->activeLobbyParticipation() !== null) {
            return 'already_in_lobby';
        }

        $result = DB::transaction(function () use ($user, $listing, $side) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            if (! $this->lobbyAcceptsJoins($locked)) {
                return 'listing_unavailable';
            }

            if ($this->withinKickCooldown($locked, $user)) {
                return 'kick_cooldown';
            }

            $slotIndex = $this->firstOpenSlot($locked, $side);

            if ($slotIndex === null) {
                return 'no_open_slots';
            }

            return LobbyParticipant::create([
                'listing_id' => $locked->id,
                'user_id' => $user->id,
                'side' => $side,
                'slot_index' => $slotIndex,
                'is_ready' => false,
                'stake_held_at' => null,
                'kicked_at' => null,
                'joined_at' => now(),
            ]);
        });

        if ($result instanceof LobbyParticipant) {
            // Re-evaluate `recruiting` ↔ `ready_checking` now that the
            // soft-joined count may have hit max.
            $this->readyCheck->handle($listing->fresh());
            LobbyUpdated::dispatch($listing);
        }

        return $result;
    }

    /**
     * Lobby accepts new soft-joins only in `recruiting`. Once
     * `ready_checking` starts the soft-join roster is frozen until either
     * everyone Ready's (→ lock) or someone times out (→ back to recruiting).
     */
    private function lobbyAcceptsJoins(Listing $listing): bool
    {
        return $listing->status === ListingStatus::Open
            && $listing->lobby_state === 'recruiting';
    }

    private function withinKickCooldown(Listing $listing, User $user): bool
    {
        return LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->withinKickCooldown()
            ->exists();
    }

    /**
     * Lowest unclaimed slot_index on the given side (0..team_size-1). Returns
     * null when the side is full. Live rows only — kicked rows don't occupy
     * slots (the partial unique index treats them as exempt).
     */
    private function firstOpenSlot(Listing $listing, string $side): ?int
    {
        $taken = LobbyParticipant::query()
            ->where('listing_id', $listing->id)
            ->where('side', $side)
            ->live()
            ->pluck('slot_index')
            ->all();

        for ($i = 0; $i < $listing->team_size; $i++) {
            if (! in_array($i, $taken, true)) {
                return $i;
            }
        }

        return null;
    }
}
