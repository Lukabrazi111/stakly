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
            // M23 Phase 2 — platform fee rate as a float at the JSON
            // boundary (mirror `GameMatchResource::fee_rate`). Frontend
            // computes pot = stake × 2, fee = pot × fee_rate, winner
            // payout = pot − fee. Single source of truth = config; FE
            // never duplicates the rate. The marketplace surfaces don't
            // currently render this, but the resource shape stays
            // consistent across index / show / mine for the cost of one
            // float per listing.
            'fee_rate' => (float) config('stakly.platform_fee_rate'),
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
                // M18 Phase 1 propagation — 128×128 thumb so listing cards /
                // detail page can show the real avatar instead of initials.
                // Null until the creator uploads an avatar.
                'avatar_thumb_url' => $this->user->avatar_thumb_url,
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
                // M22 Phase 1 — seller trust signals batch-loaded by
                // `App\Services\SellerTrust::attachTo()` in the controller.
                // PII-safe — both fields are aggregates of public match
                // history. Defensive defaults when `seller_trust` wasn't
                // attached (factory / partial-test paths).
                'completion_rate_30d' => $this->seller_trust['rate_30d'] ?? null,
                'settled_lifetime' => (int) ($this->seller_trust['settled_lifetime'] ?? 0),
                // M22 Phase 1 (badge tier) — verified provider list drives
                // the earned cross-platform badge on the listing row meta.
                // Requires `user.linkedAccounts` eager-loaded; falls back
                // to empty array when not loaded (defensive).
                'verified_providers' => $this->user->relationLoaded('linkedAccounts')
                    ? $this->user->linkedAccounts
                        ->pluck('provider')
                        ->map(fn ($provider) => $provider->value)
                        ->values()
                        ->all()
                    : [],
                // M23 Phase 1 — detail-page identity fields (creator card
                // uplift). Costs negligible payload on the row/card surfaces
                // (`bio` capped at 500 chars; `linked_accounts` is 2 small
                // objects today) so we keep one ListingResource shape rather
                // than splitting a detail-only DTO. `linked_accounts` differs
                // from `verified_providers` above by carrying the username
                // — needed for the click-out chip strip on the detail page.
                'bio' => $this->user->bio,
                'member_since' => $this->user->created_at?->toIso8601String(),
                'linked_accounts' => $this->user->relationLoaded('linkedAccounts')
                    ? $this->user->linkedAccounts
                        ->map(fn ($account) => [
                            'provider' => $account->provider->value,
                            'username' => $account->username,
                        ])
                        ->values()
                        ->all()
                    : [],
            ],
        ];
    }
}
