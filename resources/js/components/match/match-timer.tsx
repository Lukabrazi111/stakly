import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MatchTimerProps {
    /** Deadline by which the game API must verify a result before the match flips to ManualReview. */
    deadline: Date;
}

const MS_30_MIN = 30 * 60 * 1000;
const MS_1_HOUR = 60 * 60 * 1000;

/**
 * Compact countdown chip for the 4-hour API-resolution deadline. Sits next
 * to the status badge in the match page header — small enough to live
 * inline, but ticks every second and shifts color as the deadline
 * approaches so it reads as live, not decorative.
 *
 * Tiers (matched to the page header badge style):
 *   > 1h     — pink (primary, brand neutral) — distinct from the amber
 *              "Pending" badge sitting next to it
 *   30m–1h   — amber (warning)
 *   < 30m    — red (destructive) + pulse
 *   expired  — muted gray, "Expired" label (`ResolveMatchTimeoutAction`
 *              flips the match to ManualReview in the next cron sweep)
 */
export function MatchTimer({ deadline }: MatchTimerProps) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, []);

    const msRemaining = deadline.getTime() - now;
    const isExpired = msRemaining <= 0;
    const isCritical = !isExpired && msRemaining < MS_30_MIN;
    const isWarning = !isExpired && !isCritical && msRemaining < MS_1_HOUR;

    const totalSeconds = Math.max(0, Math.floor(msRemaining / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const tierClass = isExpired
        ? 'border-muted-foreground/40 bg-muted text-muted-foreground'
        : isCritical
          ? 'border-destructive/40 bg-destructive/10 text-destructive motion-safe:animate-pulse'
          : isWarning
            ? 'border-warning/40 bg-warning/10 text-warning'
            : 'border-primary/40 bg-primary/10 text-primary';

    const display = isExpired
        ? 'Expired'
        : `${hours}h ${minutes.toString().padStart(2, '0')}m ${seconds.toString().padStart(2, '0')}s`;

    const ariaLabel = isExpired
        ? 'Match deadline expired'
        : `Time remaining: ${hours} hours ${minutes} minutes ${seconds} seconds`;

    return (
        <time
            dateTime={deadline.toISOString()}
            role="timer"
            aria-label={ariaLabel}
            className={`inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium ${tierClass}`}
        >
            <Clock className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="tabular-nums">{display}</span>
        </time>
    );
}
