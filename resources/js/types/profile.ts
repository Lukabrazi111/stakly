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
    // M18 Phase 1 — uploaded avatar URLs from Spatie media library. Null
    // when the user hasn't uploaded one yet; frontend falls back to a
    // gradient-initials avatar via `useInitials()`.
    //   - `avatar_url`       512×512 (profile header)
    //   - `avatar_thumb_url` 128×128 (chat bubbles / listing rows)
    avatar_url: string | null;
    avatar_thumb_url: string | null;
    // Verified external game-account usernames (M8 Phase 1). `null` when not
    // linked. Pending verification state is NEVER exposed here — these fields
    // are only populated after the bio-code flow completes.
    chess_com_username: string | null;
    lichess_username: string | null;
}

export interface ProfileStats {
    // Same ISO 8601 as `UserProfile.member_since` — duplicated here so the
    // stats grid can render independently of the header card.
    member_since: string;
    // M18 Phase 2 — settled-match count and total stake volume (the user's
    // own stake summed across settled matches; not the pot). Public to
    // everyone. Zero on a fresh profile; never null.
    total_matches: number;
    total_volume: number;
    // Owner-only — null when the viewer is not the profile owner OR when
    // the owner has no settled matches yet. Win rate is intentionally not
    // public to avoid inviting strong players to hunt weak ones; the
    // public skill signal is the chess.com / Lichess rating.
    //
    // `percentage` is rounded to integer (e.g. 67 not 66.67). Null when
    // there are no decided matches (all draws), so the UI renders "—"
    // instead of a misleading "0%".
    win_rate: {
        wins: number;
        draws: number;
        losses: number;
        percentage: number | null;
    } | null;
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
