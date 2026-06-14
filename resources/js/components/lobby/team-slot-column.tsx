import { EmptySlot, FilledSlot } from '@/components/lobby/slot-card';
import type { Lobby, LobbySide } from '@/types';

interface Props {
    lobby: Lobby;
    side: LobbySide;
    onJoin: (side: LobbySide) => void;
    onKick: (username: string) => void;
}

/**
 * A single team's slot column for the 3-col team-play layout — Team A on
 * the left, the center-column blocks in the middle, Team B on the right.
 * Team identity (leader avatar + "Team {username}" + fill counter) lives
 * in `LobbyHeader` above the grid; this component is just the slot stack.
 */
export function TeamSlotColumn({ lobby, side, onJoin, onKick }: Props) {
    const slots = lobby.roster[side];
    const viewer = lobby.viewer;

    const canViewerJoin =
        viewer !== null &&
        !viewer.is_participant &&
        lobby.lobby_state === 'recruiting' &&
        lobby.status === 'open';
    const canKick = viewer?.is_owner === true;

    return (
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
    );
}
