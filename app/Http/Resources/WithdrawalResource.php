<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a withdrawal. Whitelist only.
 *
 * Deliberately omitted:
 *   - `user_id` — implied by the auth context.
 *   - `debit_transaction_id` / `provider_payout_id` / `provider` — internal
 *     plumbing that leaks our ledger keys and provider relationship.
 *   - `reviewed_by` — never expose which admin actioned a withdrawal.
 *
 * Money values cast to floats at the JSON boundary so the FE never handles
 * BCMath strings.
 *
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount' => (float) $this->amount,
            'platform_fee' => (float) $this->platform_fee,
            'network_fee' => $this->network_fee === null ? null : (float) $this->network_fee,
            'net_amount' => (float) $this->netAmount(),
            'destination_address' => $this->destination_address,
            'tx_hash' => $this->tx_hash,
            // Surfaced so a user can see WHY their withdrawal was refused —
            // hiding it just generates support tickets.
            'rejected_reason' => $this->rejected_reason,
            // New-address cooldown (M9 Phase 0e). Exposed so the UI can explain
            // a long Pending rather than leaving it looking stuck.
            'hold_until' => $this->isHeld() ? $this->hold_until?->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
