import { Link } from '@inertiajs/react';
import { Clock, Gamepad2, Trophy } from 'lucide-react';
import { FaceitRatingBadge } from '@/components/listings/faceit-rating-badge';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { findGame } from '@/config/games';
import { useT } from '@/lib/i18n';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlChipLabels,
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
    const t = useT();
    const gameName = findGame(listing.game).name;
    const timeRemaining = formatTimeRemaining(listing.expires_at, t);
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
                aria-label={t('View listing #:id', { id: listing.id })}
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
                    {t(STATUS_LABEL[listing.status])}
                </span>

                <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                    <Gamepad2 className="size-3" aria-hidden="true" />
                    {gameName}
                </span>

                <VerifiedPlatformChip platform={listing.platform} />

                {listing.game === 'cs2' ? (
                    <FaceitRatingBadge
                        rating={listing.creator.faceit_rating}
                        variant="compact"
                    />
                ) : (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" aria-hidden="true" />
                        {formatSkillRange(
                            listing.skill_min,
                            listing.skill_max,
                            t,
                        )}
                    </span>
                )}

                {timeControlChipLabels(listing.time_control, t).map((label) => (
                    <span
                        key={label}
                        className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        <Clock className="size-3" aria-hidden="true" />
                        {label}
                    </span>
                ))}
            </div>

            <div
                className={`pointer-events-none relative inline-flex shrink-0 items-center gap-1 text-xs font-medium ${urgencyTone}`}
                aria-label={t('Listing ends in :time', { time: timeRemaining })}
            >
                <Clock className="size-3.5" aria-hidden="true" />
                {timeRemaining}
            </div>
        </article>
    );
}
