import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AuthModal } from '@/components/auth/auth-modal';
import { NotificationProvider } from '@/components/notifications/notification-provider';
import { BannedBanner } from '@/components/site/banned-banner';
import type { MarqueeItem } from '@/components/site/marquee-strip';
import { MarqueeStrip } from '@/components/site/marquee-strip';
import { SiteFooter } from '@/components/site/site-footer';
import { SiteHeader } from '@/components/site/site-header';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useT } from '@/lib/i18n';

interface SiteLayoutProps {
    children: ReactNode;
    marqueeItems?: MarqueeItem[];
}

export default function SiteLayout({
    children,
    marqueeItems,
}: SiteLayoutProps) {
    const t = useT();
    useFlashToast();
    const ban = usePage().props.auth.user?.ban ?? null;

    // Built inside the component so `useT()` resolves against the active
    // locale's bag — module-top-level evaluation would lock the labels to
    // the default 'en' strings before any route middleware runs.
    const items: MarqueeItem[] = marqueeItems ?? [
        {
            label: t('Skill staking'),
            value: t('Stake USDT on your own results'),
        },
        {
            label: t('USDT escrow'),
            value: t('Stakes locked the moment a listing is created'),
        },
        {
            label: t('Auto-refund'),
            value: t('Unmatched listings refund on expiry'),
        },
        {
            label: t('1v1 chess'),
            value: t('Find an opponent at your skill level'),
        },
    ];

    return (
        <NotificationProvider>
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                {ban && <BannedBanner reason={ban.reason} />}
                <SiteHeader />
                <MarqueeStrip items={items} />
                <main className="flex-1">{children}</main>
                <SiteFooter />
                <AuthModal />
            </div>
        </NotificationProvider>
    );
}
