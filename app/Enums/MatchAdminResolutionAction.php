<?php

namespace App\Enums;

/**
 * Resolution actions an admin can take on a Disputed or ManualReview match.
 * Persisted on `match_admin_resolutions.action`. Read-only audit trail —
 * rows are never updated in place.
 */
enum MatchAdminResolutionAction: string
{
    case SettleToCreator = 'settle_to_creator';
    case SettleToTaker = 'settle_to_taker';
    case SettleDraw = 'settle_draw';

    public function label(): string
    {
        return match ($this) {
            self::SettleToCreator => 'Settled to creator',
            self::SettleToTaker => 'Settled to taker',
            self::SettleDraw => 'Settled as draw',
        };
    }
}
