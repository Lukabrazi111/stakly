import { AlertTriangle } from 'lucide-react';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { BalanceCard } from '@/components/wallet/balance-card';
import { WithdrawForm } from '@/components/wallet/withdraw-form';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletWithdrawProps } from '@/types';

export default function WalletWithdraw({
    balance,
    minWithdrawal,
}: WalletWithdrawProps) {
    const t = useT();

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Withdraw — Wallet')}
                description={t('Withdraw USDT from your Stakly wallet.')}
                noindex
            />

            <div className="mx-auto max-w-lg px-4 py-10 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Withdraw USDT')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'Send USDT to a TRC20 address. Network fees are paid from the amount sent.',
                        )}
                    </p>
                </header>

                <div className="mb-6">
                    <BalanceCard balance={balance} variant="compact" />
                </div>

                <div className="mb-6 flex gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4">
                    <AlertTriangle className="size-5 shrink-0 text-warning" />
                    <div className="space-y-1">
                        <p className="text-sm font-semibold text-warning">
                            {t('Send to TRC20 (Tron) addresses only')}
                        </p>
                        <p className="text-xs text-warning/90">
                            {t(
                                'Sending to an ERC20, BEP20, Solana, or any other network address loses your funds permanently. Double-check the address before submitting.',
                            )}
                        </p>
                    </div>
                </div>

                <WithdrawForm balance={balance} minWithdrawal={minWithdrawal} />
            </div>
        </PlayerHubLayout>
    );
}
