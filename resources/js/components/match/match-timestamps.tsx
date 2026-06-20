import { useT } from '@/lib/i18n';

interface MatchTimestampsProps {
    startedAt: string | null;
    /** Null for Pending / Disputed / ManualReview — only set once Settled. */
    finishedAt: string | null;
}

export function MatchTimestamps({
    startedAt,
    finishedAt,
}: MatchTimestampsProps) {
    const t = useT();

    if (!startedAt) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
            <time dateTime={startedAt}>
                {t('Started :time', { time: formatAbsoluteTime(startedAt) })}
            </time>
            {finishedAt && (
                <>
                    <span aria-hidden>·</span>
                    <time dateTime={finishedAt}>
                        {t('Finished :time', {
                            time: formatAbsoluteTime(finishedAt),
                        })}
                    </time>
                </>
            )}
        </div>
    );
}

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
