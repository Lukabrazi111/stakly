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
}

export interface Listing {
    id: number;
    game: GameId;
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
export interface ListingShowProps {
    listing: Listing;
}

// Props for the create-listing form. Option lists (regions / languages /
// durations) are passed from the backend so `StoreListingRequest`'s constants
// stay the single source of truth — frontend never duplicates them.
// `balance` is the user's current `usdt_balance` as a BCMath-safe string.
export interface ListingCreateProps {
    balance: string;
    regions: string[];
    languages: string[];
    durations: number[];
}
