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
import type { Listing } from '@/types';

interface Props {
    listing: Listing;
}

/**
 * Compact vertical card for marketing surfaces (homepage featured strip).
 * Sibling to `ListingRow` (which is dense/horizontal for the index page).
 * Both render the same `Listing` shape and link to the detail page.
 */
export function ListingCard({ listing }: Props) {
    const getInitials = useInitials();
    const endingSoon = isEndingSoon(listing.expires_at);

    return (
        <Link
            href={showListing(listing.id).url}
            className="focus-visible:ring-primary focus-visible:ring-offset-background block h-full rounded-2xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm group flex h-full flex-col gap-4 rounded-2xl border p-5 transition-all duration-200 ease-out hover:-translate-y-0.5">
            {/* Header: creator + time remaining */}
            <header className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2.5">
                    <Avatar className="size-9 shrink-0 overflow-hidden rounded-full">
                        <AvatarFallback className="bg-gradient-primary text-primary-foreground text-xs font-semibold">
                            {getInitials(listing.creator.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="text-foreground truncate text-sm font-semibold">
                        {listing.creator.name}
                    </span>
                </div>

                <span
                    className={`inline-flex shrink-0 items-center gap-1 text-xs font-medium ${
                        endingSoon ? 'text-warning' : 'text-muted-foreground'
                    }`}
                >
                    <Clock className="size-3.5" />
                    {formatTimeRemaining(listing.expires_at)}
                </span>
            </header>

            {/* Stake (the headline) */}
            <div className="flex items-baseline gap-1.5">
                <span className="font-display text-gradient-primary text-3xl leading-none font-bold">
                    ${listing.stake_amount}
                </span>
                <span className="text-muted-foreground text-xs">USDT</span>
            </div>

            {/* Badges */}
            <div className="flex flex-wrap items-center gap-2">
                <span className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium">
                    <Trophy className="size-3" />
                    {formatSkillRange(listing.skill_min, listing.skill_max)}
                </span>
                {listing.time_control.map((tc) => (
                    <span
                        key={tc}
                        className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium"
                    >
                        <Clock className="size-3" />
                        {timeControlLabels[tc]}
                    </span>
                ))}
            </div>

            {/* Take CTA — `mt-auto` keeps it bottom-aligned so cards in the
                same grid row stay visually aligned regardless of badge count. */}
            <Button
                variant="gradient"
                size="pill"
                disabled
                title="Coming in M6"
                className="mt-auto w-full"
            >
                Take
            </Button>
            </article>
        </Link>
    );
}
