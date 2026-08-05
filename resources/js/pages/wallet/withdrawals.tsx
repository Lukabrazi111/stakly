import { Inbox } from 'lucide-react';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { WalletPagination } from '@/components/wallet/wallet-pagination';
import { WithdrawalStatusChip } from '@/components/wallet/withdrawal-status-chip';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import {
    formatTimeUntil,
    formatTransactionDate,
    formatUsdt,
    truncateAddress,
} from '@/lib/wallet-format';
import {
    index as walletIndex,
    withdrawals as withdrawalsRoute,
} from '@/routes/wallet';
import type { WalletWithdrawalsProps, Withdrawal } from '@/types';

export default function WalletWithdrawals({
    withdrawals,
}: WalletWithdrawalsProps) {
    const t = useT();
    const isEmpty = withdrawals.data.length === 0;

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Withdrawals — Wallet')}
                description={t('Your withdrawal history.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Withdrawals')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t('Every cash-out you have requested, newest first.')}
                    </p>
                </header>

                {isEmpty ? (
                    <div className="rounded-2xl border border-dashed border-border/60 p-10 text-center">
                        <div className="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Inbox className="size-5" />
                        </div>
                        <p className="mt-3 text-sm font-medium text-foreground">
                            {t('No withdrawals yet')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('Win a match, then cash out once it clears.')}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-2">
                            {withdrawals.data.map((withdrawal) => (
                                <WithdrawalRow
                                    key={withdrawal.id}
                                    withdrawal={withdrawal}
                                />
                            ))}
                        </div>

                        <WalletPagination
                            currentPage={withdrawals.meta.current_page}
                            lastPage={withdrawals.meta.last_page}
                            filters={{ type: null }}
                            url={withdrawalsRoute().url}
                        />
                    </>
                )}
            </div>
        </PlayerHubLayout>
    );
}

function WithdrawalRow({ withdrawal }: { withdrawal: Withdrawal }) {
    const t = useT();

    return (
        <div className="rounded-xl border border-border/60 bg-card/60 p-4 transition-colors hover:border-primary/40">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <WithdrawalStatusChip status={withdrawal.status} />
                        <span className="text-xs text-muted-foreground">
                            {formatTransactionDate(withdrawal.created_at)}
                        </span>
                    </div>
                    <p className="mt-2 font-mono text-xs text-muted-foreground">
                        {truncateAddress(withdrawal.destination_address)}
                    </p>
                </div>

                <div className="text-right">
                    <p className="font-display text-lg font-bold tabular-nums">
                        ${formatUsdt(withdrawal.amount)}
                    </p>
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {t('You receive')} ${formatUsdt(withdrawal.net_amount)}
                    </p>
                </div>
            </div>

            {withdrawal.hold_until && (
                <p className="mt-3 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2 text-xs text-warning">
                    {t(
                        'Security hold on a new address — sending in :time. Contact support if this was not you.',
                        { time: formatTimeUntil(withdrawal.hold_until) ?? '' },
                    )}
                </p>
            )}

            {withdrawal.rejected_reason && (
                <p className="mt-3 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                    {withdrawal.rejected_reason}
                </p>
            )}

            {withdrawal.tx_hash && (
                <p className="mt-3 font-mono text-[11px] break-all text-muted-foreground">
                    {withdrawal.tx_hash}
                </p>
            )}
        </div>
    );
}
