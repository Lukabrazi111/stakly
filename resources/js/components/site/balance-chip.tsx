import { Link } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { formatUsdt } from '@/lib/wallet-format';
import { index as walletIndex } from '@/routes/wallet';

interface Props {
    balance: number;
}

/**
 * Compact wallet-balance pill rendered in `SiteHeader` next to `ProfileMenu`
 * on desktop (md+). Closes the create-listing → balance-changed feedback loop
 * — without this, the user has to navigate to /wallet to see whether the
 * escrow actually went through.
 *
 * Hidden on mobile (`hidden md:inline-flex`) — `MobileMenu` shows the same
 * info inline inside the account card.
 */
export function BalanceChip({ balance }: Props) {
    return (
        <Link
            href={walletIndex().url}
            prefetch
            aria-label={`Wallet balance: $${formatUsdt(balance)} USDT`}
            className="border-border/60 bg-card/60 hover:border-primary/30 hover:bg-primary/10 hover:shadow-glow-sm focus-visible:ring-primary focus-visible:ring-offset-background hidden items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-all duration-150 ease-out focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none md:inline-flex"
        >
            <Wallet className="text-muted-foreground size-4" />
            <span className="text-foreground font-mono font-medium tabular-nums">
                ${formatUsdt(balance)}
            </span>
        </Link>
    );
}
