<?php

namespace App\Services\Payments\Dto;

/**
 * Provider-agnostic payout lifecycle, mapped from each provider's own payout
 * states. The `Withdrawals` service (M9 Phase 0b) translates these into the
 * app-facing `WithdrawalStatus` — keeping provider status drift out of the
 * domain model.
 */
enum GatewayPayoutStatus: string
{
    case Queued = 'queued';        // accepted by provider, not yet broadcast
    case Sending = 'sending';      // payout in flight on-chain
    case Completed = 'completed';  // on-chain confirmed
    case Failed = 'failed';        // provider rejected / send failed
}
