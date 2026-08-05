import { AlertTriangle, Clock } from 'lucide-react';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { BalanceCard } from '@/components/wallet/balance-card';
import { WithdrawForm } from '@/components/wallet/withdraw-form';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import { formatTimeUntil, formatUsdt } from '@/lib/wallet-format';
import { index as walletIndex } from '@/routes/wallet';
import type { WalletWithdrawProps } from '@/types';

export default function WalletWithdraw({
    balance,
    availableBalance,
    clearingBalance,
    nextClearanceAt,
    minWithdrawal,
    platformFee,
    estimatedNetworkFee,
    twoFactorRequired,
    twoFactorEnrolled,
}: WalletWithdrawProps) {
    const t = useT();
    const clearsIn = formatTimeUntil(nextClearanceAt);

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
                    <BalanceCard
                        balance={balance}
                        availableBalance={availableBalance}
                        clearingBalance={clearingBalance}
                        nextClearanceAt={nextClearanceAt}
                        variant="compact"
                    />
                </div>

                {clearingBalance > 0 && (
                    <div className="mb-6 flex gap-3 rounded-xl border border-border/60 bg-card/60 p-4">
                        <Clock className="size-5 shrink-0 text-warning" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-foreground">
                                {t(':amount USDT is still clearing', {
                                    amount: formatUsdt(clearingBalance),
                                })}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Recent winnings are held briefly before they can be withdrawn. You can still stake them on new matches in the meantime.',
                                )}
                                {clearsIn && (
                                    <>
                                        {' '}
                                        <span className="text-foreground">
                                            {t('Next unlock')} {t(clearsIn)}.
                                        </span>
                                    </>
                                )}
                            </p>
                        </div>
                    </div>
                )}

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

                <WithdrawForm
                    availableBalance={availableBalance}
                    minWithdrawal={minWithdrawal}
                    platformFee={platformFee}
                    estimatedNetworkFee={estimatedNetworkFee}
                    twoFactorRequired={twoFactorRequired}
                    twoFactorEnrolled={twoFactorEnrolled}
                />
            </div>
        </PlayerHubLayout>
    );
}
