import { useSyncExternalStore } from 'react';

const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(hover: hover)');

function subscribe(callback: () => void): () => void {
    if (!mql) {
        return () => {};
    }

    mql.addEventListener('change', callback);

    return () => mql.removeEventListener('change', callback);
}

function getSnapshot(): boolean {
    return mql?.matches ?? false;
}

function getServerSnapshot(): boolean {
    return false;
}

/**
 * True on devices with a real hover-capable pointer (desktop mouse / trackpad),
 * false on touch-only devices. Lets a component pick a hover affordance vs a
 * tap affordance. SSR-safe — defaults to the tap-friendly (false) path on the
 * server + first paint, then upgrades after mount; the swap is invisible for
 * idle-on-load triggers (only an unopened wrapper changes, not the markup).
 */
export function useHasHover(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
