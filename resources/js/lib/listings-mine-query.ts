import type { ListingsMineTab } from '@/types';

interface BuildOptions {
    tab?: ListingsMineTab;
    page?: number;
}

/**
 * Query-string params object for `/listings/mine` that the backend
 * (`ListingController::mine`) expects. Output shape:
 *   { tab: 'all', page: 2 }
 *
 * Defaults are stripped — `tab=listed` and `page=1` are implicit, so the
 * URL stays clean (`/listings/mine` rather than
 * `/listings/mine?tab=listed&page=1`).
 */
export function buildMineQuery(
    options: BuildOptions,
): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    if (options.tab && options.tab !== 'listed') {
        params.tab = options.tab;
    }

    if (options.page && options.page > 1) {
        params.page = options.page;
    }

    return params;
}
