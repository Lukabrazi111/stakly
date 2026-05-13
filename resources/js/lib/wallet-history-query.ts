// URL-builder for /wallet/history visits. Mirrors the backend contract:
//   /wallet/history?filter[type]=deposit&page=2
//
// Same Spatie query-builder shape as `buildListingsQuery` so future filtered
// endpoints can converge on a single helper signature.

import type { WalletFilters } from '@/types';

export type WalletHistoryVisitParams = {
    filter?: Record<string, string>;
    page?: number;
};

export function buildWalletHistoryQuery(
    filters: WalletFilters,
    overrides: { page?: number } = {},
): WalletHistoryVisitParams {
    const filter: Record<string, string> = {};

    if (filters.type) {
        filter.type = filters.type;
    }

    const params: WalletHistoryVisitParams = {};

    if (Object.keys(filter).length > 0) {
        params.filter = filter;
    }

    if (overrides.page && overrides.page > 1) {
        params.page = overrides.page;
    }

    return params;
}
