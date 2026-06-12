import { EmptySlot, FilledSlot } from '@/components/lobby/slot-card';
import { useT } from '@/lib/i18n';
import type { Lobby, LobbyParticipantPayload, LobbySide } from '@/types';

interface Props {
    lobby: Lobby;
    side: LobbySide;
    onJoin: (side: LobbySide) => void;
    onKick: (username: string) => void;
}

const FALLBACK_LABELS: Record<LobbySide, string> = {
    a: 'Team A',
    b: 'Team B',
};

/**
 * Picks the team's leader name. Creator's side → the creator. Opposing
 * side → first-joined participant (oldest `joined_at`). Falls back to a
 * generic "Team A" / "Team B" label when no one has joined the side yet.
 */
function pickLeaderLabel(
    slots: Array<LobbyParticipantPayload | null>,
    fallback: string,
): string {
    const filled = slots.filter(
        (s): s is LobbyParticipantPayload => s !== null,
    );

    if (filled.length === 0) {
        return fallback;
    }

    const creator = filled.find((s) => s.is_creator);

    if (creator) {
        return `Team ${creator.user.username}`;
    }

    const earliest = [...filled].sort((a, b) => {
        const ta = a.joined_at ? new Date(a.joined_at).getTime() : Infinity;
        const tb = b.joined_at ? new Date(b.joined_at).getTime() : Infinity;

        return ta - tb;
    })[0];

    return `Team ${earliest.user.username}`;
}

/**
 * A single team's slot column for the 3-col team-play layout — Team A on
 * the left, the center-column blocks in the middle, Team B on the right.
 * Header shows the leader-derived team name + fill counter; slot rows
 * render as the shared `FilledSlot` / `EmptySlot` pair (matched dimensions
 * so the grid doesn't reflow when someone joins or leaves).
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

    const teamLabel = pickLeaderLabel(slots, t(FALLBACK_LABELS[side]));

    return (
        <div className="space-y-2">
            <header className="flex items-center justify-between px-1">
                <h3 className="truncate font-display text-sm font-semibold tracking-wide text-foreground">
                    {teamLabel}
                </h3>
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
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
