<?php

namespace App\Actions\Listing;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Services\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an Open listing and refunds the escrow. Both operations live in
 * one `DB::transaction` so a partial failure can't leave the listing
 * Cancelled with the stake still held (or vice versa).
 *
 * `Wallet::release` is idempotent on `listing-cancel:{id}` — a double-submit
 * (network retry, double-click) returns the existing ledger row silently
 * instead of double-crediting.
 *
 * Authorization (creator-only, Open-only) is the caller's responsibility
 * via `ListingPolicy::cancel`. This Action trusts its input and assumes
 * the policy has already passed.
 */
class CancelListingAction
{
    public function handle(Listing $listing): void
    {
        DB::transaction(function () use ($listing) {
            Wallet::release(
                user: $listing->user,
                amount: (string) $listing->stake_amount,
                listing: $listing,
                reference: "listing-cancel:{$listing->id}",
                description: 'Stake refunded on listing cancellation.',
            );

            $listing->update(['status' => ListingStatus::Cancelled]);
        });
    }
}
