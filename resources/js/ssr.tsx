import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import type { ComponentType } from 'react';
import ReactDOMServer from 'react-dom/server';
import { AuthModalProvider } from '@/components/auth/auth-modal-provider';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SiteLayout from '@/layouts/site-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Server-side rendering entry. Mirror of `app.tsx` minus client-only setup
// (`configureEcho` — Echo's WebSocket client crashes on Node; Echo is
// client-only by design and stays in `app.tsx`).
//
// The `setup` callback is the SSR equivalent of the client's `withApp` —
// both wrap the Inertia App component with the same provider tree so
// hydration lines up. Keep the wrapper in sync with `app.tsx`'s `withApp`
// to avoid subtle hydration mismatches.
//
// `@inertiajs/vite` builds this entry when `vite build --ssr` runs (the
// `build:ssr` npm script). Output lands in `bootstrap/ssr/ssr.js`; the
// `inertia:start-ssr` artisan command serves it via a Node process bound
// to the URL configured in `config/inertia.php` (`INERTIA_SSR_URL`).
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) => {
            // Eager glob — the SSR bundle ships every page synchronously so
            // the Node render path has no dynamic imports to await. Inertia's
            // resolver accepts `{ default: ReactComponent }` directly, which
            // matches what an eager glob entry returns.
            const pages = import.meta.glob<{ default: ComponentType<any> }>(
                './pages/**/*.tsx',
                { eager: true },
            );

            return pages[`./pages/${name}.tsx`];
        },
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
        setup: ({ App, props }) => (
            <TooltipProvider delayDuration={0}>
                <AuthModalProvider>
                    <App {...props} />
                </AuthModalProvider>
                <Toaster />
            </TooltipProvider>
        ),
    }),
);
