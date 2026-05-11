import { Clock, Globe, Languages, Trophy } from 'lucide-react';
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

function formatTimeRemaining(isoString: string): string {
    const target = new Date(isoString).getTime();
    const diffMs = target - Date.now();

    if (diffMs <= 0) {
        return 'Expired';
    }

    const minutes = Math.floor(diffMs / 60_000);

    if (minutes < 60) {
        return `${minutes}m left`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        const remainingMinutes = minutes % 60;

        return remainingMinutes > 0
            ? `${hours}h ${remainingMinutes}m`
            : `${hours}h left`;
    }

    const days = Math.floor(hours / 24);

    return `${days}d left`;
}

function isEndingSoon(isoString: string): boolean {
    return new Date(isoString).getTime() - Date.now() < 60 * 60 * 1000;
}

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

export function ListingRow({ listing }: Props) {
    const getInitials = useInitials();
    const timeRemaining = formatTimeRemaining(listing.expires_at);
    const endingSoon = isEndingSoon(listing.expires_at);

    return (
        <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm group flex flex-col gap-4 rounded-2xl border p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 md:flex-row md:items-center md:gap-6 md:p-5">
            {/* Creator */}
            <div className="flex min-w-0 items-center gap-3 md:w-48 md:shrink-0">
                <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                    <AvatarFallback className="bg-gradient-primary text-primary-foreground text-sm font-semibold">
                        {getInitials(listing.creator.name)}
                    </AvatarFallback>
                </Avatar>

                <div className="flex min-w-0 flex-col">
                    <span className="text-foreground truncate text-sm font-semibold">
                        {listing.creator.name}
                    </span>
                    {listing.region && (
                        <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                            <Globe className="size-3" />
                            {listing.region}
                        </span>
                    )}
                </div>
            </div>

            {/* Badges (skill / time control / language) */}
            <div className="flex flex-wrap items-center gap-2 md:flex-1">
                <span className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium">
                    <Trophy className="size-3" />
                    {formatSkillRange(listing.skill_min, listing.skill_max)}
                </span>

                <span className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium">
                    <Clock className="size-3" />
                    {timeControlLabels[listing.time_control]}
                </span>

                {listing.language && (
                    <span className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium">
                        <Languages className="size-3" />
                        {listing.language}
                    </span>
                )}
            </div>

            {/* Time remaining */}
            <div
                className={`inline-flex items-center gap-1.5 text-xs font-medium md:w-28 md:shrink-0 md:justify-end ${
                    endingSoon ? 'text-warning' : 'text-muted-foreground'
                }`}
            >
                <Clock className="size-3.5" />
                {timeRemaining}
            </div>

            {/* Stake amount */}
            <div className="flex items-baseline gap-1 md:w-32 md:shrink-0 md:justify-end">
                <span className="font-display text-gradient-primary text-2xl font-bold leading-none">
                    ${listing.stake_amount}
                </span>
                <span className="text-muted-foreground text-xs">USDT</span>
            </div>

            {/* Take CTA */}
            <div className="flex items-center gap-2 md:shrink-0">
                <Button
                    variant="gradient"
                    size="pill"
                    disabled
                    title="Coming in M4"
                    className="w-full md:w-auto"
                >
                    Take
                </Button>
                <span className="bg-background/80 text-muted-foreground hidden rounded-full px-2 py-0.5 text-[10px] tracking-wide uppercase backdrop-blur lg:inline-block">
                    Soon
                </span>
            </div>
        </article>
    );
}
