import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { buildMineQuery } from '@/lib/listings-mine-query';
import { mine as mineRoute } from '@/routes/listings';
import type { ListingsMineTab } from '@/types';

interface Props {
    current: ListingsMineTab;
}

/**
 * Underline-style tabs for /listings/mine, matching Bybit's My Ads pattern.
 * Click switches the `?tab=` URL param and re-fetches the page.
 *
 * M19 Phase 4 — migrated to the shadcn `Tabs` primitive (Radix) so this
 * page + ProfileTabs share one implementation. Behavior preserved:
 * router.get re-fetch on tab change because the underlying listing
 * collection differs per tab and we can't trust client-side filtering
 * for the visibility-gated dataset.
 *
 * Optimistic local state: the Tabs primitive reads from `activeTab`
 * rather than the server `current` prop directly, so the underline
 * transitions immediately on click instead of waiting for the Inertia
 * round-trip (which would otherwise add a 200–500ms perceptible delay
 * before the animation starts — looks snappy not smooth). When the
 * fetch returns, the effect syncs `activeTab` to the new `current` prop.
 *
 * No `TabsContent` here — the page renders the listings collection
 * itself outside the Tabs root since the data swap happens server-side.
 */
export function MineTabs({ current }: Props) {
    const [activeTab, setActiveTab] = useState<ListingsMineTab>(current);

    useEffect(() => {
        setActiveTab(current);
    }, [current]);

    const handleChange = (next: string) => {
        const newTab = next as ListingsMineTab;

        if (newTab === activeTab) {
            return;
        }

        setActiveTab(newTab);
        // `preserveState: true` keeps the page component mounted, so the
        // Tabs primitive retains its DOM continuity and the CSS
        // transition on the active underline can complete. With
        // `preserveState: false` (the previous setting), Inertia remounts
        // the page on every fetch, tearing down the in-progress
        // transition and producing a snap-to-new-position effect.
        router.get(mineRoute().url, buildMineQuery({ tab: newTab }), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <Tabs value={activeTab} onValueChange={handleChange} className="mb-6">
            <TabsList variant="line" aria-label="Listings view">
                <TabsTrigger value="listed">Listed</TabsTrigger>
                <TabsTrigger value="all">All Ads</TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
