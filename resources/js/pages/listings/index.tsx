import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ListingFiltersBar } from '@/components/listings/listing-filters-bar';
import { ListingGridCard } from '@/components/listings/listing-grid-card';
import { ListingPagination } from '@/components/listings/listing-pagination';
import { ListingRow } from '@/components/listings/listing-row';
import { ListingRowSkeleton } from '@/components/listings/listing-row-skeleton';
import { ListingsGameTabs } from '@/components/listings/listings-game-tabs';
import { ListingsViewToggle } from '@/components/listings/listings-view-toggle';
import { PageMeta } from '@/components/site/page-meta';
import { BGPattern } from '@/components/ui/bg-pattern';
import type { GameId } from '@/config/games';
import { useListingsView } from '@/hooks/use-listings-view';
import SiteLayout from '@/layouts/site-layout';
import { useT } from '@/lib/i18n';
import { buildListingsQuery } from '@/lib/listings-query';
import { index as listingsIndex } from '@/routes/listings';
import type { ListingsIndexProps } from '@/types';

const SKELETON_ROW_COUNT = 6;

export default function ListingsIndex({
    listings,
    filters,
    sorts,
    games,
}: ListingsIndexProps) {
    const t = useT();
    const [isLoading, setIsLoading] = useState(false);
    const { view, setView } = useListingsView();

    const selectedGame = games.data.find((g) => g.slug === filters.game);
    const isComingSoonGame = selectedGame?.status === 'coming_soon';

    const switchGame = (slug: string) => {
        if (slug === filters.game) {
            return;
        }

        // Drop game-specific filter selections — chess's `time_control` set
        // is meaningless on CS2/Dota/etc. Universal filters (stake, region,
        // language, sort) carry over because they apply across all games.
        // Skill range is held over for now: only Chess is Active, so
        // cross-game skill-metric semantics (Elo vs MMR vs Faceit ELO) are
        // theoretical until the second adapter lands in M15.
        router.get(
            listingsIndex().url,
            buildListingsQuery({
                ...filters,
                game: slug as GameId,
                time_control: [],
            }),
            {
                preserveState: false,
                preserveScroll: false,
                replace: false,
            },
        );
    };

    useEffect(() => {
        const listingsPath = listingsIndex().url;

        const isListingsVisit = (url: string): boolean => {
            try {
                return (
                    new URL(url, window.location.origin).pathname ===
                    listingsPath
                );
            } catch {
                return false;
            }
        };

        const removeStart = router.on('start', (event) => {
            if (isListingsVisit(event.detail.visit.url.toString())) {
                setIsLoading(true);
            }
        });

        const removeFinish = router.on('finish', (event) => {
            if (isListingsVisit(event.detail.visit.url.toString())) {
                setIsLoading(false);
            }
        });

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    return (
        <SiteLayout showMarquee>
            <PageMeta
                title="Browse chess listings"
                description="Live peer-to-peer chess staking marketplace. Filter open listings by stake, skill range, time control, and region. Take a listing to start a match — both stakes go in escrow until the game ends."
            />

            <div className="relative isolate">
                <BGPattern
                    variant="dots"
                    mask="fade-edges"
                    size={28}
                    fill="rgba(168, 85, 247, 0.18)"
                />

                <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                    <header className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                                {t('Listings')}
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {t('Find an opponent and stake your skill.')}{' '}
                                <span className="text-foreground/70">
                                    {listings.meta.total}{' '}
                                    {listings.meta.total === 1
                                        ? t('open')
                                        : t('matching')}
                                </span>
                            </p>
                        </div>
                        <ListingsViewToggle value={view} onChange={setView} />
                    </header>

                    <div className="mb-5">
                        <ListingsGameTabs
                            games={games.data}
                            selectedSlug={filters.game}
                            onSelect={switchGame}
                        />
                    </div>

                    <div className="mb-6">
                        <ListingFiltersBar filters={filters} sorts={sorts} />
                    </div>

                    {isLoading ? (
                        <div className="flex flex-col gap-3">
                            {Array.from({ length: SKELETON_ROW_COUNT }).map(
                                (_, i) => (
                                    <ListingRowSkeleton key={i} />
                                ),
                            )}
                        </div>
                    ) : listings.data.length === 0 ? (
                        <p className="py-16 text-center text-sm text-muted-foreground">
                            {isComingSoonGame && selectedGame
                                ? t(
                                      ':game launches with our next platform release.',
                                      { game: selectedGame.display_name },
                                  )
                                : t('No listings match your filters yet.')}
                        </p>
                    ) : view === 'grid' ? (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {listings.data.map((listing) => (
                                <ListingGridCard
                                    key={listing.id}
                                    listing={listing}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {/* Desktop-only column header strip. Widths mirror
                                the row's column widths in `listing-row.tsx` —
                                including the trailing `w-40` spacer for the
                                Take/Manage CTA — so each label sits over its
                                data column. Hidden on mobile where rows stack
                                vertically and labels would only confuse. */}
                            <div
                                className="hidden gap-6 px-5 text-[10px] font-medium tracking-wider text-muted-foreground uppercase md:flex md:items-center"
                                aria-hidden="true"
                            >
                                <div className="w-48 shrink-0">
                                    {t('Player')}
                                </div>
                                <div className="flex flex-1 items-center gap-6">
                                    <div className="flex-1">{t('Match')}</div>
                                    <div className="w-28 shrink-0 text-right">
                                        {t('Ends in')}
                                    </div>
                                    <div className="w-32 shrink-0 text-right">
                                        {t('Stake')}
                                    </div>
                                </div>
                                <div className="w-44 shrink-0" />
                            </div>

                            {listings.data.map((listing) => (
                                <ListingRow
                                    key={listing.id}
                                    listing={listing}
                                />
                            ))}
                        </div>
                    )}

                    <ListingPagination
                        currentPage={listings.meta.current_page}
                        lastPage={listings.meta.last_page}
                        filters={filters}
                    />
                </div>
            </div>
        </SiteLayout>
    );
}
