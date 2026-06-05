import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useT } from '@/lib/i18n';
import { buildMineQuery } from '@/lib/listings-mine-query';
import { mine as mineRoute } from '@/routes/listings';
import type { ListingsMineTab } from '@/types';

interface Props {
    current: ListingsMineTab;
}

/**
 * Underline tabs for /listings/mine. Uses optimistic local state so the
 * underline transitions immediately on click instead of waiting for the
 * Inertia round-trip (which would otherwise produce a 200–500ms lag).
 */
export function MineTabs({ current }: Props) {
    const t = useT();
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
        // `preserveState: true` keeps the Tabs primitive mounted so the
        // underline CSS transition can complete; remount would snap it.
        router.get(mineRoute().url, buildMineQuery({ tab: newTab }), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <Tabs value={activeTab} onValueChange={handleChange} className="mb-6">
            <TabsList variant="line" aria-label={t('Listings view')}>
                <TabsTrigger value="listed">{t('Listed')}</TabsTrigger>
                <TabsTrigger value="all">{t('All Ads')}</TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
