import { ProfileListingRow } from '@/components/profile/profile-listing-row';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
}

export function ListingsSection({ listings }: Props) {
    if (listings.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 bg-card/40 p-8 text-center">
                <p className="text-sm text-muted-foreground">
                    No active listings right now.
                </p>
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
