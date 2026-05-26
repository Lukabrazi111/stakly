import { RotateCcw } from 'lucide-react';
import type { ProfileTrust } from '@/types';

interface Props {
    trust: ProfileTrust;
    /**
     * Count of settled matches between the viewer and the profile user.
     * Drives the repeat-pair callout (shown only when count >= 2). 0 for
     * guests and own-profile views — the controller skips the query in
     * those cases.
     */
    repeatPairCount: number;
}

/**
 * Data Overview row below the profile hero (M19 Phase 3). Three trust
 * tiles — completion rate 30d, completion rate lifetime, disputes — plus
 * a conditional repeat-pair callout above for repeat opponents.
 *
 * Hidden entirely when the user has no settled-match history (and no
 * repeat-pair). On a fresh profile, the page jumps directly from hero to
 * activity rather than displaying an empty Data Overview.
 */
export function TrustStrip({ trust, repeatPairCount }: Props) {
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
                        Data overview
                    </h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <CompletionTile
                            label="Completion (30 days)"
                            rate={trust.rate_30d}
                            settled={trust.settled_30d}
                            cancellations={trust.cancellations_30d}
                        />
                        <CompletionTile
                            label="Completion (lifetime)"
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
                You&rsquo;ve played{' '}
                <span className="font-semibold text-primary tabular-nums">
                    {count}
                </span>{' '}
                settled {count === 1 ? 'match' : 'matches'} against this player.
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
                    ? 'No engaged matches in window'
                    : `${settled} of ${denom} matches`}
            </div>
        </div>
    );
}

function DisputeTile({ count }: { count: number }) {
    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
                Disputes (lifetime)
            </div>
            <div className="mt-2 font-display text-2xl font-semibold text-foreground tabular-nums">
                {count}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">
                {count === 0
                    ? 'Clean record'
                    : count === 1
                      ? '1 match disputed'
                      : `${count} matches disputed`}
            </div>
        </div>
    );
}
