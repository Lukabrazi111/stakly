import { ProfileListingRow } from '@/components/profile/profile-listing-row';
import type { Listing } from '@/types';

interface Props {
    listings: Listing[];
}

export function ListingsSection({ listings }: Props) {
    return (
        <section>
            <h2 className="font-display text-foreground mb-3 text-lg font-semibold">
                Active listings · {listings.length}
            </h2>

            {listings.length === 0 ? (
                <div className="border-border/60 bg-card/40 rounded-xl border border-dashed p-8 text-center">
                    <p className="text-muted-foreground text-sm">
                        No active listings right now.
                    </p>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {listings.map((listing) => (
                        <ProfileListingRow
                            key={listing.id}
                            listing={listing}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}
