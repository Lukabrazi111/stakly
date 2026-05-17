import { Link } from '@inertiajs/react';
import { Clock, Handshake, Trophy, X } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { timeControlLabels } from '@/lib/listings-format';
import { formatMatchDate } from '@/lib/matches-format';
import { show as userShow } from '@/routes/users';
import type { Match } from '@/types';

interface Props {
    match: Match;
    /** The profile user's id — drives who is "opponent" and the win/loss/draw chip. */
    profileUserId: number;
}

/**
 * Compact match row for the public profile's history section. Informational
 * only — does NOT link through to the match detail page (`/matches/{id}` is
 * participant-only, so a non-participant click would 404).
 *
 * Result chip reflects the *profile user's* result, not the viewer's. So
 * Alice's profile showing a settled match where she beat Bob will say "Won"
 * regardless of who's viewing. A Settled match with no winner is a draw —
 * shown as a neutral "Draw" chip for both players.
 */
export function ProfileMatchRow({ match, profileUserId }: Props) {
    const getInitials = useInitials();

    const isCreator = match.creator.id === profileUserId;
    const opponent = isCreator ? match.taker : match.creator;
    const isDraw = match.winner === null;
    const profileUserWon =
        match.winner !== null && match.winner.id === profileUserId;

    return (
        <article className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card/60 p-4 md:flex-row md:items-center md:gap-5">
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
                ) : isDraw ? (
                    <Handshake className="size-3" />
                ) : (
                    <X className="size-3" />
                )}
                {profileUserWon ? 'Won' : isDraw ? 'Draw' : 'Lost'}
            </span>

            {/* Opponent → opponent's profile */}
            <Link
                href={userShow(opponent.username).url}
                className="flex min-w-0 flex-1 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <Avatar className="size-9 shrink-0 overflow-hidden rounded-full">
                    <AvatarFallback className="bg-gradient-primary text-xs font-semibold text-primary-foreground">
                        {getInitials(opponent.name)}
                    </AvatarFallback>
                </Avatar>
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-foreground transition-colors hover:text-primary">
                        vs {opponent.name}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        @{opponent.username}
                    </span>
                </div>
            </Link>

            {/* Metadata */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground md:shrink-0 md:justify-end">
                <span className="inline-flex items-center gap-1">
                    <Clock className="size-3" />
                    {match.listing.time_control
                        .map((tc) => timeControlLabels[tc])
                        .join(', ')}
                </span>
                <span className="font-semibold text-foreground">
                    ${match.listing.stake_amount}
                </span>
                {match.settled_at && (
                    <span>{formatMatchDate(match.settled_at)}</span>
                )}
            </div>
        </article>
    );
}
