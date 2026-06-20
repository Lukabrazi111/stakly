<?php

namespace App\Services\Payments\Dto;

/**
 * The persistent deposit destination a user funds. `address` is the on-chain
 * receive address shown on the deposit page; `providerAccountId` is the
 * provider's own handle for the sub-account/customer (null for the mock
 * driver, which has no remote account).
 */
final readonly class DepositAccount
{
    public function __construct(
        public string $address,
        public ?string $providerAccountId = null,
    ) {}
}
