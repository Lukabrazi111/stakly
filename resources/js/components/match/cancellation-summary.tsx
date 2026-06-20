import { Handshake } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { Match } from '@/types';

interface CancellationSummaryProps {
    match: Match;
}

/** Terminal banner when `status === 'cancelled'`. Sibling to
 *  `SettlementSummary` but muted — no winner, no payout math. */
export function CancellationSummary({ match }: CancellationSummaryProps) {
    const t = useT();
    const { cancellation, listing } = match;

    const requesterName = resolveRequesterName(
        match,
        cancellation.requested_by_id,
    );
    const isTeam = listing.team_size > 1;

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-6">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Handshake className="size-5" strokeWidth={1.75} />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="font-display text-lg font-semibold text-foreground">
                        {t('Match cancelled')}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {isTeam
                            ? t(
                                  'By mutual agreement. All stakes refunded — $:amount returned to each player.',
                                  { amount: listing.stake_amount },
                              )
                            : t(
                                  'By mutual agreement. Both stakes refunded — $:amount returned to each player.',
                                  { amount: listing.stake_amount },
                              )}
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground/80">
                        {t(
                            "Cancellations don't count toward your match record.",
                        )}
                    </p>
                    {requesterName !== null && cancellation.reason !== null && (
                        <div className="mt-4 rounded-lg border border-border/60 bg-background/40 p-3">
                            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                {t(":name's reason", { name: requesterName })}
                            </p>
                            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                                {cancellation.reason}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

function resolveRequesterName(
    match: Match,
    requesterId: number | null,
): string | null {
    if (requesterId === null) {
        return null;
    }

    if (match.creator.id === requesterId) {
        return match.creator.name;
    }

    if (match.taker.id === requesterId) {
        return match.taker.name;
    }

    const fromRoster =
        match.team_a?.find((p) => p.user_id === requesterId) ??
        match.team_b?.find((p) => p.user_id === requesterId) ??
        null;

    return fromRoster?.name ?? null;
}
