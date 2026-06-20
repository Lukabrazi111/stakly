import { Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { formatUsdt } from '@/lib/wallet-format';
import { index as walletIndex } from '@/routes/wallet';

interface Props {
    balance: number;
}

/** Wallet-balance pill in `SiteHeader`. Desktop-only — `MobileMenu` shows
 *  the same info inline. */
export function BalanceChip({ balance }: Props) {
    return (
        <Link
            href={walletIndex().url}
            prefetch
            aria-label={`Wallet balance: $${formatUsdt(balance)} USDT`}
            className="hidden items-center gap-2 rounded-full border border-border/60 bg-card/60 px-3 py-1.5 text-sm transition-colors duration-150 ease-out hover:border-primary/40 hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none md:inline-flex"
        >
            <Wallet className="size-4 text-muted-foreground" />
            <span className="font-mono font-medium text-foreground tabular-nums">
                ${formatUsdt(balance)}
            </span>
        </Link>
    );
}
