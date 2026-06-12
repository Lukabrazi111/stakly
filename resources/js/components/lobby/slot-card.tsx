import { Crown, CheckCircle2, X } from 'lucide-react';
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
 * A filled lobby slot. Same outer dimensions as `EmptySlot` so the grid
 * doesn't reflow as players join / leave.
 *
 * Visual hierarchy: avatar + name + linked username + skill rating, with
 * a Ready badge anchored top-right and an optional Kick affordance (owner
 * only, never on the creator's own slot).
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

    return (
        <div
            className={cn(
                'group relative flex h-20 items-center gap-3 rounded-xl border bg-card/60 p-3 transition-colors',
                isViewer
                    ? 'border-primary/40 bg-primary/5'
                    : 'border-border/60',
                participant.is_ready &&
                    'shadow-(--shadow-arena-card-glow)',
            )}
        >
            <div className="relative size-12 shrink-0">
                {participant.user.avatar_thumb_url ? (
                    <img
                        src={participant.user.avatar_thumb_url}
                        alt={participant.user.name}
                        className="size-12 rounded-full object-cover"
                    />
                ) : (
                    <div className="flex size-12 items-center justify-center rounded-full bg-gradient-primary text-sm font-semibold text-foreground">
                        {initials}
                    </div>
                )}
                {participant.is_creator && (
                    <span
                        title={t('Lobby owner')}
                        className="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-accent text-background"
                    >
                        <Crown className="size-3" aria-hidden="true" />
                    </span>
                )}
            </div>

            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex items-center gap-1.5">
                    <span className="truncate text-sm font-semibold text-foreground">
                        {participant.user.name}
                    </span>
                </div>
                {participant.platform_account && (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <span className="truncate font-mono">
                            {participant.platform_account.username}
                        </span>
                        {participant.platform_account.skill_rating !== null && (
                            <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium">
                                {participant.platform_account.skill_rating}
                            </span>
                        )}
                    </div>
                )}
            </div>

            <div className="flex shrink-0 items-center gap-1.5">
                {participant.is_ready ? (
                    <span className="inline-flex items-center gap-1 rounded-full border border-success/40 bg-success/10 px-2 py-1 text-xs font-medium text-success">
                        <CheckCircle2
                            className="size-3.5"
                            aria-hidden="true"
                        />
                        {t('Ready')}
                    </span>
                ) : (
                    <span className="inline-flex items-center rounded-full border border-border/60 px-2 py-1 text-xs font-medium text-muted-foreground">
                        {t('Waiting')}
                    </span>
                )}
                {canKick && !participant.is_creator && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => onKick(participant.user.username)}
                        aria-label={t('Kick :name from lobby', {
                            name: participant.user.name,
                        })}
                    >
                        <X className="size-4" aria-hidden="true" />
                    </Button>
                )}
            </div>
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
 * Join CTA. Otherwise it's a passive "Open slot" indicator. Identical
 * outer dimensions to `FilledSlot`.
 */
export function EmptySlot({ side, canJoin, onJoin }: EmptySlotProps) {
    const t = useT();

    if (!canJoin) {
        return (
            <div className="flex h-20 items-center justify-center rounded-xl border border-dashed border-border/40 bg-card/30 text-xs text-muted-foreground">
                {t('Open slot')}
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => onJoin(side)}
            className={cn(
                'group flex h-20 cursor-pointer items-center justify-center rounded-xl border border-dashed text-sm font-medium transition-colors',
                'border-primary/40 bg-primary/5 text-primary',
                'hover:border-primary hover:bg-primary/10',
            )}
        >
            {t('Join Team :side', { side: side.toUpperCase() })}
        </button>
    );
}
