<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a user profile. Whitelist-only — NEVER expose:
 *   - email
 *   - usdt_balance
 *   - is_platform
 *   - two_factor_secret / two_factor_recovery_codes / two_factor_confirmed_at
 *   - password / remember_token
 *
 * Hidden attributes on the User model give a second layer, but this resource
 * is the contract: the only fields here are the only fields shipped.
 *
 * @mixin User
 */
class UserProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'bio' => $this->bio,
            'member_since' => $this->created_at->toIso8601String(),
            // Always null in v1 — frontend falls back to a gradient-initials
            // avatar via `useInitials()`. Upload flow lands post-MVP.
            'avatar' => null,
            // Verified linked external accounts (M8 Phase 1). Null when not
            // linked — the username column is only populated after successful
            // bio-code verification (pending state lives in
            // `pending_verification_*` and is NEVER exposed publicly). Frontend
            // renders these on the profile's "Linked accounts" section.
            'chess_com_username' => $this->chess_com_username,
            'lichess_username' => $this->lichess_username,
        ];
    }
}
