import { router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { BackLink } from '@/components/site/back-link';
import { PageMeta } from '@/components/site/page-meta';
import { TransactionRow } from '@/components/wallet/transaction-row';
import { WalletPagination } from '@/components/wallet/wallet-pagination';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import { buildWalletHistoryQuery } from '@/lib/wallet-history-query';
import { history as historyRoute, index as walletIndex } from '@/routes/wallet';
import type { WalletHistoryProps, WalletTransactionType } from '@/types';

const TYPE_LABELS: Record<WalletTransactionType, string> = {
    deposit: 'Deposit',
    withdrawal: 'Withdrawal',
    escrow_hold: 'Escrow hold',
    escrow_release: 'Refund',
    payout: 'Payout',
    fee: 'Fee',
    withdrawal_reversal: 'Returned',
};

export default function WalletHistory({
    transactions,
    filters,
    types,
}: WalletHistoryProps) {
    const t = useT();

    const goToFilter = (type: WalletTransactionType | null) => {
        // `replace: true` — filter chip clicks are view-state changes, not
        // real navigation events. Without this, each chip click pushes a
        // history entry and `BackLink`'s `history.back()` walks back through
        // every filter the user tried instead of returning to `/wallet`.
        // Mirrors the listings filter-bar pattern (`listing-filters-bar.tsx`).
        router.get(historyRoute().url, buildWalletHistoryQuery({ type }), {
            preserveState: false,
            preserveScroll: false,
            replace: true,
        });
    };

    const isEmpty = transactions.data.length === 0;
    const hasActiveFilter = filters.type !== null;

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Transaction history — Wallet')}
                description={t('Your wallet transaction history.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Transaction history')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'Every credit and debit on your account, newest first.',
                        )}
                    </p>
                </header>

                <div className="mb-6 flex flex-wrap items-center gap-2">
                    <FilterChip
                        active={!filters.type}
                        onClick={() => goToFilter(null)}
                    >
                        {t('All')}
                    </FilterChip>
                    {types.map((type) => (
                        <FilterChip
                            key={type}
                            active={filters.type === type}
                            onClick={() => goToFilter(type)}
                        >
                            {t(TYPE_LABELS[type])}
                        </FilterChip>
                    ))}
                </div>

                {isEmpty ? (
                    <div className="rounded-2xl border border-dashed border-border/60 p-10 text-center">
                        <div className="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Inbox className="size-5" />
                        </div>
                        <p className="mt-3 text-sm font-medium text-foreground">
                            {hasActiveFilter
                                ? t('No transactions match this filter')
                                : t('No transactions yet')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {hasActiveFilter
                                ? t('Try a different type or clear the filter.')
                                : t('Make a deposit to get started.')}
                        </p>
                        {hasActiveFilter && (
                            <button
                                type="button"
                                onClick={() => goToFilter(null)}
                                className="mt-4 inline-block cursor-pointer text-sm font-medium text-primary transition-colors hover:text-primary/80"
                            >
                                {t('Clear filter')}
                            </button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="space-y-2">
                            {transactions.data.map((transaction) => (
                                <TransactionRow
                                    key={transaction.id}
                                    transaction={transaction}
                                />
                            ))}
                        </div>

                        <WalletPagination
                            currentPage={transactions.meta.current_page}
                            lastPage={transactions.meta.last_page}
                            filters={filters}
                        />
                    </>
                )}
            </div>
        </PlayerHubLayout>
    );
}

interface FilterChipProps {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}

function FilterChip({ active, onClick, children }: FilterChipProps) {
    const base =
        'inline-flex cursor-pointer items-center rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background';

    const stateClasses = active
        ? 'border-primary/40 bg-primary/15 text-foreground'
        : 'border-border/60 bg-card/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground';

    return (
        <button
            type="button"
            onClick={onClick}
            className={`${base} ${stateClasses}`}
        >
            {children}
        </button>
    );
}
