import { Head, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import { BackLink } from '@/components/site/back-link';
import { TransactionRow } from '@/components/wallet/transaction-row';
import { WalletPagination } from '@/components/wallet/wallet-pagination';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { buildWalletHistoryQuery } from '@/lib/wallet-history-query';
import { history as historyRoute, index as walletIndex } from '@/routes/wallet';
import type { WalletHistoryProps, WalletTransactionType } from '@/types';

// Labels for filter chips. Kept here rather than imported from the chip
// component so the chip stays a pure presentational unit. A future i18n pass
// can converge these on a single translation file.
const TYPE_LABELS: Record<WalletTransactionType, string> = {
    deposit: 'Deposit',
    withdrawal: 'Withdrawal',
    escrow_hold: 'Escrow hold',
    escrow_release: 'Refund',
    payout: 'Payout',
    fee: 'Fee',
};

export default function WalletHistory({ transactions, filters, types }: WalletHistoryProps) {
    const goToFilter = (type: WalletTransactionType | null) => {
        router.get(
            historyRoute().url,
            buildWalletHistoryQuery({ type }),
            { preserveState: false, preserveScroll: false },
        );
    };

    const isEmpty = transactions.data.length === 0;
    const hasActiveFilter = filters.type !== null;

    return (
        <PlayerHubLayout>
            <Head title="Transaction history — Wallet" />

            <div className="mx-auto max-w-4xl px-4 py-10 md:py-14">
                <BackLink fallback={walletIndex().url} />

                <header className="mt-4 mb-6">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Transaction history
                    </h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Every credit and debit on your account, newest first.
                    </p>
                </header>

                <div className="mb-6 flex flex-wrap items-center gap-2">
                    <FilterChip
                        active={!filters.type}
                        onClick={() => goToFilter(null)}
                    >
                        All
                    </FilterChip>
                    {types.map((type) => (
                        <FilterChip
                            key={type}
                            active={filters.type === type}
                            onClick={() => goToFilter(type)}
                        >
                            {TYPE_LABELS[type]}
                        </FilterChip>
                    ))}
                </div>

                {isEmpty ? (
                    <div className="border-border/60 rounded-2xl border border-dashed p-10 text-center">
                        <div className="bg-primary/10 text-primary mx-auto inline-flex size-12 items-center justify-center rounded-full">
                            <Inbox className="size-5" />
                        </div>
                        <p className="text-foreground mt-3 text-sm font-medium">
                            {hasActiveFilter
                                ? 'No transactions match this filter'
                                : 'No transactions yet'}
                        </p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {hasActiveFilter
                                ? 'Try a different type or clear the filter.'
                                : 'Make a deposit to get started.'}
                        </p>
                        {hasActiveFilter && (
                            <button
                                type="button"
                                onClick={() => goToFilter(null)}
                                className="text-primary hover:text-primary/80 mt-4 inline-block cursor-pointer text-sm font-medium transition-colors"
                            >
                                Clear filter
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
    const base
        = 'inline-flex cursor-pointer items-center rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25 focus-visible:ring-offset-2 focus-visible:ring-offset-background';

    const stateClasses = active
        ? 'border-primary/40 bg-primary/15 text-foreground'
        : 'border-border/60 bg-card/60 text-muted-foreground hover:bg-primary/10 hover:text-foreground';

    return (
        <button type="button" onClick={onClick} className={`${base} ${stateClasses}`}>
            {children}
        </button>
    );
}
