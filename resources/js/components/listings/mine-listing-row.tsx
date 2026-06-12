import { Link } from '@inertiajs/react';
import { Clock, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CancelListingDialog } from '@/components/listings/cancel-listing-dialog';
import { GameChip } from '@/components/listings/game-chip';
import {
    LobbyFillCounter,
    LobbyStateBadge,
} from '@/components/listings/team-play-meta';
import { useT } from '@/lib/i18n';
import {
    formatTimeRemaining,
    isEndingSoon,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import { show as showLobby } from '@/routes/lobbies';
import type { Listing, ListingStatus } from '@/types';

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

interface Props {
    listing: Listing;
}

/** One row inside `/listings/mine`. Whole row → listing detail via an
 *  absolute-overlay Link; Cancel sits in a `relative` cell above it. */
export function MineListingRow({ listing }: Props) {
    const t = useT();
    const [cancelOpen, setCancelOpen] = useState(false);

    const canCancel = listing.status === 'open';
    const isTeamPlay = listing.team_size > 1;
    // Owners follow the same routing rule as marketplace viewers: team-play
    // → lobby (coordination + leave-as-cancel), chess → listing detail.
    // Locked / Settled / Cancelled team-play rows fall back to the detail
    // page since the lobby URL 404s in those states.
    const overlayHref =
        isTeamPlay && listing.status === 'open'
            ? showLobby({ listing: listing.id }).url
            : showListing({ listing: listing.id }).url;

    const openCancelDialog = (event: React.MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.stopPropagation();
        setCancelOpen(true);
    };

    const endingSoon =
        listing.status === 'open' && isEndingSoon(listing.expires_at);

    return (
        <article className="group relative flex flex-col gap-2 border-t border-border/40 px-4 py-4 transition-colors duration-200 ease-out first:border-t-0 hover:bg-primary/5 md:flex-row md:items-center md:gap-4 md:px-5">
            <Link
                href={overlayHref}
                className="absolute inset-0 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                aria-label={
                    isTeamPlay
                        ? t('Open lobby #:id', { id: listing.id })
                        : t('View listing #:id', { id: listing.id })
                }
            />

            {/* Mobile: two grouped rows. Desktop: wrappers collapse via
                `md:contents` so children flow into the article's flex-row. */}
            <div className="flex items-center gap-3 md:contents">
                <div className="pointer-events-none relative shrink-0 md:w-24">
                    <GameChip
                        game={listing.game}
                        teamSize={isTeamPlay ? listing.team_size : undefined}
                    />
                </div>

                <span
                    className={`pointer-events-none relative inline-flex shrink-0 items-center justify-center rounded-full border px-3 py-1 text-xs font-medium md:w-24 ${STATUS_TONE[listing.status]}`}
                >
                    {t(STATUS_LABEL[listing.status])}
                </span>

                <div className="pointer-events-none relative flex shrink-0 items-baseline gap-1 md:w-28">
                    <span className="text-gradient-primary font-display text-xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>

            <div className="flex items-center gap-3 md:contents">
                <div className="pointer-events-none relative flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {isTeamPlay && (
                        <LobbyStateBadge state={listing.lobby_state} />
                    )}
                    {isTeamPlay && <LobbyFillCounter listing={listing} />}
                    {listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {t(timeControlLabels[tc])}
                        </span>
                    ))}
                </div>

                <div
                    className={`pointer-events-none relative inline-flex shrink-0 items-center gap-1.5 text-xs font-medium md:w-24 md:justify-end ${endingSoon ? 'text-warning' : 'text-muted-foreground'}`}
                >
                    <Clock className="size-3" />
                    {formatTimeRemaining(listing.expires_at, t)}
                </div>

                {/* Cell is pointer-events-none so empty space falls through
                    to the overlay; the button overrides to pointer-events-auto. */}
                {canCancel && (
                    <div className="pointer-events-none relative flex shrink-0 items-center gap-1 md:w-24 md:justify-end">
                        <IconButton
                            onClick={openCancelDialog}
                            label={t('Cancel listing')}
                            destructive
                            className="pointer-events-auto"
                        >
                            <X className="size-4" />
                        </IconButton>
                    </div>
                )}
            </div>

            <CancelListingDialog
                listing={listing}
                open={cancelOpen}
                onOpenChange={setCancelOpen}
            />
        </article>
    );
}

interface IconButtonProps {
    onClick: (event: React.MouseEvent<HTMLButtonElement>) => void;
    disabled?: boolean;
    label: string;
    destructive?: boolean;
    className?: string;
    children: ReactNode;
}

function IconButton({
    onClick,
    disabled = false,
    label,
    destructive = false,
    className = '',
    children,
}: IconButtonProps) {
    const hoverClasses = destructive
        ? 'hover:bg-destructive/10 hover:text-destructive'
        : 'hover:bg-primary/10 hover:text-primary';

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className={`relative inline-flex size-8 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${hoverClasses} ${className}`}
        >
            {children}
        </button>
    );
}
