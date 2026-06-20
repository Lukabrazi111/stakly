<?php

namespace App\Services\Payments\Dto;

/**
 * Outcome of submitting a payout to the provider.
 *
 * `providerPayoutId` is the idempotency/lookup handle stored on the withdrawal
 * row. `txHash` / `networkFee` are null until the chain confirms (a provider
 * that returns immediately leaves them for the payout webhook to fill). Money
 * fields are BCMath strings at scale 6 — never floats.
 */
final readonly class PayoutResult
{
    public function __construct(
        public string $providerPayoutId,
        public GatewayPayoutStatus $status,
        public ?string $txHash = null,
        public ?string $networkFee = null,
    ) {}
}
