import { router, usePage } from '@inertiajs/react';
import { Swords } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MatchListRow } from '@/components/match/match-list-row';
import { MatchListRowSkeleton } from '@/components/match/match-list-row-skeleton';
import { MatchesFilterChips } from '@/components/match/matches-filter-chips';
import { MatchesPagination } from '@/components/match/matches-pagination';
import { MatchesViewTabs } from '@/components/match/matches-view-tabs';
import { RecruitingLobbyRow } from '@/components/match/recruiting-lobby-row';
import { PageMeta } from '@/components/site/page-meta';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import { index as matchesIndex } from '@/routes/matches';
import type { MatchesIndexProps, MatchView } from '@/types';

const SKELETON_ROW_COUNT = 4;

export default function MatchesIndex({ matches, filters }: MatchesIndexProps) {
    const t = useT();
    const { props } = usePage();
    const activeCount = props.auth.user?.active_matches_count ?? 0;
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const matchesPath = matchesIndex().url;

        const isMatchesVisit = (url: string): boolean => {
            try {
                return (
                    new URL(url, window.location.origin).pathname ===
                    matchesPath
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

    const isFiltering = filters.view === 'all' && filters.status !== null;
    const isEmpty = matches.data.length === 0;

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Your matches')}
                description={t('Your active and historical matches on Stakly.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Your matches')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t("Every match you've created or taken.")}{' '}
                        <span className="text-foreground/70">
                            {matches.meta.total === 1
                                ? t(':count match', {
                                      count: matches.meta.total,
                                  })
                                : t(':count matches', {
                                      count: matches.meta.total,
                                  })}
                        </span>
                    </p>
                </header>

                <div className="mb-6 space-y-4">
                    <MatchesViewTabs
                        filters={filters}
                        activeCount={activeCount}
                    />
                    {filters.view === 'all' && (
                        <MatchesFilterChips filters={filters} />
                    )}
                </div>

                {!isEmpty || isLoading ? (
                    <div className="overflow-hidden rounded-2xl border border-border/60 bg-card/40">
                        {/* Column header — desktop only. Mobile rows stack
                            vertically so labeled columns don't apply. */}
                        <div className="hidden border-b border-border/40 px-5 py-3 text-xs tracking-wide text-muted-foreground uppercase md:flex md:items-center md:gap-6">
                            <div className="md:w-52">{t('Opponent')}</div>
                            <div className="flex flex-1 items-center gap-6">
                                <div className="flex-1">{t('Status')}</div>
                                <div className="md:w-20 md:text-right">
                                    {t('Date')}
                                </div>
                                <div className="md:w-28 md:text-right">
                                    {t('Stake')}
                                </div>
                            </div>
                        </div>

                        {isLoading
                            ? Array.from({ length: SKELETON_ROW_COUNT }).map(
                                  (_, i) => <MatchListRowSkeleton key={i} />,
                              )
                            : matches.data.map((match) =>
                                  // M44 — a recruiting lobby you're in has no
                                  // opponent yet, so it renders its own row
                                  // (fill progress + Return → to the lobby).
                                  match.status === 'lobby_filling' ? (
                                      <RecruitingLobbyRow
                                          key={match.id}
                                          match={match}
                                      />
                                  ) : (
                                      <MatchListRow
                                          key={match.id}
                                          match={match}
                                      />
                                  ),
                              )}
                    </div>
                ) : (
                    <EmptyState view={filters.view} filtering={isFiltering} />
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
    view: MatchView;
    filtering: boolean;
}

function EmptyState({ view, filtering }: EmptyStateProps) {
    const t = useT();

    let title: string;
    let body: string;

    if (view === 'in_progress') {
        title = t('Nothing live right now');
        body = t("When you create or take a match, it'll show up here.");
    } else if (filtering) {
        title = t('No matches in this view');
        body = t('Try a different filter, or clear it to see every match.');
    } else {
        title = t('No matches yet');
        body = t(
            "Create a listing or take someone else's to start your first match.",
        );
    }

    return (
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border/60 bg-card/40 px-6 py-16 text-center">
            <div className="rounded-full bg-primary/10 p-3 text-primary">
                <Swords className="size-6" />
            </div>
            <h2 className="font-display text-xl font-semibold text-foreground">
                {title}
            </h2>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}
