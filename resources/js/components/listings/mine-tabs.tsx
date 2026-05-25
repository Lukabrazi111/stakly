import { router } from '@inertiajs/react';
import { buildMineQuery } from '@/lib/listings-mine-query';
import { mine as mineRoute } from '@/routes/listings';
import type { ListingsMineTab } from '@/types';

interface TabDef {
    value: ListingsMineTab;
    label: string;
}

const TABS: TabDef[] = [
    { value: 'listed', label: 'Listed' },
    { value: 'all', label: 'All Ads' },
];

interface Props {
    current: ListingsMineTab;
}

/**
 * Underline-style tabs for /listings/mine, matching Bybit's My Ads pattern.
 * Click switches the `?tab=` URL param and re-fetches the page.
 */
export function MineTabs({ current }: Props) {
    const handleSelect = (tab: ListingsMineTab) => {
        if (current === tab) {
            return;
        }

        router.get(mineRoute().url, buildMineQuery({ tab }), {
            preserveState: false,
            preserveScroll: false,
        });
    };

    return (
        <div
            role="tablist"
            aria-label="Listings view"
            className="mb-6 flex items-center gap-6 border-b border-border/60"
        >
            {TABS.map((tab) => {
                const active = current === tab.value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => handleSelect(tab.value)}
                        className={`relative -mb-px cursor-pointer border-b-2 px-1 py-3 text-sm font-medium transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${
                            active
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}
