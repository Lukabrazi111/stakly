import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { ListingCard } from '@/components/listings/listing-card';
import { index as listingsIndex } from '@/routes/listings';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
}

/**
 * Top N ending-soon open listings rendered as a card grid below the Hero.
 * Marketing-surface preview of `/listings` — urgency-skewed to drive
 * exploration. Hidden entirely if no listings are open (we don't show
 * an empty marketplace on the landing page).
 */
export function FeaturedListings({ listings }: Props) {
    if (listings.length === 0) {
        return null;
    }

    return (
        <section className="mx-auto max-w-7xl px-4 py-12 md:px-6 md:py-16">
            <header className="mb-8 flex items-end justify-between gap-4">
                <div>
                    <h2 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
                        Ending soon
                    </h2>
                    <p className="text-muted-foreground mt-1.5 text-sm">
                        Open listings closing in the next few hours.
                    </p>
                </div>

                <Link
                    href={listingsIndex()}
                    className="text-muted-foreground hover:text-primary group inline-flex shrink-0 items-center gap-1.5 text-sm font-medium transition-colors"
                >
                    View all
                    <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                </Link>
            </header>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {listings.map((listing) => (
                    <ListingCard key={listing.id} listing={listing} />
                ))}
            </div>
        </section>
    );
}
