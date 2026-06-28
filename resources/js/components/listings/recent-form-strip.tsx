import { useT } from '@/lib/i18n';
import type { RecentFormResult } from '@/types/listings';

/**
 * Recent W/L/D form (M41 P7) — the player's last few settled Stakly matches as
 * compact chips, newest first. Win green / loss red / draw grey: these ARE
 * status semantics, so they use Stakly's status palette (distinct from the
 * FACEIT level dial's authentic-FACEIT colors it sits beside). Sourced from our
 * own DB, never FACEIT. Renders nothing when there's no settled history.
 */

const RESULT_STYLE: Record<RecentFormResult, string> = {
    W: 'bg-success/15 text-success',
    L: 'bg-destructive/15 text-destructive',
    D: 'bg-muted text-muted-foreground',
};

const RESULT_LABEL: Record<RecentFormResult, string> = {
    W: 'Win',
    L: 'Loss',
    D: 'Draw',
};

export function RecentFormStrip({ form }: { form: RecentFormResult[] | null }) {
    const t = useT();

    if (!form || form.length === 0) {
        return null;
    }

    return (
        <span
            className="inline-flex items-center gap-1"
            role="img"
            aria-label={t('Recent form: :form', {
                form: form.map((result) => t(RESULT_LABEL[result])).join(', '),
            })}
        >
            {form.map((result, index) => (
                <span
                    key={index}
                    className={`inline-flex size-4 items-center justify-center rounded-[4px] text-[10px] font-bold ${RESULT_STYLE[result]}`}
                    aria-hidden="true"
                >
                    {result}
                </span>
            ))}
        </span>
    );
}
