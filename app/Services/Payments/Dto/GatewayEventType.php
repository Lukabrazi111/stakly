<?php

namespace App\Services\Payments\Dto;

/**
 * Canonical class of inbound provider webhook, normalized away from each
 * provider's own status vocabulary so `PaymentWebhookController` (M9) can
 * branch on a stable surface.
 */
enum GatewayEventType: string
{
    case DepositCredited = 'deposit_credited';
    case PayoutUpdated = 'payout_updated';
}
