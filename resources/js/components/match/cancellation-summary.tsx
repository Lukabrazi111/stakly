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
        <section className="border-border/60 bg-card/60 rounded-2xl border p-6">
            <div className="flex items-start gap-3">
                <span className="bg-muted text-muted-foreground inline-flex size-10 shrink-0 items-center justify-center rounded-full">
                    <Handshake className="size-5" strokeWidth={1.75} />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-foreground font-display text-lg font-semibold">
                        Match cancelled
                    </h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        By mutual agreement. Both stakes refunded —
                        ${listing.stake_amount} returned to each player.
                    </p>
                    <p className="text-muted-foreground/80 mt-2 text-xs">
                        Cancellations don't count toward your match record.
                    </p>
                    {requester !== null && cancellation.reason !== null && (
                        <div className="border-border/60 bg-background/40 mt-4 rounded-lg border p-3">
                            <p className="text-muted-foreground text-[10px] font-medium tracking-wide uppercase">
                                {requester.name}'s reason
                            </p>
                            <p className="text-foreground mt-1 text-sm whitespace-pre-wrap">
                                {cancellation.reason}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
