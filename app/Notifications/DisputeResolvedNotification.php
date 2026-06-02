<?php

namespace App\Notifications;

use App\Enums\SoundPriority;
use App\Models\GameMatch;

/**
 * Sent to both players when [[ResolveDisputeAction]] settles a Disputed
 * match. Distinct from [[MatchSettledNotification]] so the player sees the
 * admin-intervened path explicitly — "admin reviewed your dispute and
 * decided X" vs "auto-settled from API result."
 */
class DisputeResolvedNotification extends PlayerNotification
{
    public function __construct(
        public readonly GameMatch $match,
        public readonly string $outcome,
        public readonly string $payoutAmount = '0',
    ) {}

    public function eventType(): string
    {
        return 'dispute_resolved';
    }

    public function soundPriority(): SoundPriority
    {
        return SoundPriority::None;
    }

    public function title(): string
    {
        return match ($this->outcome) {
            'won' => __('Dispute resolved — you won.'),
            'lost' => __('Dispute resolved — you lost.'),
            'draw' => __('Dispute resolved — match ruled a draw.'),
            default => __('Dispute resolved.'),
        };
    }

    public function body(): string
    {
        $payout = number_format((float) $this->payoutAmount, 2);

        return match ($this->outcome) {
            'won' => __('Admin reviewed and ruled in your favor. $:payout USDT paid to your wallet.', ['payout' => $payout]),
            'lost' => __('Admin reviewed and ruled for your opponent. Stake locked as their payout.'),
            'draw' => __('Admin reviewed and ruled a draw. Both stakes refunded.'),
            default => __('Admin completed review.'),
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
