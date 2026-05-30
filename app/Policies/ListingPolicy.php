<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    /**
     * Only the creator can cancel, and only while Open. `taken` is
     * load-bearing here: cancelling a taken listing would have to claw back
     * from the opponent — that's the match dispute path, not listing-cancel.
     */
    public function cancel(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && $listing->status === ListingStatus::Open;
    }
}
