import { Link, usePage } from '@inertiajs/react';
import { Clock, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { timeControlLabels } from '@/lib/listings-format';
import {
    formatMatchDate,
    matchStatusLabel,
    matchStatusTone,
} from '@/lib/matches-format';
import { show as matchShow } from '@/routes/matches';
import { show as userShow } from '@/routes/users';
import type { Match } from '@/types';

interface Props {
    match: Match;
}

/**
 * One match in the /matches list. Mirrors `ListingRow`'s two-interior-Links
 * pattern (opponent zone → opponent profile; body → match detail) so the
 * marketplace and the matches list feel visually consistent.
 *
 * The current user's perspective drives which player is "opponent" and
 * whether a settled match was a win or loss for them.
 */
export function MatchListRow({ match }: Props) {
    const getInitials = useInitials();
    const { auth } = usePage().props;

    const userId = auth.user?.id;
    const isCreator = match.creator.id === userId;
    const opponent = isCreator ? match.taker : match.creator;

    // Result chip only appears on settled matches. Pending/Disputed/ManualReview
    // matches don't have a winner yet, and the status pill carries the meaning.
    const youWon = match.winner !== null && userId === match.winner.id;
    const youLost
        = match.status === 'settled'
            && match.winner !== null
            && !youWon;

    return (
        <article className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm group flex flex-col gap-4 rounded-2xl border p-4 transition-all duration-200 ease-out hover:-translate-y-0.5 md:flex-row md:items-center md:gap-6 md:p-5">
            {/* Opponent zone → opponent profile */}
            <Link
                href={userShow(opponent.username).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background flex min-w-0 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none md:w-52 md:shrink-0"
            >
                <Avatar className="size-11 shrink-0 overflow-hidden rounded-full">
                    <AvatarFallback className="bg-gradient-primary text-primary-foreground text-sm font-semibold">
                        {getInitials(opponent.name)}
                    </AvatarFallback>
                </Avatar>

                <div className="flex min-w-0 flex-col">
                    <span className="text-foreground hover:text-primary truncate text-sm font-semibold transition-colors">
                        {opponent.name}
                    </span>
                    <span className="text-muted-foreground truncate text-xs">
                        @{opponent.username}
                    </span>
                </div>
            </Link>

            {/* Match body → match detail */}
            <Link
                href={matchShow(match.id).url}
                className="focus-visible:ring-primary focus-visible:ring-offset-background flex flex-1 flex-wrap items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none md:flex-nowrap md:gap-6"
            >
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    <span
                        className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium ${matchStatusTone[match.status]}`}
                    >
                        {matchStatusLabel[match.status]}
                    </span>

                    {match.listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="border-border/60 bg-background/60 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium"
                        >
                            <Clock className="size-3" />
                            {timeControlLabels[tc]}
                        </span>
                    ))}

                    {(youWon || youLost) && (
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium ${
                                youWon
                                    ? 'border-success/40 bg-success/10 text-success'
                                    : 'border-muted-foreground/40 bg-muted text-muted-foreground'
                            }`}
                        >
                            <Trophy className="size-3" />
                            {youWon ? 'You won' : 'You lost'}
                        </span>
                    )}
                </div>

                <div className="text-muted-foreground inline-flex shrink-0 items-center gap-1.5 text-xs font-medium md:w-20 md:justify-end">
                    {match.created_at && formatMatchDate(match.created_at)}
                </div>

                <div className="flex items-baseline gap-1 md:w-28 md:shrink-0 md:justify-end">
                    <span className="font-display text-gradient-primary text-2xl font-bold leading-none">
                        ${match.listing.stake_amount}
                    </span>
                    <span className="text-muted-foreground text-xs">USDT</span>
                </div>
            </Link>
        </article>
    );
}
