import { Crown, Frown } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TeamMatchPlayer } from '@/types';

interface TeamRostersProps {
    teamA: TeamMatchPlayer[];
    teamB: TeamMatchPlayer[];
    /** When set (Settled match), winning side gets a crown + success tint
     *  and losing side gets a muted frown. Null = pre-settlement (no
     *  winner indicators). */
    winningTeam: 'a' | 'b' | null;
    /** Viewer's user id — drives the "(you)" badge next to their slot. */
    viewerId: number | null;
}

/**
 * Compact roster display for the team-aware match show page. Two columns
 * (Team A | Team B) on desktop, stacked on mobile. Slot order from the
 * resource (already sorted by slot_index ascending). Avatar + name + the
 * "you" badge if it's the viewer; winner crown / loser frown when the
 * match is Settled.
 */
export function TeamRosters({
    teamA,
    teamB,
    winningTeam,
    viewerId,
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

            <div className="grid gap-4 sm:grid-cols-[1fr_auto_1fr] sm:items-start">
                <TeamColumn
                    label={t('Team A')}
                    players={teamA}
                    isWinner={winningTeam === 'a'}
                    isLoser={winningTeam === 'b'}
                    viewerId={viewerId}
                />
                <div
                    aria-hidden="true"
                    className="hidden self-stretch border-l border-border/40 sm:block"
                />
                <TeamColumn
                    label={t('Team B')}
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
    return (
        <div>
            <div
                className={cn(
                    'mb-2 flex items-center justify-between gap-2',
                    isWinner && 'text-success',
                    isLoser && 'text-muted-foreground',
                )}
            >
                <span className="text-[10px] font-semibold tracking-wider uppercase">
                    {label}
                </span>
                {isWinner && (
                    <span className="inline-flex items-center gap-1 text-[10px] font-semibold tracking-wider text-success uppercase">
                        <Crown className="size-3" aria-hidden="true" />
                        {/* Localised label kept in sibling component via useT() — header span is decorative only */}
                    </span>
                )}
                {isLoser && (
                    <Frown
                        className="size-3 text-muted-foreground"
                        aria-hidden="true"
                    />
                )}
            </div>

            <ul className="space-y-1.5">
                {players.map((player) => (
                    <RosterRow
                        key={player.user_id}
                        player={player}
                        isWinner={isWinner}
                        isLoser={isLoser}
                        isViewer={player.user_id === viewerId}
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
}

function RosterRow({ player, isWinner, isLoser, isViewer }: RosterRowProps) {
    const t = useT();
    const initials = getInitials(player.name);

    return (
        <li
            className={cn(
                'flex items-center gap-2.5 rounded-lg border bg-background/40 px-2.5 py-1.5',
                isWinner && 'border-success/30 bg-success/5',
                isLoser && 'border-border/40',
                !isWinner && !isLoser && 'border-border/40',
            )}
        >
            {player.avatar_thumb_url ? (
                <img
                    src={player.avatar_thumb_url}
                    alt={player.name}
                    className="size-7 shrink-0 rounded-full border border-border/40 object-cover"
                />
            ) : (
                <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-gradient-primary text-[10px] font-bold text-white">
                    {initials}
                </span>
            )}

            <div className="min-w-0 flex-1">
                <p className="truncate text-xs font-medium text-foreground">
                    {player.name}
                    {isViewer && (
                        <span className="ml-1.5 text-[10px] font-semibold tracking-wider text-primary uppercase">
                            {t('(you)')}
                        </span>
                    )}
                </p>
                <p className="truncate text-[10px] text-muted-foreground">
                    @{player.username}
                </p>
            </div>

            {isWinner && (
                <Crown
                    className="size-3.5 shrink-0 text-success"
                    aria-label={t('Winner')}
                />
            )}
        </li>
    );
}

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
