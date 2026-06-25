import { useT } from '@/lib/i18n';

interface DealSummaryProps {
    /** Per-player stake in USDT (0 hides the panel). */
    stake: number;
    teamSize: number;
    feeRate: number;
}

function formatUsd(amount: number): string {
    return `$${amount.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/**
 * Live "what do I win?" breakdown shown once a stake is entered — the Bybit-style
 * tinted info box. Mirrors the settlement math: pot = stake × players,
 * fee = pot × feeRate, payout = (pot − fee) / teamSize. Display floats only; the
 * authoritative money path is BCMath in `App\Services\Wallet`. `feeRate` is the
 * same `config('stakly.platform_fee_rate')` the ledger uses, passed from the
 * controller so this can never drift from real settlement.
 */
export function DealSummary({ stake, teamSize, feeRate }: DealSummaryProps) {
    const t = useT();

    if (!(stake > 0)) {
        return null;
    }

    const players = teamSize * 2;
    const pot = stake * players;
    const fee = pot * feeRate;
    const payoutPerPlayer = (pot - fee) / teamSize;
    const feePercent = Number((feeRate * 100).toFixed(2));
    const isTeam = teamSize > 1;

    return (
        <div className="space-y-2.5 rounded-xl border border-primary/20 bg-primary/5 p-4">
            <SummaryRow label={t('Pot')} value={formatUsd(pot)} />
            <SummaryRow
                label={t('Platform fee (:pct%)', { pct: feePercent })}
                value={`−${formatUsd(fee)}`}
                muted
            />
            <div className="border-t border-primary/15 pt-2.5">
                <SummaryRow
                    label={
                        isTeam
                            ? t('Payout if you win (per player)')
                            : t('Payout if you win')
                    }
                    value={formatUsd(payoutPerPlayer)}
                    highlight
                />
            </div>
        </div>
    );
}

function SummaryRow({
    label,
    value,
    muted,
    highlight,
}: {
    label: string;
    value: string;
    muted?: boolean;
    highlight?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <span
                className={
                    highlight
                        ? 'font-semibold text-foreground'
                        : 'text-muted-foreground'
                }
            >
                {label}
            </span>
            <span
                className={
                    highlight
                        ? 'font-display text-base font-bold text-success tabular-nums'
                        : muted
                          ? 'text-muted-foreground tabular-nums'
                          : 'font-medium text-foreground tabular-nums'
                }
            >
                {value}
            </span>
        </div>
    );
}
