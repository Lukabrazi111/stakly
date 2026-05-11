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
            'stake_amount' => (float) $this->stake_amount,
            'skill_min' => $this->skill_min,
            'skill_max' => $this->skill_max,
            'time_control' => $this->time_control->value,
            'region' => $this->region,
            'language' => $this->language,
            'expires_at' => $this->expires_at->toIso8601String(),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'creator' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        ];
    }
}
