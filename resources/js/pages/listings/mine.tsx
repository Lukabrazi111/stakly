import { Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ActiveModeToggle } from '@/components/listings/active-mode-toggle';
import { InactiveModeBanner } from '@/components/listings/inactive-mode-banner';
import { ListingsViewToggle } from '@/components/listings/listings-view-toggle';
import { MineListingGridCard } from '@/components/listings/mine-listing-grid-card';
import { MineListingRow } from '@/components/listings/mine-listing-row';
import { MinePagination } from '@/components/listings/mine-pagination';
import { MineTabs } from '@/components/listings/mine-tabs';
import { PageMeta } from '@/components/site/page-meta';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useListingsView } from '@/hooks/use-listings-view';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import { create as listingsCreate, mine as mineRoute } from '@/routes/listings';
import type { ListingsMineProps } from '@/types';

const SKELETON_ROW_COUNT = 4;

export default function ListingsMine({
    listings,
    tab,
    activeCount,
    maxActive,
}: ListingsMineProps) {
    const t = useT();
    const { auth } = usePage().props;
    const isInactive = Boolean(auth.user && !auth.user.is_active_mode);
    const atCap = activeCount >= maxActive;
    const [isLoading, setIsLoading] = useState(false);
    const { view, setView } = useListingsView();

    useEffect(() => {
        const minePath = mineRoute().url;

        const isMineVisit = (url: string): boolean => {
            try {
                return (
                    new URL(url, window.location.origin).pathname === minePath
                );
            } catch {
                return false;
            }
        };

        const removeStart = router.on('start', (event) => {
            if (isMineVisit(event.detail.visit.url.toString())) {
                setIsLoading(true);
            }
        });
        const removeFinish = router.on('finish', (event) => {
            if (isMineVisit(event.detail.visit.url.toString())) {
                setIsLoading(false);
            }
        });

        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    const isEmpty = listings.data.length === 0;

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('My listings')}
                description={t('Your listings dashboard.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                {/* Header — title + count on left, toggle + Post listing on right */}
                <header className="mb-6 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                            {t('My listings')}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t("Manage what's on the board.")}{' '}
                            <span
                                className={`font-medium ${atCap ? 'text-warning' : 'text-foreground/70'}`}
                            >
                                {t(':active of :max', {
                                    active: activeCount,
                                    max: maxActive,
                                })}
                            </span>{' '}
                            {t('active')}
                        </p>
                    </div>

                    <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center">
                        <ActiveModeToggle listingsCount={activeCount} />
                        {atCap ? (
                            <Button
                                variant="gradient"
                                size="default"
                                disabled
                                title={t(
                                    "You're at the :max-listing cap. Cancel or settle one first.",
                                    { max: maxActive },
                                )}
                            >
                                <Plus className="size-4" />
                                {t('Post listing')}
                            </Button>
                        ) : (
                            <Button variant="gradient" size="default" asChild>
                                <Link href={listingsCreate().url}>
                                    <Plus className="size-4" />
                                    {t('Post listing')}
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                <InactiveModeBanner />

                <div className="flex items-center justify-between gap-3">
                    <MineTabs current={tab} />
                    <ListingsViewToggle value={view} onChange={setView} />
                </div>

                {!isEmpty || isLoading ? (
                    view === 'grid' ? (
                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {isLoading
                                ? Array.from({
                                      length: SKELETON_ROW_COUNT,
                                  }).map((_, i) => <MineGridSkeleton key={i} />)
                                : listings.data.map((listing) => (
                                      <MineListingGridCard
                                          key={listing.id}
                                          listing={listing}
                                      />
                                  ))}
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-2xl border border-border/60 bg-card/40">
                            {/* Column header — desktop only */}
                            <div className="hidden border-b border-border/40 px-5 py-3 text-xs tracking-wide text-muted-foreground uppercase md:flex md:items-center md:gap-4">
                                <div className="md:w-24">{t('Game')}</div>
                                <div className="md:w-24 md:text-center">
                                    {t('Status')}
                                </div>
                                <div className="md:w-28">{t('Stake')}</div>
                                <div className="flex-1">
                                    {t('Time control')}
                                </div>
                                <div className="md:w-24 md:text-right">
                                    {t('Expires')}
                                </div>
                                <div className="md:w-24 md:text-right">
                                    {t('Actions')}
                                </div>
                            </div>

                            {isLoading
                                ? Array.from({
                                      length: SKELETON_ROW_COUNT,
                                  }).map((_, i) => <MineRowSkeleton key={i} />)
                                : listings.data.map((listing) => (
                                      <MineListingRow
                                          key={listing.id}
                                          listing={listing}
                                      />
                                  ))}
                        </div>
                    )
                ) : (
                    <EmptyState
                        tab={tab}
                        atCap={atCap}
                        isInactive={isInactive}
                    />
                )}

                <MinePagination
                    currentPage={listings.meta.current_page}
                    lastPage={listings.meta.last_page}
                    tab={tab}
                />
            </div>
        </PlayerHubLayout>
    );
}

interface EmptyStateProps {
    tab: ListingsMineProps['tab'];
    atCap: boolean;
    isInactive: boolean;
}

function EmptyState({ tab, atCap, isInactive }: EmptyStateProps) {
    const t = useT();
    const heading =
        tab === 'all'
            ? t('No listings yet')
            : isInactive
              ? t('Nothing listed while inactive')
              : t('No listings on the board');

    const body =
        tab === 'all'
            ? t(
                  'Once you post a listing, it shows up here — across every status.',
              )
            : isInactive
              ? t(
                    'Your listings are hidden globally. Switch to Active Mode (toggle in the header) or post a new listing.',
                )
              : t('Post one to find an opponent at your skill level.');

    return (
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border/60 bg-card/40 px-6 py-16 text-center">
            <h2 className="font-display text-xl font-semibold text-foreground">
                {heading}
            </h2>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
            {!atCap && (
                <Button
                    variant="gradient"
                    size="default"
                    asChild
                    className="mt-2"
                >
                    <Link href={listingsCreate().url}>
                        <Plus className="size-4" />
                        {t('Post listing')}
                    </Link>
                </Button>
            )}
        </div>
    );
}

function MineGridSkeleton() {
    return (
        <div
            aria-hidden
            className="flex h-full flex-col gap-4 rounded-2xl border border-border/60 bg-card/40 p-5"
        >
            <div className="flex items-center gap-1.5">
                <Skeleton className="h-6 w-16 rounded-full" />
                <Skeleton className="h-6 w-12 rounded-full" />
            </div>
            <Skeleton className="h-6 w-20 rounded-full" />
            <div className="flex flex-wrap gap-1.5">
                <Skeleton className="h-5 w-20 rounded-full" />
                <Skeleton className="h-5 w-16 rounded-full" />
            </div>
            <div className="mt-auto flex items-end justify-between border-t border-border/40 pt-4">
                <Skeleton className="h-7 w-20" />
                <Skeleton className="h-4 w-14" />
            </div>
        </div>
    );
}

function MineRowSkeleton() {
    return (
        <div
            aria-hidden
            className="flex flex-col gap-3 border-t border-border/40 px-4 py-4 first:border-t-0 md:flex-row md:items-center md:gap-4 md:px-5"
        >
            <div className="md:w-24">
                <Skeleton className="h-6 w-20 rounded-full" />
            </div>
            <div className="md:flex md:w-24 md:justify-center">
                <Skeleton className="h-6 w-20 rounded-full" />
            </div>
            <div className="md:w-28">
                <Skeleton className="h-6 w-20" />
            </div>
            <div className="flex flex-1 flex-wrap items-center gap-1.5">
                <Skeleton className="h-5 w-16 rounded-full" />
                <Skeleton className="h-5 w-16 rounded-full" />
            </div>
            <div className="md:flex md:w-24 md:justify-end">
                <Skeleton className="h-4 w-14" />
            </div>
            <div className="md:flex md:w-24 md:justify-end md:gap-1">
                <Skeleton className="size-8 rounded-full" />
                <Skeleton className="size-8 rounded-full" />
            </div>
        </div>
    );
}
