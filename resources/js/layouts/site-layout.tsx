import type { ReactNode } from 'react';
import { AuthModal } from '@/components/auth/auth-modal';
import type { MarqueeItem } from '@/components/site/marquee-strip';
import { MarqueeStrip } from '@/components/site/marquee-strip';
import { SiteFooter } from '@/components/site/site-footer';
import { SiteHeader } from '@/components/site/site-header';
import { useFlashToast } from '@/hooks/use-flash-toast';

const defaultMarqueeItems: MarqueeItem[] = [
    {
        label: 'Skill staking',
        value: 'Stake USDT on your own results',
    },
    {
        label: 'USDT escrow',
        value: 'Stakes locked the moment a listing is created',
    },
    {
        label: 'Auto-refund',
        value: 'Unmatched listings refund on expiry',
    },
    {
        label: '1v1 chess',
        value: 'Find an opponent at your skill level',
    },
];

interface SiteLayoutProps {
    children: ReactNode;
    marqueeItems?: MarqueeItem[];
}

export default function SiteLayout({
    children,
    marqueeItems = defaultMarqueeItems,
}: SiteLayoutProps) {
    useFlashToast();

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <SiteHeader />
            <MarqueeStrip items={marqueeItems} />
            <main className="flex-1">{children}</main>
            <SiteFooter />
            <AuthModal />
        </div>
    );
}
