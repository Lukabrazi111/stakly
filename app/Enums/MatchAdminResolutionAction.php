<?php

namespace App\Enums;

/**
 * The three resolution actions an admin can take on a Disputed or
 * ManualReview match from the Filament admin panel (M12 Phase 2).
 *
 * Persisted as the `action` string column on `match_admin_resolutions`.
 * Read-only audit trail — no migration ever updates these rows in place.
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
