<?php

namespace App\Notifications;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Sent to the original cancellation requester when their opponent accepts.
 * Both stakes have been refunded by this point.
 */
class CancellationAcceptedNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly User $accepter,
    ) {}

    public function eventType(): string
    {
        return 'cancellation_accepted';
    }

    public function title(): string
    {
        return __('Cancellation accepted');
    }

    public function body(): string
    {
        return __(':name accepted. Match cancelled, stake refunded to your wallet.', [
            'name' => $this->accepter->name,
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
