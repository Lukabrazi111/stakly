<?php

namespace App\Services\Payments\Dto;

/**
 * A verified, normalized provider webhook. `PaymentWebhookController` (M9)
 * consumes only this — never the raw provider envelope — so swapping providers
 * never ripples into the receiver.
 *
 * For `DepositCredited`: `address` / `providerAccountId` identify the user and
 * `amount` is the NET credit (after provider fee) keyed for idempotency by
 * `txHash`. For `PayoutUpdated`: `providerPayoutId` + `payoutStatus` (and
 * `txHash` / `networkFee` once confirmed) drive the withdrawal state machine.
 *
 * `raw` is the decoded provider payload, retained for audit. Money fields are
 * BCMath strings at scale 6.
 *
 * @phpstan-type RawPayload array<string, mixed>
 */
final readonly class GatewayWebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public GatewayEventType $type,
        public array $raw,
        public ?string $amount = null,
        public ?string $address = null,
        public ?string $providerAccountId = null,
        public ?string $txHash = null,
        public ?string $providerPayoutId = null,
        public ?GatewayPayoutStatus $payoutStatus = null,
    ) {}
}
