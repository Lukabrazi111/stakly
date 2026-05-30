<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use App\Models\Listing;

/**
 * Sent to the listing creator when [[ExpireListingAction]] runs on a listing
 * that aged past its TTL without being taken. Stake has been refunded by
 * this point. Links to /listings/mine where the Expired row is now visible.
 */
class ListingExpiredNotification extends PlayerNotification
{
    public function __construct(public readonly Listing $listing) {}

    public function eventType(): string
    {
        return 'listing_expired';
    }

    public function soundPriority(): SoundPriority
    {
        return SoundPriority::None;
    }

    public function title(): string
    {
        return __('Your listing expired');
    }

    public function body(): string
    {
        $stake = number_format((float) $this->listing->stake_amount, 2);

        return __('Your $:stake listing expired without being taken. Stake refunded to your wallet.', [
            'stake' => $stake,
        ]);
    }

    public function actionUrl(): string
    {
        return route('listings.mine');
    }

    public function relatedId(): ?int
    {
        return $this->listing->id;
    }
}
