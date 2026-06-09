// Frontend contract for the listings domain. Backend source of truth:
// - App\Http\Resources\ListingResource (data shape)
// - App\Http\Requests\Listings\IndexListingsRequest (filter shape + sort list)
// - resources/js/config/games.ts (game registry)

import type { GameId } from '@/config/games';
import type { GameTile } from '@/types/home';

export type ListingStatus = 'open' | 'taken' | 'expired' | 'cancelled';

export type TimeControl = 'blitz' | 'rapid' | 'classical';

export type ListingSort =
    | 'newest'
    | 'highest_stake'
    | 'lowest_stake'
    | 'ending_soon';

export interface ListingCreator {
    id: number;
    name: string;
    username: string;
    // M18 Phase 1 propagation — 128×128 avatar thumb for listing cards +
    // detail page. Null when the creator hasn't uploaded one; FE falls
    // back to gradient-initials via `useInitials()`.
    avatar_thumb_url: string | null;
    // M6 Phase 6.5 — used by the listing detail page to disable the Take
    // button + show "currently inactive" banner when false. Server-side gate
    // in `GameMatchController::take` is the authoritative enforcement.
    is_active_mode: boolean;
    // M22 Phase 1 — seller trust signals on the listing row chip.
    // `completion_rate_30d` is an integer percentage (0–100), null when the
    // creator has no engaged matches in the last 30 days (no headline to
    // show). `settled_lifetime` is the all-time settled count and drives
    // the chip's "98% · 47" denominator. Chip hides entirely when
    // `settled_lifetime === 0` (no track record).
    completion_rate_30d: number | null;
    settled_lifetime: number;
    // M22 Phase 1 (badge tier) — list of providers the creator is verified
    // on (chess.com / Lichess today; FACEIT / Riot / Steam in M15). Drives
    // the cross-platform earned badge in `SellerTrustMeta`: the green
    // `BadgeCheck` icon appears in the meta line ONLY when the creator has
    // verified on 2+ providers ("went the extra mile" credential).
    verified_providers: ChessProvider[];
    // M23 Phase 1 — detail-page creator card uplift. `bio` and `member_since`
    // mirror the profile-page hero. `linked_accounts` carries the
    // (provider, username) pairs the detail-page chip strip needs to click
    // out to each external profile — different shape from `verified_providers`
    // above (which only carries the provider id, sufficient for the badge).
    // M15 Phase 3 — widened from `ChessProvider` to `ListingPlatform` so
    // FACEIT (+ future Steam) verifications surface in the chip strip too.
    bio: string | null;
    member_since: string | null;
    linked_accounts: Array<{ provider: ListingPlatform; username: string }>;
}

// Chess-only linked-account providers. Distinct from `ListingPlatform` below
// because a user can only "link" chess accounts today — FACEIT/Steam linking
// is M15 work. The two types overlap on `chess_com | lichess`.
export type ChessProvider = 'chess_com' | 'lichess';

// The external provider the match must be played on (M8 Phase 5 Slice B).
// Matches `App\Enums\LinkedAccountProvider` values. Taker must have THIS
// platform verified to take the listing.
// `faceit` + `steam` are M15 placeholders — they appear on dev-seeded CS2
// (FACEIT) and Dota 2 (Steam) listings, never via the Create flow today.
// See backend `App\Enums\LinkedAccountProvider` for the matching cases.
export type ListingPlatform = ChessProvider | 'faceit' | 'steam';

export interface Listing {
    id: number;
    game: GameId;
    platform: ListingPlatform;
    stake_amount: number;
    // M23 Phase 2 — platform fee rate at the JSON boundary (mirrors
    // `GameMatch.fee_rate`). Frontend uses this to render the pot
    // breakdown on the detail page (pot = stake × 2, fee = pot × fee_rate,
    // winner payout = pot − fee).
    fee_rate: number;
    skill_min: number | null;
    skill_max: number | null;
    // Array of one or more time controls the creator is willing to play.
    // Taker (M6) picks which one for the actual match.
    time_control: TimeControl[];
    region: string | null;
    // Array of languages the creator speaks, or null = no restriction.
    language: string[] | null;
    expires_at: string;
    status: ListingStatus;
    created_at: string | null;
    creator: ListingCreator;
}

export interface ListingFilters {
    game: GameId;
    stake_min: number | null;
    stake_max: number | null;
    skill_min: number | null;
    skill_max: number | null;
    time_control: TimeControl[];
    region: string | null;
    language: string | null;
    sort: ListingSort;
}

// Standard Laravel paginator shape (when wrapped by an API Resource collection).
export interface Paginator<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
}

export interface ListingsIndexProps {
    listings: Paginator<Listing>;
    filters: ListingFilters;
    sorts: ListingSort[];
    games: { data: GameTile[] };
}

// Phase 1 (M4) — props for the listing detail page. `listing` is the resource
// resolved/unwrapped (no `data` wrapper), since the controller calls
// `(new ListingResource($listing))->resolve()`.
//
// `match` is populated (M6 Phase 6) only when the listing is `taken` AND the
// viewer is a participant (creator or taker). For non-participants and
// non-taken listings the controller sends `null` — the frontend uses its
// presence as the sole gate for the "View match →" link.
export interface ListingShowProps {
    listing: Listing;
    match: { id: number } | null;
}

// Props for the create-listing form. Option lists (regions / languages /
// durations) are passed from the backend so `StoreListingRequest`'s constants
// stay the single source of truth — frontend never duplicates them.
// `balance` is the user's current `usdt_balance` as a BCMath-safe string.
//
// `activeListingsCount` + `maxActiveListings` (M6 Phase 6.5) gate the form
// when the user is at the cap — submit button disables and a banner explains
// why. Backend re-validates via StoreListingRequest::withValidator.
export interface ListingCreateProps {
    balance: string;
    regions: string[];
    languages: string[];
    durations: number[];
    activeListingsCount: number;
    maxActiveListings: number;
    // M8 Phase 5 Slice B (widened in M15 Phase 3) — every provider the user
    // has verified. Drives the chess platform picker (shown when both chess
    // providers linked) + the per-game default-platform pick.
    linkedPlatforms: ListingPlatform[];
    // M15 Phase 3 — DB-backed game catalog (Active games). Same shape the
    // homepage GameSelector consumes; drives the in-form game-tile picker.
    games: { data: GameTile[] };
    // M15 Phase 3 — per-game gate data. `providers` = which platforms the
    // game can be posted on (chess → chess_com|lichess; cs2 → faceit).
    // `verified` = the current user has at least one of those linked. The
    // form shows an inline "Link X to post" notice when verified=false for
    // the picked game.
    requirementsByGame: Record<
        GameId,
        { providers: ListingPlatform[]; verified: boolean }
    >;
}

// Tab values for the /listings/mine page (M6 Phase 6.5).
export type ListingsMineTab = 'listed' | 'all';

// Page-level props for /listings/mine. `listings` is paginated via Spatie
// query-builder; `tab` reflects the current ?tab= value (defaults to listed);
// `activeCount` + `maxActive` drive the header count chip + Post listing
// button's at-cap disabled state.
export interface ListingsMineProps {
    listings: Paginator<Listing>;
    tab: ListingsMineTab;
    activeCount: number;
    maxActive: number;
}
