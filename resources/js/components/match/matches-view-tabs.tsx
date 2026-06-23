import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useT } from '@/lib/i18n';
import { buildMatchesQuery } from '@/lib/matches-query';
import { index as matchesIndex } from '@/routes/matches';
import type { MatchFilters, MatchView } from '@/types';

interface Props {
    filters: MatchFilters;
    /** Live count of in-progress matches — shown beside the In Progress tab. */
    activeCount: number;
}

/**
 * M36 — the "In Progress / All" split on /matches. Underline tabs matching
 * `MineTabs` (so /matches and /listings/mine feel like one app) and Bybit's
 * Orders tab strip. Optimistic local state so the underline slides on click
 * instead of waiting for the Inertia round-trip.
 */
export function MatchesViewTabs({ filters, activeCount }: Props) {
    const t = useT();
    const [activeView, setActiveView] = useState<MatchView>(filters.view);

    useEffect(() => {
        setActiveView(filters.view);
    }, [filters.view]);

    const handleChange = (next: string) => {
        const view = next as MatchView;

        if (view === activeView) {
            return;
        }

        setActiveView(view);
        // `preserveState` keeps the Tabs primitive mounted so the underline
        // transition completes instead of snapping on remount.
        router.get(
            matchesIndex().url,
            buildMatchesQuery({ view, status: null }),
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <Tabs value={activeView} onValueChange={handleChange}>
            <TabsList variant="line" aria-label={t('Matches view')}>
                <TabsTrigger value="in_progress">
                    {t('In Progress')}
                    {activeCount > 0 && (
                        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/15 px-1.5 text-[10px] font-semibold text-primary">
                            {activeCount > 9 ? '9+' : activeCount}
                        </span>
                    )}
                </TabsTrigger>
                <TabsTrigger value="all">{t('All')}</TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
