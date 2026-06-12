import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy, X } from 'lucide-react';
import { useState } from 'react';
import { CancelListingDialog } from '@/components/listings/cancel-listing-dialog';
import { GameChip } from '@/components/listings/game-chip';
import { ReadyCheckBanner } from '@/components/listings/ready-check-banner';
import { RosterPreview } from '@/components/listings/team-play-meta';
import { VerifiedPlatformChip } from '@/components/listings/verified-platform-chip';
import { useT } from '@/lib/i18n';
import {
    formatSkillRange,
    formatTimeRemaining,
    getTimeUrgency,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import { show as showLobby } from '@/routes/lobbies';
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
 * Grid-mode card for `/listings/mine`. Mirrors `MineListingRow` — status
 * badge + inline cancel for Open listings, no Take/View-lobby CTA at the
 * foot (whole-card overlay handles navigation). Owner-only surface; status
 * tone is the management signal, not stake size.
 */
export function MineListingGridCard({ listing }: Props) {
    const t = useT();
    const [cancelOpen, setCancelOpen] = useState(false);

    const canCancel = listing.status === 'open';
    const isTeamPlay = listing.team_size > 1;
    const overlayHref =
        isTeamPlay && listing.status === 'open'
            ? showLobby({ listing: listing.id }).url
            : showListing({ listing: listing.id }).url;

    const timeRemaining = formatTimeRemaining(listing.expires_at, t);
    const urgency = getTimeUrgency(listing.expires_at);
    const urgencyTone =
        urgency === 'critical'
            ? 'text-destructive'
            : urgency === 'warning'
              ? 'text-warning'
              : 'text-muted-foreground';

    const openCancelDialog = (event: React.MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.stopPropagation();
        setCancelOpen(true);
    };

    const showReadyCheckBanner =
        listing.lobby_state === 'ready_checking' &&
        listing.lobby_ready_check_deadline !== null;

    return (
        <article className="group relative flex h-full flex-col gap-4 overflow-hidden rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:p-5">
            <Link
                href={overlayHref}
                className="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                aria-label={
                    isTeamPlay
                        ? t('Open lobby #:id', { id: listing.id })
                        : t('View listing #:id', { id: listing.id })
                }
            />

            {showReadyCheckBanner && (
                <ReadyCheckBanner
                    deadlineIso={listing.lobby_ready_check_deadline as string}
                />
            )}

            <header className="relative flex items-start justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    <GameChip
                        game={listing.game}
                        teamSize={isTeamPlay ? listing.team_size : undefined}
                    />
                    <VerifiedPlatformChip platform={listing.platform} />
                </div>
                {canCancel && (
                    <button
                        type="button"
                        onClick={openCancelDialog}
                        aria-label={t('Cancel listing')}
                        title={t('Cancel listing')}
                        className="relative inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors duration-150 ease-out hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <X className="size-4" aria-hidden="true" />
                    </button>
                )}
            </header>

            <span
                className={`pointer-events-none relative inline-flex w-fit items-center justify-center rounded-full border px-3 py-1 text-xs font-medium ${STATUS_TONE[listing.status]}`}
            >
                {t(STATUS_LABEL[listing.status])}
            </span>

            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-1.5">
                <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                    <Trophy className="size-3" aria-hidden="true" />
                    {formatSkillRange(listing.skill_min, listing.skill_max, t)}
                </span>

                {!isTeamPlay &&
                    listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" aria-hidden="true" />
                            {t(timeControlLabels[tc])}
                        </span>
                    ))}

                {listing.language && listing.language.length > 0 && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Languages className="size-3" aria-hidden="true" />
                        {listing.language.slice(0, 2).join(', ')}
                        {listing.language.length > 2 &&
                            ` +${listing.language.length - 2}`}
                    </span>
                )}

                {listing.region && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                        <Globe className="size-3" aria-hidden="true" />
                        {listing.region}
                    </span>
                )}
            </div>

            {/* Roster preview — owner sees who's joined at a glance. Inner
                guard renders null when no participants have joined yet. */}
            {isTeamPlay && <RosterPreview listing={listing} />}

            <div className="relative mt-auto flex items-end justify-between gap-3 border-t border-border/40 pt-4">
                <div className="flex items-baseline gap-1">
                    <span className="text-gradient-primary font-display text-2xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
                <div
                    className={`inline-flex items-center gap-1 text-xs font-medium ${urgencyTone}`}
                >
                    <Clock className="size-3" aria-hidden="true" />
                    {timeRemaining}
                </div>
            </div>

            <CancelListingDialog
                listing={listing}
                open={cancelOpen}
                onOpenChange={setCancelOpen}
            />
        </article>
    );
}
