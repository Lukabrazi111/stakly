import { Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    History,
    Wallet as WalletIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageMeta } from '@/components/site/page-meta';
import { BalanceCard } from '@/components/wallet/balance-card';
import { TransactionRow } from '@/components/wallet/transaction-row';
import PlayerHubLayout from '@/layouts/player-hub-layout';
import {
    deposit as depositRoute,
    history as historyRoute,
    withdraw as withdrawRoute,
} from '@/routes/wallet';
import type { WalletIndexProps } from '@/types';

export default function WalletIndex({
    balance,
    recentTransactions,
}: WalletIndexProps) {
    const hasTransactions = recentTransactions.data.length > 0;

    return (
        <PlayerHubLayout>
            <PageMeta
                title="Wallet"
                description="Your wallet balance and recent transactions."
                noindex
            />

            <div className="mx-auto max-w-5xl px-4 py-10 md:px-6 md:py-14">
                <header className="mb-8">
                    <h1 className="font-display text-3xl font-bold tracking-tight md:text-4xl">
                        Wallet
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Deposit, withdraw, and review your USDT activity.
                    </p>
                </header>

                <BalanceCard balance={balance} />

                <div className="mt-6 grid gap-4 md:grid-cols-3">
                    <ActionCard
                        href={depositRoute().url}
                        icon={ArrowDownToLine}
                        title="Deposit"
                        description="Add USDT via TRC20"
                    />
                    <ActionCard
                        href={withdrawRoute().url}
                        icon={ArrowUpFromLine}
                        title="Withdraw"
                        description="Send to a TRC20 address"
                    />
                    <ActionCard
                        href={historyRoute().url}
                        icon={History}
                        title="History"
                        description="All your transactions"
                    />
                </div>

                <section className="mt-10">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="font-display text-xl font-semibold text-foreground">
                            Recent activity
                        </h2>
                        {hasTransactions && (
                            <Link
                                href={historyRoute().url}
                                className="text-sm font-medium text-primary transition-colors hover:text-primary/80"
                            >
                                View all →
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
                                No transactions yet
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Make a deposit to get started.
                            </p>
                            <Link
                                href={depositRoute().url}
                                className="mt-4 inline-block text-sm font-medium text-primary transition-colors hover:text-primary/80"
                            >
                                Deposit USDT →
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
