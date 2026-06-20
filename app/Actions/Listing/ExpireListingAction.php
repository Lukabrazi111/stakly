<?php

namespace App\Actions\Listing;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Notifications\ListingExpiredNotification;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Refunds escrow + flips status on a single listing past its expiry.
 * Designed to be called from the iteration loop in
 * `App\Console\Commands\ExpireListings`.
 *
 * Idempotent end-to-end:
 *   - Re-checks status + expiry inside a row-locked transaction so
 *     concurrent take / cancel can't race us into a double-action.
 *   - `Wallet::release` is keyed on `listing-expire:{id}`, so a crashed
 *     mid-run never double-refunds on retry.
 *
 * Returns `true` if the listing was expired, `false` if it was skipped
 * (deleted, no longer Open, or expiry no longer passed). Caller uses the
 * return value for run statistics.
 */
class ExpireListingAction
{
    public function handle(int $listingId): bool
    {
        $expired = DB::transaction(function () use ($listingId) {
            $listing = Listing::query()->lockForUpdate()->find($listingId);

            if (! $this->isStillExpirable($listing)) {
                return null;
            }

            $this->refundStake($listing);
            $this->markExpired($listing);

            return $listing;
        });

        if ($expired === null) {
            return false;
        }

        $expired->loadMissing('user');
        $expired->user->notify(new ListingExpiredNotification($expired));

        return true;
    }

    /**
     * Re-check inside the lock: a concurrent take or cancel may have
     * flipped status between the iteration's SELECT and our lock.
     */
    private function isStillExpirable(?Listing $listing): bool
    {
        return $listing !== null
            && $listing->status === ListingStatus::Open
            && $listing->expires_at->lte(now());
    }

    private function refundStake(Listing $listing): void
    {
        Wallet::release(
            user: $listing->user,
            amount: (string) $listing->stake_amount,
            listing: $listing,
            reference: "listing-expire:{$listing->id}",
            description: 'Stake refunded on listing expiry.',
        );
    }

    private function markExpired(Listing $listing): void
    {
        $listing->update(['status' => ListingStatus::Expired]);
    }
}
