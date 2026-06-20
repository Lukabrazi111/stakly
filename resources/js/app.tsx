import { createInertiaApp, router } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import { AuthModalProvider } from '@/components/auth/auth-modal-provider';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SiteLayout from '@/layouts/site-layout';
import { setUrlDefaults } from '@/wayfinder';

// Wayfinder mirrors Laravel's URL::defaults via a runtime registry.
// Keeping `locale` populated here lets every Wayfinder-generated URL
// auto-prefix with the active locale — no per-call-site changes needed
// after wrapping web routes in `Route::prefix('{locale}')` (M26 P4).
// Seeded from the initial `data-page` attribute on the Inertia root, then
// kept in sync via the router's `success` event on every visit.
let currentLocale = readInitialLocale();

setUrlDefaults(() => ({ locale: currentLocale }));

router.on('success', (event) => {
    const next = (event.detail.page.props as { locale?: string }).locale;

    if (typeof next === 'string') {
        currentLocale = next;
    }
});

function readInitialLocale(): string {
    if (typeof document === 'undefined') {
        return 'en';
    }

    const root = document.getElementById('app');
    const raw = root?.dataset.page;

    if (typeof raw !== 'string' || raw === '') {
        return 'en';
    }

    try {
        const page = JSON.parse(raw) as { props?: { locale?: string } };

        return page.props?.locale ?? 'en';
    } catch {
        return 'en';
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Explicit options — package's implicit env-var defaults silently fail when
// Vite hasn't re-read the env. Restart `sail npm run dev` after any
// VITE_REVERB_* change.
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const reverbScheme =
    (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ?? 'http';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [SiteLayout, SettingsLayout];
            default:
                return null;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <AuthModalProvider>{app}</AuthModalProvider>
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
