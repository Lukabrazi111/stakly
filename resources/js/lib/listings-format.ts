import type { TranslationFn } from '@/lib/i18n';
import type { TimeControl } from '@/types';

export const timeControlLabels: Record<TimeControl, string> = {
    bullet: 'Bullet',
    blitz: 'Blitz',
    rapid: 'Rapid',
};

/**
 * Display label for a listing's single time control (M41 P3a — one chess
 * listing = one time control). Returns an empty string for non-chess listings
 * (`time_control` is null), so callers can guard with `value && …` to render
 * nothing rather than an empty chip.
 */
export function timeControlLabel(
    value: TimeControl | null | undefined,
    t?: TranslationFn,
): string {
    if (!value) {
        return '';
    }

    return t ? t(timeControlLabels[value]) : timeControlLabels[value];
}

export function formatTimeRemaining(
    isoString: string,
    t?: TranslationFn,
): string {
    const target = new Date(isoString).getTime();
    const diffMs = target - Date.now();

    if (diffMs <= 0) {
        return t ? t('Expired') : 'Expired';
    }

    const minutes = Math.floor(diffMs / 60_000);

    if (minutes < 60) {
        return t ? t(':minutes m left', { minutes }) : `${minutes}m left`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        const remainingMinutes = minutes % 60;

        if (remainingMinutes > 0) {
            return t
                ? t(':hours h :minutes m', { hours, minutes: remainingMinutes })
                : `${hours}h ${remainingMinutes}m`;
        }

        return t ? t(':hours h left', { hours }) : `${hours}h left`;
    }

    const days = Math.floor(hours / 24);

    return t ? t(':days d left', { days }) : `${days}d left`;
}

export function isEndingSoon(isoString: string): boolean {
    return new Date(isoString).getTime() - Date.now() < 60 * 60 * 1000;
}

export type TimeUrgency = 'expired' | 'critical' | 'warning' | 'normal';

export function getTimeUrgency(isoString: string): TimeUrgency {
    const diffMs = new Date(isoString).getTime() - Date.now();

    if (diffMs <= 0) {
        return 'expired';
    }

    if (diffMs < 15 * 60 * 1000) {
        return 'critical';
    }

    if (diffMs < 60 * 60 * 1000) {
        return 'warning';
    }

    return 'normal';
}
