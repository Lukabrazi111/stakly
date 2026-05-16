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
 * `related_match` is included for transactions whose listing has a 1:1 match
 * (Payout / Fee, and any Hold posted at match-take time). The frontend uses
 * it to deep-link Payout / Fee rows directly to the match for one-click
 * navigation from "$180 credit" to "the match it came from". Callers MUST
 * eager-load `listing.gameMatch` to avoid N+1 — controllers do this today.
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
