import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';

interface MatchTimerProps {
    /** Deadline by which both players should have confirmed an outcome. */
    deadline: Date;
}

const MS_30_MIN = 30 * 60 * 1000;
const MS_1_HOUR = 60 * 60 * 1000;

/**
 * Countdown timer for the 4-hour confirmation window. Visible only while
 * the match is `Pending` (parent gates with `match.status === 'pending'`).
 *
 * Visual urgency tiers:
 *   > 1h     — neutral (default card tone)
 *   30m–1h   — warning (amber)
 *   < 30m    — destructive (red) + pulse on icon
 *   expired  — muted (gray) — backend Phase 7 timeout job will resolve the
 *              match in the next sweep
 *
 * `tabular-nums` keeps the digits from shifting width every second.
 * `motion-safe:animate-pulse` honors `prefers-reduced-motion`.
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

    const tone = isExpired
        ? 'border-muted-foreground/40 bg-muted/40 text-muted-foreground'
        : isCritical
          ? 'border-destructive/40 bg-destructive/5 text-destructive'
          : isWarning
            ? 'border-warning/40 bg-warning/5 text-warning'
            : 'border-border/60 bg-card/60 text-foreground';

    const label = isExpired ? 'Time expired' : 'Time to confirm';

    const display = isExpired
        ? 'Expired'
        : `${hours}h ${minutes.toString().padStart(2, '0')}m ${seconds.toString().padStart(2, '0')}s`;

    const ariaLabel = isExpired
        ? 'Match confirmation window has expired'
        : `Time to confirm: ${hours} hours ${minutes} minutes ${seconds} seconds`;

    return (
        <div
            role="timer"
            aria-label={ariaLabel}
            className={`flex items-center gap-3 rounded-2xl border p-4 transition-colors duration-300 ${tone}`}
        >
            <Clock
                className={`size-5 shrink-0 ${isCritical ? 'motion-safe:animate-pulse' : ''}`}
                aria-hidden="true"
            />
            <div className="flex-1 min-w-0">
                <div className="text-xs uppercase tracking-wide opacity-70">
                    {label}
                </div>
                <div className="font-display text-xl font-bold tabular-nums">
                    {display}
                </div>
            </div>
            <div className="text-right text-xs opacity-70">
                <div className="font-medium">4h window</div>
                <div className="hidden sm:block">auto-resolves after</div>
            </div>
        </div>
    );
}
