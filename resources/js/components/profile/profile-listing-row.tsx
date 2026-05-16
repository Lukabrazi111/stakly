import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { timeControlLabels } from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import type { Listing, ListingStatus } from '@/types';

interface Props {
    listing: Listing;
}

const STATUS_LABEL: Record<ListingStatus, string> = {
    open: 'Open',
    taken: 'Taken',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const STATUS_TONE: Record<ListingStatus, string> = {
    open: 'border-success/40 bg-success/10 text-success',
    taken: 'border-primary/40 bg-primary/10 text-primary',
    expired: 'border-border/60 bg-muted text-muted-foreground',
    cancelled: 'border-destructive/40 bg-destructive/10 text-destructive',
};

/**
 * Compact row for a listing on the profile page. Skips the creator avatar /
 * name from the marketplace `ListingRow` — on a profile, the creator is
 * already implied by the page itself.
 *
 * The whole card is a Link to the listing detail page. Listing management
 * (cancel) lives on the detail page; bulk visibility control lives on the
 * `/listings/mine` Active Mode toggle.
 */
export function ProfileListingRow({ listing }: Props) {
    return (
        <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card group relative flex items-center gap-3 rounded-xl border p-4 transition-all duration-200 ease-out md:gap-4">
            <Link
                href={showListing(listing.id).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                aria-label={`View listing #${listing.id}`}
            />

            {/* Stake — leftmost, prominent. pointer-events-none so clicks
                + cursor fall through to the overlay Link. */}
            <div className="pointer-events-none relative flex shrink-0 items-baseline gap-1">
                <span className="font-display text-gradient-primary text-xl font-bold leading-none md:text-2xl">
                    ${listing.stake_amount}
                </span>
                <span className="text-muted-foreground hidden text-xs sm:inline">
                    USDT
                </span>
            </div>

            {/* Status + time controls — same pointer-events-none treatment */}
            <div className="pointer-events-none relative flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <span
                    className={`inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATUS_TONE[listing.status]}`}
                >
                    {STATUS_LABEL[listing.status]}
                </span>
                {listing.time_control.map((tc) => (
                    <span
                        key={tc}
                        className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                    >
                        <Clock className="size-3" />
                        {timeControlLabels[tc]}
                    </span>
                ))}
            </div>
        </article>
    );
}
