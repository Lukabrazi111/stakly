import { Link, router } from '@inertiajs/react';
import { Clock, Pause, Play } from 'lucide-react';
import { useState } from 'react';
import { timeControlLabels } from '@/lib/listings-format';
import {
    pause as pauseRoute,
    resume as resumeRoute,
    show as showListing,
} from '@/routes/listings';
import type { Listing, ListingStatus } from '@/types';

interface Props {
    listing: Listing;
    /** Whether to show the inline pause/resume icon. True on the viewer's own profile only. */
    isOwnProfile: boolean;
}

const STATUS_LABEL: Record<ListingStatus, string> = {
    open: 'Open',
    paused: 'Paused',
    taken: 'Taken',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const STATUS_TONE: Record<ListingStatus, string> = {
    open: 'border-success/40 bg-success/10 text-success',
    paused: 'border-warning/40 bg-warning/10 text-warning',
    taken: 'border-primary/40 bg-primary/10 text-primary',
    expired: 'border-border/60 bg-muted text-muted-foreground',
    cancelled: 'border-destructive/40 bg-destructive/10 text-destructive',
};

/**
 * Compact row for a listing on the profile page. Skips the creator avatar /
 * name from the marketplace `ListingRow` — on a profile, the creator is
 * already implied by the page itself.
 *
 * On the *viewer's own* profile, renders an inline pause/resume icon button
 * for one-click visibility toggling. Other-profile views show the row without
 * the action. The whole card is a Link to the listing detail page (where
 * Cancel + the full management UI lives).
 */
export function ProfileListingRow({ listing, isOwnProfile }: Props) {
    const [actionProcessing, setActionProcessing] = useState(false);

    const canToggle
        = isOwnProfile && (listing.status === 'open' || listing.status === 'paused');
    const isPaused = listing.status === 'paused';

    const handleToggle = (event: React.MouseEvent<HTMLButtonElement>) => {
        // Stop propagation so the outer Link doesn't navigate when the icon
        // button is clicked. The button sits inside a sibling div, not the
        // Link, but defensive in case of future layout changes.
        event.preventDefault();
        event.stopPropagation();

        if (actionProcessing) {
            return;
        }

        const route = isPaused
            ? resumeRoute(listing.id).url
            : pauseRoute(listing.id).url;

        setActionProcessing(true);
        router.post(
            route,
            {},
            {
                preserveScroll: true,
                onFinish: () => setActionProcessing(false),
            },
        );
    };

    return (
        <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card group relative flex items-center gap-3 rounded-xl border p-4 transition-all duration-200 ease-out md:gap-4">
            <Link
                href={showListing(listing.id).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                aria-label={`View listing #${listing.id}`}
            />

            {/* Stake — leftmost, prominent */}
            <div className="relative flex shrink-0 items-baseline gap-1">
                <span className="font-display text-gradient-primary text-xl font-bold leading-none md:text-2xl">
                    ${listing.stake_amount}
                </span>
                <span className="text-muted-foreground hidden text-xs sm:inline">
                    USDT
                </span>
            </div>

            {/* Status + time controls */}
            <div className="relative flex min-w-0 flex-1 flex-wrap items-center gap-2">
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

            {/* Inline pause/resume icon — own-profile only, status-gated */}
            {canToggle && (
                <button
                    type="button"
                    onClick={handleToggle}
                    disabled={actionProcessing}
                    aria-label={isPaused ? 'Resume listing' : 'Pause listing'}
                    title={isPaused ? 'Resume listing' : 'Pause listing'}
                    className="text-muted-foreground hover:bg-primary/10 hover:text-primary focus-visible:ring-primary/25 focus-visible:ring-offset-background relative inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {isPaused ? (
                        <Play className="size-4" />
                    ) : (
                        <Pause className="size-4" />
                    )}
                </button>
            )}
        </article>
    );
}
