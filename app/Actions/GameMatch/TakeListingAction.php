<?php

namespace App\Actions\GameMatch;

use App\Enums\ListingStatus;
use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Models\Listing;
use App\Models\User;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Takes an open listing: escrows the taker's stake and creates the match
 * row, all inside one `DB::transaction` with a row lock on the listing.
 *
 * Three sentinel returns the controller maps to user-facing flows:
 *   - `GameMatch` instance → success; controller redirects to match page.
 *   - `'race_lost'`        → listing state changed between page load + submit
 *                            (Taken / Expired / Cancelled / past-expiry).
 *                            Controller redirects to listing with info toast.
 *   - `'owner_inactive'`   → owner flipped to Inactive Mode between page load
 *                            + submit. Controller info toast + redirect.
 *
 * Self-take is hard-rejected via `abort(403)` since the UI hides the Take
 * button for owners; reaching the action with a self-take is a hand-crafted
 * request.
 *
 * Insufficient-balance race propagates `InsufficientBalanceException` to
 * the controller, which maps to a `ValidationException` keyed on `amount`.
 *
 * Idempotency: `Wallet::hold` is keyed `match-take:{listing_id}`. A
 * successful Take followed by a retry POST hits the race-lost branch
 * (listing is now Taken) and gets the friendly redirect — double-clicks
 * are safe.
 */
class TakeListingAction
{
    public function handle(User $user, Listing $listing): GameMatch|string
    {
        return DB::transaction(function () use ($listing, $user) {
            $locked = Listing::query()->lockForUpdate()->findOrFail($listing->id);

            $this->assertNotSelfTake($locked, $user);

            if ($this->listingIsNoLongerOpen($locked)) {
                return 'race_lost';
            }

            if (! $this->ownerIsActive($locked)) {
                return 'owner_inactive';
            }

            $this->escrowTakerStake($user, $locked);
            $this->markListingTaken($locked);

            return $this->createMatch($locked, $user);
        });
    }

    private function assertNotSelfTake(Listing $listing, User $user): void
    {
        abort_if($listing->user_id === $user->id, 403, 'You cannot take your own listing.');
    }

    private function listingIsNoLongerOpen(Listing $listing): bool
    {
        return $listing->status !== ListingStatus::Open || $listing->expires_at->isPast();
    }

    /**
     * Active Mode gate (M6 Phase 6.5): if the owner flipped to Inactive
     * between the taker loading the listing detail page and submitting this
     * Take, the listing is no longer takeable. `lockForUpdate` on the owner
     * row serializes against any in-flight `ActiveModeController` toggle so
     * the check sees a consistent view. Active Mode is "I'm not available"
     * — it must gate the actual match-start, not just marketplace
     * visibility, otherwise stale browser tabs bypass the intent.
     */
    private function ownerIsActive(Listing $listing): bool
    {
        return (bool) User::query()
            ->lockForUpdate()
            ->where('id', $listing->user_id)
            ->value('is_active_mode');
    }

    /**
     * Throws `InsufficientBalanceException` for the rare race where the
     * balance dropped between TakeRequest's pre-check and now. The
     * controller catches that and maps to a `ValidationException`.
     */
    private function escrowTakerStake(User $user, Listing $listing): void
    {
        Wallet::hold(
            user: $user,
            amount: (string) $listing->stake_amount,
            listing: $listing,
            reference: "match-take:{$listing->id}",
            description: 'Stake escrowed on match take.',
        );
    }

    private function markListingTaken(Listing $listing): void
    {
        $listing->update(['status' => ListingStatus::Taken]);
    }

    private function createMatch(Listing $listing, User $taker): GameMatch
    {
        return GameMatch::create([
            'listing_id' => $listing->id,
            'taker_user_id' => $taker->id,
            'status' => MatchStatus::Pending,
        ]);
    }
}
