import { EmptySlot, FilledSlot } from '@/components/lobby/slot-card';
import { useT } from '@/lib/i18n';
import type { Lobby, LobbySide } from '@/types';

interface Props {
    lobby: Lobby;
    side: LobbySide;
    onJoin: (side: LobbySide) => void;
    onKick: (username: string) => void;
}

const LABELS: Record<LobbySide, string> = {
    a: 'Team A',
    b: 'Team B',
};

/**
 * A single team's slot column for the 3-col team-play layout — Team A on
 * the left, the center-column blocks in the middle, Team B on the right.
 * Header shows the team name + fill counter; slot rows render as the
 * shared `FilledSlot` / `EmptySlot` pair (matched dimensions so the grid
 * doesn't reflow when someone joins or leaves).
 */
export function TeamSlotColumn({ lobby, side, onJoin, onKick }: Props) {
    const t = useT();
    const slots = lobby.roster[side];
    const filledCount = slots.filter((s) => s !== null).length;
    const viewer = lobby.viewer;

    const canViewerJoin =
        viewer !== null &&
        !viewer.is_participant &&
        lobby.lobby_state === 'recruiting' &&
        lobby.status === 'open';
    const canKick = viewer?.is_owner === true;

    return (
        <div className="space-y-2">
            <header className="flex items-center justify-between px-1">
                <h3 className="font-display text-sm font-semibold tracking-wide text-foreground">
                    {t(LABELS[side])}
                </h3>
                <span className="text-xs text-muted-foreground tabular-nums">
                    {filledCount} / {slots.length}
                </span>
            </header>
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
                                isViewer={slot.user.id === viewer?.id}
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
