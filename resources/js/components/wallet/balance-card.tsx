import { formatUsdt } from '@/lib/wallet-format';

interface Props {
    balance: number;
    variant?: 'hero' | 'compact';
    label?: string;
}

/**
 * Stakly's wallet balance display. Two variants:
 *   - `hero` (default) — full card with massive gradient number. Used on /wallet
 *     as the page hero.
 *   - `compact` — slim card used on sub-pages (/wallet/withdraw) where the
 *     balance is a reference, not the focal point.
 *
 * Gradient text is one of Stakly's "use sparingly" elements (~2-3 per page).
 * Balance is the natural place for it on a wallet surface — money is the
 * message.
 */
export function BalanceCard({
    balance,
    variant = 'hero',
    label = 'Available balance',
}: Props) {
    if (variant === 'compact') {
        return (
            <div className="flex items-baseline justify-between rounded-xl border border-border/60 bg-card/60 px-4 py-3">
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </span>
                <span className="font-display text-lg font-semibold text-foreground">
                    {formatUsdt(balance)}{' '}
                    <span className="text-xs text-muted-foreground">USDT</span>
                </span>
            </div>
        );
    }

    return (
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card/60 p-8 text-center">
            {/* Subtle background glow — radial blur centered behind the number. */}
            <div className="pointer-events-none absolute top-1/2 left-1/2 size-40 -translate-x-1/2 -translate-y-1/2 rounded-full bg-gradient-primary opacity-10 blur-3xl" />
            <div className="relative">
                <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </div>
                <div className="mt-3 text-gradient-primary font-display text-5xl leading-none font-bold tracking-tight md:text-6xl">
                    {formatUsdt(balance)}
                </div>
                <div className="mt-2 text-sm font-medium text-muted-foreground">
                    USDT
                </div>
            </div>
        </div>
    );
}
