import { Link, usePage } from '@inertiajs/react';
import { Clock, Handshake, Trophy } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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

/** One row inside `/matches`. Whole row → match detail via absolute-overlay
 *  Link; opponent zone is a sibling Link with `relative` to capture its own clicks. */
export function MatchListRow({ match }: Props) {
    const getInitials = useInitials();
    const { auth } = usePage().props;

    const userId = auth.user?.id;
    const isCreator = match.creator.id === userId;
    const opponent = isCreator ? match.taker : match.creator;

    const youWon = match.winner !== null && userId === match.winner.id;
    const youLost =
        match.status === 'settled' && match.winner !== null && !youWon;
    const isDraw = match.status === 'settled' && match.winner === null;

    return (
        <article className="group relative flex flex-col gap-4 border-t border-border/40 px-4 py-4 transition-colors duration-200 ease-out first:border-t-0 hover:bg-primary/5 md:flex-row md:items-center md:gap-6 md:px-5">
            <Link
                href={matchShow(match.id).url}
                aria-label={`View match vs ${opponent.name}`}
                className="absolute inset-0 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            />

            <Link
                href={userShow(opponent.username).url}
                className="relative flex min-w-0 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:w-52 md:shrink-0"
            >
                <Avatar className="size-10 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={opponent.avatar_thumb_url ?? undefined}
                        alt={opponent.name}
                    />
                    <AvatarFallback className="bg-gradient-primary text-sm font-semibold text-primary-foreground">
                        {getInitials(opponent.name)}
                    </AvatarFallback>
                </Avatar>

                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-semibold text-foreground transition-colors hover:text-primary">
                        {opponent.name}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        @{opponent.username}
                    </span>
                </div>
            </Link>

            <div className="pointer-events-none relative flex flex-1 flex-wrap items-center gap-3 md:flex-nowrap md:gap-6">
                <div className="flex flex-wrap items-center gap-2 md:flex-1">
                    <span
                        className={`inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium ${matchStatusTone[match.status]}`}
                    >
                        {matchStatusLabel[match.status]}
                    </span>

                    {match.listing.time_control.map((tc) => (
                        <span
                            key={tc}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            <Clock className="size-3" />
                            {timeControlLabels[tc]}
                        </span>
                    ))}

                    {(youWon || youLost || isDraw) && (
                        <span
                            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium ${
                                youWon
                                    ? 'border-success/40 bg-success/10 text-success'
                                    : 'border-muted-foreground/40 bg-muted text-muted-foreground'
                            }`}
                        >
                            {isDraw ? (
                                <Handshake className="size-3" />
                            ) : (
                                <Trophy className="size-3" />
                            )}
                            {isDraw ? 'Draw' : youWon ? 'You won' : 'You lost'}
                        </span>
                    )}
                </div>

                <div className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-muted-foreground md:w-20 md:justify-end">
                    {match.created_at && formatMatchDate(match.created_at)}
                </div>

                <div className="flex items-baseline gap-1 md:w-28 md:shrink-0 md:justify-end">
                    <span className="text-gradient-primary font-display text-xl leading-none font-bold md:text-2xl">
                        ${match.listing.stake_amount}
                    </span>
                    <span className="text-xs text-muted-foreground">USDT</span>
                </div>
            </div>
        </article>
    );
}
