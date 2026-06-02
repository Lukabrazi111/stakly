<?php

namespace App\Notifications;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Sent to the opponent of a player who proposed a mutual cancellation.
 * Recipient can accept or reject from the match page banner.
 */
class CancellationRequestedNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly User $requester,
    ) {}

    public function eventType(): string
    {
        return 'cancellation_requested';
    }

    public function title(): string
    {
        return __('Cancellation requested');
    }

    public function body(): string
    {
        return __(':name wants to call off the match. Accept to refund both stakes, or reject to keep playing.', [
            'name' => $this->requester->name,
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
