import { Link } from '@inertiajs/react';
import { Crown, ExternalLink, Frown } from 'lucide-react';
import { MatchDetailsTrigger } from '@/components/match/match-details-strip';
import { PLATFORM_LABEL, PLATFORM_PROFILE_URL } from '@/config/platforms';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { teamLabel } from '@/lib/team-leader';
import { cn } from '@/lib/utils';
import { show as userShow } from '@/routes/users';
import type { TeamMatchPlayer } from '@/types';
import type { ListingPlatform } from '@/types/listings';

// Team accent — Team A pink (primary), Team B purple (accent), the two ends of
// the brand gradient facing off. Mirrors the lobby slot cards (M43 P4) so a
// player reads as their side across lobby → match page.
const TEAM_AVATAR_RING: Record<'a' | 'b', string> = {
    a: 'ring-2 ring-primary/50',
    b: 'ring-2 ring-accent/50',
};

interface TeamRostersProps {
    teamA: TeamMatchPlayer[];
    teamB: TeamMatchPlayer[];
    /** When set (Settled match), winning side gets a crown + success tint
     *  and losing side gets a muted frown. Null = pre-settlement (no
     *  winner indicators). */
    winningTeam: 'a' | 'b' | null;
    /** Viewer's user id — tints the viewer's own card (not a "You" label; the
     *  card shows the real name, matching the lobby). */
    viewerId: number | null;
    /** Money + verification cells rendered as a strip at the top of the
     *  card. Closes the gap left when the lobby's Money block disappears
     *  post-lock — the team match page would otherwise have nowhere to
     *  surface Pot / Stake / payout / settlement source. */
    pot: number;
    stakeEach: number;
    winnerPayout: number;
    loserLoss: number;
    feeRate: number;
    platform: ListingPlatform;
}

/**
 * Roster display for the team-aware match show page. Two columns (Team A
 * | Team B) on desktop, stacked on mobile. Cards mirror the lobby's
 * `slot-card.tsx` shape (team-color avatar ring, name → profile link + platform
 * handle, labeled rating, and a uniform stats row so every card is the same
 * height). Leader crown sits on slot 0 of each side; winner crown appears once
 * the match Settles.
 */
export function TeamRosters({
    teamA,
    teamB,
    winningTeam,
    viewerId,
    pot,
    stakeEach,
    winnerPayout,
    loserLoss,
    feeRate,
    platform,
}: TeamRostersProps) {
    const t = useT();

    return (
        <section className="mb-6 rounded-2xl border border-border/60 bg-card/60 p-5">
            <header className="mb-5 flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <h2 className="font-display text-base font-semibold text-foreground">
                        {t('Rosters')}
                    </h2>
                    {winningTeam !== null && (
                        <span className="text-[10px] font-semibold tracking-wider text-success uppercase">
                            {t('Settled')}
                        </span>
                    )}
                </div>
                <MatchDetailsTrigger
                    pot={pot}
                    stakeEach={stakeEach}
                    winnerPayout={winnerPayout}
                    loserLoss={loserLoss}
                    feeRate={feeRate}
                    platform={platform}
                />
            </header>

            <div className="grid gap-5 sm:grid-cols-2">
                <TeamColumn
                    label={teamLabel(teamA, t('Team A'))}
                    players={teamA}
                    side="a"
                    isWinner={winningTeam === 'a'}
                    isLoser={winningTeam === 'b'}
                    viewerId={viewerId}
                    platform={platform}
                />
                <TeamColumn
                    label={teamLabel(teamB, t('Team B'))}
                    players={teamB}
                    side="b"
                    isWinner={winningTeam === 'b'}
                    isLoser={winningTeam === 'a'}
                    viewerId={viewerId}
                    platform={platform}
                />
            </div>
        </section>
    );
}

interface TeamColumnProps {
    label: string;
    players: TeamMatchPlayer[];
    side: 'a' | 'b';
    isWinner: boolean;
    isLoser: boolean;
    viewerId: number | null;
    platform: ListingPlatform;
}

