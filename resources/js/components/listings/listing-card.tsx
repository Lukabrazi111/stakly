import { Link } from '@inertiajs/react';
import { Clock, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import {
    formatSkillRange,
    formatTimeRemaining,
    isEndingSoon,
    timeControlLabels,
} from '@/lib/listings-format';
import { show as showListing } from '@/routes/listings';
import { show as userShow } from '@/routes/users';
import type { Listing } from '@/types';

interface Props {
    listing: Listing;
}

/**
 * Compact vertical card for marketing surfaces (homepage featured strip).
 * Sibling to `ListingRow` (dense/horizontal for the index page).
 *
 * Clickability uses the absolute-overlay-Link pattern (same as the wallet's
 * `ActionCard` and `MineListingRow`): the article is `relative`, an
 * `absolute inset-0` Link covers the entire card to navigate to the listing
 * detail page, and the creator zone is a *sibling* Link with `relative`
 * positioning so it paints above the overlay and intercepts its own clicks
 * (→ user profile). The Take pill is visual-only with `pointer-events-none`
 * so its click bubbles to the overlay — entire card is clickable.
 */
export function ListingCard({ listing }: Props) {
    const getInitials = useInitials();
    const endingSoon = isEndingSoon(listing.expires_at);

    return (
        <article className="group relative flex h-full flex-col gap-4 rounded-2xl border border-border/60 bg-card/60 p-5 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm">
            {/* Overlay: entire card → listing detail */}
            <Link
                href={showListing(listing.id).url}
                aria-label={`View listing from ${listing.creator.name}`}
                className="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            {/* Header: creator Link (sits above overlay) + time remaining text */}
            <header className="relative flex items-start justify-between gap-3">
                <Link
                    href={userShow(listing.creator.username).url}
                    className="flex min-w-0 items-center gap-2.5 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Avatar className="size-9 shrink-0 overflow-hidden rounded-full">
                        <AvatarFallback className="bg-gradient-primary text-xs font-semibold text-primary-foreground">
                            {getInitials(listing.creator.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                        {listing.creator.name}
                    </span>
                </Link>

                <span
                    className={`pointer-events-none inline-flex shrink-0 items-center gap-1 text-xs font-medium ${
                        endingSoon ? 'text-warning' : 'text-muted-foreground'
                    }`}
                >
                    <Clock className="size-3.5" />
                    {formatTimeRemaining(listing.expires_at)}
                </span>
            </header>

            {/* Body — stake + badges. No Link; clicks bubble to the overlay. */}
            <div className="pointer-events-none relative flex flex-col gap-4">
                <div className="flex items-baseline gap-1.5">
                    <span className="text-gradient-primary font-display text-3xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                        <Trophy className="size-3" />
                        {formatSkillRange(listing.skill_min, listing.skill_max)}
                    </span>
                    {listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1 rounded-full border border-border/60 bg-background/60 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {timeControlLabels[tc]}
                        </span>
                    ))}
                </div>
            </div>

            {/* Take CTA — its own Link with `relative` so it sits above the
                overlay and captures hover + click. Same destination as the
                overlay (listing detail), but having its own pointer events
                means cursor + hover-glow boost work naturally like any
                other gradient button. */}
            <Button
                variant="gradient"
                size="pill"
                asChild
                className="relative mt-auto w-full"
            >
                <Link href={showListing(listing.id).url}>Take</Link>
            </Button>
        </article>
    );
}
