import { Link } from '@inertiajs/react';
import { Clock, Handshake, Trophy, X } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
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

/** Compact match row for the public profile history. Does NOT link to
 *  match detail (participant-only). Result chip is from the profile user's
 *  perspective, not the viewer's. */
export function ProfileMatchRow({ match, profileUserId }: Props) {
    const getInitials = useInitials();

    const isCreator = match.creator.id === profileUserId;
    const opponent = isCreator ? match.taker : match.creator;
    const isDraw = match.winner === null;
    const profileUserWon =
        match.winner !== null && match.winner.id === profileUserId;

    return (
        <article className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card p-4 md:flex-row md:items-center md:gap-5">
            <span
                className={`inline-flex shrink-0 items-center gap-1.5 self-start rounded-full border px-3 py-1 text-xs font-medium md:self-center ${
                    profileUserWon
                        ? 'border-success/40 bg-success/10 text-success'
                        : 'border-muted-foreground/40 bg-muted text-muted-foreground'
                }`}
            >
                {profileUserWon ? (
                    <Trophy className="size-3" aria-hidden="true" />
                ) : isDraw ? (
                    <Handshake className="size-3" aria-hidden="true" />
                ) : (
                    <X className="size-3" aria-hidden="true" />
                )}
                {profileUserWon ? 'Won' : isDraw ? 'Draw' : 'Lost'}
            </span>

            <Link
                href={userShow({ user: opponent.username }).url}
                className="flex min-w-0 flex-1 items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <Avatar className="size-9 shrink-0 overflow-hidden rounded-full">
                    <AvatarImage
                        src={opponent.avatar_thumb_url ?? undefined}
                        alt={opponent.name}
                    />
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

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground md:shrink-0 md:justify-end">
                <span className="inline-flex items-center gap-1">
                    <Clock className="size-3" aria-hidden="true" />
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
