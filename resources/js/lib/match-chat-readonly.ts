import type { MatchStatus } from '@/types/match';

/**
 * Single source of truth for "is the match chat read-only?" — consumed by
 * both `team-match-view.tsx` (match page) and `lobby-chat-panel.tsx`
 * (lobby page). Only truly terminal statuses freeze the chat to a
 * transcript: Settled (paid) + Cancelled (refunded). `Disputed` and
 * `ManualReview` are NOT terminal — the chat IS the evidence channel while
 * an admin resolves, and the dispute / timeout system messages ask players
 * to post evidence in chat, so we keep it open. Pre-lock states
 * (`lobby_filling`, null) keep the chat open too — viewers coordinate
 * before pressing Ready.
 */
export function isMatchChatReadOnly(status: MatchStatus | null): boolean {
    if (status === null) {
        return false;
    }

    return status === 'settled' || status === 'cancelled';
}
