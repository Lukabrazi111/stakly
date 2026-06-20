<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Payments\Dto\DepositAccount;
use App\Services\Payments\Dto\FeeEstimate;
use App\Services\Payments\Dto\GatewayEventType;
use App\Services\Payments\Dto\GatewayPayoutStatus;
use App\Services\Payments\Dto\GatewayWebhookEvent;
use App\Services\Payments\Dto\PayoutResult;
use App\Support\MockTronAddress;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Default `PaymentGateway` driver — deterministic, no network, no provider
 * account. Lets the entire billing ledger (M9 Phase 0b) and webhook receiver
 * be built and tested end-to-end with zero provider signup, key, or sandbox.
 *
 * It mocks the chain the same way `MockTronAddress` / the deposit page already
 * do: a payout "confirms" instantly with a `MOCK-…` tx hash, fees come from
 * config, and the webhook signature is a real HMAC so the receiver's
 * verification path is genuinely exercised. When M9 resumes, flipping
 * `PAYMENTS_DRIVER` to a real provider is the only change.
 */
final class MockGateway implements PaymentGateway
{
    public function ensureDepositAccount(User $user): DepositAccount
    {
        // Users already carry a mock TRC20 address from registration; only
        // synthesize one for the case where it's absent — and persist it, so
        // the contract's idempotency holds (a real gateway likewise creates +
        // stores the provider account on first call).
        $address = $user->tron_address;

        if (! is_string($address) || $address === '') {
            $address = MockTronAddress::generate();
            $user->forceFill(['tron_address' => $address])->save();
        }

        return new DepositAccount(address: $address);
    }

    public function createPayout(string $amount, string $address, string $reference): PayoutResult
    {
        // Deterministic in $reference so repeat calls (idempotency) and tests
        // see a stable id/hash without any randomness.
        return new PayoutResult(
            providerPayoutId: "mock-payout:{$reference}",
            status: GatewayPayoutStatus::Completed,
            txHash: 'MOCK-'.strtoupper(substr(hash('sha256', $reference), 0, 40)),
            networkFee: $this->mockNetworkFee(),
        );
    }

    public function estimatePayoutFee(string $amount, string $address): FeeEstimate
    {
        return new FeeEstimate(networkFee: $this->mockNetworkFee());
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = config('services.payments.mock.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $provided = (string) $request->header('X-Mock-Signature', '');

        return hash_equals($expected, $provided);
    }

    public function parseWebhookEvent(Request $request): GatewayWebhookEvent
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;

        return match ($event) {
            'deposit' => new GatewayWebhookEvent(
                type: GatewayEventType::DepositCredited,
                raw: $payload,
                amount: $this->requireString($payload, 'amount'),
                address: $this->requireString($payload, 'address'),
                txHash: $this->requireString($payload, 'tx_hash'),
            ),
            'payout' => new GatewayWebhookEvent(
                type: GatewayEventType::PayoutUpdated,
                raw: $payload,
                amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
                txHash: isset($payload['tx_hash']) ? (string) $payload['tx_hash'] : null,
                providerPayoutId: $this->requireString($payload, 'payout_id'),
                payoutStatus: $this->requirePayoutStatus($payload),
            ),
            default => throw new InvalidArgumentException(
                'MockGateway: unrecognized webhook event ['.(is_scalar($event) ? $event : gettype($event)).'].',
            ),
        };
    }

    /**
     * Pull a required scalar field as a non-empty string, failing fast — the
     * contract promises a throw (not a half-built event) on an unmappable
     * payload, so a downstream ledger write never sees a null key field.
     *
     * @param  array<string, mixed>  $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            throw new InvalidArgumentException("MockGateway: webhook missing required field [{$key}].");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requirePayoutStatus(array $payload): GatewayPayoutStatus
    {
        $raw = $this->requireString($payload, 'status');
        $status = GatewayPayoutStatus::tryFrom($raw);

        if ($status === null) {
            throw new InvalidArgumentException("MockGateway: unknown payout status [{$raw}].");
        }

        return $status;
    }

    private function mockNetworkFee(): string
    {
        return (string) config('services.payments.mock.network_fee', '1.500000');
    }
}
