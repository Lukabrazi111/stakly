// Shared formatting helpers for listing UI components. Used by:
//   - ListingRow (marketplace index)
//   - ListingShow (detail page)
//   - any future surface displaying listing data

import type { TimeControl } from '@/types';

export const timeControlLabels: Record<TimeControl, string> = {
    blitz: 'Blitz',
    rapid: 'Rapid',
    classical: 'Classical',
};

/**
 * Joins time-control labels for inline display ("Blitz, Rapid"). Used where
 * rendering chips per value would be visually noisy (e.g. detail page
 * Match-details rows). For multi-chip rendering, iterate directly.
 */
export function formatTimeControls(values: TimeControl[]): string {
    return values.map((v) => timeControlLabels[v]).join(', ');
}

/**
 * Renders a "Xh Ym left" / "Xd left" / "Expired" string for a listing's
 * `expires_at`. Coarse-grained on purpose — UI doesn't need second-precision.
 */
export function formatTimeRemaining(isoString: string): string {
    const target = new Date(isoString).getTime();
    const diffMs = target - Date.now();

    if (diffMs <= 0) {
        return 'Expired';
    }

    const minutes = Math.floor(diffMs / 60_000);

    if (minutes < 60) {
        return `${minutes}m left`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        const remainingMinutes = minutes % 60;

        return remainingMinutes > 0
            ? `${hours}h ${remainingMinutes}m`
            : `${hours}h left`;
    }

    const days = Math.floor(hours / 24);

    return `${days}d left`;
}

/**
 * Sub-hour means we paint the "expires" field with `text-warning`.
 */
export function isEndingSoon(isoString: string): boolean {
    return new Date(isoString).getTime() - Date.now() < 60 * 60 * 1000;
}

export function formatSkillRange(
    min: number | null,
    max: number | null,
): string {
    if (min === null && max === null) {
        return 'Any skill';
    }

    if (min !== null && max !== null) {
        return `${min}-${max} Elo`;
    }

    if (min !== null) {
        return `${min}+ Elo`;
    }

    return `up to ${max} Elo`;
}
