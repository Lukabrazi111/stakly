<?php

namespace App\Services\Payments\Concerns;

use App\Models\User;
use App\Services\Payments\Dto\DepositAccount;
use App\Services\Payments\Dto\FeeEstimate;
use App\Services\Payments\Dto\GatewayWebhookEvent;
use App\Services\Payments\Dto\PayoutResult;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Fills the `PaymentGateway` contract for provider drivers whose real client
 * isn't wired yet (M9 — paused until go-ahead). Every method throws, so the
 * binding `match()` stays exhaustive and the swap surface is visible, but no
 * provider HTTP/key/webhook code exists until explicitly approved (CLAUDE.md).
 *
 * When a real client lands, it defines these methods on the class itself —
 * class methods take precedence over trait methods, so the gateway can drop
 * `use Unwired;` one method at a time.
 */
trait Unwired
{
    public function ensureDepositAccount(User $user): DepositAccount
    {
        throw $this->notWired();
    }

    public function createPayout(string $amount, string $address, string $reference): PayoutResult
    {
        throw $this->notWired();
    }

    public function estimatePayoutFee(string $amount, string $address): FeeEstimate
    {
        throw $this->notWired();
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        throw $this->notWired();
    }

    public function parseWebhookEvent(Request $request): GatewayWebhookEvent
    {
        throw $this->notWired();
    }

    private function notWired(): RuntimeException
    {
        return new RuntimeException(
            class_basename(static::class).' is not wired yet — real provider client lands in M9 (paused for go-ahead).',
        );
    }
}
