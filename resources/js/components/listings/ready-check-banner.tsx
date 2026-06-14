import { Zap } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';

interface Props {
    deadlineIso: string;
}

/**
 * Full-width amber strip rendered at the top of the listing card / row
 * whenever the lobby is in `ready_checking`. Ticks the MM:SS countdown
 * locally; renders nothing once the deadline has passed (the server-side
 * cron + next Inertia partial reload will pick up the state transition).
 *
 * Responsive negative margins extend the banner to the card / row edges
 * regardless of whether the consumer has `p-4` (mobile rows) or `p-5`
 * (everything else) padding.
 */
export function ReadyCheckBanner({ deadlineIso }: Props) {
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

    return (
        <div
            className="relative -mx-4 -mt-4 mb-1 flex items-center justify-between gap-2 border-b border-warning/40 bg-warning/10 px-4 py-2 text-xs font-medium text-warning md:-mx-5 md:-mt-5"
            aria-live="polite"
        >
            <span className="inline-flex items-center gap-1.5">
                <Zap className="size-3.5 animate-pulse" aria-hidden="true" />
                {t('Ready check')}
            </span>
            <span
                className="font-semibold tabular-nums"
                aria-label={t('Time remaining')}
            >
                {minutes}:{seconds.toString().padStart(2, '0')}
            </span>
        </div>
    );
}
