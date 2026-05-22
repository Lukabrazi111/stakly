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
            ],
            'taker' => [
                'id' => $this->taker->id,
                'name' => $this->taker->name,
                'username' => $this->taker->username,
            ],
            'creator_confirmed_outcome' => $this->creator_confirmed_outcome?->value,
            'taker_confirmed_outcome' => $this->taker_confirmed_outcome?->value,
            'winner' => $this->winner ? [
                'id' => $this->winner->id,
                'name' => $this->winner->name,
                'username' => $this->winner->username,
            ] : null,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
