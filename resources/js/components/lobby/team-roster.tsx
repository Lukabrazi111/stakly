import { useT } from '@/lib/i18n';
import { EmptySlot, FilledSlot } from '@/components/lobby/slot-card';
import type { Lobby, LobbySide } from '@/types';

interface TeamRosterProps {
    lobby: Lobby;
    onJoin: (side: LobbySide) => void;
    onKick: (username: string) => void;
}

/**
 * Side-by-side team layout (Team A | VS | Team B). At narrow widths the
 * two teams stack with the divider rendered as a horizontal "vs" chip.
 */
export function TeamRoster({ lobby, onJoin, onKick }: TeamRosterProps) {
    const t = useT();
    const viewer = lobby.viewer;

    const canViewerJoin =
        viewer !== null &&
        !viewer.is_participant &&
        lobby.lobby_state === 'recruiting' &&
        lobby.status === 'open';

    const canKick = viewer?.is_owner === true;

    return (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_auto_1fr] md:items-stretch">
            <TeamColumn
                label={t('Team A')}
                side="a"
                slots={lobby.roster.a}
                viewerUserId={viewer?.id ?? null}
                canViewerJoin={canViewerJoin}
                canKick={canKick}
                onJoin={onJoin}
                onKick={onKick}
            />

            <div className="flex items-center justify-center md:flex-col">
                <span className="rounded-full border border-border/60 bg-card/80 px-3 py-1 font-display text-sm font-bold tracking-wider text-muted-foreground">
                    {t('VS')}
                </span>
            </div>

            <TeamColumn
                label={t('Team B')}
                side="b"
                slots={lobby.roster.b}
                viewerUserId={viewer?.id ?? null}
                canViewerJoin={canViewerJoin}
                canKick={canKick}
                onJoin={onJoin}
                onKick={onKick}
            />
        </div>
    );
}

interface TeamColumnProps {
    label: string;
    side: LobbySide;
    slots: Lobby['roster']['a'];
    viewerUserId: number | null;
    canViewerJoin: boolean;
    canKick: boolean;
    onJoin: (side: LobbySide) => void;
    onKick: (username: string) => void;
}

function TeamColumn({
    label,
    side,
    slots,
    viewerUserId,
    canViewerJoin,
    canKick,
    onJoin,
    onKick,
}: TeamColumnProps) {
    const filledCount = slots.filter((s) => s !== null).length;

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between px-1">
                <h3 className="font-display text-sm font-semibold tracking-wide text-foreground">
                    {label}
                </h3>
                <span className="text-xs text-muted-foreground">
                    {filledCount} / {slots.length}
                </span>
            </div>
            <div className="space-y-2">
                {slots.map((slot, index) => (
                    <div key={`${side}-${index}`}>
                        {slot === null ? (
                            <EmptySlot
                                side={side}
                                canJoin={canViewerJoin}
                                onJoin={onJoin}
                            />
                        ) : (
                            <FilledSlot
                                participant={slot}
                                isViewer={slot.user.id === viewerUserId}
                                canKick={canKick}
                                onKick={onKick}
                            />
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
