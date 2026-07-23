import { useT } from '@/lib/i18n';
import type { RecentFormResult } from '@/types/listings';

/**
 * Recent W/L/D form (M41 P7) — the player's last few settled Stakly matches as
 * compact chips, newest first. Win green / loss red / draw grey: these ARE
 * status semantics, so they use Stakly's status palette (distinct from the
 * FACEIT level dial's authentic-FACEIT colors it sits beside). Sourced from our
 * own DB, never FACEIT.
 *
 * `horizontal` — a row of rounded chips (reusable; not currently mounted).
 * `vertical` — a flush, full-height right-edge column for the lobby slot card
 * (matches the FACEIT-roster reference). Pass `slots` to ALWAYS render that many
 * cells, padding the tail with a faded "N" (no match yet) so the column reads
 * consistently even for players with little / no settled history.
 */

const RESULT_STYLE: Record<RecentFormResult, string> = {
    W: 'bg-success/15 text-success',
    L: 'bg-destructive/15 text-destructive',
    D: 'bg-muted text-muted-foreground',
};

// "N" — no match in this slot yet. Faded so it reads as empty, not a result.
const NO_RESULT_STYLE = 'bg-muted/20 text-muted-foreground/40';

const RESULT_LABEL: Record<RecentFormResult, string> = {
    W: 'Win',
    L: 'Loss',
    D: 'Draw',
};

interface Props {
    form: RecentFormResult[] | null;
    orientation?: 'horizontal' | 'vertical';
    /** Vertical only — always render this many cells, padding the tail with "N". */
    slots?: number;
}

export function RecentFormStrip({
    form,
    orientation = 'horizontal',
    slots,
}: Props) {
    const t = useT();
    const results = form ?? [];

    const label =
        results.length === 0
            ? t('No recent matches')
            : t('Recent form: :form', {
                  form: results
                      .map((result) => t(RESULT_LABEL[result]))
                      .join(', '),
              });

    if (orientation === 'vertical') {
        const count = slots ?? results.length;

        if (count === 0) {
            return null;
        }

        // No settled history at all → a single quiet column (M43 P4) instead of
        // a stack of repeated faded "N" cells, which read as noise across a full
        // roster. Partial history still pads with "N" below for context.
        if (results.length === 0) {
            return (
                <span
                    className="flex w-7 shrink-0 items-center justify-center self-stretch bg-muted/10"
                    role="img"
                    aria-label={label}
                >
                    <span
                        className="text-[10px] font-medium text-muted-foreground/30"
                        aria-hidden="true"
                    >
                        —
                    </span>
                </span>
            );
        }

        // Newest first; tail padded with null → a faded "N" cell.
        const cells = Array.from(
            { length: count },
            (_, i) => results[i] ?? null,
        );

        return (
            <span
                className="flex w-7 shrink-0 flex-col gap-px self-stretch overflow-hidden"
                role="img"
                aria-label={label}
            >
                {cells.map((result, index) => (
                    <span
                        key={index}
                        className={`flex flex-1 items-center justify-center text-[10px] font-bold ${
                            result ? RESULT_STYLE[result] : NO_RESULT_STYLE
                        }`}
                        aria-hidden="true"
                    >
                        {result ?? 'N'}
                    </span>
                ))}
            </span>
        );
    }

    if (results.length === 0) {
        return null;
    }

    return (
        <span
            className="inline-flex items-center gap-1"
            role="img"
            aria-label={label}
        >
            {results.map((result, index) => (
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
