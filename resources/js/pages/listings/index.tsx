import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ListingFiltersBar } from '@/components/listings/listing-filters-bar';
import { ListingPagination } from '@/components/listings/listing-pagination';
import { ListingRow } from '@/components/listings/listing-row';
import { ListingRowSkeleton } from '@/components/listings/listing-row-skeleton';
import SiteLayout from '@/layouts/site-layout';
import { index as listingsIndex } from '@/routes/listings';
import type { ListingsIndexProps } from '@/types';

const SKELETON_ROW_COUNT = 6;

export default function ListingsIndex({
    listings,
    filters,
    sorts,
}: ListingsIndexProps) {
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const listingsPath = listingsIndex().url;

        const isListingsVisit = (url: string): boolean => {
            try {
                return new URL(url, window.location.origin).pathname === listingsPath;
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
        <SiteLayout>
            <Head title="Listings" />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Listings
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Find an opponent and stake your skill.{' '}
                        <span className="text-foreground/70">
                            {listings.meta.total}{' '}
                            {listings.meta.total === 1 ? 'open' : 'matching'}
                        </span>
                    </p>
                </header>

                <div className="mb-6">
                    <ListingFiltersBar filters={filters} sorts={sorts} />
                </div>

                {isLoading ? (
                    <div className="flex flex-col gap-3">
                        {Array.from({ length: SKELETON_ROW_COUNT }).map((_, i) => (
                            <ListingRowSkeleton key={i} />
                        ))}
                    </div>
                ) : listings.data.length === 0 ? (
                    <p className="text-muted-foreground py-16 text-center text-sm">
                        No listings match your filters yet.
                    </p>
                ) : (
                    <div className="flex flex-col gap-3">
                        {listings.data.map((listing) => (
                            <ListingRow key={listing.id} listing={listing} />
                        ))}
                    </div>
                )}

                <ListingPagination
                    currentPage={listings.meta.current_page}
                    lastPage={listings.meta.last_page}
                    filters={filters}
                />
            </div>
        </SiteLayout>
    );
}
