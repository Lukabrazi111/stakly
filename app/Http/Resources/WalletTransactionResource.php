<?php

namespace App\Http\Resources;

use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a wallet ledger row. Whitelist only.
 *
 * Deliberately omitted:
 *   - `reference_id` — idempotency keys (`listing-create:42`) are internal
 *     plumbing. Leaking them would expose our naming convention to anyone who
 *     can see their own history.
 *   - `user_id` — the user is implied by the auth context for every endpoint
 *     that returns this resource. Repeating it adds nothing and would invite
 *     mistakes if we ever introduce a generic ledger viewer.
 *
 * `amount` and `balance_after` cast to floats at the JSON boundary — matches
 * the M3 listings convention and keeps the frontend free of BCMath strings.
 *
 * @mixin WalletTransaction
 */
class WalletTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => (float) $this->amount,
            'balance_after' => (float) $this->balance_after,
            'related_listing_id' => $this->related_listing_id,
            'related_listing' => $this->whenLoaded('listing', fn () => $this->listing ? [
                'id' => $this->listing->id,
                'game' => $this->listing->game->value,
            ] : null),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
