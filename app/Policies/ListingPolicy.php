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
     * Only the listing's creator can cancel it, and only while it's Open OR
     * Paused. Both states still have escrow held, so a refund makes sense.
     * Taken / expired / already-cancelled listings cannot be cancelled —
     * `taken` is load-bearing: cancelling a taken listing would have to claw
     * back from the opponent, which is the match dispute path (M6), not the
     * listing-cancel path.
     */
    public function cancel(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && in_array($listing->status, [ListingStatus::Open, ListingStatus::Paused], true);
    }

    /**
     * Only the creator can pause, and only while the listing is Open. Pause
     * is a visibility-only state — escrow stays held, expiry clock keeps
     * ticking. See milestones.md M6 Phase 6 "Pause/resume locked decisions".
     */
    public function pause(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && $listing->status === ListingStatus::Open;
    }

    /**
     * Inverse of pause — only the creator, only when status is Paused.
     * Resume flips back to Open without any wallet operations (soft pause).
     */
    public function resume(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id
            && $listing->status === ListingStatus::Paused;
    }
}
