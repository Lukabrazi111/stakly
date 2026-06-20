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
        // synthesize one for the (test) case where it's absent.
        return new DepositAccount(
            address: $user->tron_address ?? MockTronAddress::generate(),
        );
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
                amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
                address: isset($payload['address']) ? (string) $payload['address'] : null,
                txHash: isset($payload['tx_hash']) ? (string) $payload['tx_hash'] : null,
            ),
            'payout' => new GatewayWebhookEvent(
                type: GatewayEventType::PayoutUpdated,
                raw: $payload,
                amount: isset($payload['amount']) ? (string) $payload['amount'] : null,
                txHash: isset($payload['tx_hash']) ? (string) $payload['tx_hash'] : null,
                providerPayoutId: isset($payload['payout_id']) ? (string) $payload['payout_id'] : null,
                payoutStatus: isset($payload['status'])
                    ? GatewayPayoutStatus::from((string) $payload['status'])
                    : null,
            ),
            default => throw new InvalidArgumentException(
                'MockGateway: unrecognized webhook event ['.(is_scalar($event) ? $event : gettype($event)).'].',
            ),
        };
    }

    private function mockNetworkFee(): string
    {
        return (string) config('services.payments.mock.network_fee', '1.500000');
    }
}
