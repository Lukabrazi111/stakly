import type { MatchStatus } from '@/types';

/**
 * Short status labels for compact list rows + filter chips. The match
 * detail page (`pages/match/show.tsx`) uses its own slightly-longer
 * variants ("Pending — confirm outcome" etc.) so users get more context
 * on the page that demands action.
 */
export const matchStatusLabel: Record<MatchStatus, string> = {
    pending: 'Pending',
    disputed: 'Disputed',
    settled: 'Settled',
    manual_review: 'Manual review',
    cancelled: 'Cancelled',
};

/**
 * Stakly badge tones — kept consistent with `pages/match/show.tsx` so the
 * status pill on the index reads identically to the one on the detail page.
 */
export const matchStatusTone: Record<MatchStatus, string> = {
    pending: 'border-warning/40 bg-warning/10 text-warning',
    disputed: 'border-destructive/40 bg-destructive/10 text-destructive',
    settled: 'border-success/40 bg-success/10 text-success',
    manual_review: 'border-muted-foreground/40 bg-muted text-muted-foreground',
    cancelled: 'border-muted-foreground/40 bg-muted text-muted-foreground',
};

/**
 * Relative-time formatter for the index row: "just now", "5m ago", "3h ago",
 * "2d ago", and finally a localized absolute date once the match is older
 * than ~7 days. Frontend-only — no backend formatting needed.
 */
export function formatMatchDate(iso: string): string {
    const date = new Date(iso);
    const diffMs = Date.now() - date.getTime();
    const diffMinutes = Math.floor(diffMs / 60_000);

    if (diffMinutes < 1) {
        return 'just now';
    }

    if (diffMinutes < 60) {
        return `${diffMinutes}m ago`;
    }

    const diffHours = Math.floor(diffMinutes / 60);

    if (diffHours < 24) {
        return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);

    if (diffDays < 7) {
        return `${diffDays}d ago`;
    }

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
