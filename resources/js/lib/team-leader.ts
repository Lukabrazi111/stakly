import type { TeamMatchPlayer } from '@/types/match';

/**
 * Picks the team's leader from a match-page roster. Slot 0 is the leader
 * by backend convention (creator on creator's side, earliest-joined on
 * the opposing side — captured at `LobbyLockAction` time via
 * `LobbyParticipant.slot_index`). Returns null when the roster is empty.
 *
 * Shape-agnostic counterpart to `components/lobby/lobby-leader.ts` (which
 * works on `LobbyParticipantPayload`). Both helpers exist so each surface
 * picks the leader from its own resource shape without forcing a shared
 * intermediary type.
 */
export function pickTeamLeader(
    roster: TeamMatchPlayer[],
): TeamMatchPlayer | null {
    if (roster.length === 0) {
        return null;
    }

    const sorted = [...roster].sort((a, b) => a.slot_index - b.slot_index);

    return sorted[0];
}

/**
 * Formats the team's display label — "Team {leaderUsername}" if a leader
 * exists, falling back to the provided generic label otherwise.
 */
export function teamLabel(roster: TeamMatchPlayer[], fallback: string): string {
    const leader = pickTeamLeader(roster);

    return leader === null ? fallback : `Team ${leader.username}`;
}
