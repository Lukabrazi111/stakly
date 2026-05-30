<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use App\Models\GameMatch;

/**
 * Sent to the listing creator the moment an opponent takes the match.
 * The ONLY sound-enabled notification in Stakly — creator is typically
 * away from the page waiting, and the match starts immediately.
 */
class ListingTakenNotification extends PlayerNotification
{
    public function __construct(public readonly GameMatch $match) {}

    public function eventType(): string
    {
        return 'listing_taken';
    }

    public function soundPriority(): SoundPriority
    {
        return SoundPriority::Urgent;
    }

    public function title(): string
    {
        return __('Your listing was taken!');
    }

    public function body(): string
    {
        $taker = $this->match->taker?->username ?? 'Opponent';
        $stake = number_format((float) ($this->match->listing?->stake_amount ?? 0), 2);

        return __(':taker accepted your $:stake match. Head to the match to start playing.', [
            'taker' => $taker,
            'stake' => $stake,
        ]);
    }

    public function actionUrl(): string
    {
        return route('matches.show', $this->match);
    }

    public function relatedId(): ?int
    {
        return $this->match->id;
    }
}
