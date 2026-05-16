import { Link } from '@inertiajs/react';
import { Clock, Trophy, X } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { timeControlLabels } from '@/lib/listings-format';
import { formatMatchDate } from '@/lib/matches-format';
import { show as userShow } from '@/routes/users';
import type { Match } from '@/types';

interface Props {
    match: Match;
    /** The profile user's id — drives who is "opponent" and the win/loss chip. */
    profileUserId: number;
}

/**
 * Compact match row for the public profile's history section. Informational
 * only — does NOT link through to the match detail page (`/matches/{id}` is
 * participant-only, so a non-participant click would 404).
 *
 * Win/loss reflects the *profile user's* result, not the viewer's. So Alice's
 * profile showing a settled match where she beat Bob will say "Won" regardless
 * of who's viewing.
 */
export function ProfileMatchRow({ match, profileUserId }: Props) {
    const getInitials = useInitials();

    const isCreator = match.creator.id === profileUserId;
    const opponent = isCreator ? match.taker : match.creator;
    const profileUserWon
        = match.winner !== null && match.winner.id === profileUserId;

    return (
        <article className="border-border/60 bg-card/60 flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-center md:gap-5">
            {/* Result chip — leads visually so the outcome is instantly clear */}
            <span
                className={`inline-flex shrink-0 items-center gap-1.5 self-start rounded-full border px-3 py-1 text-xs font-medium md:self-center ${
                    profileUserWon
                        ? 'border-success/40 bg-success/10 text-success'
                        : 'border-muted-foreground/40 bg-muted text-muted-foreground'
                }`}
            >
                {profileUserWon ? (
                    <Trophy className="size-3" />
                ) : (
                    <X className="size-3" />
                )}
                {profileUserWon ? 'Won' : 'Lost'}
            </span>

            {/* Opponent → opponent's profile */}
            <Link
                href={userShow(opponent.username).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background flex min-w-0 flex-1 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <Avatar className="size-9 shrink-0 overflow-hidden rounded-full">
                    <AvatarFallback className="bg-gradient-primary text-primary-foreground text-xs font-semibold">
                        {getInitials(opponent.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="flex min-w-0 flex-col">
                    <span className="text-foreground hover:text-primary truncate text-sm font-medium transition-colors">
                        vs {opponent.name}
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        @{opponent.username}
                    </span>
                </div>
            </Link>

            {/* Metadata */}
            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs md:shrink-0 md:justify-end">
                <span className="inline-flex items-center gap-1">
                    <Clock className="size-3" />
                    {match.listing.time_control
                        .map((tc) => timeControlLabels[tc])
                        .join(', ')}
                </span>
                <span className="text-foreground font-semibold">
                    ${match.listing.stake_amount}
                </span>
                {match.settled_at && (
                    <span>{formatMatchDate(match.settled_at)}</span>
                )}
            </div>
        </article>
    );
}
