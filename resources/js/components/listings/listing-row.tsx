import { Link } from '@inertiajs/react';
import { Clock, Globe, Languages, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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
 * Marketplace row on `/listings`. Whole row clickable via the absolute-overlay
 * Link pattern (matches `MineListingRow` + the wallet's `ActionCard`):
 *   - Outer `<article relative>` carries hover lift/glow.
 *   - Absolute `inset-0` Link covers the entire row → listing detail.
 *   - Creator zone is a *sibling* Link with `relative` positioning so it
 *     paints above the overlay and intercepts its own clicks → user profile.
 *   - Body content (badges, time, stake) is `pointer-events-none` so clicks
 *     fall through to the overlay.
 *   - Take pill is a visual CTA only, also `pointer-events-none` — the
 *     entire row already navigates to the detail page where the real Take
 *     dialog + balance check live.
 */
export function ListingRow({ listing }: Props) {
    const getInitials = useInitials();
    const timeRemaining = formatTimeRemaining(listing.expires_at);
    const endingSoon = isEndingSoon(listing.expires_at);

    return (
        <article className="group relative flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/60 p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm md:flex-row md:items-center md:gap-6 md:p-5">
            {/* Overlay: entire row → listing detail */}
            <Link
                href={showListing(listing.id).url}
                aria-label={`View listing from ${listing.creator.name}`}
                className="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            {/* Creator zone — relative, sits above the overlay → user profile */}
            <Link
                href={userShow(listing.creator.username).url}
                className="relative flex min-w-0 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:w-48 md:shrink-0"
            >
                <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={listing.creator.avatar_thumb_url ?? undefined}
                        alt={listing.creator.name}
                    />
                    <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                        {getInitials(listing.creator.name)}
                    </AvatarFallback>
                </Avatar>

                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                        {listing.creator.name}
                    </span>
                    {listing.region && (
                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Globe className="size-3" />
                            {listing.region}
                        </span>
                    )}
                </div>
            </Link>

            {/* Listing body — no Link wrapper; clicks bubble to overlay */}
            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                        <Trophy className="size-3" />
                        {formatSkillRange(listing.skill_min, listing.skill_max)}
                    </span>

                    {listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {timeControlLabels[tc]}
                        </span>
                    ))}

                    {listing.language && listing.language.length > 0 && (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground">
                            <Languages className="size-3" />
                            {listing.language.slice(0, 3).join(', ')}
                            {listing.language.length > 3 &&
                                ` +${listing.language.length - 3}`}
                        </span>
                    )}
                </div>

                <div
                    className={`inline-flex items-center gap-1.5 text-xs font-medium md:w-28 md:shrink-0 md:justify-end ${
                        endingSoon ? 'text-warning' : 'text-muted-foreground'
                    }`}
                >
                    <Clock className="size-3.5" />
                    {timeRemaining}
                </div>

                <div className="flex items-baseline gap-1 md:w-32 md:shrink-0 md:justify-end">
                    <span className="text-gradient-primary font-display text-2xl leading-none font-bold">
                        ${listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>

            {/* Take CTA — its own Link with `relative` so it sits above the
                overlay and captures hover + click. Same destination as the
                row overlay (listing detail), but having its own pointer
                events means cursor + hover-glow boost work naturally like
                any other gradient button. */}
            <div className="relative flex items-center gap-2 md:shrink-0">
                <Button
                    variant="gradient"
                    size="pill"
                    asChild
                    className="w-full md:w-auto"
                >
                    <Link href={showListing(listing.id).url}>Take</Link>
                </Button>
            </div>
        </article>
    );
}
