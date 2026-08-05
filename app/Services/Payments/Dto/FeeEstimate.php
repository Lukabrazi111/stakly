<?php

namespace App\Services\Payments\Dto;

/**
 * Estimated cost of a payout, for the withdraw-page fee preview. Both fields
 * are BCMath strings at scale 6. `networkFee` is the on-chain gas estimate;
 * `providerFee` is any flat/percentage the gateway charges on top (0 for the
 * mock driver). The platform margin is applied separately by the domain layer.
 */
final readonly class FeeEstimate
{
    public function __construct(
        public string $networkFee,
        public string $providerFee = '0',
    ) {}

    public function total(): string
    {
        return bcadd($this->networkFee, $this->providerFee, 6);
    }
}
