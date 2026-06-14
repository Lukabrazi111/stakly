<?php

namespace App\Notifications;

use App\Models\GameMatch;

/**
 * Fires from `LobbyLockAction` when the final Ready click locks the lobby —
 * every locked-in participant (4 on Wingman 2v2, 10 on CS2 5v5) is notified
 * that the match is now live and they should head to FACEIT. Sound-default
 * ON because the moment is high-attention (you've staked, the game is now
 * ready to start) — same UX rationale as `listing_taken` for 1v1.
 */
class TeamMatchStartedNotification extends PlayerNotification
{
    public function __construct(public readonly GameMatch $match) {}

    public function eventType(): string
    {
        return 'team_match_started';
    }

    public function title(): string
    {
        return __('Your team match is starting!');
    }

    public function body(): string
    {
        $teamSize = (int) ($this->match->listing?->team_size ?? 0);
        $stake = number_format((float) ($this->match->listing?->stake_amount ?? 0), 2);

        return __('All :size players are Ready. Your $:stake match is live — head to FACEIT and queue together.', [
            'size' => $teamSize * 2,
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
