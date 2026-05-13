import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { BalanceCard } from '@/components/wallet/balance-card';
import { WithdrawForm } from '@/components/wallet/withdraw-form';
import SiteLayout from '@/layouts/site-layout';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletWithdrawProps } from '@/types';

export default function WalletWithdraw({ balance, minWithdrawal }: WalletWithdrawProps) {
    return (
        <SiteLayout>
            <Head title="Withdraw — Wallet" />

            <div className="mx-auto max-w-lg px-4 py-10 md:py-14">
                <Link
                    href={walletIndex().url}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm transition-colors"
                >
                    <ChevronLeft className="size-4" />
                    Back to wallet
                </Link>

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Withdraw USDT
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Send USDT to a TRC20 address. Network fees are paid from the
                        amount sent.
                    </p>
                </header>

                <div className="mb-6">
                    <BalanceCard balance={balance} variant="compact" />
                </div>

                <WithdrawForm balance={balance} minWithdrawal={minWithdrawal} />
            </div>
        </SiteLayout>
    );
}
