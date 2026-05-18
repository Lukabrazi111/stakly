import { Handshake, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import type { MatchPlayer } from '@/types';

interface SettlementSummaryProps {
    /** Null when the match was settled as a draw (refund-only, no winner). */
    winner: MatchPlayer | null;
    pot: number;
    /** Platform fee. Always 0 on a draw. */
    fee: number;
    /** Winner payout when there's a winner; per-player refund when drawn. */
    payout: number;
    iAmWinner: boolean;
}

/**
 * Shown when `match.status === 'settled'`. Three views:
 *   - Winner's view: success accent, "You won."
 *   - Loser's view: muted, "[Winner] won."
 *   - Draw (winner === null): neutral, "Both stakes refunded." No fee row.
 */
export function SettlementSummary({
    winner,
    pot,
    fee,
    payout,
    iAmWinner,
}: SettlementSummaryProps) {
    if (winner === null) {
        return (
            <section className="rounded-2xl border border-border/60 bg-card/60 p-6">
                <div className="mb-5 flex items-center gap-3">
                    <div className="rounded-full bg-muted p-2">
                        <Handshake className="size-5 text-muted-foreground" />
                    </div>
                    <div>
                        <h2 className="font-display text-lg font-semibold text-foreground">
                            Match drawn
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Both stakes refunded. No platform fee.
                        </p>
                    </div>
                </div>

                <dl className="grid gap-5 sm:grid-cols-2">
                    <Stat label="Pot" value={`$${pot}`} />
                    <Stat
                        label="Refund (each)"
                        value={`$${payout.toFixed(2)}`}
                        accent
                    />
                </dl>
            </section>
        );
    }

    return (
        <section
            className={`rounded-2xl border p-6 ${
                iAmWinner
                    ? 'border-success/40 bg-success/5'
                    : 'border-border/60 bg-card/60'
            }`}
        >
            <div className="mb-5 flex items-center gap-3">
                <div
                    className={`rounded-full p-2 ${
                        iAmWinner ? 'bg-success/15' : 'bg-muted'
                    }`}
                >
                    <Trophy
                        className={`size-5 ${
                            iAmWinner ? 'text-success' : 'text-muted-foreground'
                        }`}
                    />
                </div>
                <div>
                    <h2 className="font-display text-lg font-semibold text-foreground">
                        Match settled
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {iAmWinner ? (
                            'You won.'
                        ) : (
                            <>
                                <span className="text-foreground font-medium">
                                    {winner.name}
                                </span>{' '}
                                <span>(@{winner.username})</span> won.
                            </>
                        )}
                    </p>
                </div>
            </div>

            <dl className="grid gap-5 sm:grid-cols-3">
                <Stat label="Pot" value={`$${pot}`} />
                <Stat
                    label="Platform fee"
                    value={`−$${fee.toFixed(2)}`}
                    muted
                />
                <Stat
                    label={iAmWinner ? 'Your payout' : 'Winner payout'}
                    value={`$${payout.toFixed(2)}`}
                    accent={iAmWinner}
                />
            </dl>
        </section>
    );
}

interface StatProps {
    label: string;
    value: string;
    accent?: boolean;
    muted?: boolean;
}

function Stat({ label, value, accent, muted }: StatProps): ReactNode {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={`mt-1.5 font-display text-xl font-bold ${
                    accent
                        ? 'text-success'
                        : muted
                          ? 'font-medium text-muted-foreground'
                          : 'text-foreground'
                }`}
            >
                {value}
            </dd>
        </div>
    );
}
