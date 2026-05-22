<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a listing. Whitelist only — never leak PII from the
 * creator's `User` record (email, two-factor secret, etc).
 *
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game' => $this->game->value,
            // M8 Phase 5 Slice B — the platform the match must be played on
            // (chess_com | lichess). Take button copy on the frontend reads
            // this to disable + label "Link {platform} to take" when the
            // viewer hasn't verified the right provider.
            'platform' => $this->platform->value,
            'stake_amount' => (float) $this->stake_amount,
            'skill_min' => $this->skill_min,
            'skill_max' => $this->skill_max,
            'time_control' => $this->time_control->map(fn ($tc) => $tc->value)->values()->all(),
            'region' => $this->region,
            'language' => $this->language,
            'expires_at' => $this->expires_at->toIso8601String(),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'creator' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                // M6 Phase 6.5 — surface Active Mode to the frontend so the
                // listing detail page can disable the Take button + show a
                // banner when the owner is inactive. PII-wise this is already
                // inferrable (an inactive owner's listings disappear from the
                // marketplace + public profile via `scopeOnPublicMarketplace`),
                // so exposing the boolean here just lets the listing-detail
                // surface — which doesn't go through that scope — surface the
                // same state explicitly. Server still enforces the gate
                // independently in `GameMatchController::take`.
                'is_active_mode' => (bool) $this->user->is_active_mode,
            ],
        ];
    }
}
