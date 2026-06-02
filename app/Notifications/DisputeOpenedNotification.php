<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use App\Models\GameMatch;
use App\Models\User;

/**
 * Sent to the opponent of a player who opened a dispute. Money sits in
 * escrow until [[DisputeResolvedNotification]] fires or the game-API
 * arbitration completes via [[MatchSettledNotification]].
 */
class DisputeOpenedNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly User $opener,
    ) {}

    public function eventType(): string
    {
        return 'dispute_opened';
    }

    public function soundPriority(): SoundPriority
    {
        return SoundPriority::None;
    }

    public function title(): string
    {
        return __('Your opponent reported a problem');
    }

    public function body(): string
    {
        return __(':name opened a dispute. Admin will review — post any evidence in the match chat.', [
            'name' => $this->opener->name,
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
