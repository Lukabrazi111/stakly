<?php

namespace App\Http\Resources;

use App\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a match. Whitelist only — never leak PII from either
 * player's `User` record (email, two-factor secret, balance, tron address).
 *
 * Authorization to even reach this resource is enforced upstream
 * (`GameMatchPolicy::view`); this resource trusts the caller has access.
 *
 * @mixin GameMatch
 */
class GameMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // Platform fee rate as a float at the JSON boundary (BCMath
            // string internally). Frontend computes pot / fee / payout
            // from stake_amount + fee_rate so we don't duplicate the
            // settlement math in two places.
            'fee_rate' => (float) config('stakly.platform_fee_rate'),
            'listing' => [
                'id' => $this->listing->id,
                'game' => $this->listing->game->value,
                'stake_amount' => (float) $this->listing->stake_amount,
                // Platform binds outcome verification: a Lichess listing
                // is auto-verified via Lichess, a chess.com listing via
                // chess.com. Frontend shows this in the capability
                // indicator inside `MatchInfoCard`.
                'platform' => $this->listing->platform->value,
                'time_control' => $this->listing->time_control
                    ->map(fn ($tc) => $tc->value)
                    ->values()
                    ->all(),
            ],
            'creator' => [
                'id' => $this->listing->user->id,
                'name' => $this->listing->user->name,
                'username' => $this->listing->user->username,
                // M18 Phase 1 propagation — 128×128 thumb for chat bubbles +
                // match info card. Null until upload.
                'avatar_thumb_url' => $this->listing->user->avatar_thumb_url,
            ],
            'taker' => [
                'id' => $this->taker->id,
                'name' => $this->taker->name,
                'username' => $this->taker->username,
                'avatar_thumb_url' => $this->taker->avatar_thumb_url,
            ],
            // Snapshotted external-account handles scoped to the listing's
            // platform (M16 Phase 3 — Pending action card displays the
            // username pair so players know which game we're polling for).
            // Either side may be null if the player never linked that
            // provider; in practice the take + create gates prevent
            // unlinked matches but the FE handles null defensively.
            'snapshots' => [
                'creator_username' => $this->snapshotUsername(
                    GameMatch::SIDE_CREATOR,
                    $this->listing->platform,
                ),
                'taker_username' => $this->snapshotUsername(
                    GameMatch::SIDE_TAKER,
                    $this->listing->platform,
                ),
            ],
            'winner' => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
                'username' => $this->winner->username,
                'avatar_thumb_url' => $this->winner->avatar_thumb_url,
            ] : null,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // M10 — mutual cancellation state. All fields nullable; the
            // frontend infers open-request / cooldown / terminal banner
            // from the combination. `requested_by_id` is enough for the
            // FE to look up the name client-side from creator / taker
            // (already loaded) — saves an eager-load for the requester
            // relation. `cancellation_rejected_at` is what the requester
            // reads to compute their cooldown countdown for the disabled
            // "Request cancellation" button tooltip.
            'cancellation' => [
                'requested_by_id' => $this->cancellation_requested_by,
                'requested_at' => $this->cancellation_requested_at?->toIso8601String(),
                'reason' => $this->cancellation_reason,
                'rejected_at' => $this->cancellation_rejected_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],
        ];
    }
}
