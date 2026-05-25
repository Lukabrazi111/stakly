// Frontend contract for the listings domain. Backend source of truth:
// - App\Http\Resources\ListingResource (data shape)
// - App\Http\Requests\Listings\IndexListingsRequest (filter shape + sort list)
// - resources/js/config/games.ts (game registry)

import type { GameId } from '@/config/games';

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
}

// The external provider the match must be played on (M8 Phase 5 Slice B).
// Matches `App\Enums\LinkedAccountProvider` values. Taker must have THIS
// platform verified to take the listing.
export type ListingPlatform = 'chess_com' | 'lichess';

export interface Listing {
    id: number;
    game: GameId;
    platform: ListingPlatform;
    stake_amount: number;
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
    // M8 Phase 5 Slice B — the verified providers the user has linked.
    // Empty array = no link; create form swaps to the link-CTA notice card.
    // One = picker hidden, platform auto-selected.
    // Two = picker shown so the user picks per listing.
    linkedPlatforms: ListingPlatform[];
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
