interface MatchTimestampsProps {
    // ISO 8601 from `GameMatchResource::created_at` / `settled_at`. The latter
    // is null for Pending / Disputed / ManualReview matches; we only render
    // the "Finished" stamp when settle has actually happened (Settled status
    // OR Settled-via-API for a Disputed match).
    startedAt: string | null;
    finishedAt: string | null;
}

/**
 * Subtle metadata strip shown right under the match-page subtitle. Conveys
 * lifecycle timing — "Started May 18, 2026, 09:12 PM · Finished May 18,
 * 2026, 09:13 PM" — at a glance, without forcing the user to scroll into
 * chat or read system messages.
 */
export function MatchTimestamps({
    startedAt,
    finishedAt,
}: MatchTimestampsProps) {
    if (!startedAt) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
            <time dateTime={startedAt}>
                Started {formatAbsoluteTime(startedAt)}
            </time>
            {finishedAt && (
                <>
                    <span aria-hidden>·</span>
                    <time dateTime={finishedAt}>
                        Finished {formatAbsoluteTime(finishedAt)}
                    </time>
                </>
            )}
        </div>
    );
}

/**
 * Locale-aware absolute timestamp — "May 18, 2026, 09:12 PM" in en-US. Other
 * locales adapt automatically via `toLocaleString` with `undefined` locale.
 */
function formatAbsoluteTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
}
