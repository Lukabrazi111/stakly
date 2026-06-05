import { Link } from '@inertiajs/react';
import { ProfileListingRow } from '@/components/profile/profile-listing-row';
import { useT } from '@/lib/i18n';
import { create as createListing } from '@/routes/listings';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
    isOwnProfile: boolean;
}

export function ListingsSection({ listings, isOwnProfile }: Props) {
    const t = useT();

    if (listings.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
                <p className="text-sm font-medium text-foreground">
                    {isOwnProfile
                        ? t('No open listings')
                        : t('No active listings right now')}
                </p>
                <p className="mx-auto mt-1 max-w-prose text-xs text-muted-foreground">
                    {isOwnProfile
                        ? t('Post a listing to find an opponent.')
                        : t('Check back later — listings come and go quickly.')}
                </p>
                {isOwnProfile && (
                    <Link
                        href={createListing().url}
                        className="mt-4 inline-flex text-sm font-medium text-primary transition-colors hover:text-primary/80"
                    >
                        {t('Create your first listing')}{' '}
                        <span aria-hidden="true">→</span>
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
