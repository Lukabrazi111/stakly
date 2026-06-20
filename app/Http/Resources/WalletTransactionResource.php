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
 *     plumbing; exposing them leaks our naming convention.
 *   - `user_id` — implied by the auth context; would invite mistakes in a
 *     generic ledger viewer.
 *
 * `amount` / `balance_after` cast to floats at the JSON boundary so the FE
 * never deals with BCMath strings.
 *
 * Callers MUST eager-load `listing.gameMatch` to avoid N+1 on `related_match`.
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
            'related_match' => $this->whenLoaded('listing', function () {
                if (! $this->listing || ! $this->listing->gameMatch) {
                    return null;
                }

                return ['id' => $this->listing->gameMatch->id];
            }),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
