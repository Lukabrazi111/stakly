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
            // Spatie `profile-avatar` collection on User. Null until upload
            // — FE falls back to gradient-initials via `useInitials()`.
            //   - `avatar_url`       512×512 (profile header, settings)
            //   - `avatar_thumb_url` 128×128 (chat bubbles, listing rows)
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            // Null when not linked. Pending verification state lives in
            // `pending_verification_*` and is NEVER exposed publicly.
            'chess_com_username' => $this->chess_com_username,
            'lichess_username' => $this->lichess_username,
        ];
    }
}
