// Frontend contract for the user profile domain. Backend source of truth:
// - App\Http\Resources\UserProfileResource (user shape — whitelisted public fields)
// - App\Http\Controllers\UserController::show (props shape)
//
// Distinct from `User` in `auth.ts`, which represents the *currently logged-in*
// user (includes email + verification fields). `UserProfile` is the public slice
// visible on `/users/{username}` — no email, no balance, no PII.

import type { Listing } from './listings';
import type { Match } from './match';

export interface UserProfile {
    id: number;
    username: string;
    name: string;
    bio: string | null;
    // ISO 8601 timestamp from `created_at`. Frontend formats it for display
    // (e.g. "Joined Mar 2026").
    member_since: string;
    // Always `null` in v1 — frontend falls back to the gradient-initials
    // avatar via `useInitials()`. Upload flow lands post-MVP.
    avatar: string | null;
    // Verified external game-account usernames (M8 Phase 1). `null` when not
    // linked. Pending verification state is NEVER exposed here — these fields
    // are only populated after the bio-code flow completes.
    chess_com_username: string | null;
    lichess_username: string | null;
}

export interface ProfileStats {
    open_listings: number;
    total_listings: number;
    // Same ISO 8601 as `UserProfile.member_since` — duplicated here so the
    // stats grid can render independently of the header card.
    member_since: string;
}

// Page-level props for `pages/users/show.tsx` (built in Phase 4).
// `user` is the resolved/unwrapped resource (no `data` envelope), since the
// controller calls `(new UserProfileResource($user))->resolve()`.
// `openListings` and `matchHistory` are Resource::collection(...) results —
// wrapped in a `data` envelope but without pagination meta (no `paginate()`
// call, fixed limit on the backend).
export interface ProfileShowProps {
    user: UserProfile;
    stats: ProfileStats;
    openListings: { data: Listing[] };
    matchHistory: { data: Match[] };
}
