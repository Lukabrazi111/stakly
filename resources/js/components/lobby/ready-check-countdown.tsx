import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Props {
    deadlineIso: string;
}

/**
 * Live countdown to the lobby's ready-check deadline. Ticks every second,
 * goes red + pulses inside the final 30 s. Stops rendering once the
 * deadline has passed (server-side cron picks up the timeout from there).
 */
export function ReadyCheckCountdown({ deadlineIso }: Props) {
    const t = useT();
    const [remainingMs, setRemainingMs] = useState(() =>
        Math.max(0, new Date(deadlineIso).getTime() - Date.now()),
    );

    useEffect(() => {
        const deadline = new Date(deadlineIso).getTime();
        const tick = () => setRemainingMs(Math.max(0, deadline - Date.now()));

        tick();
        const id = window.setInterval(tick, 1000);

        return () => window.clearInterval(id);
    }, [deadlineIso]);

    if (remainingMs <= 0) {
        return null;
    }

    const totalSeconds = Math.ceil(remainingMs / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    const isFinalStretch = totalSeconds <= 30;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium tabular-nums',
                isFinalStretch
                    ? 'animate-pulse border-destructive/40 bg-destructive/10 text-destructive'
                    : 'border-warning/40 bg-warning/10 text-warning',
            )}
            aria-live="polite"
            aria-label={t('Time remaining to ready up')}
        >
            <Clock className="size-3.5" aria-hidden="true" />
            {minutes}:{seconds.toString().padStart(2, '0')}
        </span>
    );
}
