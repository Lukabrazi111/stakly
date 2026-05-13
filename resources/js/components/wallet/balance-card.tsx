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
export function BalanceCard({ balance, variant = 'hero', label = 'Available balance' }: Props) {
    if (variant === 'compact') {
        return (
            <div className="border-border/60 bg-card/60 flex items-baseline justify-between rounded-xl border px-4 py-3">
                <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    {label}
                </span>
                <span className="font-display text-foreground text-lg font-semibold">
                    {formatUsdt(balance)}{' '}
                    <span className="text-muted-foreground text-xs">USDT</span>
                </span>
            </div>
        );
    }

    return (
        <div className="border-border/60 bg-card/60 relative overflow-hidden rounded-2xl border p-8 text-center">
            {/* Subtle background glow — radial blur centered behind the number. */}
            <div className="bg-gradient-primary pointer-events-none absolute top-1/2 left-1/2 size-40 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-10 blur-3xl" />
            <div className="relative">
                <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    {label}
                </div>
                <div className="font-display text-gradient-primary mt-3 text-5xl leading-none font-bold tracking-tight md:text-6xl">
                    {formatUsdt(balance)}
                </div>
                <div className="text-muted-foreground mt-2 text-sm font-medium">
                    USDT
                </div>
            </div>
        </div>
    );
}
