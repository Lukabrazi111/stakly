<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use App\Models\GameMatch;

/**
 * Sent to both players when a match flips to ManualReview — either the 4h
 * confirmation window expired without an API-verified game, or the game-API
 * returned Unknown confidence on a dispute. Stakes stay in escrow until
 * admin resolves via the Filament panel.
 */
class MatchManualReviewNotification extends PlayerNotification
{
    public function __construct(public readonly GameMatch $match) {}

    public function eventType(): string
    {
        return 'match_manual_review';
    }

    public function soundPriority(): SoundPriority
    {
        return SoundPriority::None;
    }

    public function title(): string
    {
        return __('Match flagged for admin review');
    }

    public function body(): string
    {
        return __('Your stakes stay in escrow while admin reviews. Post any evidence (screenshot, game URL, PGN) in the match chat.');
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
