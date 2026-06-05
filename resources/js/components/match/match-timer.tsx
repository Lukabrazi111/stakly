import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';

interface MatchTimerProps {
    /** Deadline by which the game API must verify a result before the match flips to ManualReview. */
    deadline: Date;
}

const MS_30_MIN = 30 * 60 * 1000;
const MS_1_HOUR = 60 * 60 * 1000;

/** Countdown chip for the 4-hour API-resolution deadline. Tiers shift
 *  color (>1h pink, 30m–1h amber, <30m red+pulse, expired gray). */
export function MatchTimer({ deadline }: MatchTimerProps) {
    const t = useT();
    // null until mount — initializing to Date.now() would cause a hydration mismatch.
    const [now, setNow] = useState<number | null>(null);

    useEffect(() => {
        setNow(Date.now());
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, []);

    if (now === null) {
        return (
            <time
                dateTime={deadline.toISOString()}
                role="timer"
                aria-label={t('Loading time remaining')}
                className="inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary"
            >
                <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                <span className="tabular-nums">— : — : —</span>
            </time>
        );
    }

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
        ? t('Expired')
        : t(':hours h :minutes m :seconds s', {
              hours,
              minutes: minutes.toString().padStart(2, '0'),
              seconds: seconds.toString().padStart(2, '0'),
          });

    const ariaLabel = isExpired
        ? t('Match deadline expired')
        : t('Time remaining: :hours hours :minutes minutes :seconds seconds', {
              hours,
              minutes,
              seconds,
          });

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
