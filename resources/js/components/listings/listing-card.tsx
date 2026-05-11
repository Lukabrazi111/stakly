import { Clock, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import type { Listing, TimeControl } from '@/types';

interface Props {
    listing: Listing;
}

const timeControlLabels: Record<TimeControl, string> = {
    blitz: 'Blitz',
    rapid: 'Rapid',
    classical: 'Classical',
};

function formatSkillRange(min: number | null, max: number | null): string {
    if (min === null && max === null) {
        return 'Any skill';
    }

    if (min !== null && max !== null) {
        return `${min}-${max} Elo`;
    }

    if (min !== null) {
        return `${min}+ Elo`;
    }

    return `up to ${max} Elo`;
}

function formatTimeRemaining(isoString: string): string {
    const diffMs = new Date(isoString).getTime() - Date.now();

    if (diffMs <= 0) {
        return 'Expired';
    }

    const minutes = Math.floor(diffMs / 60_000);

    if (minutes < 60) {
        return `${minutes}m left`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h left`;
    }

    return `${Math.floor(hours / 24)}d left`;
}

function isEndingSoon(isoString: string): boolean {
    return new Date(isoString).getTime() - Date.now() < 60 * 60 * 1000;
}

/**
 * Compact vertical card for marketing surfaces (homepage featured strip).
 * Sibling to `ListingRow` (which is dense/horizontal for the index page).
 * Both render the same `Listing` shape.
 */
export function ListingCard({ listing }: Props) {
    const getInitials = useInitials();
    const endingSoon = isEndingSoon(listing.expires_at);

    return (
        <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm group flex flex-col gap-4 rounded-2xl border p-5 transition-all duration-200 ease-out hover:-translate-y-0.5">
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
                <span className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-medium">
                    <Clock className="size-3" />
                    {timeControlLabels[listing.time_control]}
                </span>
            </div>

            {/* Take CTA */}
            <Button
                variant="gradient"
                size="pill"
                disabled
                title="Coming in M4"
                className="w-full"
            >
                Take
            </Button>
        </article>
    );
}
