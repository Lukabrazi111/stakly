import type { MatchStatus } from '@/types/match';

/**
 * Single source of truth for "is the match chat read-only?" — consumed by
 * both `team-match-view.tsx` (match page) and `lobby-chat-panel.tsx`
 * (lobby page). A terminal match status freezes the chat to a transcript
 * (Settled / ManualReview / Cancelled). `Disputed` is NOT terminal — the
 * chat IS the dispute evidence channel, so we keep it open. Pre-lock
 * states (`lobby_filling`, null) keep the chat open too — viewers
 * coordinate before pressing Ready.
 */
export function isMatchChatReadOnly(status: MatchStatus | null): boolean {
    if (status === null) {
        return false;
    }

    return (
        status === 'settled' ||
        status === 'manual_review' ||
        status === 'cancelled'
    );
}