function TeamColumn({
    label,
    players,
    side,
    isWinner,
    isLoser,
    viewerId,
    platform,
}: TeamColumnProps) {
    const t = useT();

    return (
        <div>
            <div
                className={cn(
                    'mb-2 flex items-center justify-between gap-2 px-1',
                    isWinner && 'text-success',
                    isLoser && 'text-muted-foreground',
                )}
            >
                <span className="truncate font-display text-sm font-semibold tracking-wide">
                    {label}
                </span>
                {isWinner && (
                    <span className="inline-flex shrink-0 items-center gap-1 text-[10px] font-semibold tracking-wider text-success uppercase">
                        <Crown className="size-3" aria-hidden="true" />
                        {t('Winner')}
                    </span>
                )}
                {isLoser && (
                    <Frown
                        className="size-3 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                )}
            </div>

            <ul className="space-y-2">
                {players.map((player) => (
                    <RosterRow
                        key={player.user_id}
                        player={player}
                        side={side}
                        isWinner={isWinner}
                        isLoser={isLoser}
                        isViewer={player.user_id === viewerId}
                        isLeader={player.slot_index === 0}
                        platform={platform}
                    />
                ))}
            </ul>
        </div>
    );
}

interface RosterRowProps {
    player: TeamMatchPlayer;
    side: 'a' | 'b';
    isWinner: boolean;
    isLoser: boolean;
    isViewer: boolean;
    isLeader: boolean;
    platform: ListingPlatform;
}

function RosterRow({
    player,
    side,
    isWinner,
    isLoser,
    isViewer,
    isLeader,
    platform,
}: RosterRowProps) {
    const t = useT();
    const getInitials = useInitials();
    const teamRing = TEAM_AVATAR_RING[side];

    return (
        <li
            className={cn(
                'flex flex-col rounded-xl border bg-card/60 px-3 py-2.5 transition-colors',
                isWinner && 'border-success/40 bg-success/5',
                isLoser && 'border-border/60 opacity-80',
                !isWinner &&
                    !isLoser &&
                    isViewer &&
                    'border-primary/40 bg-primary/5',
                !isWinner && !isLoser && !isViewer && 'border-border/60',
            )}
        >
            <div className="flex items-center gap-3">
                <div className="relative size-10 shrink-0">
                    {player.avatar_thumb_url ? (
                        <img
                            src={player.avatar_thumb_url}
                            alt={player.name}
                            className={cn(
                                'size-10 rounded-full object-cover',
                                teamRing,
                            )}
                        />
                    ) : (
                        <div
                            className={cn(
                                'flex size-10 items-center justify-center rounded-full bg-gradient-primary text-sm font-semibold text-white',
                                teamRing,
                            )}
                        >
                            {getInitials(player.name)}
                        </div>
                    )}
                    {isLeader && (
                        <span
                            title={t('Team leader')}
                            className="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-accent text-background"
                        >
                            <Crown className="size-2.5" aria-hidden="true" />
                        </span>
                    )}
                </div>

                <div className="flex min-w-0 flex-1 flex-col">
                    <Link
                        href={userShow({ user: player.username }).url}
                        className="w-fit max-w-full truncate text-sm font-semibold text-foreground hover:underline focus-visible:underline focus-visible:outline-none"
                    >
                        {player.name}
                    </Link>
                    <span className="truncate font-mono text-[11px] text-muted-foreground">
                        @{player.username}
                    </span>
                    {player.platform_username && (
                        <a
                            href={PLATFORM_PROFILE_URL[platform](
                                player.platform_username,
                            )}
                            target="_blank"
                            rel="noopener noreferrer"
                            title={t('View :platform profile', {
                                platform: PLATFORM_LABEL[platform],
                            })}
                            className="mt-0.5 inline-flex w-fit max-w-full items-center gap-0.5 font-mono text-[11px] text-muted-foreground transition-colors hover:text-primary"
                        >
                            <span className="truncate">
                                {PLATFORM_LABEL[platform]}:{' '}
                                {player.platform_username}
                            </span>
                            <ExternalLink
                                className="size-2.5 shrink-0"
                                aria-hidden="true"
                            />
                        </a>
                    )}
                </div>

                {player.skill_rating !== null && (
                    <div className="flex shrink-0 flex-col items-end">
                        <span className="text-[9px] tracking-wider text-muted-foreground/70 uppercase">
                            {t('Rating')}
                        </span>
                        <span className="font-display text-sm font-bold text-foreground tabular-nums">
                            {player.skill_rating}
                        </span>
                    </div>
                )}

                {isWinner && (
                    <Crown
                        className="size-4 shrink-0 text-success"
                        aria-label={t('Winner')}
                    />
                )}
            </div>

            <StatsLine stats={player.platform_stats} />
        </li>
    );
}

interface StatsLineProps {
    stats: TeamMatchPlayer['platform_stats'];
}

/**
 * Bottom stat row — the SAME 3-cell grid on every card so all cards in a
 * column stay the same height (F1). A player with no settled Stakly matches
 * reads as `0 / — / —` (honest "no track record yet" on a staking platform),
 * not a whisper-thin "no matches" line or a lonely chip in empty space.
 */
function StatsLine({ stats }: StatsLineProps) {
    const t = useT();
    const totalMatches = stats?.total_matches ?? 0;
    const winRate = stats?.win_rate ?? null;
    const completion = stats?.completion_rate_30d ?? null;

    return (
        <div className="mt-2.5 grid grid-cols-3 gap-2 border-t border-border/40 pt-2">
            <StatCell label={t('Matches')} value={String(totalMatches)} />
            <StatCell
                label={t('Win rate')}
                value={winRate === null ? '—' : `${winRate}%`}
                align="center"
            />
            <StatCell
                label={t('Completion 30d')}
                value={completion === null ? '—' : `${completion}%`}
                align="right"
            />
        </div>
    );
}

interface StatCellProps {
    label: string;
    value: string;
    align?: 'left' | 'center' | 'right';
}

function StatCell({ label, value, align = 'left' }: StatCellProps) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col',
                align === 'center' && 'items-center text-center',
                align === 'right' && 'items-end text-right',
            )}
        >
            <span className="text-[9px] tracking-wider text-muted-foreground/70 uppercase">
                {label}
            </span>
            <span className="font-display text-xs font-semibold text-foreground tabular-nums">
                {value}
            </span>
        </div>
    );
}
