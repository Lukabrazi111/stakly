import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

export type ListingsViewMode = 'rows' | 'grid';

const COOKIE_NAME = 'listings_view_layout';
const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

/**
 * View-mode preference for `/listings` and `/listings/mine`. Initial value
 * comes from a server-shared Inertia prop (`HandleInertiaRequests::share`),
 * which reads the same cookie this hook writes — so SSR + first paint never
 * disagree with the user's last choice. Cookie + state only; no localStorage,
 * since the cookie already covers cross-tab and round-trip.
 */
export function useListingsView(): {
    view: ListingsViewMode;
    setView: (mode: ListingsViewMode) => void;
} {
    const initial =
        (usePage().props.listingsViewLayout as ListingsViewMode) ?? 'grid';
    const [view, setLocalView] = useState<ListingsViewMode>(initial);

    const setView = useCallback((mode: ListingsViewMode) => {
        setLocalView(mode);
        document.cookie = `${COOKIE_NAME}=${mode}; Max-Age=${COOKIE_MAX_AGE_SECONDS}; path=/; SameSite=Lax`;
    }, []);

    return { view, setView };
}
