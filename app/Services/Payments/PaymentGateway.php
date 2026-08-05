<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Payments\Dto\DepositAccount;
use App\Services\Payments\Dto\FeeEstimate;
use App\Services\Payments\Dto\GatewayWebhookEvent;
use App\Services\Payments\Dto\PayoutResult;
use Illuminate\Http\Request;

/**
 * Driver-agnostic interface for the custodial crypto payment provider.
 *
 * Mirrors the `App\Services\GameApi\GameApi` pattern: the active driver is
 * bound by config (`services.payments.driver`) in `AppServiceProvider`, and
 * every consumer (deposit page, `Withdrawals` service, webhook receiver)
 * depends ONLY on this contract + the canonical DTOs in `Dto/` — never on a
 * provider's payload shape or SDK. Switching NowPayments ⇄ Cryptomus ⇄ mock
 * is a config flip plus one implementation class.
 *
 * v1 driver is `MockGateway` (deterministic, no network). The real
 * `NowPaymentsGateway` / `CryptomusGateway` clients are stubs until M9
 * go-ahead. The internal `App\Services\Wallet` ledger stays the source of
 * truth for all balances regardless of driver.
 *
 * All money values are BCMath strings at scale 6 — never floats.
 */
interface PaymentGateway
{
    /**
     * Resolve (creating if needed) the user's persistent deposit destination.
     * Idempotent: repeat calls for the same user return the same address.
     */
    public function ensureDepositAccount(User $user): DepositAccount;

    /**
     * Submit a payout of $amount (gross, in USDT) to $address. $reference is a
     * caller-supplied idempotency key (the withdrawal id) — implementations
     * MUST NOT double-send for a repeated reference.
     */
    public function createPayout(string $amount, string $address, string $reference): PayoutResult;

    /**
     * Estimate the network + provider cost of paying out $amount to $address,
     * for the withdraw-page fee preview.
     */
    public function estimatePayoutFee(string $amount, string $address): FeeEstimate;

    /**
     * Verify an inbound webhook's signature against the raw request. The
     * per-provider scheme (HMAC-SHA512 over sorted JSON, MD5(base64(body)+key),
     * etc.) is fully hidden here. MUST be constant-time.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Normalize a (already signature-verified) webhook into a canonical event.
     * Throws if the payload can't be mapped — callers treat a throw as a
     * malformed/unsupported event and respond without mutating ledger state.
     */
    public function parseWebhookEvent(Request $request): GatewayWebhookEvent;
}
