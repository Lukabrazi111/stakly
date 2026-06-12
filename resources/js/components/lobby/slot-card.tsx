import { CheckCircle2, Crown, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { LobbyParticipantPayload, LobbySide } from '@/types';

interface FilledSlotProps {
    participant: LobbyParticipantPayload;
    isViewer: boolean;
    canKick: boolean;
    onKick: (username: string) => void;
}

/**
 * Filled lobby slot. Display-only — the viewer's Ready / Leave actions live
 * in the Money block on the center column, not on the slot card. Two visual
 * rows separated by a hairline divider:
 *   1. Header — avatar (with crown on creator) + name/platform handle, rating
 *      chip on the right, Ready/Waiting state pill underneath the rating.
 *   2. Stats — Matches / Win rate / Completion-30d row.
 *
 * Kept dimensionally identical to `EmptySlot` so the team column doesn't
 * reflow as players join / leave.
 */
export function FilledSlot({
    participant,
    isViewer,
    canKick,
    onKick,
}: FilledSlotProps) {
    const t = useT();
    const initials =
        participant.user.name
            ?.split(' ')
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase() ?? '??';

    const rating = participant.platform_account?.skill_rating ?? null;
    const showKickX = !isViewer && canKick && !participant.is_creator;

    return (
        <div
            className={cn(
                'relative flex flex-col rounded-xl border bg-card/60 px-3 py-2.5 transition-colors',
                participant.is_ready
                    ? 'border-success/40 bg-success/5'
                    : isViewer
                      ? 'border-primary/40 bg-primary/5'
                      : 'border-border/60',
            )}
        >
            <div className="flex items-center gap-3">
                <div className="relative size-10 shrink-0">
                    {participant.user.avatar_thumb_url ? (
                        <img
                            src={participant.user.avatar_thumb_url}
                            alt={participant.user.name}
                            className="size-10 rounded-full object-cover"
                        />
                    ) : (
                        <div className="flex size-10 items-center justify-center rounded-full bg-gradient-primary text-sm font-semibold text-foreground">
                            {initials}
                        </div>
                    )}
                    {participant.is_creator && (
                        <span
                            title={t('Lobby owner')}
                            className="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-accent text-background"
                        >
                            <Crown className="size-2.5" aria-hidden="true" />
                        </span>
                    )}
                </div>

                <div className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate text-sm font-semibold text-foreground">
                        {isViewer ? t('You') : participant.user.name}
                    </span>
                    {participant.platform_account && (
                        <span className="truncate font-mono text-[11px] text-muted-foreground">
                            {participant.platform_account.username}
                        </span>
                    )}
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1">
                    {rating !== null && (
                        <span className="rounded-md bg-muted/70 px-2 py-0.5 font-display text-sm font-bold text-foreground tabular-nums">
                            {rating}
                        </span>
                    )}
                    <ReadyPill ready={participant.is_ready} />
                </div>

                {showKickX && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-1 right-1 size-6 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => onKick(participant.user.username)}
                        aria-label={t('Kick :name from lobby', {
                            name: participant.user.name,
                        })}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                    </Button>
                )}
            </div>

            <StatsLine stats={participant.platform_stats} />
        </div>
    );
}

function ReadyPill({ ready }: { ready: boolean }) {
    const t = useT();

    if (ready) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-success/40 bg-success/10 px-1.5 py-0.5 text-[10px] font-medium text-success">
                <CheckCircle2 className="size-2.5" aria-hidden="true" />
                {t('Ready')}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full border border-border/60 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
            {t('Waiting')}
        </span>
    );
}

interface StatsLineProps {
    stats: LobbyParticipantPayload['platform_stats'];
}

/**
 * Three-cell stats row at the foot of the slot card: Matches / Win rate /
 * Completion-30d. Renders a muted placeholder for users with no settled
 * matches yet so the card height stays stable across roster states.
 */
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

interface EmptySlotProps {
    side: LobbySide;
    canJoin: boolean;
    onJoin: (side: LobbySide) => void;
}

/**
 * Empty slot placeholder. When the viewer can join, the whole card is the
 * Join CTA. Otherwise it's a passive "Open slot" indicator. Stretches to
 * the column's full width so the team column reads as a clean vertical
 * stack regardless of fill state.
 */
export function EmptySlot({ side, canJoin, onJoin }: EmptySlotProps) {
    const t = useT();

    if (!canJoin) {
        return (
            <div className="flex h-[88px] w-full items-center justify-center rounded-xl border border-dashed border-border/40 bg-card/30 text-xs text-muted-foreground">
                {t('Open slot')}
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onJoin(side)}
            className={cn(
                'group flex h-[88px] w-full cursor-pointer items-center justify-center rounded-xl border border-dashed text-sm font-medium transition-colors',
                'border-primary/40 bg-primary/5 text-primary',
                'hover:border-primary hover:bg-primary/10',
            )}
        >
            {t('Join Team :side', { side: side.toUpperCase() })}
        </button>
    );
}
