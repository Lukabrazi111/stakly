import { router } from '@inertiajs/react';
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
 * page + the new ProfileTabs share one implementation. The previous
 * hand-rolled `<button role="tab">` markup is gone; behavior preserved
 * (router.get re-fetch on tab change because the underlying listing
 * collection differs per tab and we can't trust client-side filtering for
 * the visibility-gated dataset).
 *
 * No `TabsContent` here — the page renders the listings collection itself
 * outside the Tabs root since the data swap happens server-side.
 */
export function MineTabs({ current }: Props) {
    const handleChange = (next: string) => {
        if (next === current) {
            return;
        }
        router.get(
            mineRoute().url,
            buildMineQuery({ tab: next as ListingsMineTab }),
            {
                preserveState: false,
                preserveScroll: false,
            },
        );
    };

    return (
        <Tabs value={current} onValueChange={handleChange} className="mb-6">
            <TabsList variant="line" aria-label="Listings view">
                <TabsTrigger value="listed">Listed</TabsTrigger>
                <TabsTrigger value="all">All Ads</TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
