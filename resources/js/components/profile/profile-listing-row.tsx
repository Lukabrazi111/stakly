import { Link } from '@inertiajs/react';
import { Clock, Gamepad2, Trophy } from 'lucide-react';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { findGame } from '@/config/games';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabels,
} from '@/lib/listings-format';
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

/** Compact listing row for the profile page (no creator block — implied by the page). */
export function ProfileListingRow({ listing }: Props) {
    const gameName = findGame(listing.game).name;
    const timeRemaining = formatTimeRemaining(listing.expires_at);
    const urgency = getTimeUrgency(listing.expires_at);

    const urgencyTone =
        urgency === 'critical'
            ? 'text-destructive'
            : urgency === 'warning'
              ? 'text-warning'
              : 'text-muted-foreground';

    return (
        <article className="group relative flex flex-wrap items-center gap-3 rounded-xl border border-border/60 bg-card p-4 transition-all duration-200 ease-out hover:border-primary/30 hover:bg-secondary md:flex-nowrap md:gap-4">
            <Link
                href={showListing({ listing: listing.id }).url}
                className="absolute inset-0 rounded-xl focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                aria-label={`View listing #${listing.id}`}
            />

            <div className="pointer-events-none relative flex shrink-0 items-baseline gap-1">
                <span className="text-gradient-primary font-display text-xl leading-none font-bold md:text-2xl">
                    ${listing.stake_amount}
                </span>
                <span className="hidden text-xs text-muted-foreground sm:inline">
                    USDT
                </span>
            </div>

            <div className="pointer-events-none relative flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <span
                    className={`inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${STATUS_TONE[listing.status]}`}
                >
                    {STATUS_LABEL[listing.status]}
                </span>

                <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                    <Gamepad2 className="size-3" aria-hidden="true" />
                    {gameName}
                </span>

                <VerifiedPlatformChip platform={listing.platform} />

                <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                    <Trophy className="size-3" aria-hidden="true" />
                    {formatSkillRange(listing.skill_min, listing.skill_max)}
                </span>

                {listing.time_control.map((tc) => (
                    <span
                        key={tc}
                        className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        <Clock className="size-3" aria-hidden="true" />
                        {timeControlLabels[tc]}
                    </span>
                ))}
            </div>

            <div
                className={`pointer-events-none relative inline-flex shrink-0 items-center gap-1 text-xs font-medium ${urgencyTone}`}
                aria-label={`Listing ends in ${timeRemaining}`}
            >
                <Clock className="size-3.5" aria-hidden="true" />
                {timeRemaining}
            </div>
        </article>
    );
}
