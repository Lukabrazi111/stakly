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

// No `configureEcho` here — Echo's WebSocket client crashes on Node and is
// client-only by design. Keep the `setup` wrapper in sync with `app.tsx`'s
// `withApp` to avoid hydration mismatches.
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) => {
            // Eager glob — SSR bundle ships every page synchronously so the
            // Node render path has no dynamic imports to await.
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
