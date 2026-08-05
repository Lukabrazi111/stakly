import { Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowRight,
    ArrowUpFromLine,
    History,
    ShieldAlert,
    Wallet as WalletIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageMeta } from '@/components/site/page-meta';
import { BalanceCard } from '@/components/wallet/balance-card';
import { TransactionRow } from '@/components/wallet/transaction-row';
import { WithdrawalStatusChip } from '@/components/wallet/withdrawal-status-chip';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import { useT } from '@/lib/i18n';
import {
    formatTimeUntil,
    formatUsdt,
    truncateAddress,
} from '@/lib/wallet-format';
import {
    deposit as depositRoute,
    history as historyRoute,
    withdraw as withdrawRoute,
    withdrawals as withdrawalsRoute,
} from '@/routes/wallet';
import type { WalletIndexProps } from '@/types';

export default function WalletIndex({
    balance,
    availableBalance,
    clearingBalance,
    nextClearanceAt,
    recentTransactions,
    pendingWithdrawals,
}: WalletIndexProps) {
    const t = useT();
    const hasTransactions = recentTransactions.data.length > 0;
    const hasPendingWithdrawals = pendingWithdrawals.data.length > 0;

    return (
        <PlayerHubLayout>
            <PageMeta
                title={t('Wallet')}
                description={t('Your wallet balance and recent transactions.')}
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        {t('Wallet')}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {t('Deposit, withdraw, and review your USDT activity.')}
                    </p>
                </header>

                <BalanceCard
                    balance={balance}
                    availableBalance={availableBalance}
                    clearingBalance={clearingBalance}
                    nextClearanceAt={nextClearanceAt}
                />

                {hasPendingWithdrawals && (
                    <section className="mt-6">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <h2 className="font-display text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('Withdrawals in progress')}
                            </h2>
                            <Link
                                href={withdrawalsRoute().url}
                                className="group inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-primary"
                            >
                                {t('All withdrawals')}
                                <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                            </Link>
                        </div>
                        <div className="space-y-2">
                            {pendingWithdrawals.data.map((withdrawal) => (
                                <div
                                    key={withdrawal.id}
                                    className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/60 bg-card/60 px-4 py-3"
                                >
                                    <div className="flex flex-wrap items-center gap-3">
                                        <WithdrawalStatusChip
                                            status={withdrawal.status}
                                        />
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {truncateAddress(
                                                withdrawal.destination_address,
                                            )}
                                        </span>
                                        {withdrawal.hold_until && (
                                            <span className="inline-flex items-center gap-1 text-xs text-warning">
                                                <ShieldAlert className="size-3" />
                                                {t('New address — sending in :time', {
                                                    time:
                                                        formatTimeUntil(
                                                            withdrawal.hold_until,
                                                        ) ?? '',
                                                })}
                                            </span>
                                        )}
                                    </div>
                                    <span className="font-display font-semibold tabular-nums">
                                        ${formatUsdt(withdrawal.amount)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <div className="mt-6 grid gap-4 md:grid-cols-3">
                    <ActionCard
                        href={depositRoute().url}
                        icon={ArrowDownToLine}
                        title={t('Deposit')}
                        description={t('Add USDT via TRC20')}
                    />
                    <ActionCard
                        href={withdrawRoute().url}
                        icon={ArrowUpFromLine}
                        title={t('Withdraw')}
                        description={t('Send to a TRC20 address')}
                    />
                    <ActionCard
                        href={historyRoute().url}
                        icon={History}
                        title={t('History')}
                        description={t('All your transactions')}
                    />
                </div>

                <div className="mt-3 flex justify-end">
                    <Link
                        href={withdrawalsRoute().url}
                        className="group inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-primary"
                    >
                        {t('Withdrawal history')}
                        <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                    </Link>
                </div>

                <section className="mt-10">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="font-display text-xl font-semibold text-foreground">
                            {t('Recent activity')}
                        </h2>
                        {hasTransactions && (
                            <Link
                                href={historyRoute().url}
                                className="group inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-primary"
                            >
                                {t('View all')}
                                <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                            </Link>
                        )}
                    </div>

                    {hasTransactions ? (
                        <div className="space-y-2">
                            {recentTransactions.data.map((transaction) => (
                                <TransactionRow
                                    key={transaction.id}
                                    transaction={transaction}
                                />
                            ))}
                        </div>
                    ) : (
                        <div className="rounded-2xl border border-dashed border-border/60 p-10 text-center">
                            <div className="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <WalletIcon className="size-5" />
                            </div>
                            <p className="mt-3 text-sm font-medium text-foreground">
                                {t('No transactions yet')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Make a deposit to get started.')}
                            </p>
                            <Link
                                href={depositRoute().url}
                                className="group mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary transition-colors hover:text-primary/80"
                            >
                                {t('Deposit USDT')}
                                <ArrowRight className="size-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" />
                            </Link>
                        </div>
                    )}
                </section>
            </div>
        </PlayerHubLayout>
    );
}

interface ActionCardProps {
    href: string;
    icon: LucideIcon;
    title: string;
    description: string;
}

function ActionCard({ href, icon: Icon, title, description }: ActionCardProps) {
    return (
        <Link
            href={href}
            className="group flex flex-col gap-3 rounded-2xl border border-border/60 bg-card/60 p-5 transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-primary/30 hover:bg-card hover:shadow-glow-sm focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <div className="inline-flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary transition-colors group-hover:bg-primary/15">
                <Icon className="size-5" />
            </div>
            <div>
                <h3 className="font-semibold text-foreground">{title}</h3>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {description}
                </p>
            </div>
        </Link>
    );
}
