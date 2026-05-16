import { Head, router } from '@inertiajs/react';
import { Swords } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MatchesFilterChips } from '@/components/match/matches-filter-chips';
import { MatchListRow } from '@/components/match/match-list-row';
import { MatchListRowSkeleton } from '@/components/match/match-list-row-skeleton';
import { MatchesPagination } from '@/components/match/matches-pagination';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { index as matchesIndex } from '@/routes/matches';
import type { MatchesIndexProps } from '@/types';

const SKELETON_ROW_COUNT = 4;

export default function MatchesIndex({ matches, filters }: MatchesIndexProps) {
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const matchesPath = matchesIndex().url;

        const isMatchesVisit = (url: string): boolean => {
            try {
                return (
                    new URL(url, window.location.origin).pathname
                    === matchesPath
                );
            } catch {
                return false;
            }
        };

        const removeStart = router.on('start', (event) => {
            if (isMatchesVisit(event.detail.visit.url.toString())) {
                setIsLoading(true);
            }
        });

        const removeFinish = router.on('finish', (event) => {
            if (isMatchesVisit(event.detail.visit.url.toString())) {
                setIsLoading(false);
            }
        });

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    const isFiltering = filters.status !== null;
    const isEmpty = matches.data.length === 0;

    return (
        <PlayerHubLayout>
            <Head title="Your matches" />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Your matches
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Every match you've created or taken.{' '}
                        <span className="text-foreground/70">
                            {matches.meta.total}{' '}
                            {matches.meta.total === 1 ? 'match' : 'matches'}
                        </span>
                    </p>
                </header>

                <div className="mb-6">
                    <MatchesFilterChips filters={filters} />
                </div>

                {!isEmpty || isLoading ? (
                    <div className="border-border/60 bg-card/40 overflow-hidden rounded-2xl border">
                        {/* Column header — desktop only. Mobile rows stack
                            vertically so labeled columns don't apply. */}
                        <div className="border-border/40 text-muted-foreground hidden border-b px-5 py-3 text-xs uppercase tracking-wide md:flex md:items-center md:gap-6">
                            <div className="md:w-52">Opponent</div>
                            <div className="flex flex-1 items-center gap-6">
                                <div className="flex-1">Status</div>
                                <div className="md:w-20 md:text-right">
                                    Date
                                </div>
                                <div className="md:w-28 md:text-right">
                                    Stake
                                </div>
                            </div>
                        </div>

                        {isLoading
                            ? Array.from({ length: SKELETON_ROW_COUNT }).map(
                                (_, i) => <MatchListRowSkeleton key={i} />,
                            )
                            : matches.data.map((match) => (
                                <MatchListRow key={match.id} match={match} />
                            ))}
                    </div>
                ) : (
                    <EmptyState filtering={isFiltering} />
                )}

                <MatchesPagination
                    currentPage={matches.meta.current_page}
                    lastPage={matches.meta.last_page}
                    filters={filters}
                />
            </div>
        </PlayerHubLayout>
    );
}

interface EmptyStateProps {
    filtering: boolean;
}

function EmptyState({ filtering }: EmptyStateProps) {
    return (
        <div className="border-border/60 bg-card/40 flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-16 text-center">
            <div className="bg-primary/10 text-primary rounded-full p-3">
                <Swords className="size-6" />
            </div>
            <h2 className="font-display text-foreground text-xl font-semibold">
                {filtering ? 'No matches in this view' : 'No matches yet'}
            </h2>
            <p className="text-muted-foreground max-w-sm text-sm">
                {filtering
                    ? 'Try a different filter, or clear it to see every match.'
                    : 'Create a listing or take someone else’s to start your first match.'}
            </p>
        </div>
    );
}
