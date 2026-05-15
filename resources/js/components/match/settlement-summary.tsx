import { Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import type { MatchPlayer } from '@/types';

interface SettlementSummaryProps {
    winner: MatchPlayer;
    pot: number;
    fee: number;
    payout: number;
    iAmWinner: boolean;
}

/**
 * Shown when match.status === 'settled'. Differentiates visually between
 * the winner's view (success accent) and the loser's (muted) so the
 * outcome reads at a glance.
 */
export function SettlementSummary({
    winner,
    pot,
    fee,
    payout,
    iAmWinner,
}: SettlementSummaryProps) {
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
                    <h2 className="font-display text-foreground text-lg font-semibold">
                        Match settled
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        {iAmWinner ? 'You won.' : `${winner.name} won.`}
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
            <dt className="text-muted-foreground text-xs uppercase tracking-wide">
                {label}
            </dt>
            <dd
                className={`font-display mt-1.5 text-xl font-bold ${
                    accent
                        ? 'text-success'
                        : muted
                          ? 'text-muted-foreground font-medium'
                          : 'text-foreground'
                }`}
            >
                {value}
            </dd>
        </div>
    );
}
