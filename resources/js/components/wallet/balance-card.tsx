import { Clock } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { formatTimeUntil, formatUsdt } from '@/lib/wallet-format';

interface Props {
    balance: number;
    variant?: 'hero' | 'compact';
    label?: string;
    /**
     * Winnings credited but still inside their insurance window. When > 0 the
     * card shows the split, because a single number here would read as "this
     * is what I can withdraw" and be wrong.
     */
    clearingBalance?: number;
    availableBalance?: number;
    nextClearanceAt?: string | null;
}

/** Wallet balance display: `hero` (full card with gradient number) or
 *  `compact` (slim sub-page card). */
export function BalanceCard({
    balance,
    variant = 'hero',
    label,
    clearingBalance = 0,
    availableBalance,
    nextClearanceAt = null,
}: Props) {
    const t = useT();
    const isClearing = clearingBalance > 0;

    // Only claim "available" when nothing is held back; otherwise the headline
    // number is the total and the breakdown below carries the nuance.
    const resolvedLabel =
        label ?? (isClearing ? t('Total balance') : t('Available balance'));

    if (variant === 'compact') {
        return (
            <div className="rounded-xl border border-border/60 bg-card/60 px-4 py-3">
                <div className="flex items-baseline justify-between">
                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        {resolvedLabel}
                    </span>
                    <span className="font-display text-lg font-semibold text-foreground">
                        {formatUsdt(balance)}{' '}
                        <span className="text-xs text-muted-foreground">
                            USDT
                        </span>
                    </span>
                </div>

                {isClearing && availableBalance !== undefined && (
                    <div className="mt-2 flex items-baseline justify-between border-t border-border/60 pt-2">
                        <span className="text-xs text-muted-foreground">
                            {t('Available to withdraw')}
                        </span>
                        <span className="font-display text-sm font-semibold text-success tabular-nums">
                            {formatUsdt(availableBalance)}
                        </span>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card/60 p-8 text-center">
            <div className="pointer-events-none absolute top-1/2 left-1/2 size-40 -translate-x-1/2 -translate-y-1/2 rounded-full bg-gradient-primary opacity-10 blur-3xl" />
            <div className="relative">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {resolvedLabel}
                </div>
                <div className="mt-3 text-gradient-primary font-display text-5xl leading-none font-bold tracking-tight md:text-6xl">
                    {formatUsdt(balance)}
                </div>
                <div className="mt-2 text-sm font-medium text-muted-foreground">
                    USDT
                </div>

                {isClearing && availableBalance !== undefined && (
                    <ClearingBreakdown
                        availableBalance={availableBalance}
                        clearingBalance={clearingBalance}
                        nextClearanceAt={nextClearanceAt}
                    />
                )}
            </div>
        </div>
    );
}

function ClearingBreakdown({
    availableBalance,
    clearingBalance,
    nextClearanceAt,
}: {
    availableBalance: number;
    clearingBalance: number;
    nextClearanceAt: string | null;
}) {
    const t = useT();
    const unlocksIn = formatTimeUntil(nextClearanceAt);

    return (
        <div className="mx-auto mt-6 max-w-sm space-y-2 border-t border-border/60 pt-5 text-left">
            <div className="flex items-baseline justify-between">
                <span className="text-sm text-muted-foreground">
                    {t('Available to withdraw')}
                </span>
                <span className="font-display font-semibold text-success tabular-nums">
                    {formatUsdt(availableBalance)}
                </span>
            </div>

            <div className="flex items-baseline justify-between">
                <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Clock className="size-3.5 text-warning" />
                    {t('Clearing')}
                </span>
                <span className="font-display font-semibold text-warning tabular-nums">
                    {formatUsdt(clearingBalance)}
                </span>
            </div>

            <p className="pt-1 text-xs leading-relaxed text-muted-foreground">
                {t(
                    'Recent winnings are held briefly before they can be withdrawn. You can still stake them on new matches in the meantime.',
                )}
                {unlocksIn && (
                    <>
                        {' '}
                        <span className="text-foreground">
                            {t('Next unlock')} {t(unlocksIn)}.
                        </span>
                    </>
                )}
            </p>
        </div>
    );
}
