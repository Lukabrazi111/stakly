import { Link } from '@inertiajs/react';
import { Clock, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CancelListingDialog } from '@/components/listings/cancel-listing-dialog';
import {
    formatTimeRemaining,
    isEndingSoon,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
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

/**
 * One row inside the `/listings/mine` table. Mirrors the visual model from
 * `MatchListRow` — sits inside a single wrapping card, `border-t` separator
 * between rows, calm `bg-primary/5` hover.
 *
 * Whole row is clickable to the listing detail page via an absolute-overlay
 * Link. The Cancel icon in a `relative` cell paints above the Link in the
 * stacking order and stops propagation. Per-listing pause/resume is
 * intentionally absent — the global Active Mode toggle in the page header
 * replaces per-listing visibility control (Phase 6.5 simplification).
 */
export function MineListingRow({ listing }: Props) {
    const [cancelOpen, setCancelOpen] = useState(false);

    const canCancel = listing.status === 'open';

    const openCancelDialog = (event: React.MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.stopPropagation();
        setCancelOpen(true);
    };

    const endingSoon
        = listing.status === 'open' && isEndingSoon(listing.expires_at);

    return (
        <article className="hover:bg-primary/5 border-border/40 group relative flex flex-col gap-3 border-t px-4 py-4 transition-colors duration-200 ease-out first:border-t-0 md:flex-row md:items-center md:gap-4 md:px-5">
            <Link
                href={showListing(listing.id).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background absolute inset-0 rounded-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                aria-label={`View listing #${listing.id}`}
            />

            {/* Content cells use pointer-events-none so clicks + cursor fall
                through to the overlay Link above. The Cancel button inside
                the actions cell keeps its own pointer events (children
                don't inherit pointer-events: none in CSS). */}

            {/* Status pill */}
            <span
                className={`pointer-events-none relative inline-flex shrink-0 items-center justify-center rounded-full border px-3 py-1 text-xs font-medium md:w-24 ${STATUS_TONE[listing.status]}`}
            >
                {STATUS_LABEL[listing.status]}
            </span>

            {/* Stake */}
            <div className="pointer-events-none relative flex shrink-0 items-baseline gap-1 md:w-28">
                <span className="font-display text-gradient-primary text-xl font-bold leading-none">
                    ${listing.stake_amount}
                </span>
                <span className="text-muted-foreground text-xs">USDT</span>
            </div>

            {/* Time controls */}
            <div className="pointer-events-none relative flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
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

            {/* Expires */}
            <div
                className={`pointer-events-none relative inline-flex shrink-0 items-center gap-1.5 text-xs font-medium md:w-24 md:justify-end ${endingSoon ? 'text-warning' : 'text-muted-foreground'}`}
            >
                <Clock className="size-3" />
                {formatTimeRemaining(listing.expires_at)}
            </div>

            {/* Actions — only Cancel for Open listings. Cell is
                pointer-events-none so empty space falls through to the
                overlay; the IconButton overrides with pointer-events-auto
                so the cancel click still works. */}
            {canCancel && (
                <div className="pointer-events-none relative flex shrink-0 items-center gap-1 md:w-24 md:justify-end">
                    <IconButton
                        onClick={openCancelDialog}
                        label="Cancel listing"
                        destructive
                        className="pointer-events-auto"
                    >
                        <X className="size-4" />
                    </IconButton>
                </div>
            )}

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
            className={`text-muted-foreground focus-visible:ring-primary/25 focus-visible:ring-offset-background relative inline-flex size-8 cursor-pointer items-center justify-center rounded-full transition-colors duration-150 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 ${hoverClasses} ${className}`}
        >
            {children}
        </button>
    );
}
