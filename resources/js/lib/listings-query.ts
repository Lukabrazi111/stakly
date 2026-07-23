// Shared URL-builder for /listings visits. Mirrors the backend contract:
//   /listings?filter[stake_max]=100&filter[time_control]=blitz,rapid&sort=highest_stake&page=2
//
// Builds the params object passed to `router.get(...)`. Inertia serializes
// nested objects into `filter[*]` query-string keys via `qs`.

import type { ListingFilters } from '@/types';

export type ListingsVisitParams = {
    filter?: Record<string, string | number>;
    sort?: string;
    page?: number;
};

export function buildListingsQuery(
    filters: ListingFilters,
    overrides: { page?: number } = {},
): ListingsVisitParams {
    const filter: Record<string, string | number> = {};

    // `game` always set, but omit if it's the default chess (keeps URLs clean).
    if (filters.game && filters.game !== 'chess') {
        filter.game = filters.game;
    }

    if (filters.stake_min !== null) {
        filter.stake_min = filters.stake_min;
    }

    if (filters.stake_max !== null) {
        filter.stake_max = filters.stake_max;
    }

    // M41 P5: the "unrated only" toggle is mutually exclusive with a rating
    // range — send one or the other, never both.
    if (filters.unrated) {
        filter.unrated = 1;
    } else {
        if (filters.skill_min !== null) {
            filter.skill_min = filters.skill_min;
        }

        if (filters.skill_max !== null) {
            filter.skill_max = filters.skill_max;
        }
    }

    // Spatie convention: CSV string for multi-value filters.
    if (filters.time_control.length > 0) {
        filter.time_control = filters.time_control.join(',');
    }

    if (filters.region) {
        filter.region = filters.region;
    }

    if (filters.language) {
        filter.language = filters.language;
    }

    const params: ListingsVisitParams = {};

    if (Object.keys(filter).length > 0) {
        params.filter = filter;
    }

    // Omit `sort=newest` (the default) to keep canonical URLs clean.
    if (filters.sort && filters.sort !== 'newest') {
        params.sort = filters.sort;
    }

    if (overrides.page && overrides.page > 1) {
        params.page = overrides.page;
    }

    return params;
}
