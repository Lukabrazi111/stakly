<?php

namespace App\Enums;

/**
 * Result type returned by a `GameApi` driver. (Named "Confidence" historically;
 * with `Drawn` added it's now a three-way result-type discriminator. Kept to
 * avoid churn.)
 *
 * - Drawn refunds both stakes via `SettleDrawMatchAction` — no platform fee.
 * - Unknown transitions the match to ManualReview; money stays in escrow
 *   until an admin resolves it.
 */
enum GameApiConfidence: string
{
    case Confirmed = 'confirmed';
    case Drawn = 'drawn';
    case Unknown = 'unknown';
}
