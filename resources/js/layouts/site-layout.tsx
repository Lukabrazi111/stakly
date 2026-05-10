import { ReactNode } from 'react';
import { MarqueeStrip, type MarqueeItem } from '@/components/site/marquee-strip';
import { SiteFooter } from '@/components/site/site-footer';
import { SiteHeader } from '@/components/site/site-header';

const defaultMarqueeItems: MarqueeItem[] = [
    {
        label: 'Grand Opening',
        value: 'Use code STAKLY30 for 30% off your first match',
    },
    {
        label: 'Verified Results',
        value: 'Every match resolved via official game API',
    },
    {
        label: 'Instant Payouts',
        value: 'Win and get paid in seconds, not days',
    },
    {
        label: 'Global',
        value: 'Available across EU, CIS, and worldwide',
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
    return (
        <div className="bg-background text-foreground flex min-h-screen flex-col">
            <SiteHeader />
            <MarqueeStrip items={marqueeItems} />
            <main className="flex-1">{children}</main>
            <SiteFooter />
        </div>
    );
}
