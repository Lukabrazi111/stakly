import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export type ListingsViewMode = 'rows' | 'grid';

const COOKIE_NAME = 'listings_view_layout';
const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

/**
 * View-mode preference for `/listings` and `/listings/mine`. Initial value
 * comes from a server-shared Inertia prop (`HandleInertiaRequests::share`),
 * which reads the same cookie this hook writes — so SSR + first paint never
 * disagree with the user's last choice. Cookie + state only; no localStorage,
 * since the cookie already covers cross-tab and round-trip.
 *
 * The `useEffect` syncs the local state whenever the server-shared prop
 * changes (e.g. after an Inertia navigation). Without it, the hook would
 * snapshot the cookie value on first mount and the toggle would appear to
 * forget the user's choice after navigating away and back.
 */
export function useListingsView(): {
    view: ListingsViewMode;
    setView: (mode: ListingsViewMode) => void;
} {
    const shared =
        (usePage().props.listingsViewLayout as ListingsViewMode) ?? 'grid';
    const [view, setLocalView] = useState<ListingsViewMode>(shared);

    useEffect(() => {
        setLocalView(shared);
    }, [shared]);

    const setView = useCallback((mode: ListingsViewMode) => {
        setLocalView(mode);
        document.cookie = `${COOKIE_NAME}=${mode}; Max-Age=${COOKIE_MAX_AGE_SECONDS}; path=/; SameSite=Lax`;
    }, []);

    return { view, setView };
}
