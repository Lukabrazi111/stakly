import { Head } from '@inertiajs/react';
import { ListingFiltersBar } from '@/components/listings/listing-filters-bar';
import { ListingRow } from '@/components/listings/listing-row';
import SiteLayout from '@/layouts/site-layout';
import type { ListingsIndexProps } from '@/types';

// Pagination UI lands in M3.9. For now: filters bar + rows + empty state.
export default function ListingsIndex({
    listings,
    filters,
    sorts,
}: ListingsIndexProps) {
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

                {listings.data.length === 0 ? (
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
            </div>
        </SiteLayout>
    );
}
