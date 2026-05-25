import { Handshake } from 'lucide-react';
import type { Match } from '@/types';

interface CancellationSummaryProps {
    match: Match;
}

/**
 * Terminal banner shown on the match page when `status === 'cancelled'`.
 * Sibling to `SettlementSummary` but visually muted — no winner, no
 * payout math to display, no gradient accent. Reads more like an
 * informational closeout than a celebration.
 *
 * Per the M10 Decisions block: cancellation does NOT count toward
 * player record (distinct from a played draw). The sub-line below the
 * subtitle makes that explicit so players understand the distinction.
 */
export function CancellationSummary({ match }: CancellationSummaryProps) {
    const { cancellation, creator, taker, listing } = match;

    const requesterId = cancellation.requested_by_id;
    const requester =
        requesterId === creator.id
            ? creator
            : requesterId === taker.id
              ? taker
              : null;

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60 p-6">
            <div className="flex items-start gap-3">
                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Handshake className="size-5" strokeWidth={1.75} />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="font-display text-lg font-semibold text-foreground">
                        Match cancelled
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        By mutual agreement. Both stakes refunded — $
                        {listing.stake_amount} returned to each player.
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground/80">
                        Cancellations don't count toward your match record.
                    </p>
                    {requester !== null && cancellation.reason !== null && (
                        <div className="mt-4 rounded-lg border border-border/60 bg-background/40 p-3">
                            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                {requester.name}'s reason
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
