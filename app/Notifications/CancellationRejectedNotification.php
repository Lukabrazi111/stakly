<?php

namespace App\Notifications;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Sent to the original cancellation requester when their opponent rejects.
 * Match continues, stakes stay in escrow. Requester is in a 30-min
 * per-user cooldown before they can re-request.
 */
class CancellationRejectedNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly User $rejecter,
    ) {}

    public function eventType(): string
    {
        return 'cancellation_rejected';
    }

    public function title(): string
    {
        return __('Cancellation rejected');
    }

    public function body(): string
    {
        return __(':name declined the cancellation. Match continues — play your game and confirm the result.', [
            'name' => $this->rejecter->name,
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
