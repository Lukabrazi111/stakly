import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ActiveModeToggle } from '@/components/listings/active-mode-toggle';
import { InactiveModeBanner } from '@/components/listings/inactive-mode-banner';
import { MineListingRow } from '@/components/listings/mine-listing-row';
import { MinePagination } from '@/components/listings/mine-pagination';
import { MineTabs } from '@/components/listings/mine-tabs';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import SiteLayout from '@/layouts/site-layout';
import {
    create as listingsCreate,
    mine as mineRoute,
} from '@/routes/listings';
import type { ListingsMineProps } from '@/types';

const SKELETON_ROW_COUNT = 4;

export default function ListingsMine({
    listings,
    tab,
    activeCount,
    maxActive,
}: ListingsMineProps) {
    const { auth } = usePage().props;
    const isInactive = Boolean(auth.user && !auth.user.is_active_mode);
    const atCap = activeCount >= maxActive;
    const [isLoading, setIsLoading] = useState(false);

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
        <SiteLayout>
            <Head title="My listings" />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                {/* Header — title + count on left, toggle + Post listing on right */}
                <header className="mb-6 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                            My listings
                        </h1>
                        <p className="text-muted-foreground mt-2 text-sm">
                            Manage what&apos;s on the board.{' '}
                            <span
                                className={`font-medium ${atCap ? 'text-warning' : 'text-foreground/70'}`}
                            >
                                {activeCount} of {maxActive}
                            </span>{' '}
                            active
                        </p>
                    </div>

                    <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center">
                        <ActiveModeToggle listingsCount={activeCount} />
                        {atCap ? (
                            <Button
                                variant="gradient"
                                size="default"
                                disabled
                                title={`You're at the ${maxActive}-listing cap. Cancel or settle one first.`}
                            >
                                <Plus className="size-4" />
                                Post listing
                            </Button>
                        ) : (
                            <Button variant="gradient" size="default" asChild>
                                <Link href={listingsCreate().url}>
                                    <Plus className="size-4" />
                                    Post listing
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                <InactiveModeBanner />

                <MineTabs current={tab} />

                {!isEmpty || isLoading ? (
                    <div className="border-border/60 bg-card/40 overflow-hidden rounded-2xl border">
                        {/* Column header — desktop only */}
                        <div className="border-border/40 text-muted-foreground hidden border-b px-5 py-3 text-xs uppercase tracking-wide md:flex md:items-center md:gap-4">
                            <div className="md:w-24 md:text-center">
                                Status
                            </div>
                            <div className="md:w-28">Stake</div>
                            <div className="flex-1">Time control</div>
                            <div className="md:w-24 md:text-right">Expires</div>
                            <div className="md:w-24 md:text-right">Actions</div>
                        </div>

                        {isLoading
                            ? Array.from({ length: SKELETON_ROW_COUNT }).map(
                                (_, i) => <MineRowSkeleton key={i} />,
                            )
                            : listings.data.map((listing) => (
                                <MineListingRow
                                    key={listing.id}
                                    listing={listing}
                                />
                            ))}
                    </div>
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
        </SiteLayout>
    );
}

interface EmptyStateProps {
    tab: ListingsMineProps['tab'];
    atCap: boolean;
    isInactive: boolean;
}

function EmptyState({ tab, atCap, isInactive }: EmptyStateProps) {
    const heading
        = tab === 'all'
            ? 'No listings yet'
            : isInactive
                ? 'Nothing listed while inactive'
                : 'No listings on the board';

    const body
        = tab === 'all'
            ? 'Once you post a listing, it shows up here — across every status.'
            : isInactive
                ? 'Your listings are hidden globally. Switch to Active Mode (toggle in the header) or post a new listing.'
                : 'Post one to find an opponent at your skill level.';

    return (
        <div className="border-border/60 bg-card/40 flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-16 text-center">
            <h2 className="font-display text-foreground text-xl font-semibold">
                {heading}
            </h2>
            <p className="text-muted-foreground max-w-sm text-sm">{body}</p>
            {!atCap && (
                <Button
                    variant="gradient"
                    size="default"
                    asChild
                    className="mt-2"
                >
                    <Link href={listingsCreate().url}>
                        <Plus className="size-4" />
                        Post listing
                    </Link>
                </Button>
            )}
        </div>
    );
}

function MineRowSkeleton() {
    return (
        <div
            aria-hidden
            className="border-border/40 flex flex-col gap-3 border-t px-4 py-4 first:border-t-0 md:flex-row md:items-center md:gap-4 md:px-5"
        >
            <div className="md:w-24 md:flex md:justify-center">
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
