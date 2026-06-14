import { Crown, Frown } from 'lucide-react';
import { MatchDetailsStrip } from '@/components/match/match-details-strip';
import { useInitials } from '@/hooks/use-initials';
import { useT } from '@/lib/i18n';
import { teamLabel } from '@/lib/team-leader';
import { cn } from '@/lib/utils';
import type { TeamMatchPlayer } from '@/types';
import type { ListingPlatform } from '@/types/listings';

interface TeamRostersProps {
    teamA: TeamMatchPlayer[];
    teamB: TeamMatchPlayer[];
    /** When set (Settled match), winning side gets a crown + success tint
     *  and losing side gets a muted frown. Null = pre-settlement (no
     *  winner indicators). */
    winningTeam: 'a' | 'b' | null;
    /** Viewer's user id — drives the "(you)" badge next to their slot. */
    viewerId: number | null;
    /** Money + verification cells rendered as a strip at the top of the
     *  card. Closes the gap left when the lobby's Money block disappears
     *  post-lock — the team match page would otherwise have nowhere to
     *  surface Pot / Stake / payout / settlement source. */
    pot: number;
    stakeEach: number;
    winnerPayout: number;
    loserLoss: number;
    platform: ListingPlatform;
}

/**
 * Roster display for the team-aware match show page. Two columns (Team A
 * | Team B) on desktop, stacked on mobile. Cards mirror the lobby's
 * `slot-card.tsx` shape (avatar + name + platform handle on top row,
 * rating chip right, Matches / Win rate / Completion-30d stats line
 * across the bottom) so the visual language stays consistent between
 * lobby → match-page. Leader crown sits on slot 0 of each side; winner
 * crown appears once the match Settles.
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
    platform,
}: TeamRostersProps) {
    const t = useT();

    return (
        <section className="mb-6 rounded-2xl border border-border/60 bg-card/60 p-5">
            <header className="mb-4 flex items-center justify-between">
                <h2 className="font-display text-base font-semibold text-foreground">
                    {t('Rosters')}
                </h2>
                {winningTeam !== null && (
                    <span className="text-[10px] font-semibold tracking-wider text-success uppercase">
                        {t('Settled')}
                    </span>
                )}
            </header>

            <MatchDetailsStrip
                pot={pot}
                stakeEach={stakeEach}
                winnerPayout={winnerPayout}
                loserLoss={loserLoss}
                platform={platform}
            />

            <div className="grid gap-5 sm:grid-cols-2">
                <TeamColumn
                    label={teamLabel(teamA, t('Team A'))}
                    players={teamA}
                    isWinner={winningTeam === 'a'}
                    isLoser={winningTeam === 'b'}
                    viewerId={viewerId}
                />
                <TeamColumn
                    label={teamLabel(teamB, t('Team B'))}
                    players={teamB}
                    isWinner={winningTeam === 'b'}
                    isLoser={winningTeam === 'a'}
                    viewerId={viewerId}
                />
            </div>
        </section>
    );
}

interface TeamColumnProps {
    label: string;
    players: TeamMatchPlayer[];
    isWinner: boolean;
    isLoser: boolean;
    viewerId: number | null;
}

function TeamColumn({
    label,
    players,
    isWinner,
    isLoser,
    viewerId,
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
                        isWinner={isWinner}
                        isLoser={isLoser}
                        isViewer={player.user_id === viewerId}
                        isLeader={player.slot_index === 0}
                    />
                ))}
            </ul>
        </div>
    );
}

interface RosterRowProps {
    player: TeamMatchPlayer;
    isWinner: boolean;
    isLoser: boolean;
    isViewer: boolean;
    isLeader: boolean;
}

function RosterRow({
    player,
    isWinner,
    isLoser,
    isViewer,
    isLeader,
}: RosterRowProps) {
    const t = useT();
    const getInitials = useInitials();

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
                            className="size-10 rounded-full object-cover"
                        />
                    ) : (
                        <div className="flex size-10 items-center justify-center rounded-full bg-gradient-primary text-sm font-semibold text-white">
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
                    <span className="truncate text-sm font-semibold text-foreground">
                        {isViewer ? t('You') : player.name}
                    </span>
                    <span className="truncate font-mono text-[11px] text-muted-foreground">
                        @{player.username}
                    </span>
                </div>

                {player.skill_rating !== null && (
                    <span className="shrink-0 rounded-md bg-muted/70 px-2 py-0.5 font-display text-sm font-bold text-foreground tabular-nums">
                        {player.skill_rating}
                    </span>
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

function StatsLine({ stats }: StatsLineProps) {
    const t = useT();

    if (stats === null || stats.total_matches === 0) {
        return (
            <div className="mt-2 border-t border-border/40 pt-1.5 text-[10px] text-muted-foreground/60">
                {t('No matches yet')}
            </div>
        );
    }

    return (
        <div className="mt-2 grid grid-cols-3 gap-2 border-t border-border/40 pt-1.5">
            <StatCell
                label={t('Matches')}
                value={String(stats.total_matches)}
            />
            <StatCell
                label={t('Win rate')}
                value={stats.win_rate === null ? '—' : `${stats.win_rate}%`}
                align="center"
            />
            <StatCell
                label={t('Completion 30d')}
                value={
                    stats.completion_rate_30d === null
                        ? '—'
                        : `${stats.completion_rate_30d}%`
                }
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
