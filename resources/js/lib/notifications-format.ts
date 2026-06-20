import type { TranslationFn } from '@/lib/i18n';

interface RelativeTimeOptions {
    /** When true, sub-minute renders as "Just now" instead of "now". */
    capitalizeJustNow?: boolean;
    /** When true, ages between 1 and 4 weeks render as "Nw ago" instead
     *  of dropping straight to an absolute date. Bell-dropdown wants this;
     *  the history card jumps to an absolute date after 7 days. */
    showWeeks?: boolean;
    /** Locale-formatted absolute date returned once relative ladder is exhausted. */
    absoluteFormat?: Intl.DateTimeFormatOptions;
}

export function formatNotificationTime(
    iso: string,
    t?: TranslationFn,
    options: RelativeTimeOptions = {},
): string {
    const {
        capitalizeJustNow = false,
        showWeeks = false,
        absoluteFormat,
    } = options;

    const date = new Date(iso);
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - date.getTime()) / 1000),
    );

    if (seconds < 60) {
        if (capitalizeJustNow) {
            return t ? t('Just now') : 'Just now';
        }

        return t ? t('now') : 'now';
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return t ? t(':minutes m ago', { minutes }) : `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return t ? t(':hours h ago', { hours }) : `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);

    if (days < 7) {
        return t ? t(':days d ago', { days }) : `${days}d ago`;
    }

    if (showWeeks && days < 28) {
        const weeks = Math.floor(days / 7);

        return t ? t(':weeks w ago', { weeks }) : `${weeks}w ago`;
    }

    return date.toLocaleDateString(undefined, absoluteFormat);
}
