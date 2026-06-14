import type { LobbyParticipantPayload } from '@/types';

/**
 * Picks the team's leader participant. Creator's side → the creator.
 * Opposing side → first-joined participant (oldest `joined_at`).
 * Returns null when no one has joined the side yet.
 */
export function pickLeader(
    slots: Array<LobbyParticipantPayload | null>,
): LobbyParticipantPayload | null {
    const filled = slots.filter(
        (s): s is LobbyParticipantPayload => s !== null,
    );

    if (filled.length === 0) {
        return null;
    }

    const creator = filled.find((s) => s.is_creator);

    if (creator) {
        return creator;
    }

    return [...filled].sort((a, b) => {
        const ta = a.joined_at ? new Date(a.joined_at).getTime() : Infinity;
        const tb = b.joined_at ? new Date(b.joined_at).getTime() : Infinity;

        return ta - tb;
    })[0];
}

export function leaderLabel(
    leader: LobbyParticipantPayload | null,
    fallback: string,
): string {
    return leader === null ? fallback : `Team ${leader.user.username}`;
}
