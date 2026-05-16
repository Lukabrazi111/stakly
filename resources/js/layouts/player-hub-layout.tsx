import type { ReactNode } from 'react';
import { PlayerSidebar } from '@/components/site/player-sidebar';
import SiteLayout from '@/layouts/site-layout';

interface PlayerHubLayoutProps {
    children: ReactNode;
}

/**
 * Layout for player management surfaces: /listings/mine, /matches, /wallet
 * (and wallet sub-pages: deposit, withdraw, history). Wraps SiteLayout
 * (header + marquee + footer + auth modal) and adds a sticky left sidebar
 * with navigation between management pages.
 *
 * Mobile (under md:): sidebar is hidden entirely. Mobile users navigate via
 * the existing SiteHeader hamburger menu, which already exposes "My
 * listings", "Matches", and "Wallet". Keeping the mobile pattern unchanged
 * means we don't duplicate navigation surfaces on small screens.
 *
 * Public pages (/, /listings, /listings/{id}, /users/{username}) keep the
 * plain SiteLayout — the sidebar would feel out of place on marketing /
 * browsing surfaces and would shrink content for no benefit.
 */
export default function PlayerHubLayout({ children }: PlayerHubLayoutProps) {
    return (
        <SiteLayout>
            <div className="flex">
                <PlayerSidebar />
                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </SiteLayout>
    );
}
