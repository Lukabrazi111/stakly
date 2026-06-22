import type { MatchFilters } from '@/types';

interface BuildOptions {
    page?: number;
}

/**
 * Construct the query-string params object for /matches that the backend
 * (Spatie query-builder) expects.
 *
 * Output shape (URL-encoded by Inertia's router.get):
 *   { 'filter[status]': 'pending', page: 2 }
 */
export function buildMatchesQuery(
    filters: MatchFilters,
    options: BuildOptions = {},
): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    // In Progress is the default — a clean /matches URL. Only the All view
    // (and its optional status chip) carry query params.
    if (filters.view === 'all') {
        params.view = 'all';

        if (filters.status) {
            params['filter[status]'] = filters.status;
        }
    }

    if (options.page && options.page > 1) {
        params.page = options.page;
    }

    return params;
}
