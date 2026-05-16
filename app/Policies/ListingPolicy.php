<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

/**
 * Authorization for listing actions. Auto-discovered by Laravel 11+ via the
 * `App\Policies\{Model}Policy` convention — no manual `Gate::policy(...)`
 * registration needed.
 */
class ListingPolicy
{
    /**
     * Only the listing's creator can cancel it, and only while it's Open.
     * Taken / expired / already-cancelled listings cannot be cancelled —
     * `taken` is load-bearing: cancelling a taken listing would have to claw
     * back from the opponent, which is the match dispute path (M6), not the
     * listing-cancel path. (Per-listing pause was removed in M6 Phase 6.5;
     * hiding listings now goes through the global Active Mode toggle, which
     * doesn't change `status`.)
     */
    public function cancel(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && $listing->status === ListingStatus::Open;
    }
}
