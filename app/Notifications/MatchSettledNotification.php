<?php

namespace App\Notifications;

use App\Models\GameMatch;

/**
 * Sent to both players when a match settles via the API or player confirmation.
 * Outcome is recipient-perspective ('won' | 'lost' | 'draw'); dispatch site
 * sends two notifications, one per player. Admin-resolved disputes use
 * [[DisputeResolvedNotification]] instead so the player sees the
 * admin-intervened signal explicitly.
 */
class MatchSettledNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly string $outcome,
        public readonly string $payoutAmount = '0',
    ) {}

    public function eventType(): string
    {
        return 'match_settled';
    }

    public function title(): string
    {
        return match ($this->outcome) {
            'won' => __('You won!'),
            'lost' => __('Match settled — you lost.'),
            'draw' => __('Match ended in a draw.'),
            default => __('Match settled.'),
        };
    }

    public function body(): string
    {
        $payout = number_format((float) $this->payoutAmount, 2);

        return match ($this->outcome) {
            'won' => __('$:payout USDT paid to your wallet.', ['payout' => $payout]),
            'lost' => __('Stake locked as your opponent\'s payout.'),
            'draw' => __('Both stakes refunded to wallets.'),
            default => __('Match resolved.'),
        };
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
