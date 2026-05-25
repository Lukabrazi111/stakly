import { Head } from '@inertiajs/react';
import { BackLink } from '@/components/site/back-link';
import { BalanceCard } from '@/components/wallet/balance-card';
import { WithdrawForm } from '@/components/wallet/withdraw-form';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletWithdrawProps } from '@/types';

export default function WalletWithdraw({
    balance,
    minWithdrawal,
}: WalletWithdrawProps) {
    return (
        <PlayerHubLayout>
            <Head title="Withdraw — Wallet" />

            <div className="mx-auto max-w-lg px-4 py-10 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Withdraw USDT
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Send USDT to a TRC20 address. Network fees are paid from
                        the amount sent.
                    </p>
                </header>

                <div className="mb-6">
                    <BalanceCard balance={balance} variant="compact" />
                </div>

                <WithdrawForm balance={balance} minWithdrawal={minWithdrawal} />
            </div>
        </PlayerHubLayout>
    );
}
