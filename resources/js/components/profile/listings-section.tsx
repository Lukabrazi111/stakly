import { Link } from '@inertiajs/react';
import { ProfileListingRow } from '@/components/profile/profile-listing-row';
import { create as createListing } from '@/routes/listings';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
    isOwnProfile: boolean;
}

export function ListingsSection({ listings, isOwnProfile }: Props) {
    if (listings.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
                <p className="text-sm font-medium text-foreground">
                    {isOwnProfile
                        ? 'No open listings'
                        : 'No active listings right now'}
                </p>
                <p className="mx-auto mt-1 max-w-prose text-xs text-muted-foreground">
                    {isOwnProfile
                        ? 'Post a listing to find an opponent.'
                        : 'Check back later — listings come and go quickly.'}
                </p>
                {isOwnProfile && (
                    <Link
                        href={createListing().url}
                        className="mt-4 inline-flex text-sm font-medium text-primary transition-colors hover:text-primary/80"
                    >
                        Create your first listing →
                    </Link>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            {listings.map((listing) => (
                <ProfileListingRow key={listing.id} listing={listing} />
            ))}
        </div>
    );
}
