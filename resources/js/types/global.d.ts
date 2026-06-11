import '@inertiajs/core';
import 'react';
import type { LocaleMeta, Translations } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { FlashToast } from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            playerSidebarCollapsed: boolean;
            locale: string;
            availableLocales: LocaleMeta[];
            translations: Translations;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            verify_cooldown_seconds?: number;
        };
    }
}

// Inertia's `<Head>` uses `head-key` on child elements to dedupe tags
// across React renders (same head-key = later render wins). React's own
// HTML attribute types don't include it since it's not a standard DOM
// attribute, so we widen HTMLAttributes globally to keep `<meta head-key=...>`
// type-clean wherever it appears (PageMeta + any future per-page <Head>
// usage that needs explicit dedup).
declare module 'react' {
    interface HTMLAttributes<T> {
        'head-key'?: string;
    }
}
