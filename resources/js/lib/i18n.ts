import { usePage } from '@inertiajs/react';

export interface LocaleMeta {
    code: string;
    native_label: string;
}

export type Translations = Record<string, string>;

export type TranslationFn = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

export function useT(): TranslationFn {
    const { translations } = usePage().props;

    return (key, replacements) => {
        let value = (translations as Translations)[key] ?? key;

        if (replacements) {
            for (const [token, replacement] of Object.entries(replacements)) {
                value = value.split(`:${token}`).join(String(replacement));
            }
        }

        return value;
    };
}

export function useLocale(): string {
    return usePage().props.locale;
}

export function useAvailableLocales(): LocaleMeta[] {
    return usePage().props.availableLocales;
}
