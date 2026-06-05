import { RotateCcw } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { ProfileTrust } from '@/types';

interface Props {
    trust: ProfileTrust;
    /** Settled matches between viewer and profile user. 0 for guests and
     *  own-profile views — the controller skips the query in those cases. */
    repeatPairCount: number;
}

/** Trust tiles + conditional repeat-pair callout. Hidden when the user
 *  has no settled-match history AND no repeat-pair. */
export function TrustStrip({ trust, repeatPairCount }: Props) {
    const t = useT();
    const hasSettledHistory = trust.settled_lifetime > 0;
    const hasRepeatPair = repeatPairCount >= 2;

    if (!hasSettledHistory && !hasRepeatPair) {
        return null;
    }

    return (
        <section className="flex flex-col gap-3">
            {hasRepeatPair && <RepeatPairCallout count={repeatPairCount} />}

            {hasSettledHistory && (
                <>
                    <h2 className="font-display text-lg font-semibold text-foreground">
                        {t('Data overview')}
                    </h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <CompletionTile
                            label={t('Completion (30 days)')}
                            rate={trust.rate_30d}
                            settled={trust.settled_30d}
                            cancellations={trust.cancellations_30d}
                        />
                        <CompletionTile
                            label={t('Completion (lifetime)')}
                            rate={trust.rate_lifetime}
                            settled={trust.settled_lifetime}
                            cancellations={trust.cancellations_lifetime}
                        />
                        <DisputeTile count={trust.disputes_lifetime} />
                    </div>
                </>
            )}
        </section>
    );
}

function RepeatPairCallout({ count }: { count: number }) {
    const t = useT();
    const message =
        count === 1
            ? t("You've played :count settled match against this player.", {
                  count,
              })
            : t("You've played :count settled matches against this player.", {
                  count,
              });

    // Replace the count token with a styled span so the number gets the
    // primary tabular-nums emphasis without losing translation positioning.
    const parts = message.split(String(count));

    return (
        <div
            className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/10 px-4 py-3"
            role="status"
        >
            <RotateCcw
                className="size-4 shrink-0 text-primary"
                aria-hidden="true"
            />
            <p className="text-sm text-foreground">
                {parts[0]}
                <span className="font-semibold text-primary tabular-nums">
                    {count}
                </span>
                {parts[1] ?? ''}
            </p>
        </div>
    );
}

interface CompletionTileProps {
    label: string;
    /** Integer percentage (0–100) or null when the window has no engaged matches. */
    rate: number | null;
    settled: number;
    cancellations: number;
}

function CompletionTile({
    label,
    rate,
    settled,
    cancellations,
}: CompletionTileProps) {
    const t = useT();
    const denom = settled + cancellations;

    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-2 font-display text-2xl font-semibold text-foreground tabular-nums">
                {rate === null ? (
                    '—'
                ) : (
                    <>
                        {rate}
                        <span className="ml-0.5 text-base font-normal text-muted-foreground">
                            %
                        </span>
                    </>
                )}
            </div>
            <div className="mt-1 text-xs text-muted-foreground tabular-nums">
                {rate === null
                    ? t('No engaged matches in window')
                    : t(':settled of :denom matches', { settled, denom })}
            </div>
        </div>
    );
}

function DisputeTile({ count }: { count: number }) {
    const t = useT();

    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                {t('Disputes (lifetime)')}
            </div>
            <div className="mt-2 font-display text-2xl font-semibold text-foreground tabular-nums">
                {count}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
                {count === 0
                    ? t('Clean record')
                    : count === 1
                      ? t(':count match disputed', { count })
                      : t(':count matches disputed', { count })}
            </div>
        </div>
    );
}
